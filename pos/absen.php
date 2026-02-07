<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/attendance.php';

start_secure_session();
require_login();
ensure_employee_roles();
ensure_employee_attendance_tables();

$me = current_user();
$role = (string)($me['role'] ?? '');
if (!is_employee_role($role)) {
  http_response_code(403);
  exit('Forbidden');
}

$type = ($_GET['type'] ?? 'in') === 'out' ? 'out' : 'in';
$today = app_today_jakarta();
$err = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $postedType = ($_POST['type'] ?? 'in') === 'out' ? 'out' : 'in';
  $type = $postedType;
  $attendDate = trim((string)($_POST['attend_date'] ?? ''));
  $attendTime = trim((string)($_POST['attend_time'] ?? ''));
  $deviceInfo = substr(trim((string)($_POST['device_info'] ?? '')), 0, 255);

  try {
    if ($attendDate !== $today) {
      throw new Exception('Tanggal absen harus hari ini.');
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $attendTime)) {
      throw new Exception('Waktu absen tidak valid.');
    }
    if (empty($_FILES['attendance_photo']['name'] ?? '')) {
      throw new Exception('Foto wajib dari kamera.');
    }

    $photo = $_FILES['attendance_photo'];
    if (($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      throw new Exception('Upload foto gagal.');
    }
    if (($photo['size'] ?? 0) <= 0 || ($photo['size'] ?? 0) > 2 * 1024 * 1024) {
      throw new Exception('Ukuran foto maksimal 2MB.');
    }

    $tmpPath = (string)($photo['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
      throw new Exception('File foto tidak valid.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmpPath) ?: '';
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
      throw new Exception('MIME foto tidak valid.');
    }

    $raw = @file_get_contents($tmpPath);
    if ($raw === false || $raw === '') {
      throw new Exception('Foto tidak valid.');
    }

    $timeFull = $attendDate . ' ' . $attendTime . ':00';
    $db = db();
    $db->beginTransaction();
    $stmt = $db->prepare("SELECT * FROM employee_attendance WHERE user_id=? AND attend_date=? LIMIT 1 FOR UPDATE");
    $stmt->execute([(int)$me['id'], $today]);
    $row = $stmt->fetch();

    if (!$row) {
      $ins = $db->prepare("INSERT INTO employee_attendance (user_id, attend_date) VALUES (?, ?)");
      $ins->execute([(int)$me['id'], $today]);
      $stmt->execute([(int)$me['id'], $today]);
      $row = $stmt->fetch();
    }

    if ($type === 'in' && !empty($row['checkin_time'])) {
      throw new Exception('Absen masuk sudah tercatat.');
    }
    if ($type === 'out' && empty($row['checkin_time'])) {
      throw new Exception('Belum ada absen masuk hari ini.');
    }
    if ($type === 'out' && !empty($row['checkout_time'])) {
      throw new Exception('Absen pulang sudah tercatat.');
    }

    $dir = attendance_upload_dir($today);
    $uniq = bin2hex(random_bytes(5));
    $ext = $mime === 'image/png' ? '.png' : '.jpg';
    $fileName = 'user_' . (int)$me['id'] . '_' . str_replace('-', '', $today) . '_' . $type . '_' . $uniq . $ext;
    $fullPath = $dir . $fileName;
    if (@file_put_contents($fullPath, $raw, LOCK_EX) === false) {
      throw new Exception('Gagal menyimpan foto.');
    }
    @chmod($fullPath, 0640);
    $stored = 'attendance/' . substr($today, 0, 4) . '/' . substr($today, 5, 2) . '/' . $fileName;

    if ($type === 'in') {
      $upd = $db->prepare("UPDATE employee_attendance SET checkin_time=?, checkin_photo_path=?, checkin_device_info=?, updated_at=NOW() WHERE id=?");
      $upd->execute([$timeFull, $stored, $deviceInfo, (int)$row['id']]);
    } else {
      $upd = $db->prepare("UPDATE employee_attendance SET checkout_time=?, checkout_photo_path=?, checkout_device_info=?, updated_at=NOW() WHERE id=?");
      $upd->execute([$timeFull, $stored, $deviceInfo, (int)$row['id']]);
    }

    $db->commit();
    if ($type === 'out' && !empty($_GET['logout'])) {
      logout();
      redirect(base_url('index.php'));
    }
    $ok = $type === 'in' ? 'Absen masuk berhasil disimpan.' : 'Absen pulang berhasil disimpan.';
  } catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
      $db->rollBack();
    }
    if (!empty($fullPath) && is_file($fullPath)) {
      @unlink($fullPath);
    }
    $err = $e->getMessage();
  }
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Absen <?php echo e(strtoupper($type)); ?></title>
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
</head>
<body>
<div class="container" style="max-width:720px;margin:20px auto">
  <div class="card">
    <h3>Absensi <?php echo $type === 'in' ? 'Masuk' : 'Pulang'; ?></h3>
    <?php if ($err): ?><div class="card" style="background:rgba(251,113,133,.12)"><?php echo e($err); ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="card" style="background:rgba(52,211,153,.12)"><?php echo e($ok); ?></div><?php endif; ?>
    <form method="post" id="absen-form" enctype="multipart/form-data">
      <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
      <input type="hidden" name="type" value="<?php echo e($type); ?>">
      <input type="hidden" name="device_info" id="device_info">
      <div class="row"><label>Tanggal</label><input name="attend_date" value="<?php echo e($today); ?>" readonly></div>
      <div class="row"><label>Waktu</label><input type="time" name="attend_time" value="<?php echo e(app_now_jakarta('H:i')); ?>" required></div>
      <div class="row">
        <label>Foto Absensi</label>
        <input type="file" name="attendance_photo" id="attendance_photo" accept="image/jpeg,image/png" capture="user" required>
        <small>Gunakan kamera HP untuk mengambil foto absensi.</small>
      </div>
      <div id="photo_preview_wrap" style="margin-top:10px;display:none">
        <img id="photo_preview" alt="Preview foto absensi" style="max-width:100%;border-radius:12px">
      </div>
      <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn" type="submit">Simpan</button>
        <a class="btn" href="<?php echo e(base_url('pos/index.php')); ?>">Kembali ke POS</a>
      </div>
    </form>
  </div>
</div>
<script>
  document.getElementById('device_info').value = navigator.userAgent || '';

  const fileInput = document.getElementById('attendance_photo');
  const previewWrap = document.getElementById('photo_preview_wrap');
  const previewImg = document.getElementById('photo_preview');

  fileInput.addEventListener('change', () => {
    const file = fileInput.files && fileInput.files[0];
    if (!file) {
      previewImg.src = '';
      previewWrap.style.display = 'none';
      return;
    }
    previewImg.src = URL.createObjectURL(file);
    previewWrap.style.display = 'block';
  });
</script>
</body>
</html>
