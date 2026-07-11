<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/inventory.php';
require_once __DIR__ . '/sales_revision.php';

function pos_sales_history_range(array $source): array {
  $allowed = ['today', 'yesterday', '7days', 'custom'];
  $range = strtolower(trim((string)($source['range'] ?? 'today')));
  if (!in_array($range, $allowed, true)) $range = 'today';

  $tz = new DateTimeZone('Asia/Jakarta');
  $today = new DateTimeImmutable('today', $tz);
  $customStart = trim((string)($source['start'] ?? ''));
  $customEnd = trim((string)($source['end'] ?? ''));
  $warning = '';

  if ($range === 'today') {
    $start = $today->setTime(0, 0, 0);
    $end = $today->setTime(23, 59, 59);
    $label = 'Hari ini (' . $today->format('d/m/Y') . ')';
  } elseif ($range === 'yesterday') {
    $day = $today->modify('-1 day');
    $start = $day->setTime(0, 0, 0);
    $end = $day->setTime(23, 59, 59);
    $label = 'Kemarin (' . $day->format('d/m/Y') . ')';
  } elseif ($range === '7days') {
    $start = $today->modify('-6 days')->setTime(0, 0, 0);
    $end = $today->setTime(23, 59, 59);
    $label = '7 hari terakhir (' . $start->format('d/m/Y') . ' - ' . $end->format('d/m/Y') . ')';
  } else {
    $parsedStart = DateTimeImmutable::createFromFormat('!Y-m-d', $customStart, $tz);
    $parsedEnd = DateTimeImmutable::createFromFormat('!Y-m-d', $customEnd, $tz);
    $validStart = $parsedStart && $parsedStart->format('Y-m-d') === $customStart;
    $validEnd = $parsedEnd && $parsedEnd->format('Y-m-d') === $customEnd;
    if (!$validStart || !$validEnd) {
      $range = 'today';
      $start = $today->setTime(0, 0, 0);
      $end = $today->setTime(23, 59, 59);
      $label = 'Hari ini (' . $today->format('d/m/Y') . ')';
      $warning = 'Tanggal custom tidak valid. Filter dikembalikan ke hari ini.';
    } else {
      $start = $parsedStart->setTime(0, 0, 0);
      $end = $parsedEnd->setTime(23, 59, 59);
      if ($start > $end) {
        [$start, $end] = [$end->setTime(0, 0, 0), $start->setTime(23, 59, 59)];
        $warning = 'Urutan tanggal custom dibalik secara otomatis.';
      }
      $maxEnd = $start->modify('+366 days')->setTime(23, 59, 59);
      if ($end > $maxEnd) {
        $end = $maxEnd;
        $warning = 'Rentang custom dibatasi maksimal 367 hari untuk menjaga performa POS.';
      }
      $customStart = $start->format('Y-m-d');
      $customEnd = $end->format('Y-m-d');
      $label = 'Custom (' . $start->format('d/m/Y') . ' - ' . $end->format('d/m/Y') . ')';
    }
  }

  return [
    'range' => $range,
    'start_input' => $customStart,
    'end_input' => $customEnd,
    'start' => $start,
    'end' => $end,
    'start_sql' => $start->format('Y-m-d H:i:s'),
    'end_sql' => $end->format('Y-m-d H:i:s'),
    'label' => $label,
    'warning' => $warning,
  ];
}

function pos_sales_history_base_where(array $range, int $branchId): array {
  $where = "s.is_active_revision=1
    AND s.branch_id=?
    AND s.transaction_code IS NOT NULL
    AND s.transaction_code LIKE 'TRX-%'
    AND s.sold_at BETWEEN ? AND ?";
  return [$where, [$branchId, $range['start_sql'], $range['end_sql']]];
}

function pos_sales_history_headers(array $range, int $branchId, int $limit = 50, int $offset = 0): array {
  [$where, $params] = pos_sales_history_base_where($range, $branchId);
  $limit = max(1, min(200, $limit));
  $offset = max(0, $offset);

  $sql = "SELECT
      s.transaction_code,
      MIN(s.sold_at) AS sold_at,
      MIN(s.payment_method) AS payment_method,
      MIN(s.payment_proof_path) AS payment_proof_path,
      MIN(NULLIF(s.customer_name,'')) AS customer_name,
      MIN(u.name) AS cashier_name,
      SUM(s.qty) AS item_qty,
      COUNT(*) AS item_lines,
      CASE WHEN MAX(s.grand_total) > 0 THEN MAX(s.grand_total) ELSE SUM(s.total) END AS total_amount,
      MAX(CASE WHEN s.returned_at IS NOT NULL THEN 1 ELSE 0 END) AS is_returned,
      MAX(s.returned_at) AS returned_at,
      MAX(s.return_reason) AS return_reason,
      MAX(s.revision_no) AS revision_no
    FROM sales s
    LEFT JOIN users u ON u.id=s.created_by
    WHERE {$where}
    GROUP BY s.transaction_code
    ORDER BY sold_at DESC, s.transaction_code DESC
    LIMIT {$limit} OFFSET {$offset}";

  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll();
}

function pos_sales_history_count(array $range, int $branchId): int {
  [$where, $params] = pos_sales_history_base_where($range, $branchId);
  $stmt = db()->prepare("SELECT COUNT(DISTINCT s.transaction_code) FROM sales s WHERE {$where}");
  $stmt->execute($params);
  return (int)$stmt->fetchColumn();
}

function pos_sales_history_detail(string $transactionCode, int $branchId): ?array {
  $transactionCode = trim($transactionCode);
  if ($transactionCode === '' || !preg_match('/^TRX-[A-Za-z0-9\-]{6,70}$/', $transactionCode)) return null;

  $stmt = db()->prepare("SELECT
      s.id,
      s.transaction_code,
      s.sold_at,
      s.payment_method,
      s.payment_proof_path,
      s.customer_name,
      s.qty,
      s.price_each,
      s.total,
      s.discount_amount,
      s.tax_amount,
      s.extra_fee,
      s.grand_total,
      s.returned_at,
      s.return_reason,
      s.revision_no,
      p.name AS product_name,
      u.name AS cashier_name
    FROM sales s
    JOIN products p ON p.id=s.product_id
    LEFT JOIN users u ON u.id=s.created_by
    WHERE s.transaction_code=?
      AND s.branch_id=?
      AND s.is_active_revision=1
      AND s.transaction_code LIKE 'TRX-%'
    ORDER BY s.id ASC");
  $stmt->execute([$transactionCode, $branchId]);
  $rows = $stmt->fetchAll();
  if (!$rows) return null;

  $first = $rows[0];
  $items = [];
  $subtotal = 0.0;
  $itemQty = 0.0;
  foreach ($rows as $row) {
    $qty = (float)$row['qty'];
    $lineSubtotal = (float)$row['total'];
    $items[] = [
      'name' => (string)$row['product_name'],
      'qty' => $qty,
      'price' => (float)$row['price_each'],
      'subtotal' => $lineSubtotal,
      'is_reward' => (float)$row['price_each'] <= 0 && $lineSubtotal <= 0,
    ];
    $subtotal += $lineSubtotal;
    $itemQty += $qty;
  }
  $discount = (float)($first['discount_amount'] ?? 0);
  $tax = (float)($first['tax_amount'] ?? 0);
  $extraFee = (float)($first['extra_fee'] ?? 0);
  $grandTotal = (float)($first['grand_total'] ?? 0);
  $total = $grandTotal > 0 ? $grandTotal : max(0, $subtotal - $discount + $tax + $extraFee);

  return [
    'transaction_code' => (string)$first['transaction_code'],
    'sold_at' => (string)$first['sold_at'],
    'payment_method' => (string)($first['payment_method'] ?? ''),
    'payment_proof_path' => (string)($first['payment_proof_path'] ?? ''),
    'customer_name' => (string)($first['customer_name'] ?? ''),
    'cashier_name' => (string)($first['cashier_name'] ?? ''),
    'returned_at' => (string)($first['returned_at'] ?? ''),
    'return_reason' => (string)($first['return_reason'] ?? ''),
    'revision_no' => (int)($first['revision_no'] ?? 0),
    'items' => $items,
    'item_qty' => $itemQty,
    'subtotal' => $subtotal,
    'discount_amount' => $discount,
    'tax_amount' => $tax,
    'extra_fee' => $extraFee,
    'total' => $total,
  ];
}

function pos_sales_history_summary(array $range, int $branchId): array {
  [$where, $params] = pos_sales_history_base_where($range, $branchId);
  $headerSql = "SELECT
      s.transaction_code,
      DATE(MIN(s.sold_at)) AS sale_date,
      MIN(s.payment_method) AS payment_method,
      MIN(u.name) AS cashier_name,
      SUM(s.qty) AS item_qty,
      CASE WHEN MAX(s.grand_total) > 0 THEN MAX(s.grand_total) ELSE SUM(s.total) END AS total_amount,
      MAX(CASE WHEN s.returned_at IS NOT NULL THEN 1 ELSE 0 END) AS is_returned
    FROM sales s
    LEFT JOIN users u ON u.id=s.created_by
    WHERE {$where}
    GROUP BY s.transaction_code
    ORDER BY sale_date ASC";
  $stmt = db()->prepare($headerSql);
  $stmt->execute($params);
  $headers = $stmt->fetchAll();

  $summary = [
    'transaction_count' => 0,
    'active_transaction_count' => 0,
    'returned_transaction_count' => 0,
    'item_qty' => 0.0,
    'cash_count' => 0,
    'cash_amount' => 0.0,
    'qris_count' => 0,
    'qris_amount' => 0.0,
    'other_count' => 0,
    'other_amount' => 0.0,
    'gross_sales' => 0.0,
    'return_amount' => 0.0,
    'net_sales' => 0.0,
    'daily' => [],
    'cashiers' => [],
    'products' => [],
  ];

  foreach ($headers as $row) {
    $summary['transaction_count']++;
    $amount = (float)$row['total_amount'];
    $qty = (float)$row['item_qty'];
    $isReturned = (int)$row['is_returned'] === 1;
    $method = strtolower(trim((string)$row['payment_method']));
    $date = (string)$row['sale_date'];
    $cashier = trim((string)($row['cashier_name'] ?? '')) ?: '-';

    $summary['gross_sales'] += $amount;
    if ($isReturned) {
      $summary['returned_transaction_count']++;
      $summary['return_amount'] += $amount;
    } else {
      $summary['active_transaction_count']++;
      $summary['item_qty'] += $qty;
      if ($method === 'cash') {
        $summary['cash_count']++;
        $summary['cash_amount'] += $amount;
      } elseif ($method === 'qris') {
        $summary['qris_count']++;
        $summary['qris_amount'] += $amount;
      } else {
        $summary['other_count']++;
        $summary['other_amount'] += $amount;
      }
    }

    if (!isset($summary['daily'][$date])) {
      $summary['daily'][$date] = ['date' => $date, 'transactions' => 0, 'gross' => 0.0, 'returns' => 0.0, 'net' => 0.0];
    }
    $summary['daily'][$date]['transactions']++;
    $summary['daily'][$date]['gross'] += $amount;
    if ($isReturned) $summary['daily'][$date]['returns'] += $amount;
    $summary['daily'][$date]['net'] = $summary['daily'][$date]['gross'] - $summary['daily'][$date]['returns'];

    if (!isset($summary['cashiers'][$cashier])) {
      $summary['cashiers'][$cashier] = ['cashier' => $cashier, 'transactions' => 0, 'amount' => 0.0];
    }
    if (!$isReturned) {
      $summary['cashiers'][$cashier]['transactions']++;
      $summary['cashiers'][$cashier]['amount'] += $amount;
    }
  }
  $summary['net_sales'] = $summary['gross_sales'] - $summary['return_amount'];

  $productSql = "SELECT
      p.id AS product_id,
      p.name AS product_name,
      SUM(CASE WHEN s.returned_at IS NULL THEN s.qty ELSE 0 END) AS qty_sold,
      SUM(CASE WHEN s.returned_at IS NULL THEN s.total ELSE 0 END) AS sales_amount,
      SUM(CASE WHEN s.returned_at IS NOT NULL THEN s.qty ELSE 0 END) AS qty_returned,
      SUM(CASE WHEN s.returned_at IS NOT NULL THEN s.total ELSE 0 END) AS return_amount
    FROM sales s
    JOIN products p ON p.id=s.product_id
    WHERE {$where}
    GROUP BY p.id, p.name
    HAVING qty_sold <> 0 OR qty_returned <> 0
    ORDER BY qty_sold DESC, sales_amount DESC, p.name ASC";
  $stmt = db()->prepare($productSql);
  $stmt->execute($params);
  $summary['products'] = $stmt->fetchAll();
  $summary['daily'] = array_values($summary['daily']);
  $summary['cashiers'] = array_values(array_filter($summary['cashiers'], fn($row) => (int)$row['transactions'] > 0));
  usort($summary['cashiers'], fn($a, $b) => ($b['amount'] <=> $a['amount']));

  return $summary;
}

function pos_sales_history_query_string(array $range, array $extra = []): string {
  $params = ['range' => $range['range']];
  if ($range['range'] === 'custom') {
    $params['start'] = $range['start']->format('Y-m-d');
    $params['end'] = $range['end']->format('Y-m-d');
  }
  foreach ($extra as $key => $value) {
    if ($value === null || $value === '') continue;
    $params[$key] = $value;
  }
  return http_build_query($params);
}

function pos_sales_receipt_from_detail(array $detail): array {
  $soldAt = trim((string)($detail['sold_at'] ?? ''));
  $time = $soldAt;
  try {
    $dt = new DateTimeImmutable($soldAt, new DateTimeZone('Asia/Jakarta'));
    $time = $dt->format('d/m/Y H:i');
  } catch (Throwable $e) {
  }
  return [
    'id' => (string)$detail['transaction_code'],
    'time' => $time,
    'cashier' => (string)($detail['cashier_name'] ?: '-'),
    'payment' => (string)($detail['payment_method'] ?: '-'),
    'items' => $detail['items'] ?? [],
    'total' => (float)($detail['total'] ?? 0),
    'paid_amount' => (float)($detail['total'] ?? 0),
    'change_amount' => 0,
  ];
}
