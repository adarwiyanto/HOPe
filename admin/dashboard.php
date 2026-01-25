<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';

require_login();

$appName = app_config()['app']['name'];
$customCss = setting('custom_css', '');
$u = current_user();

$stats = [
  'products' => (int)db()->query("SELECT COUNT(*) c FROM products")->fetch()['c'],
  'sales' => (int)db()->query("SELECT COUNT(*) c FROM sales")->fetch()['c'],
  'revenue' => (float)db()->query("SELECT COALESCE(SUM(total),0) s FROM sales")->fetch()['s'],
];
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin</title>
  <link rel="stylesheet" href="<?php echo e(base_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body>
  <div class="container">
    <?php include __DIR__ . '/partials_sidebar.php'; ?>
    <div class="main">
        <div class="topbar">
        <button class="burger" data-toggle-sidebar type="button">☰</button>
        <div class="title">Dasbor</div>

        <div class="spacer"></div>

        <div class="top-actions">
            <div class="chip">🔎 <span>Pencari Pintar</span></div>
            <button class="iconbtn" type="button" title="Notif">
            🔔 <span class="dot"></span>
            </button>
            <div class="chip">🏷️ <span>Adena Belitung</span> ▾</div>
        </div>
        </div>

      <div class="content">
        <div class="grid cols-2">
          <div class="card">
            <h3 style="margin-top:0">Ringkasan</h3>
            <p><small>Jumlah Produk: <?php echo e((string)$stats['products']); ?></small></p>
            <p><small>Transaksi Penjualan: <?php echo e((string)$stats['sales']); ?></small></p>
            <p><small>Total Omzet: Rp <?php echo e(number_format($stats['revenue'], 0, '.', ',')); ?></small></p>
          </div>
          <div class="card">
            <h3 style="margin-top:0">Akses Cepat</h3>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
              <a class="btn" href="<?php echo e(base_url('admin/products.php')); ?>">Produk</a>
              <a class="btn" href="<?php echo e(base_url('admin/sales.php')); ?>">Penjualan</a>
              <a class="btn" href="<?php echo e(base_url('admin/theme.php')); ?>">Tema</a>
            </div>
            <p><small>Kalau ada yang error, biasanya karena base_url salah—yang benar itu URL folder di htdocs.</small></p>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script src="<?php echo e(base_url('assets/app.js')); ?>"></script>
</body>
</html>
