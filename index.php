<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/functions.php';

try {
  ensure_products_favorite_column();
  $products = db()->query("SELECT * FROM products WHERE is_favorite = 1 ORDER BY id DESC LIMIT 30")->fetchAll();
} catch (Throwable $e) {
  header('Location: install/index.php');
  exit;
}
$appName = app_config()['app']['name'];
$storeName = setting('store_name', $appName);
$storeSubtitle = setting('store_subtitle', 'Katalog produk sederhana');
$storeIntro = setting('store_intro', 'Kami adalah usaha yang menghadirkan produk pilihan dengan kualitas terbaik untuk kebutuhan Anda.');
$storeLogo = setting('store_logo', '');
$customCss = setting('custom_css', '');
$landingCss = setting('landing_css', '');
$landingHtml = setting('landing_html', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo e($storeName); ?></title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="apple-touch-icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="manifest" href="<?php echo e(base_url('manifest.php')); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?><?php echo $landingCss; ?></style>
</head>
<body>
  <?php
    $productCards = '';
    ob_start();
  ?>
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
  <?php
    $productCards = ob_get_clean();
    $logoBlock = '';
    $storeLogoUrl = '';
    if (!empty($storeLogo)) {
      $storeLogoUrl = base_url($storeLogo);
      $logoBlock = '<img src="' . e($storeLogoUrl) . '" alt="' . e($storeName) . '" style="width:56px;height:56px;object-fit:cover;border-radius:12px;border:1px solid var(--border)">';
    }
    $landingTemplate = $landingHtml !== '' ? $landingHtml : landing_default_html();
    echo strtr($landingTemplate, [
      '{{store_name}}' => e($storeName),
      '{{store_subtitle}}' => e($storeSubtitle),
      '{{store_intro}}' => e($storeIntro),
      '{{store_logo}}' => e($storeLogoUrl),
      '{{store_logo_block}}' => $logoBlock,
      '{{login_url}}' => e(base_url('login.php')),
      '{{products}}' => $productCards,
    ]);
  ?>
</body>
</html>
