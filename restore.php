<?php
/** Backup file se data wapas laana (Settings page ka form yahan aata hai) */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/backup_lib.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('settings.php');
}
require_csrf();
@set_time_limit(600);
@ini_set('memory_limit', '512M');

$fail = function (string $msg): never {
    flash('danger', $msg);
    redirect('settings.php#restore');
};

$user = q1('SELECT * FROM users WHERE id = ?', [current_user()['id']]);
if (!$user || !password_verify($_POST['password'] ?? '', $user['password_hash'])) {
    $fail('Wrong password. Restore was not done.');
}
if (empty($_POST['confirm'])) {
    $fail('Tick the "Yes, replace current data" box.');
}

$file = $_FILES['file'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    $code = $file['error'] ?? -1;
    $fail($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE
        ? 'File is too large. Increase the hosting upload limit (upload_max_filesize).'
        : 'Upload failed. Choose the backup .sql file again.');
}
if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'sql') {
    $fail('Only a .sql backup file is accepted.');
}
$sql = file_get_contents($file['tmp_name']);
if ($sql === false || $sql === '') {
    $fail('The file is empty.');
}

try {
    // file check hone ke baad abhi wale data ki copy storage/backups mein ban jati hai
    $counts = backup_restore($sql, $safety);
} catch (RestoreError $ex) {
    $fail($ex->getMessage());
}

// Login backup wale account se hoga
logout();
redirect('login.php?restored=' . (int)$counts['products'] . '-' . (int)$counts['sales'] . ($safety ? '' : '&nosafety=1'));
