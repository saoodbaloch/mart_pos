<?php
require __DIR__ . '/inc/bootstrap.php';
require_perm('billing');
header('Cache-Control: no-cache');

$shop = shop_info();
$me = current_user();
$admin = is_admin();
$rules = [
    'admin'        => $admin,
    'discount_pct' => setting('staff_discount_pct', '') === '' ? null : (float)setting('staff_discount_pct'),
    'pins'         => $admin ? [] : admin_pin_records(),
    'iterations'   => PIN_ITERATIONS,
    'default_limit' => setting('udhaar_limit', '') === '' ? null : (float)setting('udhaar_limit'),
    'day_start_hour' => day_start_hour(),
];

// Kal ka din band hua? Aaj opening likhi?
require_once __DIR__ . '/inc/hisaab_lib.php';
$bToday = business_day();
$bYday = date('Y-m-d', strtotime($bToday . ' -1 day'));
$dayNote = null;
[$ya, $yb] = bday_range($bYday);
$yRow = q1('SELECT status FROM day_closes WHERE day = ?', [$bYday]);
$ySales = (int)qv("SELECT COUNT(*) FROM sales WHERE status = 'completed' AND created_at BETWEEN ? AND ?", [$ya, $yb]);
if ($ySales > 0 && (!$yRow || $yRow['status'] !== 'closed')) {
    $dayNote = ['warn', 'Yesterday (' . date('d M', strtotime($bYday)) . ') has not been closed yet. Count the drawer and close it —', 'hisaab.php' . ($admin ? '?day=' . $bYday : '')];
} elseif (!q1('SELECT 1 FROM day_closes WHERE day = ?', [$bToday])) {
    $dayNote = ['info', 'Start today: enter the opening cash in the drawer —', 'hisaab.php'];
}
$scanIcon = 'M3 7V5a2 2 0 012-2h2M17 3h2a2 2 0 012 2v2M21 17v2a2 2 0 01-2 2h-2M7 21H5a2 2 0 01-2-2v-2M7 8v8M10 8v8M13 8v8M17 8v8';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f5c52">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title>Billing — <?= e($shop['name']) ?></title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="assets/icons/icon-192.png">
<link rel="apple-touch-icon" href="assets/icons/icon-192.png">
<link rel="stylesheet" href="assets/css/app.css?v=<?= APP_VERSION ?>">
</head>
<body class="pos">
<div class="pos-shell">
    <header class="pos-top">
        <span class="brand"><span class="brand-mark sm">M</span><span><?= e($shop['name']) ?></span></span>
        <span class="net" id="net">Online</span>
        <span class="pending" id="pending" hidden></span>
        <span class="spacer"></span>
        <span class="clock" id="clock"></span>
        <nav class="row" style="gap:.2rem">
            <?php foreach (nav_items() as $key => [$label, $href, $ico, $perm]): if ($key === 'billing' || !nav_allowed($perm)) continue; ?>
                <a class="navlink" href="<?= e($href) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <span class="cashier" title="<?= $admin ? 'Admin' : 'Staff' ?>"><?= e($me['name']) ?></span>
        <form method="post" action="logout.php" id="logoutForm" style="margin:0"><?= csrf_field() ?><button class="navlink" type="submit" style="background:none;border:0;cursor:pointer;font:inherit">Logout</button></form>
    </header>

    <div class="pos-notes">
    <div class="alert danger" id="authBar" style="margin:.6rem .8rem 0" hidden>
        Your login has expired. Billing keeps working, but to send bills to the server please
        <a href="login.php?next=billing.php"><b>log in again</b></a>.
    </div>

    <?php foreach (flashes() as $fl): ?>
        <div class="alert <?= e($fl['type']) ?>" style="margin:.6rem .8rem 0" data-autohide><?= e($fl['msg']) ?></div>
    <?php endforeach; ?>
    <?php if ($dayNote): ?>
        <div class="alert <?= e($dayNote[0]) ?> day-note" style="margin:.6rem .8rem 0;padding:.45rem .9rem"><?= e($dayNote[1]) ?> <a href="<?= e($dayNote[2]) ?>"><b>Open Day Close</b></a></div>
    <?php endif; ?>
    </div>

    <main class="pos-main">
        <section class="pos-left">
            <div class="scanbar">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="<?= $scanIcon ?>"/></svg>
                <span class="qty-chip" id="multChip" hidden></span>
                <input id="scan" autocomplete="off" spellcheck="false" placeholder="Scan barcode · type name · 4* then scan = 4 pieces" autofocus>
                <div class="suggest" id="suggest" hidden></div>
            </div>

            <div class="cart">
                <div class="cart-scroll">
                    <table>
                        <thead><tr>
                            <th style="width:44px">#</th><th>Item</th><th class="num" style="width:150px">Qty</th>
                            <th class="num" style="width:110px">Rate</th><th class="num" style="width:120px">Total</th><th style="width:44px"></th>
                        </tr></thead>
                        <tbody id="cartBody"></tbody>
                    </table>
                    <div class="cart-empty" id="cartEmpty">
                        <div>
                            <div class="big">New bill</div>
                            <div>Scan products. When done press <b>Enter</b>, type the cash received, then <b>Enter</b> again.</div>
                            <div id="lastBill" class="small" style="margin-top:1rem"></div>
                        </div>
                    </div>
                </div>
                <div class="cart-foot"><span id="itemCount">0 items</span><span id="heldInfo"></span></div>
            </div>
        </section>

        <aside class="pos-right">
            <div class="quick" id="quickBox" hidden>
                <h3>Quick items</h3>
                <div class="quick-grid" id="quickGrid"></div>
            </div>
            <div class="totals" id="totalsBox">
                <div class="line"><span>Subtotal</span><span id="tSub">0</span></div>
                <div class="line disc" id="tDiscRow" hidden><span>Discount</span><span id="tDisc">0</span></div>
                <div class="grand"><span class="gl">Total</span><span class="gv" id="tTotal">0</span></div>
                <div class="pay">
                    <label for="cash">Cash received</label>
                    <input class="cash" id="cash" inputmode="decimal" autocomplete="off" placeholder="0">
                    <div class="notes" id="notes">
                        <button type="button" data-note="exact">Exact</button>
                        <button type="button" data-note="500">500</button>
                        <button type="button" data-note="1000">1000</button>
                        <button type="button" data-note="5000">5000</button>
                    </div>
                    <div class="change" id="changeRow"><span>Change</span><span class="cv" id="tChange">0</span></div>
                    <div class="methods" id="methods">
                        <button type="button" data-m="jazzcash">JazzCash</button>
                        <button type="button" data-m="easypaisa">Easypaisa</button>
                        <button type="button" data-m="bank">Bank</button>
                        <button type="button" data-m="card">Card</button>
                        <button type="button" data-m="udhaar" class="udh">Credit</button>
                        <button type="button" data-m="split" title="F10">Split</button>
                    </div>
                    <button class="btn primary block pay-btn" id="payBtn" type="button">Cash bill + Print (Enter)</button>
                </div>
            </div>
            <div class="side-actions">
                <button class="btn" type="button" id="discBtn">Discount <small>F4</small></button>
                <button class="btn" type="button" id="holdBtn">Hold <small>F8</small></button>
                <button class="btn" type="button" id="heldBtn">Held bills <small>F9</small></button>
                <button class="btn" type="button" id="reprintBtn">Last reprint <small>Ctrl+P</small></button>
                <button class="btn danger-ghost" type="button" id="clearBtn">Bill cancel <small>Ctrl+Del</small></button>
                <button class="btn" type="button" id="syncBtn">Sync</button>
            </div>
        </aside>
    </main>

    <footer class="keys">
        <span><kbd>Enter</kbd>on empty box = payment</span>
        <span><kbd>4*</kbd>then scan = 4 pcs</span>
        <span><kbd>↑</kbd><kbd>↓</kbd>select item</span>
        <span><kbd>+</kbd><kbd>-</kbd>qty</span>
        <span><kbd>F2</kbd>qty / rate</span>
        <span><kbd>Del</kbd>remove item</span>
        <span><kbd>F4</kbd>discount</span>
        <span><kbd>F8</kbd>hold</span>
        <span><kbd>F9</kbd>held bills</span>
        <span><kbd>F10</kbd>split / payment</span>
        <span><kbd>Esc</kbd>back to scan</span>
    </footer>
</div>

<!-- Naya product (scan hua magar system mein nahi) -->
<div class="modal-backdrop" id="newModal" hidden>
    <form class="modal" id="newForm" autocomplete="off">
        <div class="modal-head"><h2>This product is not in the system</h2><p class="muted small" style="margin:.2rem 0 0">Enter the name and price — the product will be saved and added to the bill.</p></div>
        <div class="modal-body stack">
            <label class="field">Barcode <input id="nBarcode" readonly class="mono"></label>
            <label class="field">Name <input id="nName" required maxlength="190"></label>
            <div class="grid-2">
                <label class="field">Sale price <input id="nPrice" inputmode="decimal" required></label>
                <?php if ($admin): ?><label class="field">Purchase price <input id="nCost" inputmode="decimal" placeholder="can add later"></label><?php else: ?><input id="nCost" type="hidden" value=""><?php endif; ?>
            </div>
            <label class="field">Unit
                <select id="nUnit"><option value="pcs">Piece</option><option value="kg">Kilo (kg)</option><option value="ltr">Litre</option><option value="dozen">Dozen</option><option value="m">Meter</option></select>
            </label>
        </div>
        <div class="modal-foot"><button class="btn" type="button" data-close>Cancel (Esc)</button><button class="btn primary" type="submit">Save + add to bill</button></div>
    </form>
</div>

<!-- Khula saaman: kitna? -->
<div class="modal-backdrop" id="weighModal" hidden>
    <form class="modal" id="weighForm" autocomplete="off">
        <div class="modal-head"><h2 id="wTitle">How much?</h2><p class="muted small" id="wRate" style="margin:.2rem 0 0"></p></div>
        <div class="modal-body stack">
            <div class="grid-2">
                <label class="field"><span id="wUnitLbl">Quantity (kg)</span> <input id="wQty" inputmode="decimal" placeholder="e.g. 1.5"></label>
                <label class="field">Or amount (Rs) <input id="wAmt" inputmode="decimal" placeholder="e.g. 100"></label>
            </div>
            <div class="muted small" id="wCalc"></div>
        </div>
        <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn primary" type="submit">Add to bill</button></div>
    </form>
</div>

<!-- Discount -->
<div class="modal-backdrop" id="discModal" hidden>
    <form class="modal" id="discForm" autocomplete="off">
        <div class="modal-head"><h2>Discount (whole bill)</h2></div>
        <div class="modal-body stack">
            <div class="grid-2">
                <label class="field">Amount <input id="dAmt" inputmode="decimal" placeholder="0"></label>
                <label class="field">Or percent (%) <input id="dPct" inputmode="decimal" placeholder="0"></label>
            </div>
            <div class="muted small" id="dCalc"></div>
        </div>
        <div class="modal-foot"><button class="btn" type="button" id="dRemove">Remove discount</button><span class="spacer"></span><button class="btn" type="button" data-close>Cancel</button><button class="btn primary" type="submit">Apply</button></div>
    </form>
</div>

<!-- Qty likhein -->
<div class="modal-backdrop" id="qtyModal" hidden>
    <form class="modal" id="qtyForm" autocomplete="off">
        <div class="modal-head"><h2 id="qTitle">Quantity</h2><p class="muted small" id="qBase" style="margin:.2rem 0 0"></p></div>
        <div class="modal-body grid-2">
            <label class="field">Quantity <input class="input" id="qVal" inputmode="decimal" style="font-size:1.5rem;text-align:center"></label>
            <label class="field">Rate <input class="input" id="qRate" inputmode="decimal" style="font-size:1.5rem;text-align:center"></label>
        </div>
        <div class="modal-foot"><button class="btn" type="button" data-close>Cancel</button><button class="btn primary" type="submit">OK</button></div>
    </form>
</div>

<!-- Held bills -->
<div class="modal-backdrop" id="heldModal" hidden>
    <div class="modal wide">
        <div class="modal-head"><h2>Held bills</h2><p class="muted small" style="margin:.2rem 0 0">Choose a bill (↑ ↓ Enter). The current bill is held automatically.</p></div>
        <div class="modal-body"><div class="held-list" id="heldList"></div></div>
        <div class="modal-foot"><button class="btn" type="button" data-close>Close (Esc)</button></div>
    </div>
</div>

<!-- Payment: JazzCash / Easypaisa / Bank / Card / Udhaar / Split -->
<div class="modal-backdrop" id="payModal" hidden>
    <form class="modal wide" id="payForm" autocomplete="off">
        <div class="modal-head row between">
            <h2>Payment</h2>
            <span class="pm-total">Total <b id="pmTotal">0</b></span>
        </div>
        <div class="modal-body stack">
            <div id="pmLines" class="pm-lines"></div>
            <button class="btn sm" type="button" id="pmAdd">+ Add payment (split)</button>
            <div id="pmAccounts" class="pm-accounts" hidden></div>
            <div id="pmCustBox" class="pm-cust" hidden>
                <div class="row between"><b>Credit to which customer?</b><span class="small muted" id="pmCustInfo"></span></div>
                <div id="pmCustPicked" class="pm-picked" hidden></div>
                <div id="pmCustSearch">
                    <input class="input" id="pmCustQ" placeholder="Customer name or phone">
                    <div class="pm-cust-list" id="pmCustList"></div>
                    <div class="grid-2" id="pmNewCust" hidden style="margin-top:.5rem">
                        <label class="field">New customer: name <input id="pmNewName" maxlength="120"></label>
                        <label class="field">Phone <input id="pmNewPhone" inputmode="tel" maxlength="30"></label>
                    </div>
                </div>
            </div>
            <label class="check pm-old" id="pmOldBox" hidden><input type="checkbox" id="pmOldChk" checked> <span id="pmOldTxt"></span></label>
            <div class="pm-sum" id="pmSum"></div>
        </div>
        <div class="modal-foot"><button class="btn" type="button" data-close>Cancel (Esc)</button><button class="btn primary" type="submit" id="pmOk">Bill complete + Print</button></div>
    </form>
</div>

<!-- Admin PIN -->
<div class="modal-backdrop" id="pinModal" hidden>
    <form class="modal" id="pinForm" autocomplete="off">
        <div class="modal-head"><h2>Owner approval needed</h2><p class="muted small" id="pinWhy" style="margin:.2rem 0 0"></p></div>
        <div class="modal-body stack">
            <input class="input" type="password" id="pinVal" inputmode="numeric" maxlength="8" placeholder="Admin PIN" style="font-size:1.5rem;text-align:center;letter-spacing:.3em">
            <div class="small" id="pinMsg" style="color:var(--danger)"></div>
        </div>
        <div class="modal-foot"><button class="btn" type="button" id="pinCancel">Cancel</button><button class="btn primary" type="submit">Approve</button></div>
    </form>
</div>

<script>
window.SHOP = <?= json_encode($shop, JSON_UNESCAPED_UNICODE) ?>;
window.ME = <?= json_encode(['id' => $me['id'], 'name' => $me['name'], 'role' => $me['role']], JSON_UNESCAPED_UNICODE) ?>;
window.RULES = <?= json_encode($rules, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/js/store.js?v=<?= APP_VERSION ?>"></script>
<script src="assets/js/receipt.js?v=<?= APP_VERSION ?>"></script>
<script src="assets/js/billing.js?v=<?= APP_VERSION ?>"></script>
</body>
</html>
