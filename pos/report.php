<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../core/inventory.php';
require_once __DIR__ . '/../core/pos_sales_history.php';

start_secure_session();
require_login();
require_menu_access('pos');
ensure_sales_transaction_code_column();
ensure_sales_user_column();
ensure_inventory_module_schema();
ensure_sales_revision_schema();

$appName = app_config()['app']['name'];
$storeName = setting('store_name', $appName);
$storeSubtitle = setting('store_subtitle', '');
$storeLogo = setting('store_logo', '');
$storeAddress = setting('store_address', '');
$storePhone = setting('store_phone', '');
$receiptFooter = setting('receipt_footer', '');
$me = current_user();
$branchId = active_branch_id();
$range = pos_sales_history_range($_GET);
$summary = pos_sales_history_summary($range, $branchId);
$isAndroidApp = is_android_app_request();
$logoSrc = '';
if ($storeLogo !== '') {
  $logoSrc = preg_match('/^https?:\/\//i', $storeLogo) ? $storeLogo : upload_url($storeLogo, 'image');
}

$summaryLines = [
  ['label' => 'Jumlah transaksi', 'value' => (string)$summary['transaction_count']],
  ['label' => 'Transaksi aktif', 'value' => (string)$summary['active_transaction_count']],
  ['label' => 'Jumlah item', 'value' => format_number_id((float)$summary['item_qty'], 0)],
  ['label' => 'Tunai (' . $summary['cash_count'] . ')', 'value' => format_number_id((float)$summary['cash_amount'])],
  ['label' => 'QRIS (' . $summary['qris_count'] . ')', 'value' => format_number_id((float)$summary['qris_amount'])],
];
if ((int)$summary['other_count'] > 0) {
  $summaryLines[] = ['label' => 'Lainnya (' . $summary['other_count'] . ')', 'value' => format_number_id((float)$summary['other_amount'])];
}
$summaryLines[] = ['label' => 'Penjualan kotor', 'value' => format_number_id((float)$summary['gross_sales'])];
$summaryLines[] = ['label' => 'Retur (' . $summary['returned_transaction_count'] . ')', 'value' => format_number_id((float)$summary['return_amount'])];
$summaryLines[] = ['label' => 'PENJUALAN BERSIH', 'value' => format_number_id((float)$summary['net_sales']), 'emphasis' => true];

$reportItems = [];
foreach ($summary['products'] as $product) {
  $reportItems[] = [
    'name' => (string)$product['product_name'],
    'qty' => (float)$product['qty_sold'],
    'price' => 0,
    'subtotal' => (float)$product['sales_amount'],
  ];
}

$payload = [
  'document_type' => 'sales_report',
  'receipt_id' => 'RPT-' . $range['start']->format('Ymd') . '-' . $range['end']->format('Ymd'),
  'tanggal_jam' => date('d/m/Y H:i'),
  'cashier' => (string)($me['name'] ?? '-'),
  'store_name' => $storeName,
  'store_subtitle' => $storeSubtitle,
  'store_address' => $storeAddress,
  'store_phone' => $storePhone,
  'footer' => $receiptFooter,
  'logo_url' => $logoSrc,
  'payment_method' => '',
  'total' => (int)round((float)$summary['net_sales']),
  'bayar' => 0,
  'kembalian' => 0,
  'paper_width' => 58,
  'report_title' => 'LAPORAN PENJUALAN',
  'period_label' => $range['label'],
  'summary_lines' => $summaryLines,
  'items' => $reportItems,
];
$backUrl = base_url('pos/history.php?' . pos_sales_history_query_string($range, ['view' => 'recap']));
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Rekap Penjualan 58 mm</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('pos/receipt-print.css')); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('pos/report-print.css')); ?>">
</head>
<body>
<div class="receipt-page"
  data-receipt-bridge="1"
  data-is-android-app="<?php echo $isAndroidApp ? '1' : '0'; ?>"
  data-android-bridge-name="AndroidBridge">
  <div class="receipt-toolbar no-print">
    <div>
      <strong>Rekap Penjualan 58 mm</strong>
      <ul>
        <li>Pastikan printer thermal 58 mm sudah dipilih.</li>
        <li>Rekap mengikuti cabang aktif dan periode terpilih.</li>
        <li>Transaksi retur dikurangi dari penjualan bersih.</li>
      </ul>
    </div>
    <div class="receipt-toolbar-actions">
      <button class="btn" type="button" data-print-via-app>Cetak 58 mm</button>
      <button class="btn btn-secondary" type="button" data-print-window>Print Browser</button>
      <button class="btn btn-muted" type="button" data-open-printer-settings hidden>Pengaturan Printer</button>
      <a class="btn btn-muted" href="<?php echo e($backUrl); ?>">Kembali</a>
    </div>
  </div>
  <div class="receipt-notice no-print" data-receipt-bridge-notice hidden></div>

  <article class="receipt report-receipt" id="receipt-print-root" role="document">
    <header class="receipt-header">
      <?php if ($logoSrc): ?><div class="receipt-logo"><img src="<?php echo e($logoSrc); ?>" alt="<?php echo e($storeName); ?>"></div><?php endif; ?>
      <div class="receipt-store">
        <div class="receipt-store-name"><?php echo e($storeName); ?></div>
        <?php if ($storeSubtitle): ?><div class="receipt-store-line"><?php echo e($storeSubtitle); ?></div><?php endif; ?>
        <?php if ($storeAddress): ?><div class="receipt-store-line"><?php echo e($storeAddress); ?></div><?php endif; ?>
        <?php if ($storePhone): ?><div class="receipt-store-line">Telp: <?php echo e($storePhone); ?></div><?php endif; ?>
      </div>
    </header>

    <div class="report-title">LAPORAN PENJUALAN</div>
    <div class="receipt-meta">
      <div>Periode: <?php echo e($range['label']); ?></div>
      <div>Dicetak: <?php echo e(date('d/m/Y H:i')); ?></div>
      <div>Oleh: <?php echo e((string)($me['name'] ?? '-')); ?></div>
    </div>

    <div class="report-summary">
      <?php foreach ($summaryLines as $line): ?>
        <div class="receipt-line <?php echo !empty($line['emphasis']) ? 'report-emphasis' : ''; ?>">
          <span><?php echo e((string)$line['label']); ?></span>
          <span><?php echo e((string)$line['value']); ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="report-section-title">REKAP PRODUK</div>
    <?php if (empty($reportItems)): ?>
      <div class="report-empty">Belum ada produk terjual.</div>
    <?php else: ?>
      <div class="receipt-items report-products">
        <?php foreach ($reportItems as $item): ?>
          <div class="receipt-item">
            <div class="receipt-item-name"><?php echo e((string)$item['name']); ?></div>
            <div class="receipt-item-row">
              <div class="receipt-item-qty"><?php echo e(format_number_id((float)$item['qty'], 0)); ?> item</div>
              <div class="receipt-item-subtotal">Rp <?php echo e(format_number_id((float)$item['subtotal'])); ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($summary['daily']) && count($summary['daily']) > 1): ?>
      <div class="report-section-title">REKAP PER HARI</div>
      <div class="report-daily">
        <?php foreach ($summary['daily'] as $day): ?>
          <div class="receipt-line"><span><?php echo e(date('d/m/Y', strtotime((string)$day['date']))); ?></span><span><?php echo e((string)$day['transactions']); ?> trx</span></div>
          <div class="receipt-line report-daily-net"><span>Bersih</span><span>Rp <?php echo e(format_number_id((float)$day['net'])); ?></span></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($receiptFooter): ?><footer class="receipt-footer"><?php echo e($receiptFooter); ?></footer><?php endif; ?>
  </article>
</div>
<script id="receipt-bridge-payload" type="application/json"><?php echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<script src="<?php echo e(asset_url('pos/receipt-bridge.js')); ?>"></script>
</body>
</html>
