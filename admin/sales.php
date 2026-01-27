<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

require_admin();

$err = '';
$me = current_user();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? 'create';

  try {
    if ($action === 'delete') {
      if (($me['role'] ?? '') !== 'owner') {
        throw new Exception('Hanya owner yang bisa menghapus transaksi.');
      }
      $saleId = (int)($_POST['sale_id'] ?? 0);
      if ($saleId <= 0) throw new Exception('Transaksi tidak ditemukan.');
      $stmt = db()->prepare("SELECT payment_proof_path FROM sales WHERE id=?");
      $stmt->execute([$saleId]);
      $sale = $stmt->fetch();
      if (!empty($sale['payment_proof_path'])) {
        $fullPath = realpath(__DIR__ . '/../' . $sale['payment_proof_path']);
        $uploadsDir = realpath(__DIR__ . '/../uploads/qris');
        if ($fullPath && $uploadsDir && strpos($fullPath, $uploadsDir . DIRECTORY_SEPARATOR) === 0 && is_file($fullPath)) {
          unlink($fullPath);
        }
      }
      $stmt = db()->prepare("DELETE FROM sales WHERE id=?");
      $stmt->execute([$saleId]);
      redirect(base_url('admin/sales.php'));
    }

    if ($action === 'return') {
      if (!in_array($me['role'] ?? '', ['admin', 'owner'], true)) {
        throw new Exception('Anda tidak diizinkan meretur transaksi.');
      }
      $saleId = (int)($_POST['sale_id'] ?? 0);
      $reason = trim($_POST['return_reason'] ?? '');
      if ($saleId <= 0) throw new Exception('Transaksi tidak ditemukan.');
      if ($reason === '') throw new Exception('Alasan retur wajib diisi.');
      $stmt = db()->prepare("UPDATE sales SET return_reason=?, returned_at=NOW() WHERE id=?");
      $stmt->execute([$reason, $saleId]);
      redirect(base_url('admin/sales.php'));
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
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
  <style>
    .return-reason {
      width: 100%;
      min-width: 0;
      max-width: 420px;
    }
    .return-form {
      display: flex;
      flex-direction: column;
      align-items: flex-start;
      gap: 6px;
    }
    .return-reason-wrapper {
      width: 100%;
      display: none;
    }
    .return-form.is-open .return-reason-wrapper {
      display: block;
    }
  </style>
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
            <input type="hidden" name="action" value="create">
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
              <tr><th>Waktu</th><th>Produk</th><th>Qty</th><th>Total</th><th>Pembayaran</th><th>Bukti QRIS</th><th>Status</th><th>Aksi</th></tr>
            </thead>
            <tbody>
              <?php foreach ($sales as $s): ?>
                <tr>
                  <td><?php echo e($s['sold_at']); ?></td>
                  <td><?php echo e($s['product_name']); ?></td>
                  <td><?php echo e((string)$s['qty']); ?></td>
                  <td>Rp <?php echo e(number_format((float)$s['total'], 0, '.', ',')); ?></td>
                  <td><?php echo e($s['payment_method'] ?? '-'); ?></td>
                  <td>
                    <?php if (!empty($s['payment_proof_path'])): ?>
                      <button type="button" class="qris-thumb-btn" data-qris-full="<?php echo e(base_url($s['payment_proof_path'])); ?>">
                        <img class="qris-thumb" src="<?php echo e(base_url($s['payment_proof_path'])); ?>" alt="Bukti QRIS">
                      </button>
                    <?php else: ?>
                      -
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!empty($s['return_reason'])): ?>
                      Retur: <?php echo e($s['return_reason']); ?>
                    <?php else: ?>
                      Sukses
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (empty($s['return_reason']) && in_array($me['role'] ?? '', ['admin', 'owner'], true)): ?>
                      <form method="post" class="return-form" data-return-form>
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="return">
                        <input type="hidden" name="sale_id" value="<?php echo e((string)$s['id']); ?>">
                        <div class="return-reason-wrapper" data-return-reason>
                          <input class="return-reason" type="text" name="return_reason" placeholder="Alasan retur">
                        </div>
                        <button class="btn" type="submit" data-return-submit>Retur</button>
                      </form>
                    <?php endif; ?>
                    <?php if (($me['role'] ?? '') === 'owner'): ?>
                      <form method="post" onsubmit="return confirm('Hapus transaksi ini?');" style="margin-top:6px">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="sale_id" value="<?php echo e((string)$s['id']); ?>">
                        <button class="btn" type="submit">Hapus</button>
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
<div class="qris-preview-modal" data-qris-modal hidden>
  <div class="qris-preview-stage">
    <img alt="Preview bukti QRIS" data-qris-modal-img>
  </div>
  <button class="qris-preview-exit" type="button" data-qris-close>← Kembali</button>
</div>
<script>
  document.querySelectorAll('[data-return-form]').forEach((form) => {
    const reasonWrap = form.querySelector('[data-return-reason]');
    const reasonInput = reasonWrap ? reasonWrap.querySelector('input[name="return_reason"]') : null;
    form.addEventListener('submit', (event) => {
      if (!form.classList.contains('is-open')) {
        event.preventDefault();
        form.classList.add('is-open');
        if (reasonInput) {
          reasonInput.required = true;
          reasonInput.focus();
        }
      }
    });
  });

  const modal = document.querySelector('[data-qris-modal]');
  const modalImg = modal ? modal.querySelector('[data-qris-modal-img]') : null;
  const closeButtons = modal ? modal.querySelectorAll('[data-qris-close]') : [];
  const openButtons = document.querySelectorAll('[data-qris-full]');
  let scale = 1;
  let translateX = 0;
  let translateY = 0;
  let isPanning = false;
  let startX = 0;
  let startY = 0;

  const applyTransform = () => {
    if (!modalImg) return;
    modalImg.style.transform = `translate(${translateX}px, ${translateY}px) scale(${scale})`;
  };

  const resetTransform = () => {
    scale = 1;
    translateX = 0;
    translateY = 0;
    applyTransform();
  };

  openButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      if (!modal || !modalImg) return;
      const src = btn.getAttribute('data-qris-full');
      if (!src) return;
      modalImg.src = src;
      resetTransform();
      modal.hidden = false;
      modal.classList.add('is-open');
    });
  });

  closeButtons.forEach((btn) => {
    btn.addEventListener('click', () => {
      if (!modal) return;
      modal.classList.remove('is-open');
      modal.hidden = true;
      if (modalImg) modalImg.src = '';
    });
  });

  if (modalImg) {
    modalImg.addEventListener('pointerdown', (event) => {
      isPanning = true;
      startX = event.clientX - translateX;
      startY = event.clientY - translateY;
      modalImg.setPointerCapture(event.pointerId);
      modalImg.style.cursor = 'grabbing';
    });
    modalImg.addEventListener('pointermove', (event) => {
      if (!isPanning) return;
      translateX = event.clientX - startX;
      translateY = event.clientY - startY;
      applyTransform();
    });
    modalImg.addEventListener('pointerup', (event) => {
      isPanning = false;
      modalImg.releasePointerCapture(event.pointerId);
      modalImg.style.cursor = 'grab';
    });
    modalImg.addEventListener('pointercancel', () => {
      isPanning = false;
      modalImg.style.cursor = 'grab';
    });
  }

  if (modal) {
    modal.addEventListener('wheel', (event) => {
      if (!modalImg) return;
      event.preventDefault();
      const delta = event.deltaY < 0 ? 0.1 : -0.1;
      scale = Math.max(1, Math.min(4, scale + delta));
      applyTransform();
    }, { passive: false });

    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape') return;
      if (!modal.classList.contains('is-open')) return;
      modal.classList.remove('is-open');
      modal.hidden = true;
      if (modalImg) modalImg.src = '';
    });
  }
</script>
<script src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
