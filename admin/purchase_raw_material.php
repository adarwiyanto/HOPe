<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/inventory.php';

start_secure_session(); require_admin(); ensure_inventory_module_schema();
$err = ''; $u = current_user();
$branches = inventory_branches();
$suppliers = db()->query("SELECT id,supplier_name FROM suppliers WHERE is_active=1 ORDER BY supplier_name ASC")->fetchAll();
$materials = db()->query("SELECT id,name FROM products WHERE product_type='raw_material' ORDER BY name ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 csrf_check();
 $action = (string)($_POST['action'] ?? 'create');
 try {
  if ($action === 'create') {
    $purchaseNo = trim((string)($_POST['purchase_no'] ?? ''));
    $branchId = (int)($_POST['branch_id'] ?? active_branch_id());
    $supplierId = (int)($_POST['supplier_id'] ?? 0);
    $date = (string)($_POST['purchase_date'] ?? date('Y-m-d'));
    $productId = (int)($_POST['product_id'] ?? 0);
    $qty = (float)($_POST['qty'] ?? 0);
    $unitCost = (float)($_POST['unit_cost'] ?? 0);
    if ($purchaseNo === '') throw new Exception('Nomor purchase wajib.');
    if ($supplierId <= 0 || $productId <= 0 || $qty <= 0 || $unitCost < 0) throw new Exception('Data pembelian tidak valid.');
    $lineTotal = $qty * $unitCost;

    $db = db(); $db->beginTransaction();
    $stmt = $db->prepare("INSERT INTO purchase_headers (branch_id,supplier_id,purchase_no,purchase_date,status,subtotal,grand_total,created_by) VALUES (?,?,?,?, 'draft',?,?,?)");
    $stmt->execute([$branchId, $supplierId, $purchaseNo, $date, $lineTotal, $lineTotal, (int)($u['id'] ?? 0)]);
    $purchaseId = (int)$db->lastInsertId();
    $stmt = $db->prepare("INSERT INTO purchase_items (purchase_id,product_id,qty,unit_cost,line_total) VALUES (?,?,?,?,?)");
    $stmt->execute([$purchaseId, $productId, $qty, $unitCost, $lineTotal]);
    $db->commit();
    redirect(base_url('admin/purchase_raw_material.php'));
  }
  if ($action === 'post') {
    $id = (int)($_POST['id'] ?? 0);
    $db = db(); $db->beginTransaction();
    $stmt = $db->prepare("SELECT * FROM purchase_headers WHERE id=? LIMIT 1 FOR UPDATE"); $stmt->execute([$id]); $h = $stmt->fetch();
    if (!$h) throw new Exception('Dokumen tidak ditemukan.');
    if ($h['status'] !== 'draft') throw new Exception('Hanya draft yang bisa diposting.');
    $stmt = $db->prepare("SELECT pi.*, p.product_type FROM purchase_items pi JOIN products p ON p.id=pi.product_id WHERE pi.purchase_id=?"); $stmt->execute([$id]); $items = $stmt->fetchAll();
    if (empty($items)) throw new Exception('Item kosong.');
    foreach ($items as $it) {
      if (($it['product_type'] ?? '') !== 'raw_material') throw new Exception('Pembelian ini hanya untuk raw material.');
      add_stock_ledger(['branch_id'=>(int)$h['branch_id'],'product_id'=>(int)$it['product_id'],'trans_type'=>'purchase_post','ref_table'=>'purchase_headers','ref_id'=>$id,'qty_in'=>(float)$it['qty'],'qty_out'=>0,'unit_cost'=>(float)$it['unit_cost'],'note'=>'Posting pembelian','created_by'=>(int)($u['id'] ?? 0)]);
    }
    $stmt = $db->prepare("UPDATE purchase_headers SET status='posted', posted_by=?, posted_at=NOW() WHERE id=?"); $stmt->execute([(int)($u['id'] ?? 0), $id]);
    $db->commit(); redirect(base_url('admin/purchase_raw_material.php'));
  }
  if ($action === 'cancel') {
    $id = (int)($_POST['id'] ?? 0);
    $db = db(); $db->beginTransaction();
    $stmt = $db->prepare("SELECT * FROM purchase_headers WHERE id=? LIMIT 1 FOR UPDATE"); $stmt->execute([$id]); $h = $stmt->fetch();
    if (!$h) throw new Exception('Dokumen tidak ditemukan.');
    if ($h['status'] === 'cancelled') throw new Exception('Sudah cancelled.');
    if ($h['status'] === 'posted') {
      $stmt = $db->prepare("SELECT * FROM purchase_items WHERE purchase_id=?"); $stmt->execute([$id]);
      foreach ($stmt->fetchAll() as $it) {
        add_stock_ledger(['branch_id'=>(int)$h['branch_id'],'product_id'=>(int)$it['product_id'],'trans_type'=>'purchase_cancel','ref_table'=>'purchase_headers','ref_id'=>$id,'qty_in'=>0,'qty_out'=>(float)$it['qty'],'unit_cost'=>(float)$it['unit_cost'],'note'=>'Reversal cancel pembelian','created_by'=>(int)($u['id'] ?? 0)]);
      }
    }
    $stmt = $db->prepare("UPDATE purchase_headers SET status='cancelled' WHERE id=?"); $stmt->execute([$id]);
    $db->commit(); redirect(base_url('admin/purchase_raw_material.php'));
  }
 } catch (Throwable $e) { if (isset($db) && $db->inTransaction()) $db->rollBack(); $err = $e->getMessage(); }
}

$docs = db()->query("SELECT ph.*, b.branch_name, s.supplier_name FROM purchase_headers ph JOIN branches b ON b.id=ph.branch_id JOIN suppliers s ON s.id=ph.supplier_id ORDER BY ph.id DESC LIMIT 100")->fetchAll();
$customCss = setting('custom_css', '');
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pembelian Bahan Baku</title>
<link rel="icon" href="<?php echo e(favicon_url()); ?>"><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"><style><?php echo $customCss; ?></style></head>
<body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button></div><div class="content">
<div class="card"><h3>Pembelian Bahan Baku</h3><?php if ($err): ?><div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div><?php endif; ?>
<form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="create">
<div class="grid cols-2"><div class="row"><label>No Purchase</label><input name="purchase_no" value="PO-<?php echo e(date('YmdHis')); ?>" required></div><div class="row"><label>Tanggal</label><input type="date" name="purchase_date" value="<?php echo e(date('Y-m-d')); ?>" required></div></div>
<div class="grid cols-2"><div class="row"><label>Cabang</label><select name="branch_id"><?php foreach($branches as $b): ?><option value="<?php echo e((string)$b['id']); ?>" <?php echo (int)$b['id']===active_branch_id()?'selected':''; ?>><?php echo e($b['branch_name']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Supplier</label><select name="supplier_id" required><?php foreach($suppliers as $s): ?><option value="<?php echo e((string)$s['id']); ?>"><?php echo e($s['supplier_name']); ?></option><?php endforeach; ?></select></div></div>
<div class="grid cols-3"><div class="row"><label>Material</label><select name="product_id" required><?php foreach($materials as $m): ?><option value="<?php echo e((string)$m['id']); ?>"><?php echo e($m['name']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Qty</label><input type="number" min="0.0001" step="0.0001" name="qty" required></div><div class="row"><label>Unit Cost</label><input type="number" min="0" step="0.01" name="unit_cost" required></div></div>
<button class="btn" type="submit">Simpan Draft</button></form></div>
<div class="card"><table class="table"><thead><tr><th>No</th><th>Tanggal</th><th>Cabang</th><th>Supplier</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead><tbody><?php foreach($docs as $d): ?><tr><td><?php echo e($d['purchase_no']); ?></td><td><?php echo e($d['purchase_date']); ?></td><td><?php echo e($d['branch_name']); ?></td><td><?php echo e($d['supplier_name']); ?></td><td><?php echo e(number_format((float)$d['grand_total'],2,'.',',')); ?></td><td><?php echo e($d['status']); ?></td><td style="display:flex;gap:6px"><?php if($d['status']==='draft'): ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="post"><input type="hidden" name="id" value="<?php echo e((string)$d['id']); ?>"><button class="btn" type="submit">Post</button></form><?php endif; ?><?php if($d['status']!=='cancelled'): ?><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?php echo e((string)$d['id']); ?>"><button class="btn danger" type="submit">Cancel</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
</div></div></div></div><script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
