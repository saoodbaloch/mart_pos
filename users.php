<?php
/** Staff management — sirf owner (admin) */
require __DIR__ . '/inc/bootstrap.php';
require_admin();

$me = current_user();
$editId = isset($_GET['id']) ? (int)$_GET['id'] : null;
$isNew = isset($_GET['new']);
$edit = $editId ? q1('SELECT * FROM users WHERE id = ?', [$editId]) : null;
if ($editId && !$edit) {
    flash('danger', 'User not found.');
    redirect('users.php');
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $name = trim($_POST['full_name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $role = ($_POST['role'] ?? 'staff') === 'admin' ? 'admin' : 'staff';
    $active = !empty($_POST['active']) ? 1 : 0;
    $password = $_POST['password'] ?? '';
    $pin = trim($_POST['pin'] ?? '');

    if ($name === '') {
        $errors[] = 'Enter the name.';
    }
    if ((!$edit || $username !== $edit['username']) && !preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $username)) {
        $errors[] = 'Username must be 3-40 English letters/numbers (no spaces).';
    }
    $dup = q1('SELECT id FROM users WHERE username = ?', [$username]);
    if ($dup && (!$edit || (int)$dup['id'] !== (int)$edit['id'])) {
        $errors[] = 'This username is already taken.';
    }
    if (!$edit && strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($edit && $password !== '' && strlen($password) < 6) {
        $errors[] = 'New password must be at least 6 characters.';
    }
    if ($pin !== '' && !valid_pin_format($pin)) {
        $errors[] = 'PIN must be 4 to 8 digits.';
    } elseif ($pin !== '' && (preg_match('/^(\d)\1+$/', $pin) || str_contains('0123456789', $pin) || str_contains('9876543210', $pin))) {
        $errors[] = 'This PIN is too easy (like 1111 or 1234). Choose another.';
    }
    if ($edit && (int)$edit['id'] === $me['id'] && (!$active || $role !== 'admin')) {
        $errors[] = 'You cannot disable your own admin account or make it staff.';
    }
    if ($edit && $edit['role'] === 'admin' && ($role !== 'admin' || !$active)) {
        $admins = (int)qv("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1 AND id <> ?", [$edit['id']]);
        if ($admins < 1) {
            $errors[] = 'There must be at least one active admin.';
        }
    }

    if (!$errors) {
        if ($edit) {
            qexec('UPDATE users SET full_name = ?, username = ?, role = ?, active = ? WHERE id = ?',
                [$name, $username, $role, $active, $edit['id']]);
            if ($password !== '') {
                qexec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $edit['id']]);
            }
            $uid = (int)$edit['id'];
            $what = [];
            if ($edit['role'] !== $role) {
                $what[] = 'role ' . $role;
            }
            if ((int)$edit['active'] !== $active) {
                $what[] = $active ? 'account enabled' : 'account disabled';
            }
            if ($password !== '') {
                $what[] = 'password changed';
            }
            log_activity('staff_edit', $name . ($what ? ': ' . implode(', ', $what) : ''));
        } else {
            qexec('INSERT INTO users (username, full_name, role, active, password_hash, created_at) VALUES (?,?,?,?,?,?)',
                [$username, $name, $role, $active, password_hash($password, PASSWORD_DEFAULT), now()]);
            $uid = (int)db()->lastInsertId();
            log_activity('staff_add', $name . ' (' . $role . ')');
        }
        if ($role === 'admin' && $pin !== '') {
            set_user_pin($uid, $pin);
            log_activity('staff_edit', $name . ': PIN set');
        } elseif ($role !== 'admin' || !empty($_POST['remove_pin'])) {
            set_user_pin($uid, null);
        }
        flash('ok', $edit ? 'Saved.' : 'New account created: ' . $username);
        redirect('users.php');
    }
}

$users = q('SELECT * FROM users ORDER BY active DESC, role, full_name, username');
$todaySales = [];
foreach (q("SELECT user_id, COUNT(*) AS n, SUM(total) AS t FROM sales WHERE status = 'completed' AND created_at >= ? GROUP BY user_id", [bday_range(business_day())[0]]) as $r) {
    $todaySales[(int)$r['user_id']] = $r;
}
$form = $edit ?? ($isNew || $errors ? [] : null);
$v = fn($k, $d = '') => e($_POST[$k] ?? ($edit[$k] ?? $d));

layout_start('Staff', 'users');
?>
<div class="page-head">
    <div>
        <h1>Staff</h1>
        <p class="muted">Everyone works with their own login, so you always know who did what.</p>
    </div>
    <div class="row">
        <a class="btn" href="activity.php">Activity log</a>
        <a class="btn primary" href="users.php?new=1">+ New staff</a>
    </div>
</div>

<?php foreach ($errors as $err): ?><div class="alert danger"><?= e($err) ?></div><?php endforeach; ?>

<div class="grid-2" style="grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);align-items:start">
    <div class="card">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Name</th><th>Role</th><th class="num">Today's sales</th><th>Last login</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($users as $u): $ts = $todaySales[(int)$u['id']] ?? null; ?>
                    <tr class="<?= (int)$u['active'] ? '' : 'is-cancelled' ?>">
                        <td><b><?= e($u['full_name'] ?: $u['username']) ?></b><br><span class="muted small mono"><?= e($u['username']) ?></span></td>
                        <td>
                            <span class="badge <?= $u['role'] === 'admin' ? 'ok' : '' ?>"><?= $u['role'] === 'admin' ? 'Admin' : 'Staff' ?></span>
                            <?php if ($u['role'] === 'admin' && !$u['pin_hash']): ?><br><span class="badge warn" title="Needed to approve staff actions">No PIN</span><?php endif; ?>
                            <?php if (!(int)$u['active']): ?><br><span class="badge danger">Disabled</span><?php endif; ?>
                        </td>
                        <td class="num"><?= $ts ? money($ts['t']) . '<br><span class="muted small">' . (int)$ts['n'] . ' bills</span>' : '<span class="muted">—</span>' ?></td>
                        <td class="small muted nowrap"><?= $u['last_login'] ? e(date('d-m h:i A', strtotime($u['last_login']))) : '—' ?></td>
                        <td class="actions"><a class="btn sm" href="users.php?id=<?= (int)$u['id'] ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($form !== null): ?>
    <form method="post" class="card card-pad stack" autocomplete="off">
        <?= csrf_field() ?>
        <h2><?= $edit ? 'Edit: ' . e($edit['full_name'] ?: $edit['username']) : 'New staff' ?></h2>
        <label class="field">Full name (shown on bills) <input name="full_name" value="<?= $v('full_name') ?>" required maxlength="80"></label>
        <div class="grid-2">
            <label class="field">Username <input name="username" value="<?= $v('username') ?>" required maxlength="40"></label>
            <label class="field">Role
                <select name="role" id="roleSel">
                    <option value="staff" <?= ($_POST['role'] ?? $edit['role'] ?? 'staff') === 'staff' ? 'selected' : '' ?>>Staff (Cashier)</option>
                    <option value="admin" <?= ($_POST['role'] ?? $edit['role'] ?? '') === 'admin' ? 'selected' : '' ?>>Admin (Owner)</option>
                </select>
            </label>
        </div>
        <label class="field"><?= $edit ? 'New password (to change it)' : 'Password' ?>
            <input type="password" name="password" <?= $edit ? '' : 'required' ?> minlength="6" autocomplete="new-password">
        </label>
        <div id="pinBox" class="stack" style="margin:0">
            <label class="field">Admin PIN (4-8 digits)
                <input type="password" name="pin" inputmode="numeric" maxlength="8" autocomplete="off" placeholder="<?= $edit && $edit['pin_hash'] ? 'PIN is set — type to change it' : 'e.g. 4826' ?>">
                <span class="hint">Used to approve staff discounts, credit above limit, or returns. A 6-digit PIN is safer. Do not share it, and avoid PINs like 1234.</span>
            </label>
            <?php if ($edit && $edit['pin_hash']): ?><label class="check"><input type="checkbox" name="remove_pin" value="1"> Remove PIN</label><?php endif; ?>
        </div>
        <label class="check"><input type="checkbox" name="active" value="1" <?= ($edit ? (int)$edit['active'] : 1) ? 'checked' : '' ?>> Account active</label>
        <p class="muted small">When staff leave, disable the account instead of deleting it — their name stays on old bills.</p>
        <div class="row">
            <button class="btn primary" type="submit">Save</button>
            <a class="btn ghost" href="users.php">Cancel</a>
        </div>
    </form>
    <script>
    (function () {
        var r = document.getElementById('roleSel'), b = document.getElementById('pinBox');
        function t() { b.hidden = r.value !== 'admin'; }
        r.addEventListener('change', t); t();
    })();
    </script>
    <?php else: ?>
    <div class="card card-pad stack">
        <h2>Who can do what</h2>
        <table class="table small">
            <tr><td>Billing, stock in, expenses, credit, day close</td><td class="nowrap">Admin + Staff</td></tr>
            <tr><td>See purchase price and profit</td><td>Admin only</td></tr>
            <tr><td>Change products / prices, delete</td><td>Admin only</td></tr>
            <tr><td>Return / cancel bill</td><td>Admin; staff need PIN*</td></tr>
            <tr><td>Reports, Settings, Backup, Staff</td><td>Admin only</td></tr>
        </table>
        <p class="muted small">* Can be turned off in Settings. When counting the drawer, staff do not see the "expected" amount — only you see the difference.</p>
    </div>
    <?php endif; ?>
</div>
<?php layout_end();
