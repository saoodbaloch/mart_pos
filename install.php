<?php
/**
 * MartPOS Installer — sirf pehli dafa chalta hai.
 * Database ki tables banata hai, owner ka account aur config.php likhta hai.
 */
define('INSTALLING', true);
require __DIR__ . '/inc/bootstrap.php';

$installed = is_file(ROOT . '/config.php');
$errors = [];
$done = false;

$f = [
    'driver'    => $_POST['driver'] ?? 'mysql',
    'host'      => trim($_POST['host'] ?? 'localhost'),
    'port'      => trim($_POST['port'] ?? '3306'),
    'name'      => trim($_POST['name'] ?? ''),
    'user'      => trim($_POST['user'] ?? ''),
    'pass'      => $_POST['pass'] ?? '',
    'shop_name' => trim($_POST['shop_name'] ?? ''),
    'username'  => trim($_POST['username'] ?? 'owner'),
    'full_name' => trim($_POST['full_name'] ?? ''),
];

if (!$installed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password2'] ?? '';

    if ($f['driver'] === 'mysql' && ($f['name'] === '' || $f['user'] === '')) {
        $errors[] = 'Enter the database name and user.';
    }
    if ($f['shop_name'] === '') {
        $errors[] = 'Enter the shop name.';
    }
    if ($f['username'] === '') {
        $errors[] = 'Enter a username.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }
    if (!is_writable(ROOT)) {
        $errors[] = 'Folder is not writable (cannot create config.php). Set the folder permission to 755 in the hosting File Manager.';
    }

    if (!$errors) {
        $dbc = $f['driver'] === 'sqlite'
            ? ['driver' => 'sqlite', 'path' => ROOT . '/storage/martpos.sqlite']
            : ['driver' => 'mysql', 'host' => $f['host'], 'port' => (int)$f['port'], 'name' => $f['name'], 'user' => $f['user'], 'pass' => $f['pass']];
        try {
            $pdo = db($dbc);
            foreach (schema_sql($dbc['driver']) as $stmt) {
                try {
                    $pdo->exec($stmt);
                } catch (PDOException $ex) {
                    // index pehle se ho to MySQL error deta hai, ignore
                    if (!str_contains($ex->getMessage(), 'Duplicate key name')) {
                        throw $ex;
                    }
                }
            }
            run_migrations($pdo);
            if ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
                $errors[] = 'MartPOS is already installed in this database. Use a new empty database.';
            } else {
                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO users (username, full_name, role, active, password_hash, created_at) VALUES (?,?,'admin',1,?,?)")
                    ->execute([$f['username'], $f['full_name'] ?: $f['username'], password_hash($password, PASSWORD_DEFAULT), now()]);
                $ins = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?)');
                $settings = default_settings();
                $settings['shop_name'] = $f['shop_name'];
                foreach ($settings as $k => $v) {
                    $ins->execute([$k, $v]);
                }
                $pdo->commit();

                $config = [
                    'db'       => $dbc,
                    'timezone' => 'Asia/Karachi',
                    'debug'    => false,
                ];
                $php = "<?php\n// MartPOS config — created by install.php. Do not share this file.\nreturn " . var_export($config, true) . ";\n";
                if (file_put_contents(ROOT . '/config.php', $php) === false) {
                    $errors[] = 'Could not write config.php.';
                } else {
                    @chmod(ROOT . '/config.php', 0640);
                    $done = true;
                }
            }
        } catch (Throwable $ex) {
            $errors[] = 'Could not connect to the database: ' . $ex->getMessage();
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>MartPOS Install</title>
<link rel="stylesheet" href="assets/css/app.css?v=<?= APP_VERSION ?>">
</head>
<body class="auth-page">
<main class="auth-card wide">
    <div class="brand-mark">M</div>
    <h1>MartPOS Install</h1>

    <?php if ($installed && !$done): ?>
        <p class="muted">MartPOS is already installed.</p>
        <p class="muted">To reinstall, delete <code>config.php</code> from the server.</p>
        <a class="btn primary block" href="login.php">Login page</a>

    <?php elseif ($done): ?>
        <div class="alert ok">Installation complete!</div>
        <p>Now log in with your username <b><?= e($f['username']) ?></b> and password.</p>
        <p class="muted small">For security, now delete <code>install.php</code> from the server.</p>
        <a class="btn primary block" href="login.php">Log in</a>

    <?php else: ?>
        <?php foreach ($errors as $err): ?>
            <div class="alert danger"><?= e($err) ?></div>
        <?php endforeach; ?>

        <form method="post" class="stack" autocomplete="off">
            <fieldset>
                <legend>1. Database</legend>
                <label class="field">Type
                    <select name="driver" id="driver">
                        <option value="mysql" <?= $f['driver'] === 'mysql' ? 'selected' : '' ?>>MySQL (for hosting)</option>
                        <option value="sqlite" <?= $f['driver'] === 'sqlite' ? 'selected' : '' ?>>SQLite (testing only)</option>
                    </select>
                </label>
                <div id="mysqlFields" class="stack">
                    <div class="grid-2">
                        <label class="field">Host <input name="host" value="<?= e($f['host']) ?>"></label>
                        <label class="field">Port <input name="port" value="<?= e($f['port']) ?>" inputmode="numeric"></label>
                    </div>
                    <label class="field">Database name <input name="name" value="<?= e($f['name']) ?>" placeholder="e.g. user_martpos"></label>
                    <div class="grid-2">
                        <label class="field">Database user <input name="user" value="<?= e($f['user']) ?>"></label>
                        <label class="field">Database password <input type="password" name="pass" value=""></label>
                    </div>
                    <p class="muted small">You will find these in your hosting cPanel &rarr; MySQL Databases.</p>
                </div>
            </fieldset>

            <fieldset>
                <legend>2. Shop and Owner (Admin)</legend>
                <label class="field">Shop name <input name="shop_name" value="<?= e($f['shop_name']) ?>" required></label>
                <div class="grid-2">
                    <label class="field">Your name <input name="full_name" value="<?= e($f['full_name']) ?>" placeholder="e.g. Saood"></label>
                    <label class="field">Username <input name="username" value="<?= e($f['username']) ?>" required></label>
                </div>
                <div class="grid-2">
                    <label class="field">Password <input type="password" name="password" required minlength="6"></label>
                    <label class="field">Confirm password <input type="password" name="password2" required minlength="6"></label>
                </div>
            </fieldset>

            <button class="btn primary block lg" type="submit">Install</button>
        </form>
        <script>
        (function () {
            var d = document.getElementById('driver'), m = document.getElementById('mysqlFields');
            function t() { m.style.display = d.value === 'mysql' ? '' : 'none'; }
            d.addEventListener('change', t); t();
        })();
        </script>
    <?php endif; ?>
</main>
</body>
</html>
