<?php
function csrf_token(): string {
  if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(16));
  }
  return $_SESSION['_csrf'];
}

function csrf_check(): void {
  $t = $_POST['_csrf'] ?? '';
  if (!$t || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $t)) {
    http_response_code(403);
    exit('CSRF token invalid.');
  }
}
