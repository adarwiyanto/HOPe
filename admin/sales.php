<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

require_admin();

$u = current_user();
$err = '';
$notice = '';
start_session();
if (!empty($_SESSION['sales_notice'])) {
  $notice = $_SESSION['sales_notice'];
  unset($_SESSION['sales_notice']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? 'add';

  try {
    if ($action === 'add') {
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

      $stmt = db()->prepare("INSERT INTO sales (product_id, qty, price_each, total, payment_method) VALUES (?,?,?,?,?)");
      $stmt->execute([$product_id, $qty, $price, $total, 'cash']);
      $_SESSION['sales_notice'] = 'Transaksi berhasil ditambahkan.';
      redirect(base_url('admin/sales.php'));
    }

    if ($action === 'return') {
      $saleId = (int)($_POST['sale_id'] ?? 0);
      $reason = trim($_POST['return_reason'] ?? '');
      if ($saleId <= 0) throw new Exception('Transaksi tidak valid.');
      if ($reason === '') throw new Exception('Alasan retur wajib diisi.');

      $stmt = db()->prepare("SELECT returned_at FROM sales WHERE id=?");
      $stmt->execute([$saleId]);
      $sale = $stmt->fetch();
      if (!$sale) throw new Exception('Transaksi tidak ditemukan.');
      if (!empty($sale['returned_at'])) throw new Exception('Transaksi sudah diretur.');

      $stmt = db()->prepare("UPDATE sales SET returned_at=NOW(), return_reason=? WHERE id=?");
      $stmt->execute([$reason, $saleId]);
      $_SESSION['sales_notice'] = 'Transaksi berhasil diretur.';
      redirect(base_url('admin/sales.php'));
    }

    if ($action === 'delete') {
      $u = current_user();
      if (($u['role'] ?? '') !== 'superadmin') {
        throw new Exception('Hanya superadmin yang dapat menghapus transaksi.');
      }
      $saleId = (int)($_POST['sale_id'] ?? 0);
      if ($saleId <= 0) throw new Exception('Transaksi tidak valid.');
      $stmt = db()->prepare("DELETE FROM sales WHERE id=?");
      $stmt->execute([$saleId]);
      $_SESSION['sales_notice'] = 'Transaksi berhasil dihapus.';
      redirect(base_url('admin/sales.php'));
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$products = db()->query("SELECT id, name FROM products ORDER BY name ASC")->fetchAll();
$sales = db()->query("
  SELECT s.*, p.name product_name 
  FROM sales s JOIN products p ON p.id=s.product_id
  ORDER BY s.id DESC LIMIT 100
")->fetchAll();

$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Penjualan</title>
  <link rel="stylesheet" href="<?php echo e(base_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <button class="btn" data-toggle-sidebar type="button">Menu</button>
      <div class="badge">Input Penjualan</div>
    </div>

    <div class="content">
      <div class="grid cols-2">
        <div class="card">
          <h3 style="margin-top:0">Transaksi Baru</h3>
          <?php if ($notice): ?>
            <div class="card" style="border-color:rgba(56,189,248,.35);background:rgba(56,189,248,.10)"><?php echo e($notice); ?></div>
          <?php endif; ?>
          <?php if ($err): ?>
            <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
          <?php endif; ?>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="add">
            <div class="row">
              <label>Produk</label>
              <select name="product_id" required>
                <option value="">-- pilih --</option>
                <?php foreach ($products as $p): ?>
                  <option value="<?php echo e((string)$p['id']); ?>"><?php echo e($p['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="row">
              <label>Qty</label>
              <input type="number" name="qty" value="1" min="1" required>
            </div>
            <button class="btn" type="submit">Simpan Penjualan</button>
          </form>
          <p><small>Ini versi sederhana: harga mengikuti harga produk saat transaksi dibuat.</small></p>
        </div>

        <div class="card">
          <h3 style="margin-top:0">Riwayat (100 terakhir)</h3>
          <table class="table">
            <thead>
              <tr><th>Waktu</th><th>Produk</th><th>Qty</th><th>Total</th><th>Metode</th><th>Status</th><th>Aksi</th></tr>
            </thead>
            <tbody>
              <?php foreach ($sales as $s): ?>
                <tr>
                  <td><?php echo e($s['sold_at']); ?></td>
                  <td><?php echo e($s['product_name']); ?></td>
                  <td><?php echo e((string)$s['qty']); ?></td>
                  <td>Rp <?php echo e(number_format((float)$s['total'], 0, '.', ',')); ?></td>
                  <td><?php echo e(strtoupper($s['payment_method'] ?? 'cash')); ?></td>
                  <td>
                    <?php if (!empty($s['returned_at'])): ?>
                      <div><strong>Retur</strong></div>
                      <small><?php echo e($s['return_reason'] ?? ''); ?></small>
                    <?php else: ?>
                      <small>Normal</small>
                    <?php endif; ?>
                    <?php if (!empty($s['payment_proof'])): ?>
                      <div><a href="<?php echo e(base_url($s['payment_proof'])); ?>" target="_blank" rel="noopener">Lihat bukti</a></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php $role = $u['role'] ?? ''; ?>
                    <?php if (empty($s['returned_at']) && in_array($role, ['admin','superadmin'], true)): ?>
                      <form method="post" style="margin-bottom:6px">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="return">
                        <input type="hidden" name="sale_id" value="<?php echo e((string)$s['id']); ?>">
                        <textarea name="return_reason" placeholder="Alasan retur" required style="width:100%;min-height:60px"></textarea>
                        <button class="btn" type="submit">Retur</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($role === 'superadmin'): ?>
                      <form method="post" onsubmit="return confirm('Hapus transaksi ini?');">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="sale_id" value="<?php echo e((string)$s['id']); ?>">
                        <button class="btn" type="submit" style="background:#fee2e2;border-color:#fecaca;color:#b91c1c">Hapus</button>
                      </form>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="<?php echo e(base_url('assets/app.js')); ?>"></script>
</body>
</html>
