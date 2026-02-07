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
$userId = (int)($_GET['user_id'] ?? 0);
$statusFilter = trim((string)($_GET['status'] ?? ''));

$employees = db()->query("SELECT id,name,role FROM users WHERE role IN ('pegawai','pegawai_pos','pegawai_non_pos') ORDER BY name")->fetchAll();
$employeeIds = array_map(fn($r)=>(int)$r['id'],$employees);
$rows=[];
if ($employeeIds && preg_match('/^\d{4}-\d{2}-\d{2}$/',$from) && preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)) {
  $dates=[];
  $d = new DateTimeImmutable($from, new DateTimeZone('Asia/Jakarta'));
  $end = new DateTimeImmutable($to, new DateTimeZone('Asia/Jakarta'));
  while ($d <= $end) { $dates[]=$d->format('Y-m-d'); $d=$d->modify('+1 day'); }

  $phEmp = implode(',', array_fill(0,count($employeeIds),'?'));
  $phDate = implode(',', array_fill(0,count($dates),'?'));
  $stmt = db()->prepare("SELECT * FROM employee_attendance WHERE user_id IN ($phEmp) AND attend_date IN ($phDate)");
  $stmt->execute(array_merge($employeeIds,$dates));
  $attMap=[]; foreach($stmt->fetchAll() as $r){$attMap[(int)$r['user_id'].'|'.$r['attend_date']]=$r;}

  $stmt = db()->prepare("SELECT * FROM employee_schedule_weekly WHERE user_id IN ($phEmp)");
  $stmt->execute($employeeIds);
  $weekly=[]; foreach($stmt->fetchAll() as $w){$weekly[(int)$w['user_id']][(int)$w['weekday']]=$w;}

  $stmt = db()->prepare("SELECT * FROM employee_schedule_overrides WHERE user_id IN ($phEmp) AND schedule_date IN ($phDate)");
  $stmt->execute(array_merge($employeeIds,$dates));
  $override=[]; foreach($stmt->fetchAll() as $o){$override[(int)$o['user_id'].'|'.$o['schedule_date']]=$o;}

  foreach ($employees as $emp) {
    if ($userId > 0 && (int)$emp['id'] !== $userId) continue;
    foreach ($dates as $date) {
      $key=(int)$emp['id'].'|'.$date;
      $schedule = $override[$key] ?? ($weekly[(int)$emp['id']][(int)(new DateTimeImmutable($date))->format('N')] ?? null);
      $att = $attMap[$key] ?? null;
      $row=[
        'date'=>$date,'name'=>$emp['name'],'user_id'=>$emp['id'],
        'start_time'=>$schedule['start_time'] ?? null,'end_time'=>$schedule['end_time'] ?? null,
        'grace_minutes'=>(int)($schedule['grace_minutes'] ?? 0),'is_off'=>(int)($schedule['is_off'] ?? 0),
        'checkin_time'=>$att['checkin_time'] ?? null,'checkout_time'=>$att['checkout_time'] ?? null,
        'checkin_photo_path'=>$att['checkin_photo_path'] ?? null,'checkout_photo_path'=>$att['checkout_photo_path'] ?? null,
      ];
      $row['status_in']='Jadwal belum diatur';$row['late_minutes']=0;$row['status_out']='-';$row['early_minutes']=0;$row['status']='';
      if ($row['is_off']) { $row['status']='libur'; $row['status_in']='Libur'; $row['status_out']='Libur'; }
      elseif (empty($row['checkin_time'])) { $row['status']='tidak absen'; $row['status_in']='Tidak absen'; }
      else {
        $startTs = !empty($row['start_time']) ? strtotime($date.' '.$row['start_time']) : null;
        $checkinTs = strtotime($row['checkin_time']);
        if ($startTs !== null && $checkinTs > ($startTs + ($row['grace_minutes']*60))) {
          $row['status']='telat'; $row['status_in']='Telat'; $row['late_minutes']=(int)floor(($checkinTs-$startTs)/60);
        } else { $row['status']='tepat'; $row['status_in']='Tepat waktu'; }
        if (!empty($row['checkout_time']) && !empty($row['end_time'])) {
          $endTs = strtotime($date.' '.$row['end_time']);
          $outTs = strtotime($row['checkout_time']);
          if ($outTs < $endTs) { $row['status_out']='Pulang cepat'; $row['early_minutes']=(int)floor(($endTs-$outTs)/60); }
          else { $row['status_out']='Normal'; }
        }
      }
      if ($statusFilter !== '' && $row['status'] !== $statusFilter) continue;
      $rows[]=$row;
    }
  }
}
?>
<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"></head><body>
<div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="content"><div class="card"><h3>Rekap Absensi Pegawai</h3>
<form method="get" class="grid cols-4"><div class="row"><label>Dari</label><input type="date" name="from" value="<?php echo e($from);?>"></div><div class="row"><label>Sampai</label><input type="date" name="to" value="<?php echo e($to);?>"></div><div class="row"><label>Pegawai</label><select name="user_id"><option value="0">Semua</option><?php foreach($employees as $u):?><option value="<?php echo e((string)$u['id']);?>" <?php echo $userId===(int)$u['id']?'selected':'';?>><?php echo e($u['name']);?></option><?php endforeach;?></select></div><div class="row"><label>Status</label><select name="status"><option value="">Semua</option><option value="tepat" <?php echo $statusFilter==='tepat'?'selected':'';?>>Tepat</option><option value="telat" <?php echo $statusFilter==='telat'?'selected':'';?>>Telat</option><option value="tidak absen" <?php echo $statusFilter==='tidak absen'?'selected':'';?>>Tidak absen</option><option value="libur" <?php echo $statusFilter==='libur'?'selected':'';?>>Libur</option></select></div><button class="btn" type="submit">Filter</button></form>
<table class="table"><thead><tr><th>Tanggal</th><th>Pegawai</th><th>Jadwal Masuk</th><th>Jadwal Pulang</th><th>Grace</th><th>Status Masuk</th><th>Telat(mnt)</th><th>Status Pulang</th><th>Pulang cepat(mnt)</th><th>Foto Masuk</th><th>Foto Pulang</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?php echo e($r['date']);?></td><td><?php echo e($r['name']);?></td><td><?php echo e((string)$r['start_time']);?></td><td><?php echo e((string)$r['end_time']);?></td><td><?php echo e((string)$r['grace_minutes']);?></td><td><?php echo e($r['status_in']);?></td><td><?php echo e((string)$r['late_minutes']);?></td><td><?php echo e((string)$r['status_out']);?></td><td><?php echo e((string)$r['early_minutes']);?></td><td><?php if($r['checkin_photo_path']):?><a href="<?php echo e(attendance_photo_url($r['checkin_photo_path']));?>" target="_blank">Lihat</a><?php endif;?></td><td><?php if($r['checkout_photo_path']):?><a href="<?php echo e(attendance_photo_url($r['checkout_photo_path']));?>" target="_blank">Lihat</a><?php endif;?></td></tr><?php endforeach;?>
</tbody></table></div></div></div></div></body></html>
