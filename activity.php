<?php
/** Kis ne kab kya kiya — sirf owner */
require __DIR__ . '/inc/bootstrap.php';
require_admin();

const ACTION_LABELS = [
    'login' => 'Login', 'login_lock' => 'Wrong password', 'product_add' => 'Product add', 'product_edit' => 'Product edit',
    'price_change' => 'Price changed', 'product_delete' => 'Product delete', 'import' => 'Excel import', 'stock_in' => 'Stock in',
    'discount' => 'Discount', 'sale_return' => 'Return', 'sale_cancel' => 'Bill cancel', 'udhaar' => 'Credit given',
    'udhaar_payment' => 'Credit payment', 'customer' => 'Customer', 'expense' => 'Expense', 'expense_delete' => 'Expense deleted',
    'withdraw' => 'Cash withdrawal', 'day_open' => 'Day opened', 'day_close' => 'Day closed', 'settings' => 'Settings',
    'staff_add' => 'New staff', 'staff_edit' => 'Staff edit', 'restore' => 'Backup restore', 'pin_override' => 'PIN approval',
    'new_product_billing' => 'New product in billing', 'limit_breach' => 'Limit broken / no PIN', 'stock_count' => 'Stock count',
];
$important = ['stock_count', 'limit_breach', 'product_delete', 'price_change', 'sale_cancel', 'sale_return', 'pin_override', 'login_lock', 'expense_delete', 'restore', 'withdraw'];

$day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day'] ?? '') ? $_GET['day'] : business_day();
$uid = (int)($_GET['user'] ?? 0);
$act = (string)($_GET['action'] ?? '');
$where = 'created_at BETWEEN ? AND ?';
$params = bday_range($day);
if ($uid) {
    $where .= ' AND user_id = ?';
    $params[] = $uid;
}
if ($act !== '' && isset(ACTION_LABELS[$act])) {
    $where .= ' AND action = ?';
    $params[] = $act;
}
$rows = q("SELECT * FROM activity_log WHERE $where ORDER BY id DESC LIMIT 500", $params);
$users = q('SELECT id, full_name, username FROM users ORDER BY full_name');

layout_start('Activity log', 'users');
?>
<div class="page-head">
    <div><h1>Activity log</h1><p class="muted">A record of every important action: who, when, what.</p></div>
    <a class="btn" href="users.php">&larr; Staff</a>
</div>
<div class="card">
    <form class="toolbar" method="get">
        <input class="input" type="date" name="day" value="<?= e($day) ?>" style="max-width:170px">
        <select class="input" name="user" style="max-width:180px">
            <option value="">All users</option>
            <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $uid === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['full_name'] ?: $u['username']) ?></option><?php endforeach; ?>
        </select>
        <select class="input" name="action" style="max-width:190px">
            <option value="">All actions</option>
            <?php foreach (ACTION_LABELS as $k => $l): ?><option value="<?= e($k) ?>" <?= $act === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
        <button class="btn" type="submit">Show</button>
    </form>
    <?php if (!$rows): ?><div class="empty">No records for this day.</div><?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Details</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td class="nowrap muted"><?= e(date('h:i A', strtotime($r['created_at']))) ?></td>
                    <td class="nowrap"><?= e($r['user_name']) ?></td>
                    <td><span class="badge <?= in_array($r['action'], $important, true) ? 'warn' : '' ?>"><?= e(ACTION_LABELS[$r['action']] ?? $r['action']) ?></span></td>
                    <td><?= e($r['details']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php layout_end();
