<?php
/** Naam ya barcode se products dhoondo (labels / stock pages ke liye) */
require dirname(__DIR__) . '/inc/bootstrap.php';
require_login();

$s = trim($_GET['q'] ?? '');
if ($s === '') {
    json_out(['items' => []]);
}
$rows = q('SELECT id, barcode, name, unit, sale_price, purchase_price, stock FROM products
           WHERE deleted_at IS NULL AND (barcode = ? OR name LIKE ?) ORDER BY (barcode = ?) DESC, name LIMIT 12',
    [$s, '%' . $s . '%', $s]);
json_out(['items' => array_map(fn($p) => [
    'id' => (int)$p['id'], 'barcode' => $p['barcode'], 'name' => $p['name'], 'unit' => $p['unit'],
    'sale_price' => (float)$p['sale_price'], 'purchase_price' => is_admin() ? (float)$p['purchase_price'] : null,
    'price' => money($p['sale_price']), 'stock' => qty($p['stock']),
], $rows)]);
