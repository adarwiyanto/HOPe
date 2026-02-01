<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function start_session(): void {
  $cfg = app_config();
  if (session_status() === PHP_SESSION_NONE) {
    session_name($cfg['security']['session_name']);
    $lifetime = 60 * 60 * 24 * 30;
    session_set_cookie_params([
      'lifetime' => $lifetime,
      'path' => '/',
      'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
    session_start();
  }
}

function require_login(): void {
  start_session();
  if (empty($_SESSION['user'])) {
    redirect(base_url('login.php'));
  }
}

function require_admin(): void {
  require_login();
  ensure_owner_role();
  $u = current_user();
  if (($u['role'] ?? '') === 'pegawai') {
    redirect(base_url('pos/index.php'));
  }
  if (!in_array($u['role'] ?? '', ['admin', 'owner', 'superadmin'], true)) {
    http_response_code(403);
    exit('Forbidden');
  }
}

function current_user(): ?array {
  start_session();
  return $_SESSION['user'] ?? null;
}

function current_customer(): ?array {
  start_session();
  return $_SESSION['customer'] ?? null;
}

function customer_login_attempt(string $phone, string $password): bool {
  $stmt = db()->prepare("SELECT id, name, phone, gender, birth_date, loyalty_points, password_hash FROM customers WHERE phone=? LIMIT 1");
  $stmt->execute([$phone]);
  $customer = $stmt->fetch();
  if (!$customer) return false;
  if (empty($customer['password_hash']) || !password_verify($password, $customer['password_hash'])) {
    return false;
  }
  start_session();
  unset($customer['password_hash']);
  $_SESSION['customer'] = $customer;
  return true;
}

function customer_login(array $customer): void {
  start_session();
  unset($customer['password_hash']);
  $_SESSION['customer'] = $customer;
}

function customer_logout(): void {
  start_session();
  unset($_SESSION['customer']);
}

function require_customer(): void {
  if (!current_customer()) {
    redirect(base_url('index.php'));
  }
}

function login_attempt(string $username, string $password): bool {
  ensure_user_profile_columns();
  $stmt = db()->prepare("SELECT id, username, name, role, email, avatar_path, password_hash FROM users WHERE username=? LIMIT 1");
  $stmt->execute([$username]);
  $u = $stmt->fetch();
  if (!$u) return false;
  if (!password_verify($password, $u['password_hash'])) return false;

  start_session();
  unset($u['password_hash']);
  if (($u['role'] ?? '') === 'superadmin') {
    $u['role'] = 'owner';
  }
  $_SESSION['user'] = $u;
  login_clear_failed_attempts();
  return true;
}

function logout(): void {
  start_session();
  $_SESSION = [];
  session_destroy();
}

function login_attempt_key(): string {
  $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
  return hash('sha256', $ip . '|' . $ua);
}

function login_attempt_store_path(): string {
  return sys_get_temp_dir() . '/hope_login_attempts.json';
}

function login_attempt_store_ttl(): int {
  return 900;
}

function login_read_attempts(): array {
  $path = login_attempt_store_path();
  if (!is_file($path)) {
    return [];
  }
  $raw = file_get_contents($path);
  if ($raw === false || $raw === '') {
    return [];
  }
  $data = json_decode($raw, true);
  if (!is_array($data)) {
    return [];
  }
  $now = time();
  $ttl = login_attempt_store_ttl();
  foreach ($data as $key => $info) {
    $last = (int)($info['last'] ?? 0);
    if ($now - $last > $ttl) {
      unset($data[$key]);
    }
  }
  return $data;
}

function login_write_attempts(array $data): void {
  $path = login_attempt_store_path();
  @file_put_contents($path, json_encode($data), LOCK_EX);
}

function login_failed_attempts(): int {
  $data = login_read_attempts();
  $key = login_attempt_key();
  if (!isset($data[$key])) {
    return 0;
  }
  return (int)($data[$key]['count'] ?? 0);
}

function login_record_failed_attempt(): int {
  $data = login_read_attempts();
  $key = login_attempt_key();
  $count = (int)($data[$key]['count'] ?? 0);
  $count++;
  $data[$key] = [
    'count' => $count,
    'last' => time(),
  ];
  login_write_attempts($data);
  return $count;
}

function login_clear_failed_attempts(): void {
  $data = login_read_attempts();
  $key = login_attempt_key();
  if (isset($data[$key])) {
    unset($data[$key]);
    login_write_attempts($data);
  }
}

function login_should_recover(): bool {
  return login_failed_attempts() >= 3;
}
