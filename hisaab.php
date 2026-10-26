<?php
/** Roz ka Hisaab: galla, payments, kharcha, udhaar, din band */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/hisaab_lib.php';
require_perm('hisaab_close');

$admin = is_admin();
$today = business_day();
$day = $admin && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day'] ?? '') ? $_GET['day'] : $today;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $pday = $admin && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['day'] ?? '') ? $_POST['day'] : $today;
    $amt = str_replace(',', '', trim((string)($_POST['amount'] ?? '')));
    $row = q1('SELECT * FROM day_closes WHERE day = ?', [$pday]);
    if ($action === 'opening') {
        if (!is_numeric($amt) || (float)$amt < 0) {
            $errors[] = 'Enter the opening cash in the drawer (0 if empty).';
        } elseif ($row && $row['status'] === 'closed' && !$admin) {
            $errors[] = 'The day is already closed.';
        } elseif ($row && !$admin) {
            $errors[] = 'Opening cash is already entered. Ask the owner to change it.';
        } else {
            set_opening($pday, (float)$amt);
            flash('ok', 'Opening cash: ' . money($amt));
            redirect('hisaab.php' . ($pday !== $today ? '?day=' . $pday : ''));
        }
    } elseif ($action === 'close') {
        if (!is_numeric($amt) || (float)$amt < 0) {
            $errors[] = 'Enter the cash counted in the drawer.';
        } elseif ($row && $row['status'] === 'closed' && !$admin) {
            $errors[] = 'The day has already been closed.';
        } else {
            $r = close_day($pday, (float)$amt, trim((string)($_POST['note'] ?? '')));
            if ($admin) {
                flash(abs($r['diff']) < 0.5 ? 'ok' : 'warn', 'Day closed. ' . (abs($r['diff']) < 0.5 ? 'Cash matches exactly.' : 'Difference: ' . ($r['diff'] > 0 ? money($r['diff']) . ' over' : money(-$r['diff']) . ' short')));
            } else {
                flash('ok', 'Thank you! The day is closed and the report has gone to the owner.');
            }
            redirect('hisaab.php' . ($pday !== $today ? '?day=' . $pday : ''));
        }
    }
}

$h = hisaab_compute($day);
$close = $h['close'];
$closed = $close && $close['status'] === 'closed';
$suggest = suggested_opening($day);
$cur = currency();
$label = bday_label($day);

// ---------- STAFF: blind count ----------
if (!$admin) {
    $me = current_user();
    $mine = q1("SELECT COUNT(*) AS n FROM sales WHERE status = 'completed' AND user_id = ? AND created_at BETWEEN ? AND ?", array_merge([$me['id']], bday_range($today)));
    layout_start('Day close', 'hisaab');
    ?>
    <div class="page-head"><div><h1>Day close — <?= e($label) ?></h1><p class="muted">You made <?= (int)$mine['n'] ?> bills today.</p></div></div>
    <?php foreach ($errors as $err): ?><div class="alert danger"><?= e($err) ?></div><?php endforeach; ?>
    <div class="grid-2" style="align-items:start">
        <?php if (!$close): ?>
        <form method="post" class="card card-pad stack">
            <?= csrf_field() ?><input type="hidden" name="action" value="opening">
            <h2>1. Opening cash</h2>
            <p class="muted">How much cash was in the drawer when you opened the shop?</p>
            <label class="field">Amount <input name="amount" inputmode="decimal" required style="font-size:1.4rem;font-weight:700" placeholder="0"></label>
            <button class="btn primary" type="submit">Save</button>
        </form>
        <?php elseif ($closed): ?>
        <div class="card card-pad stack">
            <h2>The day is closed</h2>
            <p class="muted">Counted: <b><?= e($cur) ?> <?= money($close['counted']) ?></b> — <?= e(date('h:i A', strtotime($close['closed_at']))) ?></p>
        </div>
        <?php else: ?>
        <form method="post" class="card card-pad stack" onsubmit="return confirm('Close the day? This report will go to the owner.')">
            <?= csrf_field() ?><input type="hidden" name="action" value="close">
            <h2>Close the day</h2>
            <p class="muted">Count all the cash in the drawer and enter it. Do not include JazzCash, card etc.</p>
            <label class="field">Cash counted in drawer <input name="amount" inputmode="decimal" required style="font-size:1.6rem;font-weight:800" placeholder="0"></label>
            <label class="field">Note (optional) <input name="note" maxlength="190" placeholder="e.g. one torn 500 note"></label>
            <button class="btn primary lg" type="submit">Close day</button>
        </form>
        <?php endif; ?>
        <div class="card card-pad stack">
            <h2>Remember</h2>
            <ul class="small" style="margin:0;padding-left:1.1rem">
                <li>Whenever money leaves the drawer (tea, delivery, given to owner), record it first in <a href="expenses.php">Expenses</a>.</li>
                <li>When a customer pays back credit, record it on the <a href="customers.php">Credit</a> page.</li>
                <li>Enter the exact count — the owner sees any difference automatically.</li>
            </ul>
        </div>
    </div>
    <?php
    layout_end();
    exit;
}

// ---------- ADMIN ----------
$history = q('SELECT d.*, u.full_name, u.username FROM day_closes d LEFT JOIN users u ON u.id = d.closed_by ORDER BY d.day DESC LIMIT 15');
$diffNow = $closed ? (float)$close['diff'] : null;
$closerName = $closed ? (string)qv('SELECT COALESCE(full_name, username) FROM users WHERE id = ?', [(int)$close['closed_by']]) : '';
$changedAfter = $closed && abs((float)$close['expected'] - $h['expected']) > 0.004;

layout_start('Day close', 'hisaab');
?>
<div class="page-head">
    <div>
        <h1>Day close — <?= e($label) ?></h1>
        <p class="muted"><?= $closed ? 'Closed at ' . e(date('h:i A', strtotime($close['closed_at']))) : 'Day is still open' ?></p>
    </div>
    <div class="row">
        <form method="get" class="row"><input class="input" type="date" name="day" value="<?= e($day) ?>" max="<?= e($today) ?>" onchange="this.form.submit()" style="max-width:170px"></form>
        <button class="btn" type="button" id="printH">Print</button>
    </div>
</div>
<?php foreach ($errors as $err): ?><div class="alert danger"><?= e($err) ?></div><?php endforeach; ?>
<?php $breaches = (int)qv("SELECT COUNT(*) FROM activity_log WHERE action = 'limit_breach' AND created_at BETWEEN ? AND ?", bday_range($day));
if ($breaches): ?><div class="alert danger"><?= $breaches ?> time(s) staff broke a limit without approval or entered a wrong PIN. <a href="activity.php?day=<?= e($day) ?>&action=limit_breach"><b>View activity log</b></a></div><?php endif; ?>
<?php if ($changedAfter): ?><div class="alert warn">Some entries arrived after the day was closed (e.g. offline bills syncing). The drawer should now have <?= money($h['expected']) ?> — you can count again and re-close.</div><?php endif; ?>

<div class="cards">
    <div class="card stat hl"><div class="label">Sale</div><div class="value"><?= e($cur) ?> <?= money($h['net']) ?></div><div class="sub"><?= $h['bills'] ?> bills · <?= qty($h['items']) ?> items</div></div>
    <div class="card stat"><div class="label">Profit</div><div class="value" style="color:<?= $h['profit'] < 0 ? 'var(--danger)' : 'var(--ok)' ?>"><?= money($h['profit']) ?></div></div>
    <div class="card stat"><div class="label">Received in accounts</div><div class="value"><?= money($h['digital']) ?></div><div class="sub">JazzCash, Easypaisa, Bank, Card</div></div>
    <div class="card stat"><div class="label">Credit given</div><div class="value" style="color:var(--warn)"><?= money($h['pay']['udhaar']) ?></div><div class="sub">Paid back: <?= money($h['udhaar_in_total']) ?></div></div>
</div>

<div class="grid-2" style="align-items:start">
    <div class="card">
        <div class="card-head"><h2>Cash drawer</h2><?= $closed ? '<span class="badge ' . (abs($diffNow) < 0.5 ? 'ok' : 'danger') . '">' . (abs($diffNow) < 0.5 ? 'OK' : ($diffNow > 0 ? money($diffNow) . ' over' : money(-$diffNow) . ' short')) . '</span>' : '' ?></div>
        <table class="table hisaab">
            <tr><td>Opening cash</td><td class="num"><?= money($h['opening']) ?></td></tr>
            <tr><td>+ Cash sale</td><td class="num"><?= money($h['pay']['cash']) ?></td></tr>
            <tr><td>+ Credit payments (cash)</td><td class="num"><?= money($h['udhaar_in']['cash']) ?></td></tr>
            <tr><td>− Cash refunds (returns)</td><td class="num"><?= money($h['refund']) ?></td></tr>
            <tr><td>− Expenses</td><td class="num"><?= money($h['expense']) ?></td></tr>
            <tr><td>− Cash withdrawn (owner / bank)</td><td class="num"><?= money($h['withdraw']) ?></td></tr>
            <tr class="sum"><td><b>Expected in drawer</b></td><td class="num"><b><?= e($cur) ?> <?= money($h['expected']) ?></b></td></tr>
            <?php if ($closed): ?>
                <tr><td>Counted<?= $closerName !== '' ? ' (' . e($closerName) . ')' : '' ?></td><td class="num"><b><?= money($close['counted']) ?></b></td></tr>
                <tr class="<?= abs($diffNow) < 0.5 ? '' : 'diff' ?>"><td>Difference</td><td class="num"><b><?= ($diffNow > 0 ? '+' : '') . money($diffNow) ?></b></td></tr>
                <?php if ($close['note']): ?><tr><td colspan="2" class="muted small">Note: <?= e($close['note']) ?></td></tr><?php endif; ?>
            <?php endif; ?>
        </table>
        <div class="card-pad stack" style="border-top:1px solid var(--line)">
            <?php if (!$closed): ?>
            <form method="post" class="row" style="align-items:flex-end">
                <?= csrf_field() ?><input type="hidden" name="action" value="opening"><input type="hidden" name="day" value="<?= e($day) ?>">
                <label class="field" style="flex:1">Opening cash in drawer <input name="amount" inputmode="decimal" required value="<?= $close ? e(money($close['opening_cash'])) : ($suggest !== null ? e(money($suggest)) : '') ?>"></label>
                <button class="btn" type="submit">Save opening</button>
            </form>
            <?php if ($suggest !== null): ?><p class="muted small" style="margin:0">Cash counted at last close: <?= money($suggest) ?></p><?php endif; ?>
            <?php endif; ?>
            <form method="post" class="row" style="align-items:flex-end" onsubmit="return confirm('Close the day?')">
                <?= csrf_field() ?><input type="hidden" name="action" value="close"><input type="hidden" name="day" value="<?= e($day) ?>">
                <label class="field" style="flex:1"><?= $closed ? 'Count again and re-close' : 'Cash counted in drawer' ?> <input name="amount" inputmode="decimal" required></label>
                <label class="field" style="flex:1">Note <input name="note" maxlength="190"></label>
                <button class="btn primary" type="submit"><?= $closed ? 'Re-close' : 'Close day' ?></button>
            </form>
        </div>
    </div>

    <div class="stack" style="margin:0">
        <div class="card">
            <div class="card-head"><h2>By payment method</h2></div>
            <table class="table">
                <thead><tr><th>Method</th><th class="num">Sales</th><th class="num">Credit payments</th></tr></thead>
                <?php foreach (PAY_METHODS as $k => $l): if (!$h['pay'][$k] && !($h['udhaar_in'][$k] ?? 0)) continue; ?>
                    <tr><td><?= e($l) ?></td><td class="num"><?= money($h['pay'][$k]) ?></td><td class="num"><?= $k === 'udhaar' ? '' : money($h['udhaar_in'][$k]) ?></td></tr>
                <?php endforeach; ?>
                <tr class="sum"><td><b>Total</b></td><td class="num"><b><?= money(array_sum($h['pay'])) ?></b></td><td class="num"><b><?= money($h['udhaar_in_total']) ?></b></td></tr>
            </table>
            <div class="card-pad small muted" style="border-top:1px solid var(--line)">
                Discount: <?= money($h['discount']) ?> · Returns: <?= money($h['returns']) ?> · Cancelled bills: <?= $h['cancelled'] ?>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h2>By cashier</h2></div>
            <?php if (!$h['cashiers']): ?><div class="empty">No bills.</div><?php else: ?>
            <table class="table">
                <thead><tr><th>Cashier</th><th class="num">Bills</th><th class="num">Sale</th><th class="num">Cash</th><th class="num">Credit</th></tr></thead>
                <?php foreach ($h['cashiers'] as $c): ?>
                    <tr><td><a href="sales.php?from=<?= e($day) ?>&user=<?= (int)$c['user_id'] ?>"><?= e($c['cashier']) ?></a></td><td class="num"><?= (int)$c['bills'] ?></td>
                        <td class="num"><?= money($c['total']) ?></td><td class="num"><?= money($c['cash']) ?></td><td class="num"><?= money($c['udhaar']) ?></td></tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card" style="margin-top:1rem">
    <div class="card-head"><h2>Previous days</h2></div>
    <?php if (!$history): ?><div class="empty">No days closed yet.</div><?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Day</th><th class="num">Opening</th><th class="num">Expected</th><th class="num">Counted</th><th class="num">Difference</th><th>Closed by</th></tr></thead>
            <?php foreach ($history as $d): $df = $d['diff'] !== null ? (float)$d['diff'] : null; ?>
                <tr>
                    <td><a href="hisaab.php?day=<?= e($d['day']) ?>"><?= e(date('D, d M', strtotime($d['day']))) ?></a></td>
                    <td class="num"><?= money($d['opening_cash']) ?></td>
                    <td class="num"><?= $d['expected'] !== null ? money($d['expected']) : '—' ?></td>
                    <td class="num"><?= $d['counted'] !== null ? money($d['counted']) : '—' ?></td>
                    <td class="num"><?php if ($df === null): ?><span class="badge">Open</span><?php else: ?><b style="color:<?= abs($df) < 0.5 ? 'var(--ok)' : 'var(--danger)' ?>"><?= ($df > 0 ? '+' : '') . money($df) ?></b><?php endif; ?></td>
                    <td class="small muted"><?= e($d['full_name'] ?: $d['username'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>
</div>

<script src="assets/js/receipt.js?v=<?= APP_VERSION ?>"></script>
<script>
(function () {
    var H = <?= json_encode(['h' => array_diff_key($h, ['close' => 1, 'cashiers' => 1]), 'cashiers' => $h['cashiers'], 'close' => $close, 'label' => $label, 'shop' => setting('shop_name'), 'cur' => $cur, 'paper' => (int)setting('receipt_width', '80')]) ?>;
    var M = Receipt.money;
    function esc(x) { return String(x == null ? '' : x).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    document.getElementById('printH').addEventListener('click', function () {
        var h = H.h, row = function (a, b, bold) { return '<tr><td>' + (bold ? '<b>' + a + '</b>' : a) + '</td><td class="r">' + (bold ? '<b>' + b + '</b>' : b) + '</td></tr>'; };
        var b = '<h1>' + esc(H.shop) + '</h1><div class="c">Day close: ' + H.label + '</div><hr><table>' +
            row('Opening', M(h.opening)) + row('+ Cash sale', M(h.pay.cash)) + row('+ Credit payments (cash)', M(h.udhaar_in.cash)) +
            row('- Cash refunds', M(h.refund)) + row('- Expenses', M(h.expense)) + row('- Withdrawn', M(h.withdraw)) +
            row('Expected', H.cur + ' ' + M(h.expected), true);
        if (H.close && H.close.counted !== null) b += row('Counted', M(H.close.counted), true) + row('Difference', M(H.close.diff), true);
        b += '</table><hr><table>' + row('Sale (' + h.bills + ' bills)', M(h.net), true) + row('JazzCash', M(h.pay.jazzcash)) + row('Easypaisa', M(h.pay.easypaisa)) +
            row('Bank', M(h.pay.bank)) + row('Card', M(h.pay.card)) + row('Credit given', M(h.pay.udhaar)) + row('Credit paid back', M(h.udhaar_in_total)) +
            row('Discount', M(h.discount)) + row('Profit', M(h.profit), true) + '</table>';
        if (H.cashiers.length) {
            b += '<hr><table>';
            H.cashiers.forEach(function (c) { b += row(esc(c.cashier) + ' (' + c.bills + ')', M(c.total)); });
            b += '</table>';
        }
        var html = '<!doctype html><html><head><meta charset="utf-8"><style>' + Receipt.pageCss({ paper: H.paper }) + 'h1{font-size:17px;text-align:center;margin:0}.c{text-align:center}.r{text-align:right;white-space:nowrap}hr{border:0;border-top:1px dashed #000;margin:2mm 0}table{width:100%;border-collapse:collapse}td{padding:.3mm 0}</style></head><body>' + b + '</body></html>';
        var f = document.createElement('iframe');
        f.style.cssText = 'position:fixed;width:0;height:0;border:0;visibility:hidden';
        document.body.appendChild(f);
        f.contentWindow.document.open(); f.contentWindow.document.write(html); f.contentWindow.document.close();
        setTimeout(function () { f.contentWindow.focus(); f.contentWindow.print(); }, 120);
    });
})();
</script>
<?php layout_end();
