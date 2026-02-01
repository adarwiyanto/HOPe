<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

require_admin();
ensure_landing_order_tables();

$pointsPerOrder = (int)setting('loyalty_points_per_order', '0');
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $pointsInput = (int)($_POST['points_per_order'] ?? 0);
  if ($pointsInput < 0) {
    $err = 'Poin tidak boleh negatif.';
  } else {
    set_setting('loyalty_points_per_order', (string)$pointsInput);
    redirect(base_url('admin/loyalty.php'));
  }
}

$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Loyalti Point</title>
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
        <h3 style="margin-top:0">Pengaturan Loyalti Point</h3>
        <?php if ($err): ?>
          <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <div class="row">
            <label>Poin per transaksi (pesanan dibuat)</label>
            <input name="points_per_order" type="number" min="0" value="<?php echo e($_POST['points_per_order'] ?? (string)$pointsPerOrder); ?>" required>
          </div>
          <button class="btn" type="submit">Simpan</button>
        </form>
        <p><small>Poin akan ditambahkan setiap kali pesanan checkout dilakukan dari landing page.</small></p>
      </div>
    </div>
  </div>
</div>
<script src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
