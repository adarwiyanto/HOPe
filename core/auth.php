<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function start_session(): void {
  $cfg = app_config();
  if (session_status() === PHP_SESSION_NONE) {
    session_name($cfg['security']['session_name']);
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
  return true;
}

function logout(): void {
  start_session();
  $_SESSION = [];
  session_destroy();
}
