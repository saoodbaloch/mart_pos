<?php
declare(strict_types=1);

const UNITS = ['pcs' => 'Piece (pcs)', 'kg' => 'Kilo (kg)', 'ltr' => 'Litre (ltr)', 'dozen' => 'Dozen', 'm' => 'Meter (m)'];

function find_product_by_barcode(string $barcode, bool $withDeleted = false): ?array
{
    return q1('SELECT * FROM products WHERE barcode = ?' . ($withDeleted ? '' : ' AND deleted_at IS NULL'), [$barcode]);
}

function find_product(int $id): ?array
{
    return q1('SELECT * FROM products WHERE id = ? AND deleted_at IS NULL', [$id]);
}

/**
 * Product add ya edit.
 * @return array{ok:bool, id?:int, errors?:string[], revived?:bool}
 */
function save_product(array $in, ?int $id = null, string $source = 'manual'): array
{
    $errors = [];
    $barcode = clean_barcode($in['barcode'] ?? null);
    $name = trim(preg_replace('/\s+/', ' ', (string)($in['name'] ?? '')));
    $unit = (string)($in['unit'] ?? 'pcs');
    if (!isset(UNITS[$unit])) {
        $unit = 'pcs';
    }
    $purchase = r2(str_replace(',', '', (string)($in['purchase_price'] ?? '0')));
    $sale = r2(str_replace(',', '', (string)($in['sale_price'] ?? '0')));
    $stockRaw = trim((string)($in['stock'] ?? ''));
    $stock = r3(str_replace(',', '', $stockRaw === '' ? '0' : $stockRaw));
    $lowRaw = trim((string)($in['low_stock'] ?? ''));
    $low = $lowRaw === '' ? null : r3($lowRaw);
    $quickKey = !empty($in['quick']) ? max(1, (int)($in['quick_key'] ?? 0) ?: 99) : null;

    if ($name === '') {
        $errors[] = 'Enter the product name.';
    }
    if (mb_strlen($name) > 190) {
        $errors[] = 'Name is too long.';
    }
    if ($sale <= 0) {
        $errors[] = 'Enter the sale price.';
    }
    if ($purchase < 0 || $stock < -1000000) {
        $errors[] = 'Invalid price or stock.';
    }
    if ($barcode !== null && !preg_match('/^[\x21-\x7E]{1,64}$/', $barcode)) {
        $errors[] = 'Barcode can only contain numbers and English letters.';
    }

    $revive = null;
    if (!$errors && $barcode !== null) {
        $other = find_product_by_barcode($barcode, true);
        if ($other && (int)$other['id'] !== (int)$id) {
            if ($other['deleted_at'] !== null && $id === null) {
                $revive = $other; // delete hua product dobara add ho raha hai
            } elseif ($other['deleted_at'] !== null) {
                // edit mein purane deleted product ka barcode free kar do
                qexec('UPDATE products SET barcode = NULL WHERE id = ?', [$other['id']]);
            } else {
                $errors[] = 'This barcode already belongs to "' . $other['name'] . '".';
            }
        }
    }
    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    $pdo = db();
    $t = now();
    $pdo->beginTransaction();
    try {
        if ($id === null && $revive) {
            $id = (int)$revive['id'];
            qexec('UPDATE products SET name=?, unit=?, purchase_price=?, sale_price=?, stock=?, low_stock=?, quick_key=?, deleted_at=NULL, updated_at=? WHERE id=?',
                [$name, $unit, $purchase, $sale, $stock, $low, $quickKey, $t, $id]);
            $diff = $stock - (float)$revive['stock'];
            stock_move($id, $diff, 'opening', null, 'Re-added');
            $pdo->commit();
            log_activity('product_add', $name . ' (re-added) — ' . money($sale));
            return ['ok' => true, 'id' => $id, 'revived' => true];
        }
        if ($id === null) {
            qexec('INSERT INTO products (barcode, name, unit, purchase_price, sale_price, stock, low_stock, quick_key, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$barcode, $name, $unit, $purchase, $sale, $stock, $low, $quickKey, $t, $t]);
            $id = (int)$pdo->lastInsertId();
            stock_move($id, $stock, 'opening', null, $source === 'manual' ? 'New product' : ucfirst($source));
            if ($source === 'billing' && !is_admin()) {
                qexec('UPDATE products SET needs_review = 1 WHERE id = ?', [$id]);
            }
            if ($source !== 'import') {
                log_activity($source === 'billing' ? 'new_product_billing' : 'product_add', $name . ' — ' . money($sale) . ($barcode ? ' [' . $barcode . ']' : ''));
            }
        } else {
            $old = find_product($id);
            if (!$old) {
                $pdo->rollBack();
                return ['ok' => false, 'errors' => ['Product not found.']];
            }
            $keepStock = !array_key_exists('stock', $in) || $stockRaw === '';
            $newStock = $keepStock ? (float)$old['stock'] : $stock;
            qexec('UPDATE products SET barcode=?, name=?, unit=?, purchase_price=?, sale_price=?, stock=?, low_stock=?, quick_key=?, needs_review=0, updated_at=? WHERE id=?',
                [$barcode, $name, $unit, $purchase, $sale, $newStock, $low, $quickKey, $t, $id]);
            stock_move($id, $newStock - (float)$old['stock'], 'adjust', null, 'Corrected via edit');
            if ($source !== 'import') {
                $ch = [];
                if (abs((float)$old['sale_price'] - $sale) > 0.004) {
                    $ch[] = 'sale ' . money($old['sale_price']) . ' → ' . money($sale);
                }
                if (abs((float)$old['purchase_price'] - $purchase) > 0.004) {
                    $ch[] = 'purchase ' . money($old['purchase_price']) . ' → ' . money($purchase);
                }
                if (abs((float)$old['stock'] - $newStock) > 0.0005) {
                    $ch[] = 'stock ' . qty($old['stock']) . ' → ' . qty($newStock);
                }
                log_activity($ch && str_contains(implode(' ', $ch), 'sale ') ? 'price_change' : 'product_edit', $name . ($ch ? ': ' . implode(', ', $ch) : ''));
            }
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    return ['ok' => true, 'id' => $id];
}

function delete_product(int $id): void
{
    $p = find_product($id);
    if ($p) {
        log_activity('product_delete', $p['name'] . ($p['barcode'] ? ' [' . $p['barcode'] . ']' : ''));
    }
    qexec('UPDATE products SET deleted_at = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL', [now(), now(), $id]);
}

/** Billing ke liye product ka chhota roop */
function product_public(array $p): array
{
    return [
        'id'       => (int)$p['id'],
        'barcode'  => $p['barcode'],
        'name'     => $p['name'],
        'unit'     => $p['unit'],
        'price'    => (float)$p['sale_price'],
        'stock'    => (float)$p['stock'],
        'quick'    => $p['quick_key'] !== null ? (int)$p['quick_key'] : null,
        'deleted'  => $p['deleted_at'] !== null,
        'updated'  => $p['updated_at'],
    ];
}

function low_stock_level(array $p): float
{
    return $p['low_stock'] !== null ? (float)$p['low_stock'] : (float)setting('low_stock', '5');
}
