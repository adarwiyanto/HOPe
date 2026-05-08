<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
require_admin();
ensure_store_theme_backgrounds_table();

$customCss = setting('custom_css', '');
$landingCss = setting('landing_css', '');
$landingHtml = setting('landing_html', '');
$defaultLandingHtml = landing_default_html();

$notice = '';
$err = '';

$sanitizeFilename = static function (string $name): string {
  $name = strtolower(trim($name));
  $name = preg_replace('/[^a-z0-9\-_\.]+/', '-', $name) ?: 'background';
  return trim($name, '-_.') ?: 'background';
};

$resolveUploadDir = static function (string $type): array {
  $sub = $type === 'video' ? 'uploads/theme/backgrounds/videos' : 'uploads/theme/backgrounds/images';
  $abs = dirname(__DIR__) . '/' . $sub;
  if (!is_dir($abs)) {
    @mkdir($abs, 0755, true);
  }
  return [$sub, $abs];
};

$deleteBackgroundFile = static function (string $path): void {
  $path = trim($path);
  if ($path === '') return;
  if (preg_match('~^https?://~i', $path)) return;
  $relative = ltrim(str_replace('\\', '/', $path), '/');
  if (!str_starts_with($relative, 'uploads/theme/backgrounds/')) return;
  $abs = dirname(__DIR__) . '/' . $relative;
  if (is_file($abs)) {
    @unlink($abs);
  }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? 'save_theme');

  try {
    if ($action === 'save_theme') {
      $css = (string)($_POST['custom_css'] ?? '');
      $landingCssInput = (string)($_POST['landing_css'] ?? '');
      $landingHtmlInput = (string)($_POST['landing_html'] ?? '');
      set_setting('custom_css', $css);
      set_setting('landing_css', $landingCssInput);
      set_setting('landing_html', $landingHtmlInput);
      $notice = 'Pengaturan tema berhasil disimpan.';
    }

    if ($action === 'upload_background') {
      $title = trim((string)($_POST['title'] ?? ''));
      $targetPage = (string)($_POST['target_page'] ?? 'both');
      $sortOrder = (int)($_POST['sort_order'] ?? 0);
      $fileType = (string)($_POST['file_type'] ?? 'image');
      $enabled = isset($_POST['is_enabled']) ? 1 : 0;

      if ($title === '') {
        throw new Exception('Judul background wajib diisi.');
      }
      if (!in_array($targetPage, ['landing', 'login', 'both'], true)) {
        throw new Exception('Target halaman tidak valid.');
      }
      if (!in_array($fileType, ['image', 'video'], true)) {
        throw new Exception('Tipe background tidak valid.');
      }
      if (empty($_FILES['background_file'])) {
        throw new Exception('File background wajib diunggah.');
      }

      $file = $_FILES['background_file'];
      if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new Exception('Upload file gagal.');
      }

      $maxSize = $fileType === 'video' ? (20 * 1024 * 1024) : (5 * 1024 * 1024);
      $allowedExt = $fileType === 'video' ? ['mp4', 'webm'] : ['jpg', 'jpeg', 'png', 'webp'];
      $allowedMime = $fileType === 'video'
        ? ['video/mp4', 'video/webm']
        : ['image/jpeg', 'image/png', 'image/webp'];

      $size = (int)($file['size'] ?? 0);
      if ($size <= 0 || $size > $maxSize) {
        throw new Exception('Ukuran file tidak valid. Maksimal ' . ($fileType === 'video' ? '20MB' : '5MB') . '.');
      }

      $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
      if (!in_array($ext, $allowedExt, true)) {
        throw new Exception('Format file tidak diizinkan.');
      }

      $tmp = (string)($file['tmp_name'] ?? '');
      if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new Exception('File upload tidak valid.');
      }

      $finfo = new finfo(FILEINFO_MIME_TYPE);
      $mime = (string)$finfo->file($tmp);
      if (!in_array($mime, $allowedMime, true)) {
        throw new Exception('MIME file tidak valid.');
      }

      [$dirRelative, $dirAbsolute] = $resolveUploadDir($fileType);
      $baseName = $sanitizeFilename(pathinfo((string)$file['name'], PATHINFO_FILENAME));
      $newName = $baseName . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
      $destAbs = rtrim($dirAbsolute, '/\\') . DIRECTORY_SEPARATOR . $newName;
      $destRelative = trim($dirRelative, '/\\') . '/' . $newName;

      if (!move_uploaded_file($tmp, $destAbs)) {
        throw new Exception('Gagal menyimpan file upload.');
      }

      $stmt = db()->prepare("INSERT INTO store_theme_backgrounds (title, file_type, file_path, target_page, is_enabled, sort_order) VALUES (?,?,?,?,?,?)");
      $stmt->execute([$title, $fileType, $destRelative, $targetPage, $enabled, $sortOrder]);
      $notice = 'Background baru berhasil diunggah.';
    }

    if ($action === 'set_active') {
      $page = (string)($_POST['page'] ?? 'landing');
      $backgroundId = (int)($_POST['background_id'] ?? 0);
      if (!in_array($page, ['landing', 'login'], true)) {
        throw new Exception('Halaman tujuan tidak valid.');
      }
      if ($backgroundId <= 0) {
        throw new Exception('Background aktif tidak valid.');
      }

      $stmt = db()->prepare("SELECT * FROM store_theme_backgrounds WHERE id = ? LIMIT 1");
      $stmt->execute([$backgroundId]);
      $bg = $stmt->fetch();
      if (!$bg) {
        throw new Exception('Background tidak ditemukan.');
      }
      if ((int)($bg['is_enabled'] ?? 0) !== 1) {
        throw new Exception('Background nonaktif tidak bisa dipilih.');
      }
      $target = (string)($bg['target_page'] ?? 'both');
      if (!in_array($target, [$page, 'both'], true)) {
        throw new Exception('Background tidak cocok untuk halaman ini.');
      }

      set_setting('theme_background_active_' . $page, (string)$backgroundId);
      $notice = 'Background aktif untuk halaman ' . $page . ' berhasil diubah.';
    }

    if ($action === 'clear_active') {
      $page = (string)($_POST['page'] ?? 'landing');
      if (!in_array($page, ['landing', 'login'], true)) {
        throw new Exception('Halaman tujuan tidak valid.');
      }
      set_setting('theme_background_active_' . $page, '0');
      $notice = 'Background aktif untuk halaman ' . $page . ' direset ke fallback default.';
    }

    if ($action === 'toggle_enabled') {
      $backgroundId = (int)($_POST['background_id'] ?? 0);
      if ($backgroundId <= 0) {
        throw new Exception('Background tidak valid.');
      }
      $stmt = db()->prepare("UPDATE store_theme_backgrounds SET is_enabled = IF(is_enabled=1,0,1) WHERE id = ?");
      $stmt->execute([$backgroundId]);
      $notice = 'Status background berhasil diperbarui.';
    }

    if ($action === 'delete_background') {
      $backgroundId = (int)($_POST['background_id'] ?? 0);
      if ($backgroundId <= 0) {
        throw new Exception('Background tidak valid.');
      }

      $stmt = db()->prepare("SELECT file_path FROM store_theme_backgrounds WHERE id = ? LIMIT 1");
      $stmt->execute([$backgroundId]);
      $bg = $stmt->fetch();
      if (!$bg) {
        throw new Exception('Background tidak ditemukan.');
      }

      db()->prepare("DELETE FROM store_theme_backgrounds WHERE id = ?")->execute([$backgroundId]);
      db()->prepare("UPDATE settings SET value='0' WHERE `key` IN ('theme_background_active_landing','theme_background_active_login') AND value = ?")
        ->execute([(string)$backgroundId]);
      $deleteBackgroundFile((string)($bg['file_path'] ?? ''));
      $notice = 'Background berhasil dihapus.';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$activeLandingId = (int)setting('theme_background_active_landing', '0');
$activeLoginId = (int)setting('theme_background_active_login', '0');

$backgroundRows = db()->query("SELECT * FROM store_theme_backgrounds ORDER BY sort_order ASC, id DESC")->fetchAll();

$eligibleLanding = array_values(array_filter($backgroundRows, static function (array $row): bool {
  return (int)($row['is_enabled'] ?? 0) === 1 && in_array(($row['target_page'] ?? 'both'), ['landing', 'both'], true);
}));
$eligibleLogin = array_values(array_filter($backgroundRows, static function (array $row): bool {
  return (int)($row['is_enabled'] ?? 0) === 1 && in_array(($row['target_page'] ?? 'both'), ['login', 'both'], true);
}));
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Tema Toko</title>
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
      <div class="badge">Tema Toko</div>
    </div>

    <div class="content">
      <?php if ($notice): ?><div class="card" style="margin-bottom:12px;border-color:#86efac;background:rgba(134,239,172,.12)"><?php echo e($notice); ?></div><?php endif; ?>
      <?php if ($err): ?><div class="card" style="margin-bottom:12px;border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div><?php endif; ?>

      <div class="card" style="margin-bottom:16px">
        <h3 style="margin-top:0">Upload Background Baru</h3>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="upload_background">
          <div class="grid cols-2">
            <div class="row"><label>Judul</label><input name="title" required></div>
            <div class="row">
              <label>Tipe File</label>
              <select name="file_type" required>
                <option value="image">Image (jpg/jpeg/png/webp)</option>
                <option value="video">Video (mp4/webm)</option>
              </select>
            </div>
            <div class="row">
              <label>Target Halaman</label>
              <select name="target_page" required>
                <option value="both">Landing + Login</option>
                <option value="landing">Landing saja</option>
                <option value="login">Login saja</option>
              </select>
            </div>
            <div class="row"><label>Urutan</label><input type="number" name="sort_order" value="0"></div>
          </div>
          <label class="checkbox-row" style="margin:8px 0 12px"><input type="checkbox" name="is_enabled" value="1" checked> Aktifkan media setelah upload</label>
          <div class="row"><label>File</label><input type="file" name="background_file" accept=".jpg,.jpeg,.png,.webp,.mp4,.webm" required></div>
          <p><small>Batas ukuran: image maksimal 5MB, video maksimal 20MB.</small></p>
          <button class="btn" type="submit">Upload Background</button>
        </form>
      </div>

      <div class="grid cols-2" style="margin-bottom:16px">
        <div class="card">
          <h3 style="margin-top:0">Background Landing Page</h3>
          <p><small>Aktif saat ini: <?php echo $activeLandingId > 0 ? '#'.e((string)$activeLandingId) : 'Fallback default lokal'; ?></small></p>
          <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="set_active">
            <input type="hidden" name="page" value="landing">
            <div style="flex:1;min-width:220px">
              <label>Pilih Background Aktif</label>
              <select name="background_id" required>
                <?php foreach ($eligibleLanding as $row): ?>
                  <option value="<?php echo e((string)$row['id']); ?>" <?php echo (int)$row['id'] === $activeLandingId ? 'selected' : ''; ?>>#<?php echo e((string)$row['id']); ?> - <?php echo e($row['title']); ?> (<?php echo e($row['file_type']); ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn" type="submit" <?php echo empty($eligibleLanding) ? 'disabled' : ''; ?>>Set Aktif</button>
          </form>
          <form method="post" style="margin-top:10px">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="clear_active">
            <input type="hidden" name="page" value="landing">
            <button class="btn btn-light" type="submit">Reset ke Default</button>
          </form>
        </div>

        <div class="card">
          <h3 style="margin-top:0">Background Login Page</h3>
          <p><small>Aktif saat ini: <?php echo $activeLoginId > 0 ? '#'.e((string)$activeLoginId) : 'Fallback default lokal'; ?></small></p>
          <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="set_active">
            <input type="hidden" name="page" value="login">
            <div style="flex:1;min-width:220px">
              <label>Pilih Background Aktif</label>
              <select name="background_id" required>
                <?php foreach ($eligibleLogin as $row): ?>
                  <option value="<?php echo e((string)$row['id']); ?>" <?php echo (int)$row['id'] === $activeLoginId ? 'selected' : ''; ?>>#<?php echo e((string)$row['id']); ?> - <?php echo e($row['title']); ?> (<?php echo e($row['file_type']); ?>)</option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn" type="submit" <?php echo empty($eligibleLogin) ? 'disabled' : ''; ?>>Set Aktif</button>
          </form>
          <form method="post" style="margin-top:10px">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="clear_active">
            <input type="hidden" name="page" value="login">
            <button class="btn btn-light" type="submit">Reset ke Default</button>
          </form>
        </div>
      </div>

      <div class="card">
        <h3 style="margin-top:0">Daftar Media Background</h3>
        <p><small>Fallback lokal digunakan jika tidak ada media aktif/valid: <code>assets/images/landing-bg.svg</code>.</small></p>
        <div class="table" role="table">
          <table class="table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Media</th>
                <th>Info</th>
                <th>Status</th>
                <th>Aksi</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($backgroundRows)): ?>
              <tr><td colspan="5"><small>Belum ada media background diupload.</small></td></tr>
            <?php else: ?>
              <?php foreach ($backgroundRows as $row): ?>
                <?php
                  $isImage = ($row['file_type'] ?? '') === 'image';
                  $path = (string)($row['file_path'] ?? '');
                  $previewUrl = asset_url($path);
                ?>
                <tr>
                  <td>#<?php echo e((string)$row['id']); ?></td>
                  <td>
                    <?php if ($isImage): ?>
                      <img class="thumb" src="<?php echo e($previewUrl); ?>" alt="<?php echo e($row['title']); ?>">
                    <?php else: ?>
                      <span class="badge">VIDEO</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <strong><?php echo e($row['title']); ?></strong>
                    <div><small><?php echo e($row['file_type']); ?> • target: <?php echo e($row['target_page']); ?> • sort: <?php echo e((string)$row['sort_order']); ?></small></div>
                    <div><small><?php echo e($path); ?></small></div>
                  </td>
                  <td>
                    <span class="badge"><?php echo (int)$row['is_enabled'] === 1 ? 'Aktif' : 'Nonaktif'; ?></span>
                  </td>
                  <td>
                    <form method="post" style="display:inline-flex;gap:6px;flex-wrap:wrap">
                      <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                      <input type="hidden" name="background_id" value="<?php echo e((string)$row['id']); ?>">
                      <button class="btn btn-light" type="submit" name="action" value="toggle_enabled"><?php echo (int)$row['is_enabled'] === 1 ? 'Nonaktifkan' : 'Aktifkan'; ?></button>
                      <button class="btn danger" type="submit" name="action" value="delete_background" onclick="return confirm('Hapus background ini?')">Hapus</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card" style="margin-top:16px">
        <h3 style="margin-top:0">Editor CSS & HTML Landing</h3>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="save_theme">
          <h4 style="margin:6px 0">CSS Global</h4>
          <textarea name="custom_css" rows="12" style="font-family:ui-monospace,Consolas,monospace"><?php echo e($customCss); ?></textarea>
          <h4 style="margin:12px 0 6px">CSS Landing</h4>
          <textarea name="landing_css" rows="10" style="font-family:ui-monospace,Consolas,monospace"><?php echo e($landingCss); ?></textarea>
          <h4 style="margin:12px 0 6px">HTML Landing</h4>
          <textarea name="landing_html" rows="16" style="font-family:ui-monospace,Consolas,monospace"><?php echo e($landingHtml !== '' ? $landingHtml : $defaultLandingHtml); ?></textarea>
          <div style="margin-top:10px"><button class="btn" type="submit">Simpan Tema</button></div>
        </form>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
