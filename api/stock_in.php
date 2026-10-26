<?php
/** Naya maal aaya: stock barhao (aur purchase/sale price update) */
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/products_lib.php';
require_perm('stock_in');
require_csrf();

$in = json_input();
$p = find_product((int)($in['product_id'] ?? 0));
if (!$p) {
    json_out(['ok' => false, 'message' => 'Product not found.'], 404);
}
$qtyIn = r3($in['qty'] ?? 0);
if ($qtyIn <= 0 || $qtyIn > 1000000) {
    json_out(['ok' => false, 'message' => 'Enter a valid quantity.'], 422);
}
$pdo = db();
$pdo->beginTransaction();
$fields = [];
$params = [];
foreach (is_admin() ? ['purchase_price', 'sale_price'] : [] as $f) {
    if (isset($in[$f]) && $in[$f] !== '' && is_numeric($in[$f]) && (float)$in[$f] >= 0) {
        if ($f === 'sale_price' && (float)$in[$f] <= 0) {
            continue;
        }
        $fields[] = "$f = ?";
        $params[] = r2($in[$f]);
    }
}
if ($fields) {
    $params[] = (int)$p['id'];
    qexec('UPDATE products SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
}
change_stock((int)$p['id'], $qtyIn, 'purchase', null, trim((string)($in['note'] ?? '')) ?: 'Stock received');
log_activity('stock_in', $p['name'] . ': +' . qty($qtyIn) . ($fields ? ' (price badli)' : ''));
$pdo->commit();

$p = find_product((int)$p['id']);
json_out(['ok' => true, 'product' => [
    'id' => (int)$p['id'], 'name' => $p['name'], 'stock' => qty($p['stock']), 'unit' => $p['unit'],
    'added' => qty($qtyIn), 'time' => date('h:i A'),
]]);
