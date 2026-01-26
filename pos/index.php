<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

require_login();

$appName = app_config()['app']['name'];
$storeName = setting('store_name', $appName);
$storeSubtitle = setting('store_subtitle', '');
$me = current_user();
$products = db()->query("SELECT id, name, price, image_path FROM products ORDER BY name ASC")->fetchAll();
$hasProducts = !empty($products);
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
      $receiptItems = [];
      $receiptTotal = 0.0;
      foreach ($cart as $pid => $qty) {
        if (empty($productsById[$pid])) {
          throw new Exception('Produk tidak ditemukan saat checkout.');
        }
        $qty = (int)$qty;
        if ($qty <= 0) {
          throw new Exception('Jumlah produk tidak valid.');
        }
        $price = (float)$productsById[$pid]['price'];
        $total = $price * $qty;
        $stmt->execute([(int)$pid, $qty, $price, $total]);
        $receiptItems[] = [
          'name' => $productsById[$pid]['name'],
          'qty' => $qty,
          'price' => $price,
          'subtotal' => $total,
        ];
        $receiptTotal += $total;
      }
      $db->commit();
      $_SESSION['pos_receipt'] = [
        'id' => 'TRX-' . date('YmdHis'),
        'time' => date('d/m/Y H:i'),
        'cashier' => $me['name'] ?? 'Kasir',
        'items' => $receiptItems,
        'total' => $receiptTotal,
      ];
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
$receipt = $_SESSION['pos_receipt'] ?? null;
unset($_SESSION['pos_notice'], $_SESSION['pos_err']);

$cartItems = [];
$total = 0.0;
$cartCount = 0;
foreach ($cart as $pid => $qty) {
  if (empty($productsById[$pid])) continue;
  $price = (float)$productsById[$pid]['price'];
  $qty = (int)$qty;
  $subtotal = $price * $qty;
  $total += $subtotal;
  $cartCount += $qty;
  $cartItems[] = [
    'id' => (int)$pid,
    'name' => $productsById[$pid]['name'],
    'price' => $price,
    'qty' => $qty,
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
  <link rel="stylesheet" href="<?php echo e(base_url('pos/pos.css')); ?>">
</head>
<body>
  <div class="pos-page">
    <div class="topbar pos-topbar">
      <div class="title"><?php echo e($appName); ?> POS</div>
      <div class="spacer"></div>
      <div class="pos-user">
        <div class="pos-user-name"><?php echo e($me['name'] ?? 'User'); ?></div>
        <div class="pos-user-role"><?php echo e(ucfirst($me['role'] ?? '')); ?></div>
      </div>
      <a class="btn pos-logout" href="<?php echo e(base_url('pos/logout.php')); ?>">Logout</a>
    </div>

    <div class="pos-wrap">
      <?php if ($notice): ?>
        <div class="pos-panel pos-alert pos-alert-success"><?php echo e($notice); ?></div>
      <?php endif; ?>
      <?php if ($err): ?>
        <div class="pos-panel pos-alert pos-alert-error"><?php echo e($err); ?></div>
      <?php endif; ?>
      <?php if (!empty($receipt)): ?>
        <div class="pos-panel pos-receipt pos-print-area">
          <div class="pos-receipt-header">
            <div>
              <div class="pos-receipt-title"><?php echo e($storeName); ?></div>
              <?php if (!empty($storeSubtitle)): ?>
                <div class="pos-receipt-subtitle"><?php echo e($storeSubtitle); ?></div>
              <?php endif; ?>
            </div>
            <div class="pos-receipt-meta">
              <div><?php echo e($receipt['id']); ?></div>
              <div><?php echo e($receipt['time']); ?></div>
              <div>Kasir: <?php echo e($receipt['cashier']); ?></div>
            </div>
          </div>

          <div class="pos-receipt-items">
            <?php foreach ($receipt['items'] as $item): ?>
              <div class="pos-receipt-row">
                <div>
                  <div class="pos-receipt-item-name"><?php echo e($item['name']); ?></div>
                  <div class="pos-receipt-item-meta"><?php echo e((string)$item['qty']); ?> x Rp <?php echo e(number_format((float)$item['price'], 0, '.', ',')); ?></div>
                </div>
                <div class="pos-receipt-item-subtotal">Rp <?php echo e(number_format((float)$item['subtotal'], 0, '.', ',')); ?></div>
              </div>
            <?php endforeach; ?>
          </div>

          <div class="pos-receipt-total">
            <span>Total</span>
            <strong>Rp <?php echo e(number_format((float)$receipt['total'], 0, '.', ',')); ?></strong>
          </div>
          <div class="pos-receipt-actions no-print">
            <button class="btn pos-print-btn" type="button" data-print-receipt>Cetak Struk</button>
          </div>
        </div>
      <?php endif; ?>

      <div class="pos-layout">
        <section class="pos-panel pos-products">
          <div class="pos-products-header">
            <div>
              <h3>Produk</h3>
              <small>Pilih produk untuk ditambahkan ke keranjang.</small>
            </div>
            <div class="pos-search">
              <input id="pos-search" type="search" placeholder="Cari produk..." autocomplete="off">
            </div>
          </div>
          <div class="pos-products-grid">
            <?php foreach ($products as $p): ?>
              <div class="pos-product-card" data-name="<?php echo e(strtolower($p['name'])); ?>">
                <div class="pos-product-thumb">
                  <?php if (!empty($p['image_path'])): ?>
                    <img src="<?php echo e(base_url($p['image_path'])); ?>" alt="<?php echo e($p['name']); ?>">
                  <?php else: ?>
                    <div class="pos-product-placeholder">No Image</div>
                  <?php endif; ?>
                </div>
                <div class="pos-product-info">
                  <div class="pos-product-name"><?php echo e($p['name']); ?></div>
                  <div class="pos-product-price">Rp <?php echo e(number_format((float)$p['price'], 0, '.', ',')); ?></div>
                </div>
                <form method="post" class="pos-product-action">
                  <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                  <input type="hidden" name="action" value="add">
                  <input type="hidden" name="product_id" value="<?php echo e((string)$p['id']); ?>">
                  <button class="btn pos-btn pos-add-btn" type="submit">Tambah</button>
                </form>
              </div>
            <?php endforeach; ?>
            <?php if ($hasProducts): ?>
              <div class="pos-empty" id="pos-empty">Produk tidak ditemukan.</div>
            <?php else: ?>
              <div class="pos-empty" style="display:block">Belum ada produk.</div>
            <?php endif; ?>
          </div>
        </section>

        <aside class="pos-panel pos-cart">
          <div class="pos-cart-header">
            <div>
              <h3>Keranjang</h3>
              <small>Ringkasan transaksi.</small>
            </div>
            <div class="pos-cart-count"><?php echo e((string)$cartCount); ?> item</div>
          </div>

          <?php if (empty($cartItems)): ?>
            <div class="pos-empty-cart">
              <p><strong>Keranjang masih kosong.</strong></p>
              <small>Tambahkan produk dari daftar di kiri.</small>
            </div>
          <?php else: ?>
            <div class="pos-cart-items">
              <?php foreach ($cartItems as $item): ?>
                <div class="pos-cart-item">
                  <div class="pos-cart-item-head">
                    <div class="pos-cart-item-name"><?php echo e($item['name']); ?></div>
                    <div class="pos-cart-item-price">Rp <?php echo e(number_format($item['price'], 0, '.', ',')); ?></div>
                  </div>
                  <div class="pos-cart-row">
                    <div class="pos-qty">
                      <form method="post">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="dec">
                        <input type="hidden" name="product_id" value="<?php echo e((string)$item['id']); ?>">
                        <button class="btn pos-qty-btn" type="submit">−</button>
                      </form>
                      <div class="pos-qty-value"><?php echo e((string)$item['qty']); ?></div>
                      <form method="post">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="inc">
                        <input type="hidden" name="product_id" value="<?php echo e((string)$item['id']); ?>">
                        <button class="btn pos-qty-btn" type="submit">+</button>
                      </form>
                    </div>
                    <div class="pos-cart-subtotal">Rp <?php echo e(number_format($item['subtotal'], 0, '.', ',')); ?></div>
                  </div>
                  <form method="post" class="pos-remove-form">
                    <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="product_id" value="<?php echo e((string)$item['id']); ?>">
                    <button class="btn pos-remove-btn" type="submit">Hapus</button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="pos-summary">
              <div class="pos-summary-row">
                <span>Total</span>
                <strong>Rp <?php echo e(number_format($total, 0, '.', ',')); ?></strong>
              </div>
              <form method="post">
                <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="action" value="checkout">
                <button class="btn pos-checkout" type="submit">Checkout</button>
              </form>
            </div>
          <?php endif; ?>
        </aside>
      </div>
    </div>
  </div>
  <script src="<?php echo e(base_url('pos/pos.js')); ?>"></script>
</body>
</html>
