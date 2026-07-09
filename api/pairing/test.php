<?php
require_once __DIR__ . '/../../core/api_pairing.php';
$conn=hope_pairing_auth('readonly');
hope_pairing_ok(['message'=>'Koneksi pairing HOPe aktif.','connection_id'=>(int)$conn['id'],'scope'=>$conn['access_scope'],'system'=>'hope']);
