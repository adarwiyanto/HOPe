<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

require_admin();

$appName = app_config()['app']['name'];
$storeName = setting('store_name', $appName);
$storeSubtitle = setting('store_subtitle', 'Katalog produk sederhana');
$storeLogo = setting('store_logo', '');
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  $name = trim($_POST['store_name'] ?? '');
  $subtitle = trim($_POST['store_subtitle'] ?? '');
  $removeLogo = isset($_POST['remove_logo']);

  try {
    if ($name === '') throw new Exception('Nama toko wajib diisi.');

    $logoPath = $storeLogo;

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
    set_setting('store_logo', $logoPath);

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
  <link rel="stylesheet" href="<?php echo e(base_url('assets/app.css')); ?>">
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
          <div class="row">
            <label>Nama Toko</label>
            <input name="store_name" value="<?php echo e($_POST['store_name'] ?? $storeName); ?>" required>
          </div>
          <div class="row">
            <label>Subjudul</label>
            <input name="store_subtitle" value="<?php echo e($_POST['store_subtitle'] ?? $storeSubtitle); ?>">
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
    </div>
  </div>
</div>
<script src="<?php echo e(base_url('assets/app.js')); ?>"></script>
</body>
</html>
