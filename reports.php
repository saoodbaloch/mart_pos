<?php
require __DIR__ . '/inc/bootstrap.php';
require_admin();

$today = business_day();
$tt = strtotime($today);
$presets = [
    'today'     => ['Today', $today, $today],
    'yesterday' => ['Yesterday', date('Y-m-d', $tt - 86400), date('Y-m-d', $tt - 86400)],
    'week'      => ['Last 7 days', date('Y-m-d', $tt - 6 * 86400), $today],
    'month'     => ['This month', date('Y-m-01', $tt), $today],
    'lastmonth' => ['Last month', date('Y-m-01', strtotime('first day of last month', $tt)), date('Y-m-t', strtotime('last day of last month', $tt))],
];
$p = $_GET['p'] ?? 'today';
if (isset($presets[$p])) {
    [, $from, $to] = $presets[$p];
} else {
    $p = 'custom';
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : $today;
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : $from;
    if ($to < $from) {
        [$from, $to] = [$to, $from];
    }
}
[$a, $b] = bday_range($from, $to);
$bd = bday_sql('s.created_at');
$bdPlain = bday_sql('created_at');

$sales = q1("SELECT COUNT(*) AS bills, COALESCE(SUM(total),0) AS gross, COALESCE(SUM(discount),0) AS disc
             FROM sales WHERE status='completed' AND created_at BETWEEN ? AND ?", [$a, $b]);
$cost = (float)qv("SELECT COALESCE(SUM(i.qty * i.cost),0) FROM sale_items i JOIN sales s ON s.id = i.sale_id
                   WHERE s.status='completed' AND s.created_at BETWEEN ? AND ?", [$a, $b]);
$ret = q1('SELECT COALESCE(SUM(amount),0) AS amount, COALESCE(SUM(cost_amount),0) AS cost FROM returns WHERE created_at BETWEEN ? AND ?', [$a, $b]);
$cancelled = (int)qv("SELECT COUNT(*) FROM sales WHERE status='cancelled' AND created_at BETWEEN ? AND ?", [$a, $b]);
$noCost = (int)qv("SELECT COUNT(*) FROM sale_items i JOIN sales s ON s.id = i.sale_id
                   WHERE s.status='completed' AND s.created_at BETWEEN ? AND ? AND i.cost <= 0", [$a, $b]);

$net = (float)$sales['gross'] - (float)$ret['amount'];
$netCost = $cost - (float)$ret['cost'];
$profit = $net - $netCost;
$bills = (int)$sales['bills'];

$daily = q("SELECT $bd AS d, COUNT(DISTINCT s.id) AS bills, SUM(i.qty * i.cost) AS cost
            FROM sales s JOIN sale_items i ON i.sale_id = s.id
            WHERE s.status='completed' AND s.created_at BETWEEN ? AND ?
            GROUP BY $bd ORDER BY d DESC", [$a, $b]);
$dailyTotals = [];
foreach (q("SELECT $bdPlain AS d, SUM(total) AS t FROM sales WHERE status='completed' AND created_at BETWEEN ? AND ? GROUP BY $bdPlain", [$a, $b]) as $r) {
    $dailyTotals[$r['d']] = (float)$r['t'];
}
$dailyRet = [];
foreach (q("SELECT $bdPlain AS d, SUM(amount) AS a, SUM(cost_amount) AS c FROM returns WHERE created_at BETWEEN ? AND ? GROUP BY $bdPlain", [$a, $b]) as $r) {
    $dailyRet[$r['d']] = $r;
}
$maxDay = 0;
foreach ($daily as &$d) {
    $sale = ($dailyTotals[$d['d']] ?? 0) - (float)($dailyRet[$d['d']]['a'] ?? 0);
    $d['sale'] = $sale;
    $d['profit'] = $sale - ((float)$d['cost'] - (float)($dailyRet[$d['d']]['c'] ?? 0));
    $maxDay = max($maxDay, $sale);
}
unset($d);

$top = q("SELECT i.name, SUM(i.qty - i.returned_qty) AS q, SUM((i.qty - i.returned_qty) * i.price) AS amount,
                 SUM((i.qty - i.returned_qty) * (i.price - i.cost)) AS profit, MAX(i.unit) AS unit
          FROM sale_items i JOIN sales s ON s.id = i.sale_id
          WHERE s.status='completed' AND s.created_at BETWEEN ? AND ?
          GROUP BY COALESCE(i.product_id, -1), i.name
          HAVING SUM(i.qty - i.returned_qty) > 0
          ORDER BY q DESC LIMIT 15", [$a, $b]);

$label = $p === 'custom' ? date('d M Y', strtotime($from)) . ($from !== $to ? ' – ' . date('d M Y', strtotime($to)) : '') : $presets[$p][0];

// Payment, cashier, kharcha, udhaar
$payRange = array_fill_keys(array_keys(PAY_METHODS), 0.0);
foreach (q("SELECT p.method, SUM(p.amount) AS t FROM sale_payments p JOIN sales s ON s.id = p.sale_id
            WHERE s.status = 'completed' AND s.created_at BETWEEN ? AND ? GROUP BY p.method", [$a, $b]) as $r) {
    $payRange[$r['method']] = (float)$r['t'];
}
$cashierRange = q("SELECT COALESCE(MAX(cashier), '-') AS cashier, user_id, COUNT(*) AS bills, SUM(total) AS total, SUM(discount) AS disc
                   FROM sales WHERE status = 'completed' AND created_at BETWEEN ? AND ? GROUP BY user_id ORDER BY total DESC", [$a, $b]);
$expRange = (float)qv("SELECT COALESCE(SUM(amount),0) FROM cash_moves WHERE type = 'expense' AND deleted_at IS NULL AND created_at BETWEEN ? AND ?", [$a, $b]);
$expByCat = q("SELECT category, SUM(amount) AS t FROM cash_moves WHERE type = 'expense' AND deleted_at IS NULL AND created_at BETWEEN ? AND ? GROUP BY category ORDER BY t DESC", [$a, $b]);
$udhaarOut = (float)qv('SELECT COALESCE(SUM(amount),0) FROM customer_ledger');
$bachat = $profit - $expRange;

layout_start('Reports', 'reports');
?>
<div class="page-head">
    <div>
        <h1>Report: <?= e($label) ?></h1>
        <p class="muted"><?= e(date('d M Y', strtotime($from))) ?><?= $from !== $to ? ' to ' . e(date('d M Y', strtotime($to))) : '' ?></p>
    </div>
    <div class="row">
        <?php foreach ($presets as $k => [$lbl]): ?>
            <a class="btn sm <?= $p === $k ? 'primary' : '' ?>" href="?p=<?= $k ?>"><?= e($lbl) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<form class="row" method="get" style="margin-bottom:1rem">
    <input type="hidden" name="p" value="custom">
    <input class="input" type="date" name="from" value="<?= e($from) ?>" style="max-width:170px">
    <span class="muted">to</span>
    <input class="input" type="date" name="to" value="<?= e($to) ?>" style="max-width:170px">
    <button class="btn" type="submit">Show</button>
</form>

<div class="cards">
    <div class="card stat hl"><div class="label">Sale</div><div class="value"><?= e(currency()) ?> <?= money($net) ?></div><div class="sub"><?= $bills ?> bills<?= $bills ? ' · average ' . money($net / $bills) : '' ?></div></div>
    <div class="card stat"><div class="label">Profit</div><div class="value" style="color:<?= $profit < 0 ? 'var(--danger)' : 'var(--ok)' ?>"><?= money($profit) ?></div><div class="sub"><?= $net > 0 ? number_format($profit / $net * 100, 1) . '% margin' : '&nbsp;' ?></div></div>
    <div class="card stat"><div class="label">Discount given</div><div class="value"><?= money($sales['disc']) ?></div></div>
    <div class="card stat"><div class="label">Expenses</div><div class="value"><?= money($expRange) ?></div><div class="sub">Profit − expenses = <b style="color:<?= $bachat < 0 ? 'var(--danger)' : 'var(--ok)' ?>"><?= money($bachat) ?></b></div></div>
    <div class="card stat"><div class="label">Returns</div><div class="value"><?= money($ret['amount']) ?></div><div class="sub"><?= $cancelled ?> bills cancelled</div></div>
    <div class="card stat"><div class="label">Total credit due</div><div class="value" style="color:var(--warn)"><?= money($udhaarOut) ?></div><div class="sub"><a href="customers.php">View ledger</a></div></div>
</div>

<div class="grid-3" style="align-items:start;margin-bottom:1.2rem">
    <div class="card">
        <div class="card-head"><h2>By payment method</h2></div>
        <table class="table">
            <?php foreach (PAY_METHODS as $k => $l): if (!$payRange[$k]) continue; ?>
                <tr><td><?= e($l) ?></td><td class="num"><?= money($payRange[$k]) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!array_sum($payRange)): ?><tr><td class="muted">No sales</td><td></td></tr><?php endif; ?>
        </table>
    </div>
    <div class="card">
        <div class="card-head"><h2>By cashier</h2></div>
        <table class="table">
            <?php foreach ($cashierRange as $c): ?>
                <tr><td><?= e($c['cashier']) ?><br><span class="muted small"><?= (int)$c['bills'] ?> bills<?= (float)$c['disc'] > 0 ? ' · discount ' . money($c['disc']) : '' ?></span></td><td class="num"><?= money($c['total']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$cashierRange): ?><tr><td class="muted">No sales</td><td></td></tr><?php endif; ?>
        </table>
    </div>
    <div class="card">
        <div class="card-head"><h2>Expenses by category</h2></div>
        <table class="table">
            <?php foreach ($expByCat as $c): ?><tr><td><?= e($c['category']) ?></td><td class="num"><?= money($c['t']) ?></td></tr><?php endforeach; ?>
            <?php if (!$expByCat): ?><tr><td class="muted">No expenses</td><td></td></tr><?php endif; ?>
        </table>
    </div>
</div>

<?php if ($noCost): ?>
    <div class="alert warn"><?= $noCost ?> sold items had no purchase price, so profit may look higher than it really is. Add purchase prices to your products.</div>
<?php endif; ?>

<div class="grid-2" style="align-items:start">
    <div class="card">
        <div class="card-head"><h2>Best sellers</h2></div>
        <?php if (!$top): ?><div class="empty">No sales in this period.</div><?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Product</th><th class="num">Sold</th><th class="num">Sale</th><th class="num">Profit</th></tr></thead>
                <tbody>
                <?php foreach ($top as $t): ?>
                    <tr><td><?= e($t['name']) ?></td><td class="num"><b><?= qty($t['q']) ?></b> <span class="muted small"><?= e($t['unit']) ?></span></td>
                        <td class="num"><?= money($t['amount']) ?></td><td class="num"><?= money($t['profit']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-head"><h2>Day by day</h2></div>
        <?php if (!$daily): ?><div class="empty">No data.</div><?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Day</th><th class="num">Bills</th><th style="width:40%">Sale</th><th class="num">Profit</th></tr></thead>
                <tbody>
                <?php foreach ($daily as $d): ?>
                    <tr>
                        <td class="nowrap"><a href="sales.php?from=<?= e($d['d']) ?>"><?= e(date('D, d M', strtotime($d['d']))) ?></a></td>
                        <td class="num"><?= (int)$d['bills'] ?></td>
                        <td>
                            <div style="display:flex;align-items:center;gap:.5rem">
                                <div style="flex:1;height:8px;background:var(--surface-2);border-radius:4px;overflow:hidden">
                                    <div style="height:100%;width:<?= $maxDay > 0 ? max(2, round($d['sale'] / $maxDay * 100)) : 0 ?>%;background:var(--brand);border-radius:4px"></div>
                                </div>
                                <span class="num tnum" style="min-width:70px;text-align:right"><?= money($d['sale']) ?></span>
                            </div>
                        </td>
                        <td class="num"><?= money($d['profit']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php layout_end();
