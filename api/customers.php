<?php
/** Billing (offline) ke liye customers aur un ka baqi udhaar */
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/customers_lib.php';
require_perm('billing');
json_out(['items' => customers_for_billing()]);
