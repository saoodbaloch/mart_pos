<?php
/** Session zinda hai? naya CSRF token */
require dirname(__DIR__) . '/inc/bootstrap.php';
require_login();
json_out(['ok' => true, 'csrf' => csrf_token(), 'server_time' => now()]);
