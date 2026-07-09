<?php
require_once __DIR__ . '/../../core/api_pairing.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST') hope_pairing_err('Method tidak diizinkan',405);
ensure_hope_integration_schema(); $in=hope_pairing_input();
$requesterName=trim((string)($in['requester_name']??'Dapur'));
$requesterType=trim((string)($in['requester_type']??'dapur'));
$requesterUrl=hope_normalize_url((string)($in['requester_base_url']??''));
$code=trim((string)($in['request_code']??hope_request_code('PAIR')));
$secretHash=trim((string)($in['request_secret_hash']??''));
$callback=hope_normalize_url((string)($in['callback_url']??''));
if($requesterUrl==='') hope_pairing_err('Requester base URL wajib diisi.',422);
$scope=hope_pairing_scope_for($requesterType,'hope');
$exists=db()->prepare('SELECT id,status FROM api_pairing_requests WHERE request_code=? LIMIT 1'); $exists->execute([$code]); $old=$exists->fetch(PDO::FETCH_ASSOC);
if($old) hope_pairing_ok(['message'=>'Request pairing sudah tercatat.','request_code'=>$code,'status'=>$old['status']]);
$st=db()->prepare("INSERT INTO api_pairing_requests(direction,request_code,request_secret_hash,requester_name,requester_type,requester_base_url,target_type,requested_scope,status,callback_url,expires_at,created_at) VALUES('incoming',?,?,?,?,?,?,?,'pending',?,DATE_ADD(NOW(), INTERVAL 48 HOUR),NOW())");
$st->execute([$code,$secretHash,$requesterName,$requesterType,$requesterUrl,'hope',$scope,$callback]);
hope_pairing_ok(['message'=>'Permintaan koneksi diterima. Menunggu approval di HOPe.','request_code'=>$code,'status'=>'pending','requested_scope'=>$scope]);
