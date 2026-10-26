<?php
/** Barcode pehle se mojood hai ya nahi */
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/products_lib.php';
require_login();

$code = clean_barcode($_GET['barcode'] ?? '');
$exclude = (int)($_GET['exclude'] ?? 0);
if ($code === null) {
    json_out(['exists' => false]);
}
$p = find_product_by_barcode($code);
if (!$p || (int)$p['id'] === $exclude) {
    json_out(['exists' => false]);
}
json_out(['exists' => true, 'product' => [
    'id' => (int)$p['id'], 'barcode' => $p['barcode'], 'name' => $p['name'], 'sale_price' => (float)$p['sale_price'], 'price' => money($p['sale_price']),
    'stock' => qty($p['stock']), 'unit' => $p['unit'], 'purchase_price' => is_admin() ? (float)$p['purchase_price'] : null,
]]);
