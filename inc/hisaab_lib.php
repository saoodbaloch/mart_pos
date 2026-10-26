<?php
declare(strict_types=1);

const EXPENSE_CATEGORIES = ['Tea / Food', 'Electricity / Gas', 'Rent', 'Salaries', 'Delivery / Transport', 'Cleaning', 'Supplier payment', 'Other'];

/** Ek din ka poora hisaab */
function hisaab_compute(string $day): array
{
    [$a, $b] = bday_range($day);

    $pay = array_fill_keys(array_keys(PAY_METHODS), 0.0);
    foreach (q("SELECT p.method, SUM(p.amount) AS t FROM sale_payments p JOIN sales s ON s.id = p.sale_id
                WHERE s.status = 'completed' AND s.created_at BETWEEN ? AND ? GROUP BY p.method", [$a, $b]) as $r) {
        $pay[$r['method']] = r2($r['t']);
    }
    $sales = q1("SELECT COUNT(*) AS bills, COALESCE(SUM(total),0) AS gross, COALESCE(SUM(discount),0) AS disc,
                        COALESCE(SUM(items_count),0) AS line_count
                 FROM sales WHERE status = 'completed' AND created_at BETWEEN ? AND ?", [$a, $b]);
    $cancelled = (int)qv("SELECT COUNT(*) FROM sales WHERE status = 'cancelled' AND created_at BETWEEN ? AND ?", [$a, $b]);
    $itemsSold = (float)qv("SELECT COALESCE(SUM(CASE WHEN i.unit IN ('kg','ltr','m') THEN 1 ELSE i.qty END),0)
                            FROM sale_items i JOIN sales s ON s.id = i.sale_id WHERE s.status = 'completed' AND s.created_at BETWEEN ? AND ?", [$a, $b]);

    $udhaarIn = array_fill_keys(array_keys(PAY_METHODS), 0.0);
    foreach (q("SELECT method, SUM(-amount) AS t FROM customer_ledger WHERE type = 'payment' AND created_at BETWEEN ? AND ? GROUP BY method", [$a, $b]) as $r) {
        if (isset($udhaarIn[$r['method']])) {
            $udhaarIn[$r['method']] = r2($r['t']);
        }
    }

    $moves = ['expense' => 0.0, 'withdraw' => 0.0, 'refund' => 0.0];
    foreach (q('SELECT type, SUM(amount) AS t FROM cash_moves WHERE deleted_at IS NULL AND created_at BETWEEN ? AND ? GROUP BY type', [$a, $b]) as $r) {
        $moves[$r['type']] = r2($r['t']);
    }
    $returns = q1('SELECT COALESCE(SUM(amount),0) AS amount, COALESCE(SUM(cost_amount),0) AS cost FROM returns WHERE created_at BETWEEN ? AND ?', [$a, $b]);
    $cost = (float)qv("SELECT COALESCE(SUM(i.qty * i.cost),0) FROM sale_items i JOIN sales s ON s.id = i.sale_id
                       WHERE s.status = 'completed' AND s.created_at BETWEEN ? AND ?", [$a, $b]);

    $close = q1('SELECT * FROM day_closes WHERE day = ?', [$day]);
    $opening = $close ? (float)$close['opening_cash'] : 0.0;

    $expected = r2($opening + $pay['cash'] + $udhaarIn['cash'] - $moves['refund'] - $moves['expense'] - $moves['withdraw']);
    $net = r2((float)$sales['gross'] - (float)$returns['amount']);
    $profit = r2($net - ($cost - (float)$returns['cost']));

    $cashiers = q("SELECT s.user_id, COALESCE(MAX(s.cashier), '-') AS cashier, COUNT(*) AS bills, SUM(s.total) AS total,
                          COALESCE(SUM((SELECT SUM(p.amount) FROM sale_payments p WHERE p.sale_id = s.id AND p.method = 'cash')),0) AS cash,
                          COALESCE(SUM((SELECT SUM(p.amount) FROM sale_payments p WHERE p.sale_id = s.id AND p.method = 'udhaar')),0) AS udhaar
                   FROM sales s WHERE s.status = 'completed' AND s.created_at BETWEEN ? AND ?
                   GROUP BY s.user_id ORDER BY total DESC", [$a, $b]);

    return [
        'day' => $day,
        'opening' => $opening,
        'pay' => $pay,
        'digital' => r2($pay['jazzcash'] + $pay['easypaisa'] + $pay['bank'] + $pay['card']),
        'bills' => (int)$sales['bills'],
        'gross' => r2($sales['gross']),
        'discount' => r2($sales['disc']),
        'cancelled' => $cancelled,
        'items' => $itemsSold,
        'udhaar_in' => $udhaarIn,
        'udhaar_in_total' => r2(array_sum($udhaarIn)),
        'expense' => $moves['expense'],
        'withdraw' => $moves['withdraw'],
        'refund' => $moves['refund'],
        'returns' => r2($returns['amount']),
        'net' => $net,
        'profit' => $profit,
        'expected' => $expected,
        'close' => $close,
        'cashiers' => $cashiers,
    ];
}

/** Pichle band din ka gina hua cash (aaj ki opening ke liye mashwara) */
function suggested_opening(string $day): ?float
{
    $prev = q1("SELECT counted FROM day_closes WHERE day < ? AND status = 'closed' AND counted IS NOT NULL ORDER BY day DESC LIMIT 1", [$day]);
    return $prev ? (float)$prev['counted'] : null;
}

function set_opening(string $day, float $amount): void
{
    $u = current_user();
    $row = q1('SELECT * FROM day_closes WHERE day = ?', [$day]);
    if ($row) {
        qexec('UPDATE day_closes SET opening_cash = ? WHERE id = ?', [r2($amount), $row['id']]);
    } else {
        qexec("INSERT INTO day_closes (day, opening_cash, status, opened_by, created_at) VALUES (?,?, 'open', ?, ?)",
            [$day, r2($amount), $u['id'] ?? null, now()]);
    }
    log_activity('day_open', $day . ': opening cash ' . money($amount));
}

function close_day(string $day, float $counted, string $note = ''): array
{
    $h = hisaab_compute($day);
    if (!$h['close']) {
        set_opening($day, 0);
        $h = hisaab_compute($day);
    }
    $diff = r2($counted - $h['expected']);
    $u = current_user();
    qexec("UPDATE day_closes SET status = 'closed', expected = ?, counted = ?, diff = ?, note = ?, closed_by = ?, closed_at = ? WHERE day = ?",
        [$h['expected'], r2($counted), $diff, mb_substr($note, 0, 190) ?: null, $u['id'] ?? null, now(), $day]);
    log_activity('day_close', $day . ': counted ' . money($counted) . ', expected ' . money($h['expected']) . ', difference ' . ($diff >= 0 ? '+' : '') . money($diff));
    return ['expected' => $h['expected'], 'diff' => $diff];
}
