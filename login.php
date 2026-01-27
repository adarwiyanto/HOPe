<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/auth.php';

start_session();

if (file_exists(__DIR__ . '/install/install.lock') === false && !file_exists(__DIR__ . '/config.php')) {
  redirect('install/index.php');
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = trim($_POST['username'] ?? '');
  $p = (string)($_POST['password'] ?? '');
  if (login_attempt($u, $p)) {
    $me = current_user();
    if (!in_array($me['role'] ?? '', ['admin', 'superadmin'], true)) {
      redirect(base_url('pos/index.php'));
    }
    redirect(base_url('admin/dashboard.php'));
  }
  $err = 'Username atau password salah.';
}
$appName = app_config()['app']['name'];
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Login</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(base_url('assets/app.css')); ?>">
  <style>
    .login-wrap{max-width:420px;margin:8vh auto}
    .center{text-align:center}
  </style>
</head>
<body>
  <div class="login-wrap">
    <div class="card">
      <div class="center">
        <h2><?php echo e($appName); ?></h2>
        <p><small>Silakan login</small></p>
      </div>
      <?php if ($err): ?>
        <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
      <?php endif; ?>
      <form method="post">
        <div class="row">
          <label>Username</label>
          <input name="username" autocomplete="username" required>
        </div>
        <div class="row">
          <label>Password</label>
          <input type="password" name="password" autocomplete="current-password" required>
        </div>
        <button class="btn" type="submit" style="width:100%">Masuk</button>
      </form>
    </div>
  </div>
</body>
</html>
