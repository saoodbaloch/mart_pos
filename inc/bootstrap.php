<?php
/**
 * MartPOS bootstrap: config, session, database and helpers.
 * Har page sab se pehle ye file include karta hai.
 */
declare(strict_types=1);

define('ROOT', dirname(__DIR__));
define('APP_VERSION', '1.4.1');

$configFile = ROOT . '/config.php';
if (!is_file($configFile)) {
    if (!defined('INSTALLING')) {
        header('Location: ' . (str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/') ? '../' : '') . 'install.php');
        exit;
    }
    $CONFIG = null;
} else {
    $CONFIG = require $configFile;
}

date_default_timezone_set($CONFIG['timezone'] ?? 'Asia/Karachi');
error_reporting(E_ALL);
ini_set('display_errors', ($CONFIG['debug'] ?? false) ? '1' : '0');

require __DIR__ . '/helpers.php';
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/layout.php';

// Security headers (har server par)
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

// Purana version ho to database khud upgrade
if ($CONFIG) {
    run_migrations();
}

// Session: app ka apna folder, taake shared hosting par session jaldi khatam na ho
$sessDir = ROOT . '/storage/sessions';
if (is_dir($sessDir) && is_writable($sessDir)) {
    session_save_path($sessDir);
}
$lifetime = 60 * 60 * 24 * 30; // 30 din
ini_set('session.gc_maxlifetime', (string)$lifetime);
session_name('martpos');
session_set_cookie_params([
    'lifetime' => $lifetime,
    'path'     => app_base_path(),
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => is_https(),
]);
session_start();
