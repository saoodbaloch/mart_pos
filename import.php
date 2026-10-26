<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/products_lib.php';
require __DIR__ . '/inc/xlsx.php';
require_admin();

// Template file download
if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="martpos-products-template.csv"');
    echo "\xEF\xBB\xBF";
    echo "barcode,name,purchase_price,sale_price,stock,unit\n";
    echo "8964000123456,Lays Masala 50g,45,50,24,pcs\n";
    echo ",Sugar (loose),140,155,50,kg\n";
    exit;
}

$result = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    @set_time_limit(300);
    $file = $_FILES['file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload failed. Please select the file again.';
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        try {
            if ($ext === 'xlsx') {
                $rows = read_xlsx_rows($file['tmp_name']);
            } elseif (in_array($ext, ['csv', 'txt'], true)) {
                $rows = read_csv_rows($file['tmp_name']);
            } else {
                throw new RuntimeException('Only .xlsx or .csv files are accepted. (Save old .xls files as .xlsx in Excel.)');
            }
            $result = import_rows($rows, !empty($_POST['update']), !empty($_POST['update_stock']));
            log_activity('import', $result['added'] . ' added, ' . $result['updated'] . ' updated (' . $file['name'] . ')');
        } catch (Throwable $ex) {
            $error = $ex->getMessage();
        }
    }
}

function import_rows(array $rows, bool $update, bool $updateStock): array
{
    $aliases = [
        'barcode' => ['barcode', 'bar code', 'code', 'ean', 'upc', 'sku'],
        'name' => ['name', 'product', 'product name', 'item', 'item name', 'naam', 'description'],
        'purchase_price' => ['purchase_price', 'purchase price', 'purchase', 'cost', 'cost price', 'buy price', 'tp'],
        'sale_price' => ['sale_price', 'sale price', 'sale', 'price', 'retail', 'retail price', 'mrp', 'rp'],
        'stock' => ['stock', 'qty', 'quantity', 'opening stock'],
        'unit' => ['unit', 'uom'],
    ];
    $map = [];
    $header = array_map(fn($h) => strtolower(trim((string)$h)), $rows[0] ?? []);
    foreach ($header as $i => $h) {
        foreach ($aliases as $field => $names) {
            if (!isset($map[$field]) && in_array($h, $names, true)) {
                $map[$field] = $i;
            }
        }
    }
    $hasHeader = isset($map['name']);
    if ($hasHeader) {
        array_shift($rows); // header row
    } else {
        $map = ['barcode' => 0, 'name' => 1, 'purchase_price' => 2, 'sale_price' => 3, 'stock' => 4, 'unit' => 5];
    }

    $res = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
    $line = $hasHeader ? 1 : 0;
    foreach ($rows as $r) {
        $line++;
        $get = fn($f) => isset($map[$f]) ? trim((string)($r[$map[$f]] ?? '')) : '';
        $in = [
            'barcode' => $get('barcode'),
            'name' => $get('name'),
            'purchase_price' => $get('purchase_price') ?: '0',
            'sale_price' => $get('sale_price'),
            'stock' => $get('stock'),
            'unit' => strtolower($get('unit')) ?: 'pcs',
        ];
        if ($in['name'] === '' && $in['barcode'] === '' && $in['sale_price'] === '') {
            continue; // khaali line
        }
        $in['barcode'] = preg_replace('/\.0+$/', '', $in['barcode']);

        $existing = null;
        if ($in['barcode'] !== '') {
            $existing = find_product_by_barcode($in['barcode']);
        } elseif ($in['name'] !== '') {
            $existing = q1('SELECT * FROM products WHERE barcode IS NULL AND name = ? AND deleted_at IS NULL', [$in['name']]);
        }

        if ($existing) {
            if (!$update) {
                $res['skipped']++;
                continue;
            }
            if (!$updateStock) {
                unset($in['stock']);
            }
            if ($in['purchase_price'] === '0' && $get('purchase_price') === '') {
                $in['purchase_price'] = (string)$existing['purchase_price'];
            }
            if ($existing['quick_key'] !== null) {
                $in['quick'] = true;
                $in['quick_key'] = $existing['quick_key'];
            }
            $in['low_stock'] = $existing['low_stock'] !== null ? (string)$existing['low_stock'] : '';
            $out = save_product($in, (int)$existing['id'], 'import');
            $key = 'updated';
        } else {
            $out = save_product($in, null, 'import');
            $key = 'added';
        }
        if ($out['ok']) {
            $res[$key]++;
        } else {
            $res['skipped']++;
            if (count($res['errors']) < 25) {
                $res['errors'][] = "Line $line (" . ($in['name'] ?: $in['barcode']) . '): ' . implode(' ', $out['errors']);
            }
        }
    }
    return $res;
}

layout_start('Import', 'products');
?>
<div class="page-head">
    <div>
        <h1>Import products from Excel</h1>
        <p class="muted">To add all your products at once when you start.</p>
    </div>
    <a class="btn" href="products.php">&larr; Products</a>
</div>

<?php if ($error): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?>

<?php if ($result): ?>
    <div class="cards">
        <div class="card stat"><div class="label">Added</div><div class="value"><?= $result['added'] ?></div></div>
        <div class="card stat"><div class="label">Updated</div><div class="value"><?= $result['updated'] ?></div></div>
        <div class="card stat"><div class="label">Skipped</div><div class="value"><?= $result['skipped'] ?></div></div>
    </div>
    <?php if ($result['errors']): ?>
        <div class="alert warn"><b>Some lines had problems:</b><br><?= implode('<br>', array_map('e', $result['errors'])) ?></div>
    <?php endif; ?>
<?php endif; ?>

<div class="grid-2" style="align-items:start">
    <div class="card card-pad">
        <form method="post" enctype="multipart/form-data" class="stack">
            <?= csrf_field() ?>
            <label class="field">Excel (.xlsx) or CSV file
                <input type="file" name="file" accept=".xlsx,.csv,.txt" required>
            </label>
            <label class="check"><input type="checkbox" name="update" value="1" checked> Update name and price of products that already exist</label>
            <label class="check"><input type="checkbox" name="update_stock" value="1"> Also replace stock of existing products with the file value</label>
            <button class="btn primary lg" type="submit">Import</button>
        </form>
    </div>
    <div class="card card-pad stack">
        <h2>File format</h2>
        <p class="muted">The first row should have these headings (any order):</p>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>barcode</th><th>name</th><th>purchase_price</th><th>sale_price</th><th>stock</th><th>unit</th></tr></thead>
                <tbody>
                    <tr><td class="mono small">8964000123456</td><td>Lays Masala 50g</td><td>45</td><td>50</td><td>24</td><td>pcs</td></tr>
                    <tr><td class="muted">(empty)</td><td>Sugar (loose)</td><td>140</td><td>155</td><td>50</td><td>kg</td></tr>
                </tbody>
            </table>
        </div>
        <p class="muted small">Only <b>name</b> and <b>sale_price</b> are required. Keep the barcode column in "Text" format in Excel so long numbers are not changed.</p>
        <a class="btn" href="import.php?template=1">Download template</a>
    </div>
</div>
<?php layout_end();
