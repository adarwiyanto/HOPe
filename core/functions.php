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

function ensure_upload_dir(string $dir): void {
  if (!is_dir($dir)) mkdir($dir, 0755, true);
}

function normalize_money(string $s): float {
  // menerima "12.500" atau "12500"
  $s = trim($s);
  $s = str_replace([' ', ','], ['', ''], $s);
  return (float)$s;
}
