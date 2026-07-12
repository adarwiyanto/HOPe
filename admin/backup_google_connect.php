<?php
$backupRoot = dirname(__DIR__);
require_once $backupRoot.'/core/backup_safe.php';
backup_safe_register($backupRoot, 'HOPE OAuth connect', 'html');
require_once $backupRoot.'/core/auth.php';
require_once $backupRoot.'/core/csrf.php';
start_secure_session();
require_admin();
if (!current_user_is_owner()) { http_response_code(403); exit('Akses hanya untuk owner.'); }
try {
    $token = (string)($_GET['token'] ?? '');
    if ($token === '' || !csrf_verify($token)) throw new RuntimeException('Token koneksi tidak valid atau kedaluwarsa. Buka kembali menu backup.');
    require_once $backupRoot.'/core/backup_adapter.php';
    $svc = hope_backup_service();
    $clientId = trim((string)$svc->get('oauth_client_id', ''));
    $clientSecret = trim((string)$svc->get('oauth_client_secret', ''));
    if ($clientId === '' || $clientSecret === '') throw new RuntimeException('Google OAuth Client ID atau Client Secret belum tersimpan.');
    $relative = base_url('admin/backup_google_callback.php');
    $callback = preg_match('~^https?://~i', $relative) ? $relative : (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost').'/'.ltrim($relative, '/'));
    $state = bin2hex(random_bytes(24));
    $_SESSION['backup_oauth_state'] = $state;
    $_SESSION['backup_oauth_started_at'] = time();
    $url = $svc->authorizationUrl($callback, $state);
    backup_safe_write_log($backupRoot, 'HOPE OAuth connect', 'OAuth redirect generated for '.$callback);
    if (!headers_sent()) { header('Location: '.$url, true, 302); backup_safe_finish(); exit; }
    echo '<p>Redirect otomatis tidak tersedia.</p><p><a href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'">Buka Otorisasi Google</a></p>';
} catch (Throwable $e) {
    $message = backup_safe_capture($backupRoot, 'HOPE OAuth connect', $e);
    echo '<div style="margin:20px;padding:16px;border:1px solid #fca5a5;background:#fef2f2;color:#991b1b;border-radius:12px"><b>Koneksi Google Drive gagal dimulai.</b><br>'.htmlspecialchars($message, ENT_QUOTES, 'UTF-8').'<p><a href="backup.php">Kembali ke Setting Backup</a></p></div>';
}
backup_safe_finish();
