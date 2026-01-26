<?php
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';

$appName = app_config()['app']['name'];
$u = current_user();
?>
<div class="sidebar">
  <div class="sb-top">
    <div class="profile-card">
      <div class="avatar">A</div>
      <div class="p-text">
        <div class="p-title"><?php echo e($u['name'] ?? 'User'); ?></div>
        <div class="p-sub"><?php echo e(ucfirst($u['role'] ?? 'admin')); ?></div>
      </div>
      <div class="p-right">
        <span class="badge-pill">75</span>
        <span class="badge-pill">▾</span>
      </div>
    </div>
  </div>

  <div class="nav">
    <div class="item">
      <a class="<?php echo (basename($_SERVER['PHP_SELF'])==='dashboard.php')?'active':''; ?>"
         href="<?php echo e(base_url('admin/dashboard.php')); ?>">
        <div class="mi">🏠</div><div class="label">Dasbor</div>
      </a>
    </div>

    <div class="item">
      <button type="button" data-toggle-submenu="#m-produk">
        <div class="mi">📦</div><div class="label">Produk & Inventori</div>
        <div class="chev">▾</div>
      </button>
      <div class="submenu" id="m-produk">
        <a href="<?php echo e(base_url('admin/products.php')); ?>">Produk</a>
      </div>
    </div>

    <div class="item">
      <button type="button" data-toggle-submenu="#m-transaksi">
        <div class="mi">💳</div><div class="label">Transaksi & Pembayaran</div>
        <div class="chev">▾</div>
      </button>
      <div class="submenu" id="m-transaksi">
        <a href="<?php echo e(base_url('admin/sales.php')); ?>">Penjualan</a>
      </div>
    </div>

    <div class="item">
      <button type="button" data-toggle-submenu="#m-admin">
        <div class="mi">⚙️</div><div class="label">Admin</div>
        <div class="chev">▾</div>
      </button>
      <div class="submenu" id="m-admin">
        <a href="<?php echo e(base_url('admin/users.php')); ?>">User</a>
        <a href="<?php echo e(base_url('admin/theme.php')); ?>">Tema / CSS</a></a>
      </div>
    </div>

    <div class="item">
      <a href="<?php echo e(base_url('admin/logout.php')); ?>">
        <div class="mi">⎋</div><div class="label">Logout</div>
      </a>
    </div>
  </div>
</div>
