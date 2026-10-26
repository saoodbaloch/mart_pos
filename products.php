<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/products_lib.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    require_csrf();
    $p = find_product((int)$_POST['id']);
    if ($p) {
        delete_product((int)$p['id']);
        flash('ok', '"' . $p['name'] . '" deleted.');
    }
    redirect('products.php?' . http_build_query(array_filter(['s' => $_POST['s'] ?? '', 'f' => $_POST['f'] ?? ''])));
}

if (isset($_GET['export'])) {
    $rows = q('SELECT barcode, name, unit, purchase_price, sale_price, stock, low_stock FROM products WHERE deleted_at IS NULL ORDER BY name');
    csv_download('products-' . date('Ymd') . '.csv', ['barcode', 'name', 'unit', 'purchase_price', 'sale_price', 'stock', 'low_stock'],
        array_map(fn($r) => [(string)$r['barcode'], $r['name'], $r['unit'], (float)$r['purchase_price'], (float)$r['sale_price'], (float)$r['stock'], $r['low_stock'] !== null ? (float)$r['low_stock'] : ''], $rows));
}

$s = trim($_GET['s'] ?? '');
$f = $_GET['f'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 50;
$defLow = (float)setting('low_stock', '5');

$where = ['deleted_at IS NULL'];
$params = [];
if ($s !== '') {
    $where[] = '(name LIKE ? OR barcode = ? OR barcode LIKE ?)';
    $params[] = '%' . $s . '%';
    $params[] = $s;
    $params[] = $s . '%';
}
if ($f === 'low') {
    $where[] = 'stock <= COALESCE(low_stock, ?)';
    $params[] = $defLow;
} elseif ($f === 'quick') {
    $where[] = 'quick_key IS NOT NULL';
} elseif ($f === 'nobarcode') {
    $where[] = 'barcode IS NULL';
} elseif ($f === 'review') {
    $where[] = 'needs_review = 1';
}
$w = implode(' AND ', $where);
$total = (int)qv("SELECT COUNT(*) FROM products WHERE $w", $params);
$pages = max(1, (int)ceil($total / $per));
$page = min($page, $pages);
$order = $f === 'quick' ? 'quick_key, name' : 'name';
$rows = q("SELECT * FROM products WHERE $w ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per), $params);
$all = (int)qv('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL');
$reviewCount = (int)qv('SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND needs_review = 1');

layout_start('Products', 'products');
?>
<div class="page-head">
    <div>
        <h1>Products</h1>
        <p class="muted"><?= number_format($all) ?> products in the system</p>
    </div>
    <div class="row">
        <a class="btn" href="import.php">Import from Excel</a>
        <a class="btn" href="products.php?export=1">Export to Excel</a>
        <a class="btn" href="labels.php">Barcode labels</a>
        <a class="btn primary" href="product_form.php">+ New product</a>
    </div>
</div>

<?php if ($reviewCount && $f !== 'review'): ?>
    <div class="alert warn"><?= $reviewCount ?> product(s) were added by staff during billing — please check the price and purchase price.
        <a class="btn sm" href="products.php?f=review" style="margin-left:.5rem">View</a></div>
<?php endif; ?>
<div class="card">
    <form class="toolbar" method="get">
        <input class="input" type="search" name="s" value="<?= e($s) ?>" placeholder="Name or scan barcode" autofocus>
        <select class="input" name="f" style="max-width:190px" onchange="this.form.submit()">
            <option value="">All products</option>
            <option value="low" <?= $f === 'low' ? 'selected' : '' ?>>Low stock</option>
            <option value="quick" <?= $f === 'quick' ? 'selected' : '' ?>>Quick buttons</option>
            <option value="nobarcode" <?= $f === 'nobarcode' ? 'selected' : '' ?>>No barcode</option>
            <option value="review" <?= $f === 'review' ? 'selected' : '' ?>>Needs review (added by staff)</option>
        </select>
        <button class="btn" type="submit">Search</button>
        <?php if ($s !== '' || $f !== ''): ?><a class="btn ghost" href="products.php">Clear</a><?php endif; ?>
        <span class="spacer"></span>
        <span class="muted small"><?= number_format($total) ?> results</span>
    </form>

    <?php if (!$rows): ?>
        <div class="empty">
            <?php if ($all === 0): ?>
                <p><b>No products yet.</b></p>
                <p>Scan a product to add it, or import many at once from an Excel file.</p>
                <a class="btn primary" href="product_form.php">+ Add your first product</a>
            <?php else: ?>
                No products found.
            <?php endif; ?>
        </div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr>
                <th>Product</th><th>Barcode</th><th class="num">Purchase</th><th class="num">Sale</th><th class="num">Stock</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $p):
                $low = (float)$p['stock'] <= low_stock_level($p); ?>
                <tr class="<?= $low ? 'is-low' : '' ?>">
                    <td>
                        <a href="product_form.php?id=<?= (int)$p['id'] ?>"><b><?= e($p['name']) ?></b></a>
                        <?php if ($p['quick_key'] !== null): ?> <span class="badge">Quick <?= (int)$p['quick_key'] ?></span><?php endif; ?>
                        <?php if ((int)$p['needs_review']): ?> <span class="badge warn">Review</span><?php endif; ?>
                    </td>
                    <td class="mono small"><?= $p['barcode'] ? e($p['barcode']) : '<span class="muted">—</span>' ?></td>
                    <td class="num"><?= money($p['purchase_price']) ?></td>
                    <td class="num"><b><?= money($p['sale_price']) ?></b></td>
                    <td class="num">
                        <?= qty($p['stock']) ?> <span class="muted small"><?= e($p['unit']) ?></span>
                        <?php if ($low): ?><br><span class="badge warn">Low</span><?php endif; ?>
                    </td>
                    <td class="actions">
                        <a class="btn sm" href="product_form.php?id=<?= (int)$p['id'] ?>">Edit</a>
                        <a class="btn sm" href="labels.php?id=<?= (int)$p['id'] ?>">Label</a>
                        <form method="post" onsubmit="return confirm('<?= e(addslashes($p['name'])) ?> — delete this product?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                            <input type="hidden" name="s" value="<?= e($s) ?>">
                            <input type="hidden" name="f" value="<?= e($f) ?>">
                            <button class="btn sm danger-ghost" type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?= pager($page, $pages, array_filter(['s' => $s, 'f' => $f])) ?>
    <?php endif; ?>
</div>
<?php layout_end();
