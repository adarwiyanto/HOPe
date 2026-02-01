<?php
function e(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function base_url(string $path = ''): string {
  $cfg = app_config();
  $base = rtrim($cfg['app']['base_url'], '/');
  $path = ltrim($path, '/');
  return $path ? "{$base}/{$path}" : $base;
}

function app_cache_bust(): string {
  static $version = null;
  if ($version !== null) return $version;
  $version = (string)($_SERVER['REQUEST_TIME'] ?? time());
  return $version;
}

function asset_url(string $path = ''): string {
  $url = base_url($path);
  if ($path === '') return $url;
  $version = app_cache_bust();
  return "{$url}?v={$version}";
}

function redirect(string $to): void {
  header("Location: {$to}");
  exit;
}

function setting(string $key, $default = null) {
  try {
    $stmt = db()->prepare("SELECT value FROM settings WHERE `key` = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    if (!$row) return $default;
    return $row['value'];
  } catch (Throwable $e) {
    return $default;
  }
}

function favicon_url(): string {
  $storeLogo = setting('store_logo', '');
  if (!empty($storeLogo)) {
    return base_url($storeLogo);
  }
  return base_url('assets/favicon.svg');
}

function set_setting(string $key, string $value): void {
  $stmt = db()->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?)
    ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
  $stmt->execute([$key, $value]);
}

function ensure_products_favorite_column(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    $stmt = db()->query("SHOW COLUMNS FROM products LIKE 'is_favorite'");
    $hasColumn = (bool)$stmt->fetch();
    if (!$hasColumn) {
      db()->exec("ALTER TABLE products ADD COLUMN is_favorite TINYINT(1) NOT NULL DEFAULT 0");
    }
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }
}

function ensure_user_invites_table(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    db()->exec("
      CREATE TABLE IF NOT EXISTS user_invites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        role VARCHAR(30) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at TIMESTAMP NULL DEFAULT NULL,
        used_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_token_hash (token_hash),
        KEY idx_email (email)
      ) ENGINE=InnoDB
    ");
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }
}

function ensure_user_profile_columns(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    $stmt = db()->query("SHOW COLUMNS FROM users LIKE 'avatar_path'");
    $hasAvatar = (bool)$stmt->fetch();
    if (!$hasAvatar) {
      db()->exec("ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) NULL AFTER role");
    }
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }

  try {
    $stmt = db()->query("SHOW COLUMNS FROM users LIKE 'email'");
    $hasEmail = (bool)$stmt->fetch();
    if (!$hasEmail) {
      db()->exec("ALTER TABLE users ADD COLUMN email VARCHAR(190) NULL AFTER username");
    }
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }
}

function ensure_password_resets_table(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    db()->exec("
      CREATE TABLE IF NOT EXISTS password_resets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token_hash CHAR(64) NOT NULL,
        expires_at TIMESTAMP NULL DEFAULT NULL,
        used_at TIMESTAMP NULL DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_token_hash (token_hash),
        KEY idx_user_id (user_id),
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
      ) ENGINE=InnoDB
    ");
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }
}

function ensure_owner_role(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    $stmt = db()->query("SHOW COLUMNS FROM users LIKE 'role'");
    $column = $stmt->fetch();
    if (!$column) return;
    $type = (string)($column['Type'] ?? '');
    if (strpos($type, "'owner'") === false || strpos($type, "'superadmin'") !== false) {
      db()->exec("UPDATE users SET role='owner' WHERE role='superadmin'");
      db()->exec("ALTER TABLE users MODIFY role ENUM('owner','admin','user','pegawai') NOT NULL DEFAULT 'admin'");
    }
  } catch (Throwable $e) {
    // Diamkan jika gagal agar tidak mengganggu halaman.
  }
}

function ensure_upload_dir(string $dir): void {
  if (!is_dir($dir)) mkdir($dir, 0755, true);
}

function normalize_money(string $s): float {
  // menerima "12.500" atau "12500"
  $s = trim($s);
  $s = str_replace([' ', ','], ['', ''], $s);
  return (float)$s;
}

function landing_default_html(): string {
  return <<<'HTML'
<div class="content landing">
  <div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:12px">
        {{store_logo_block}}
        <div>
          <h2 style="margin:0">{{store_name}}</h2>
          <p style="margin:6px 0 0"><small>{{store_subtitle}}</small></p>
        </div>
      </div>
      <a class="btn" href="{{login_url}}">Login</a>
    </div>
  </div>

  <div class="card" style="margin-top:16px">
    <h3 style="margin:0 0 8px">Tentang Kami</h3>
    <p style="margin:0;color:var(--muted)">{{store_intro}}</p>
  </div>

  {{products}}
</div>
HTML;
}
