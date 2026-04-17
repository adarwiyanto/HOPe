<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/inventory.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../core/sales_revision.php';
require_once __DIR__ . '/../lib/upload_secure.php';

start_secure_session();
require_admin();
require_menu_access('sales');
ensure_sales_transaction_code_column();
ensure_sales_user_column();
ensure_inventory_module_schema();
ensure_roles_permissions_schema();
ensure_sales_revision_schema();

$err = '';
$ok = '';
$me = current_user();
$isOwner = current_user_is_owner();
$isAdmin = current_user_role_key() === 'admin';
$canEditSale = $isOwner || $isAdmin;

$revisionReasonOptions = [
  'Salah input item',
  'Salah qty',
  'Salah harga',
  'Salah diskon',
  'Salah customer',
  'Koreksi pembayaran',
  'Lainnya',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? 'create';
  $transactionCode = trim($_POST['transaction_code'] ?? '');
  $legacySaleId = (int)($_POST['sale_id'] ?? 0);

  try {
    if ($action === 'delete') {
      if (!$isOwner) {
        throw new Exception('Hanya owner yang bisa menghapus transaksi.');
      }
      if ($transactionCode !== '' && strpos($transactionCode, 'LEGACY-') !== 0) {
        $stmt = db()->prepare("SELECT DISTINCT payment_proof_path FROM sales WHERE transaction_code=?");
        $stmt->execute([$transactionCode]);
        foreach ($stmt->fetchAll() as $row) {
          if (!empty($row['payment_proof_path'])) {
            upload_secure_delete((string)$row['payment_proof_path'], 'image');
          }
        }
        $stmt = db()->prepare("DELETE FROM sales WHERE transaction_code=?");
        $stmt->execute([$transactionCode]);
      } else {
        if ($legacySaleId <= 0) throw new Exception('Transaksi tidak ditemukan.');
        $stmt = db()->prepare("DELETE FROM sales WHERE id=?");
        $stmt->execute([$legacySaleId]);
      }
      redirect(base_url('admin/sales.php'));
    }

    if ($action === 'return') {
      if (!in_array(current_user_role_key(), ['admin', 'owner'], true)) {
        throw new Exception('Anda tidak diizinkan meretur transaksi.');
      }
      $reason = trim($_POST['return_reason'] ?? '');
      if ($reason === '') throw new Exception('Alasan retur wajib diisi.');
      $stmt = db()->prepare("UPDATE sales SET return_reason=?, returned_at=NOW() WHERE transaction_code=?");
      $stmt->execute([$reason, $transactionCode]);
      redirect(base_url('admin/sales.php'));
    }

    if ($action === 'revise') {
      if (!$canEditSale) throw new Exception('Hanya owner/admin yang dapat edit transaksi.');
      $sourceCode = trim((string)($_POST['source_transaction_code'] ?? ''));
      if ($sourceCode === '') throw new Exception('Kode transaksi sumber tidak valid.');

      $reasonCategory = trim((string)($_POST['revision_reason_category'] ?? ''));
      $reasonText = trim((string)($_POST['revision_reason_text'] ?? ''));
      if ($isAdmin) {
        if ($reasonCategory === '' || !in_array($reasonCategory, $revisionReasonOptions, true)) {
          throw new Exception('Kategori alasan revisi wajib diisi untuk admin.');
        }
        if (mb_strlen($reasonText) < 8) {
          throw new Exception('Alasan revisi minimal 8 karakter untuk admin.');
        }
      }

      $rawItems = $_POST['items'] ?? [];
      $items = [];
      foreach ($rawItems as $row) {
        $pid = (int)($row['product_id'] ?? 0);
        $qty = (float)($row['qty'] ?? 0);
        $price = (float)($row['price_each'] ?? 0);
        $disc = (float)($row['item_discount'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        $items[] = [
          'product_id' => $pid,
          'qty' => $qty,
          'price_each' => $price,
          'item_discount' => max(0, $disc),
        ];
      }
      if (empty($items)) throw new Exception('Item transaksi wajib ada.');

      $newCode = revise_sale_transaction($sourceCode, [
        'sold_at' => (string)($_POST['sold_at'] ?? ''),
        'payment_method' => (string)($_POST['payment_method'] ?? 'cash'),
        'customer_name' => (string)($_POST['customer_name'] ?? ''),
        'discount_amount' => (float)($_POST['discount_amount'] ?? 0),
        'tax_amount' => (float)($_POST['tax_amount'] ?? 0),
        'extra_fee' => (float)($_POST['extra_fee'] ?? 0),
        'notes' => (string)($_POST['notes'] ?? ''),
        'reason_category' => $reasonCategory,
        'reason_text' => $reasonText,
        'items' => $items,
      ], $me ?? []);

      redirect(base_url('admin/sales.php?detail=' . urlencode($newCode) . '&ok=1'));
    }

    $product_id = (int)($_POST['product_id'] ?? 0);
    $qty = (int)($_POST['qty'] ?? 1);

    if ($product_id <= 0) throw new Exception('Produk wajib dipilih.');
    if ($qty <= 0) throw new Exception('Qty minimal 1.');

    $stmt = db()->prepare("SELECT price FROM products WHERE id=?");
    $stmt->execute([$product_id]);
    $p = $stmt->fetch();
    if (!$p) throw new Exception('Produk tidak ditemukan.');

    $price = (float)$p['price'];
    $total = $price * $qty;

    $transactionCode = 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
    $stmt = db()->prepare("INSERT INTO sales (transaction_code, base_sale_code, revision_no, revision_status, is_active_revision, product_id, qty, price_each, total, grand_total, created_by)
      VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$transactionCode, $transactionCode, 0, 'active', 1, $product_id, $qty, $price, $total, $total, (int)($me['id'] ?? 0)]);

    $saleId = (int)db()->lastInsertId();
    $stmt = db()->prepare("UPDATE sales SET original_sale_id=? WHERE id=?");
    $stmt->execute([$saleId, $saleId]);

    add_stock_ledger([
      'branch_id' => active_branch_id(),
      'product_id' => $product_id,
      'trans_type' => 'admin_sale',
      'ref_table' => 'sales',
      'ref_id' => $saleId,
      'qty_in' => 0,
      'qty_out' => $qty,
      'unit_cost' => null,
      'note' => 'Penjualan admin',
      'created_by' => (int)($me['id'] ?? 0),
    ]);

    redirect(base_url('admin/sales.php'));
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$products = db()->query("SELECT id, name, price, base_unit FROM products ORDER BY name ASC")->fetchAll();
$productMap = [];
foreach ($products as $p) $productMap[(int)$p['id']] = $p;

$range = $_GET['range'] ?? 'today';
$rangeOptions = ['today', 'yesterday', '7days', 'custom'];
if (!in_array($range, $rangeOptions, true)) $range = 'today';
$customStart = $_GET['start'] ?? '';
$customEnd = $_GET['end'] ?? '';
$startDate = null;
$endDate = null;
$today = new DateTimeImmutable('today');
if ($range === 'today') {
  $startDate = $today->setTime(0, 0, 0); $endDate = $today->setTime(23, 59, 59);
} elseif ($range === 'yesterday') {
  $yesterday = $today->modify('-1 day'); $startDate = $yesterday->setTime(0, 0, 0); $endDate = $yesterday->setTime(23, 59, 59);
} elseif ($range === '7days') {
  $startDate = $today->modify('-6 days')->setTime(0, 0, 0); $endDate = $today->setTime(23, 59, 59);
} else {
  $parsedStart = DateTimeImmutable::createFromFormat('Y-m-d', $customStart);
  $parsedEnd = DateTimeImmutable::createFromFormat('Y-m-d', $customEnd);
  if ($parsedStart && $parsedEnd) {
    $startDate = $parsedStart->setTime(0,0,0); $endDate = $parsedEnd->setTime(23,59,59);
  } else {
    $startDate = $today->setTime(0,0,0); $endDate = $today->setTime(23,59,59);
  }
}

$whereClause = "WHERE s.is_active_revision=1";
$params = [];
if ($startDate && $endDate) {
  $whereClause .= " AND s.sold_at BETWEEN ? AND ?";
  $params[] = $startDate->format('Y-m-d H:i:s');
  $params[] = $endDate->format('Y-m-d H:i:s');
}

$stmt = db()->prepare("SELECT
    s.transaction_code AS tx_code,
    s.base_sale_code,
    MIN(s.sold_at) AS sold_at,
    SUM(s.total) AS total_amount,
    MAX(s.payment_method) AS payment_method,
    MAX(s.payment_proof_path) AS payment_proof_path,
    MAX(s.return_reason) AS return_reason,
    MAX(s.customer_name) AS customer_name,
    MAX(s.revision_no) AS revision_no,
    MAX(s.is_active_revision) AS is_active_revision,
    MAX(s.revision_status) AS revision_status,
    MAX(u.name) AS cashier_name
  FROM sales s
  LEFT JOIN users u ON u.id = s.created_by
  {$whereClause}
  GROUP BY s.transaction_code, s.base_sale_code
  ORDER BY sold_at DESC
  LIMIT 100");
$stmt->execute($params);
$transactions = $stmt->fetchAll();

$itemsByTx = [];
if (!empty($transactions)) {
  $codes = array_map(static fn($r) => (string)$r['tx_code'], $transactions);
  $placeholders = implode(',', array_fill(0, count($codes), '?'));
  $stmt = db()->prepare("SELECT s.*, p.name AS product_name, p.base_unit
    FROM sales s
    JOIN products p ON p.id=s.product_id
    WHERE s.transaction_code IN ({$placeholders})
    ORDER BY s.id ASC");
  $stmt->execute($codes);
  foreach ($stmt->fetchAll() as $row) {
    $itemsByTx[(string)$row['transaction_code']][] = $row;
  }
}

$detailCode = trim((string)($_GET['detail'] ?? ''));
$historyBaseCode = trim((string)($_GET['history'] ?? ''));
$editCode = trim((string)($_GET['edit'] ?? ''));
$detailItems = $detailCode !== '' ? fetch_sale_version_items($detailCode) : [];
$detailHeader = $detailItems ? sale_version_header($detailItems) : [];
$historyRows = $historyBaseCode !== '' ? sale_revision_history($historyBaseCode) : [];
$editItems = $editCode !== '' ? fetch_sale_version_items($editCode) : [];
$editHeader = $editItems ? sale_version_header($editItems) : [];

$customCss = setting('custom_css', '');
?>
<!doctype html><html><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Penjualan</title>
<link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style>
<style>.badge-ok{background:rgba(52,211,153,.2);padding:4px 8px;border-radius:999px}.badge-old{background:rgba(251,146,60,.2);padding:4px 8px;border-radius:999px}.transaction-card{border:1px solid rgba(148,163,184,.3);padding:12px;border-radius:10px;margin-bottom:10px}.actions{display:flex;gap:8px;flex-wrap:wrap}</style>
</head><body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><div class="badge">Input Penjualan</div></div>
<div class="content">
<?php if ($err): ?><div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div><?php endif; ?>
<?php if (!empty($_GET['ok'])): ?><div class="card" style="border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.10)">Revisi transaksi berhasil disimpan.</div><?php endif; ?>

<?php if ($detailHeader): ?>
<div class="card"><h3 style="margin-top:0">Detail Transaksi <?php echo e($detailHeader['transaction_code']); ?></h3>
<div class="actions"><span class="<?php echo ((int)$detailHeader['is_active_revision']===1)?'badge-ok':'badge-old'; ?>"><?php echo ((int)$detailHeader['is_active_revision']===1)?'Versi Aktif':'Versi Lama'; ?></span><?php if ((int)$detailHeader['is_revised']===1): ?><span class="badge">Sudah Direvisi</span><?php endif; ?><?php if ($detailHeader['revised_by_name'] !== ''): ?><span class="badge">Direvisi oleh <?php echo e($detailHeader['revised_by_name']); ?></span><?php endif; ?></div>
<p><strong>No Transaksi:</strong> <?php echo e($detailHeader['transaction_code']); ?> | <strong>No Revisi:</strong> <?php echo e((string)$detailHeader['revision_no']); ?> | <strong>Tanggal:</strong> <?php echo e($detailHeader['sold_at']); ?></p>
<p><strong>Customer:</strong> <?php echo e($detailHeader['customer_name'] !== '' ? $detailHeader['customer_name'] : '-'); ?> | <strong>Kasir:</strong> <?php echo e($detailHeader['cashier_name']); ?> | <strong>Role:</strong> <?php echo e($detailHeader['cashier_role_name']); ?></p>
<p><strong>Status:</strong> <?php echo e($detailHeader['sale_status']); ?> | <strong>Pembayaran:</strong> <?php echo e($detailHeader['payment_method']); ?></p>
<p><strong>Subtotal:</strong> Rp <?php echo e(format_number_id($detailHeader['subtotal'])); ?> | <strong>Diskon:</strong> Rp <?php echo e(format_number_id($detailHeader['discount_amount'])); ?> | <strong>Pajak/Biaya:</strong> Rp <?php echo e(format_number_id($detailHeader['tax_amount'] + $detailHeader['extra_fee'])); ?> | <strong>Grand Total:</strong> Rp <?php echo e(format_number_id($detailHeader['grand_total'])); ?></p>
<p><strong>Catatan:</strong> <?php echo e($detailHeader['notes'] !== '' ? $detailHeader['notes'] : '-'); ?></p>
<p><strong>Alasan Revisi:</strong> <?php echo e($detailHeader['revision_reason_category'] !== '' ? $detailHeader['revision_reason_category'] : '-'); ?> - <?php echo e($detailHeader['revision_reason_text'] !== '' ? $detailHeader['revision_reason_text'] : '-'); ?></p>
<table class="table"><thead><tr><th>Produk</th><th>Qty</th><th>Satuan</th><th>Harga</th><th>Diskon Item</th><th>Subtotal Item</th></tr></thead><tbody>
<?php foreach ($detailItems as $it): ?><tr><td><?php echo e($it['product_name']); ?></td><td><?php echo e((string)$it['qty']); ?></td><td><?php echo e((string)($it['base_unit'] ?? 'pcs')); ?></td><td>Rp <?php echo e(format_number_id((float)$it['price_each'])); ?></td><td>Rp 0</td><td>Rp <?php echo e(format_number_id((float)$it['total'])); ?></td></tr><?php endforeach; ?>
</tbody></table>
<p><a class="btn" href="<?php echo e(base_url('admin/sales.php')); ?>">Kembali</a></p>
</div>
<?php endif; ?>

<?php if ($historyBaseCode !== ''): ?>
<div class="card"><h3 style="margin-top:0">Riwayat Revisi <?php echo e($historyBaseCode); ?></h3>
<table class="table"><thead><tr><th>No Revisi</th><th>Kode</th><th>Diedit Oleh</th><th>Waktu</th><th>Kategori</th><th>Alasan</th><th>Total</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($historyRows as $row): ?><tr><td><?php echo e((string)$row['revision_no']); ?></td><td><?php echo e($row['transaction_code']); ?></td><td><?php echo e($row['revised_by_name'] !== '' ? $row['revised_by_name'] : '-'); ?></td><td><?php echo e($row['revised_at'] !== '' ? $row['revised_at'] : '-'); ?></td><td><?php echo e($row['revision_reason_category'] !== '' ? $row['revision_reason_category'] : '-'); ?></td><td><?php echo e($row['revision_reason_text'] !== '' ? $row['revision_reason_text'] : '-'); ?></td><td>Rp <?php echo e(format_number_id($row['grand_total'])); ?></td><td><?php echo ((int)$row['is_active_revision']===1) ? '<span class="badge-ok">Aktif</span>' : '<span class="badge-old">Arsip</span>'; ?></td><td><a class="btn" href="<?php echo e(base_url('admin/sales.php?detail=' . urlencode($row['transaction_code']))); ?>">Detail</a></td></tr><?php endforeach; ?>
</tbody></table><p><a class="btn" href="<?php echo e(base_url('admin/sales.php')); ?>">Kembali</a></p></div>
<?php endif; ?>

<?php if ($editHeader && $canEditSale): ?>
<div class="card"><h3 style="margin-top:0">Edit Transaksi <?php echo e($editHeader['transaction_code']); ?></h3>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="revise"><input type="hidden" name="source_transaction_code" value="<?php echo e($editHeader['transaction_code']); ?>">
<div class="row"><label>Tanggal Transaksi</label><input type="datetime-local" name="sold_at" value="<?php echo e(date('Y-m-d\TH:i', strtotime((string)$editHeader['sold_at']))); ?>"></div>
<div class="row"><label>Customer</label><input name="customer_name" value="<?php echo e($editHeader['customer_name']); ?>"></div>
<div class="row"><label>Metode Pembayaran</label><select name="payment_method"><option value="cash" <?php echo $editHeader['payment_method']==='cash'?'selected':''; ?>>Cash</option><option value="qris" <?php echo $editHeader['payment_method']==='qris'?'selected':''; ?>>QRIS</option><option value="transfer" <?php echo $editHeader['payment_method']==='transfer'?'selected':''; ?>>Transfer</option><option value="card" <?php echo $editHeader['payment_method']==='card'?'selected':''; ?>>Card</option></select></div>
<div class="row"><label>Diskon</label><input type="number" step="0.01" name="discount_amount" value="<?php echo e((string)$editHeader['discount_amount']); ?>"></div>
<div class="row"><label>Pajak</label><input type="number" step="0.01" name="tax_amount" value="<?php echo e((string)$editHeader['tax_amount']); ?>"></div>
<div class="row"><label>Biaya lain</label><input type="number" step="0.01" name="extra_fee" value="<?php echo e((string)$editHeader['extra_fee']); ?>"></div>
<div class="row"><label>Catatan</label><textarea name="notes" rows="3"><?php echo e($editHeader['notes']); ?></textarea></div>
<?php if ($isAdmin): ?><div class="row"><label>Kategori Alasan (wajib)</label><select name="revision_reason_category" required><option value="">-- pilih --</option><?php foreach ($revisionReasonOptions as $opt): ?><option value="<?php echo e($opt); ?>"><?php echo e($opt); ?></option><?php endforeach; ?></select></div><div class="row"><label>Alasan Revisi (wajib)</label><textarea name="revision_reason_text" rows="3" minlength="8" required></textarea></div><?php endif; ?>
<table class="table"><thead><tr><th>Produk</th><th>Qty</th><th>Harga</th><th>Diskon Item</th></tr></thead><tbody>
<?php foreach ($editItems as $idx => $it): ?><tr><td><select name="items[<?php echo e((string)$idx); ?>][product_id]"><?php foreach ($products as $p): ?><option value="<?php echo e((string)$p['id']); ?>" <?php echo ((int)$p['id']===(int)$it['product_id'])?'selected':''; ?>><?php echo e($p['name']); ?></option><?php endforeach; ?></select></td><td><input type="number" step="0.01" min="0.01" name="items[<?php echo e((string)$idx); ?>][qty]" value="<?php echo e((string)$it['qty']); ?>"></td><td><input type="number" step="0.01" min="0" name="items[<?php echo e((string)$idx); ?>][price_each]" value="<?php echo e((string)$it['price_each']); ?>"></td><td><input type="number" step="0.01" min="0" name="items[<?php echo e((string)$idx); ?>][item_discount]" value="0"></td></tr><?php endforeach; ?>
</tbody></table>
<button class="btn" type="submit">Simpan Revisi</button> <a class="btn" href="<?php echo e(base_url('admin/sales.php')); ?>">Batal</a>
</form></div>
<?php endif; ?>

<div class="grid cols-2"><div class="card"><h3 style="margin-top:0">Transaksi Baru</h3><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="create"><div class="row"><label>Produk</label><select name="product_id" required><option value="">-- pilih --</option><?php foreach ($products as $p): ?><option value="<?php echo e((string)$p['id']); ?>"><?php echo e($p['name']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Qty</label><input type="number" name="qty" value="1" min="1" required></div><button class="btn" type="submit">Simpan Penjualan</button></form></div>
<div class="card"><h3 style="margin-top:0">Riwayat Transaksi</h3><form method="get" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap"><div class="row" style="margin:0"><label>Rentang Waktu</label><select name="range"><option value="today" <?php echo $range==='today'?'selected':''; ?>>Hari ini</option><option value="yesterday" <?php echo $range==='yesterday'?'selected':''; ?>>Kemarin</option><option value="7days" <?php echo $range==='7days'?'selected':''; ?>>7 hari</option><option value="custom" <?php echo $range==='custom'?'selected':''; ?>>Custom</option></select></div><div class="row" style="margin:0"><label>Mulai</label><input type="date" name="start" value="<?php echo e($customStart); ?>"></div><div class="row" style="margin:0"><label>Sampai</label><input type="date" name="end" value="<?php echo e($customEnd); ?>"></div><button class="btn" type="submit">Terapkan</button></form>
<?php foreach ($transactions as $tx): $txCode=(string)$tx['tx_code']; $items=$itemsByTx[$txCode] ?? []; $revised = ((int)$tx['revision_no'] > 0); ?>
<div class="transaction-card"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap"><div><strong><?php echo e($txCode); ?></strong><br><small><?php echo e((string)$tx['sold_at']); ?></small></div><div><strong>Rp <?php echo e(format_number_id((float)$tx['total_amount'])); ?></strong></div></div>
<div style="display:flex;gap:10px;flex-wrap:wrap"><span>Kasir: <?php echo e($tx['cashier_name'] ?? '-'); ?></span><span>Customer: <?php echo e($tx['customer_name'] ?? '-'); ?></span><span>Status Versi: <?php echo ((int)$tx['is_active_revision']===1) ? '<span class="badge-ok">Aktif</span>' : '<span class="badge-old">Arsip</span>'; ?></span><?php if ($revised): ?><span class="badge">Revised</span><?php endif; ?></div>
<?php if ($items): ?><ul><?php foreach ($items as $it): ?><li><?php echo e($it['product_name']); ?> x <?php echo e((string)$it['qty']); ?> (Rp <?php echo e(format_number_id((float)$it['total'])); ?>)</li><?php endforeach; ?></ul><?php endif; ?>
<div class="actions"><a class="btn" href="<?php echo e(base_url('admin/sales.php?detail=' . urlencode($txCode))); ?>">Detail</a><?php if ($canEditSale): ?><a class="btn" href="<?php echo e(base_url('admin/sales.php?edit=' . urlencode($txCode))); ?>">Edit Transaksi</a><?php endif; ?><?php if (in_array(current_user_role_key(), ['owner','admin'], true)): ?><a class="btn" href="<?php echo e(base_url('admin/sales.php?history=' . urlencode((string)$tx['base_sale_code']))); ?>">Lihat Riwayat Revisi</a><?php endif; ?></div>
</div>
<?php endforeach; ?></div></div>
</div></div></div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
