<?php
require __DIR__ . '/inc/bootstrap.php';

$next = basename((string)($_GET['next'] ?? 'billing.php'));
if (!preg_match('/^[a-z_]+\.php$/', $next)) {
    $next = 'billing.php';
}

if (current_user()) {
    redirect($next);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $res = attempt_login($_POST['username'] ?? '', $_POST['password'] ?? '');
    if ($res === true) {
        redirect($next);
    }
    $error = $res;
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f5c52">
<title>Login — <?= e(setting('shop_name', 'MartPOS')) ?></title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="assets/icons/icon-192.png">
<link rel="stylesheet" href="assets/css/app.css?v=<?= APP_VERSION ?>">
</head>
<body class="auth-page">
<main class="auth-card">
    <div class="brand-mark">M</div>
    <h1><?= e(setting('shop_name', 'MartPOS')) ?></h1>
    <p class="muted">Point of Sale</p>
    <?php if ($error): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?>
    <?php if (isset($_GET['restored']) && preg_match('/^(\d+)-(\d+)$/', $_GET['restored'], $rm)): ?>
        <div class="alert ok">Backup restored: <?= (int)$rm[1] ?> products, <?= (int)$rm[2] ?> bills. Now log in with the username/password <b>from the backup</b>.</div>
    <?php endif; ?>
    <form method="post" class="stack">
        <?= csrf_field() ?>
        <label class="field">Username
            <input name="username" required autofocus autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>">
        </label>
        <label class="field">Password
            <input type="password" name="password" required autocomplete="current-password">
        </label>
        <button class="btn primary block lg" type="submit">Login</button>
    </form>
</main>
</body>
</html>
