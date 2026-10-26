<?php
/**
 * Billing ke liye products ki list.
 * ?since=... diya ho to sirf us waqt ke baad badle hue (delete samet).
 */
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/products_lib.php';
require dirname(__DIR__) . '/inc/backup_lib.php';
require_login();

$serverTime = now();
$since = $_GET['since'] ?? '';
$dv = data_version();
// Restore ke baad (data_version badla) poori list dobara
$full = !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $since) || ($_GET['v'] ?? '') !== $dv;

if ($full) {
    $rows = q('SELECT * FROM products WHERE deleted_at IS NULL');
} else {
    $rows = q('SELECT * FROM products WHERE updated_at >= ?', [$since]);
}
json_out([
    'full' => $full,
    'data_version' => $dv,
    'server_time' => $serverTime,
    'items' => array_map('product_public', $rows),
]);
