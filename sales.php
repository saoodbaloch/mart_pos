<?php
require __DIR__ . '/inc/bootstrap.php';
require_perm('sales_own');

$admin = is_admin();
$me = current_user();
$from = $_GET['from'] ?? business_day();
$to = $_GET['to'] ?? $from;
if (!$admin) { // staff: sirf aaj ke apne bills
    $from = $to = business_day();
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = business_day();
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) {
    $to = $from;
}
$s = trim($_GET['s'] ?? '');
$uid = $admin ? (int)($_GET['user'] ?? 0) : $me['id'];
$method = $admin && isset(PAY_METHODS[$_GET['m'] ?? '']) ? $_GET['m'] : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 50;

$where = [];
$params = [];
if ($s !== '' && $admin) {
    $where[] = 's.bill_no LIKE ?';
    $params[] = '%' . $s . '%';
} else {
    $where[] = 's.created_at BETWEEN ? AND ?';
    array_push($params, ...bday_range($from, $to));
    if ($s !== '') {
        $where[] = 's.bill_no LIKE ?';
        $params[] = '%' . $s . '%';
    }
}
if ($uid) {
    $where[] = 's.user_id = ?';
    $params[] = $uid;
}
if ($method) {
    $where[] = 's.id IN (SELECT sale_id FROM sale_payments WHERE method = ?)';
    $params[] = $method;
}
$w = implode(' AND ', $where);
$sum = q1("SELECT COUNT(*) AS n,
           COALESCE(SUM(CASE WHEN status='completed' THEN total - returned_amount ELSE 0 END),0) AS net,
           COALESCE(SUM(CASE WHEN status='cancelled' THEN 1 ELSE 0 END),0) AS cancelled
           FROM sales s WHERE $w", $params);
$pages = max(1, (int)ceil($sum['n'] / $per));
$page = min($page, $pages);
$rows = q("SELECT s.*, c.name AS customer_name FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
           WHERE $w ORDER BY s.created_at DESC, s.id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $params);
if ($admin && isset($_GET['export'])) {
    $all = q("SELECT s.*, c.name AS customer_name FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE $w ORDER BY s.created_at", $params);
    $pays = [];
    if ($all) {
        foreach (q('SELECT sale_id, method, amount, ref FROM sale_payments WHERE sale_id IN (' . implode(',', array_map(fn($r) => (int)$r['id'], $all)) . ')') as $p) {
            $pays[(int)$p['sale_id']][] = (PAY_METHODS[$p['method']] ?? $p['method']) . ' ' . $p['amount'] . ($p['ref'] ? ' (' . $p['ref'] . ')' : '');
        }
    }
    csv_download('bills-' . $from . '_' . $to . '.csv', ['bill_no', 'time', 'cashier', 'customer', 'items', 'subtotal', 'discount', 'total', 'returned', 'status', 'payments'],
        array_map(fn($r) => [$r['bill_no'], $r['created_at'], (string)$r['cashier'], (string)$r['customer_name'], (int)$r['items_count'], (float)$r['subtotal'], (float)$r['discount'], (float)$r['total'], (float)$r['returned_amount'], $r['status'], implode(' + ', $pays[(int)$r['id']] ?? [])], $all));
}
$payBy = [];
if ($rows) {
    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
    foreach (q("SELECT sale_id, method FROM sale_payments WHERE sale_id IN ($ids) ORDER BY id") as $p) {
        $payBy[(int)$p['sale_id']][$p['method']] = PAY_METHODS[$p['method']] ?? $p['method'];
    }
}
$users = $admin ? q('SELECT id, full_name, username FROM users ORDER BY full_name') : [];

layout_start('Bills', 'sales');
?>
<div class="page-head">
    <div>
        <h1><?= $admin ? 'Bills' : 'My bills today' ?></h1>
        <p class="muted">Open a bill to reprint it<?= $admin ? ', return items or cancel it' : '' ?>.</p>
    </div>
</div>

<div class="card">
    <form class="toolbar" method="get">
        <?php if ($admin): ?>
            <label class="row small" style="gap:.4rem">From <input class="input" type="date" name="from" value="<?= e($from) ?>"></label>
            <label class="row small" style="gap:.4rem">To <input class="input" type="date" name="to" value="<?= e($to) ?>"></label>
            <select class="input" name="user" style="max-width:160px">
                <option value="">All cashiers</option>
                <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $uid === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['full_name'] ?: $u['username']) ?></option><?php endforeach; ?>
            </select>
            <select class="input" name="m" style="max-width:150px">
                <option value="">All payments</option>
                <?php foreach (PAY_METHODS as $k => $l): ?><option value="<?= e($k) ?>" <?= $method === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
            </select>
        <?php endif; ?>
        <input class="input" type="search" name="s" value="<?= e($s) ?>" placeholder="Bill number" style="max-width:160px">
        <button class="btn" type="submit">Show</button>
        <?php if ($admin): ?><a class="btn ghost" href="sales.php">Today</a>
            <a class="btn" href="sales.php?<?= e(http_build_query(array_filter(['from' => $from, 'to' => $to, 's' => $s, 'user' => $uid ?: null, 'm' => $method, 'export' => 1]))) ?>">Excel</a><?php endif; ?>
        <span class="spacer"></span>
        <span class="small"><b><?= (int)$sum['n'] ?></b> bills · Sale <b><?= e(currency()) ?> <?= money($sum['net']) ?></b><?= $sum['cancelled'] ? ' · ' . (int)$sum['cancelled'] . ' cancel' : '' ?></span>
    </form>
    <?php if (!$rows): ?>
        <div class="empty">No bills.<br><span class="small">Bills made offline will appear here once the internet is back.</span></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Bill #</th><th>Time</th><?php if ($admin): ?><th>Cashier</th><?php endif; ?><th>Payment</th><th class="num">Total</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr class="<?= $r['status'] === 'cancelled' ? 'is-cancelled' : '' ?>">
                    <td><a href="sale.php?id=<?= (int)$r['id'] ?>"><b class="mono"><?= e($r['bill_no']) ?></b></a></td>
                    <td class="nowrap"><?= e(date($admin ? 'd-m-Y h:i A' : 'h:i A', strtotime($r['created_at']))) ?></td>
                    <?php if ($admin): ?><td class="nowrap"><?= e($r['cashier'] ?? '—') ?></td><?php endif; ?>
                    <td class="small"><?= e(implode(' + ', $payBy[(int)$r['id']] ?? ['Cash'])) ?><?= $r['customer_name'] ? '<br><span class="muted">' . e($r['customer_name']) . '</span>' : '' ?></td>
                    <td class="num"><b><?= money($r['total']) ?></b></td>
                    <td>
                        <?php if ($r['status'] === 'cancelled'): ?><span class="badge danger">Cancelled</span>
                        <?php elseif ((float)$r['returned_amount'] > 0): ?><span class="badge warn">Return <?= money($r['returned_amount']) ?></span>
                        <?php else: ?><span class="badge ok">Done</span><?php endif; ?>
                    </td>
                    <td class="actions"><a class="btn sm" href="sale.php?id=<?= (int)$r['id'] ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pager($page, $pages, array_filter(['from' => $from, 'to' => $to, 's' => $s, 'user' => $admin ? $uid : null, 'm' => $method])) ?>
    <?php endif; ?>
</div>
<?php layout_end();
