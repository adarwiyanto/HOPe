<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';

ensure_landing_order_tables();
ensure_loyalty_rewards_table();
require_customer();

$customer = current_customer();
$appName = app_config()['app']['name'];
$storeName = setting('store_name', $appName);
$customCss = setting('custom_css', '');

$stmt = db()->prepare("SELECT id, name, phone, gender, birth_date, loyalty_points FROM customers WHERE id=? LIMIT 1");
$stmt->execute([(int)$customer['id']]);
$customer = $stmt->fetch();

$points = (int)($customer['loyalty_points'] ?? 0);
$rewards = db()->query("
  SELECT lr.id, lr.points_required, p.name
  FROM loyalty_rewards lr
  JOIN products p ON p.id = lr.product_id
  ORDER BY lr.points_required ASC
")->fetchAll();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Poin Loyalti</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
  <style>
    .kpi-subtitle {
      margin: 4px 0 0;
      font-size: 12px;
      color: #6b7280;
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="main">
      <div class="topbar">
        <a class="brand-logo" href="<?php echo e(base_url('index.php')); ?>">
          <span><?php echo e($storeName); ?></span>
        </a>
        <div class="spacer"></div>
        <a class="btn" href="<?php echo e(base_url('customer/logout.php')); ?>">Logout</a>
      </div>

      <div class="content">
        <div class="card">
          <h3 style="margin-top:0">Profil Pelanggan</h3>
          <p style="margin:0">
            <strong><?php echo e($customer['name'] ?? '-'); ?></strong><br>
            <?php echo e($customer['phone'] ?? '-'); ?><br>
            <?php echo e($customer['gender'] ?? '-'); ?> ·
            <?php echo e($customer['birth_date'] ?? '-'); ?>
          </p>
        </div>

        <div class="card" style="margin-top:16px">
          <h3 style="margin-top:0">Poin Loyalti</h3>
          <p style="font-size:24px;font-weight:600;margin:0"><?php echo e((string)$points); ?> poin</p>
          <p class="kpi-subtitle">Tunjukkan halaman ini di kasir untuk klaim reward.</p>
        </div>

        <div class="card" style="margin-top:16px">
          <h3 style="margin-top:0">Reward Tersedia</h3>
          <?php if (empty($rewards)): ?>
            <p style="margin:0">Belum ada reward yang disetting admin.</p>
          <?php else: ?>
            <table>
              <thead>
                <tr>
                  <th>Reward</th>
                  <th>Poin Dibutuhkan</th>
                  <th>Status</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rewards as $reward): ?>
                  <?php $required = (int)$reward['points_required']; ?>
                  <tr>
                    <td><?php echo e($reward['name']); ?></td>
                    <td><?php echo e((string)$required); ?> poin</td>
                    <td><?php echo $points >= $required ? '<span class="badge">Bisa diklaim</span>' : 'Belum cukup'; ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</body>
</html>
