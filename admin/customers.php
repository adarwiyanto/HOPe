<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';

require_admin();
ensure_landing_order_tables();

$customers = db()->query("
  SELECT c.id, c.name, c.email, c.created_at,
         MAX(o.created_at) AS last_order_at,
         COUNT(o.id) AS order_count
  FROM customers c
  LEFT JOIN orders o ON o.customer_id = c.id
  GROUP BY c.id
  ORDER BY last_order_at DESC, c.created_at DESC
  LIMIT 200
")->fetchAll();

$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Data Pelanggan</title>
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
      <div class="badge">Data Pelanggan</div>
    </div>

    <div class="content">
      <div class="card">
        <h3 style="margin-top:0">Pelanggan Landing Page</h3>
        <table class="table">
          <thead>
            <tr>
              <th>Nama</th>
              <th>Email</th>
              <th>Order</th>
              <th>Order Terakhir</th>
              <th>Terdaftar</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($customers)): ?>
              <tr><td colspan="5">Belum ada data pelanggan.</td></tr>
            <?php else: ?>
              <?php foreach ($customers as $c): ?>
                <tr>
                  <td><?php echo e($c['name']); ?></td>
                  <td><a href="mailto:<?php echo e($c['email']); ?>"><?php echo e($c['email']); ?></a></td>
                  <td><?php echo e((string)$c['order_count']); ?></td>
                  <td><?php echo e($c['last_order_at'] ?? '-'); ?></td>
                  <td><?php echo e($c['created_at']); ?></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
