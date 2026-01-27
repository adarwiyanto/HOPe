<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

require_admin();

$customCss = setting('custom_css', '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $css = (string)($_POST['custom_css'] ?? '');
  set_setting('custom_css', $css);
  redirect(base_url('admin/theme.php'));
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Tema</title>
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
      <div class="badge">Custom CSS</div>
    </div>

    <div class="content">
      <div class="card">
        <h3 style="margin-top:0">Edit CSS</h3>
        <p><small>CSS di sini akan di-inject ke semua halaman. Jangan masukkan tag &lt;style&gt;.</small></p>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <textarea name="custom_css" rows="14" style="font-family:ui-monospace,Consolas,monospace"><?php echo e($customCss); ?></textarea>
          <div style="margin-top:10px">
            <button class="btn" type="submit">Simpan</button>
          </div>
        </form>

        <p><small>Contoh: <br>
          :root { --accent:#a78bfa; } <br>
          .brand .logo { background: rgba(167,139,250,.18); }
        </small></p>
      </div>
    </div>
  </div>
</div>
<script src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
