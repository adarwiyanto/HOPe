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
  $photoData = (string)($_POST['photo_data'] ?? '');
  $deviceInfo = substr(trim((string)($_POST['device_info'] ?? '')), 0, 255);

  try {
    if ($attendDate !== $today) {
      throw new Exception('Tanggal absen harus hari ini.');
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $attendTime)) {
      throw new Exception('Waktu absen tidak valid.');
    }
    if (!preg_match('#^data:image/(jpeg|png);base64,#i', $photoData, $m)) {
      throw new Exception('Foto wajib dari kamera.');
    }
    $raw = base64_decode(substr($photoData, strpos($photoData, ',') + 1), true);
    if ($raw === false) {
      throw new Exception('Foto tidak valid.');
    }
    if (strlen($raw) <= 0 || strlen($raw) > 2 * 1024 * 1024) {
      throw new Exception('Ukuran foto maksimal 2MB.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($raw) ?: '';
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
      throw new Exception('MIME foto tidak valid.');
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
    $fileName = 'user_' . (int)$me['id'] . '_' . str_replace('-', '', $today) . '_' . $type . '_' . $uniq . '.jpg';
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
    <form method="post" id="absen-form">
      <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
      <input type="hidden" name="type" value="<?php echo e($type); ?>">
      <input type="hidden" name="photo_data" id="photo_data" required>
      <input type="hidden" name="device_info" id="device_info">
      <div class="row"><label>Tanggal</label><input name="attend_date" value="<?php echo e($today); ?>" readonly></div>
      <div class="row"><label>Waktu</label><input type="time" name="attend_time" value="<?php echo e(app_now_jakarta('H:i')); ?>" required></div>
      <video id="camera" autoplay playsinline style="width:100%;border-radius:12px;background:#111"></video>
      <canvas id="canvas" style="display:none"></canvas>
      <div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn" type="button" id="capture">Ambil Foto</button>
        <button class="btn" type="submit">Simpan Absen</button>
        <a class="btn" href="<?php echo e(base_url('pos/index.php')); ?>">Kembali POS</a>
      </div>
      <img id="preview" alt="Preview" style="width:100%;margin-top:10px;display:none;border-radius:12px">
      <div id="cam_err" style="margin-top:10px;color:#fb7185"></div>
    </form>
  </div>
</div>
<script>
(async function() {
  const video = document.getElementById('camera');
  const canvas = document.getElementById('canvas');
  const capture = document.getElementById('capture');
  const photoInput = document.getElementById('photo_data');
  const preview = document.getElementById('preview');
  const camErr = document.getElementById('cam_err');
  document.getElementById('device_info').value = navigator.userAgent || '';

  try {
    const stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'user'}, audio: false});
    video.srcObject = stream;
  } catch (err) {
    camErr.textContent = 'Kamera tidak tersedia/ditolak. Absensi tidak dapat diproses tanpa kamera.';
    capture.disabled = true;
    return;
  }

  capture.addEventListener('click', () => {
    const w = video.videoWidth || 640;
    const h = video.videoHeight || 480;
    canvas.width = w;
    canvas.height = h;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0, w, h);
    const data = canvas.toDataURL('image/jpeg', 0.9);
    photoInput.value = data;
    preview.src = data;
    preview.style.display = 'block';
  });

  document.getElementById('absen-form').addEventListener('submit', (ev) => {
    if (!photoInput.value) {
      ev.preventDefault();
      camErr.textContent = 'Silakan ambil foto dari kamera terlebih dahulu.';
    }
  });
})();
</script>
</body>
</html>
