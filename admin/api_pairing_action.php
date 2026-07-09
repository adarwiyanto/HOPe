<?php
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/permissions.php';
require_once __DIR__ . '/../core/api_pairing.php';
start_secure_session(); require_admin(); csrf_check(); ensure_hope_integration_schema();
$u=current_user()??[]; $uid=(int)($u['id']??0); $act=(string)($_POST['act']??'');
function hope_pair_go(string $msg=''): void { if($msg!=='') set_setting('last_integration_error',$msg); redirect(base_url('admin/dapur_connection.php')); }
try{
 if($act==='create_request'){
   $base=hope_normalize_url((string)($_POST['base_url']??'')); if($base==='') throw new RuntimeException('Website Dapur wajib diisi.');
   $code=hope_request_code('HOPE2DAPUR'); $secret=hope_secret(); $secretHash=password_hash($secret,PASSWORD_DEFAULT); $scope='products.read';
   $payload=['request_code'=>$code,'request_secret_hash'=>$secretHash,'requester_name'=>'HOPe POS System','requester_type'=>'hope','requester_base_url'=>base_url(''),'target_type'=>'dapur','requested_scope'=>$scope,'callback_url'=>base_url('api/pairing/status.php')];
   $res=hope_remote_json($base,'api/pairing/request.php',$payload,'POST');
   $ok=!empty($res['ok']);
   $status=$ok?'pending':'failed'; $message=(string)($res['message']??$res['_error']??'');
   $sql="INSERT INTO api_pairing_requests(direction,request_code,request_secret_hash,requester_name,requester_type,requester_base_url,target_name,target_type,target_base_url,requested_scope,status,callback_url,access_token_plain,last_message,created_at) VALUES('outgoing',?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())";
   db()->prepare($sql)->execute([$code,$secretHash,'HOPe POS System','hope',base_url(''),'Dapur','dapur',$base,$scope,$status,base_url('api/pairing/status.php'),$secret,$message]);
   hope_api_log_event(null,'api/pairing/request.php','out',$ok?'pair_request_sent':'pair_request_failed',$message ?: ($ok?'Request pairing terkirim':'Request pairing gagal'),['target'=>$base,'request_code'=>$code,'response'=>$res]);
   hope_pair_go($ok?'Request pairing terkirim. Approve di Dapur, lalu klik Cek Status di HOPe.':'Request gagal: '.$message);
 }
 elseif($act==='approve'){
   if(!current_user_is_owner()) throw new RuntimeException('Hanya owner yang dapat approve koneksi.');
   $id=(int)($_POST['id']??0); $r=db()->prepare("SELECT * FROM api_pairing_requests WHERE id=? AND direction='incoming' LIMIT 1"); $r->execute([$id]); $req=$r->fetch(PDO::FETCH_ASSOC); if(!$req) throw new RuntimeException('Request tidak ditemukan.');
   $token='hope_'.bin2hex(random_bytes(32)); $hash=hash('sha256',$token);
   db()->prepare("UPDATE api_pairing_requests SET status='approved', access_token_plain=?, token_hash=?, approved_by=?, approved_at=NOW(), last_message='Approved', updated_at=NOW() WHERE id=?")->execute([$token,$hash,$uid,$id]);
   db()->prepare("INSERT INTO api_connections(connection_name,connection_type,remote_system_type,remote_base_url,access_scope,token_hash,access_token_plain,status,paired_from_request_code,paired_by,paired_at) VALUES(?,?,?,?,?,?,?,?,?,?,NOW())")->execute([(string)$req['requester_name'],'incoming',(string)$req['requester_type'],(string)$req['requester_base_url'],(string)$req['requested_scope'],$hash,$token,'active',(string)$req['request_code'],$uid]);
   hope_api_log_event(null,'api/pairing/approve','in','pair_request_approved','Request pairing disetujui.',['request_code'=>$req['request_code'],'requester'=>$req['requester_base_url']]);
 }
 elseif($act==='reject'){
   if(!current_user_is_owner()) throw new RuntimeException('Hanya owner yang dapat reject koneksi.');
   $id=(int)($_POST['id']??0); db()->prepare("UPDATE api_pairing_requests SET status='rejected', reject_reason=?, rejected_by=?, rejected_at=NOW(), last_message='Rejected', updated_at=NOW() WHERE id=? AND direction='incoming'")->execute([trim((string)($_POST['reason']??'Ditolak')),$uid,$id]);
 }
 elseif($act==='check_status'){
   $id=(int)($_POST['id']??0); $st=db()->prepare("SELECT * FROM api_pairing_requests WHERE id=? AND direction='outgoing' LIMIT 1"); $st->execute([$id]); $r=$st->fetch(PDO::FETCH_ASSOC); if(!$r) throw new RuntimeException('Request keluar tidak ditemukan.');
   $secret=(string)($r['access_token_plain']??''); if($secret==='') throw new RuntimeException('Secret lokal request kosong. Kirim ulang pairing agar HOPe bisa cek status otomatis.');
   $res=hope_remote_json((string)$r['target_base_url'],'api/pairing/status.php',['request_code'=>(string)$r['request_code'],'request_secret'=>$secret],'GET');
   $status=(string)($res['status']??(!empty($res['ok'])?'pending':'failed')); $message=(string)($res['message']??$res['_error']??'');
   db()->prepare("UPDATE api_pairing_requests SET status=?, last_checked_at=NOW(), last_message=?, updated_at=NOW() WHERE id=?")->execute([$status,$message,$id]);
   if(!empty($res['ok']) && $status==='approved' && !empty($res['access_token'])){
     $token=(string)$res['access_token']; $hash=hash('sha256',$token); $scope=(string)($res['access_scope']??$r['requested_scope']??'products.read');
     db()->prepare("UPDATE api_pairing_requests SET access_token_plain=?, token_hash=?, last_message='Approved', updated_at=NOW() WHERE id=?")->execute([$token,$hash,$id]);
     db()->prepare("INSERT INTO api_connections(connection_name,connection_type,remote_system_type,remote_base_url,access_scope,token_hash,access_token_plain,status,paired_from_request_code,paired_by,paired_at) VALUES(?,?,?,?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE status='active', token_hash=VALUES(token_hash), access_token_plain=VALUES(access_token_plain), access_scope=VALUES(access_scope), updated_at=NOW()")->execute([(string)($r['target_name']?:'Dapur'),'outgoing','dapur',(string)$r['target_base_url'],$scope,$hash,$token,'active',(string)$r['request_code'],$uid]);
     hope_api_log_event(null,'api/pairing/status.php','out','pair_status_approved','Koneksi Dapur aktif.',['request_code'=>$r['request_code'],'target'=>$r['target_base_url']]);
     hope_pair_go('Koneksi Dapur aktif.');
   }
   hope_api_log_event(null,'api/pairing/status.php','out',$status==='failed'?'pair_status_failed':'pair_status_checked',$message,['request_code'=>$r['request_code'],'target'=>$r['target_base_url'],'response'=>$res]);
   hope_pair_go('Status pairing: '.$status.($message!==''?' - '.$message:''));
 }
 elseif($act==='test_connection'){
   $id=(int)($_POST['id']??0); $st=db()->prepare('SELECT * FROM api_connections WHERE id=? LIMIT 1'); $st->execute([$id]); $c=$st->fetch(PDO::FETCH_ASSOC); if(!$c) throw new RuntimeException('Koneksi tidak ditemukan.');
   $token=(string)($c['access_token_plain']??''); if($token==='') throw new RuntimeException('Token koneksi kosong. Cek ulang status pairing atau kirim ulang pairing.');
   $res=hope_remote_json((string)$c['remote_base_url'],'api/pairing/test.php',[],'GET',$token); $ok=!empty($res['ok']); $msg=(string)($res['message']??$res['_error']??'');
   db()->prepare('UPDATE api_connections SET last_test_at=NOW(),last_test_status=?,last_test_message=? WHERE id=?')->execute([$ok?'ok':'failed',$msg,$id]);
   hope_test_log($id,(string)$c['remote_base_url'],(string)$c['remote_system_type'],'api/pairing/test.php',$ok?'ok':'failed',(int)($res['_http_code']??0),$msg,$res,$uid);
   hope_api_log_event(null,'api/pairing/test.php','out',$ok?'test_connection_ok':'test_connection_failed',$msg,['connection_id'=>$id,'response'=>$res]);
   hope_pair_go($ok?'Test koneksi berhasil.':'Test koneksi gagal: '.$msg);
 }
 elseif($act==='test_receive_transfer'){
   $id=(int)($_POST['id']??0); $st=db()->prepare("SELECT * FROM api_connections WHERE id=? AND status='active' LIMIT 1"); $st->execute([$id]); $c=$st->fetch(PDO::FETCH_ASSOC); if(!$c) throw new RuntimeException('Koneksi tidak ditemukan.');
   $token=(string)($c['access_token_plain']??''); if($token==='') throw new RuntimeException('Token penerimaan kosong.');
   $payload=['dry_run'=>true,'source'=>'DAPUR_ADENA','transfer_no'=>'DRYRUN-HOPE-'.date('YmdHis').'-'.$id,'transfer_date'=>date('Y-m-d'),'items'=>[['sku'=>'DRYRUN-ITEM','name'=>'Tes Dry Run Dapur','item_type'=>'raw_material','qty'=>1,'unit'=>'pcs','transfer_price'=>0]],'notes'=>'Dry-run test receiver. Tidak mengubah stok.'];
   $res=hope_remote_json(base_url(''),'api/v1/kitchen/receive-transfer.php',$payload,'POST',$token); $ok=!empty($res['ok']); $msg=(string)($res['message']??$res['_error']??'');
   hope_test_log($id,base_url(''),'hope','api/v1/kitchen/receive-transfer.php',$ok?'ok':'failed',(int)($res['_http_code']??0),$msg,$res,$uid);
   hope_api_log_event(null,'api/v1/kitchen/receive-transfer.php','in',$ok?'dryrun_receive_ok':'dryrun_receive_failed',$msg,['request'=>$payload,'response'=>$res]);
   hope_pair_go($ok?'Test terima stok dry-run berhasil. Stok tidak berubah.':'Test terima stok gagal: '.$msg);
 }
 elseif($act==='delete_request'){
   $id=(int)($_POST['id']??0); if($id<=0) throw new RuntimeException('Request tidak valid.');
   $st=db()->prepare("SELECT * FROM api_pairing_requests WHERE id=? LIMIT 1"); $st->execute([$id]); $r=$st->fetch(PDO::FETCH_ASSOC); if(!$r) throw new RuntimeException('Request tidak ditemukan.');
   if(($r['status']??'')==='approved') db()->prepare("UPDATE api_pairing_requests SET status='cancelled', last_message='Dibatalkan dari menu HOPe', updated_at=NOW() WHERE id=?")->execute([$id]); else db()->prepare('DELETE FROM api_pairing_requests WHERE id=?')->execute([$id]);
 }
 elseif($act==='revoke_connection'){
   if(!current_user_is_owner()) throw new RuntimeException('Hanya owner yang dapat hapus/revoke koneksi.');
   $id=(int)($_POST['id']??0); db()->prepare("UPDATE api_connections SET status='revoked', revoked_by=?, revoked_at=NOW(), updated_at=NOW(), last_test_status='revoked', last_test_message='Dicabut dari menu HOPe' WHERE id=?")->execute([$uid,$id]);
   hope_api_log_event(null,'api_connections','out','connection_revoked','Koneksi dicabut.',['connection_id'=>$id]);
 }
}catch(Throwable $e){ hope_api_log_event(null,'admin/api_pairing_action.php','out','action_error',$e->getMessage(),['act'=>$act]); hope_pair_go('Error: '.$e->getMessage()); }
hope_pair_go();
