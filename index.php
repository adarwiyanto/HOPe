<?php
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/functions.php';
require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/csrf.php';

try {
  ensure_products_favorite_column();
  ensure_landing_order_tables();
  $products = db()->query("SELECT * FROM products WHERE is_favorite = 1 ORDER BY id DESC LIMIT 30")->fetchAll();
} catch (Throwable $e) {
  header('Location: install/index.php');
  exit;
}
$appName = app_config()['app']['name'];
$storeName = setting('store_name', $appName);
$storeSubtitle = setting('store_subtitle', 'Katalog produk sederhana');
$storeIntro = setting('store_intro', 'Kami adalah usaha yang menghadirkan produk pilihan dengan kualitas terbaik untuk kebutuhan Anda.');
$storeLogo = setting('store_logo', '');
$customCss = setting('custom_css', '');
$landingCss = setting('landing_css', '');
$landingHtml = setting('landing_html', '');
$recaptchaSiteKey = setting('recaptcha_site_key', '');
$recaptchaSecretKey = setting('recaptcha_secret_key', '');
$recaptchaAction = 'checkout';
$recaptchaMinScore = 0.5;

start_session();
$cart = $_SESSION['landing_cart'] ?? [];
$notice = $_SESSION['landing_notice'] ?? '';
$err = $_SESSION['landing_err'] ?? '';
unset($_SESSION['landing_notice'], $_SESSION['landing_err']);

$productsById = [];
foreach ($products as $p) {
  $productsById[(int)$p['id']] = $p;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';
  $productId = (int)($_POST['product_id'] ?? 0);

  try {
    if (in_array($action, ['add', 'inc', 'dec', 'remove'], true)) {
      if ($productId <= 0 || empty($productsById[$productId])) {
        throw new Exception('Produk tidak ditemukan.');
      }
    }

    if ($action === 'add') {
      $cart[$productId] = ($cart[$productId] ?? 0) + 1;
      $notice = 'Produk ditambahkan ke keranjang.';
    } elseif ($action === 'inc') {
      $cart[$productId] = ($cart[$productId] ?? 1) + 1;
      $notice = 'Jumlah produk ditambah.';
    } elseif ($action === 'dec') {
      $current = (int)($cart[$productId] ?? 1);
      if ($current <= 1) {
        unset($cart[$productId]);
      } else {
        $cart[$productId] = $current - 1;
      }
      $notice = 'Jumlah produk dikurangi.';
    } elseif ($action === 'remove') {
      unset($cart[$productId]);
      $notice = 'Produk dihapus dari keranjang.';
    } elseif ($action === 'checkout') {
      if (empty($cart)) {
        throw new Exception('Keranjang masih kosong.');
      }
      $customerName = trim($_POST['customer_name'] ?? '');
      $customerPhone = trim($_POST['customer_phone'] ?? '');
      if ($customerName === '') {
        throw new Exception('Nama wajib diisi.');
      }
      if ($customerPhone === '') {
        throw new Exception('Nomor telepon/WA wajib diisi.');
      }
      if (!preg_match('/^[0-9+][0-9\\s\\-]{6,20}$/', $customerPhone)) {
        throw new Exception('Nomor telepon/WA tidak valid.');
      }
      if ($recaptchaSecretKey === '') {
        throw new Exception('reCAPTCHA belum diatur oleh admin.');
      }
      $recaptchaToken = (string)($_POST['g-recaptcha-response'] ?? '');
      if (!verify_recaptcha_response(
        $recaptchaToken,
        $recaptchaSecretKey,
        $_SERVER['REMOTE_ADDR'] ?? '',
        $recaptchaAction,
        $recaptchaMinScore
      )) {
        throw new Exception('Verifikasi reCAPTCHA gagal.');
      }

      $db = db();
      $db->beginTransaction();

      $stmt = $db->prepare("SELECT id FROM customers WHERE phone=? LIMIT 1");
      $stmt->execute([$customerPhone]);
      $customer = $stmt->fetch();
      if ($customer) {
        $customerId = (int)$customer['id'];
        $stmt = $db->prepare("UPDATE customers SET name=? WHERE id=?");
        $stmt->execute([$customerName, $customerId]);
      } else {
        $stmt = $db->prepare("INSERT INTO customers (name, email, phone) VALUES (?, ?, ?)");
        $stmt->execute([$customerName, $customerPhone, $customerPhone]);
        $customerId = (int)$db->lastInsertId();
      }

      $orderCode = 'ORD-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(2)));
      $stmt = $db->prepare("INSERT INTO orders (order_code, customer_id, status) VALUES (?,?, 'pending')");
      $stmt->execute([$orderCode, $customerId]);
      $orderId = (int)$db->lastInsertId();

      $stmt = $db->prepare("INSERT INTO order_items (order_id, product_id, qty, price_each, subtotal) VALUES (?,?,?,?,?)");
      $orderTotal = 0;
      foreach ($cart as $pid => $qty) {
        if (empty($productsById[$pid])) {
          throw new Exception('Produk tidak ditemukan saat checkout.');
        }
        $qty = (int)$qty;
        if ($qty <= 0) {
          throw new Exception('Jumlah produk tidak valid.');
        }
        $price = (float)$productsById[$pid]['price'];
        $subtotal = $price * $qty;
        $stmt->execute([$orderId, (int)$pid, $qty, $price, $subtotal]);
        $orderTotal += $subtotal;
      }

      $db->commit();
      $cart = [];
      $notice = 'Pesanan berhasil dikirim. Kode pesanan: ' . $orderCode;
    }
  } catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
      $db->rollBack();
    }
    $err = $e->getMessage();
  }

  $_SESSION['landing_cart'] = $cart;
  $_SESSION['landing_notice'] = $notice;
  $_SESSION['landing_err'] = $err;
  redirect(base_url('index.php'));
}

$cartItems = [];
$cartTotal = 0.0;
$cartCount = 0;
foreach ($cart as $pid => $qty) {
  if (empty($productsById[$pid])) continue;
  $price = (float)$productsById[$pid]['price'];
  $qty = (int)$qty;
  $subtotal = $price * $qty;
  $cartTotal += $subtotal;
  $cartCount += $qty;
  $cartItems[] = [
    'id' => (int)$pid,
    'name' => $productsById[$pid]['name'],
    'price' => $price,
    'qty' => $qty,
    'subtotal' => $subtotal,
  ];
}

$currentUser = current_user();
$loginButton = $currentUser
  ? '<a class="btn" href="' . e(base_url('admin/dashboard.php')) . '">Admin</a>'
  : '<a class="btn" href="' . e(base_url('login.php')) . '">Login</a>';
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo e($storeName); ?></title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="apple-touch-icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="manifest" href="<?php echo e(base_url('manifest.php')); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?><?php echo $landingCss; ?></style>
</head>
<body>
  <?php
    $productCards = '';
    ob_start();
  ?>
    <div class="grid cols-2 landing-products" style="margin-top:16px">
      <?php foreach ($products as $p): ?>
        <form method="post" class="landing-product-form">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="add">
          <input type="hidden" name="product_id" value="<?php echo e((string)$p['id']); ?>">
          <button class="card landing-product-card" type="submit">
            <span class="landing-product-body">
              <?php if (!empty($p['image_path'])): ?>
                <img class="thumb" src="<?php echo e(base_url($p['image_path'])); ?>" alt="">
              <?php else: ?>
                <span class="thumb landing-product-thumb-fallback">No Img</span>
              <?php endif; ?>
              <span class="landing-product-info">
                <span class="landing-product-name"><?php echo e($p['name']); ?></span>
                <span class="badge">Rp <?php echo e(number_format((float)$p['price'], 0, '.', ',')); ?></span>
              </span>
            </span>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
  <?php
    $productCards = ob_get_clean();
    $noticeBlock = '';
    if ($notice) {
      $noticeBlock = '<div class="card landing-alert landing-alert-success">' . e($notice) . '</div>';
    } elseif ($err) {
      $noticeBlock = '<div class="card landing-alert landing-alert-error">' . e($err) . '</div>';
    }

    ob_start();
  ?>
    <div class="card landing-cart">
      <h3 style="margin-top:0">Keranjang</h3>
      <?php if (empty($cartItems)): ?>
        <p style="margin:0;color:var(--muted)">Keranjang masih kosong. Klik produk untuk menambah.</p>
      <?php else: ?>
        <div class="landing-cart-items">
          <?php foreach ($cartItems as $item): ?>
            <div class="landing-cart-item">
              <div>
                <div class="landing-cart-name"><?php echo e($item['name']); ?></div>
                <div class="landing-cart-price">Rp <?php echo e(number_format((float)$item['price'], 0, '.', ',')); ?></div>
              </div>
              <div class="landing-cart-actions">
                <form method="post">
                  <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                  <input type="hidden" name="action" value="dec">
                  <input type="hidden" name="product_id" value="<?php echo e((string)$item['id']); ?>">
                  <button class="btn btn-light" type="submit">−</button>
                </form>
                <div class="landing-cart-qty"><?php echo e((string)$item['qty']); ?></div>
                <form method="post">
                  <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                  <input type="hidden" name="action" value="inc">
                  <input type="hidden" name="product_id" value="<?php echo e((string)$item['id']); ?>">
                  <button class="btn btn-light" type="submit">+</button>
                </form>
                <form method="post">
                  <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                  <input type="hidden" name="action" value="remove">
                  <input type="hidden" name="product_id" value="<?php echo e((string)$item['id']); ?>">
                  <button class="btn btn-ghost" type="submit">Hapus</button>
                </form>
              </div>
              <div class="landing-cart-subtotal">Rp <?php echo e(number_format((float)$item['subtotal'], 0, '.', ',')); ?></div>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="landing-cart-summary">
          <div>Total (<?php echo e((string)$cartCount); ?> item)</div>
          <strong>Rp <?php echo e(number_format((float)$cartTotal, 0, '.', ',')); ?></strong>
        </div>
        <form method="post" class="landing-checkout">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="checkout">
          <div class="row">
            <label>Nama</label>
            <input name="customer_name" required>
          </div>
          <div class="row">
            <label>Nomor Telepon / WhatsApp</label>
            <input name="customer_phone" type="tel" inputmode="tel" placeholder="Contoh: 08xxxxxxxxxx" required>
          </div>
          <?php if (!empty($recaptchaSiteKey)): ?>
            <input type="hidden" name="g-recaptcha-response" id="recaptcha-token">
          <?php else: ?>
            <div class="card landing-alert landing-alert-error" style="margin-top:12px">
              reCAPTCHA belum disetting. Hubungi admin.
            </div>
          <?php endif; ?>
          <button class="btn landing-checkout-btn" type="submit" <?php echo $recaptchaSiteKey === '' ? 'disabled' : ''; ?>>Kirim Pesanan</button>
        </form>
      <?php endif; ?>
    </div>
  <?php
    $cartBlock = ob_get_clean();
    $logoBlock = '';
    $storeLogoUrl = '';
    if (!empty($storeLogo)) {
      $storeLogoUrl = base_url($storeLogo);
      $logoBlock = '<img src="' . e($storeLogoUrl) . '" alt="' . e($storeName) . '" style="width:56px;height:56px;object-fit:cover;border-radius:12px;border:1px solid var(--border)">';
    }
    $landingTemplate = $landingHtml !== '' ? $landingHtml : landing_default_html();
    echo strtr($landingTemplate, [
      '{{store_name}}' => e($storeName),
      '{{store_subtitle}}' => e($storeSubtitle),
      '{{store_intro}}' => e($storeIntro),
      '{{store_logo}}' => e($storeLogoUrl),
      '{{store_logo_block}}' => $logoBlock,
      '{{login_button}}' => $loginButton,
      '{{login_url}}' => e(base_url('login.php')),
      '{{notice}}' => $noticeBlock,
      '{{products}}' => $productCards,
      '{{cart}}' => $cartBlock,
    ]);
  ?>
  <?php if (!empty($recaptchaSiteKey)): ?>
    <script src="https://www.google.com/recaptcha/api.js?render=<?php echo e($recaptchaSiteKey); ?>"></script>
    <script>
      (function () {
        const form = document.querySelector('.landing-checkout');
        if (!form) return;
        const tokenInput = document.getElementById('recaptcha-token');
        if (!tokenInput) return;
        form.addEventListener('submit', function (event) {
          if (form.dataset.recaptchaReady === '1') return;
          event.preventDefault();
          grecaptcha.ready(function () {
            grecaptcha.execute('<?php echo e($recaptchaSiteKey); ?>', { action: '<?php echo e($recaptchaAction); ?>' })
              .then(function (token) {
                tokenInput.value = token;
                form.dataset.recaptchaReady = '1';
                form.submit();
              })
              .catch(function () {
                form.dataset.recaptchaReady = '';
                form.submit();
              });
          });
        });
      })();
    </script>
  <?php endif; ?>
</body>
</html>
