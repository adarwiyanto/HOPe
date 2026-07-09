<?php
require_once __DIR__ . '/../../core/api_pairing.php';
if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST') hope_pairing_err('Method tidak diizinkan',405);
$conn=hope_pairing_auth('readonly');
$in=hope_pairing_input();
$reason=trim((string)($in['reason']??'revoked_remote')) ?: 'revoked_remote';
db()->prepare("UPDATE api_connections SET status='revoked', revoked_at=NOW(), last_test_status='revoked', last_test_message=?, updated_at=NOW() WHERE id=?")->execute(['Dicabut remote: '.$reason,(int)$conn['id']]);
if(!empty($conn['paired_from_request_code'])) db()->prepare("UPDATE api_pairing_requests SET status='cancelled', last_message='Koneksi dicabut remote', updated_at=NOW() WHERE request_code=?")->execute([(string)$conn['paired_from_request_code']]);
hope_api_log_event(null,'api/pairing/revoke.php','in','connection_revoked_remote','Koneksi dicabut remote.',['connection_id'=>(int)$conn['id'],'reason'=>$reason]);
hope_pairing_ok(['message'=>'Koneksi sudah dicabut di sisi HOPe.']);
