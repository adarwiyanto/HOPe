<?php
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/attendance.php';

start_secure_session();
require_login();
ensure_employee_attendance_tables();
$me = current_user();
if (is_employee_role($me['role'] ?? '')) {
  $today = attendance_today_for_user((int)($me['id'] ?? 0));
  if (!empty($today['checkin_time']) && empty($today['checkout_time'])) {
    redirect(base_url('pos/absen.php?type=out&logout=1'));
  }
}
logout();
redirect(base_url('index.php'));
