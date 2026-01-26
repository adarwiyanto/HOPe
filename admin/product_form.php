<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

require_admin();

$id = (int)($_GET['id'] ?? 0);
ensure_products_favorite_column();
$product = ['name'=>'','price'=>'0','image_path'=>null,'is_favorite'=>0];

if ($id) {
  $stmt = db()->prepare("SELECT * FROM products WHERE id=?");
  $stmt->execute([$id]);
  $product = $stmt->fetch() ?: $product;
}

$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  $name = trim($_POST['name'] ?? '');
  $price = normalize_money($_POST['price'] ?? '0');
  $isFavorite = isset($_POST['is_favorite']) ? 1 : 0;

  try {
    if ($name === '') throw new Exception('Nama produk wajib diisi.');

    // Upload foto (opsional)
    $imagePath = $product['image_path'];
    if (!empty($_FILES['image']['name'])) {
      $f = $_FILES['image'];
      if ($f['error'] !== UPLOAD_ERR_OK) throw new Exception('Upload gagal.');
      if ($f['size'] > 2 * 1024 * 1024) throw new Exception('Maks ukuran foto 2MB.');

      $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
      $allowed = ['jpg','jpeg','png','webp'];
      if (!in_array($ext, $allowed, true)) throw new Exception('Format foto harus JPG/PNG/WEBP.');

      $dir = __DIR__ . '/../uploads/products';
      ensure_upload_dir($dir);

      $newName = 'p_' . bin2hex(random_bytes(8)) . '.' . $ext;
      $dest = $dir . '/' . $newName;

      if (!move_uploaded_file($f['tmp_name'], $dest)) throw new Exception('Gagal menyimpan file upload.');

      // Hapus foto lama
      if ($imagePath) {
        $old = __DIR__ . '/../' . $imagePath;
        if (file_exists($old)) @unlink($old);
      }
      $imagePath = 'uploads/products/' . $newName;
    }

    if ($id) {
      $stmt = db()->prepare("UPDATE products SET name=?, price=?, image_path=?, is_favorite=? WHERE id=?");
      $stmt->execute([$name, $price, $imagePath, $isFavorite, $id]);
    } else {
      $stmt = db()->prepare("INSERT INTO products (name, price, image_path, is_favorite) VALUES (?,?,?,?)");
      $stmt->execute([$name, $price, $imagePath, $isFavorite]);
    }

    redirect(base_url('admin/products.php'));
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo $id ? 'Edit Produk' : 'Tambah Produk'; ?></title>
  <link rel="stylesheet" href="<?php echo e(base_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <button class="btn" data-toggle-sidebar type="button">Menu</button>
      <a class="btn" href="<?php echo e(base_url('admin/products.php')); ?>">Kembali</a>
    </div>

    <div class="content">
      <div class="card">
        <h3 style="margin-top:0"><?php echo $id ? 'Edit Produk' : 'Tambah Produk'; ?></h3>
        <?php if ($err): ?>
          <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <div class="row">
            <label>Nama Produk</label>
            <input name="name" value="<?php echo e($_POST['name'] ?? $product['name']); ?>" required>
          </div>
          <div class="row">
            <label>Harga</label>
            <input name="price" value="<?php echo e($_POST['price'] ?? (string)$product['price']); ?>" required>
            <small>Gunakan angka, contoh: 12500</small>
          </div>
          <div class="row">
            <label>Foto Produk (opsional, max 2MB)</label>
            <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
            <?php if (!empty($product['image_path'])): ?>
              <div style="margin-top:10px">
                <img class="thumb" src="<?php echo e(base_url($product['image_path'])); ?>">
              </div>
            <?php endif; ?>
          </div>
          <div class="row">
            <label>
              <input type="checkbox" name="is_favorite" value="1" <?php echo !empty($_POST) ? (isset($_POST['is_favorite']) ? 'checked' : '') : ((int)$product['is_favorite'] === 1 ? 'checked' : ''); ?>>
              Tandai sebagai favorit (tampil di landing page)
            </label>
          </div>
          <button class="btn" type="submit">Simpan</button>
        </form>
      </div>
    </div>
  </div>
</div>
<script src="<?php echo e(base_url('assets/app.js')); ?>"></script>
</body>
</html>
