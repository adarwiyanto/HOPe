<?php
// Installer: membuat DB + tabel + admin + config.php, lalu lock.
$lock = __DIR__ . '/install.lock';
if (file_exists($lock)) {
  header('Location: ../login.php');
  exit;
}

function h($s){ return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $app_name = trim($_POST['app_name'] ?? '');
  $base_url = trim($_POST['base_url'] ?? '');
  $db_host = trim($_POST['db_host'] ?? '127.0.0.1');
  $db_port = trim($_POST['db_port'] ?? '3306');
  $db_name = trim($_POST['db_name'] ?? '');
  $db_user = trim($_POST['db_user'] ?? 'root');
  $db_pass = (string)($_POST['db_pass'] ?? '');

  $admin_username = trim($_POST['admin_username'] ?? 'admin');
  $admin_name = trim($_POST['admin_name'] ?? 'Administrator');
  $admin_pass1 = (string)($_POST['admin_pass1'] ?? '');
  $admin_pass2 = (string)($_POST['admin_pass2'] ?? '');

  try {
    if (!$app_name) throw new Exception('Nama aplikasi wajib diisi.');
    if (!$base_url) throw new Exception('Base URL wajib diisi (contoh: http://localhost/toko_online).');
    if (!$db_name) throw new Exception('Nama database wajib diisi.');
    if ($admin_pass1 === '' || $admin_pass1 !== $admin_pass2) throw new Exception('Password admin tidak cocok.');

    $dsn = "mysql:host={$db_host};port={$db_port};charset=utf8mb4";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$db_name}`");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        email VARCHAR(190) NULL,
        name VARCHAR(120) NOT NULL,
        role ENUM('owner','admin','user','pegawai') NOT NULL DEFAULT 'admin',
        avatar_path VARCHAR(255) NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(180) NOT NULL,
        price DECIMAL(15,2) NOT NULL DEFAULT 0,
        image_path VARCHAR(255) NULL,
        is_favorite TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
      ) ENGINE=InnoDB
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS sales (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        qty INT NOT NULL DEFAULT 1,
        price_each DECIMAL(15,2) NOT NULL DEFAULT 0,
        total DECIMAL(15,2) NOT NULL DEFAULT 0,
        sold_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
      ) ENGINE=InnoDB
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        email VARCHAR(190) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
      ) ENGINE=InnoDB
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_code VARCHAR(40) NOT NULL,
        customer_id INT NOT NULL,
        status ENUM('pending','processing','completed','cancelled') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL DEFAULT NULL,
        KEY idx_status (status),
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
      ) ENGINE=InnoDB
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS order_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        product_id INT NOT NULL,
        qty INT NOT NULL DEFAULT 1,
        price_each DECIMAL(15,2) NOT NULL DEFAULT 0,
        subtotal DECIMAL(15,2) NOT NULL DEFAULT 0,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
      ) ENGINE=InnoDB
    ");

    $pdo->exec("
      CREATE TABLE IF NOT EXISTS settings (
        `key` VARCHAR(80) PRIMARY KEY,
        `value` MEDIUMTEXT NOT NULL
      ) ENGINE=InnoDB
    ");

    $hash = password_hash($admin_pass1, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username,name,role,password_hash) VALUES (?,?, 'owner', ?)
      ON DUPLICATE KEY UPDATE name=VALUES(name), role='owner', password_hash=VALUES(password_hash)");
    $stmt->execute([$admin_username, $admin_name, $hash]);

    $stmt = $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES ('custom_css','') 
      ON DUPLICATE KEY UPDATE `value`=`value`");
    $stmt->execute();

    $stmt = $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?)
      ON DUPLICATE KEY UPDATE `value`=`value`");
    $stmt->execute(['store_name', $app_name]);
    $stmt->execute(['store_subtitle', 'Katalog produk sederhana']);
    $stmt->execute(['store_intro', 'Kami adalah usaha yang menghadirkan produk pilihan dengan kualitas terbaik untuk kebutuhan Anda.']);
    $stmt->execute(['landing_css', '']);
    $stmt->execute(['landing_html', '']);
    $stmt->execute(['recaptcha_site_key', '']);
    $stmt->execute(['recaptcha_secret_key', '']);

    // Tulis config.php
    $config = [
      'app' => ['name' => $app_name, 'base_url' => rtrim($base_url, '/')],
      'db'  => ['host'=>$db_host,'port'=>$db_port,'name'=>$db_name,'user'=>$db_user,'pass'=>$db_pass,'charset'=>'utf8mb4'],
      'security' => ['session_name' => 'TOKOSESS'],
    ];

    $configPhp = "<?php\nreturn " . var_export($config, true) . ";\n";
    $configPath = __DIR__ . '/../config.php';
    file_put_contents($configPath, $configPhp);

    // Lock installer
    file_put_contents($lock, "installed_at=" . date('c'));

    header('Location: ../login.php');
    exit;

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Installer</title>
  <style>
    body{font-family:system-ui,Segoe UI,Arial;background:#0f172a;color:#e5e7eb;margin:0;padding:24px}
    .wrap{max-width:920px;margin:0 auto}
    .card{background:#0b1224;border:1px solid rgba(148,163,184,.18);border-radius:14px;padding:16px}
    input{width:100%;padding:10px 12px;border-radius:12px;border:1px solid rgba(148,163,184,.18);background:rgba(255,255,255,.03);color:#e5e7eb}
    .grid{display:grid;gap:14px}
    .cols{grid-template-columns:1fr 1fr}
    @media(max-width:860px){.cols{grid-template-columns:1fr}}
    .btn{padding:10px 12px;border-radius:12px;border:1px solid rgba(148,163,184,.18);background:rgba(56,189,248,.18);color:#e5e7eb;cursor:pointer}
    .err{background:rgba(251,113,133,.12);border:1px solid rgba(251,113,133,.35);padding:10px;border-radius:12px;margin-bottom:12px}
    small{color:#94a3b8}
  </style>
</head>
<body>
  <div class="wrap">
    <h2>Installer Toko Online</h2>
    <div class="card">
      <?php if ($err): ?><div class="err"><?php echo h($err); ?></div><?php endif; ?>
      <form method="post">
        <div class="grid cols">
          <div>
            <label>Nama Aplikasi</label>
            <input name="app_name" value="<?php echo h($_POST['app_name'] ?? ''); ?>" placeholder="Nama toko Anda">
            <small>Contoh: Toko Adena</small>
          </div>
          <div>
            <label>Base URL</label>
            <input name="base_url" placeholder="http://localhost/toko_online" value="<?php echo h($_POST['base_url'] ?? ''); ?>">
            <small>Sesuaikan dengan nama folder di htdocs</small>
          </div>
        </div>

        <h3>Koneksi MySQL/MariaDB</h3>
        <div class="grid cols">
          <div><label>Host</label><input name="db_host" value="<?php echo h($_POST['db_host'] ?? '127.0.0.1'); ?>"></div>
          <div><label>Port</label><input name="db_port" value="<?php echo h($_POST['db_port'] ?? '3306'); ?>"></div>
          <div><label>DB Name</label><input name="db_name" value="<?php echo h($_POST['db_name'] ?? 'toko_online'); ?>"></div>
          <div><label>DB User</label><input name="db_user" value="<?php echo h($_POST['db_user'] ?? 'root'); ?>"></div>
          <div><label>DB Password</label><input type="password" name="db_pass" value="<?php echo h($_POST['db_pass'] ?? ''); ?>"></div>
        </div>

        <h3>Admin Pertama</h3>
        <div class="grid cols">
          <div><label>Nama</label><input name="admin_name" value="<?php echo h($_POST['admin_name'] ?? 'Administrator'); ?>"></div>
          <div><label>Username</label><input name="admin_username" value="<?php echo h($_POST['admin_username'] ?? 'admin'); ?>"></div>
          <div><label>Password</label><input type="password" name="admin_pass1"></div>
          <div><label>Ulangi Password</label><input type="password" name="admin_pass2"></div>
        </div>

        <div style="margin-top:14px">
          <button class="btn" type="submit">Install</button>
        </div>
        <p><small>Sesudah berhasil, installer otomatis terkunci (install/install.lock) dan Anda diarahkan ke login.</small></p>
      </form>
    </div>
  </div>
</body>
</html>
