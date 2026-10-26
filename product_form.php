<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/products_lib.php';
require_admin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$product = $id ? find_product($id) : null;
if ($id && !$product) {
    flash('danger', 'Product not found.');
    redirect('products.php');
}
$isEdit = (bool)$product;
$errors = [];

$v = $product ? [
    'barcode' => $product['barcode'], 'name' => $product['name'], 'unit' => $product['unit'],
    'purchase_price' => money_input($product['purchase_price']), 'sale_price' => money_input($product['sale_price']),
    'stock' => qty($product['stock']), 'low_stock' => $product['low_stock'] !== null ? qty($product['low_stock']) : '',
    'quick' => $product['quick_key'] !== null, 'quick_key' => $product['quick_key'] ?? '',
] : [
    'barcode' => $_GET['barcode'] ?? '', 'name' => '', 'unit' => 'pcs', 'purchase_price' => '', 'sale_price' => '',
    'stock' => '', 'low_stock' => '', 'quick' => false, 'quick_key' => '',
];

function money_input($n): string
{
    $n = (float)$n;
    return abs($n - round($n)) < 0.005 ? (string)(int)round($n) : number_format($n, 2, '.', '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $in = $_POST;
    $in['quick'] = !empty($_POST['quick']);
    $res = save_product($in, $isEdit ? (int)$product['id'] : null);
    if ($res['ok']) {
        if ($isEdit) {
            flash('ok', 'Product updated.');
            redirect('products.php');
        }
        flash('ok', ($res['revived'] ?? false ? 'Re-added: ' : 'Saved: ') . trim($_POST['name']) . ' — scan the next product.');
        redirect('product_form.php');
    }
    $errors = $res['errors'];
    $v = array_merge($v, array_intersect_key($_POST, $v));
    $v['quick'] = !empty($_POST['quick']);
}

$addedToday = $isEdit ? 0 : (int)qv('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND created_at >= ?', [date('Y-m-d 00:00:00')]);

layout_start($isEdit ? 'Edit product' : 'New product', 'products');
?>
<div class="page-head">
    <div>
        <h1><?= $isEdit ? 'Edit product' : 'New product' ?></h1>
        <p class="muted"><?= $isEdit ? e($product['name']) : 'Scan the barcode, enter name and price, press Enter.' ?></p>
    </div>
    <div class="row">
        <?php if (!$isEdit && $addedToday): ?><span class="badge ok"><?= $addedToday ?> products added today</span><?php endif; ?>
        <a class="btn" href="products.php">&larr; Products</a>
    </div>
</div>

<div class="card card-pad" style="max-width:720px">
    <?php foreach ($errors as $err): ?><div class="alert danger"><?= e($err) ?></div><?php endforeach; ?>

    <form method="post" id="productForm" class="stack" autocomplete="off">
        <?= csrf_field() ?>
        <label class="field scan-field">Barcode
            <div class="input-group">
                <input name="barcode" id="barcode" value="<?= e($v['barcode']) ?>" placeholder="Scan the product here" <?= $isEdit ? '' : 'autofocus' ?>>
                <button class="btn" type="button" id="genBarcode" title="For items without a barcode">Generate barcode</button>
            </div>
            <span class="hint">Scan the company barcode. For loose items press "Generate barcode", or leave it empty.</span>
        </label>
        <div id="dupBox" class="dup-box" hidden></div>

        <label class="field">Product name
            <input name="name" id="name" value="<?= e($v['name']) ?>" required maxlength="190" placeholder="e.g. Lays Masala 50g" <?= $isEdit ? 'autofocus' : '' ?>>
        </label>

        <div class="grid-3">
            <label class="field">Purchase price (<?= e(currency()) ?>)
                <input name="purchase_price" id="purchase" value="<?= e($v['purchase_price']) ?>" inputmode="decimal" placeholder="0">
            </label>
            <label class="field">Sale price (<?= e(currency()) ?>)
                <input name="sale_price" id="sale" value="<?= e($v['sale_price']) ?>" inputmode="decimal" required placeholder="0">
            </label>
            <label class="field"><?= $isEdit ? 'Stock (to correct it)' : 'Stock (current quantity)' ?>
                <input name="stock" id="stock" value="<?= e($v['stock']) ?>" inputmode="decimal" placeholder="0">
            </label>
        </div>
        <div id="marginHint" class="muted small"></div>

        <details <?= ($v['unit'] !== 'pcs' || $v['quick'] || $v['low_stock'] !== '') ? 'open' : '' ?>>
            <summary class="small" style="cursor:pointer;font-weight:600">More options (unit, quick button, low stock)</summary>
            <div class="stack" style="margin-top:.8rem">
                <div class="grid-2">
                    <label class="field">Unit
                        <select name="unit">
                            <?php foreach (UNITS as $k => $lbl): ?>
                                <option value="<?= e($k) ?>" <?= $v['unit'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="hint">Use "kg" for loose items like sugar or lentils.</span>
                    </label>
                    <label class="field">Low stock alert
                        <input name="low_stock" value="<?= e($v['low_stock']) ?>" inputmode="decimal" placeholder="Default: <?= e(setting('low_stock', '5')) ?>">
                    </label>
                </div>
                <div class="grid-2">
                    <label class="check"><input type="checkbox" name="quick" value="1" <?= $v['quick'] ? 'checked' : '' ?>> Show as a quick button on the billing screen</label>
                    <label class="field">Quick button number
                        <input name="quick_key" value="<?= e($v['quick_key']) ?>" inputmode="numeric" placeholder="e.g. 1">
                        <span class="hint">Type this number in billing and press Enter to add the item.</span>
                    </label>
                </div>
            </div>
        </details>

        <div class="row">
            <button class="btn primary lg" type="submit"><?= $isEdit ? 'Update' : 'Save (Enter)' ?></button>
            <?php if ($isEdit): ?><a class="btn lg" href="labels.php?id=<?= (int)$product['id'] ?>">Label print</a><?php endif; ?>
        </div>
    </form>
</div>

<script>
(function () {
    var form = document.getElementById('productForm');
    var bc = document.getElementById('barcode');
    var dup = document.getElementById('dupBox');
    var order = ['barcode', 'name', 'purchase', 'sale', 'stock'];
    var editId = <?= $isEdit ? (int)$product['id'] : 'null' ?>;
    var csrf = document.querySelector('meta[name=csrf]').content;

    // Enter dabane par agla box, aakhri box par save
    form.addEventListener('keydown', function (ev) {
        if (ev.key !== 'Enter' || ev.target.tagName === 'BUTTON' || ev.target.tagName === 'TEXTAREA') return;
        var idx = order.indexOf(ev.target.id);
        if (idx === -1) return;
        ev.preventDefault();
        if (ev.target.id === 'barcode') { checkBarcode(true); return; }
        var next = order[idx + 1];
        if (next) {
            var el = document.getElementById(next);
            el.focus(); el.select();
        } else {
            form.requestSubmit();
        }
    });

    function checkBarcode(moveOn) {
        var code = bc.value.trim();
        dup.hidden = true;
        if (!code) { if (moveOn) document.getElementById('name').focus(); return; }
        fetch('api/product_check.php?barcode=' + encodeURIComponent(code) + (editId ? '&exclude=' + editId : ''), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.exists) {
                    dup.innerHTML = '';
                    var t = document.createElement('div');
                    t.innerHTML = '<b>This product already exists:</b> ';
                    t.appendChild(document.createTextNode(d.product.name + ' (' + d.product.price + ')'));
                    var a = document.createElement('a');
                    a.className = 'btn sm'; a.style.marginLeft = '.6rem';
                    a.href = 'product_form.php?id=' + d.product.id; a.textContent = 'Edit';
                    t.appendChild(a);
                    dup.appendChild(t);
                    dup.hidden = false;
                    bc.select();
                } else if (moveOn) {
                    document.getElementById('name').focus();
                }
            })
            .catch(function () { if (moveOn) document.getElementById('name').focus(); });
    }
    bc.addEventListener('change', function () { checkBarcode(false); });

    document.getElementById('genBarcode').addEventListener('click', function () {
        fetch('api/barcode_new.php', { credentials: 'same-origin', headers: { 'X-CSRF': csrf } })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d.barcode) { bc.value = d.barcode; dup.hidden = true; document.getElementById('name').focus(); } });
    });

    // Munafa dikhao
    var pur = document.getElementById('purchase'), sale = document.getElementById('sale'), hint = document.getElementById('marginHint');
    function margin() {
        var p = parseFloat(pur.value), s = parseFloat(sale.value);
        if (p > 0 && s > 0) {
            var m = s - p;
            hint.textContent = 'Profit per unit: ' + m.toFixed(m % 1 ? 2 : 0) + ' (' + (m / s * 100).toFixed(1) + '%)';
            hint.style.color = m < 0 ? 'var(--danger)' : '';
        } else { hint.textContent = ''; }
    }
    pur.addEventListener('input', margin); sale.addEventListener('input', margin); margin();
})();
</script>
<?php layout_end();
