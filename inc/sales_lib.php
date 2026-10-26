<?php
declare(strict_types=1);

require_once __DIR__ . '/customers_lib.php';

/**
 * Billing screen se aaya bill save karo. Ek hi bill do dafa aaye (uuid same)
 * to dobara save nahi hota — offline sync ke liye zaroori.
 *
 * payments: [{method, amount, ref}] — cash ka amount = customer ne jitne diye
 * (wapsi samet); baqi methods ka amount = bill mein lagne wali raqam.
 * @return array{id:int, duplicate:bool}
 */
function create_sale(array $s): array
{
    $uuid = (string)($s['uuid'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9-]{8,40}$/', $uuid)) {
        throw new SaleError('Invalid bill ID (uuid).');
    }
    $existing = qv('SELECT id FROM sales WHERE uuid = ?', [$uuid]);
    if ($existing) {
        return ['id' => (int)$existing, 'duplicate' => true];
    }

    $items = is_array($s['items'] ?? null) ? $s['items'] : [];
    if (!$items) {
        throw new SaleError('The bill has no items.');
    }
    if (count($items) > 500) {
        throw new SaleError('The bill has too many items.');
    }

    $clean = [];
    $subtotal = 0.0;
    foreach ($items as $it) {
        $q = r3($it['qty'] ?? 0);
        $price = r2($it['price'] ?? -1);
        $name = trim((string)($it['name'] ?? ''));
        if ($q <= 0 || $price < 0 || $name === '') {
            throw new SaleError('One of the bill items is invalid.');
        }
        $line = r2($q * $price);
        $subtotal += $line;
        $clean[] = [
            'product_id' => (int)($it['product_id'] ?? 0),
            'barcode' => clean_barcode($it['barcode'] ?? null),
            'name' => mb_substr($name, 0, 190),
            'unit' => preg_replace('/[^a-z]/', '', (string)($it['unit'] ?? 'pcs')) ?: 'pcs',
            'qty' => $q,
            'price' => $price,
            'line' => $line,
        ];
    }
    $subtotal = r2($subtotal);
    $discount = min(max(0, r2($s['discount'] ?? 0)), $subtotal);
    $total = r2($subtotal - $discount);

    // ----- Payments -----
    $pays = is_array($s['payments'] ?? null) ? $s['payments'] : [];
    if (!$pays) { // purana billing screen: sab cash
        $pays = [['method' => 'cash', 'amount' => max(r2($s['paid'] ?? $total), $total)]];
    }
    $cashTendered = 0.0;
    $other = [];
    $udhaar = 0.0;
    foreach ($pays as $p) {
        $m = (string)($p['method'] ?? '');
        $amt = r2($p['amount'] ?? 0);
        if (!isset(PAY_METHODS[$m]) || $amt < 0) {
            throw new SaleError('Invalid payment method.');
        }
        if ($amt == 0.0) {
            continue;
        }
        if ($m === 'cash') {
            $cashTendered += $amt;
        } else {
            $other[] = ['method' => $m, 'amount' => $amt, 'ref' => mb_substr(trim((string)($p['ref'] ?? '')), 0, 80) ?: null];
            if ($m === 'udhaar') {
                $udhaar += $amt;
            }
        }
    }
    $otherSum = r2(array_sum(array_column($other, 'amount')));
    if ($otherSum > $total + 0.01) {
        throw new SaleError('Non-cash payments are more than the bill total.');
    }
    $cashNeeded = r2($total - $otherSum);
    if ($cashTendered + 0.01 < $cashNeeded) {
        throw new SaleError('Payment incomplete: ' . money($cashNeeded - $cashTendered) . ' short.');
    }
    $cashApplied = $cashNeeded;
    $change = r2($cashTendered - $cashApplied);

    // Bill se zyada cash, customer ke purane udhaar mein (sirf utna jitna change bana aur jitna baqi hai)
    $balancePay = max(0.0, min(r2($s['credit_payment'] ?? 0), $change));

    $customer = null;
    $prevBalance = 0.0;
    if ($udhaar > 0 || $balancePay > 0) {
        if (empty($s['customer']) || !is_array($s['customer'])) {
            throw new SaleError('Select a customer for credit.');
        }
        $customer = find_or_create_customer($s['customer']);
        $prevBalance = customer_balance((int)$customer['id']);
        $balancePay = r2(min($balancePay, max(0.0, $prevBalance)));
    }
    $change = r2($change - $balancePay);
    $paid = r2($cashTendered + $otherSum - $balancePay);

    // ----- Kis ne banaya -----
    $user = null;
    if (!empty($s['user_id'])) {
        $user = q1('SELECT id, username, full_name FROM users WHERE id = ?', [(int)$s['user_id']]);
    }
    $me = current_user();
    $userId = $user ? (int)$user['id'] : ($me['id'] ?? null);
    $cashier = $user ? ($user['full_name'] ?: $user['username']) : ($me['name'] ?? null);

    $created = (string)($s['created_at'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $created) || strtotime($created) > time() + 86400) {
        $created = now();
    }
    $billNo = preg_replace('/[^A-Za-z0-9-]/', '', (string)($s['bill_no'] ?? '')) ?: date('ymd-His');
    $billNo = substr($billNo, 0, 30);
    if (qv('SELECT 1 FROM sales WHERE bill_no = ?', [$billNo])) {
        $billNo .= '-' . substr(str_replace('-', '', $uuid), 0, 4);
    }

    $rateDrops = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        qexec('INSERT INTO sales (uuid, bill_no, created_at, synced_at, items_count, subtotal, discount, total, paid, change_amount, status, user_id, cashier, customer_id)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$uuid, $billNo, $created, now(), count($clean), $subtotal, $discount, $total, $paid, $change, 'completed',
             $userId, $cashier ? mb_substr($cashier, 0, 80) : null, $customer ? (int)$customer['id'] : null]);
        $saleId = (int)$pdo->lastInsertId();

        foreach ($clean as $it) {
            $product = null;
            if ($it['product_id'] > 0) {
                $product = q1('SELECT id, purchase_price, sale_price FROM products WHERE id = ?', [$it['product_id']]);
            }
            if (!$product && $it['barcode'] !== null) {
                $product = q1('SELECT id, purchase_price, sale_price FROM products WHERE barcode = ?', [$it['barcode']]);
            }
            if ($product && $it['price'] < (float)$product['sale_price'] - 0.01) {
                $rateDrops[] = $it['name'] . ' ' . money($product['sale_price']) . ' → ' . money($it['price']);
            }
            $pid = $product ? (int)$product['id'] : null;
            qexec('INSERT INTO sale_items (sale_id, product_id, barcode, name, unit, qty, price, cost, line_total) VALUES (?,?,?,?,?,?,?,?,?)',
                [$saleId, $pid, $it['barcode'], $it['name'], $it['unit'], $it['qty'], $it['price'], $product ? r2($product['purchase_price']) : 0, $it['line']]);
            if ($pid) {
                change_stock($pid, -$it['qty'], 'sale', $saleId, 'Bill ' . $billNo);
            }
        }

        $ins = $pdo->prepare('INSERT INTO sale_payments (sale_id, method, amount, ref, created_at) VALUES (?,?,?,?,?)');
        if ($cashApplied > 0) {
            $ins->execute([$saleId, 'cash', $cashApplied, null, $created]);
        }
        foreach ($other as $p) {
            $ins->execute([$saleId, $p['method'], $p['amount'], $p['ref'], $created]);
        }

        if ($customer && $udhaar > 0) {
            qexec('INSERT INTO customer_ledger (customer_id, type, amount, sale_id, note, user_id, created_at) VALUES (?,?,?,?,?,?,?)',
                [$customer['id'], 'sale', r2($udhaar), $saleId, 'Bill ' . $billNo, $userId, $created]);
        }
        if ($customer && $balancePay > 0) {
            qexec('INSERT INTO customer_ledger (customer_id, type, amount, method, sale_id, note, user_id, created_at) VALUES (?,?,?,?,?,?,?,?)',
                [$customer['id'], 'payment', -$balancePay, 'cash', $saleId, 'Paid with Bill ' . $billNo, $userId, $created]);
        }
        if ($customer) {
            qexec('UPDATE customers SET updated_at = ? WHERE id = ?', [now(), $customer['id']]);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        // Dono taraf se ek hi waqt aaya ho (do tabs) to duplicate ko kamyab samjho
        $existing = qv('SELECT id FROM sales WHERE uuid = ?', [$uuid]);
        if ($existing) {
            return ['id' => (int)$existing, 'duplicate' => true];
        }
        throw $ex;
    }

    $who = ['id' => $userId, 'name' => $cashier ?? '-'];

    // Server par bhi check: staff ne limit tori aur PIN ki ijazat nahi thi?
    // (Bill offline bhi ban sakta hai, is liye rokte nahi — owner ko alert)
    $role = $userId ? qv('SELECT role FROM users WHERE id = ?', [$userId]) : null;
    if ($role === 'staff') {
        $whats = implode(' | ', array_map(fn($a) => (string)($a['what'] ?? ''), (array)($s['approvals'] ?? [])));
        $lim = setting('staff_discount_pct', '');
        if ($lim !== '' && $lim !== null && $discount > 0 && ($discount / max($subtotal, 0.01) * 100) > (float)$lim + 0.01 && !str_contains($whats, 'Discount')) {
            log_activity('limit_breach', 'Discount ' . number_format($discount / $subtotal * 100, 1) . '% (limit ' . $lim . '%) WITHOUT PIN — Bill ' . $billNo, $who);
        }
        if ($rateDrops && !str_contains($whats, 'Rate')) {
            log_activity('limit_breach', 'Rate lowered WITHOUT PIN: ' . implode(', ', array_slice($rateDrops, 0, 5)) . ' — Bill ' . $billNo, $who);
        }
        if ($customer) {
            $cl = customer_limit($customer);
            if ($cl !== null && $prevBalance + $udhaar > $cl + 0.01 && !str_contains($whats, 'Udhaar') && !str_contains($whats, 'Credit')) {
                log_activity('limit_breach', 'Credit limit exceeded WITHOUT PIN: ' . $customer['name'] . ' ' . money($prevBalance + $udhaar) . ' (limit ' . money($cl) . ') — Bill ' . $billNo, $who);
            }
        }
    }
    if ($customer && $udhaar > 0) {
        log_activity('udhaar', $customer['name'] . ': ' . money($udhaar) . ' (Bill ' . $billNo . ')', $who);
    }
    if ($customer && $balancePay > 0) {
        log_activity('udhaar_payment', $customer['name'] . ': ' . money($balancePay) . ' (Cash, with Bill ' . $billNo . ')', $who);
    }
    if ($discount > 0) {
        log_activity('discount', money($discount) . ' (' . number_format($discount / max($subtotal, 0.01) * 100, 1) . '%) Bill ' . $billNo, $who);
    }
    foreach ((array)($s['approvals'] ?? []) as $a) {
        $admin = !empty($a['admin_id']) ? q1('SELECT full_name, username FROM users WHERE id = ?', [(int)$a['admin_id']]) : null;
        log_activity('pin_override', mb_substr((string)($a['what'] ?? ''), 0, 120) . ' — approved by: ' . ($admin ? ($admin['full_name'] ?: $admin['username']) : '?') . ' (Bill ' . $billNo . ')', $who);
    }
    return ['id' => $saleId, 'duplicate' => false];
}

/** Billing screen se naya product (jo scan hua magar system mein nahi tha) */
function quick_create_product(array $p): array
{
    require_once __DIR__ . '/products_lib.php';
    $barcode = clean_barcode($p['barcode'] ?? null);
    if ($barcode !== null) {
        $ex = find_product_by_barcode($barcode);
        if ($ex) {
            return ['id' => (int)$ex['id'], 'existing' => true];
        }
    }
    $res = save_product([
        'barcode' => $barcode,
        'name' => $p['name'] ?? '',
        'unit' => $p['unit'] ?? 'pcs',
        'sale_price' => $p['price'] ?? 0,
        'purchase_price' => is_admin() ? ($p['purchase_price'] ?? 0) : 0,
        'stock' => '0',
    ], null, 'billing');
    if (!$res['ok']) {
        throw new SaleError(implode(' ', $res['errors']));
    }
    return ['id' => $res['id'], 'existing' => false];
}

function load_sale(int $id): ?array
{
    $sale = q1('SELECT s.*, c.name AS customer_name, c.phone AS customer_phone FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE s.id = ?', [$id]);
    if ($sale) {
        $sale['items'] = q('SELECT * FROM sale_items WHERE sale_id = ? ORDER BY id', [$id]);
        $sale['payments'] = q('SELECT * FROM sale_payments WHERE sale_id = ? ORDER BY id', [$id]);
    }
    return $sale;
}

/** Is bill ka kitna udhaar abhi baqi hai jo return se kam ho sakta hai */
function sale_udhaar_left(array $sale): float
{
    if (!$sale['customer_id']) {
        return 0.0;
    }
    return max(0, r2(qv('SELECT COALESCE(SUM(amount),0) FROM customer_ledger WHERE sale_id = ? AND type IN (\'sale\', \'return\', \'cancel\')', [$sale['id']])));
}

/**
 * Items wapas. $lines = [sale_item_id => qty]
 * Bill par discount tha to wapsi bhi usi hisaab se kam.
 * Udhaar wala bill ho to pehle udhaar kam hota hai, baqi cash wapas.
 * @return array{refund:float, cash:float, udhaar:float}
 */
function return_items(int $saleId, array $lines): array
{
    $sale = load_sale($saleId);
    if (!$sale || $sale['status'] !== 'completed') {
        throw new SaleError('This bill is not available for return.');
    }
    $factor = (float)$sale['subtotal'] > 0 ? ((float)$sale['total'] / (float)$sale['subtotal']) : 1.0;
    $byId = [];
    foreach ($sale['items'] as $it) {
        $byId[(int)$it['id']] = $it;
    }
    $me = current_user();

    $pdo = db();
    $pdo->beginTransaction();
    $refund = 0.0;
    $rows = [];
    try {
        foreach ($lines as $itemId => $q) {
            $q = r3($q);
            if ($q <= 0) {
                continue;
            }
            $it = $byId[(int)$itemId] ?? null;
            if (!$it) {
                throw new SaleError('This item is not on this bill.');
            }
            $left = (float)$it['qty'] - (float)$it['returned_qty'];
            if ($q > $left + 0.0005) {
                throw new SaleError('Cannot return more than ' . qty($left) . ' of "' . $it['name'] . '".');
            }
            $amount = r2($q * (float)$it['price'] * $factor);
            $refund += $amount;
            $rows[] = [$it, $q, $amount];
        }
        if ($refund <= 0) {
            throw new SaleError('Enter the return quantity.');
        }
        $refund = r2($refund);
        $udhaarPart = min($refund, sale_udhaar_left($sale));
        $cashPart = r2($refund - $udhaarPart);
        $method = $udhaarPart > 0 ? ($cashPart > 0 ? 'mixed' : 'udhaar') : 'cash';

        foreach ($rows as [$it, $q, $amount]) {
            qexec('INSERT INTO returns (sale_id, sale_item_id, qty, amount, cost_amount, created_at, user_id, refund_method) VALUES (?,?,?,?,?,?,?,?)',
                [$saleId, $it['id'], $q, $amount, r2($q * (float)$it['cost']), now(), $me['id'] ?? null, $method]);
            qexec('UPDATE sale_items SET returned_qty = returned_qty + ? WHERE id = ?', [$q, $it['id']]);
            if ($it['product_id']) {
                change_stock((int)$it['product_id'], $q, 'return', $saleId, 'Bill ' . $sale['bill_no']);
            }
        }
        qexec('UPDATE sales SET returned_amount = returned_amount + ? WHERE id = ?', [$refund, $saleId]);
        if ($udhaarPart > 0) {
            qexec('INSERT INTO customer_ledger (customer_id, type, amount, sale_id, note, user_id, created_at) VALUES (?,?,?,?,?,?,?)',
                [$sale['customer_id'], 'return', -$udhaarPart, $saleId, 'Return, Bill ' . $sale['bill_no'], $me['id'] ?? null, now()]);
        }
        if ($cashPart > 0) {
            qexec("INSERT INTO cash_moves (type, category, amount, note, user_id, created_at) VALUES ('refund', 'Return', ?, ?, ?, ?)",
                [$cashPart, 'Bill ' . $sale['bill_no'], $me['id'] ?? null, now()]);
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    $names = implode(', ', array_map(fn($r) => $r[0]['name'] . ' ×' . qty($r[1]), $rows));
    log_activity('sale_return', 'Bill ' . $sale['bill_no'] . ': ' . $names . ' — ' . money($refund) . ($udhaarPart > 0 ? ' (' . money($udhaarPart) . ' taken off credit)' : ''));
    return ['refund' => $refund, 'cash' => $cashPart, 'udhaar' => r2($udhaarPart)];
}

/**
 * Cancel sirf usi din (industry rule: void same day). Purane din ka bill
 * cancel karne se us din ka band hua hisaab badal jata — is liye Return.
 */
function sale_can_cancel(array $sale): bool
{
    return business_day((string)$sale['created_at']) === business_day();
}

/** Poora bill cancel: stock wapas, udhaar wapas, report mein shamil nahi */
function cancel_sale(int $saleId): void
{
    $sale = load_sale($saleId);
    if (!$sale || $sale['status'] !== 'completed') {
        throw new SaleError('This bill cannot be cancelled.');
    }
    if (!sale_can_cancel($sale)) {
        throw new SaleError('Bills from a previous day cannot be cancelled. Return the items instead — the refund will count in today\'s closing.');
    }
    if ((float)$sale['returned_amount'] > 0) {
        throw new SaleError('Some items on this bill were already returned. Return the remaining items instead.');
    }
    $me = current_user();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($sale['items'] as $it) {
            if ($it['product_id']) {
                change_stock((int)$it['product_id'], (float)$it['qty'], 'cancel', $saleId, 'Bill ' . $sale['bill_no'] . ' cancel');
            }
        }
        $left = sale_udhaar_left($sale);
        if ($left > 0) {
            qexec('INSERT INTO customer_ledger (customer_id, type, amount, sale_id, note, user_id, created_at) VALUES (?,?,?,?,?,?,?)',
                [$sale['customer_id'], 'cancel', -$left, $saleId, 'Bill ' . $sale['bill_no'] . ' cancel', $me['id'] ?? null, now()]);
        }
        qexec("UPDATE sales SET status = 'cancelled', cancelled_at = ? WHERE id = ?", [now(), $saleId]);
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw $ex;
    }
    log_activity('sale_cancel', 'Bill ' . $sale['bill_no'] . ' — ' . money($sale['total']) . ($sale['cashier'] ? ' (cashier: ' . $sale['cashier'] . ')' : ''));
}

/** Receipt (JS) ke liye bill ka data */
function sale_receipt_data(array $sale): array
{
    $c = $sale['customer_id'] ? q1('SELECT * FROM customers WHERE id = ?', [$sale['customer_id']]) : null;
    $bp = $c ? r2(-(float)qv("SELECT COALESCE(SUM(amount),0) FROM customer_ledger WHERE sale_id = ? AND type = 'payment'", [$sale['id']])) : 0.0;
    return [
        'credit_payment' => $bp,
        'bill_no' => $sale['bill_no'], 'created_at' => $sale['created_at'], 'status' => $sale['status'],
        'subtotal' => (float)$sale['subtotal'], 'discount' => (float)$sale['discount'], 'total' => (float)$sale['total'],
        'paid' => (float)$sale['paid'], 'change' => (float)$sale['change_amount'], 'returned_amount' => (float)$sale['returned_amount'],
        'cashier' => $sale['cashier'],
        'customer' => $c ? ['name' => $c['name'], 'phone' => $c['phone'], 'balance' => customer_balance((int)$c['id'])] : null,
        // cash line: customer ne jitne diye (wapsi samet), billing receipt jaisa
        'payments' => array_map(fn($p) => ['method' => $p['method'], 'amount' => $p['method'] === 'cash' ? r2((float)$p['amount'] + (float)$sale['change_amount'] + $bp) : (float)$p['amount'], 'ref' => $p['ref']], $sale['payments']),
        'reprint' => true,
        'items' => array_map(fn($it) => ['name' => $it['name'], 'unit' => $it['unit'], 'qty' => (float)$it['qty'], 'price' => (float)$it['price']], $sale['items']),
    ];
}
