<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/security.php';

function customer_cookie_name(): string {
  return 'HOPE_CUSTOMER_TOKEN';
}

function normalize_username(string $username): string {
  return strtolower(trim($username));
}

function is_valid_username(string $username): bool {
  return (bool)preg_match('/^[a-zA-Z0-9._-]{3,50}$/', $username);
}

function customer_current(): ?array {
  start_session();
  return $_SESSION['customer'] ?? null;
}

function customer_sync_session(array $customer): void {
  start_session();
  unset($customer['password_hash']);
  $_SESSION['customer'] = $customer;
}

function customer_create_session(array $customer): void {
  start_session();
  session_regenerate_id(true);
  $token = bin2hex(random_bytes(32));
  $tokenHash = hash('sha256', $token);
  $expiresAt = (new DateTimeImmutable('+365 days'))->format('Y-m-d H:i:s');

  $stmt = db()->prepare("INSERT INTO customer_sessions (customer_id, token_hash, expires_at) VALUES (?,?,?)");
  $stmt->execute([(int)$customer['id'], $tokenHash, $expiresAt]);

  $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
  setcookie(customer_cookie_name(), $token, [
    'expires' => time() + 365 * 24 * 60 * 60,
    'path' => '/',
    'secure' => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  customer_sync_session($customer);
}

function customer_bootstrap_from_cookie(): void {
  start_session();
  if (!empty($_SESSION['customer'])) {
    return;
  }
  $token = $_COOKIE[customer_cookie_name()] ?? '';
  if ($token === '') {
    return;
  }
  $tokenHash = hash('sha256', $token);
  $stmt = db()->prepare("
    SELECT cs.id AS session_id, c.*
    FROM customer_sessions cs
    JOIN customers c ON c.id = cs.customer_id
    WHERE cs.token_hash = ? AND (cs.expires_at IS NULL OR cs.expires_at > NOW())
    LIMIT 1
  ");
  $stmt->execute([$tokenHash]);
  $row = $stmt->fetch();
  if (!$row) {
    return;
  }
  $stmt = db()->prepare("UPDATE customer_sessions SET last_used_at=NOW() WHERE id=?");
  $stmt->execute([(int)$row['session_id']]);
  unset($row['session_id']);
  customer_sync_session($row);
}

function username_exists_anywhere(string $username, ?string $ignoreType = null, int $ignoreId = 0): bool {
  $normalized = normalize_username($username);
  if ($normalized === '') {
    return false;
  }

  $stmt = db()->prepare("SELECT id FROM users WHERE LOWER(username)=? LIMIT 1");
  $stmt->execute([$normalized]);
  $internal = $stmt->fetch();
  if ($internal && !($ignoreType === 'internal' && (int)$internal['id'] === $ignoreId)) {
    return true;
  }

  $stmt = db()->prepare("SELECT id FROM customers WHERE LOWER(username)=? LIMIT 1");
  $stmt->execute([$normalized]);
  $customer = $stmt->fetch();
  if ($customer && !($ignoreType === 'customer' && (int)$customer['id'] === $ignoreId)) {
    return true;
  }

  return false;
}

function find_account_by_username(string $username): ?array {
  $normalized = normalize_username($username);
  if ($normalized === '') {
    return null;
  }

  $stmt = db()->prepare("SELECT id, username, name, role FROM users WHERE LOWER(username)=? LIMIT 1");
  $stmt->execute([$normalized]);
  $internal = $stmt->fetch();
  if ($internal) {
    return ['type' => 'internal', 'account' => $internal];
  }

  $stmt = db()->prepare("SELECT id, username, name, email, phone FROM customers WHERE LOWER(username)=? LIMIT 1");
  $stmt->execute([$normalized]);
  $customer = $stmt->fetch();
  if ($customer) {
    return ['type' => 'customer', 'account' => $customer];
  }

  return null;
}

function customer_username_base(array $customer): string {
  $email = trim((string)($customer['email'] ?? ''));
  $name = trim((string)($customer['name'] ?? ''));

  $source = '';
  if ($email !== '' && strpos($email, '@') !== false) {
    $source = explode('@', $email, 2)[0];
  }
  if ($source === '') {
    $source = $name;
  }
  if ($source === '') {
    $source = 'customer';
  }

  $slug = strtolower($source);
  $slug = preg_replace('/[^a-z0-9._-]+/', '.', $slug) ?? '';
  $slug = trim($slug, '._-');
  if ($slug === '') {
    $slug = 'customer';
  }
  if (strlen($slug) > 40) {
    $slug = substr($slug, 0, 40);
    $slug = rtrim($slug, '._-');
  }
  if (strlen($slug) < 3) {
    $slug = str_pad($slug, 3, 'x');
  }

  return $slug;
}

function generate_unique_customer_username(array $customer, int $customerId): string {
  $base = customer_username_base($customer);
  $candidate = $base;
  $suffix = 1;

  while (username_exists_anywhere($candidate, 'customer', $customerId)) {
    $candidate = $base . $suffix;
    $suffix++;
    if ($suffix > 9999) {
      $candidate = 'customer' . $customerId . '_' . bin2hex(random_bytes(2));
      if (!username_exists_anywhere($candidate, 'customer', $customerId)) {
        break;
      }
    }
  }

  return $candidate;
}

function backfill_customer_usernames(): int {
  $updated = 0;
  $stmt = db()->query("SELECT id, username, email, name FROM customers ORDER BY id ASC");
  $customers = $stmt->fetchAll();

  foreach ($customers as $customer) {
    $id = (int)$customer['id'];
    $existing = normalize_username((string)($customer['username'] ?? ''));
    if ($existing !== '' && is_valid_username($existing) && !username_exists_anywhere($existing, 'customer', $id)) {
      if ($existing !== (string)$customer['username']) {
        $up = db()->prepare("UPDATE customers SET username=? WHERE id=?");
        $up->execute([$existing, $id]);
        $updated++;
      }
      continue;
    }

    $username = generate_unique_customer_username($customer, $id);
    $up = db()->prepare("UPDATE customers SET username=? WHERE id=?");
    $up->execute([$username, $id]);
    $updated++;
  }

  return $updated;
}

function ensure_customer_username_schema(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  $db = db();
  try {
    $col = $db->query("SHOW COLUMNS FROM customers LIKE 'username'")->fetch();
    if (!$col) {
      $db->exec("ALTER TABLE customers ADD COLUMN username VARCHAR(50) NULL AFTER name");
    }

    backfill_customer_usernames();

    $index = $db->query("SHOW INDEX FROM customers WHERE Key_name='uniq_customers_username'")->fetch();
    if (!$index) {
      $db->exec("ALTER TABLE customers ADD UNIQUE KEY uniq_customers_username (username)");
    }
  } catch (Throwable $e) {
    // best effort, jangan ganggu flow utama
  }
}

function customer_login_by_username(string $username, string $password): bool {
  ensure_customer_username_schema();
  $normalized = normalize_username($username);
  if ($normalized === '') {
    return false;
  }

  $stmt = db()->prepare("SELECT * FROM customers WHERE LOWER(username) = ? LIMIT 1");
  $stmt->execute([$normalized]);
  $customer = $stmt->fetch();
  if (!$customer) {
    return false;
  }

  $hash = (string)($customer['password_hash'] ?? '');
  if ($hash === '') {
    return false;
  }

  $verified = password_verify($password, $hash);
  if (!$verified) {
    $legacyMatch = false;
    if (strlen($hash) === 32 && hash_equals($hash, md5($password))) {
      $legacyMatch = true;
    } elseif (strlen($hash) === 40 && hash_equals($hash, sha1($password))) {
      $legacyMatch = true;
    } elseif (hash_equals($hash, $password)) {
      $legacyMatch = true;
    }
    if (!$legacyMatch) {
      return false;
    }
  }

  if ($verified && password_needs_rehash($hash, PASSWORD_DEFAULT)) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare("UPDATE customers SET password_hash=? WHERE id=?");
    $stmt->execute([$newHash, (int)$customer['id']]);
  }
  if (!$verified) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare("UPDATE customers SET password_hash=? WHERE id=?");
    $stmt->execute([$newHash, (int)$customer['id']]);
  }

  customer_create_session($customer);
  return true;
}

function customer_login(string $username, string $password): bool {
  return customer_login_by_username($username, $password);
}

function customer_logout(): void {
  start_session();
  $token = $_COOKIE[customer_cookie_name()] ?? '';
  if ($token !== '') {
    $tokenHash = hash('sha256', $token);
    $stmt = db()->prepare("DELETE FROM customer_sessions WHERE token_hash = ?");
    $stmt->execute([$tokenHash]);
    setcookie(customer_cookie_name(), '', [
      'expires' => time() - 3600,
      'path' => '/',
    ]);
  }
  unset($_SESSION['customer']);
}
