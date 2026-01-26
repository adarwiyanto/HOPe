<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

require_admin();

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $product_id = (int)($_POST['product_id'] ?? 0);
  $qty = (int)($_POST['qty'] ?? 1);

  try {
    if ($product_id <= 0) throw new Exception('Produk wajib dipilih.');
    if ($qty <= 0) throw new Exception('Qty minimal 1.');

    $stmt = db()->prepare("SELECT price FROM products WHERE id=?");
    $stmt->execute([$product_id]);
    $p = $stmt->fetch();
    if (!$p) throw new Exception('Produk tidak ditemukan.');

    $price = (float)$p['price'];
    $total = $price * $qty;

    $stmt = db()->prepare("INSERT INTO sales (product_id, qty, price_each, total) VALUES (?,?,?,?)");
    $stmt->execute([$product_id, $qty, $price, $total]);

    redirect(base_url('admin/sales.php'));
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
          <?php if ($err): ?>
            <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
          <?php endif; ?>
          <form method="post">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
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
              <tr><th>Waktu</th><th>Produk</th><th>Qty</th><th>Total</th></tr>
            </thead>
            <tbody>
              <?php foreach ($sales as $s): ?>
                <tr>
                  <td><?php echo e($s['sold_at']); ?></td>
                  <td><?php echo e($s['product_name']); ?></td>
                  <td><?php echo e((string)$s['qty']); ?></td>
                  <td>Rp <?php echo e(number_format((float)$s['total'], 0, '.', ',')); ?></td>
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
