<?php
/** Udhaar khata: customers, baqi, wapsi */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/customers_lib.php';
require_perm('customers');

$admin = is_admin();
$me = current_user();
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$c = $id ? q1('SELECT * FROM customers WHERE id = ?', [$id]) : null;
if ($id && !$c) {
    flash('danger', 'Customer not found.');
    redirect('customers.php');
}
$errors = [];

/** 0300-1234567 -> 923001234567 (WhatsApp link ke liye) */
function wa_number(?string $phone): ?string
{
    $d = preg_replace('/\D/', '', (string)$phone);
    if (preg_match('/^03\d{9}$/', $d)) {
        return '92' . substr($d, 1);
    }
    if (preg_match('/^923\d{9}$/', $d)) {
        return $d;
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add' || ($action === 'edit' && $c)) {
            $name = trim(preg_replace('/\s+/', ' ', (string)($_POST['name'] ?? '')));
            $phone = clean_phone($_POST['phone'] ?? null);
            $address = mb_substr(trim((string)($_POST['address'] ?? '')), 0, 190) ?: null;
            if ($name === '') {
                throw new SaleError('Enter the name.');
            }
            if ($phone && qv('SELECT 1 FROM customers WHERE phone = ? AND id <> ?', [$phone, $c['id'] ?? 0])) {
                throw new SaleError('A customer with this phone number already exists.');
            }
            if ($action === 'add') {
                $limRaw = str_replace(',', '', trim((string)($_POST['credit_limit'] ?? '')));
                $lim = $admin && $limRaw !== '' ? max(0, r2($limRaw)) : null;
                $t = now();
                qexec('INSERT INTO customers (uuid, name, phone, address, credit_limit, active, created_at, updated_at) VALUES (?,?,?,?,?,1,?,?)',
                    [bin2hex(random_bytes(16)), mb_substr($name, 0, 120), $phone, $address, $lim, $t, $t]);
                $newId = (int)db()->lastInsertId();
                log_activity('customer', 'New customer: ' . $name);
                $opening = r2(str_replace(',', '', (string)($_POST['opening'] ?? '0')));
                if ($admin && $opening > 0) {
                    qexec("INSERT INTO customer_ledger (customer_id, type, amount, note, user_id, created_at) VALUES (?, 'adjust', ?, 'Opening balance (from register)', ?, ?)",
                        [$newId, $opening, $me['id'], now()]);
                }
                flash('ok', 'Customer added.');
                redirect('customers.php?id=' . $newId);
            }
            if (!$admin) {
                deny();
            }
            $limRaw = str_replace(',', '', trim((string)($_POST['credit_limit'] ?? '')));
            qexec('UPDATE customers SET name = ?, phone = ?, address = ?, credit_limit = ?, active = ?, updated_at = ? WHERE id = ?',
                [mb_substr($name, 0, 120), $phone, $address, $limRaw === '' ? null : max(0, r2($limRaw)), !empty($_POST['active']) ? 1 : 0, now(), $c['id']]);
            log_activity('customer', 'Customer edit: ' . $name);
            flash('ok', 'Saved.');
            redirect('customers.php?id=' . $c['id']);
        } elseif ($action === 'payment' && $c) {
            if (!can('customer_payment')) {
                deny();
            }
            $amt = r2(str_replace(',', '', (string)($_POST['amount'] ?? '0')));
            receive_customer_payment((int)$c['id'], $amt, (string)($_POST['method'] ?? 'cash'), trim((string)($_POST['ref'] ?? '')), trim((string)($_POST['note'] ?? '')));
            flash('ok', 'Received ' . currency() . ' ' . money($amt) . ' from ' . $c['name'] . '. New balance: ' . money(customer_balance((int)$c['id'])));
            redirect('customers.php?id=' . $c['id'] . '&paid=' . $amt);
        } elseif ($action === 'adjust' && $c) {
            if (!$admin) {
                deny();
            }
            $amt = r2(str_replace(',', '', (string)($_POST['amount'] ?? '0')));
            $dir = ($_POST['dir'] ?? 'plus') === 'minus' ? -1 : 1;
            $note = trim((string)($_POST['note'] ?? ''));
            if ($amt <= 0 || $note === '') {
                throw new SaleError('Enter both the amount and the reason.');
            }
            qexec("INSERT INTO customer_ledger (customer_id, type, amount, note, user_id, created_at) VALUES (?, 'adjust', ?, ?, ?, ?)",
                [$c['id'], $dir * $amt, mb_substr($note, 0, 190), $me['id'], now()]);
            log_activity('customer', $c['name'] . ': adjust ' . ($dir > 0 ? '+' : '-') . money($amt) . ' — ' . $note);
            flash('ok', 'Entry saved.');
            redirect('customers.php?id=' . $c['id']);
        }
    } catch (SaleError $ex) {
        $errors[] = $ex->getMessage();
    }
}

$TYPE_LABELS = ['sale' => 'Credit sale', 'payment' => 'Payment received', 'return' => 'Return', 'cancel' => 'Bill cancelled', 'adjust' => 'Adjustment'];

if ($c) {
    $balance = customer_balance((int)$c['id']);
    $limit = customer_limit($c);
    $ledger = q('SELECT l.*, s.bill_no, u.full_name, u.username FROM customer_ledger l
                 LEFT JOIN sales s ON s.id = l.sale_id LEFT JOIN users u ON u.id = l.user_id
                 WHERE l.customer_id = ? ORDER BY l.created_at, l.id', [$c['id']]);
    $run = 0.0;
    foreach ($ledger as &$l) {
        $run = r2($run + (float)$l['amount']);
        $l['running'] = $run;
    }
    unset($l);
    $ledger = array_reverse($ledger);
    $wa = wa_number($c['phone']);
    $waText = 'Assalam o Alaikum ' . $c['name'] . ', your outstanding balance at ' . setting('shop_name') . ' is ' . currency() . ' ' . money($balance) . '. Thank you.';
    $paidNow = isset($_GET['paid']) ? r2($_GET['paid']) : 0;

    layout_start($c['name'] . ' — Credit', 'customers');
    ?>
    <div class="page-head">
        <div>
            <h1><?= e($c['name']) ?> <?php if (!(int)$c['active']): ?><span class="badge danger">Disabled</span><?php endif; ?></h1>
            <p class="muted"><?= e($c['phone'] ?: 'No phone') ?><?= $c['address'] ? ' · ' . e($c['address']) : '' ?></p>
        </div>
        <div class="row">
            <a class="btn" href="customers.php">&larr; Credit list</a>
            <?php if ($wa && $balance > 0): ?><a class="btn" target="_blank" rel="noopener" href="https://wa.me/<?= e($wa) ?>?text=<?= e(rawurlencode($waText)) ?>">WhatsApp reminder</a><?php endif; ?>
            <button class="btn" type="button" id="printStmt">Print statement</button>
        </div>
    </div>
    <?php foreach ($errors as $err): ?><div class="alert danger"><?= e($err) ?></div><?php endforeach; ?>

    <div class="cards">
        <div class="card stat <?= $balance > 0 ? 'hl' : '' ?>"><div class="label">Balance due</div><div class="value"><?= e(currency()) ?> <?= money($balance) ?></div>
            <div class="sub"><?= $limit !== null ? 'Limit: ' . money($limit) . ($balance > $limit ? ' — over limit!' : '') : 'No limit' ?></div></div>
    </div>

    <div class="grid-2" style="grid-template-columns:minmax(0,1fr) minmax(0,1.5fr);align-items:start">
        <div class="stack" style="margin:0">
            <?php if (can('customer_payment')): ?>
            <form method="post" class="card card-pad stack" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="payment">
                <h2>Payment received</h2>
                <div class="grid-2">
                    <label class="field">Amount <input name="amount" inputmode="decimal" required style="font-size:1.3rem;font-weight:700" placeholder="<?= e(money(max(0, $balance))) ?>"></label>
                    <label class="field">Paid by
                        <select name="method">
                            <?php foreach (PAY_METHODS as $k => $l): if ($k === 'udhaar') continue; ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                </div>
                <label class="field">Transaction ID / note (optional) <input name="ref" maxlength="80"></label>
                <button class="btn primary" type="submit">Save + print receipt</button>
            </form>
            <?php endif; ?>

            <?php if ($admin): ?>
            <form method="post" class="card card-pad stack" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="edit">
                <h2>Edit customer</h2>
                <label class="field">Name <input name="name" value="<?= e($c['name']) ?>" required></label>
                <div class="grid-2">
                    <label class="field">Phone <input name="phone" value="<?= e($c['phone']) ?>" inputmode="tel"></label>
                    <label class="field">Credit limit <input name="credit_limit" value="<?= $c['credit_limit'] !== null ? e(money($c['credit_limit'])) : '' ?>" inputmode="decimal" placeholder="Default: <?= e(setting('udhaar_limit', '') ?: 'none') ?>"></label>
                </div>
                <label class="field">Address <input name="address" value="<?= e($c['address']) ?>"></label>
                <label class="check"><input type="checkbox" name="active" value="1" <?= (int)$c['active'] ? 'checked' : '' ?>> Credit allowed</label>
                <button class="btn" type="submit">Save</button>
            </form>

            <form method="post" class="card card-pad stack" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="adjust">
                <h2>Adjustment (owner only)</h2>
                <p class="muted small">To enter an old balance from your register, or to write off an amount.</p>
                <div class="grid-2">
                    <label class="field">Type
                        <select name="dir"><option value="plus">Increase balance (+)</option><option value="minus">Decrease balance (−)</option></select>
                    </label>
                    <label class="field">Amount <input name="amount" inputmode="decimal" required></label>
                </div>
                <label class="field">Reason <input name="note" required maxlength="190" placeholder="e.g. old register"></label>
                <button class="btn" type="submit">Save entry</button>
            </form>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-head"><h2>Ledger</h2></div>
            <?php if (!$ledger): ?><div class="empty">No entries yet.</div><?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Date</th><th>Entry</th><th class="num">Credit</th><th class="num">Paid</th><th class="num">Balance</th></tr></thead>
                    <tbody>
                    <?php foreach ($ledger as $l): $amt = (float)$l['amount']; ?>
                        <tr>
                            <td class="nowrap small"><?= e(date('d-m-Y', strtotime($l['created_at']))) ?><br><span class="muted"><?= e(date('h:i A', strtotime($l['created_at']))) ?></span></td>
                            <td>
                                <?= e($TYPE_LABELS[$l['type']] ?? $l['type']) ?>
                                <?php if ($l['bill_no']): ?> <a class="mono small" href="sale.php?id=<?= (int)$l['sale_id'] ?>"><?= e($l['bill_no']) ?></a><?php endif; ?>
                                <?php if ($l['method']): ?> <span class="badge"><?= e(PAY_METHODS[$l['method']] ?? $l['method']) ?></span><?php endif; ?>
                                <br><span class="muted small"><?= e(implode(' · ', array_filter([$l['ref'], $l['type'] !== 'sale' && $l['type'] !== 'return' ? $l['note'] : null, $l['full_name'] ?: $l['username']]))) ?></span>
                            </td>
                            <td class="num"><?= $amt > 0 ? money($amt) : '' ?></td>
                            <td class="num" style="color:var(--ok)"><?= $amt < 0 ? money(-$amt) : '' ?></td>
                            <td class="num"><b><?= money($l['running']) ?></b></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="assets/js/receipt.js?v=<?= APP_VERSION ?>"></script>
    <script>
    (function () {
        var SHOP = <?= json_encode(shop_info(), JSON_UNESCAPED_UNICODE) ?>;
        var C = <?= json_encode(['name' => $c['name'], 'phone' => $c['phone'], 'balance' => $balance], JSON_UNESCAPED_UNICODE) ?>;
        var rows = <?= json_encode(array_map(fn($l) => [substr($l['created_at'], 0, 10), ($TYPE_LABELS[$l['type']] ?? $l['type']) . ($l['bill_no'] ? ' ' . $l['bill_no'] : ''), (float)$l['amount'], (float)$l['running']], array_slice(array_reverse($ledger), -40)), JSON_UNESCAPED_UNICODE) ?>;
        var paidNow = <?= json_encode($paidNow) ?>;
        function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
        function doc(body) {
            return '<!doctype html><html><head><meta charset="utf-8"><style>' + Receipt.pageCss(SHOP) +
                'h1{font-size:17px;text-align:center;margin:0}.c{text-align:center}.r{text-align:right;white-space:nowrap}hr{border:0;border-top:1px dashed #000;margin:2mm 0}table{width:100%;border-collapse:collapse}td{padding:.3mm 0;vertical-align:top}.big{font-size:16px;font-weight:800}</style></head><body>' +
                '<h1>' + esc(SHOP.name) + '</h1>' + (SHOP.phone ? '<div class="c">Ph: ' + esc(SHOP.phone) + '</div>' : '') + '<hr>' + body + '</body></html>';
        }
        function print(html) {
            var f = document.createElement('iframe');
            f.style.cssText = 'position:fixed;width:0;height:0;border:0;visibility:hidden';
            document.body.appendChild(f);
            f.contentWindow.document.open(); f.contentWindow.document.write(html); f.contentWindow.document.close();
            setTimeout(function () { f.contentWindow.focus(); f.contentWindow.print(); setTimeout(function () { f.remove(); }, 1000); }, 120);
        }
        var now = new Date().toLocaleString('en-GB');
        document.getElementById('printStmt').addEventListener('click', function () {
            var b = '<div><b>Statement: ' + esc(C.name) + '</b>' + (C.phone ? ' (' + esc(C.phone) + ')' : '') + '</div><div>' + esc(now) + '</div><hr><table>';
            rows.forEach(function (r) {
                b += '<tr><td>' + esc(r[0].split('-').reverse().join('-')) + '<br>' + esc(r[1]) + '</td><td class="r">' + (r[2] > 0 ? '+' : '') + Receipt.money(r[2]) + '<br><b>' + Receipt.money(r[3]) + '</b></td></tr>';
            });
            b += '</table><hr><table><tr><td class="big">Balance</td><td class="r big">' + esc(SHOP.currency) + ' ' + Receipt.money(C.balance) + '</td></tr></table>';
            print(doc(b));
        });
        if (paidNow > 0) {
            print(doc('<div class="c"><b>CREDIT PAYMENT RECEIPT</b></div><div>' + esc(now) + '</div><hr><div>Customer: <b>' + esc(C.name) + '</b></div><table>' +
                '<tr><td>Received</td><td class="r big">' + esc(SHOP.currency) + ' ' + Receipt.money(paidNow) + '</td></tr>' +
                '<tr><td>Balance now</td><td class="r"><b>' + Receipt.money(C.balance) + '</b></td></tr></table><hr><div class="c">Thank you!</div>'));
            history.replaceState(null, '', 'customers.php?id=<?= (int)$c['id'] ?>');
        }
    })();
    </script>
    <?php
    layout_end();
    exit;
}

// ---------- List ----------
if ($admin && isset($_GET['export'])) {
    $rows = q("SELECT c.name, c.phone, c.credit_limit, COALESCE((SELECT SUM(amount) FROM customer_ledger l WHERE l.customer_id = c.id),0) AS balance FROM customers c ORDER BY balance DESC");
    csv_download('credit-' . date('Ymd') . '.csv', ['name', 'phone', 'limit', 'balance'],
        array_map(fn($r) => [$r['name'], (string)$r['phone'], $r['credit_limit'] !== null ? (float)$r['credit_limit'] : '', (float)$r['balance']], $rows));
}
$s = trim($_GET['s'] ?? '');
$f = $_GET['f'] ?? 'baqi';
$where = '1=1';
$params = [];
if ($s !== '') {
    $where .= ' AND (c.name LIKE ? OR c.phone LIKE ?)';
    array_push($params, '%' . $s . '%', '%' . preg_replace('/\D/', '', $s) . '%');
}
$rows = q("SELECT c.*, COALESCE((SELECT SUM(amount) FROM customer_ledger l WHERE l.customer_id = c.id),0) AS balance,
                  (SELECT MAX(created_at) FROM customer_ledger l WHERE l.customer_id = c.id) AS last_at
           FROM customers c WHERE $where ORDER BY balance DESC, c.name LIMIT 500", $params);
if ($f === 'baqi' && $s === '') {
    $rows = array_values(array_filter($rows, fn($r) => (float)$r['balance'] > 0.004));
}
$totalOut = (float)qv('SELECT COALESCE(SUM(amount),0) FROM customer_ledger');
$countOut = (int)qv('SELECT COUNT(*) FROM (SELECT customer_id FROM customer_ledger GROUP BY customer_id HAVING SUM(amount) > 0.004) t');

layout_start('Credit', 'customers');
?>
<div class="page-head">
    <div>
        <h1>Customer credit</h1>
        <p class="muted">Who owes how much. Credit bills are made from Billing ("Credit"); payments are received here.</p>
    </div>
    <div class="row"><?php if ($admin): ?><a class="btn" href="customers.php?export=1">Excel</a><?php endif; ?>
    <a class="btn primary" href="#newCust" onclick="document.getElementById('newCust').hidden=false;document.querySelector('#newCust input').focus();return false;">+ New customer</a></div>
</div>
<?php foreach ($errors as $err): ?><div class="alert danger"><?= e($err) ?></div><?php endforeach; ?>

<div class="cards">
    <div class="card stat hl"><div class="label">Total credit due</div><div class="value"><?= e(currency()) ?> <?= money($totalOut) ?></div><div class="sub"><?= $countOut ?> customers owe money</div></div>
</div>

<form method="post" class="card card-pad stack" id="newCust" <?= $errors ? '' : 'hidden' ?> autocomplete="off" style="margin-bottom:1rem;max-width:720px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <h2>New customer</h2>
    <div class="grid-2">
        <label class="field">Name <input name="name" required maxlength="120" value="<?= e($_POST['name'] ?? '') ?>"></label>
        <label class="field">Phone <input name="phone" inputmode="tel" maxlength="30" value="<?= e($_POST['phone'] ?? '') ?>" placeholder="03xx-xxxxxxx"></label>
    </div>
    <label class="field">Address (optional) <input name="address" maxlength="190"></label>
    <?php if ($admin): ?>
    <div class="grid-2">
        <label class="field">Credit limit <input name="credit_limit" inputmode="decimal" placeholder="Default: <?= e(setting('udhaar_limit', '') ?: 'none') ?>"></label>
        <label class="field">Opening balance (from register) <input name="opening" inputmode="decimal" placeholder="0"></label>
    </div>
    <?php endif; ?>
    <div class="row"><button class="btn primary" type="submit">Save</button></div>
</form>

<div class="card">
    <form class="toolbar" method="get">
        <input class="input" type="search" name="s" value="<?= e($s) ?>" placeholder="Name or phone">
        <select class="input" name="f" style="max-width:170px" onchange="this.form.submit()">
            <option value="baqi" <?= $f === 'baqi' ? 'selected' : '' ?>>With balance due</option>
            <option value="all" <?= $f === 'all' ? 'selected' : '' ?>>All customers</option>
        </select>
        <button class="btn" type="submit">Search</button>
    </form>
    <?php if (!$rows): ?><div class="empty">No customers.</div><?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Customer</th><th>Phone</th><th class="num">Balance</th><th>Last activity</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $lim = customer_limit($r); $over = $lim !== null && (float)$r['balance'] > $lim; ?>
                <tr class="<?= (int)$r['active'] ? '' : 'is-cancelled' ?>">
                    <td><a href="customers.php?id=<?= (int)$r['id'] ?>"><b><?= e($r['name']) ?></b></a><?= $over ? ' <span class="badge danger">Over limit</span>' : '' ?></td>
                    <td class="small"><?= e($r['phone'] ?: '—') ?></td>
                    <td class="num"><b style="color:<?= (float)$r['balance'] > 0 ? 'var(--warn)' : 'inherit' ?>"><?= money($r['balance']) ?></b></td>
                    <td class="small muted nowrap"><?= $r['last_at'] ? e(date('d-m-Y', strtotime($r['last_at']))) : '—' ?></td>
                    <td class="actions"><a class="btn sm" href="customers.php?id=<?= (int)$r['id'] ?>">Open</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php layout_end();
