<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/attendance.php';

start_secure_session();
require_admin();
ensure_employee_attendance_tables();

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-t');
$userId = (int) ($_GET['user_id'] ?? 0);
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$isExport = (($_GET['export'] ?? '') === 'csv');

$employees = db()->query("SELECT id,name,role FROM users WHERE role IN ('pegawai','pegawai_pos','pegawai_non_pos') ORDER BY name")->fetchAll();
$employeeIds = array_map(static fn($r) => (int) $r['id'], $employees);
$rows = [];

$timeToMinutes = static function (?string $time): int {
  if (empty($time) || !preg_match('/^\d{2}:\d{2}/', (string) $time)) {
    return 0;
  }
  [$h, $m] = array_map('intval', explode(':', substr((string) $time, 0, 5)));
  return ($h * 60) + $m;
};

$today = app_today_jakarta();

if ($employeeIds && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
  $dates = [];
  $d = new DateTimeImmutable($from, new DateTimeZone('Asia/Jakarta'));
  $end = new DateTimeImmutable($to, new DateTimeZone('Asia/Jakarta'));
  while ($d <= $end) {
    $dates[] = $d->format('Y-m-d');
    $d = $d->modify('+1 day');
  }

  $phEmp = implode(',', array_fill(0, count($employeeIds), '?'));
  $phDate = implode(',', array_fill(0, count($dates), '?'));

  $stmt = db()->prepare("SELECT * FROM employee_attendance WHERE user_id IN ($phEmp) AND attend_date IN ($phDate)");
  $stmt->execute(array_merge($employeeIds, $dates));
  $attMap = [];
  foreach ($stmt->fetchAll() as $r) {
    $attMap[(int) $r['user_id'] . '|' . $r['attend_date']] = $r;
  }

  $stmt = db()->prepare("SELECT * FROM employee_schedule_weekly WHERE user_id IN ($phEmp)");
  $stmt->execute($employeeIds);
  $weekly = [];
  foreach ($stmt->fetchAll() as $w) {
    $weekly[(int) $w['user_id']][(int) $w['weekday']] = $w;
  }

  $stmt = db()->prepare("SELECT * FROM employee_schedule_overrides WHERE user_id IN ($phEmp) AND schedule_date IN ($phDate)");
  $stmt->execute(array_merge($employeeIds, $dates));
  $override = [];
  foreach ($stmt->fetchAll() as $o) {
    $override[(int) $o['user_id'] . '|' . $o['schedule_date']] = $o;
  }

  foreach ($employees as $emp) {
    if ($userId > 0 && (int) $emp['id'] !== $userId) {
      continue;
    }

    foreach ($dates as $date) {
      $key = (int) $emp['id'] . '|' . $date;
      $schedule = $override[$key] ?? ($weekly[(int) $emp['id']][(int) (new DateTimeImmutable($date, new DateTimeZone('Asia/Jakarta')))->format('N')] ?? null);
      $att = $attMap[$key] ?? null;

      $isOff = (int) ($schedule['is_off'] ?? 0) === 1;
      $isUnscheduled = $schedule === null;
      $startTime = (string) ($schedule['start_time'] ?? '');
      $endTime = (string) ($schedule['end_time'] ?? '');
      $grace = max(0, (int) ($schedule['grace_minutes'] ?? 0));
      $window = max(0, (int) ($schedule['allow_checkin_before_minutes'] ?? 0));
      $otBeforeLimit = max(0, (int) ($schedule['overtime_before_minutes'] ?? 0));
      $otAfterLimit = max(0, (int) ($schedule['overtime_after_minutes'] ?? 0));

      $checkinTime = $att['checkin_time'] ?? null;
      $checkoutTime = $att['checkout_time'] ?? null;
      $earlyReason = (string) ($att['early_checkout_reason'] ?? '');
      $statusIn = 'Jadwal belum diatur';
      $statusOut = 'Jadwal belum diatur';
      $lateMinutes = 0;
      $earlyMinutes = 0;
      $otBefore = 0;
      $otAfter = 0;
      $workMinutes = 0;
      $invalidReasonFlag = '';

      if ($isOff) {
        $statusIn = 'Libur';
        $statusOut = 'Libur';
      } elseif ($isUnscheduled || $startTime === '' || $endTime === '') {
        $statusIn = 'Jadwal belum diatur';
        $statusOut = 'Jadwal belum diatur';
      } elseif (empty($checkinTime)) {
        $statusIn = 'Tidak absen';
        $statusOut = ($date < $today) ? 'Tidak absen pulang' : '-';
      } else {
        $checkinMin = $timeToMinutes(date('H:i', strtotime((string) $checkinTime)));
        $startMin = $timeToMinutes($startTime);
        $windowStart = $startMin - $window;
        $windowEnd = $startMin + $grace;

        if ($checkinMin < $windowStart) {
          if ($otBeforeLimit > 0) {
            $statusIn = 'Early Lembur';
            $otBefore = min($startMin - $checkinMin, $otBeforeLimit);
          } else {
            $statusIn = 'Invalid Window';
          }
        } elseif ($checkinMin > $windowEnd) {
          $statusIn = 'Telat';
          $lateMinutes = $checkinMin - $windowEnd;
        } else {
          $statusIn = 'Tepat';
        }

        if (empty($checkoutTime)) {
          $statusOut = ($date < $today) ? 'Tidak absen pulang' : '-';
        } else {
          $checkoutMin = $timeToMinutes(date('H:i', strtotime((string) $checkoutTime)));
          $endMin = $timeToMinutes($endTime);

          $checkinTs = strtotime((string) $checkinTime);
          $checkoutTs = strtotime((string) $checkoutTime);
          if ($checkinTs !== false && $checkoutTs !== false && $checkoutTs > $checkinTs) {
            $workMinutes = (int) floor(($checkoutTs - $checkinTs) / 60);
          }

          if ($checkoutMin < $endMin) {
            $statusOut = 'Pulang cepat';
            $earlyMinutes = $endMin - $checkoutMin;
            if ($earlyReason === '') {
              $invalidReasonFlag = 'Alasan kosong';
            }
          } else {
            $statusOut = 'Normal';
            if ($otAfterLimit > 0) {
              $otAfter = min($checkoutMin - $endMin, $otAfterLimit);
            }
          }
        }
      }

      if ($statusFilter !== '' && strtolower($statusIn) !== strtolower($statusFilter)) {
        continue;
      }

      $rows[] = [
        'date' => $date,
        'name' => $emp['name'],
        'start_time' => $startTime,
        'end_time' => $endTime,
        'grace_minutes' => $grace,
        'window_minutes' => $window,
        'status_in' => $statusIn,
        'late_minutes' => $lateMinutes,
        'early_in_minutes' => $otBefore,
        'status_out' => $statusOut,
        'early_minutes' => $earlyMinutes,
        'early_checkout_reason' => $earlyReason,
        'invalid_reason_flag' => $invalidReasonFlag,
        'overtime_before_minutes' => $otBefore,
        'overtime_after_minutes' => $otAfter,
        'work_minutes' => $workMinutes,
        'checkin_photo_path' => $att['checkin_photo_path'] ?? null,
        'checkout_photo_path' => $att['checkout_photo_path'] ?? null,
      ];
    }
  }
}

if ($isExport) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="rekap-absensi-' . $from . '-sd-' . $to . '.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Tanggal', 'Pegawai', 'Jam Masuk', 'Jam Pulang', 'Grace', 'Window Absen Datang', 'Status Masuk', 'Telat (menit)', 'Early (menit)', 'Lembur Sebelum (menit)', 'Status Pulang', 'Pulang Cepat (menit)', 'Alasan Pulang Cepat', 'Lembur Sesudah (menit)', 'Work Minutes']);
  foreach ($rows as $r) {
    fputcsv($out, [
      $r['date'],
      $r['name'],
      $r['start_time'],
      $r['end_time'],
      $r['grace_minutes'],
      $r['window_minutes'],
      $r['status_in'],
      $r['late_minutes'],
      $r['early_in_minutes'],
      $r['overtime_before_minutes'],
      $r['status_out'],
      $r['early_minutes'],
      $r['early_checkout_reason'],
      $r['overtime_after_minutes'],
      $r['work_minutes'],
    ]);
  }
  fclose($out);
  exit;
}

$customCss = setting('custom_css', '');
ob_start();
require_once __DIR__ . '/partials_sidebar.php';
$sidebarHtml = ob_get_clean();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Rekap Absensi</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body>
<div class="container">
  <?php echo $sidebarHtml; ?>
  <div class="main">
    <div class="topbar">
      <button class="btn" data-toggle-sidebar type="button">Menu</button>
      <div class="title">Rekap Absensi Pegawai</div>
    </div>

    <div class="content">
      <div class="card">
        <h3>Rekap Absensi Pegawai</h3>
        <form method="get" class="grid cols-4">
          <div class="row"><label>Dari</label><input type="date" name="from" value="<?php echo e($from); ?>"></div>
          <div class="row"><label>Sampai</label><input type="date" name="to" value="<?php echo e($to); ?>"></div>
          <div class="row">
            <label>Pegawai</label>
            <select name="user_id">
              <option value="0">Semua</option>
              <?php foreach ($employees as $u): ?>
                <option value="<?php echo e((string) $u['id']); ?>" <?php echo $userId === (int) $u['id'] ? 'selected' : ''; ?>><?php echo e($u['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row">
            <label>Status Masuk</label>
            <select name="status">
              <option value="">Semua</option>
              <option value="tepat" <?php echo $statusFilter === 'tepat' ? 'selected' : ''; ?>>Tepat</option>
              <option value="telat" <?php echo $statusFilter === 'telat' ? 'selected' : ''; ?>>Telat</option>
              <option value="early lembur" <?php echo strtolower($statusFilter) === 'early lembur' ? 'selected' : ''; ?>>Early Lembur</option>
              <option value="invalid window" <?php echo strtolower($statusFilter) === 'invalid window' ? 'selected' : ''; ?>>Invalid Window</option>
              <option value="tidak absen" <?php echo strtolower($statusFilter) === 'tidak absen' ? 'selected' : ''; ?>>Tidak absen</option>
              <option value="libur" <?php echo strtolower($statusFilter) === 'libur' ? 'selected' : ''; ?>>Libur</option>
            </select>
          </div>
          <button class="btn" type="submit">Filter</button>
          <a class="btn" href="<?php echo e(base_url('admin/attendance.php?from=' . urlencode($from) . '&to=' . urlencode($to) . '&user_id=' . (int) $userId . '&status=' . urlencode($statusFilter) . '&export=csv')); ?>">Export CSV</a>
        </form>

        <table class="table">
          <thead>
            <tr>
              <th>Tanggal</th><th>Pegawai</th><th>Jadwal Masuk</th><th>Jadwal Pulang</th><th>Grace</th><th>Window Absen Datang</th><th>Status Masuk</th><th>Telat (mnt)</th><th>Early (mnt)</th><th>Lembur Sebelum</th><th>Status Pulang</th><th>Pulang cepat(mnt)</th><th>Alasan pulang cepat</th><th>Lembur Sesudah</th><th>Work Minutes</th><th>Foto Masuk</th><th>Foto Pulang</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?php echo e($r['date']); ?></td>
              <td><?php echo e($r['name']); ?></td>
              <td><?php echo e((string) $r['start_time']); ?></td>
              <td><?php echo e((string) $r['end_time']); ?></td>
              <td><?php echo e((string) $r['grace_minutes']); ?></td>
              <td><?php echo e((string) $r['window_minutes']); ?></td>
              <td><?php echo e($r['status_in']); ?></td>
              <td><?php echo e((string) $r['late_minutes']); ?></td>
              <td><?php echo e((string) $r['early_in_minutes']); ?></td>
              <td><?php echo e((string) $r['overtime_before_minutes']); ?></td>
              <td><?php echo e((string) $r['status_out']); ?></td>
              <td><?php echo e((string) $r['early_minutes']); ?></td>
              <td>
                <?php echo e($r['early_checkout_reason']); ?>
                <?php if ($r['invalid_reason_flag'] !== ''): ?>
                  <div style="color:#ef4444;font-size:12px"><?php echo e($r['invalid_reason_flag']); ?></div>
                <?php endif; ?>
              </td>
              <td><?php echo e((string) $r['overtime_after_minutes']); ?></td>
              <td><?php echo e((string) $r['work_minutes']); ?></td>
              <td><?php if ($r['checkin_photo_path']): ?><a href="<?php echo e(attendance_photo_url($r['checkin_photo_path'])); ?>" target="_blank">Lihat</a><?php endif; ?></td>
              <td><?php if ($r['checkout_photo_path']): ?><a href="<?php echo e(attendance_photo_url($r['checkout_photo_path'])); ?>" target="_blank">Lihat</a><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
