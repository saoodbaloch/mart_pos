<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/products_lib.php';
require_admin();

$preload = [];
if (!empty($_GET['id'])) {
    $p = find_product((int)$_GET['id']);
    if ($p) {
        $preload[] = ['id' => (int)$p['id'], 'barcode' => $p['barcode'], 'name' => $p['name'], 'sale_price' => (float)$p['sale_price'], 'unit' => $p['unit'], 'copies' => 1];
    }
}
$lw = (float)setting('label_width', '38');
$lh = (float)setting('label_height', '25');

layout_start('Barcode labels', 'products');
?>
<style>
.lbl { width: <?= $lw ?>mm; height: <?= $lh ?>mm; background: #fff; color: #000; border: 1px dashed #c9cfca; padding: 1.2mm 1.6mm; display: flex; flex-direction: column; overflow: hidden; font-family: Arial, sans-serif; }
.lbl .ls { font-size: 5.5pt; text-transform: uppercase; letter-spacing: .04em; }
.lbl .ln { font-size: 7pt; font-weight: 700; line-height: 1.1; max-height: 2.2em; overflow: hidden; }
.lbl .lp { font-size: 10pt; font-weight: 800; }
.lbl .lb { flex: 1; min-height: 0; display: flex; align-items: flex-end; justify-content: center; }
.lbl .lb svg { width: 100%; height: 100%; }
.preview { display: flex; flex-wrap: wrap; gap: 8px; padding: 1rem; background: var(--surface-2); min-height: 120px; }
.qty-in { width: 70px; }
#printArea { display: none; }
@media print {
    body * { visibility: hidden; }
    #printArea, #printArea * { visibility: visible; }
    #printArea { display: block; position: absolute; left: 0; top: 0; }
    #printArea .lbl { border: 0; }
    #printArea.roll .lbl { page-break-after: always; break-after: page; }
    #printArea.sheet { display: grid; grid-template-columns: repeat(auto-fill, <?= $lw ?>mm); gap: 2mm; width: 194mm; }
    #printArea.sheet .lbl { border: .2mm dashed #bbb; }
}
</style>

<div class="page-head">
    <div>
        <h1>Barcode labels</h1>
        <p class="muted">Print labels for products without a barcode (loose items). Size: <?= qty($lw) ?> × <?= qty($lh) ?> mm (change in Settings).</p>
    </div>
    <a class="btn" href="products.php">&larr; Products</a>
</div>

<div class="grid-2" style="align-items:start">
    <div class="card">
        <div class="card-head"><h2>Select products</h2></div>
        <div class="card-pad stack">
            <div><input class="input" id="pick" placeholder="Scan product or type name" autofocus></div>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Product</th><th>Barcode</th><th class="num">Labels</th><th></th></tr></thead>
                    <tbody id="queue"><tr><td colspan="4" class="empty">No products selected yet.</td></tr></tbody>
                </table>
            </div>
            <div class="row">
                <select class="input" id="mode" style="max-width:240px">
                    <option value="roll">Label printer (roll)</option>
                    <option value="sheet">A4 sticker sheet</option>
                </select>
                <button class="btn primary" id="printBtn" type="button">Print labels</button>
            </div>
            <p class="muted small" id="warn"></p>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><h2>Preview</h2></div>
        <div class="preview" id="preview"></div>
    </div>
</div>
<div id="printArea"></div>

<script>
window.LABEL_DATA = {
    shop: <?= json_encode(setting('shop_name', ''), JSON_UNESCAPED_UNICODE) ?>,
    currency: <?= json_encode(currency()) ?>,
    preload: <?= json_encode($preload, JSON_UNESCAPED_UNICODE) ?>
};
</script>
<script src="assets/js/barcode.js?v=<?= APP_VERSION ?>"></script>
<script src="assets/js/picker.js?v=<?= APP_VERSION ?>"></script>
<script>
(function () {
    var D = window.LABEL_DATA, queue = D.preload.slice();
    var qb = document.getElementById('queue'), pv = document.getElementById('preview'), warn = document.getElementById('warn');

    function fmt(n) { n = +n; return (Math.abs(n - Math.round(n)) < 0.005 ? Math.round(n) : n.toFixed(2)).toLocaleString('en-US'); }

    function labelHtml(p) {
        var d = document.createElement('div');
        d.className = 'lbl';
        d.innerHTML = '<div class="ls"></div><div class="ln"></div><div class="lp"></div><div class="lb"></div>';
        d.querySelector('.ls').textContent = D.shop;
        d.querySelector('.ln').textContent = p.name;
        d.querySelector('.lp').textContent = D.currency + ' ' + fmt(p.sale_price) + (p.unit !== 'pcs' ? ' / ' + p.unit : '');
        if (p.barcode) d.querySelector('.lb').innerHTML = Barcode.svg(p.barcode, { height: 40, module: 1 });
        return d;
    }

    function render() {
        qb.innerHTML = '';
        if (!queue.length) qb.innerHTML = '<tr><td colspan="4" class="empty">No products selected yet.</td></tr>';
        queue.forEach(function (p, i) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<td><b></b></td><td class="mono small"></td><td class="num"><input class="input qty-in" type="number" min="1" max="500"></td><td class="actions"><button class="btn sm danger-ghost" type="button">Remove</button></td>';
            tr.querySelector('b').textContent = p.name;
            tr.children[1].textContent = p.barcode || '—';
            var inp = tr.querySelector('input');
            inp.value = p.copies;
            inp.addEventListener('input', function () { p.copies = Math.max(1, Math.min(500, parseInt(inp.value, 10) || 1)); });
            tr.querySelector('button').addEventListener('click', function () { queue.splice(i, 1); render(); });
            qb.appendChild(tr);
        });
        pv.innerHTML = '';
        queue.forEach(function (p) { pv.appendChild(labelHtml(p)); });
        var nob = queue.filter(function (p) { return !p.barcode; });
        warn.textContent = nob.length ? nob.length + ' product(s) have no barcode — edit the product and press "Generate barcode".' : '';
    }

    new Picker(document.getElementById('pick'), function (p, missing) {
        if (!p) { warn.textContent = '"' + missing + '" not found.'; return; }
        var ex = queue.filter(function (q) { return q.id === p.id; })[0];
        if (ex) ex.copies++;
        else queue.push({ id: p.id, barcode: p.barcode, name: p.name, sale_price: p.sale_price, unit: p.unit, copies: 1 });
        render();
    });

    document.getElementById('printBtn').addEventListener('click', function () {
        if (!queue.length) return;
        var mode = document.getElementById('mode').value;
        var area = document.getElementById('printArea');
        area.className = mode;
        area.innerHTML = '';
        queue.forEach(function (p) { for (var i = 0; i < p.copies; i++) area.appendChild(labelHtml(p)); });
        var st = document.getElementById('pageSize') || document.head.appendChild(Object.assign(document.createElement('style'), { id: 'pageSize' }));
        st.textContent = mode === 'roll'
            ? '@page { size: <?= $lw ?>mm <?= $lh ?>mm; margin: 0; }'
            : '@page { size: A4; margin: 8mm; }';
        window.print();
    });

    render();
})();
</script>
<?php layout_end();
