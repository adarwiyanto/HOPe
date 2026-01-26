<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

require_login();

$appName = app_config()['app']['name'];
$products = db()->query("SELECT id, name, price FROM products ORDER BY name ASC")->fetchAll();
$productsById = [];
foreach ($products as $p) {
  $productsById[(int)$p['id']] = $p;
}

start_session();
$cart = $_SESSION['pos_cart'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  $productId = (int)($_POST['product_id'] ?? 0);

  try {
    if (in_array($action, ['add','inc','dec','remove'], true)) {
      if ($productId <= 0 || empty($productsById[$productId])) {
        throw new Exception('Produk tidak ditemukan.');
      }
    }

    if ($action === 'add') {
      $cart[$productId] = ($cart[$productId] ?? 0) + 1;
      $_SESSION['pos_notice'] = 'Produk ditambahkan ke keranjang.';
    } elseif ($action === 'inc') {
      $cart[$productId] = ($cart[$productId] ?? 1) + 1;
      $_SESSION['pos_notice'] = 'Jumlah produk ditambah.';
    } elseif ($action === 'dec') {
      $current = (int)($cart[$productId] ?? 1);
      if ($current <= 1) {
        unset($cart[$productId]);
      } else {
        $cart[$productId] = $current - 1;
      }
      $_SESSION['pos_notice'] = 'Jumlah produk dikurangi.';
    } elseif ($action === 'remove') {
      unset($cart[$productId]);
      $_SESSION['pos_notice'] = 'Produk dihapus dari keranjang.';
    } elseif ($action === 'checkout') {
      if (empty($cart)) throw new Exception('Keranjang masih kosong.');
      $db = db();
      $db->beginTransaction();
      $stmt = $db->prepare("INSERT INTO sales (product_id, qty, price_each, total) VALUES (?,?,?,?)");
      foreach ($cart as $pid => $qty) {
        if (empty($productsById[$pid])) {
          throw new Exception('Produk tidak ditemukan saat checkout.');
        }
        $price = (float)$productsById[$pid]['price'];
        $total = $price * (int)$qty;
        $stmt->execute([(int)$pid, (int)$qty, $price, $total]);
      }
      $db->commit();
      $cart = [];
      $_SESSION['pos_notice'] = 'Transaksi berhasil disimpan.';
    }
  } catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
      $db->rollBack();
    }
    $_SESSION['pos_err'] = $e->getMessage();
  }

  $_SESSION['pos_cart'] = $cart;
  redirect(base_url('pos/index.php'));
}

$notice = $_SESSION['pos_notice'] ?? '';
$err = $_SESSION['pos_err'] ?? '';
unset($_SESSION['pos_notice'], $_SESSION['pos_err']);

$cartItems = [];
$total = 0.0;
foreach ($cart as $pid => $qty) {
  if (empty($productsById[$pid])) continue;
  $price = (float)$productsById[$pid]['price'];
  $subtotal = $price * (int)$qty;
  $total += $subtotal;
  $cartItems[] = [
    'id' => (int)$pid,
    'name' => $productsById[$pid]['name'],
    'price' => $price,
    'qty' => (int)$qty,
    'subtotal' => $subtotal,
  ];
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>POS</title>
  <link rel="stylesheet" href="<?php echo e(base_url('assets/app.css')); ?>">
  <style>
    .pos-page{min-height:100vh;display:flex;flex-direction:column}
    .pos-wrap{max-width:1200px;margin:0 auto;padding:12px 12px 24px;width:100%}
    .pos-grid{display:grid;grid-template-columns:1fr;gap:12px}
    .pos-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:12px;box-shadow:var(--shadow)}
    .pos-product{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px;border:1px solid var(--border);border-radius:12px;background:#fff}
    .pos-product + .pos-product{margin-top:10px}
    .pos-product-name{font-weight:700}
    .pos-product-price{color:var(--muted)}
    .pos-btn{padding:10px 14px;border-radius:12px;font-weight:700}
    .pos-cart-item{border:1px solid var(--border);border-radius:12px;padding:10px;display:flex;flex-direction:column;gap:8px;background:#fff}
    .pos-cart-item + .pos-cart-item{margin-top:10px}
    .pos-cart-actions{display:flex;flex-wrap:wrap;gap:8px}
    .pos-cart-actions form{margin:0}
    .pos-cart-total{display:flex;align-items:center;justify-content:space-between;font-weight:700;margin-top:12px}
    .pos-checkout{width:100%;padding:14px;border-radius:12px;font-size:16px}
    .pos-alert{margin-bottom:12px}
    @media (min-width: 768px){
      .pos-grid{grid-template-columns:1.2fr 1fr}
      .pos-checkout{width:auto}
    }
  </style>
</head>
<body>
  <div class="pos-page">
    <div class="topbar">
      <div class="title"><?php echo e($appName); ?> POS</div>
      <div class="spacer"></div>
      <a class="btn" href="<?php echo e(base_url('pos/logout.php')); ?>">Logout</a>
    </div>

    <div class="pos-wrap">
      <?php if ($notice): ?>
        <div class="pos-card pos-alert" style="border-color:#86efac;background:#dcfce7"><?php echo e($notice); ?></div>
      <?php endif; ?>
      <?php if ($err): ?>
        <div class="pos-card pos-alert" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
      <?php endif; ?>

      <div class="pos-grid">
        <div class="pos-card">
          <h3 style="margin-top:0">Daftar Produk</h3>
          <?php foreach ($products as $p): ?>
            <form method="post" class="pos-product">
              <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="product_id" value="<?php echo e((string)$p['id']); ?>">
              <div>
                <div class="pos-product-name"><?php echo e($p['name']); ?></div>
                <div class="pos-product-price">Rp <?php echo e(number_format((float)$p['price'], 0, '.', ',')); ?></div>
              </div>
              <button class="btn pos-btn" type="submit">Tambah</button>
            </form>
          <?php endforeach; ?>
        </div>

        <div class="pos-card">
          <h3 style="margin-top:0">Keranjang</h3>
          <?php if (empty($cartItems)): ?>
            <p><small>Keranjang masih kosong.</small></p>
          <?php else: ?>
            <?php foreach ($cartItems as $item): ?>
              <div class="pos-cart-item">
                <div>
                  <div class="pos-product-name"><?php echo e($item['name']); ?></div>
                  <div class="pos-product-price">Rp <?php echo e(number_format($item['price'], 0, '.', ',')); ?> x <?php echo e((string)$item['qty']); ?></div>
                </div>
                <div class="pos-cart-actions">
                  <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="dec">
                    <input type="hidden" name="product_id" value="<?php echo e((string)$item['id']); ?>">
                    <button class="btn pos-btn" type="submit">−</button>
                  </form>
                  <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="inc">
                    <input type="hidden" name="product_id" value="<?php echo e((string)$item['id']); ?>">
                    <button class="btn pos-btn" type="submit">+</button>
                  </form>
                  <form method="post">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="product_id" value="<?php echo e((string)$item['id']); ?>">
                    <button class="btn pos-btn" type="submit">Hapus</button>
                  </form>
                </div>
                <div><small>Subtotal: Rp <?php echo e(number_format($item['subtotal'], 0, '.', ',')); ?></small></div>
              </div>
            <?php endforeach; ?>

            <div class="pos-cart-total">
              <div>Total</div>
              <div>Rp <?php echo e(number_format($total, 0, '.', ',')); ?></div>
            </div>

            <form method="post" style="margin-top:12px">
              <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
              <input type="hidden" name="action" value="checkout">
              <button class="btn pos-checkout" type="submit">Checkout</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
