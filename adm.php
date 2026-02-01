<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/auth.php';

start_session();

if (file_exists(__DIR__ . '/install/install.lock') === false && !file_exists(__DIR__ . '/config.php')) {
  redirect('install/index.php');
}

$me = current_user();
if ($me && in_array($me['role'] ?? '', ['admin', 'owner'], true)) {
  redirect(base_url('admin/dashboard.php'));
}
if ($me && !in_array($me['role'] ?? '', ['admin', 'owner'], true)) {
  redirect(base_url('pos/index.php'));
}

$recaptchaSiteKey = setting('recaptcha_site_key', '');
$recaptchaSecretKey = setting('recaptcha_secret_key', '');
$recaptchaAction = 'admin_login';
$recaptchaMinScore = 0.5;

$err = '';
if (login_should_recover()) {
  redirect(base_url('recovery.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $u = trim($_POST['username'] ?? '');
  $p = (string)($_POST['password'] ?? '');
  if ($recaptchaSecretKey === '') {
    $err = 'reCAPTCHA belum diatur oleh admin.';
  } else {
    $recaptchaToken = (string)($_POST['g-recaptcha-response'] ?? '');
    if (!verify_recaptcha_response(
      $recaptchaToken,
      $recaptchaSecretKey,
      $_SERVER['REMOTE_ADDR'] ?? '',
      $recaptchaAction,
      $recaptchaMinScore
    )) {
      $err = 'Verifikasi reCAPTCHA gagal.';
    } elseif (login_attempt($u, $p)) {
      $me = current_user();
      if (!in_array($me['role'] ?? '', ['admin', 'owner'], true)) {
        logout();
        $err = 'Akun ini bukan admin.';
      } else {
        redirect(base_url('admin/dashboard.php'));
      }
    } else {
      $failedAttempts = login_record_failed_attempt();
      if ($failedAttempts >= 3) {
        redirect(base_url('recovery.php'));
      }
      $err = 'Username atau password salah.';
    }
  }
}
$appName = app_config()['app']['name'];
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Login Admin</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
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
        <p><small>Silakan login admin</small></p>
      </div>
      <?php if ($err): ?>
        <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
      <?php endif; ?>
      <form method="post" class="admin-login">
        <div class="row">
          <label>Username</label>
          <input name="username" autocomplete="username" required>
        </div>
        <div class="row">
          <label>Password</label>
          <input type="password" name="password" autocomplete="current-password" required>
        </div>
        <?php if (!empty($recaptchaSiteKey)): ?>
          <input type="hidden" name="g-recaptcha-response" id="recaptcha-admin-token">
        <?php else: ?>
          <div class="card" style="margin-top:12px;border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)">
            reCAPTCHA belum disetting. Hubungi admin.
          </div>
        <?php endif; ?>
        <button class="btn" type="submit" style="width:100%" <?php echo $recaptchaSiteKey === '' ? 'disabled' : ''; ?>>Masuk</button>
      </form>
      <div class="center" style="margin-top:12px">
        <a href="<?php echo e(base_url('recovery.php')); ?>">Recovery Password</a>
      </div>
    </div>
  </div>
  <?php if (!empty($recaptchaSiteKey)): ?>
    <script src="https://www.google.com/recaptcha/api.js?render=<?php echo e($recaptchaSiteKey); ?>"></script>
    <script>
      (function () {
        const form = document.querySelector('.admin-login');
        if (!form) return;
        const tokenInput = document.getElementById('recaptcha-admin-token');
        if (!tokenInput) return;
        form.addEventListener('submit', function (event) {
          if (form.dataset.recaptchaReady === '1') return;
          event.preventDefault();
          grecaptcha.ready(function () {
            grecaptcha.execute('<?php echo e($recaptchaSiteKey); ?>', { action: '<?php echo e($recaptchaAction); ?>' })
              .then(function (token) {
                tokenInput.value = token;
                form.dataset.recaptchaReady = '1';
                form.submit();
              })
              .catch(function () {
                form.dataset.recaptchaReady = '';
                form.submit();
              });
          });
        });
      })();
    </script>
  <?php endif; ?>
</body>
</html>
