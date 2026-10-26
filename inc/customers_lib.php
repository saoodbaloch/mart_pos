<?php
declare(strict_types=1);

/** Customer ka baqi udhaar (+ = customer ne dene hain) */
function customer_balance(int $id): float
{
    return r2(qv('SELECT COALESCE(SUM(amount),0) FROM customer_ledger WHERE customer_id = ?', [$id]));
}

/** Limit: customer ki apni, warna default. null = koi limit nahi */
function customer_limit(array $c): ?float
{
    if ($c['credit_limit'] !== null && $c['credit_limit'] !== '') {
        return (float)$c['credit_limit'];
    }
    $d = setting('udhaar_limit', '');
    return $d === '' || $d === null ? null : (float)$d;
}

function clean_phone(?string $p): ?string
{
    $p = trim((string)$p);
    $p = preg_replace('/[^\d+\- ]/', '', $p);
    return $p === '' ? null : mb_substr($p, 0, 30);
}

/**
 * uuid se customer dhoondo; na mile to banao (offline billing mein bana ho to).
 */
function find_or_create_customer(array $c): array
{
    $uuid = (string)($c['uuid'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9-]{8,40}$/', $uuid)) {
        throw new SaleError('Invalid customer ID.');
    }
    $row = q1('SELECT * FROM customers WHERE uuid = ?', [$uuid]);
    if ($row) {
        return $row;
    }
    $name = trim(preg_replace('/\s+/', ' ', (string)($c['name'] ?? '')));
    if ($name === '') {
        throw new SaleError('Customer name is required for credit.');
    }
    $t = now();
    qexec('INSERT INTO customers (uuid, name, phone, address, credit_limit, active, created_at, updated_at) VALUES (?,?,?,?,?,1,?,?)',
        [$uuid, mb_substr($name, 0, 120), clean_phone($c['phone'] ?? null), null, null, $t, $t]);
    $row = q1('SELECT * FROM customers WHERE uuid = ?', [$uuid]);
    log_activity('customer', 'New customer: ' . $row['name'] . ($row['phone'] ? ' (' . $row['phone'] . ')' : ''));
    return $row;
}

/** Billing (offline) ke liye customers + baqi */
function customers_for_billing(): array
{
    $rows = q('SELECT c.id, c.uuid, c.name, c.phone, c.credit_limit, c.active, c.updated_at,
                      COALESCE((SELECT SUM(amount) FROM customer_ledger l WHERE l.customer_id = c.id), 0) AS balance
               FROM customers c ORDER BY c.name');
    return array_map(fn($c) => [
        'id' => (int)$c['id'], 'uuid' => $c['uuid'], 'name' => $c['name'], 'phone' => $c['phone'],
        'limit' => customer_limit($c), 'balance' => r2($c['balance']), 'active' => (bool)(int)$c['active'],
    ], $rows);
}

/** Udhaar wapas mila */
function receive_customer_payment(int $customerId, float $amount, string $method, string $ref = '', string $note = ''): int
{
    if ($amount <= 0) {
        throw new SaleError('Enter the amount.');
    }
    if (!isset(PAY_METHODS[$method]) || $method === 'udhaar') {
        throw new SaleError('Invalid payment method.');
    }
    $c = q1('SELECT * FROM customers WHERE id = ?', [$customerId]);
    if (!$c) {
        throw new SaleError('Customer not found.');
    }
    $u = current_user();
    $bal = customer_balance($customerId);
    if ($amount > $bal + 0.01 && ($u['role'] ?? '') !== 'admin') {
        throw new SaleError('Amount is more than the balance due (' . money($bal) . '). Only the owner can take an advance.');
    }
    qexec('INSERT INTO customer_ledger (customer_id, type, amount, method, ref, note, user_id, created_at) VALUES (?,?,?,?,?,?,?,?)',
        [$customerId, 'payment', -r2($amount), $method, mb_substr($ref, 0, 80) ?: null, mb_substr($note, 0, 190) ?: null, $u['id'] ?? null, now()]);
    $id = (int)db()->lastInsertId();
    qexec('UPDATE customers SET updated_at = ? WHERE id = ?', [now(), $customerId]);
    log_activity('udhaar_payment', $c['name'] . ': ' . money($amount) . ' (' . PAY_METHODS[$method] . ($ref ? ' · ' . $ref : '') . ')');
    return $id;
}
