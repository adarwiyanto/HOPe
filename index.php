<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/functions.php';

try {
  $products = db()->query("SELECT * FROM products ORDER BY id DESC LIMIT 30")->fetchAll();
} catch (Throwable $e) {
  header('Location: install/index.php');
  exit;
}
$appName = app_config()['app']['name'];
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo e($appName); ?></title>
  <link rel="stylesheet" href="<?php echo e(base_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body>
  <div class="content">
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px">
        <div>
          <h2 style="margin:0"><?php echo e($appName); ?></h2>
          <p style="margin:6px 0 0"><small>Katalog produk sederhana</small></p>
        </div>
        <a class="btn" href="<?php echo e(base_url('login.php')); ?>">Admin Login</a>
      </div>
    </div>

    <div class="grid cols-2" style="margin-top:16px">
      <?php foreach ($products as $p): ?>
        <div class="card">
          <div style="display:flex;gap:12px;align-items:center">
            <?php if (!empty($p['image_path'])): ?>
              <img class="thumb" src="<?php echo e(base_url($p['image_path'])); ?>" alt="">
            <?php else: ?>
              <div class="thumb" style="display:flex;align-items:center;justify-content:center;color:var(--muted)">No Img</div>
            <?php endif; ?>
            <div style="flex:1">
              <div style="font-weight:700"><?php echo e($p['name']); ?></div>
              <div class="badge">Rp <?php echo e(number_format((float)$p['price'], 0, '.', ',')); ?></div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</body>
</html>
