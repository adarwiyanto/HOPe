<?php
require_once __DIR__ . '/config.local.php';
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/security.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/csrf.php';
require_once __DIR__ . '/core/customer_auth.php';

start_secure_session();
ensure_landing_order_tables();
ensure_customer_username_schema();
customer_bootstrap_from_cookie();

$redirectInternal = static function (?array $user): void {
  if (!$user) return;
  if (in_array($user['role'] ?? '', ['admin', 'owner'], true)) {
    redirect(base_url('admin/dashboard.php'));
  }
  redirect(base_url('pos/index.php'));
};

$internal = current_user();
if ($internal) {
  $redirectInternal($internal);
}
$customer = customer_current();
if ($customer) {
  redirect(base_url('customer.php'));
}

$err = '';
$ok = '';
$activeTab = ($_GET['tab'] ?? 'login') === 'register' ? 'register' : 'login';

$recaptchaSiteKey = setting('recaptcha_site_key', '');
$recaptchaSecretKey = setting('recaptcha_secret_key', '');
$recaptchaAction = 'customer_register';
$recaptchaMinScore = 0.5;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';

  try {
    if ($action === 'login') {
      $activeTab = 'login';
      $username = normalize_username((string)($_POST['username'] ?? ''));
      $password = (string)($_POST['password'] ?? '');
      if ($username === '' || $password === '') {
        throw new Exception('Username atau password salah.');
      }

      $rateId = $username . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
      if (!rate_limit_check('public_login', $rateId)) {
        throw new Exception('Terlalu banyak percobaan login. Silakan coba lagi nanti.');
      }

      if (login_attempt($username, $password)) {
        rate_limit_clear('public_login', $rateId);
        $redirectInternal(current_user());
      }

      if (customer_login_by_username($username, $password)) {
        rate_limit_clear('public_login', $rateId);
        redirect(base_url('customer.php'));
      }

      rate_limit_record('public_login', $rateId);
      throw new Exception('Username atau password salah.');
    }

    if ($action === 'register') {
      $activeTab = 'register';
      $name = trim((string)($_POST['name'] ?? ''));
      $username = normalize_username((string)($_POST['username'] ?? ''));
      $email = strtolower(trim((string)($_POST['email'] ?? '')));
      $phone = trim((string)($_POST['phone'] ?? ''));
      $password = (string)($_POST['password'] ?? '');
      $password2 = (string)($_POST['password_confirm'] ?? '');
      $gender = trim((string)($_POST['gender'] ?? ''));
      $birthDate = trim((string)($_POST['birth_date'] ?? ''));

      if ($name === '') throw new Exception('Nama wajib diisi.');
      if ($username === '' || !is_valid_username($username)) {
        throw new Exception('Username wajib 3-50 karakter (huruf/angka/titik/underscore/dash).');
      }
      if (username_exists_anywhere($username)) {
        throw new Exception('Username sudah dipakai. Gunakan username lain.');
      }
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Email tidak valid.');
      }
      $stmt = db()->prepare("SELECT id FROM customers WHERE LOWER(email)=? LIMIT 1");
      $stmt->execute([$email]);
      if ($stmt->fetch()) {
        throw new Exception('Email sudah dipakai. Masukkan email lain.');
      }
      if ($phone === '' || !preg_match('/^[0-9+][0-9\s\-]{6,20}$/', $phone)) {
        throw new Exception('Nomor telepon tidak valid.');
      }
      $stmt = db()->prepare("SELECT id FROM customers WHERE phone=? LIMIT 1");
      $stmt->execute([$phone]);
      if ($stmt->fetch()) {
        throw new Exception('Nomor telepon sudah terdaftar.');
      }
      if (strlen($password) < 6) {
        throw new Exception('Password minimal 6 karakter.');
      }
      if ($password !== $password2) {
        throw new Exception('Konfirmasi password tidak cocok.');
      }
      if (!in_array($gender, ['male', 'female', 'other'], true)) {
        throw new Exception('Pilih jenis kelamin.');
      }
      $birth = DateTimeImmutable::createFromFormat('Y-m-d', $birthDate);
      if (!$birth) throw new Exception('Tanggal lahir tidak valid.');

      if ($recaptchaSecretKey === '') {
        throw new Exception('reCAPTCHA belum diatur oleh admin.');
      }
      $recaptchaToken = (string)($_POST['g-recaptcha-response'] ?? '');
      if (!verify_recaptcha_response(
        $recaptchaToken,
        $recaptchaSecretKey,
        $_SERVER['REMOTE_ADDR'] ?? '',
        $recaptchaAction,
        $recaptchaMinScore
      )) {
        throw new Exception('Verifikasi reCAPTCHA gagal.');
      }

      $hash = password_hash($password, PASSWORD_DEFAULT);
      $stmt = db()->prepare("INSERT INTO customers (name, username, email, phone, password_hash, gender, birth_date) VALUES (?,?,?,?,?,?,?)");
      $stmt->execute([$name, $username, $email, $phone, $hash, $gender, $birthDate]);
      $customerId = (int)db()->lastInsertId();

      $stmt = db()->prepare("SELECT * FROM customers WHERE id=? LIMIT 1");
      $stmt->execute([$customerId]);
      $row = $stmt->fetch();
      if ($row) {
        customer_create_session($row);
      }
      $ok = 'Pendaftaran berhasil. Selamat datang!';
      redirect(base_url('customer.php'));
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Masuk / Daftar</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
  <style>
    .auth-wrap{max-width:760px;margin:6vh auto}
    .auth-tabs{display:flex;gap:8px;margin-bottom:12px}
    .auth-tab{flex:1;text-align:center;padding:10px;border-radius:10px;background:rgba(148,163,184,.15);color:inherit;text-decoration:none}
    .auth-tab.active{background:rgba(59,130,246,.15);font-weight:700}
    .auth-grid{display:grid;grid-template-columns:1fr;gap:16px}
    @media(min-width:900px){.auth-grid{grid-template-columns:1fr 1fr}}
  </style>
</head>
<body>
  <div class="auth-wrap">
    <div class="card">
      <a class="btn btn-light" href="<?php echo e(base_url('index.php')); ?>">← Kembali ke Landing</a>
      <h2 style="margin-bottom:6px">Masuk / Daftar</h2>
      <p style="margin-top:0;color:var(--muted)">Login untuk akun internal maupun customer. Daftar hanya untuk customer.</p>

      <?php if ($err): ?><div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="card" style="border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.10)"><?php echo e($ok); ?></div><?php endif; ?>

      <div class="auth-tabs">
        <a class="auth-tab <?php echo $activeTab === 'login' ? 'active' : ''; ?>" href="<?php echo e(base_url('auth.php?tab=login')); ?>">Masuk</a>
        <a class="auth-tab <?php echo $activeTab === 'register' ? 'active' : ''; ?>" href="<?php echo e(base_url('auth.php?tab=register')); ?>">Daftar Customer</a>
      </div>

      <?php if ($activeTab === 'login'): ?>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="login">
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
        <div style="margin-top:10px"><small><a href="<?php echo e(base_url('adm.php')); ?>">Tetap gunakan login admin lama (adm.php)</a> bila diperlukan aplikasi Android.</small></div>
      <?php else: ?>
        <form method="post" class="customer-register">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="register">
          <div class="auth-grid">
            <div class="row"><label>Nama</label><input name="name" required></div>
            <div class="row"><label>Username</label><input name="username" required></div>
            <div class="row"><label>Email</label><input name="email" type="email" required></div>
            <div class="row"><label>Nomor Telepon</label><input name="phone" type="tel" inputmode="tel" required></div>
            <div class="row"><label>Password</label><input name="password" type="password" minlength="6" required></div>
            <div class="row"><label>Konfirmasi Password</label><input name="password_confirm" type="password" minlength="6" required></div>
            <div class="row">
              <label>Jenis Kelamin</label>
              <select name="gender" required>
                <option value="">-- pilih --</option>
                <option value="male">Laki-laki</option>
                <option value="female">Perempuan</option>
                <option value="other">Lainnya</option>
              </select>
            </div>
            <div class="row"><label>Tanggal Lahir</label><input name="birth_date" type="date" required></div>
          </div>
          <?php if (!empty($recaptchaSiteKey)): ?>
            <input type="hidden" name="g-recaptcha-response" id="recaptcha-register-token">
          <?php else: ?>
            <div class="card" style="margin-top:12px;border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)">reCAPTCHA belum disetting. Hubungi admin.</div>
          <?php endif; ?>
          <button class="btn" type="submit" style="margin-top:10px" <?php echo $recaptchaSiteKey === '' ? 'disabled' : ''; ?>>Daftar Customer</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($activeTab === 'register' && !empty($recaptchaSiteKey)): ?>
    <script defer src="https://www.google.com/recaptcha/api.js?render=<?php echo e($recaptchaSiteKey); ?>"></script>
    <script nonce="<?php echo e(csp_nonce()); ?>">
      (function () {
        const form = document.querySelector('.customer-register');
        const tokenInput = document.getElementById('recaptcha-register-token');
        if (!form || !tokenInput) return;
        const siteKey = '<?php echo e($recaptchaSiteKey); ?>';
        const action = '<?php echo e($recaptchaAction); ?>';

        form.addEventListener('submit', function (event) {
          event.preventDefault();
          if (!window.grecaptcha || typeof window.grecaptcha.ready !== 'function') {
            alert('reCAPTCHA belum siap. Coba lagi.');
            return;
          }
          window.grecaptcha.ready(function () {
            window.grecaptcha.execute(siteKey, { action: action }).then(function (token) {
              tokenInput.value = token || '';
              form.submit();
            }).catch(function () {
              alert('Verifikasi reCAPTCHA gagal.');
            });
          });
        });
      })();
    </script>
  <?php endif; ?>
</body>
</html>
