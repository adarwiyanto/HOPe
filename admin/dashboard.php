<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';

require_admin();

$appName = app_config()['app']['name'];
$storeName = setting('store_name', $appName);
$storeLogo = setting('store_logo', '');
$customCss = setting('custom_css', '');
$u = current_user();

$range = $_GET['range'] ?? 'today';
$rangeStart = null;
$rangeEnd = null;
$rangeLabel = '';

$today = new DateTimeImmutable('today');
switch ($range) {
  case 'yesterday':
    $rangeStart = $today->modify('-1 day');
    $rangeEnd = $today;
    $rangeLabel = 'Kemarin';
    break;
  case 'last7':
    $rangeStart = $today->modify('-6 days');
    $rangeEnd = $today->modify('+1 day');
    $rangeLabel = '7 Hari Terakhir';
    break;
  case 'this_month':
    $rangeStart = $today->modify('first day of this month');
    $rangeEnd = $rangeStart->modify('+1 month');
    $rangeLabel = 'Bulan Ini';
    break;
  case 'last_month':
    $rangeStart = $today->modify('first day of last month');
    $rangeEnd = $rangeStart->modify('+1 month');
    $rangeLabel = 'Bulan Lalu';
    break;
  case 'custom':
    $startInput = $_GET['start'] ?? '';
    $endInput = $_GET['end'] ?? '';
    if ($startInput && $endInput) {
      $rangeStart = new DateTimeImmutable($startInput);
      $rangeEnd = (new DateTimeImmutable($endInput))->modify('+1 day');
      $rangeLabel = 'Custom';
    } else {
      $rangeStart = $today;
      $rangeEnd = $today->modify('+1 day');
      $rangeLabel = 'Hari Ini';
      $range = 'today';
    }
    break;
  case 'today':
  default:
    $rangeStart = $today;
    $rangeEnd = $today->modify('+1 day');
    $rangeLabel = 'Hari Ini';
    $range = 'today';
    break;
}

$stats = [
  'products' => (int)db()->query("SELECT COUNT(*) c FROM products")->fetch()['c'],
  'sales' => 0,
  'revenue' => 0.0,
];

$stmt = db()->prepare("
  SELECT COUNT(*) c, COALESCE(SUM(total),0) s
  FROM sales
  WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL
");
$stmt->execute([
  $rangeStart->format('Y-m-d H:i:s'),
  $rangeEnd->format('Y-m-d H:i:s'),
]);
$statsRange = $stmt->fetch();
$stats['sales'] = (int)($statsRange['c'] ?? 0);
$stats['revenue'] = (float)($statsRange['s'] ?? 0);
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
        <a class="brand-logo" href="<?php echo e(base_url('admin/dashboard.php')); ?>">
          <?php if (!empty($storeLogo)): ?>
            <img src="<?php echo e(base_url($storeLogo)); ?>" alt="<?php echo e($storeName); ?>">
          <?php else: ?>
            <span><?php echo e($storeName); ?></span>
          <?php endif; ?>
        </a>
        <button class="burger" data-toggle-sidebar type="button">☰</button>
        <div class="title">Dasbor</div>
        <div class="spacer"></div>
        </div>

      <div class="content">
        <div class="grid cols-2">
          <div class="card">
            <h3 style="margin-top:0">Ringkasan</h3>
            <form method="get" style="margin-bottom:12px">
              <div class="row">
                <label>Periode</label>
                <select name="range" id="sales-range">
                  <option value="today" <?php echo $range === 'today' ? 'selected' : ''; ?>>Hari ini</option>
                  <option value="yesterday" <?php echo $range === 'yesterday' ? 'selected' : ''; ?>>Kemarin</option>
                  <option value="last7" <?php echo $range === 'last7' ? 'selected' : ''; ?>>7 hari terakhir</option>
                  <option value="this_month" <?php echo $range === 'this_month' ? 'selected' : ''; ?>>Bulan ini</option>
                  <option value="last_month" <?php echo $range === 'last_month' ? 'selected' : ''; ?>>Bulan lalu</option>
                  <option value="custom" <?php echo $range === 'custom' ? 'selected' : ''; ?>>Custom</option>
                </select>
              </div>
              <div class="row" id="custom-range" style="display:<?php echo $range === 'custom' ? 'grid' : 'none'; ?>;gap:8px">
                <label for="start">Mulai</label>
                <input type="date" name="start" id="start" value="<?php echo e($_GET['start'] ?? $today->format('Y-m-d')); ?>">
                <label for="end">Sampai</label>
                <input type="date" name="end" id="end" value="<?php echo e($_GET['end'] ?? $today->format('Y-m-d')); ?>">
              </div>
              <button class="btn" type="submit">Terapkan</button>
            </form>
            <p><small>Periode: <?php echo e($rangeLabel); ?></small></p>
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
  <script>
    const rangeSelect = document.querySelector('#sales-range');
    const customRange = document.querySelector('#custom-range');
    if (rangeSelect && customRange) {
      rangeSelect.addEventListener('change', () => {
        customRange.style.display = rangeSelect.value === 'custom' ? 'grid' : 'none';
      });
    }
  </script>
</body>
</html>
