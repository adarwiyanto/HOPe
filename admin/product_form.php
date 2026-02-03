<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
require_admin();

$id = (int)($_GET['id'] ?? 0);
ensure_products_category_column();
ensure_product_categories_table();
ensure_products_best_seller_column();
$product = ['name'=>'','category'=>'','price'=>'0','image_path'=>null,'is_best_seller'=>0];

if ($id) {
  $stmt = db()->prepare("SELECT * FROM products WHERE id=?");
  $stmt->execute([$id]);
  $product = $stmt->fetch() ?: $product;
}

$err = '';
$categories = product_categories();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  $name = trim($_POST['name'] ?? '');
  $category = trim($_POST['category'] ?? '');
  $price = normalize_money($_POST['price'] ?? '0');
  $isBestSeller = isset($_POST['is_best_seller']) ? 1 : 0;

  try {
    if ($name === '') throw new Exception('Nama produk wajib diisi.');
    if (!empty($categories)) {
      $categoryNames = array_map(static function ($cat) {
        return $cat['name'];
      }, $categories);
      $isLegacyCategory = $id && $product['category'] === $category;
      if ($category !== '' && !in_array($category, $categoryNames, true) && !$isLegacyCategory) {
        throw new Exception('Kategori tidak valid. Silakan pilih dari daftar.');
      }
    }

    // Upload foto (opsional)
    $imagePath = $product['image_path'];
    if (!empty($_FILES['image']['name'])) {
      $f = $_FILES['image'];
      if ($f['error'] !== UPLOAD_ERR_OK) throw new Exception('Upload gagal.');
      if ($f['size'] > 2 * 1024 * 1024) throw new Exception('Maks ukuran foto 2MB.');

      $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
      $allowed = ['jpg','jpeg','png','webp'];
      if (!in_array($ext, $allowed, true)) throw new Exception('Format foto harus JPG/PNG/WEBP.');
      $finfo = new finfo(FILEINFO_MIME_TYPE);
      $mime = $finfo->file($f['tmp_name']);
      $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
      if (!in_array($mime, $allowedMime, true)) throw new Exception('MIME foto tidak valid.');

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
      $stmt = db()->prepare("UPDATE products SET name=?, category=?, is_best_seller=?, price=?, image_path=? WHERE id=?");
      $stmt->execute([$name, $category, $isBestSeller, $price, $imagePath, $id]);
    } else {
      $stmt = db()->prepare("INSERT INTO products (name, category, is_best_seller, price, image_path) VALUES (?,?,?,?,?)");
      $stmt->execute([$name, $category, $isBestSeller, $price, $imagePath]);
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
      <a class="btn" href="<?php echo e(base_url('admin/products.php')); ?>">Kembali</a>
    </div>

    <div class="content">
      <div class="card product-form-card">
        <h3><?php echo $id ? 'Edit Produk' : 'Tambah Produk'; ?></h3>
        <?php if ($err): ?>
          <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <div class="row">
            <label>Nama Produk</label>
            <input name="name" value="<?php echo e($_POST['name'] ?? $product['name']); ?>" required>
          </div>
          <div class="grid cols-2">
            <div class="row">
              <label>Kategori</label>
              <?php
                $selectedCategory = $_POST['category'] ?? $product['category'];
                $categoryNames = array_map(static function ($cat) {
                  return $cat['name'];
                }, $categories);
                $hasLegacyCategory = $selectedCategory !== '' && !in_array($selectedCategory, $categoryNames, true);
              ?>
              <select name="category">
                <option value="">Tanpa kategori</option>
                <?php foreach ($categories as $category): ?>
                  <option value="<?php echo e($category['name']); ?>" <?php echo $selectedCategory === $category['name'] ? 'selected' : ''; ?>>
                    <?php echo e($category['name']); ?>
                  </option>
                <?php endforeach; ?>
                <?php if ($hasLegacyCategory): ?>
                  <option value="<?php echo e($selectedCategory); ?>" selected>
                    <?php echo e($selectedCategory); ?> (belum terdaftar)
                  </option>
                <?php endif; ?>
              </select>
              <?php if (empty($categories)): ?>
                <small>Belum ada kategori. Tambahkan di menu <a href="<?php echo e(base_url('admin/product_categories.php')); ?>">Kategori Produk</a>.</small>
              <?php else: ?>
                <small>Kelola kategori di menu <a href="<?php echo e(base_url('admin/product_categories.php')); ?>">Kategori Produk</a>.</small>
              <?php endif; ?>
            </div>
            <div class="row">
              <label>Harga</label>
              <input type="number" name="price" inputmode="numeric" min="0" step="1" value="<?php echo e($_POST['price'] ?? (string)$product['price']); ?>" required>
              <small>Gunakan angka, contoh: 12500</small>
            </div>
          </div>
          <div class="row">
            <label>Foto Produk (opsional, max 2MB)</label>
            <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
            <?php if (!empty($product['image_path'])): ?>
              <div class="helper">
                <small>Foto saat ini:</small>
                <img class="thumb" src="<?php echo e(base_url($product['image_path'])); ?>">
              </div>
            <?php endif; ?>
          </div>
          <div class="row">
            <label class="checkbox-row">
              <input type="checkbox" name="is_best_seller" value="1" <?php echo !empty($_POST) ? (isset($_POST['is_best_seller']) ? 'checked' : '') : ((int)$product['is_best_seller'] === 1 ? 'checked' : ''); ?>>
              Tandai sebagai best seller (tampil di paling atas)
            </label>
          </div>
          <button class="btn" type="submit">Simpan</button>
        </form>
      </div>
    </div>
  </div>
</div>
<script src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
