<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/sales_lib.php';
require_perm('sales_own');

$admin = is_admin();
$me = current_user();
$id = (int)($_GET['id'] ?? 0);
$sale = load_sale($id);
if (!$sale || (!$admin && (int)$sale['user_id'] !== $me['id'])) {
    flash('danger', 'Bill not found.');
    redirect('sales.php');
}
$needPin = !$admin && setting('pin_for_returns', '1') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $approver = null;
        if ($needPin) {
            if ($lockMin = pin_locked_minutes()) {
                throw new SaleError("Too many wrong PIN attempts. Try again in $lockMin minute(s).");
            }
            $approver = verify_admin_pin(trim($_POST['pin'] ?? ''));
            if (!$approver) {
                throw new SaleError('Wrong admin PIN. Ask the owner to enter the PIN.');
            }
        }
        if (($_POST['action'] ?? '') === 'return') {
            $lines = array_filter((array)($_POST['ret'] ?? []), fn($v) => trim((string)$v) !== '' && (float)$v > 0);
            $r = return_items($id, $lines);
            $msg = 'Items returned. Stock increased.';
            if ($r['cash'] > 0) {
                $msg .= ' Give the customer ' . currency() . ' ' . money($r['cash']) . ' cash back.';
            }
            if ($r['udhaar'] > 0) {
                $msg .= ' Credit reduced by ' . money($r['udhaar']) . '.';
            }
            flash('ok', $msg);
        } elseif (($_POST['action'] ?? '') === 'cancel') {
            cancel_sale($id);
            flash('ok', 'Bill cancelled. Stock restored and this bill will not count in the closing.');
        }
        if ($approver) {
            log_activity('pin_override', ($_POST['action'] === 'cancel' ? 'Bill cancel' : 'Return') . ' ' . $sale['bill_no'] . ' — approved by: ' . $approver['name']);
        }
    } catch (SaleError $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect('sale.php?id=' . $id);
}

$returns = q('SELECT r.*, i.name FROM returns r JOIN sale_items i ON i.id = r.sale_item_id WHERE r.sale_id = ? ORDER BY r.id', [$id]);
$canReturn = $sale['status'] === 'completed' && array_filter($sale['items'], fn($it) => (float)$it['qty'] - (float)$it['returned_qty'] > 0.0005);
$receipt = sale_receipt_data($sale);
$shop = shop_info();
$pinField = $needPin ? '<label class="field" style="max-width:220px">Admin PIN <input type="password" name="pin" inputmode="numeric" maxlength="8" required autocomplete="off"></label>' : '';

layout_start('Bill ' . $sale['bill_no'], 'sales');
?>
<div class="page-head">
    <div>
        <h1>Bill <span class="mono"><?= e($sale['bill_no']) ?></span>
            <?php if ($sale['status'] === 'cancelled'): ?><span class="badge danger">Cancelled</span><?php endif; ?>
        </h1>
        <p class="muted"><?= e(date('d-m-Y h:i A', strtotime($sale['created_at']))) ?><?= $sale['cashier'] ? ' · Cashier: ' . e($sale['cashier']) : '' ?></p>
    </div>
    <div class="row">
        <a class="btn" href="sales.php<?= $admin ? '?from=' . e(substr($sale['created_at'], 0, 10)) : '' ?>">&larr; Bills</a>
        <button class="btn primary" type="button" id="reprint">Reprint</button>
    </div>
</div>

<div class="grid-2" style="grid-template-columns:minmax(0,1.6fr) minmax(0,1fr);align-items:start">
    <div class="card">
        <form method="post" id="retForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="return">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Total</th>
                        <?php if ($canReturn): ?><th class="num">Return</th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($sale['items'] as $it):
                        $left = (float)$it['qty'] - (float)$it['returned_qty']; ?>
                        <tr>
                            <td><b><?= e($it['name']) ?></b><?php if ($it['barcode']): ?><br><span class="mono small muted"><?= e($it['barcode']) ?></span><?php endif; ?>
                                <?php if ((float)$it['returned_qty'] > 0): ?><br><span class="badge warn"><?= qty($it['returned_qty']) ?> returned</span><?php endif; ?></td>
                            <td class="num"><?= qty($it['qty']) ?> <span class="muted small"><?= e($it['unit']) ?></span></td>
                            <td class="num"><?= money($it['price']) ?></td>
                            <td class="num"><b><?= money($it['line_total']) ?></b></td>
                            <?php if ($canReturn): ?>
                                <td class="num">
                                    <?php if ($left > 0.0005): ?>
                                        <input class="input" style="width:80px;text-align:right" name="ret[<?= (int)$it['id'] ?>]" inputmode="decimal" placeholder="0" data-max="<?= e(qty($left)) ?>">
                                    <?php else: ?><span class="muted small">—</span><?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($canReturn): ?>
                <div class="toolbar" style="border-top:1px solid var(--line);border-bottom:0;align-items:flex-end">
                    <span class="muted small">Enter the quantity of each returned item.</span>
                    <span class="spacer"></span>
                    <?= $pinField ?>
                    <button class="btn" type="submit" onclick="return confirm('Return these items? Stock will increase.')">Return items</button>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <div class="stack" style="margin-top:0">
        <div class="card card-pad">
            <table class="table" style="font-size:1rem">
                <tr><td>Subtotal</td><td class="num"><?= money($sale['subtotal']) ?></td></tr>
                <?php if ((float)$sale['discount'] > 0): ?><tr><td>Discount</td><td class="num">-<?= money($sale['discount']) ?></td></tr><?php endif; ?>
                <tr><td><b>Total</b></td><td class="num"><b style="font-size:1.25rem"><?= e(currency()) ?> <?= money($sale['total']) ?></b></td></tr>
                <?php foreach ($sale['payments'] as $p): ?>
                    <tr><td><?= e(PAY_METHODS[$p['method']] ?? $p['method']) ?><?= $p['ref'] ? '<br><span class="muted small mono">' . e($p['ref']) . '</span>' : '' ?></td><td class="num"><?= money($p['amount']) ?></td></tr>
                <?php endforeach; ?>
                <?php if ((float)$sale['change_amount'] > 0): ?><tr><td>Change (cash)</td><td class="num"><?= money($sale['change_amount']) ?></td></tr><?php endif; ?>
                <?php if ((float)$sale['returned_amount'] > 0): ?><tr><td>Returned</td><td class="num" style="color:var(--warn)">-<?= money($sale['returned_amount']) ?></td></tr><?php endif; ?>
            </table>
            <?php if ($sale['customer_name']): ?>
                <p style="margin:.6rem 0 0">Credit customer: <a href="customers.php?id=<?= (int)$sale['customer_id'] ?>"><b><?= e($sale['customer_name']) ?></b></a></p>
            <?php endif; ?>
        </div>

        <?php if ($returns): ?>
        <div class="card">
            <div class="card-head"><h2>Returns</h2></div>
            <table class="table">
                <?php foreach ($returns as $r): ?>
                    <tr><td><?= e($r['name']) ?><br><span class="muted small"><?= e(date('d-m-Y h:i A', strtotime($r['created_at']))) ?></span></td>
                        <td class="num"><?= qty($r['qty']) ?></td><td class="num">-<?= money($r['amount']) ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($sale['status'] === 'completed' && (float)$sale['returned_amount'] <= 0 && !sale_can_cancel($sale)): ?>
        <div class="card card-pad muted small">This bill is from a previous day, so it cannot be cancelled. If there was a mistake, <b>Return</b> the items above — the refund will count in today's closing.</div>
        <?php elseif ($sale['status'] === 'completed' && (float)$sale['returned_amount'] <= 0): ?>
        <form method="post" class="card card-pad stack" onsubmit="return confirm('Cancel the whole bill? All stock will be restored.')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel">
            <div><b>Wrong bill?</b><br><span class="muted small">Cancelling restores the stock<?= $sale['customer_id'] ? ' and credit' : '' ?> and removes the bill from sales. The record is kept.</span></div>
            <?= $pinField ?>
            <button class="btn danger-ghost" type="submit">Cancel whole bill</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<script>window.SALE = <?= json_encode($receipt, JSON_UNESCAPED_UNICODE) ?>; window.SHOP = <?= json_encode($shop, JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="assets/js/receipt.js?v=<?= APP_VERSION ?>"></script>
<script>
document.getElementById('reprint').addEventListener('click', function () { Receipt.print(window.SALE, window.SHOP); });
document.querySelectorAll('[data-max]').forEach(function (inp) {
    inp.addEventListener('input', function () {
        var max = parseFloat(inp.dataset.max), v = parseFloat(inp.value);
        inp.style.borderColor = v > max ? 'var(--danger)' : '';
    });
});
</script>
<?php layout_end();
