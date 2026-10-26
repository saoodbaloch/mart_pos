<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/products_lib.php';
require_perm('stock_in');

$admin = is_admin();
$defLow = (float)setting('low_stock', '5');
$low = q('SELECT * FROM products WHERE deleted_at IS NULL AND stock <= COALESCE(low_stock, ?) ORDER BY stock, name LIMIT 200', [$defLow]);
$today = q("SELECT m.*, p.name, p.unit FROM stock_moves m JOIN products p ON p.id = m.product_id
            WHERE m.type = 'purchase' AND m.created_at >= ? ORDER BY m.id DESC LIMIT 100", [bday_range(business_day())[0]]);
$value = q1('SELECT COALESCE(SUM(CASE WHEN stock > 0 THEN stock * purchase_price ELSE 0 END),0) AS cost,
                    COALESCE(SUM(CASE WHEN stock > 0 THEN stock * sale_price ELSE 0 END),0) AS sale,
                    COUNT(*) AS n FROM products WHERE deleted_at IS NULL');

layout_start('Stock', 'stock');
?>
<div class="page-head">
    <div>
        <h1>Stock</h1>
        <p class="muted">When new stock arrives, scan it here to add stock.</p>
    </div>
    <?php if ($admin): ?><a class="btn" href="stock_count.php">Stock count (monthly)</a><?php endif; ?>
</div>

<div class="cards">
    <div class="card stat"><div class="label">Products</div><div class="value"><?= number_format((int)$value['n']) ?></div></div>
    <?php if ($admin): ?><div class="card stat"><div class="label">Stock value (at cost)</div><div class="value"><?= money($value['cost']) ?></div><div class="sub">At sale price: <?= money($value['sale']) ?></div></div><?php endif; ?>
    <div class="card stat"><div class="label">Low stock</div><div class="value" style="color:<?= $low ? 'var(--warn)' : 'inherit' ?>"><?= count($low) ?></div></div>
</div>

<div class="grid-2" style="align-items:start">
    <div class="card">
        <div class="card-head"><h2>Stock In (new stock received)</h2></div>
        <div class="card-pad stack">
            <div><input class="input" id="pick" placeholder="Scan product or type name" autofocus></div>
            <form id="inForm" class="stack" hidden>
                <div class="alert info" id="pInfo"></div>
                <div class="grid-3">
                    <label class="field">Quantity received <input id="qIn" inputmode="decimal" required></label>
                    <label class="field" <?= $admin ? '' : 'hidden' ?>>Purchase price <input id="pIn" inputmode="decimal"></label>
                    <label class="field" <?= $admin ? '' : 'hidden' ?>>Sale price <input id="sIn" inputmode="decimal"></label>
                </div>
                <div class="row">
                    <button class="btn primary" type="submit">Add stock (Enter)</button>
                    <button class="btn ghost" type="button" id="cancelIn">Cancel</button>
                </div>
            </form>
            <p class="muted small"><?= $admin ? 'Leave price empty to keep the current one. To correct the stock count, edit the product.' : 'Scan the product and enter how much arrived.' ?></p>
        </div>
        <div class="card-head" style="border-top:1px solid var(--line)"><h2>Today's stock in</h2></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Time</th><th>Product</th><th class="num">Received</th></tr></thead>
                <tbody id="todayBody">
                <?php if (!$today): ?><tr class="empty-row"><td colspan="3" class="empty">No stock received today yet.</td></tr><?php endif; ?>
                <?php foreach ($today as $m): ?>
                    <tr><td class="nowrap muted"><?= e(date('h:i A', strtotime($m['created_at']))) ?></td><td><?= e($m['name']) ?></td><td class="num">+<?= qty($m['qty_change']) ?> <?= e($m['unit']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Low stock products</h2><?php if ($admin): ?><a class="btn sm" href="products.php?f=low">View all</a><?php endif; ?></div>
        <?php if (!$low): ?>
            <div class="empty">All products have enough stock.</div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Product</th><th class="num">Stock</th><th class="num">Alert</th></tr></thead>
                <tbody>
                <?php foreach ($low as $p): ?>
                    <tr>
                        <td><?php if ($admin): ?><a href="product_form.php?id=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a><?php else: ?><?= e($p['name']) ?><?php endif; ?></td>
                        <td class="num"><b style="color:<?= (float)$p['stock'] <= 0 ? 'var(--danger)' : 'var(--warn)' ?>"><?= qty($p['stock']) ?></b> <span class="muted small"><?= e($p['unit']) ?></span></td>
                        <td class="num muted"><?= qty(low_stock_level($p)) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="assets/js/picker.js?v=<?= APP_VERSION ?>"></script>
<script>
(function () {
    var csrf = document.querySelector('meta[name=csrf]').content;
    var pick = document.getElementById('pick'), form = document.getElementById('inForm'), info = document.getElementById('pInfo');
    var qIn = document.getElementById('qIn'), pIn = document.getElementById('pIn'), sIn = document.getElementById('sIn');
    var current = null, ADMIN = <?= $admin ? 'true' : 'false' ?>;

    function reset() { current = null; form.hidden = true; pick.value = ''; pick.focus(); }

    new Picker(pick, function (p, missing) {
        if (!p) {
            if (!ADMIN) { alert('"' + missing + '" is not in the system. Ask the owner to add it.'); return; }
            if (confirm('"' + missing + '" is not in the system. Add it as a new product?')) {
                location.href = 'product_form.php?barcode=' + encodeURIComponent(missing);
            }
            return;
        }
        current = p;
        info.textContent = p.name + ' — current stock: ' + p.stock + ' ' + p.unit + (ADMIN ? ' · purchase ' + p.purchase_price + ' · sale ' + p.price : '');
        pIn.value = ''; sIn.value = ''; qIn.value = '';
        pIn.placeholder = p.purchase_price; sIn.placeholder = p.sale_price;
        form.hidden = false;
        qIn.focus();
    });

    [qIn, pIn, sIn].forEach(function (el) {
        el.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') reset(); });
    });
    document.getElementById('cancelIn').addEventListener('click', reset);

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (!current) return;
        fetch('api/stock_in.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf },
            body: JSON.stringify({ product_id: current.id, qty: qIn.value, purchase_price: pIn.value, sale_price: sIn.value })
        }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok) { alert(d.message || 'Something went wrong'); return; }
            var tb = document.getElementById('todayBody');
            var er = tb.querySelector('.empty-row'); if (er) er.remove();
            var tr = document.createElement('tr');
            tr.innerHTML = '<td class="nowrap muted"></td><td></td><td class="num"></td>';
            tr.children[0].textContent = d.product.time;
            tr.children[1].textContent = d.product.name + ' (now ' + d.product.stock + ')';
            tr.children[2].textContent = '+' + d.product.added + ' ' + d.product.unit;
            tb.insertBefore(tr, tb.firstChild);
            reset();
        }).catch(function () { alert('Please check your internet connection.'); });
    });
})();
</script>
<?php layout_end();
