<?php
/** Poore database ka .sql backup. Wapas laane ke liye: Settings -> Backup restore */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/backup_lib.php';
require_admin();
@set_time_limit(300);

// Server par rakhi file download
if (isset($_GET['file'])) {
    $name = basename((string)$_GET['file']);
    $path = ROOT . '/storage/backups/' . $name;
    if (!preg_match('/^[a-z-]+-\d{8}-\d{6}\.sql$/', $name) || !is_file($path)) {
        http_response_code(404);
        exit('File not found.');
    }
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="martpos-backup-' . date('Ymd-Hi') . '.sql"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
backup_dump($out);
fclose($out);
