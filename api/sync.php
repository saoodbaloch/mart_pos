<?php
/**
 * Billing screen ka outbox yahan aata hai: naye bills aur naye products.
 * Ek hi cheez do dafa aaye to dobara save nahi hoti.
 */
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/sales_lib.php';
require_perm('billing');
require_csrf();

$in = json_input();
$kind = $in['kind'] ?? '';
$payload = is_array($in['payload'] ?? null) ? $in['payload'] : [];

try {
    if ($kind === 'sale') {
        $r = create_sale($payload);
        json_out(['ok' => true, 'sale_id' => $r['id'], 'duplicate' => $r['duplicate']]);
    }
    if ($kind === 'product') {
        $r = quick_create_product($payload);
        json_out(['ok' => true, 'product_id' => $r['id'], 'existing' => $r['existing']]);
    }
    json_out(['ok' => false, 'message' => 'Unknown kind'], 422);
} catch (SaleError $ex) {
    json_out(['ok' => false, 'message' => $ex->getMessage()], 422);
} catch (Throwable $ex) {
    error_log('MartPOS sync: ' . $ex->getMessage());
    json_out(['ok' => false, 'message' => 'Server error, will retry.'], 500);
}
