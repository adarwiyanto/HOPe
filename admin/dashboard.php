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
$customStart = $_GET['start'] ?? '';
$customEnd = $_GET['end'] ?? '';

$rangeLabel = '';
$startAt = null;
$endAt = null;

$today = new DateTime('today');
$endToday = new DateTime('today 23:59:59');

switch ($range) {
  case 'yesterday':
    $startAt = (clone $today)->modify('-1 day');
    $endAt = (clone $today)->modify('-1 day 23:59:59');
    $rangeLabel = 'Kemarin';
    break;
  case 'last7':
    $startAt = (clone $today)->modify('-6 day');
    $endAt = $endToday;
    $rangeLabel = '7 Hari Terakhir';
    break;
  case 'this_month':
    $startAt = new DateTime('first day of this month 00:00:00');
    $endAt = new DateTime('last day of this month 23:59:59');
    $rangeLabel = 'Bulan Ini';
    break;
  case 'last_month':
    $startAt = new DateTime('first day of last month 00:00:00');
    $endAt = new DateTime('last day of last month 23:59:59');
    $rangeLabel = 'Bulan Lalu';
    break;
  case 'custom':
    $startAt = $customStart ? DateTime::createFromFormat('Y-m-d', $customStart) : null;
    $endAt = $customEnd ? DateTime::createFromFormat('Y-m-d', $customEnd) : null;
    if ($startAt && $endAt) {
      $startAt->setTime(0, 0, 0);
      $endAt->setTime(23, 59, 59);
      $rangeLabel = 'Custom';
    } else {
      $startAt = $today;
      $endAt = $endToday;
      $rangeLabel = 'Hari Ini';
    }
    break;
  case 'today':
  default:
    $startAt = $today;
    $endAt = $endToday;
    $rangeLabel = 'Hari Ini';
    $range = 'today';
    break;
}

$stats = [
  'products' => (int)db()->query("SELECT COUNT(*) c FROM products")->fetch()['c'],
];

$salesStatsStmt = db()->prepare("SELECT COUNT(*) c, COALESCE(SUM(total),0) s FROM sales WHERE sold_at BETWEEN ? AND ? AND returned_at IS NULL");
$salesStatsStmt->execute([
  $startAt->format('Y-m-d H:i:s'),
  $endAt->format('Y-m-d H:i:s'),
]);
$salesStats = $salesStatsStmt->fetch();

$stats['sales'] = (int)$salesStats['c'];
$stats['revenue'] = (float)$salesStats['s'];
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
            <form method="get" style="display:grid;gap:10px;margin-bottom:12px">
              <label>
                <small>Pilih Rentang</small>
                <select name="range" id="range-select">
                  <option value="today" <?php echo $range === 'today' ? 'selected' : ''; ?>>Hari Ini</option>
                  <option value="yesterday" <?php echo $range === 'yesterday' ? 'selected' : ''; ?>>Kemarin</option>
                  <option value="last7" <?php echo $range === 'last7' ? 'selected' : ''; ?>>7 Hari Terakhir</option>
                  <option value="this_month" <?php echo $range === 'this_month' ? 'selected' : ''; ?>>Bulan Ini</option>
                  <option value="last_month" <?php echo $range === 'last_month' ? 'selected' : ''; ?>>Bulan Lalu</option>
                  <option value="custom" <?php echo $range === 'custom' ? 'selected' : ''; ?>>Custom</option>
                </select>
              </label>
              <div id="custom-range" style="<?php echo $range === 'custom' ? 'display:flex;' : 'display:none;'; ?>gap:8px;flex-wrap:wrap">
                <label>
                  <small>Tanggal Mulai</small>
                  <input type="date" name="start" value="<?php echo e($customStart); ?>">
                </label>
                <label>
                  <small>Tanggal Selesai</small>
                  <input type="date" name="end" value="<?php echo e($customEnd); ?>">
                </label>
              </div>
              <button class="btn" type="submit">Terapkan</button>
            </form>
            <p><small>Rentang aktif: <?php echo e($rangeLabel); ?> (<?php echo e($startAt->format('d/m/Y')); ?> - <?php echo e($endAt->format('d/m/Y')); ?>)</small></p>
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
    const rangeSelect = document.getElementById('range-select');
    const customRange = document.getElementById('custom-range');
    if (rangeSelect && customRange) {
      rangeSelect.addEventListener('change', () => {
        customRange.style.display = rangeSelect.value === 'custom' ? 'flex' : 'none';
      });
    }
  </script>
</body>
</html>
