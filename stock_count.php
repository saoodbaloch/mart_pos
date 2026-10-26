<?php
/** Stock ginti (stock taking): shelf par jo hai woh gino, system theek karo */
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/products_lib.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_api_like()) {
    require_csrf();
    $in = json_input();
    $p = find_product((int)($in['product_id'] ?? 0));
    $counted = str_replace(',', '', trim((string)($in['counted'] ?? '')));
    if (!$p || !is_numeric($counted) || (float)$counted < 0) {
        json_out(['ok' => false, 'message' => 'Invalid product or count.'], 422);
    }
    $counted = r3($counted);
    $diff = r3($counted - (float)$p['stock']);
    if (abs($diff) >= 0.0005) {
        change_stock((int)$p['id'], $diff, 'count', null, 'Stock count');
    }
    $value = r2($diff * (float)$p['purchase_price']);
    log_activity('stock_count', $p['name'] . ': system ' . qty($p['stock']) . ', counted ' . qty($counted) . ' (' . ($diff > 0 ? '+' : '') . qty($diff) . ', ' . money($value) . ')');
    json_out(['ok' => true, 'row' => [
        'name' => $p['name'], 'unit' => $p['unit'], 'system' => qty($p['stock']), 'counted' => qty($counted),
        'diff' => (float)$diff, 'diff_txt' => ($diff > 0 ? '+' : '') . qty($diff), 'value' => $value, 'value_txt' => money($value), 'time' => date('h:i A'),
    ]]);
}

function is_api_like(): bool
{
    return str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json');
}

$recent = q("SELECT m.*, p.name, p.unit, p.purchase_price FROM stock_moves m JOIN products p ON p.id = m.product_id
             WHERE m.type = 'count' AND m.created_at >= ? ORDER BY m.id DESC LIMIT 300", [date('Y-m-d H:i:s', strtotime('-30 days'))]);
$loss = 0.0;
$gain = 0.0;
foreach ($recent as $r) {
    $v = (float)$r['qty_change'] * (float)$r['purchase_price'];
    $v < 0 ? $loss += $v : $gain += $v;
}

layout_start('Stock count', 'stock');
?>
<div class="page-head">
    <div>
        <h1>Stock count</h1>
        <p class="muted">Count the shelf stock once a month. Scan a product and enter the actual quantity — the system corrects itself and records the difference (theft / damaged goods).</p>
    </div>
    <a class="btn" href="stock.php">&larr; Stock</a>
</div>

<div class="cards">
    <div class="card stat"><div class="label">Last 30 days: shortage</div><div class="value" style="color:var(--danger)"><?= money(-$loss) ?></div><div class="sub">At cost price</div></div>
    <div class="card stat"><div class="label">Excess</div><div class="value" style="color:var(--ok)"><?= money($gain) ?></div></div>
</div>

<div class="grid-2" style="align-items:start">
    <div class="card card-pad stack">
        <h2>Count</h2>
        <div><input class="input" id="pick" placeholder="Scan product or type name" autofocus></div>
        <form id="cForm" class="stack" hidden>
            <div class="alert info" id="cInfo"></div>
            <label class="field">Actual quantity on the shelf <input id="cVal" inputmode="decimal" required style="font-size:1.4rem;font-weight:700"></label>
            <div class="row"><button class="btn primary" type="submit">Save (Enter)</button><button class="btn ghost" type="button" id="cCancel">Cancel</button></div>
        </form>
        <p class="muted small">Tip: count after closing so no sales happen during the count.</p>
    </div>
    <div class="card">
        <div class="card-head"><h2>Count history (30 days)</h2></div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Time</th><th>Product</th><th class="num">Difference</th><th class="num">Value</th></tr></thead>
                <tbody id="cBody">
                <?php if (!$recent): ?><tr class="empty-row"><td colspan="4" class="empty">No counts yet.</td></tr><?php endif; ?>
                <?php foreach ($recent as $r): $v = (float)$r['qty_change'] * (float)$r['purchase_price']; ?>
                    <tr><td class="nowrap muted small"><?= e(date('d-m h:i A', strtotime($r['created_at']))) ?></td><td><?= e($r['name']) ?></td>
                        <td class="num" style="color:<?= (float)$r['qty_change'] < 0 ? 'var(--danger)' : 'var(--ok)' ?>"><?= ((float)$r['qty_change'] > 0 ? '+' : '') . qty($r['qty_change']) ?> <span class="muted small"><?= e($r['unit']) ?></span></td>
                        <td class="num"><?= money($v) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="assets/js/picker.js?v=<?= APP_VERSION ?>"></script>
<script>
(function () {
    var csrf = document.querySelector('meta[name=csrf]').content;
    var pick = document.getElementById('pick'), form = document.getElementById('cForm'), info = document.getElementById('cInfo'), val = document.getElementById('cVal');
    var cur = null;
    function reset() { cur = null; form.hidden = true; pick.value = ''; pick.focus(); }
    new Picker(pick, function (p, missing) {
        if (!p) { alert('"' + missing + '" is not in the system.'); return; }
        cur = p;
        info.textContent = p.name + ' — in system: ' + p.stock + ' ' + p.unit;
        val.value = ''; form.hidden = false; val.focus();
    });
    val.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') reset(); });
    document.getElementById('cCancel').addEventListener('click', reset);
    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (!cur) return;
        fetch('stock_count.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf },
            body: JSON.stringify({ product_id: cur.id, counted: val.value }) })
            .then(function (r) { return r.json(); }).then(function (d) {
                if (!d.ok) { alert(d.message || 'Something went wrong'); return; }
                var tb = document.getElementById('cBody'), er = tb.querySelector('.empty-row'); if (er) er.remove();
                var tr = document.createElement('tr');
                tr.innerHTML = '<td class="nowrap muted small"></td><td></td><td class="num"></td><td class="num"></td>';
                tr.children[0].textContent = d.row.time;
                tr.children[1].textContent = d.row.name + ' (system ' + d.row.system + ' → ' + d.row.counted + ')';
                tr.children[2].textContent = d.row.diff_txt + ' ' + d.row.unit;
                tr.children[2].style.color = d.row.diff < 0 ? 'var(--danger)' : 'var(--ok)';
                tr.children[3].textContent = d.row.value_txt;
                tb.insertBefore(tr, tb.firstChild);
                reset();
            }).catch(function () { alert('Please check your internet connection.'); });
    });
})();
</script>
<?php layout_end();
