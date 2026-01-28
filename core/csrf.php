<?php
require_once __DIR__ . '/auth.php';

function csrf_token(): string {
  start_session();
  if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['_csrf'];
}

function csrf_verify(string $token): bool {
  start_session();
  return isset($_SESSION['_csrf']) && is_string($token)
    && hash_equals($_SESSION['_csrf'], $token);
}

function csrf_check(): void {
  $t = $_POST['_csrf'] ?? '';
  if (!csrf_verify($t)) {
    http_response_code(403);
    exit('CSRF token invalid.');
  }
}
