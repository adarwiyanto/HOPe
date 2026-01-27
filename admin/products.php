<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

require_admin();
ensure_products_favorite_column();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
  csrf_check();
  $id = (int)($_POST['id'] ?? 0);

  $stmt = db()->prepare("SELECT image_path FROM products WHERE id=?");
  $stmt->execute([$id]);
  $p = $stmt->fetch();

  $stmt = db()->prepare("DELETE FROM products WHERE id=?");
  $stmt->execute([$id]);

  if ($p && !empty($p['image_path'])) {
    $full = __DIR__ . '/../' . $p['image_path'];
    if (file_exists($full)) @unlink($full);
  }

  redirect(base_url('admin/products.php'));
}

$products = db()->query("SELECT * FROM products ORDER BY id DESC")->fetchAll();
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Produk</title>
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
      <a class="btn" href="<?php echo e(base_url('admin/product_form.php')); ?>">Tambah Produk</a>
    </div>

    <div class="content">
      <div class="card">
        <h3 style="margin-top:0">Daftar Produk</h3>
        <table class="table">
          <thead>
            <tr>
              <th>Foto</th><th>Nama</th><th>Harga</th><th>Favorit</th><th>Aksi</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($products as $p): ?>
              <tr>
                <td>
                  <?php if ($p['image_path']): ?>
                    <img class="thumb" src="<?php echo e(base_url($p['image_path'])); ?>">
                  <?php else: ?>
                    <div class="thumb" style="display:flex;align-items:center;justify-content:center;color:var(--muted)">No</div>
                  <?php endif; ?>
                </td>
                <td><?php echo e($p['name']); ?></td>
                <td>Rp <?php echo e(number_format((float)$p['price'], 0, '.', ',')); ?></td>
                <td><?php echo !empty($p['is_favorite']) ? '⭐' : '-'; ?></td>
                <td style="display:flex;gap:8px;align-items:center">
                  <a class="btn" href="<?php echo e(base_url('admin/product_form.php?id=' . (int)$p['id'])); ?>">Edit</a>
                  <form method="post" onsubmit="return confirm('Hapus produk ini?')">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?php echo e((string)$p['id']); ?>">
                    <button class="btn danger" type="submit">Hapus</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
