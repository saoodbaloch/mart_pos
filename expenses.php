<?php
/** Kharcha aur galle se nikale paise */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/hisaab_lib.php';
require_perm('expense');

$admin = is_admin();
$me = current_user();
$today = business_day();
$day = $admin && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day'] ?? '') ? $_GET['day'] : $today;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? 'add';
    if ($action === 'delete') {
        if (!$admin) {
            deny();
        }
        $row = q1('SELECT * FROM cash_moves WHERE id = ? AND deleted_at IS NULL', [(int)$_POST['id']]);
        if ($row && in_array($row['type'], ['expense', 'withdraw'], true)) {
            qexec('UPDATE cash_moves SET deleted_at = ? WHERE id = ?', [now(), $row['id']]);
            log_activity('expense_delete', ($row['category'] ?: $row['type']) . ' ' . money($row['amount']) . ($row['note'] ? ' — ' . $row['note'] : ''));
            flash('ok', 'Entry removed.');
        }
        redirect('expenses.php?day=' . $day);
    }
    $type = ($_POST['type'] ?? 'expense') === 'withdraw' ? 'withdraw' : 'expense';
    $amount = r2(str_replace(',', '', (string)($_POST['amount'] ?? '0')));
    $cat = trim((string)($_POST['category'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    if ($amount <= 0) {
        $errors[] = 'Enter the amount.';
    }
    if ($type === 'expense' && $cat === '') {
        $errors[] = 'What is the expense for? Choose a category.';
    }
    if (!$errors) {
        if ($type === 'withdraw') {
            $cat = $cat ?: 'Owner withdrawal';
        }
        qexec('INSERT INTO cash_moves (type, category, amount, note, user_id, created_at) VALUES (?,?,?,?,?,?)',
            [$type, mb_substr($cat, 0, 40), $amount, mb_substr($note, 0, 190) ?: null, $me['id'], now()]);
        log_activity($type === 'withdraw' ? 'withdraw' : 'expense', $cat . ': ' . money($amount) . ($note ? ' — ' . $note : ''));
        flash('ok', ($type === 'withdraw' ? 'Cash withdrawn: ' : 'Expense saved: ') . money($amount));
        redirect('expenses.php');
    }
}

$rows = q("SELECT m.*, u.full_name, u.username FROM cash_moves m LEFT JOIN users u ON u.id = m.user_id
           WHERE m.deleted_at IS NULL AND m.type IN ('expense','withdraw') AND m.created_at BETWEEN ? AND ? ORDER BY m.id DESC",
    bday_range($day));
$totE = array_sum(array_map(fn($r) => $r['type'] === 'expense' ? (float)$r['amount'] : 0, $rows));
$totW = array_sum(array_map(fn($r) => $r['type'] === 'withdraw' ? (float)$r['amount'] : 0, $rows));
$monthE = $admin ? (float)qv("SELECT COALESCE(SUM(amount),0) FROM cash_moves WHERE deleted_at IS NULL AND type = 'expense' AND created_at >= ?", [bday_range(date('Y-m-01', strtotime($today)))[0]]) : 0;

layout_start('Expenses', 'expenses');
?>
<div class="page-head">
    <div>
        <h1>Expenses</h1>
        <p class="muted">Record any money taken from the cash drawer here — otherwise the day close will come up short.</p>
    </div>
    <?php if ($admin): ?>
    <form method="get" class="row"><input class="input" type="date" name="day" value="<?= e($day) ?>" onchange="this.form.submit()" style="max-width:170px"></form>
    <?php endif; ?>
</div>
<?php foreach ($errors as $err): ?><div class="alert danger"><?= e($err) ?></div><?php endforeach; ?>

<div class="cards">
    <div class="card stat"><div class="label">Expenses (<?= $day === $today ? 'today' : e(date('d M', strtotime($day))) ?>)</div><div class="value"><?= money($totE) ?></div></div>
    <div class="card stat"><div class="label">Cash withdrawn</div><div class="value"><?= money($totW) ?></div><div class="sub">To owner / bank</div></div>
    <?php if ($admin): ?><div class="card stat"><div class="label">This month's expenses</div><div class="value"><?= money($monthE) ?></div></div><?php endif; ?>
</div>

<div class="grid-2" style="align-items:start">
    <?php if ($day === $today): ?>
    <form method="post" class="card card-pad stack" autocomplete="off">
        <?= csrf_field() ?>
        <h2>New entry</h2>
        <div class="row" id="typeRow">
            <label class="check"><input type="radio" name="type" value="expense" <?= ($_POST['type'] ?? 'expense') === 'expense' ? 'checked' : '' ?>> Expense</label>
            <label class="check"><input type="radio" name="type" value="withdraw" <?= ($_POST['type'] ?? '') === 'withdraw' ? 'checked' : '' ?>> Cash withdrawal (owner / bank)</label>
        </div>
        <div id="cats" class="chips">
            <?php foreach (EXPENSE_CATEGORIES as $c): ?>
                <label class="chip"><input type="radio" name="category" value="<?= e($c) ?>" <?= ($_POST['category'] ?? '') === $c ? 'checked' : '' ?>><span><?= e($c) ?></span></label>
            <?php endforeach; ?>
        </div>
        <div class="grid-2">
            <label class="field">Amount (<?= e(currency()) ?>) <input name="amount" inputmode="decimal" required value="<?= e($_POST['amount'] ?? '') ?>" style="font-size:1.3rem;font-weight:700"></label>
            <label class="field">Details (optional) <input name="note" maxlength="190" value="<?= e($_POST['note'] ?? '') ?>" placeholder="e.g. 5 cups of tea"></label>
        </div>
        <button class="btn primary lg" type="submit">Save</button>
    </form>
    <?php endif; ?>

    <div class="card">
        <div class="card-head"><h2><?= $day === $today ? "Today's entries" : e(date('d M Y', strtotime($day))) ?></h2></div>
        <?php if (!$rows): ?><div class="empty">No entries.</div><?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Time</th><th>For</th><th>By</th><th class="num">Amount</th><?php if ($admin): ?><th></th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="nowrap muted"><?= e(date('h:i A', strtotime($r['created_at']))) ?></td>
                        <td><?= $r['type'] === 'withdraw' ? '<span class="badge warn">Withdrawal</span> ' : '' ?><?= e($r['category']) ?><?= $r['note'] ? '<br><span class="muted small">' . e($r['note']) . '</span>' : '' ?></td>
                        <td class="small"><?= e($r['full_name'] ?: $r['username'] ?: '—') ?></td>
                        <td class="num"><b><?= money($r['amount']) ?></b></td>
                        <?php if ($admin): ?>
                        <td class="actions">
                            <form method="post" onsubmit="return confirm('Remove this entry?')">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn sm danger-ghost" type="submit">Remove</button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>
<script>
(function () {
    var cats = document.getElementById('cats');
    if (!cats) return;
    function t() {
        var w = document.querySelector('input[name=type]:checked').value === 'withdraw';
        cats.hidden = w;
        cats.querySelectorAll('input').forEach(function (i) { if (w) i.checked = false; });
    }
    document.querySelectorAll('input[name=type]').forEach(function (r) { r.addEventListener('change', t); });
    t();
})();
</script>
<?php layout_end();
