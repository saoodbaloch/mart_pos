<?php
/** Dukaan ke apne product ke liye naya EAN-13 barcode */
require dirname(__DIR__) . '/inc/bootstrap.php';
require_admin();
require_csrf();
json_out(['barcode' => generate_internal_barcode()]);
