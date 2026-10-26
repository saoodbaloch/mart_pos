<?php
require __DIR__ . '/inc/bootstrap.php';
require_login();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['shop', 'rules', 'accounts'], true) && !is_admin()) {
        deny();
    }
    if ($action === 'rules') {
        $pct = trim($_POST['staff_discount_pct'] ?? '');
        set_setting('staff_discount_pct', $pct === '' ? '' : (string)min(100, max(0, (float)$pct)));
        set_setting('pin_for_returns', !empty($_POST['pin_for_returns']) ? '1' : '0');
        $lim = trim($_POST['udhaar_limit'] ?? '');
        set_setting('udhaar_limit', $lim === '' ? '' : (string)max(0, (float)$lim));
        $old = day_start_hour();
        set_setting('day_start_hour', (string)max(0, min(12, (int)($_POST['day_start_hour'] ?? 6))));
        if ($old !== day_start_hour()) {
            log_activity('settings', 'Day change time: ' . $old . ':00 → ' . day_start_hour() . ':00');
        }
        log_activity('settings', 'Staff rules changed');
        flash('ok', 'Staff rules saved. Refresh the billing screen once.');
        redirect('settings.php');
    } elseif ($action === 'accounts') {
        foreach (['pay_jazzcash', 'pay_easypaisa', 'pay_bank'] as $k) {
            set_setting($k, mb_substr(trim($_POST[$k] ?? ''), 0, 160));
        }
        set_setting('receipt_accounts', !empty($_POST['receipt_accounts']) ? '1' : '0');
        log_activity('settings', 'Payment accounts changed');
        flash('ok', 'Payment accounts saved.');
        redirect('settings.php');
    } elseif ($action === 'shop') {
        $name = trim($_POST['shop_name'] ?? '');
        if ($name === '') {
            $errors[] = 'Shop name is required.';
        } else {
            set_setting('shop_name', mb_substr($name, 0, 80));
            set_setting('shop_address', mb_substr(trim($_POST['shop_address'] ?? ''), 0, 160));
            set_setting('shop_phone', mb_substr(trim($_POST['shop_phone'] ?? ''), 0, 40));
            set_setting('receipt_footer', mb_substr(trim($_POST['receipt_footer'] ?? ''), 0, 160));
            set_setting('shop_ntn', mb_substr(trim($_POST['shop_ntn'] ?? ''), 0, 60));
            set_setting('receipt_width', ($_POST['receipt_width'] ?? '80') === '58' ? '58' : '80');
            set_setting('currency', mb_substr(trim($_POST['currency'] ?? 'Rs'), 0, 6) ?: 'Rs');
            set_setting('low_stock', (string)max(0, (float)($_POST['low_stock'] ?? 5)));
            set_setting('label_width', (string)min(100, max(20, (float)($_POST['label_width'] ?? 38))));
            set_setting('label_height', (string)min(100, max(15, (float)($_POST['label_height'] ?? 25))));
            log_activity('settings', 'Shop / receipt settings');
            flash('ok', 'Settings saved. Refresh the billing screen once.');
            redirect('settings.php');
        }
    } elseif ($action === 'password') {
        $user = q1('SELECT * FROM users WHERE id = ?', [current_user()['id']]);
        $new = $_POST['new'] ?? '';
        if (!$user || !password_verify($_POST['current'] ?? '', $user['password_hash'])) {
            $errors[] = 'Current password is wrong.';
        } elseif (strlen($new) < 6) {
            $errors[] = 'New password must be at least 6 characters.';
        } elseif ($new !== ($_POST['confirm'] ?? '')) {
            $errors[] = 'The new passwords do not match.';
        } else {
            qexec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            $username = trim($_POST['username'] ?? '');
            if (is_admin() && $username !== '' && $username !== $user['username'] && !qv('SELECT 1 FROM users WHERE username = ? AND id <> ?', [$username, $user['id']])) {
                qexec('UPDATE users SET username = ? WHERE id = ?', [mb_substr($username, 0, 60), $user['id']]);
                $_SESSION['user']['username'] = $username;
            }
            flash('ok', 'Password changed.');
            redirect('settings.php');
        }
    }
}

require_once __DIR__ . '/inc/backup_lib.php';
$v = fn($k, $d = '') => setting($k, $d);
$admin = is_admin();
$counts = q1('SELECT (SELECT COUNT(*) FROM products WHERE deleted_at IS NULL) AS p, (SELECT COUNT(*) FROM sales) AS s');

layout_start('Settings', 'settings');
?>
<div class="page-head"><div><h1>Settings</h1></div></div>
<?php foreach ($errors as $err): ?><div class="alert danger"><?= e($err) ?></div><?php endforeach; ?>

<div class="grid-2" style="align-items:start">
    <div class="stack" style="margin:0">
        <?php if ($admin): ?>
        <form method="post" class="card card-pad stack" id="shopForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="shop">
            <h2>Shop and receipt</h2>
            <label class="field">Shop name <input name="shop_name" value="<?= e($v('shop_name')) ?>" required></label>
            <label class="field">Address <input name="shop_address" value="<?= e($v('shop_address')) ?>"></label>
            <div class="grid-2">
                <label class="field">Phone <input name="shop_phone" value="<?= e($v('shop_phone')) ?>"></label>
                <label class="field">Currency <input name="currency" value="<?= e($v('currency', 'Rs')) ?>"></label>
            </div>
            <div class="grid-2">
                <label class="field">Receipt footer message <input name="receipt_footer" value="<?= e($v('receipt_footer')) ?>"></label>
                <label class="field">NTN / STRN (optional) <input name="shop_ntn" value="<?= e($v('shop_ntn')) ?>" placeholder="printed on the receipt"></label>
            </div>
            <label class="field">Receipt paper width
                <select name="receipt_width">
                    <option value="80" <?= $v('receipt_width', '80') !== '58' ? 'selected' : '' ?>>80 mm (large roll — most marts)</option>
                    <option value="58" <?= $v('receipt_width', '80') === '58' ? 'selected' : '' ?>>58 mm (small roll / budget printer)</option>
                </select>
            </label>
            <div class="grid-3">
                <label class="field">Low stock alert <input name="low_stock" value="<?= e($v('low_stock', '5')) ?>" inputmode="decimal"><span class="hint">Default for every product</span></label>
                <label class="field">Label width (mm) <input name="label_width" value="<?= e($v('label_width', '38')) ?>" inputmode="decimal"></label>
                <label class="field">Label height (mm) <input name="label_height" value="<?= e($v('label_height', '25')) ?>" inputmode="decimal"></label>
            </div>
            <div class="row">
                <button class="btn primary" type="submit">Save</button>
                <button class="btn" type="button" id="testPrint">Test receipt print</button>
            </div>
        </form>

        <form method="post" class="card card-pad stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="rules">
            <h2>Staff rules</h2>
            <div class="grid-2">
                <label class="field">Max staff discount (%)
                    <input name="staff_discount_pct" value="<?= e($v('staff_discount_pct')) ?>" inputmode="decimal" placeholder="empty = no limit">
                    <span class="hint">Above this, the admin PIN is required.</span>
                </label>
                <label class="field">Credit limit (per customer, default)
                    <input name="udhaar_limit" value="<?= e($v('udhaar_limit', '5000')) ?>" inputmode="decimal" placeholder="empty = no limit">
                    <span class="hint">You can also set a separate limit for each customer.</span>
                </label>
            </div>
            <label class="check"><input type="checkbox" name="pin_for_returns" value="1" <?= $v('pin_for_returns', '1') === '1' ? 'checked' : '' ?>> Require admin PIN when staff return items / cancel a bill</label>
            <label class="field">Day change time (business day)
                <select name="day_start_hour">
                    <?php for ($hh = 0; $hh <= 12; $hh++): ?>
                        <option value="<?= $hh ?>" <?= day_start_hour() === $hh ? 'selected' : '' ?>><?= $hh === 0 ? 'Midnight 12:00 AM (calendar)' : $hh . ':00 AM' ?></option>
                    <?php endfor; ?>
                </select>
                <span class="hint">Mart closes at 2-3 AM? Every bill before this time counts in the previous day. E.g. with 6:00 AM, a 2 AM bill counts as "yesterday".</span>
            </label>
            <p class="muted small">Set your PIN on the Staff page in your own account. Everything is recorded in the Activity log.</p>
            <button class="btn primary" type="submit">Save</button>
        </form>

        <form method="post" class="card card-pad stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="accounts">
            <h2>Payment accounts</h2>
            <p class="muted small">When JazzCash / Easypaisa / Bank is chosen in billing, these details appear on screen so the customer sends money to the right account.</p>
            <label class="field">JazzCash <input name="pay_jazzcash" value="<?= e($v('pay_jazzcash')) ?>" placeholder="0300-1234567 (Saood Ahmed)"></label>
            <label class="field">Easypaisa <input name="pay_easypaisa" value="<?= e($v('pay_easypaisa')) ?>" placeholder="0345-1234567 (Saood Ahmed)"></label>
            <label class="field">Bank account <input name="pay_bank" value="<?= e($v('pay_bank')) ?>" placeholder="Meezan Bank · Saood Mart · PK00MEZN0000000000000000"></label>
            <label class="check"><input type="checkbox" name="receipt_accounts" value="1" <?= $v('receipt_accounts') === '1' ? 'checked' : '' ?>> Also print these accounts at the bottom of the receipt</label>
            <button class="btn primary" type="submit">Save</button>
        </form>
        <?php endif; ?>

        <form method="post" class="card card-pad stack" autocomplete="off">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="password">
            <h2><?= $admin ? 'Login and password' : 'My password' ?></h2>
            <?php if ($admin): ?><label class="field">Username <input name="username" value="<?= e(current_user()['username']) ?>"></label><?php endif; ?>
            <label class="field">Current password <input type="password" name="current" required autocomplete="current-password"></label>
            <div class="grid-2">
                <label class="field">New password <input type="password" name="new" required minlength="6" autocomplete="new-password"></label>
                <label class="field">Confirm new password <input type="password" name="confirm" required minlength="6" autocomplete="new-password"></label>
            </div>
            <button class="btn" type="submit">Change password</button>
        </form>
    </div>

    <div class="stack" style="margin:0">
        <?php if ($admin): ?>
        <div class="card card-pad stack">
            <h2>Backup</h2>
            <p class="muted">All data (<?= (int)$counts['p'] ?> products, <?= (int)$counts['s'] ?> bills) in one file. Download it once a week and keep it on a USB or Google Drive.</p>
            <a class="btn primary" href="backup.php">Download backup (.sql)</a>
            <?php $sb = server_backups(); if ($sb): ?>
                <details>
                    <summary class="small" style="cursor:pointer;font-weight:600">Backups on the server (<?= count($sb) ?>) — created daily</summary>
                    <table class="table small" style="margin-top:.5rem">
                        <?php foreach (array_slice($sb, 0, 20) as $b): ?>
                            <tr><td class="mono"><?= e($b['name']) ?></td><td class="num"><?= e(number_format($b['size'] / 1024, 0)) ?> KB</td><td class="actions"><a class="btn sm" href="backup.php?file=<?= e(urlencode($b['name'])) ?>">Download</a></td></tr>
                        <?php endforeach; ?>
                    </table>
                    <p class="muted small">These are on the hosting itself — if the hosting fails they are lost too. Download one weekly and keep it elsewhere.</p>
                </details>
            <?php endif; ?>
        </div>

        <form method="post" action="restore.php" enctype="multipart/form-data" class="card card-pad stack" id="restore"
              onsubmit="return confirm('All current data will be replaced with the backup. Are you sure?')">
            <?= csrf_field() ?>
            <h2>Restore backup</h2>
            <p class="muted">New PC or new hosting? Install MartPOS and upload your backup file here — all products, bills and settings come back.</p>
            <label class="field">Backup file (.sql) <input type="file" name="file" accept=".sql" required></label>
            <label class="field">Your password <input type="password" name="password" required autocomplete="current-password"></label>
            <label class="check"><input type="checkbox" name="confirm" value="1" required> Yes, replace current data with the backup</label>
            <p class="muted small">A copy of the current data is saved on the server before restoring. After restore, log in with the username/password from the backup.</p>
            <button class="btn danger" type="submit">Restore</button>
        </form>
        <?php endif; ?>

        <div class="card card-pad stack">
            <h2>This computer</h2>
            <p class="muted small">Saved only in this browser.</p>
            <label class="field">Counter letter
                <input id="deviceLetter" maxlength="2" placeholder="empty (if you have one counter)" style="text-transform:uppercase">
                <span class="hint">With two computers, enter "B" on the second one so bill numbers do not clash (B-261024-001).</span>
            </label>
            <div class="row"><button class="btn" type="button" id="saveDevice">Save</button><span class="small muted" id="localInfo"></span></div>
        </div>

        <div class="card card-pad stack">
            <h2>Printer setup (one time)</h2>
            <ol class="small" style="margin:0;padding-left:1.2rem">
                <li>Connect the thermal printer by USB and install its driver.</li>
                <li>In Windows Settings &rarr; Printers, make it the <b>Default printer</b>.</li>
                <li>Create a Chrome shortcut on the desktop. Right click &rarr; Properties &rarr; add this at the end of Target:<br>
                    <code style="display:block;margin-top:.3rem;word-break:break-all">--kiosk-printing --app=<?= e((is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('billing.php')) ?></code></li>
                <li>Always open billing from this shortcut. Every bill will now print directly without a dialog.</li>
            </ol>
        </div>
    </div>
</div>

<script src="assets/js/receipt.js?v=<?= APP_VERSION ?>"></script>
<script>
(function () {
    var inp = document.getElementById('deviceLetter');
    try { inp.value = localStorage.getItem('martpos.device') || ''; } catch (e) {}
    document.getElementById('saveDevice').addEventListener('click', function () {
        var v = inp.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 2);
        try { localStorage.setItem('martpos.device', v); } catch (e) {}
        inp.value = v;
        document.getElementById('localInfo').textContent = 'Saved.';
    });
    if (window.POS) POS.store.count('outbox').then(function (n) {
        document.getElementById('localInfo').textContent = n ? n + ' bill(s) on this computer waiting to sync.' : '';
    });
    var tp = document.getElementById('testPrint');
    if (tp) tp.addEventListener('click', function () {
        var f = document.getElementById('shopForm');
        Receipt.print({
            bill_no: 'TEST-001', created_at: (function (d) { var p = function (n) { return (n < 10 ? '0' : '') + n; }; return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':00'; })(new Date()),
            items: [{ name: 'Test item', unit: 'pcs', qty: 2, price: 50 }, { name: 'Sugar', unit: 'kg', qty: 1.5, price: 150 }],
            subtotal: 325, discount: 0, total: 325, paid: 500, change: 175, cashier: <?= json_encode(current_user()['name']) ?>,
            payments: [{ method: 'cash', amount: 325 }]
        }, {
            name: f.shop_name.value, address: f.shop_address.value, phone: f.shop_phone.value,
            footer: f.receipt_footer.value, currency: f.currency.value, ntn: f.shop_ntn.value, paper: f.receipt_width.value
        });
    });
})();
</script>
<?php layout_end();
