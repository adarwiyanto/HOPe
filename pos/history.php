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
ensure_sales_loyalty_columns();
ensure_inventory_module_schema();
ensure_sales_revision_schema();

$appName = app_config()['app']['name'];
$me = current_user();
$branchId = active_branch_id();
$range = pos_sales_history_range($_GET);
$view = strtolower(trim((string)($_GET['view'] ?? 'detail')));
if (!in_array($view, ['detail', 'recap'], true)) $view = 'detail';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$totalRows = pos_sales_history_count($range, $branchId);
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) $page = $totalPages;
$transactions = pos_sales_history_headers($range, $branchId, $perPage, ($page - 1) * $perPage);
$summary = pos_sales_history_summary($range, $branchId);
$detailCode = trim((string)($_GET['detail'] ?? ''));
$detail = $detailCode !== '' ? pos_sales_history_detail($detailCode, $branchId) : null;
$detailNotFound = $detailCode !== '' && !$detail;
$isAndroidApp = is_android_app_request();
$baseQuery = pos_sales_history_query_string($range);
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Riwayat Transaksi POS</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('pos/pos.css')); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('pos/history.css')); ?>">
</head>
<body data-android-app="<?php echo $isAndroidApp ? '1' : '0'; ?>">
<div class="pos-page pos-history-page">
  <div class="topbar pos-topbar">
    <div class="title"><?php echo e($appName); ?> POS</div>
    <a class="btn pos-history-back" href="<?php echo e(base_url('pos/index.php')); ?>">← Kembali ke POS</a>
    <div class="spacer"></div>
    <div class="pos-user-label"><?php echo e($me['name'] ?? 'User'); ?></div>
    <a class="btn pos-logout" href="<?php echo e(base_url('pos/logout.php')); ?>">Logout</a>
  </div>

  <main class="pos-history-wrap">
    <section class="pos-panel pos-history-header">
      <div>
        <h2>Riwayat Transaksi</h2>
        <p>Detail transaksi dan rekap penjualan cabang aktif.</p>
      </div>
      <div class="pos-history-period"><?php echo e($range['label']); ?></div>
    </section>

    <?php if ($range['warning']): ?>
      <div class="pos-panel pos-alert pos-alert-error"><?php echo e($range['warning']); ?></div>
    <?php endif; ?>
    <?php if ($detailNotFound): ?>
      <div class="pos-panel pos-alert pos-alert-error">Transaksi tidak ditemukan pada cabang aktif.</div>
    <?php endif; ?>

    <section class="pos-panel pos-history-filter">
      <form method="get" class="pos-history-filter-form">
        <input type="hidden" name="view" value="<?php echo e($view); ?>">
        <label>
          <span>Rentang waktu</span>
          <select name="range" data-history-range>
            <option value="today" <?php echo $range['range']==='today'?'selected':''; ?>>Hari ini</option>
            <option value="yesterday" <?php echo $range['range']==='yesterday'?'selected':''; ?>>Kemarin</option>
            <option value="7days" <?php echo $range['range']==='7days'?'selected':''; ?>>7 hari terakhir</option>
            <option value="custom" <?php echo $range['range']==='custom'?'selected':''; ?>>Custom</option>
          </select>
        </label>
        <label data-custom-date>
          <span>Tanggal mulai</span>
          <input type="date" name="start" value="<?php echo e($range['start_input']); ?>">
        </label>
        <label data-custom-date>
          <span>Tanggal selesai</span>
          <input type="date" name="end" value="<?php echo e($range['end_input']); ?>">
        </label>
        <button class="btn pos-history-apply" type="submit">Terapkan Filter</button>
      </form>
    </section>

    <nav class="pos-history-tabs" aria-label="Jenis riwayat">
      <a class="pos-history-tab <?php echo $view==='detail'?'is-active':''; ?>" href="<?php echo e(base_url('pos/history.php?' . pos_sales_history_query_string($range, ['view' => 'detail']))); ?>">Detail Transaksi</a>
      <a class="pos-history-tab <?php echo $view==='recap'?'is-active':''; ?>" href="<?php echo e(base_url('pos/history.php?' . pos_sales_history_query_string($range, ['view' => 'recap']))); ?>">Rekap Penjualan</a>
    </nav>

    <?php if ($view === 'detail'): ?>
      <section class="pos-history-stats">
        <div class="pos-panel pos-history-stat"><span>Transaksi</span><strong><?php echo e((string)$summary['transaction_count']); ?></strong></div>
        <div class="pos-panel pos-history-stat"><span>Aktif</span><strong><?php echo e((string)$summary['active_transaction_count']); ?></strong></div>
        <div class="pos-panel pos-history-stat"><span>Retur</span><strong><?php echo e((string)$summary['returned_transaction_count']); ?></strong></div>
        <div class="pos-panel pos-history-stat"><span>Penjualan bersih</span><strong>Rp <?php echo e(format_number_id((float)$summary['net_sales'])); ?></strong></div>
      </section>

      <section class="pos-panel pos-history-list-panel">
        <div class="pos-history-list-head">
          <div>
            <h3>Daftar Transaksi</h3>
            <small>Menampilkan transaksi POS cabang aktif.</small>
          </div>
          <span><?php echo e((string)$totalRows); ?> transaksi</span>
        </div>
        <?php if (empty($transactions)): ?>
          <div class="pos-history-empty">Belum ada transaksi pada periode ini.</div>
        <?php else: ?>
          <div class="pos-history-table-wrap">
            <table class="pos-history-table">
              <thead>
                <tr>
                  <th>Waktu</th>
                  <th>No. Transaksi</th>
                  <th>Kasir</th>
                  <th>Pembayaran</th>
                  <th>Item</th>
                  <th>Total</th>
                  <th>Status</th>
                  <th>Aksi</th>
                </tr>
              </thead>
              <tbody>
              <?php foreach ($transactions as $tx):
                $isReturned = (int)$tx['is_returned'] === 1;
                $detailUrl = base_url('pos/history.php?' . pos_sales_history_query_string($range, ['view' => 'detail', 'page' => $page, 'detail' => (string)$tx['transaction_code']]));
              ?>
                <tr>
                  <td><?php echo e(date('d/m/Y H:i', strtotime((string)$tx['sold_at']))); ?></td>
                  <td><strong><?php echo e((string)$tx['transaction_code']); ?></strong><?php if ((int)$tx['revision_no'] > 0): ?><small class="pos-history-revised">Revisi <?php echo e((string)$tx['revision_no']); ?></small><?php endif; ?></td>
                  <td><?php echo e((string)($tx['cashier_name'] ?: '-')); ?></td>
                  <td><span class="pos-history-payment"><?php echo e(strtoupper((string)($tx['payment_method'] ?: '-'))); ?></span></td>
                  <td><?php echo e(format_number_id((float)$tx['item_qty'], 0)); ?></td>
                  <td><strong>Rp <?php echo e(format_number_id((float)$tx['total_amount'])); ?></strong></td>
                  <td><span class="pos-history-status <?php echo $isReturned?'is-returned':'is-active'; ?>"><?php echo $isReturned?'RETUR':'SELESAI'; ?></span></td>
                  <td><a class="btn pos-history-detail-btn" href="<?php echo e($detailUrl); ?>">Lihat Detail</a></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>

          <?php if ($totalPages > 1): ?>
            <div class="pos-history-pagination">
              <?php if ($page > 1): ?><a class="btn" href="<?php echo e(base_url('pos/history.php?' . pos_sales_history_query_string($range, ['view'=>'detail','page'=>$page-1]))); ?>">← Sebelumnya</a><?php endif; ?>
              <span>Halaman <?php echo e((string)$page); ?> / <?php echo e((string)$totalPages); ?></span>
              <?php if ($page < $totalPages): ?><a class="btn" href="<?php echo e(base_url('pos/history.php?' . pos_sales_history_query_string($range, ['view'=>'detail','page'=>$page+1]))); ?>">Berikutnya →</a><?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </section>
    <?php else: ?>
      <section class="pos-history-stats pos-history-recap-stats">
        <div class="pos-panel pos-history-stat"><span>Jumlah transaksi</span><strong><?php echo e((string)$summary['transaction_count']); ?></strong></div>
        <div class="pos-panel pos-history-stat"><span>Jumlah item aktif</span><strong><?php echo e(format_number_id((float)$summary['item_qty'], 0)); ?></strong></div>
        <div class="pos-panel pos-history-stat"><span>Tunai</span><strong>Rp <?php echo e(format_number_id((float)$summary['cash_amount'])); ?></strong><small><?php echo e((string)$summary['cash_count']); ?> transaksi</small></div>
        <div class="pos-panel pos-history-stat"><span>QRIS</span><strong>Rp <?php echo e(format_number_id((float)$summary['qris_amount'])); ?></strong><small><?php echo e((string)$summary['qris_count']); ?> transaksi</small></div>
        <div class="pos-panel pos-history-stat"><span>Penjualan kotor</span><strong>Rp <?php echo e(format_number_id((float)$summary['gross_sales'])); ?></strong></div>
        <div class="pos-panel pos-history-stat"><span>Total retur</span><strong>Rp <?php echo e(format_number_id((float)$summary['return_amount'])); ?></strong><small><?php echo e((string)$summary['returned_transaction_count']); ?> transaksi</small></div>
        <div class="pos-panel pos-history-stat pos-history-stat-net"><span>Penjualan bersih</span><strong>Rp <?php echo e(format_number_id((float)$summary['net_sales'])); ?></strong></div>
      </section>

      <div class="pos-history-recap-actions">
        <a class="btn pos-history-print-report" href="<?php echo e(base_url('pos/report.php?' . $baseQuery)); ?>">Print Rekap 58 mm</a>
      </div>

      <div class="pos-history-recap-grid">
        <section class="pos-panel">
          <h3>Rekap Produk</h3>
          <?php if (empty($summary['products'])): ?>
            <div class="pos-history-empty">Belum ada produk terjual.</div>
          <?php else: ?>
            <div class="pos-history-table-wrap">
              <table class="pos-history-table">
                <thead><tr><th>Produk</th><th>Qty</th><th>Omzet</th><th>Retur</th></tr></thead>
                <tbody>
                <?php foreach ($summary['products'] as $product): ?>
                  <tr>
                    <td><?php echo e((string)$product['product_name']); ?></td>
                    <td><?php echo e(format_number_id((float)$product['qty_sold'], 0)); ?></td>
                    <td>Rp <?php echo e(format_number_id((float)$product['sales_amount'])); ?></td>
                    <td><?php echo e(format_number_id((float)$product['qty_returned'], 0)); ?> / Rp <?php echo e(format_number_id((float)$product['return_amount'])); ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>

        <section class="pos-panel">
          <h3>Rekap per Hari</h3>
          <?php if (empty($summary['daily'])): ?>
            <div class="pos-history-empty">Belum ada transaksi.</div>
          <?php else: ?>
            <div class="pos-history-table-wrap">
              <table class="pos-history-table">
                <thead><tr><th>Tanggal</th><th>Transaksi</th><th>Kotor</th><th>Retur</th><th>Bersih</th></tr></thead>
                <tbody>
                <?php foreach ($summary['daily'] as $day): ?>
                  <tr>
                    <td><?php echo e(date('d/m/Y', strtotime((string)$day['date']))); ?></td>
                    <td><?php echo e((string)$day['transactions']); ?></td>
                    <td>Rp <?php echo e(format_number_id((float)$day['gross'])); ?></td>
                    <td>Rp <?php echo e(format_number_id((float)$day['returns'])); ?></td>
                    <td><strong>Rp <?php echo e(format_number_id((float)$day['net'])); ?></strong></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>

        <section class="pos-panel">
          <h3>Rekap per Kasir</h3>
          <?php if (empty($summary['cashiers'])): ?>
            <div class="pos-history-empty">Belum ada transaksi aktif.</div>
          <?php else: ?>
            <div class="pos-history-table-wrap">
              <table class="pos-history-table">
                <thead><tr><th>Kasir</th><th>Transaksi</th><th>Penjualan</th></tr></thead>
                <tbody>
                <?php foreach ($summary['cashiers'] as $cashier): ?>
                  <tr>
                    <td><?php echo e((string)$cashier['cashier']); ?></td>
                    <td><?php echo e((string)$cashier['transactions']); ?></td>
                    <td><strong>Rp <?php echo e(format_number_id((float)$cashier['amount'])); ?></strong></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      </div>
    <?php endif; ?>
  </main>
</div>

<?php if ($detail):
  $proofUrl = ($detail['payment_method'] === 'qris' && $detail['payment_proof_path'] !== '') ? upload_url($detail['payment_proof_path'], 'image') : '';
  $closeUrl = base_url('pos/history.php?' . pos_sales_history_query_string($range, ['view'=>'detail','page'=>$page]));
  $receiptUrl = base_url('pos/receipt.php?code=' . urlencode((string)$detail['transaction_code']));
?>
<div class="pos-history-modal-backdrop" role="presentation">
  <section class="pos-history-modal" role="dialog" aria-modal="true" aria-labelledby="history-detail-title">
    <div class="pos-history-modal-head">
      <div>
        <h3 id="history-detail-title">Detail Transaksi</h3>
        <strong><?php echo e((string)$detail['transaction_code']); ?></strong>
      </div>
      <a class="pos-history-modal-close" href="<?php echo e($closeUrl); ?>" aria-label="Tutup">×</a>
    </div>
    <div class="pos-history-detail-meta">
      <div><span>Tanggal</span><strong><?php echo e(date('d/m/Y H:i', strtotime((string)$detail['sold_at']))); ?></strong></div>
      <div><span>Kasir</span><strong><?php echo e((string)($detail['cashier_name'] ?: '-')); ?></strong></div>
      <div><span>Customer</span><strong><?php echo e((string)($detail['customer_name'] ?: '-')); ?></strong></div>
      <div><span>Pembayaran</span><strong><?php echo e(strtoupper((string)($detail['payment_method'] ?: '-'))); ?></strong></div>
      <div><span>Status</span><strong><?php echo $detail['returned_at'] ? 'RETUR' : 'SELESAI'; ?></strong></div>
      <div><span>Total item</span><strong><?php echo e(format_number_id((float)$detail['item_qty'], 0)); ?></strong></div>
    </div>

    <?php if ($detail['returned_at']): ?>
      <div class="pos-history-return-note"><strong>Retur:</strong> <?php echo e((string)($detail['return_reason'] ?: '-')); ?> · <?php echo e(date('d/m/Y H:i', strtotime((string)$detail['returned_at']))); ?></div>
    <?php endif; ?>

    <div class="pos-history-table-wrap">
      <table class="pos-history-table">
        <thead><tr><th>Produk</th><th>Qty</th><th>Harga</th><th>Subtotal</th></tr></thead>
        <tbody>
        <?php foreach ($detail['items'] as $item): ?>
          <tr>
            <td><?php echo e((string)$item['name']); ?><?php if ($item['is_reward']): ?> <small class="pos-history-revised">Reward</small><?php endif; ?></td>
            <td><?php echo e(format_number_id((float)$item['qty'], 0)); ?></td>
            <td><?php echo $item['is_reward'] ? 'Gratis' : 'Rp ' . e(format_number_id((float)$item['price'])); ?></td>
            <td><strong><?php echo $item['is_reward'] ? 'Gratis' : 'Rp ' . e(format_number_id((float)$item['subtotal'])); ?></strong></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ((float)$detail['discount_amount'] != 0 || (float)$detail['tax_amount'] != 0 || (float)$detail['extra_fee'] != 0): ?>
      <div class="pos-history-detail-breakdown">
        <div><span>Subtotal</span><strong>Rp <?php echo e(format_number_id((float)$detail['subtotal'])); ?></strong></div>
        <?php if ((float)$detail['discount_amount'] != 0): ?><div><span>Diskon</span><strong>- Rp <?php echo e(format_number_id((float)$detail['discount_amount'])); ?></strong></div><?php endif; ?>
        <?php if ((float)$detail['tax_amount'] != 0): ?><div><span>Pajak</span><strong>Rp <?php echo e(format_number_id((float)$detail['tax_amount'])); ?></strong></div><?php endif; ?>
        <?php if ((float)$detail['extra_fee'] != 0): ?><div><span>Biaya tambahan</span><strong>Rp <?php echo e(format_number_id((float)$detail['extra_fee'])); ?></strong></div><?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="pos-history-detail-total"><span>Total</span><strong>Rp <?php echo e(format_number_id((float)$detail['total'])); ?></strong></div>

    <?php if ($proofUrl): ?>
      <div class="pos-history-proof"><span>Bukti QRIS</span><a href="<?php echo e($proofUrl); ?>" target="_blank" rel="noopener"><img src="<?php echo e($proofUrl); ?>" alt="Bukti QRIS"></a></div>
    <?php endif; ?>

    <div class="pos-history-modal-actions">
      <a class="btn" href="<?php echo e($receiptUrl); ?>">Cetak Ulang Struk 58 mm</a>
      <a class="btn pos-reset-btn" href="<?php echo e($closeUrl); ?>">Tutup</a>
    </div>
  </section>
</div>
<?php endif; ?>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
<script defer src="<?php echo e(asset_url('pos/history.js')); ?>"></script>
</body>
</html>
