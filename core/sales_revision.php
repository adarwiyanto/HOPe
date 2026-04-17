<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/inventory.php';

function ensure_sales_revision_schema(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  $defs = [
    "ALTER TABLE sales ADD COLUMN base_sale_code VARCHAR(60) NULL AFTER transaction_code",
    "ALTER TABLE sales ADD COLUMN revision_suffix VARCHAR(10) NULL AFTER base_sale_code",
    "ALTER TABLE sales ADD COLUMN revision_no INT NOT NULL DEFAULT 0 AFTER revision_suffix",
    "ALTER TABLE sales ADD COLUMN is_active_revision TINYINT(1) NOT NULL DEFAULT 1 AFTER revision_no",
    "ALTER TABLE sales ADD COLUMN revised_from_sale_id INT NULL AFTER is_active_revision",
    "ALTER TABLE sales ADD COLUMN revision_reason_category VARCHAR(80) NULL AFTER revised_from_sale_id",
    "ALTER TABLE sales ADD COLUMN revision_reason_text TEXT NULL AFTER revision_reason_category",
    "ALTER TABLE sales ADD COLUMN revised_by_user_id INT NULL AFTER revision_reason_text",
    "ALTER TABLE sales ADD COLUMN revised_at DATETIME NULL AFTER revised_by_user_id",
    "ALTER TABLE sales ADD COLUMN revision_status VARCHAR(30) NOT NULL DEFAULT 'active' AFTER revised_at",
    "ALTER TABLE sales ADD COLUMN original_sale_id INT NULL AFTER revision_status",
    "ALTER TABLE sales ADD COLUMN customer_name VARCHAR(160) NULL AFTER payment_proof_path",
    "ALTER TABLE sales ADD COLUMN discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER total",
    "ALTER TABLE sales ADD COLUMN tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER discount_amount",
    "ALTER TABLE sales ADD COLUMN extra_fee DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER tax_amount",
    "ALTER TABLE sales ADD COLUMN grand_total DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER extra_fee",
    "ALTER TABLE sales ADD COLUMN notes TEXT NULL AFTER grand_total",
    "ALTER TABLE sales ADD COLUMN sale_status VARCHAR(30) NOT NULL DEFAULT 'completed' AFTER notes",
  ];
  foreach ($defs as $sql) {
    try { db()->exec($sql); } catch (Throwable $e) {}
  }
  try { db()->exec("CREATE INDEX idx_sales_revision_active ON sales (base_sale_code, is_active_revision, revision_no)"); } catch (Throwable $e) {}
  try { db()->exec("CREATE INDEX idx_sales_original ON sales (original_sale_id)"); } catch (Throwable $e) {}

  try {
    db()->exec("UPDATE sales
      SET base_sale_code = COALESCE(NULLIF(transaction_code,''), CONCAT('LEGACY-', id))
      WHERE base_sale_code IS NULL OR base_sale_code=''");
    db()->exec("UPDATE sales SET revision_no=0 WHERE revision_no IS NULL");
    db()->exec("UPDATE sales SET revision_status='active' WHERE revision_status IS NULL OR revision_status=''");
    db()->exec("UPDATE sales SET grand_total=total WHERE grand_total IS NULL OR grand_total=0");
  } catch (Throwable $e) {}
}

function sale_revision_suffix(int $revisionNo): string {
  if ($revisionNo <= 0) return '';
  $n = $revisionNo;
  $suffix = '';
  while ($n > 0) {
    $n--;
    $suffix = chr(($n % 26) + 65) . $suffix;
    $n = intdiv($n, 26);
  }
  return $suffix;
}

function sale_code_with_revision(string $base, int $revisionNo): string {
  $suffix = sale_revision_suffix($revisionNo);
  return $base . $suffix;
}

function fetch_sale_version_items(string $transactionCode, bool $forUpdate = false): array {
  $sql = "SELECT s.*, p.name AS product_name, p.base_unit, p.product_type, p.track_stock, u.name AS cashier_name,
      ru.name AS revised_by_name, r.role_name AS cashier_role_name
    FROM sales s
    JOIN products p ON p.id=s.product_id
    LEFT JOIN users u ON u.id=s.created_by
    LEFT JOIN users ru ON ru.id=s.revised_by_user_id
    LEFT JOIN roles r ON r.id=u.role_id
    WHERE s.transaction_code=?
    ORDER BY s.id ASC";
  if ($forUpdate) $sql .= " FOR UPDATE";
  $stmt = db()->prepare($sql);
  $stmt->execute([$transactionCode]);
  return $stmt->fetchAll();
}

function sale_version_header(array $items): array {
  if (empty($items)) return [];
  $first = $items[0];
  $subtotal = 0.0;
  foreach ($items as $it) {
    $subtotal += (float)$it['total'];
  }
  $discount = (float)($first['discount_amount'] ?? 0);
  $tax = (float)($first['tax_amount'] ?? 0);
  $fee = (float)($first['extra_fee'] ?? 0);
  $grand = (float)($first['grand_total'] ?? 0);
  if ($grand <= 0) $grand = max(0, $subtotal - $discount + $tax + $fee);
  return [
    'id' => (int)$first['id'],
    'transaction_code' => (string)$first['transaction_code'],
    'base_sale_code' => (string)$first['base_sale_code'],
    'revision_suffix' => (string)($first['revision_suffix'] ?? ''),
    'revision_no' => (int)($first['revision_no'] ?? 0),
    'is_active_revision' => (int)($first['is_active_revision'] ?? 1),
    'sold_at' => (string)$first['sold_at'],
    'customer_name' => (string)($first['customer_name'] ?? ''),
    'cashier_name' => (string)($first['cashier_name'] ?? '-'),
    'cashier_role_name' => (string)($first['cashier_role_name'] ?? '-'),
    'sale_status' => (string)($first['sale_status'] ?? 'completed'),
    'payment_method' => (string)($first['payment_method'] ?? '-'),
    'payment_proof_path' => $first['payment_proof_path'] ?? null,
    'subtotal' => $subtotal,
    'discount_amount' => $discount,
    'tax_amount' => $tax,
    'extra_fee' => $fee,
    'grand_total' => $grand,
    'notes' => (string)($first['notes'] ?? ''),
    'revision_reason_category' => (string)($first['revision_reason_category'] ?? ''),
    'revision_reason_text' => (string)($first['revision_reason_text'] ?? ''),
    'revised_at' => (string)($first['revised_at'] ?? ''),
    'revised_by_name' => (string)($first['revised_by_name'] ?? ''),
    'is_revised' => (int)($first['revision_no'] ?? 0) > 0,
  ];
}

function sale_revision_history(string $baseSaleCode): array {
  $stmt = db()->prepare("SELECT transaction_code FROM sales WHERE base_sale_code=? GROUP BY transaction_code ORDER BY MAX(revision_no) DESC, MAX(id) DESC");
  $stmt->execute([$baseSaleCode]);
  $codes = $stmt->fetchAll();
  $rows = [];
  foreach ($codes as $code) {
    $items = fetch_sale_version_items((string)$code['transaction_code']);
    if (!$items) continue;
    $header = sale_version_header($items);
    $rows[] = $header;
  }
  return $rows;
}

function rollback_sale_stock(string $transactionCode, int $userId, string $reason = ''): void {
  $items = fetch_sale_version_items($transactionCode, true);
  if (!$items) throw new Exception('Transaksi lama tidak ditemukan.');
  foreach ($items as $it) {
    if ((string)($it['product_type'] ?? 'finished_good') === 'service' || (int)($it['track_stock'] ?? 1) !== 1) continue;
    add_stock_ledger([
      'branch_id' => (int)($it['branch_id'] ?? active_branch_id()),
      'product_id' => (int)$it['product_id'],
      'trans_type' => 'sale_revision_rollback',
      'ref_table' => 'sales',
      'ref_id' => (int)$it['id'],
      'qty_in' => (float)$it['qty'],
      'qty_out' => 0,
      'unit_cost' => null,
      'note' => 'Rollback revisi ' . $transactionCode . ($reason !== '' ? ' - ' . $reason : ''),
      'created_by' => $userId,
    ]);
  }
}

function apply_sale_stock_items(array $lineItems, int $branchId, int $userId, string $refCode): void {
  foreach ($lineItems as $it) {
    $pid = (int)$it['product_id'];
    $qty = (float)$it['qty'];
    if ($pid <= 0 || $qty <= 0) continue;
    $stmt = db()->prepare("SELECT product_type, track_stock FROM products WHERE id=? LIMIT 1");
    $stmt->execute([$pid]);
    $p = $stmt->fetch();
    if (!$p) throw new Exception('Produk tidak ditemukan saat apply stok.');
    if ((string)($p['product_type'] ?? 'finished_good') === 'service' || (int)($p['track_stock'] ?? 1) !== 1) continue;
    $stockNow = branch_stock($branchId, $pid);
    if ($stockNow < $qty) {
      throw new Exception('Stok tidak cukup untuk produk ID ' . $pid . ' saat revisi.');
    }
  }

  foreach ($lineItems as $it) {
    $pid = (int)$it['product_id'];
    $qty = (float)$it['qty'];
    if ($pid <= 0 || $qty <= 0) continue;
    $stmt = db()->prepare("SELECT product_type, track_stock FROM products WHERE id=? LIMIT 1");
    $stmt->execute([$pid]);
    $p = $stmt->fetch();
    if (!$p) continue;
    if ((string)($p['product_type'] ?? 'finished_good') === 'service' || (int)($p['track_stock'] ?? 1) !== 1) continue;
    add_stock_ledger([
      'branch_id' => $branchId,
      'product_id' => $pid,
      'trans_type' => 'sale_revision_apply',
      'ref_table' => 'sales',
      'ref_id' => 0,
      'qty_in' => 0,
      'qty_out' => $qty,
      'unit_cost' => null,
      'note' => 'Apply revisi ' . $refCode,
      'created_by' => $userId,
    ]);
  }
}

function revise_sale_transaction(string $sourceTransactionCode, array $payload, array $actor): string {
  ensure_sales_revision_schema();
  $db = db();
  $db->beginTransaction();
  try {
    $oldItems = fetch_sale_version_items($sourceTransactionCode, true);
    if (!$oldItems) throw new Exception('Transaksi tidak ditemukan.');
    $oldHeader = sale_version_header($oldItems);
    if ((int)$oldHeader['is_active_revision'] !== 1) {
      throw new Exception('Hanya versi aktif yang bisa direvisi.');
    }

    $baseCode = (string)$oldHeader['base_sale_code'];
    $stmtRev = $db->prepare("SELECT MAX(revision_no) AS max_rev FROM sales WHERE base_sale_code=? FOR UPDATE");
    $stmtRev->execute([$baseCode]);
    $maxRev = (int)($stmtRev->fetch()['max_rev'] ?? 0);
    $newRevNo = $maxRev + 1;
    $newSuffix = sale_revision_suffix($newRevNo);
    $newCode = $baseCode . $newSuffix;

    $reasonCategory = trim((string)($payload['reason_category'] ?? ''));
    $reasonText = trim((string)($payload['reason_text'] ?? ''));

    rollback_sale_stock($sourceTransactionCode, (int)$actor['id'], $reasonCategory);

    $upd = $db->prepare("UPDATE sales
      SET is_active_revision=0, revision_status='superseded', revised_by_user_id=?, revised_at=NOW(),
          revision_reason_category=?, revision_reason_text=?
      WHERE transaction_code=?");
    $upd->execute([(int)$actor['id'], $reasonCategory !== '' ? $reasonCategory : null, $reasonText !== '' ? $reasonText : null, $sourceTransactionCode]);

    $newItems = $payload['items'] ?? [];
    if (empty($newItems)) throw new Exception('Item transaksi baru wajib ada.');

    $soldAt = (string)($payload['sold_at'] ?? $oldHeader['sold_at']);
    $customerName = trim((string)($payload['customer_name'] ?? $oldHeader['customer_name']));
    $paymentMethod = trim((string)($payload['payment_method'] ?? $oldHeader['payment_method']));
    if (!in_array($paymentMethod, ['cash','qris','transfer','card'], true)) $paymentMethod = 'cash';
    $notes = trim((string)($payload['notes'] ?? $oldHeader['notes']));
    $discount = (float)($payload['discount_amount'] ?? 0);
    $tax = (float)($payload['tax_amount'] ?? 0);
    $fee = (float)($payload['extra_fee'] ?? 0);

    $subtotal = 0.0;
    foreach ($newItems as &$it) {
      $it['qty'] = (float)($it['qty'] ?? 0);
      $it['price_each'] = (float)($it['price_each'] ?? 0);
      $itemDiscount = (float)($it['item_discount'] ?? 0);
      if ($it['qty'] <= 0 || (int)($it['product_id'] ?? 0) <= 0) throw new Exception('Item tidak valid.');
      $line = max(0, ($it['qty'] * $it['price_each']) - $itemDiscount);
      $it['line_total'] = $line;
      $subtotal += $line;
    }
    unset($it);
    $grand = max(0, $subtotal - $discount + $tax + $fee);
    $branchId = (int)($oldItems[0]['branch_id'] ?? active_branch_id());

    apply_sale_stock_items($newItems, $branchId, (int)$actor['id'], $newCode);

    $ins = $db->prepare("INSERT INTO sales
      (transaction_code, base_sale_code, revision_suffix, revision_no, is_active_revision, revised_from_sale_id,
       revision_reason_category, revision_reason_text, revised_by_user_id, revised_at, revision_status, original_sale_id,
       product_id, branch_id, qty, price_each, total, discount_amount, tax_amount, extra_fee, grand_total,
       payment_method, payment_proof_path, customer_name, notes, sale_status, created_by, sold_at)
      VALUES (?,?,?,?,?,?,?,?,?,NOW(),'active',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");

    $sourceId = (int)$oldHeader['id'];
    $originalId = (int)($oldItems[0]['original_sale_id'] ?? $sourceId);
    foreach ($newItems as $it) {
      $ins->execute([
        $newCode,
        $baseCode,
        $newSuffix !== '' ? $newSuffix : null,
        $newRevNo,
        1,
        $sourceId,
        $reasonCategory !== '' ? $reasonCategory : null,
        $reasonText !== '' ? $reasonText : null,
        (int)$actor['id'],
        $originalId,
        (int)$it['product_id'],
        $branchId,
        (float)$it['qty'],
        (float)$it['price_each'],
        (float)$it['line_total'],
        $discount,
        $tax,
        $fee,
        $grand,
        $paymentMethod,
        $oldItems[0]['payment_proof_path'] ?? null,
        $customerName !== '' ? $customerName : null,
        $notes !== '' ? $notes : null,
        $oldItems[0]['sale_status'] ?? 'completed',
        (int)($oldItems[0]['created_by'] ?? $actor['id']),
        $soldAt,
      ]);
    }

    $db->commit();
    return $newCode;
  } catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    throw $e;
  }
}
