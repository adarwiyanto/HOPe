<?php
require_once __DIR__ . '/../../../core/api_pairing.php';
require_once __DIR__ . '/../../../core/inventory.php';
ensure_hope_integration_schema(); ensure_inventory_module_schema(); $conn=hope_pairing_auth('stock_transfer.write');
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST') hope_pairing_err('Method tidak diizinkan',405);
$in=hope_pairing_input(); $transferNo=trim((string)($in['transfer_no']??'')); $items=is_array($in['items']??null)?$in['items']:[]; $dryRun=!empty($in['dry_run']);
if($transferNo==='') hope_pairing_err('transfer_no wajib diisi.',422); if(!$items) hope_pairing_err('Item transfer kosong.',422);
$valid=[]; foreach($items as $it){ if(!is_array($it)) continue; $name=trim((string)($it['name']??'')); $qty=(float)($it['qty']??0); if($name!=='' && $qty>0) $valid[]=$it; }
if(!$valid) hope_pairing_err('Item transfer tidak valid.',422);
if($dryRun){
  hope_api_log_event(null,'api/v1/kitchen/receive-transfer.php','in','dryrun_receive_ok','Dry-run transfer valid. Stok tidak berubah.',['transfer_no'=>$transferNo,'items'=>$valid,'connection_id'=>(int)$conn['id']]);
  hope_pairing_ok(['message'=>'Dry-run transfer valid. Stok HOPe tidak berubah.','status'=>'dry_run_ok','transfer_no'=>$transferNo,'total_items'=>count($valid)]);
}
$hash=hash('sha256', json_encode($in, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
$db=db(); $chk=$db->prepare('SELECT * FROM integration_stock_transfers WHERE payload_hash=? OR transfer_no=? LIMIT 1'); $chk->execute([$hash,$transferNo]); if($old=$chk->fetch(PDO::FETCH_ASSOC)) hope_pairing_ok(['message'=>'Transfer sudah pernah diterima.','status'=>$old['status'],'duplicate'=>true]);
try{
 $db->beginTransaction(); $st=$db->prepare('INSERT INTO integration_stock_transfers(transfer_no,source_system,source_base_url,payload_hash,status,notes,created_by_connection_id) VALUES(?,?,?,?,?,?,?)'); $st->execute([$transferNo,'dapur',(string)($conn['remote_base_url']??''),$hash,'posted',trim((string)($in['notes']??'')),(int)$conn['id']]); $tid=(int)$db->lastInsertId();
 foreach($valid as $it){ $name=trim((string)($it['name']??'')); $sku=trim((string)($it['sku']??'')); $qty=(float)($it['qty']??0); $type=(string)($it['item_type']??$it['product_type']??'finished_good'); $type=($type==='raw'||$type==='raw_material')?'raw_material':'finished_good'; $unit=trim((string)($it['unit']??'pcs')); $category=trim((string)($it['category']??'Kiriman Dapur'));
  $find=$db->prepare('SELECT * FROM products WHERE name=? AND product_type=? LIMIT 1'); $find->execute([$name,$type]); $p=$find->fetch(PDO::FETCH_ASSOC);
  if(!$p){ $ins=$db->prepare("INSERT INTO products(name,category,price,product_type,track_stock,show_on_pos,show_on_landing,base_unit,purchase_unit,sale_unit) VALUES(?,?,?,?,1,?,?,?,?,?)"); $show=$type==='finished_good'?1:0; $ins->execute([$name,$category,(float)($it['transfer_price']??0),$type,$show,$show,$unit,$unit,$unit]); $pid=(int)$db->lastInsertId(); }
  else { $pid=(int)$p['id']; }
  add_stock_ledger(['branch_id'=>active_branch_id(),'product_id'=>$pid,'trans_type'=>'dapur_transfer_in','ref_table'=>'integration_stock_transfers','ref_id'=>$tid,'qty_in'=>$qty,'qty_out'=>0,'unit_cost'=>isset($it['transfer_price'])?(float)$it['transfer_price']:null,'note'=>'Transfer dari Dapur '.$transferNo,'created_by'=>null]);
 }
 $db->commit(); hope_api_log_event(null,'api/v1/kitchen/receive-transfer.php','in','transfer_received','Transfer stok Dapur diterima dan stok bertambah.',['transfer_no'=>$transferNo,'total_items'=>count($valid),'transfer_id'=>$tid]); hope_pairing_ok(['message'=>'Transfer stok Dapur diterima dan stok HOPe langsung bertambah.','status'=>'posted','transfer_no'=>$transferNo,'total_items'=>count($valid)]);
}catch(Throwable $e){ if($db->inTransaction()) $db->rollBack(); hope_api_log_event(null,'api/v1/kitchen/receive-transfer.php','in','transfer_receive_failed',$e->getMessage(),['transfer_no'=>$transferNo,'payload'=>$in]); hope_pairing_err('Gagal menerima transfer: '.$e->getMessage(),500); }
