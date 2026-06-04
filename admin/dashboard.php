<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/permissions.php';

date_default_timezone_set('Asia/Jakarta');

start_secure_session();
require_login();
require_menu_access('dashboard');

$appName = app_config()['app']['name'];
$storeName = setting('store_name', $appName);
$storeLogo = setting('store_logo', '');
$customCss = setting('custom_css', '');
$u = current_user();
$role = normalize_role_key((string)($u['role_key'] ?? $u['role'] ?? ''));

$range = $_GET['range'] ?? 'today';
$rangeStart = null;
$rangeEnd = null;
$rangeLabel = '';

$today = new DateTimeImmutable('today');
switch ($range) {
  case 'yesterday':
    $rangeStart = $today->modify('-1 day');
    $rangeEnd = $today;
    $rangeLabel = 'Kemarin';
    break;
  case 'last7':
    $rangeStart = $today->modify('-6 days');
    $rangeEnd = $today->modify('+1 day');
    $rangeLabel = '7 Hari Terakhir';
    break;
  case 'this_month':
    $rangeStart = $today->modify('first day of this month');
    $rangeEnd = $rangeStart->modify('+1 month');
    $rangeLabel = 'Bulan Ini';
    break;
  case 'last_month':
    $rangeStart = $today->modify('first day of last month');
    $rangeEnd = $rangeStart->modify('+1 month');
    $rangeLabel = 'Bulan Lalu';
    break;
  case 'custom':
    $startInput = $_GET['start'] ?? '';
    $endInput = $_GET['end'] ?? '';
    if ($startInput && $endInput) {
      $rangeStart = new DateTimeImmutable($startInput);
      $rangeEnd = (new DateTimeImmutable($endInput))->modify('+1 day');
      $rangeLabel = 'Custom';
    } else {
      $rangeStart = $today;
      $rangeEnd = $today->modify('+1 day');
      $rangeLabel = 'Hari Ini';
      $range = 'today';
    }
    break;
  case 'today':
  default:
    $rangeStart = $today;
    $rangeEnd = $today->modify('+1 day');
    $rangeLabel = 'Hari Ini';
    $range = 'today';
    break;
}

$rangeStartStr = $rangeStart->format('Y-m-d H:i:s');
$rangeEndStr = $rangeEnd->format('Y-m-d H:i:s');

$stats = [
  'products' => (int)db()->query("SELECT COUNT(*) c FROM products")->fetch()['c'],
  'sales' => 0,
  'revenue' => 0.0,
  'returns' => 0,
  'avg_transaction' => 0.0,
];

$stmt = db()->prepare("
  SELECT COUNT(*) c, COALESCE(SUM(total),0) s
  FROM sales
  WHERE is_active_revision=1 AND sold_at >= ? AND sold_at < ? AND return_reason IS NULL
");
$stmt->execute([$rangeStartStr, $rangeEndStr]);
$statsRange = $stmt->fetch();
$stats['sales'] = (int)($statsRange['c'] ?? 0);
$stats['revenue'] = (float)($statsRange['s'] ?? 0);

$stmt = db()->prepare("
  SELECT COUNT(DISTINCT COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id))) c,
         COALESCE(SUM(total),0) s
  FROM sales
  WHERE is_active_revision=1 AND sold_at >= ? AND sold_at < ? AND return_reason IS NULL
");
$stmt->execute([$rangeStartStr, $rangeEndStr]);
$avgRow = $stmt->fetch();
$txCount = (int)($avgRow['c'] ?? 0);
$stats['avg_transaction'] = $txCount > 0 ? ((float)$avgRow['s'] / $txCount) : 0.0;

$stmt = db()->prepare("
  SELECT COUNT(*) c
  FROM sales
  WHERE is_active_revision=1 AND COALESCE(returned_at, sold_at) >= ?
    AND COALESCE(returned_at, sold_at) < ?
    AND return_reason IS NOT NULL
");
$stmt->execute([$rangeStartStr, $rangeEndStr]);
$stats['returns'] = (int)($stmt->fetch()['c'] ?? 0);

$stmt = db()->prepare("
  SELECT payment_method, COUNT(*) c, COALESCE(SUM(total),0) s
  FROM sales
  WHERE is_active_revision=1 AND sold_at >= ? AND sold_at < ? AND return_reason IS NULL
  GROUP BY payment_method
  ORDER BY s DESC
");
$stmt->execute([$rangeStartStr, $rangeEndStr]);
$paymentBreakdown = $stmt->fetchAll();

$stmt = db()->prepare("
  SELECT s.*, p.name product_name
  FROM sales s
  JOIN products p ON p.id = s.product_id
  WHERE s.is_active_revision=1
  ORDER BY s.sold_at DESC
  LIMIT 10
");
$stmt->execute();
$recentActivity = $stmt->fetchAll();

$adminStats = [];
$superStats = [];
$trendRows = [];
$topProducts = [];
$deadStock = [];
$sharePaymentsMonth = [];
$recentReturns = [];
$customerSummary = [
  'total' => 0,
  'complete' => 0,
  'incomplete' => 0,
  'gender' => [],
  'age' => [],
];
$customerSegmentFavorites = [];
$selectedSegmentProducts = [];
$visitDailyRows = [];
$visitDailyMax = 0;

$genderLabels = [
  'male' => 'Laki-laki',
  'female' => 'Perempuan',
  'other' => 'Lainnya',
  '' => 'Belum diisi',
];
$ageBandLabels = [
  'lt17' => '< 17 tahun',
  '17_24' => '17–24 tahun',
  '25_34' => '25–34 tahun',
  '35_44' => '35–44 tahun',
  '45_54' => '45–54 tahun',
  '55_plus' => '≥ 55 tahun',
  'unknown' => 'Usia belum diisi',
];
$weekdayLabels = [
  1 => 'Minggu',
  2 => 'Senin',
  3 => 'Selasa',
  4 => 'Rabu',
  5 => 'Kamis',
  6 => 'Jumat',
  7 => 'Sabtu',
];
$segmentGender = $_GET['segment_gender'] ?? 'all';
if (!in_array($segmentGender, ['all', 'male', 'female', 'other'], true)) {
  $segmentGender = 'all';
}
$segmentAge = $_GET['segment_age'] ?? 'all';
if (!in_array($segmentAge, array_merge(['all'], array_keys($ageBandLabels)), true)) {
  $segmentAge = 'all';
}
$peakDay = $_GET['peak_day'] ?? 'all';
if ($peakDay !== 'all' && (!ctype_digit((string)$peakDay) || (int)$peakDay < 1 || (int)$peakDay > 7)) {
  $peakDay = 'all';
}

$todayStart = $today;
$todayEnd = $today->modify('+1 day');
$todayStartStr = $todayStart->format('Y-m-d H:i:s');
$todayEndStr = $todayEnd->format('Y-m-d H:i:s');

$peakRange = $_GET['peak_range'] ?? 'all_time';
$peakStartInput = $_GET['peak_start'] ?? '';
$peakEndInput = $_GET['peak_end'] ?? '';
$peakStart = null;
$peakEnd = null;
$peakLabel = '';

switch ($peakRange) {
  case 'this_week':
    $peakStart = $today->modify('monday this week');
    $peakEnd = $peakStart->modify('+1 week');
    $peakLabel = 'Minggu Ini';
    break;
  case 'this_month':
    $peakStart = $today->modify('first day of this month');
    $peakEnd = $peakStart->modify('+1 month');
    $peakLabel = 'Bulan Ini';
    break;
  case 'custom':
    $parsedStart = DateTimeImmutable::createFromFormat('Y-m-d', $peakStartInput);
    $parsedEnd = DateTimeImmutable::createFromFormat('Y-m-d', $peakEndInput);
    if ($parsedStart && $parsedEnd) {
      if ($parsedStart > $parsedEnd) {
        $tmp = $parsedStart;
        $parsedStart = $parsedEnd;
        $parsedEnd = $tmp;
      }
      $peakStart = $parsedStart;
      $peakEnd = $parsedEnd->modify('+1 day');
      $peakLabel = 'Custom';
    } else {
      $peakRange = 'all_time';
      $peakLabel = 'Semua Waktu';
    }
    break;
  case 'all_time':
  default:
    $peakRange = 'all_time';
    $peakLabel = 'Semua Waktu';
    break;
}

$peakDays = 1;
$peakParams = [];
$peakWhere = '';
if ($peakRange === 'all_time') {
  $row = db()->query("SELECT MIN(sold_at) AS min_date, MAX(sold_at) AS max_date FROM sales WHERE return_reason IS NULL")->fetch();
  if (!empty($row['min_date']) && !empty($row['max_date'])) {
    $peakStart = new DateTimeImmutable($row['min_date']);
    $peakEnd = (new DateTimeImmutable($row['max_date']))->modify('+1 day');
  }
}
if ($peakStart && $peakEnd) {
  $peakWhere = "AND sold_at >= ? AND sold_at < ?";
  $peakParams[] = $peakStart->format('Y-m-d H:i:s');
  $peakParams[] = $peakEnd->format('Y-m-d H:i:s');
  $peakDays = max(1, (int)$peakEnd->diff($peakStart)->days);
}

$peakDayOccurrencesMap = ['all' => $peakDays];
foreach ([1, 2, 3, 4, 5, 6, 7] as $dayNo) {
  $occurrences = 0;
  if ($peakStart && $peakEnd) {
    for ($d = $peakStart; $d < $peakEnd; $d = $d->modify('+1 day')) {
      if ((int)$d->format('w') + 1 === $dayNo) {
        $occurrences++;
      }
    }
  }
  $peakDayOccurrencesMap[(string)$dayNo] = max(1, $occurrences);
}
$peakDayOccurrences = $peakDay === 'all' ? $peakDays : $peakDayOccurrencesMap[(string)$peakDay];

$hourlyCountsByDay = ['all' => array_fill(0, 24, 0)];
foreach ([1, 2, 3, 4, 5, 6, 7] as $dayNo) {
  $hourlyCountsByDay[(string)$dayNo] = array_fill(0, 24, 0);
}
$stmt = db()->prepare("
  SELECT DAYOFWEEK(tx_time) weekday_no, HOUR(tx_time) h, COUNT(*) c
  FROM (
    SELECT COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id)) AS tx_code,
           MIN(sold_at) AS tx_time
    FROM sales
    WHERE return_reason IS NULL
      AND is_active_revision=1
      {$peakWhere}
    GROUP BY COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id))
  ) t
  GROUP BY DAYOFWEEK(tx_time), HOUR(tx_time)
  ORDER BY weekday_no ASC, h ASC
");
$stmt->execute($peakParams);
foreach ($stmt->fetchAll() as $row) {
  $weekdayNo = (int)($row['weekday_no'] ?? 0);
  $hour = (int)($row['h'] ?? 0);
  $count = (int)($row['c'] ?? 0);
  if ($weekdayNo >= 1 && $weekdayNo <= 7 && $hour >= 0 && $hour <= 23) {
    $hourlyCountsByDay['all'][$hour] += $count;
    $hourlyCountsByDay[(string)$weekdayNo][$hour] += $count;
  }
}

$hourlyAveragesByDay = [];
$maxHourlyByDay = [];
foreach ($hourlyCountsByDay as $dayKey => $counts) {
  $divisor = $dayKey === 'all' ? $peakDays : ($peakDayOccurrencesMap[$dayKey] ?? 1);
  $hourlyAveragesByDay[$dayKey] = [];
  $maxHourlyByDay[$dayKey] = 0.0;
  foreach ($counts as $hour => $count) {
    $avg = $divisor > 0 ? $count / $divisor : 0;
    $hourlyAveragesByDay[$dayKey][$hour] = $avg;
    if ($avg > $maxHourlyByDay[$dayKey]) {
      $maxHourlyByDay[$dayKey] = $avg;
    }
  }
}

$selectedPeakDayKey = $peakDay === 'all' ? 'all' : (string)$peakDay;
$hourlyAverages = $hourlyAveragesByDay[$selectedPeakDayKey] ?? $hourlyAveragesByDay['all'];
$maxHourly = $maxHourlyByDay[$selectedPeakDayKey] ?? 0.0;

$hourlyChartData = [];
foreach ($hourlyAveragesByDay as $dayKey => $averages) {
  $chartMax = $maxHourlyByDay[$dayKey] ?? 0.0;
  $rows = [];
  foreach ($averages as $hour => $avg) {
    $height = $chartMax > 0 ? ($avg / $chartMax) * 120 : 0;
    $rows[(string)$hour] = [
      'hour' => (int)$hour,
      'label' => str_pad((string)$hour, 2, '0', STR_PAD_LEFT) . ':00',
      'value' => format_number_id($avg),
      'height' => number_format($height, 2, '.', ''),
    ];
  }
  $meta = $dayKey === 'all'
    ? $peakLabel . ' · ' . $peakDays . ' hari'
    : $peakLabel . ' · ' . ($peakDayOccurrencesMap[$dayKey] ?? 1) . 'x ' . ($weekdayLabels[(int)$dayKey] ?? 'hari');
  $hourlyChartData[$dayKey] = [
    'meta' => $meta,
    'rows' => $rows,
  ];
}

$dailyVisitMap = array_fill(1, 7, 0);
$stmt = db()->prepare("
  SELECT DAYOFWEEK(tx_time) weekday_no, COUNT(*) c
  FROM (
    SELECT COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id)) AS tx_code,
           MIN(sold_at) AS tx_time
    FROM sales
    WHERE return_reason IS NULL
      AND is_active_revision=1
      AND sold_at >= ? AND sold_at < ?
    GROUP BY COALESCE(NULLIF(transaction_code, ''), CONCAT('LEGACY-', id))
  ) t
  GROUP BY DAYOFWEEK(tx_time)
");
$stmt->execute([$rangeStartStr, $rangeEndStr]);
foreach ($stmt->fetchAll() as $row) {
  $weekdayNo = (int)($row['weekday_no'] ?? 0);
  if ($weekdayNo >= 1 && $weekdayNo <= 7) {
    $dailyVisitMap[$weekdayNo] = (int)$row['c'];
  }
}
foreach ([2, 3, 4, 5, 6, 7, 1] as $weekdayNo) {
  $count = $dailyVisitMap[$weekdayNo] ?? 0;
  $visitDailyRows[] = ['weekday_no' => $weekdayNo, 'label' => $weekdayLabels[$weekdayNo], 'count' => $count];
  if ($count > $visitDailyMax) {
    $visitDailyMax = $count;
  }
}

$row = db()->query("
  SELECT COUNT(*) total,
         SUM(CASE WHEN gender IS NOT NULL AND gender <> '' AND birth_date IS NOT NULL THEN 1 ELSE 0 END) complete_count,
         SUM(CASE WHEN gender IS NULL OR gender = '' OR birth_date IS NULL THEN 1 ELSE 0 END) incomplete_count
  FROM customers
")->fetch();
$customerSummary['total'] = (int)($row['total'] ?? 0);
$customerSummary['complete'] = (int)($row['complete_count'] ?? 0);
$customerSummary['incomplete'] = (int)($row['incomplete_count'] ?? 0);

$stmt = db()->query("
  SELECT COALESCE(NULLIF(gender, ''), '') gender_key, COUNT(*) c
  FROM customers
  GROUP BY COALESCE(NULLIF(gender, ''), '')
");
foreach ($stmt->fetchAll() as $row) {
  $customerSummary['gender'][(string)$row['gender_key']] = (int)$row['c'];
}

$stmt = db()->query("
  SELECT
    CASE
      WHEN birth_date IS NULL THEN 'unknown'
      WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) < 17 THEN 'lt17'
      WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) BETWEEN 17 AND 24 THEN '17_24'
      WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) BETWEEN 25 AND 34 THEN '25_34'
      WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) BETWEEN 35 AND 44 THEN '35_44'
      WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) BETWEEN 45 AND 54 THEN '45_54'
      ELSE '55_plus'
    END age_band,
    COUNT(*) c
  FROM customers
  GROUP BY age_band
");
foreach ($stmt->fetchAll() as $row) {
  $customerSummary['age'][(string)$row['age_band']] = (int)$row['c'];
}

$segmentRows = [];
$stmt = db()->prepare("
  SELECT c.gender,
         CASE
           WHEN c.birth_date IS NULL THEN 'unknown'
           WHEN TIMESTAMPDIFF(YEAR, c.birth_date, CURDATE()) < 17 THEN 'lt17'
           WHEN TIMESTAMPDIFF(YEAR, c.birth_date, CURDATE()) BETWEEN 17 AND 24 THEN '17_24'
           WHEN TIMESTAMPDIFF(YEAR, c.birth_date, CURDATE()) BETWEEN 25 AND 34 THEN '25_34'
           WHEN TIMESTAMPDIFF(YEAR, c.birth_date, CURDATE()) BETWEEN 35 AND 44 THEN '35_44'
           WHEN TIMESTAMPDIFF(YEAR, c.birth_date, CURDATE()) BETWEEN 45 AND 54 THEN '45_54'
           ELSE '55_plus'
         END age_band,
         p.name product_name,
         SUM(s.qty) qty,
         COUNT(DISTINCT COALESCE(NULLIF(s.transaction_code, ''), CONCAT('LEGACY-', s.id))) tx_count
  FROM sales s
  JOIN customers c ON c.id = s.customer_id
  JOIN products p ON p.id = s.product_id
  WHERE s.is_active_revision=1
    AND s.return_reason IS NULL
    AND c.gender IS NOT NULL AND c.gender <> ''
    AND c.birth_date IS NOT NULL
    AND s.sold_at >= ? AND s.sold_at < ?
  GROUP BY c.gender, age_band, s.product_id, p.name
  ORDER BY c.gender ASC, age_band ASC, qty DESC, tx_count DESC, product_name ASC
");
$stmt->execute([$rangeStartStr, $rangeEndStr]);
foreach ($stmt->fetchAll() as $row) {
  $key = (string)$row['gender'] . '|' . (string)$row['age_band'];
  if (!isset($segmentRows[$key])) {
    $segmentRows[$key] = $row;
  }
}
$customerSegmentFavorites = array_values($segmentRows);

$segmentWhere = '';
$segmentParams = [$rangeStartStr, $rangeEndStr];
if ($segmentGender !== 'all') {
  $segmentWhere .= ' AND c.gender = ?';
  $segmentParams[] = $segmentGender;
}
if ($segmentAge !== 'all') {
  $segmentWhere .= " AND CASE
      WHEN c.birth_date IS NULL THEN 'unknown'
      WHEN TIMESTAMPDIFF(YEAR, c.birth_date, CURDATE()) < 17 THEN 'lt17'
      WHEN TIMESTAMPDIFF(YEAR, c.birth_date, CURDATE()) BETWEEN 17 AND 24 THEN '17_24'
      WHEN TIMESTAMPDIFF(YEAR, c.birth_date, CURDATE()) BETWEEN 25 AND 34 THEN '25_34'
      WHEN TIMESTAMPDIFF(YEAR, c.birth_date, CURDATE()) BETWEEN 35 AND 44 THEN '35_44'
      WHEN TIMESTAMPDIFF(YEAR, c.birth_date, CURDATE()) BETWEEN 45 AND 54 THEN '45_54'
      ELSE '55_plus'
    END = ?";
  $segmentParams[] = $segmentAge;
}
$stmt = db()->prepare("
  SELECT p.name product_name,
         SUM(s.qty) qty,
         COUNT(DISTINCT COALESCE(NULLIF(s.transaction_code, ''), CONCAT('LEGACY-', s.id))) tx_count
  FROM sales s
  JOIN customers c ON c.id = s.customer_id
  JOIN products p ON p.id = s.product_id
  WHERE s.is_active_revision=1
    AND s.return_reason IS NULL
    AND c.gender IS NOT NULL AND c.gender <> ''
    AND c.birth_date IS NOT NULL
    AND s.sold_at >= ? AND s.sold_at < ?
    {$segmentWhere}
  GROUP BY s.product_id, p.name
  ORDER BY qty DESC, tx_count DESC, product_name ASC
  LIMIT 10
");
$stmt->execute($segmentParams);
$selectedSegmentProducts = $stmt->fetchAll();

if ($role === 'admin') {
  $stmt = db()->prepare("
    SELECT COUNT(*) c, COALESCE(SUM(total),0) s
    FROM sales
    WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL
  ");
  $stmt->execute([$todayStartStr, $todayEndStr]);
  $row = $stmt->fetch();

  $stmt = db()->prepare("
    SELECT COUNT(*) c
    FROM sales
    WHERE COALESCE(returned_at, sold_at) >= ?
      AND COALESCE(returned_at, sold_at) < ?
      AND return_reason IS NOT NULL
  ");
  $stmt->execute([$todayStartStr, $todayEndStr]);
  $returnsToday = (int)($stmt->fetch()['c'] ?? 0);

  $stmt = db()->prepare("
    SELECT COUNT(*) c
    FROM sales
    WHERE sold_at >= ?
      AND sold_at < ?
      AND return_reason IS NULL
      AND payment_method != 'cash'
      AND payment_proof_path IS NULL
  ");
  $stmt->execute([$rangeStartStr, $rangeEndStr]);
  $attention = (int)($stmt->fetch()['c'] ?? 0);

  $adminStats = [
    'sales_today' => (int)($row['c'] ?? 0),
    'revenue_today' => (float)($row['s'] ?? 0),
    'returns_today' => $returnsToday,
    'attention' => $attention,
  ];

  $stmt = db()->prepare("
    SELECT s.*, p.name product_name
    FROM sales s
    JOIN products p ON p.id = s.product_id
    WHERE s.return_reason IS NOT NULL
    ORDER BY COALESCE(s.returned_at, s.sold_at) DESC
    LIMIT 5
  ");
  $stmt->execute();
  $recentReturns = $stmt->fetchAll();
}

if ($role === 'owner') {
  $monthStart = $today->modify('first day of this month');
  $monthEnd = $monthStart->modify('+1 month');
  $lastMonthStart = $today->modify('first day of last month');
  $lastMonthEnd = $lastMonthStart->modify('+1 month');

  $monthStartStr = $monthStart->format('Y-m-d H:i:s');
  $monthEndStr = $monthEnd->format('Y-m-d H:i:s');
  $lastMonthStartStr = $lastMonthStart->format('Y-m-d H:i:s');
  $lastMonthEndStr = $lastMonthEnd->format('Y-m-d H:i:s');

  $stmt = db()->prepare("
    SELECT COUNT(*) c, COALESCE(SUM(total),0) s
    FROM sales
    WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL
  ");
  $stmt->execute([$todayStartStr, $todayEndStr]);
  $todayRow = $stmt->fetch();

  $stmt->execute([$monthStartStr, $monthEndStr]);
  $monthRow = $stmt->fetch();

  $stmt->execute([$lastMonthStartStr, $lastMonthEndStr]);
  $lastMonthRow = $stmt->fetch();

  $stmt = db()->prepare("
    SELECT COUNT(*) c
    FROM sales
    WHERE COALESCE(returned_at, sold_at) >= ?
      AND COALESCE(returned_at, sold_at) < ?
      AND return_reason IS NOT NULL
  ");
  $stmt->execute([$monthStartStr, $monthEndStr]);
  $returnsMonth = (int)($stmt->fetch()['c'] ?? 0);

  $stmt = db()->prepare("
    SELECT payment_method, COUNT(*) c, COALESCE(SUM(total),0) s
    FROM sales
    WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL
    GROUP BY payment_method
    ORDER BY s DESC
  ");
  $stmt->execute([$monthStartStr, $monthEndStr]);
  $sharePaymentsMonth = $stmt->fetchAll();

  $superStats = [
    'sales_today' => (float)($todayRow['s'] ?? 0),
    'sales_month' => (float)($monthRow['s'] ?? 0),
    'tx_today' => (int)($todayRow['c'] ?? 0),
    'tx_month' => (int)($monthRow['c'] ?? 0),
    'sales_last_month' => (float)($lastMonthRow['s'] ?? 0),
    'returns_month' => $returnsMonth,
  ];

  $trendStart = $today->modify('-6 days');
  $trendStartStr = $trendStart->format('Y-m-d H:i:s');
  $trendEndStr = $todayEndStr;

  $stmt = db()->prepare("
    SELECT DATE(sold_at) d, COALESCE(SUM(total),0) s
    FROM sales
    WHERE sold_at >= ? AND sold_at < ? AND return_reason IS NULL
    GROUP BY DATE(sold_at)
    ORDER BY d ASC
  ");
  $stmt->execute([$trendStartStr, $trendEndStr]);
  $trendRowsRaw = $stmt->fetchAll();
  $trendMap = [];
  foreach ($trendRowsRaw as $row) {
    $trendMap[$row['d']] = (float)$row['s'];
  }
  $trendRows = [];
  for ($i = 0; $i < 7; $i++) {
    $day = $trendStart->modify('+' . $i . ' days');
    $key = $day->format('Y-m-d');
    $trendRows[] = [
      'date' => $key,
      'amount' => $trendMap[$key] ?? 0,
    ];
  }

  $stmt = db()->prepare("
    SELECT p.name, SUM(s.qty) qty, COALESCE(SUM(s.total),0) omzet
    FROM sales s
    JOIN products p ON p.id = s.product_id
    WHERE s.sold_at >= ? AND s.sold_at < ? AND s.return_reason IS NULL
    GROUP BY s.product_id
    ORDER BY qty DESC
    LIMIT 5
  ");
  $stmt->execute([$monthStartStr, $monthEndStr]);
  $topProducts = $stmt->fetchAll();

  $last30Start = $today->modify('-30 days');
  $last30StartStr = $last30Start->format('Y-m-d H:i:s');
  $last30EndStr = $todayEndStr;

  $stmt = db()->prepare("
    SELECT p.name
    FROM products p
    LEFT JOIN sales s
      ON s.product_id = p.id
      AND s.return_reason IS NULL
      AND s.sold_at >= ?
      AND s.sold_at < ?
    WHERE s.id IS NULL
    ORDER BY p.name ASC
    LIMIT 5
  ");
  $stmt->execute([$last30StartStr, $last30EndStr]);
  $deadStock = $stmt->fetchAll();

  if (count($deadStock) === 0) {
    $stmt = db()->prepare("
      SELECT p.name, COALESCE(SUM(s.qty),0) qty, COALESCE(SUM(s.total),0) omzet
      FROM products p
      LEFT JOIN sales s
        ON s.product_id = p.id
        AND s.return_reason IS NULL
        AND s.sold_at >= ?
        AND s.sold_at < ?
      GROUP BY p.id
      ORDER BY qty ASC, p.name ASC
      LIMIT 5
    ");
    $stmt->execute([$last30StartStr, $last30EndStr]);
    $deadStock = $stmt->fetchAll();
  }
}

function format_rupiah($amount)
{
  return 'Rp ' . format_number_id((float)$amount);
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
  <style>
    .kpi-subtitle {
      margin: 4px 0 0;
      font-size: 12px;
      color: #6b7280;
    }
    .grid.cols-3 {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }
    .grid.cols-4 {
      grid-template-columns: repeat(4, minmax(0, 1fr));
    }
    @media (max-width: 980px) {
      .grid.cols-3,
      .grid.cols-4 {
        grid-template-columns: 1fr;
      }
    }
    .hourly-chart {
      display: grid;
      gap: 10px;
      grid-template-columns: repeat(auto-fit, minmax(70px, 1fr));
      align-items: end;
      margin-top: 12px;
    }
    .hourly-bar {
      display: grid;
      gap: 6px;
      justify-items: center;
    }
    .hourly-bar-value {
      font-size: 12px;
      color: #334155;
    }
    .hourly-bar-fill {
      width: 100%;
      border-radius: 10px 10px 4px 4px;
      background: linear-gradient(180deg, rgba(59,130,246,.9), rgba(59,130,246,.35));
      min-height: 12px;
    }
    .hourly-bar-label {
      font-size: 11px;
      color: #64748b;
    }
    .hourly-filter {
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
      align-items: flex-end;
    }
    .hourly-filter .row {
      margin: 0;
    }
    .compact-chart {
      display: grid;
      gap: 8px;
      margin-top: 12px;
    }
    .compact-row {
      display: grid;
      grid-template-columns: 86px 1fr 56px;
      align-items: center;
      gap: 10px;
      font-size: 13px;
    }
    .compact-track {
      height: 16px;
      border-radius: 999px;
      background: rgba(148,163,184,.18);
      overflow: hidden;
    }
    .compact-fill {
      height: 100%;
      border-radius: 999px;
      background: linear-gradient(90deg, rgba(16,185,129,.35), rgba(16,185,129,.9));
      min-width: 2px;
    }
    .mini-table td,
    .mini-table th {
      padding-top: 8px;
      padding-bottom: 8px;
    }
    @media (min-width: 981px) {
      .hourly-chart {
        grid-template-columns: repeat(24, minmax(24px, 1fr));
        gap: 6px;
      }
      .hourly-bar-fill {
        max-height: 90px;
      }
      .hourly-bar-value,
      .hourly-bar-label {
        font-size: 10px;
      }
    }
    .dashboard-page .content {
      max-width: 1360px;
    }
    .dashboard-page .card {
      overflow-wrap: anywhere;
    }
    .dashboard-page .content table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
    }
    .dashboard-page .content th,
    .dashboard-page .content td {
      text-align: left;
      vertical-align: top;
    }
    .dashboard-page .content th {
      color: var(--muted);
      font-weight: 700;
      border-bottom: 1px solid var(--border);
    }
    .dashboard-page .content td {
      border-bottom: 1px solid var(--border);
    }
    .dashboard-page .content .card > table,
    .dashboard-page .content .card > .grid table,
    .dashboard-page .content .card > div table {
      min-width: 420px;
    }
    .dashboard-page .mini-table {
      min-width: 320px;
    }

    .dashboard-page .customer-summary-table {
      width: 100%;
      min-width: 0 !important;
      table-layout: fixed;
    }
    .dashboard-page .customer-summary-table td,
    .dashboard-page .customer-summary-table th {
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }
    .dashboard-page .customer-summary-table td:first-child,
    .dashboard-page .customer-summary-table th:first-child {
      width: 72%;
      padding-right: 12px;
    }
    .dashboard-page .customer-summary-table td:last-child,
    .dashboard-page .customer-summary-table th:last-child {
      width: 28%;
      text-align: right;
      font-weight: 700;
    }
    .dashboard-page .customer-summary-block {
      min-width: 0;
      overflow: hidden;
    }
    .dashboard-page .content .card {
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }
    .dashboard-page .content .card::-webkit-scrollbar {
      height: 8px;
    }
    .dashboard-page .content .card::-webkit-scrollbar-thumb {
      background: rgba(148,163,184,.55);
      border-radius: 999px;
    }
    @media (min-width: 981px) {
      .dashboard-page .grid.cols-4 {
        grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
      }
      .dashboard-page .grid.cols-3 {
        grid-template-columns: repeat(3, minmax(0, 1fr));
      }
      .dashboard-page .card {
        padding: 14px;
      }
      .dashboard-page .content .card {
        overflow-x: visible;
      }
    }
    @media (max-width: 720px) {
      .dashboard-page .content {
        padding: 12px 10px 22px;
      }
      .dashboard-page .topbar {
        gap: 8px;
        padding: 0 10px;
      }
      .dashboard-page .topbar .title {
        font-size: 15px;
      }
      .dashboard-page .card {
        padding: 12px;
        border-radius: 12px;
      }
      .dashboard-page .grid {
        gap: 12px;
      }
      .dashboard-page .hourly-filter {
        display: grid;
        grid-template-columns: 1fr;
        gap: 10px;
      }
      .dashboard-page .hourly-filter .row {
        min-width: 0 !important;
        width: 100%;
      }
      .dashboard-page .hourly-filter .btn {
        width: 100%;
      }
      .dashboard-page .hourly-chart {
        grid-template-columns: repeat(auto-fit, minmax(48px, 1fr));
        gap: 8px;
      }
      .dashboard-page .hourly-bar-fill {
        max-height: 72px;
      }
      .dashboard-page .compact-row {
        grid-template-columns: 74px minmax(120px, 1fr) 46px;
        gap: 8px;
      }
      .dashboard-page .content .card > table,
      .dashboard-page .content .card > .grid table,
      .dashboard-page .content .card > div table {
        min-width: 560px;
      }
      .dashboard-page .mini-table {
        min-width: 360px;
      }
      .dashboard-page h3 {
        font-size: 17px;
      }
      .dashboard-page h4 {
        font-size: 14px;
      }
    }
    @media (max-width: 420px) {
      .dashboard-page .hourly-chart {
        grid-template-columns: repeat(4, minmax(0, 1fr));
      }
      .dashboard-page .hourly-bar-value,
      .dashboard-page .hourly-bar-label {
        font-size: 10px;
      }
    }
  </style>
</head>
<body class="dashboard-page">
  <div class="container">
    <?php include __DIR__ . '/partials_sidebar.php'; ?>
    <div class="main">
      <div class="topbar">
        <a class="brand-logo" href="<?php echo e(base_url('admin/dashboard.php')); ?>">
          <?php if (!empty($storeLogo)): ?>
            <img src="<?php echo e(upload_url($storeLogo, 'image')); ?>" alt="<?php echo e($storeName); ?>">
          <?php else: ?>
            <span><?php echo e($storeName); ?></span>
          <?php endif; ?>
        </a>
        <button class="burger" data-toggle-sidebar type="button">☰</button>
        <div class="title">Dasbor</div>
        <div class="spacer"></div>
        </div>

      <div class="content">
        <div class="card" style="margin-bottom:16px">
          <h3 style="margin-top:0">Filter Periode</h3>
          <form method="get" style="margin-bottom:12px">
            <div class="row">
              <label>Periode</label>
              <select name="range" id="sales-range">
                <option value="today" <?php echo $range === 'today' ? 'selected' : ''; ?>>Hari ini</option>
                <option value="yesterday" <?php echo $range === 'yesterday' ? 'selected' : ''; ?>>Kemarin</option>
                <option value="last7" <?php echo $range === 'last7' ? 'selected' : ''; ?>>7 hari terakhir</option>
                <option value="this_month" <?php echo $range === 'this_month' ? 'selected' : ''; ?>>Bulan ini</option>
                <option value="last_month" <?php echo $range === 'last_month' ? 'selected' : ''; ?>>Bulan lalu</option>
                <option value="custom" <?php echo $range === 'custom' ? 'selected' : ''; ?>>Custom</option>
              </select>
            </div>
            <div class="row" id="custom-range" style="display:<?php echo $range === 'custom' ? 'grid' : 'none'; ?>;gap:8px">
              <label for="start">Mulai</label>
              <input type="date" name="start" id="start" value="<?php echo e($_GET['start'] ?? $today->format('Y-m-d')); ?>">
              <label for="end">Sampai</label>
              <input type="date" name="end" id="end" value="<?php echo e($_GET['end'] ?? $today->format('Y-m-d')); ?>">
            </div>
            <button class="btn" type="submit">Terapkan</button>
          </form>
          <p><small>Periode: <?php echo e($rangeLabel); ?></small></p>
        </div>

        <div class="grid cols-4">
          <div class="card">
            <h4 style="margin-top:0">Total Produk</h4>
            <div style="font-size:24px;font-weight:600"><?php echo e((string)$stats['products']); ?></div>
          </div>
          <div class="card">
            <h4 style="margin-top:0">Transaksi</h4>
            <div style="font-size:24px;font-weight:600"><?php echo e((string)$stats['sales']); ?></div>
          </div>
          <div class="card">
            <h4 style="margin-top:0">Omzet</h4>
            <div style="font-size:24px;font-weight:600"><?php echo e(format_rupiah($stats['revenue'])); ?></div>
          </div>
          <div class="card">
            <h4 style="margin-top:0">Retur</h4>
            <div style="font-size:24px;font-weight:600"><?php echo e((string)$stats['returns']); ?></div>
          </div>
          <div class="card">
            <h4 style="margin-top:0">Rata-rata Belanja</h4>
            <div style="font-size:24px;font-weight:600"><?php echo e(format_rupiah($stats['avg_transaction'])); ?></div>
          </div>
        </div>

        <div class="grid cols-2" style="margin-top:16px">
          <div class="card">
            <h3 style="margin-top:0">Ringkasan Data Pelanggan</h3>
            <div class="grid cols-3">
              <div class="card">
                <h4 style="margin-top:0">Total Pelanggan</h4>
                <div style="font-size:20px;font-weight:600"><?php echo e((string)$customerSummary['total']); ?></div>
              </div>
              <div class="card">
                <h4 style="margin-top:0">Data Lengkap</h4>
                <div style="font-size:20px;font-weight:600"><?php echo e((string)$customerSummary['complete']); ?></div>
              </div>
              <div class="card">
                <h4 style="margin-top:0">Perlu Dilengkapi</h4>
                <div style="font-size:20px;font-weight:600"><?php echo e((string)$customerSummary['incomplete']); ?></div>
              </div>
            </div>
            <div class="grid cols-2" style="margin-top:12px">
              <div class="customer-summary-block">
                <h4 style="margin:0 0 8px">Jenis Kelamin</h4>
                <table class="mini-table customer-summary-table">
                  <tbody>
                    <?php foreach ($genderLabels as $gKey => $gLabel): ?>
                      <tr><td><?php echo e($gLabel); ?></td><td><?php echo e((string)($customerSummary['gender'][$gKey] ?? 0)); ?></td></tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <div class="customer-summary-block">
                <h4 style="margin:0 0 8px">Rentang Usia</h4>
                <table class="mini-table customer-summary-table">
                  <tbody>
                    <?php foreach ($ageBandLabels as $aKey => $aLabel): ?>
                      <tr><td><?php echo e($aLabel); ?></td><td><?php echo e((string)($customerSummary['age'][$aKey] ?? 0)); ?></td></tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          <div class="card">
            <h3 style="margin-top:0">Grafik Kunjungan Harian</h3>
            <p style="margin:4px 0 12px;color:var(--muted)">Jumlah transaksi unik per hari dalam periode utama.</p>
            <div class="compact-chart">
              <?php foreach ($visitDailyRows as $row): ?>
                <?php $width = $visitDailyMax > 0 ? ((int)$row['count'] / $visitDailyMax) * 100 : 0; ?>
                <div class="compact-row">
                  <div><?php echo e($row['label']); ?></div>
                  <div class="compact-track"><div class="compact-fill" style="width:<?php echo e(number_format($width, 2, '.', '')); ?>%"></div></div>
                  <div style="text-align:right;font-weight:600"><?php echo e((string)$row['count']); ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="card" style="margin-top:16px">
          <h3 style="margin-top:0">Produk Favorit Berdasarkan Jenis Kelamin & Usia</h3>
          <p style="margin:4px 0 12px;color:var(--muted)">Analisa hanya memakai transaksi dengan pelanggan terdaftar yang sudah mengisi jenis kelamin dan tanggal lahir.</p>
          <form method="get" class="hourly-filter" style="margin-bottom:12px">
            <input type="hidden" name="range" value="<?php echo e($range); ?>">
            <?php if (!empty($_GET['start'])): ?><input type="hidden" name="start" value="<?php echo e($_GET['start']); ?>"><?php endif; ?>
            <?php if (!empty($_GET['end'])): ?><input type="hidden" name="end" value="<?php echo e($_GET['end']); ?>"><?php endif; ?>
            <input type="hidden" name="peak_range" value="<?php echo e($peakRange); ?>">
            <input type="hidden" name="peak_day" value="<?php echo e((string)$peakDay); ?>">
            <?php if (!empty($_GET['peak_start'])): ?><input type="hidden" name="peak_start" value="<?php echo e($_GET['peak_start']); ?>"><?php endif; ?>
            <?php if (!empty($_GET['peak_end'])): ?><input type="hidden" name="peak_end" value="<?php echo e($_GET['peak_end']); ?>"><?php endif; ?>
            <div class="row" style="min-width:160px">
              <label>Jenis Kelamin</label>
              <select name="segment_gender">
                <option value="all" <?php echo $segmentGender === 'all' ? 'selected' : ''; ?>>Semua</option>
                <option value="male" <?php echo $segmentGender === 'male' ? 'selected' : ''; ?>>Laki-laki</option>
                <option value="female" <?php echo $segmentGender === 'female' ? 'selected' : ''; ?>>Perempuan</option>
                <option value="other" <?php echo $segmentGender === 'other' ? 'selected' : ''; ?>>Lainnya</option>
              </select>
            </div>
            <div class="row" style="min-width:160px">
              <label>Rentang Usia</label>
              <select name="segment_age">
                <option value="all" <?php echo $segmentAge === 'all' ? 'selected' : ''; ?>>Semua</option>
                <?php foreach ($ageBandLabels as $aKey => $aLabel): ?>
                  <?php if ($aKey === 'unknown') continue; ?>
                  <option value="<?php echo e($aKey); ?>" <?php echo $segmentAge === $aKey ? 'selected' : ''; ?>><?php echo e($aLabel); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn" type="submit">Lihat Menu Favorit</button>
          </form>
          <div class="grid cols-2">
            <div>
              <h4 style="margin-top:0">Menu favorit untuk filter terpilih</h4>
              <table class="mini-table">
                <thead><tr><th>Produk</th><th>Qty</th><th>Transaksi</th></tr></thead>
                <tbody>
                  <?php if (count($selectedSegmentProducts) === 0): ?>
                    <tr><td colspan="3">Belum ada data sesuai filter.</td></tr>
                  <?php else: ?>
                    <?php foreach ($selectedSegmentProducts as $row): ?>
                      <tr>
                        <td><?php echo e($row['product_name']); ?></td>
                        <td><?php echo e((string)$row['qty']); ?></td>
                        <td><?php echo e((string)$row['tx_count']); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
            <div>
              <h4 style="margin-top:0">Top menu per segmentasi</h4>
              <table class="mini-table">
                <thead><tr><th>Segmentasi</th><th>Produk</th><th>Qty</th></tr></thead>
                <tbody>
                  <?php if (count($customerSegmentFavorites) === 0): ?>
                    <tr><td colspan="3">Belum ada data segmentasi.</td></tr>
                  <?php else: ?>
                    <?php foreach ($customerSegmentFavorites as $row): ?>
                      <tr>
                        <td><?php echo e(($genderLabels[$row['gender']] ?? $row['gender']) . ', ' . ($ageBandLabels[$row['age_band']] ?? $row['age_band'])); ?></td>
                        <td><?php echo e($row['product_name']); ?></td>
                        <td><?php echo e((string)$row['qty']); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="card" style="margin-top:16px">
          <h3 style="margin-top:0">Grafik Rata-rata Jam Kunjungan</h3>
          <p style="margin:4px 0 12px;color:var(--muted)">Rata-rata jumlah transaksi per jam berdasarkan periode yang dipilih.</p>
          <form method="get" class="hourly-filter">
            <input type="hidden" name="range" value="<?php echo e($range); ?>">
            <?php if (!empty($_GET['start'])): ?>
              <input type="hidden" name="start" value="<?php echo e($_GET['start']); ?>">
            <?php endif; ?>
            <?php if (!empty($_GET['end'])): ?>
              <input type="hidden" name="end" value="<?php echo e($_GET['end']); ?>">
            <?php endif; ?>
            <input type="hidden" name="segment_gender" value="<?php echo e($segmentGender); ?>">
            <input type="hidden" name="segment_age" value="<?php echo e($segmentAge); ?>">
            <div class="row" style="min-width:160px">
              <label>Periode</label>
              <select name="peak_range" id="peak-range">
                <option value="all_time" <?php echo $peakRange === 'all_time' ? 'selected' : ''; ?>>All time</option>
                <option value="this_week" <?php echo $peakRange === 'this_week' ? 'selected' : ''; ?>>Minggu ini</option>
                <option value="this_month" <?php echo $peakRange === 'this_month' ? 'selected' : ''; ?>>Bulan ini</option>
                <option value="custom" <?php echo $peakRange === 'custom' ? 'selected' : ''; ?>>Custom</option>
              </select>
            </div>
            <div class="row" id="peak-custom-start" style="min-width:160px;display:<?php echo $peakRange === 'custom' ? 'grid' : 'none'; ?>">
              <label>Mulai</label>
              <input type="date" name="peak_start" value="<?php echo e($peakStartInput ?: $today->format('Y-m-d')); ?>">
            </div>
            <div class="row" id="peak-custom-end" style="min-width:160px;display:<?php echo $peakRange === 'custom' ? 'grid' : 'none'; ?>">
              <label>Sampai</label>
              <input type="date" name="peak_end" value="<?php echo e($peakEndInput ?: $today->format('Y-m-d')); ?>">
            </div>
            <div class="row" style="min-width:150px">
              <label>Hari</label>
              <select name="peak_day" id="peak-day">
                <option value="all" <?php echo $peakDay === 'all' ? 'selected' : ''; ?>>Semua hari</option>
                <?php foreach ([2,3,4,5,6,7,1] as $dayNo): ?>
                  <option value="<?php echo e((string)$dayNo); ?>" <?php echo (string)$peakDay === (string)$dayNo ? 'selected' : ''; ?>><?php echo e($weekdayLabels[$dayNo]); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn" type="submit">Terapkan</button>
          </form>
          <p style="margin:10px 0 0"><small id="peak-chart-meta">Periode grafik: <?php echo e($hourlyChartData[$selectedPeakDayKey]['meta'] ?? ($peakLabel . ' · ' . $peakDays . ' hari')); ?></small></p>
          <div class="hourly-chart">
            <?php foreach ($hourlyAverages as $hour => $avg): ?>
              <?php
                $height = $maxHourly > 0 ? ($avg / $maxHourly) * 120 : 0;
                $label = str_pad((string)$hour, 2, '0', STR_PAD_LEFT) . ':00';
              ?>
              <div class="hourly-bar" data-hour="<?php echo e((string)$hour); ?>">
                <div class="hourly-bar-value" data-hourly-value><?php echo e(format_number_id($avg)); ?></div>
                <div class="hourly-bar-fill" data-hourly-fill style="height:<?php echo e(number_format($height, 2, '.', '')); ?>px"></div>
                <div class="hourly-bar-label"><?php echo e($label); ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <?php if ($role === 'owner'): ?>
          <div class="card" style="margin-top:16px">
            <h3 style="margin-top:0">KPI Owner</h3>
            <p class="kpi-subtitle">Ringkasan performa penjualan toko.</p>
            <div class="grid cols-3">
              <div class="card">
                <h4 style="margin-top:0">Sales Hari Ini</h4>
                <div class="kpi-subtitle">Total omzet penjualan hari ini.</div>
                <div style="font-size:20px;font-weight:600"><?php echo e(format_rupiah($superStats['sales_today'])); ?></div>
              </div>
              <div class="card">
                <h4 style="margin-top:0">Sales Bulan Ini</h4>
                <div class="kpi-subtitle">Total omzet penjualan bulan berjalan.</div>
                <div style="font-size:20px;font-weight:600"><?php echo e(format_rupiah($superStats['sales_month'])); ?></div>
              </div>
              <div class="card">
                <h4 style="margin-top:0">Transaksi Hari Ini</h4>
                <div class="kpi-subtitle">Jumlah transaksi selesai hari ini.</div>
                <div style="font-size:20px;font-weight:600"><?php echo e((string)$superStats['tx_today']); ?></div>
              </div>
              <div class="card">
                <h4 style="margin-top:0">Transaksi Bulan Ini</h4>
                <div class="kpi-subtitle">Jumlah transaksi selesai bulan ini.</div>
                <div style="font-size:20px;font-weight:600"><?php echo e((string)$superStats['tx_month']); ?></div>
              </div>
              <div class="card">
                <h4 style="margin-top:0">AOV Bulan Ini</h4>
                <div class="kpi-subtitle">Rata-rata nilai transaksi bulan ini.</div>
                <div style="font-size:20px;font-weight:600">
                  <?php
                  $aov = $superStats['tx_month'] > 0 ? $superStats['sales_month'] / $superStats['tx_month'] : 0;
                  echo e(format_rupiah($aov));
                  ?>
                </div>
              </div>
              <div class="card">
                <h4 style="margin-top:0">Growth vs Bulan Lalu</h4>
                <div class="kpi-subtitle">Perbandingan omzet bulan ini vs bulan lalu.</div>
                <div style="font-size:20px;font-weight:600">
                  <?php
                  if ($superStats['sales_last_month'] > 0) {
                    $growth = (($superStats['sales_month'] - $superStats['sales_last_month']) / $superStats['sales_last_month']) * 100;
                    echo e(format_number_id($growth)) . '%';
                  } else {
                    echo 'N/A';
                  }
                  ?>
                </div>
              </div>
            </div>
          </div>

          <div class="grid cols-2" style="margin-top:16px">
            <div class="card">
              <h3 style="margin-top:0">Omzet per Hari (7 hari terakhir)</h3>
              <table>
                <thead>
                  <tr>
                    <th>Tanggal</th>
                    <th>Omzet</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($trendRows as $row): ?>
                    <tr>
                      <td><?php echo e($row['date']); ?></td>
                      <td><?php echo e(format_rupiah($row['amount'])); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <div class="card">
              <h3 style="margin-top:0">Share Metode Pembayaran (Bulan Ini)</h3>
              <table>
                <thead>
                  <tr>
                    <th>Metode</th>
                    <th>Transaksi</th>
                    <th>Omzet</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (count($sharePaymentsMonth) === 0): ?>
                    <tr>
                      <td colspan="3">Belum ada data.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($sharePaymentsMonth as $row): ?>
                      <tr>
                        <td><?php echo e($row['payment_method'] ?? '-'); ?></td>
                        <td><?php echo e((string)$row['c']); ?></td>
                        <td><?php echo e(format_rupiah($row['s'])); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="grid cols-2" style="margin-top:16px">
            <div class="card">
              <h3 style="margin-top:0">Top 5 Produk Terlaris (Bulan Ini)</h3>
              <table>
                <thead>
                  <tr>
                    <th>Produk</th>
                    <th>Qty</th>
                    <th>Omzet</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (count($topProducts) === 0): ?>
                    <tr>
                      <td colspan="3">Belum ada penjualan.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($topProducts as $row): ?>
                      <tr>
                        <td><?php echo e($row['name']); ?></td>
                        <td><?php echo e((string)$row['qty']); ?></td>
                        <td><?php echo e(format_rupiah($row['omzet'])); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
            <div class="card">
              <h3 style="margin-top:0">Dead Stock (30 Hari)</h3>
              <table>
                <thead>
                  <tr>
                    <th>Produk</th>
                    <th>Keterangan</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (count($deadStock) === 0): ?>
                    <tr>
                      <td colspan="2">Semua produk punya penjualan.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($deadStock as $row): ?>
                      <tr>
                        <td><?php echo e($row['name']); ?></td>
                        <td>
                          <?php if (isset($row['qty'])): ?>
                            Qty <?php echo e((string)$row['qty']); ?>
                          <?php else: ?>
                            Tidak ada penjualan
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <div class="card" style="margin-top:16px">
            <h3 style="margin-top:0">Return Rate Bulan Ini</h3>
            <p>
              <?php
              $returnRateDenom = $superStats['returns_month'] + $superStats['tx_month'];
              $returnRate = $returnRateDenom > 0 ? ($superStats['returns_month'] / $returnRateDenom) * 100 : 0;
              ?>
              <strong><?php echo e(format_number_id($returnRate)); ?>%</strong>
              (<?php echo e((string)$superStats['returns_month']); ?> retur dari <?php echo e((string)$returnRateDenom); ?> transaksi)
            </p>
          </div>

          <div class="card" style="margin-top:16px">
            <h3 style="margin-top:0">Quick Links</h3>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
              <a class="btn" href="<?php echo e(base_url('admin/products.php')); ?>">Produk</a>
              <a class="btn" href="<?php echo e(base_url('admin/sales.php')); ?>">Penjualan</a>
              <a class="btn" href="<?php echo e(base_url('admin/theme.php')); ?>">Tema</a>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($role === 'admin'): ?>
          <div class="card" style="margin-top:16px">
            <h3 style="margin-top:0">Tugas Hari Ini</h3>
            <div class="grid cols-4">
              <div class="card">
                <h4 style="margin-top:0">Transaksi Hari Ini</h4>
                <div style="font-size:20px;font-weight:600"><?php echo e((string)$adminStats['sales_today']); ?></div>
              </div>
              <div class="card">
                <h4 style="margin-top:0">Omzet Hari Ini</h4>
                <div style="font-size:20px;font-weight:600"><?php echo e(format_rupiah($adminStats['revenue_today'])); ?></div>
              </div>
              <div class="card">
                <h4 style="margin-top:0">Retur Hari Ini</h4>
                <div style="font-size:20px;font-weight:600"><?php echo e((string)$adminStats['returns_today']); ?></div>
              </div>
              <div class="card">
                <h4 style="margin-top:0">Perlu Perhatian</h4>
                <div style="font-size:20px;font-weight:600"><?php echo e((string)$adminStats['attention']); ?></div>
              </div>
            </div>
          </div>

          <div class="card" style="margin-top:16px">
            <h3 style="margin-top:0">Aksi Cepat</h3>
            <div style="display:flex;gap:12px;flex-wrap:wrap">
              <a class="btn" href="<?php echo e(base_url('pos/index.php')); ?>">Ke POS</a>
              <a class="btn" href="<?php echo e(base_url('admin/sales.php')); ?>">Penjualan</a>
              <a class="btn" href="<?php echo e(base_url('admin/products.php')); ?>">Produk</a>
              <a class="btn" href="<?php echo e(base_url('admin/theme.php')); ?>">Tema</a>
            </div>
          </div>

          <div class="card" style="margin-top:16px">
            <h3 style="margin-top:0">Retur Terbaru</h3>
            <table>
              <thead>
                <tr>
                  <th>Tanggal</th>
                  <th>Produk</th>
                  <th>Qty</th>
                  <th>Alasan</th>
                </tr>
              </thead>
              <tbody>
                <?php if (count($recentReturns) === 0): ?>
                  <tr>
                    <td colspan="4">Belum ada retur.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($recentReturns as $row): ?>
                    <tr>
                      <td><?php echo e($row['returned_at'] ?? $row['sold_at']); ?></td>
                      <td><?php echo e($row['product_name']); ?></td>
                      <td><?php echo e((string)$row['qty']); ?></td>
                      <td><?php echo e($row['return_reason']); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <div class="grid cols-2" style="margin-top:16px">
          <div class="card">
            <h3 style="margin-top:0">Breakdown Metode Pembayaran</h3>
            <table>
              <thead>
                <tr>
                  <th>Metode</th>
                  <th>Transaksi</th>
                  <th>Omzet</th>
                </tr>
              </thead>
              <tbody>
                <?php if (count($paymentBreakdown) === 0): ?>
                  <tr>
                    <td colspan="3">Belum ada transaksi.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($paymentBreakdown as $row): ?>
                    <tr>
                      <td><?php echo e($row['payment_method'] ?? '-'); ?></td>
                      <td><?php echo e((string)$row['c']); ?></td>
                      <td><?php echo e(format_rupiah($row['s'])); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
          <div class="card">
            <h3 style="margin-top:0">Aktivitas Terbaru</h3>
            <table>
              <thead>
                <tr>
                  <th>Tanggal</th>
                  <th>Produk</th>
                  <th>Qty</th>
                  <th>Total</th>
                  <th>Metode</th>
                </tr>
              </thead>
              <tbody>
                <?php if (count($recentActivity) === 0): ?>
                  <tr>
                    <td colspan="5">Belum ada transaksi.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($recentActivity as $row): ?>
                    <tr>
                      <td>
                        <?php echo e($row['sold_at']); ?>
                        <?php if (!empty($row['return_reason'])): ?>
                          <span class="badge" style="margin-left:6px">RETUR</span>
                        <?php endif; ?>
                      </td>
                      <td><?php echo e($row['product_name']); ?></td>
                      <td><?php echo e((string)$row['qty']); ?></td>
                      <td><?php echo e(format_rupiah($row['total'])); ?></td>
                      <td><?php echo e($row['payment_method'] ?? '-'); ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>
  <script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
  <script nonce="<?php echo e(csp_nonce()); ?>">
    const rangeSelect = document.querySelector('#sales-range');
    const customRange = document.querySelector('#custom-range');
    if (rangeSelect && customRange) {
      rangeSelect.addEventListener('change', () => {
        customRange.style.display = rangeSelect.value === 'custom' ? 'grid' : 'none';
      });
    }

    const peakSelect = document.querySelector('#peak-range');
    const peakStart = document.querySelector('#peak-custom-start');
    const peakEnd = document.querySelector('#peak-custom-end');
    const peakDaySelect = document.querySelector('#peak-day');
    const peakChartMeta = document.querySelector('#peak-chart-meta');
    const hourlyChartData = <?php echo json_encode($hourlyChartData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    const submitFormSafely = (form) => {
      if (!form) return;
      form.submit();
    };
    if (peakSelect && peakStart && peakEnd) {
      const togglePeakCustom = () => {
        const show = peakSelect.value === 'custom';
        peakStart.style.display = show ? 'grid' : 'none';
        peakEnd.style.display = show ? 'grid' : 'none';
      };
      peakSelect.addEventListener('change', () => {
        togglePeakCustom();
        if (peakSelect.value !== 'custom') {
          submitFormSafely(peakSelect.form);
        }
      });
      togglePeakCustom();
    }
    if (peakDaySelect) {
      const updateHourlyChart = () => {
        const dayKey = peakDaySelect.value || 'all';
        const chartPayload = hourlyChartData[dayKey] || hourlyChartData.all;
        if (!chartPayload || !chartPayload.rows) return;
        document.querySelectorAll('.hourly-chart .hourly-bar').forEach((bar) => {
          const hour = bar.dataset.hour;
          const row = chartPayload.rows[hour];
          if (!row) return;
          const valueEl = bar.querySelector('[data-hourly-value]');
          const fillEl = bar.querySelector('[data-hourly-fill]');
          if (valueEl) valueEl.textContent = row.value;
          if (fillEl) fillEl.style.height = `${row.height}px`;
        });
        if (peakChartMeta) {
          peakChartMeta.textContent = `Periode grafik: ${chartPayload.meta}`;
        }
      };
      peakDaySelect.addEventListener('change', updateHourlyChart);
    }
  </script>
</body>
</html>
