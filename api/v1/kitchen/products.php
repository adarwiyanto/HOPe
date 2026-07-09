<?php
require_once __DIR__ . '/../../../core/api_pairing.php';
require_once __DIR__ . '/../../../core/inventory.php';
ensure_hope_integration_schema(); ensure_inventory_module_schema(); hope_pairing_auth('products.read');
$rows=db()->query("SELECT id,name,category,product_type,price,base_unit,sale_unit,purchase_unit,track_stock,show_on_pos FROM products WHERE product_type IN ('raw_material','finished_good') ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
hope_pairing_ok(['items'=>$rows]);
