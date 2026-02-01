<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

require_admin();
ensure_landing_order_tables();

$appName = app_config()['app']['name'];
$storeName = setting('store_name', $appName);
$storeSubtitle = setting('store_subtitle', 'Katalog produk sederhana');
$storeIntro = setting('store_intro', 'Kami adalah usaha yang menghadirkan produk pilihan dengan kualitas terbaik untuk kebutuhan Anda.');
$storeLogo = setting('store_logo', '');
$recaptchaSiteKey = setting('recaptcha_site_key', '');
$recaptchaSecretKey = setting('recaptcha_secret_key', '');
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? 'store';

  $name = trim($_POST['store_name'] ?? '');
  $subtitle = trim($_POST['store_subtitle'] ?? '');
  $intro = trim($_POST['store_intro'] ?? '');
  $recaptchaSiteKeyInput = trim($_POST['recaptcha_site_key'] ?? '');
  $recaptchaSecretKeyInput = trim($_POST['recaptcha_secret_key'] ?? '');
  $removeLogo = isset($_POST['remove_logo']);

  try {
    if ($action === 'store' && $name === '') {
      throw new Exception('Nama toko wajib diisi.');
    }

    $logoPath = $storeLogo;

    if ($action === 'store') {
      if ($removeLogo && $storeLogo) {
        $old = __DIR__ . '/../' . $storeLogo;
        if (file_exists($old)) @unlink($old);
        $logoPath = '';
      }

      if (!empty($_FILES['store_logo']['name'])) {
        $f = $_FILES['store_logo'];
        if ($f['error'] !== UPLOAD_ERR_OK) throw new Exception('Upload gagal.');
        if ($f['size'] > 2 * 1024 * 1024) throw new Exception('Maks ukuran logo 2MB.');

        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','webp'];
        if (!in_array($ext, $allowed, true)) throw new Exception('Format logo harus JPG/PNG/WEBP.');

        $dir = __DIR__ . '/../uploads/branding';
        ensure_upload_dir($dir);

        $newName = 'logo_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $dest = $dir . '/' . $newName;

        if (!move_uploaded_file($f['tmp_name'], $dest)) throw new Exception('Gagal menyimpan file upload.');

        if ($storeLogo) {
          $old = __DIR__ . '/../' . $storeLogo;
          if (file_exists($old)) @unlink($old);
        }

        $logoPath = 'uploads/branding/' . $newName;
      }

      set_setting('store_name', $name);
      set_setting('store_subtitle', $subtitle);
      set_setting('store_intro', $intro);
      set_setting('store_logo', $logoPath);
    }

    if ($action === 'recaptcha') {
      set_setting('recaptcha_site_key', $recaptchaSiteKeyInput);
      set_setting('recaptcha_secret_key', $recaptchaSecretKeyInput);
    }

    redirect(base_url('admin/store.php'));
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
  <title>Profil Toko</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <button class="btn" data-toggle-sidebar type="button">Menu</button>
    </div>

    <div class="content">
      <div class="card">
        <h3 style="margin-top:0">Profil Toko</h3>
        <?php if ($err): ?>
          <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="store">
          <div class="row">
            <label>Nama Toko</label>
            <input name="store_name" value="<?php echo e($_POST['store_name'] ?? $storeName); ?>" required>
          </div>
          <div class="row">
            <label>Subjudul</label>
            <input name="store_subtitle" value="<?php echo e($_POST['store_subtitle'] ?? $storeSubtitle); ?>">
          </div>
          <div class="row">
            <label>Perkenalan Usaha</label>
            <textarea name="store_intro" rows="4"><?php echo e($_POST['store_intro'] ?? $storeIntro); ?></textarea>
          </div>
          <div class="row">
            <label>Logo Toko (opsional, max 2MB)</label>
            <input type="file" name="store_logo" accept=".jpg,.jpeg,.png,.webp">
            <?php if (!empty($storeLogo)): ?>
              <div style="margin-top:10px;display:flex;align-items:center;gap:12px">
                <img class="thumb" src="<?php echo e(base_url($storeLogo)); ?>">
                <label style="display:flex;align-items:center;gap:8px">
                  <input type="checkbox" name="remove_logo" value="1">
                  Hapus logo
                </label>
              </div>
            <?php endif; ?>
          </div>
          <button class="btn" type="submit">Simpan</button>
        </form>
      </div>

      <div class="card" style="margin-top:16px">
        <h3 style="margin-top:0">Google reCAPTCHA</h3>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="recaptcha">
          <div class="row">
            <label>Site Key</label>
            <input name="recaptcha_site_key" value="<?php echo e($_POST['recaptcha_site_key'] ?? $recaptchaSiteKey); ?>">
          </div>
          <div class="row">
            <label>Secret Key</label>
            <input name="recaptcha_secret_key" value="<?php echo e($_POST['recaptcha_secret_key'] ?? $recaptchaSecretKey); ?>">
          </div>
          <button class="btn" type="submit">Simpan reCAPTCHA</button>
        </form>
        <p><small>Gunakan kunci reCAPTCHA v3 (score-based) terbaru dari Google.</small></p>
      </div>
    </div>
  </div>
</div>
<script src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
