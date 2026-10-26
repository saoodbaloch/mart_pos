<?php
declare(strict_types=1);

/** HTML escape */
function e($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

/** App ka web path, jaise "/pos/" (aakhir mein slash ke saath) */
function app_base_path(): string
{
    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir = preg_replace('#/api$#', '', $dir);
    $dir = rtrim($dir, '/');
    return $dir . '/';
}

function url(string $path = ''): string
{
    return app_base_path() . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/** Number ko 2 decimal tak round karo */
function r2($n): float
{
    return round((float)$n, 2);
}

function r3($n): float
{
    return round((float)$n, 3);
}

/** Paisay dikhane ke liye: 1250 -> "1,250", 12.5 -> "12.50" */
function money($n): string
{
    $n = (float)$n;
    $dec = (abs($n - round($n)) < 0.005) ? 0 : 2;
    return number_format($n, $dec);
}

/** Quantity: 2 -> "2", 1.25 -> "1.25" */
function qty($n): string
{
    $n = (float)$n;
    if (abs($n - round($n)) < 0.0005) {
        return (string)(int)round($n);
    }
    return rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
}

function setting(string $key, ?string $default = null): ?string
{
    global $SETTINGS_CACHE;
    if ($SETTINGS_CACHE === null) {
        $SETTINGS_CACHE = [];
        try {
            foreach (db()->query('SELECT k, v FROM settings') as $row) {
                $SETTINGS_CACHE[$row['k']] = $row['v'];
            }
        } catch (Throwable $e) {
            // install se pehle settings table nahi hoti
        }
    }
    return $SETTINGS_CACHE[$key] ?? $default;
}

function set_setting(string $key, string $value): void
{
    global $SETTINGS_CACHE;
    $SETTINGS_CACHE = null;
    $pdo = db();
    $st = $pdo->prepare('UPDATE settings SET v = ? WHERE k = ?');
    $st->execute([$value, $key]);
    if ($st->rowCount() === 0) {
        $exists = $pdo->prepare('SELECT 1 FROM settings WHERE k = ?');
        $exists->execute([$key]);
        if (!$exists->fetchColumn()) {
            $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?)')->execute([$key, $value]);
        }
    }
}

function currency(): string
{
    return setting('currency', 'Rs') ?? 'Rs';
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valid(): bool
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}

function require_csrf(): void
{
    if (!csrf_valid()) {
        http_response_code(419);
        if (is_api()) {
            json_out(['ok' => false, 'error' => 'csrf', 'message' => 'Session expired, please refresh the page.'], 419);
        }
        exit('Session expired. Please refresh the page and try again.');
    }
}

/* ---------- Flash messages ---------- */

function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ---------- JSON / API ---------- */

function is_api(): bool
{
    return str_contains($_SERVER['SCRIPT_NAME'] ?? '', '/api/');
}

function json_out(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '[]', true);
    return is_array($data) ? $data : [];
}

/* ---------- Barcode ---------- */

/** EAN-13 check digit (12 digits do) */
function ean13_check(string $twelve): int
{
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int)$twelve[$i] * ($i % 2 === 0 ? 1 : 3);
    }
    return (10 - ($sum % 10)) % 10;
}

/**
 * Dukaan ke apne (bina company barcode wale) products ke liye naya barcode.
 * "2" se shuru hone wale EAN-13 codes dukaan ke andar use ke liye reserved hain.
 */
function generate_internal_barcode(): string
{
    $pdo = db();
    $max = $pdo->query("SELECT MAX(barcode) FROM products WHERE barcode LIKE '2000%' AND LENGTH(barcode) = 13")->fetchColumn();
    $next = $max ? ((int)substr((string)$max, 4, 8)) + 1 : 1;
    for ($try = 0; $try < 1000; $try++, $next++) {
        $twelve = '2000' . str_pad((string)$next, 8, '0', STR_PAD_LEFT);
        $code = $twelve . ean13_check($twelve);
        $st = $pdo->prepare('SELECT 1 FROM products WHERE barcode = ?');
        $st->execute([$code]);
        if (!$st->fetchColumn()) {
            return $code;
        }
    }
    throw new RuntimeException('Could not generate a barcode');
}

function clean_barcode(?string $b): ?string
{
    $b = trim((string)$b);
    $b = preg_replace('/\s+/', '', $b);
    return $b === '' ? null : $b;
}

/* ---------- Stock movement log ---------- */

function stock_move(int $productId, float $change, string $type, ?int $refId = null, string $note = ''): void
{
    if (abs($change) < 0.0005) {
        return;
    }
    db()->prepare('INSERT INTO stock_moves (product_id, qty_change, type, ref_id, note, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$productId, r3($change), $type, $refId, $note, now()]);
}

/** Product ki stock badlo aur log likho */
function change_stock(int $productId, float $change, string $type, ?int $refId = null, string $note = ''): void
{
    db()->prepare('UPDATE products SET stock = stock + ?, updated_at = ? WHERE id = ?')
        ->execute([r3($change), now(), $productId]);
    stock_move($productId, $change, $type, $refId, $note);
}

/* ---------- Payments ---------- */

const PAY_METHODS = [
    'cash'      => 'Cash',
    'jazzcash'  => 'JazzCash',
    'easypaisa' => 'Easypaisa',
    'bank'      => 'Bank transfer',
    'card'      => 'Card',
    'udhaar'    => 'Credit',
];

/** Kaam ki ghalti jo user ko dikhani hai (bill, udhaar, return) */
class SaleError extends RuntimeException
{
}

/** Receipt ke liye dukaan ki details */
function shop_info(): array
{
    $acc = [];
    foreach (['jazzcash' => 'JazzCash', 'easypaisa' => 'Easypaisa', 'bank' => 'Bank'] as $k => $l) {
        $v = trim((string)setting('pay_' . $k, ''));
        if ($v !== '') {
            $acc[$k] = ['label' => $l, 'value' => $v];
        }
    }
    return [
        'name'     => setting('shop_name', 'My Mart'),
        'address'  => setting('shop_address', ''),
        'phone'    => setting('shop_phone', ''),
        'footer'   => setting('receipt_footer', ''),
        'ntn'      => setting('shop_ntn', ''),
        'currency' => currency(),
        'accounts' => $acc,
        'accounts_on_receipt' => setting('receipt_accounts', '0') === '1',
        'paper'    => (int)setting('receipt_width', '80') === 58 ? 58 : 80,
    ];
}

/* ---------- Kaarobari din (business day) ----------
 * Mart raat 2-3 baje tak khula ho to din raat 12 baje nahi badalta.
 * Settings -> "Din badalne ka waqt" (default subah 6 baje): us se pehle ka
 * har bill / kharcha pichle din mein gina jata hai.
 */

function day_start_hour(): int
{
    $h = setting('day_start_hour', '6');
    return max(0, min(12, (int)$h));
}

/** Kisi waqt ka kaarobari din (Y-m-d). Khaali = abhi. */
function business_day(?string $ts = null): string
{
    $t = $ts === null ? time() : strtotime($ts);
    return date('Y-m-d', $t - day_start_hour() * 3600);
}

/** Kaarobari din(on) ka [shuru, aakhir] waqt, SQL BETWEEN ke liye */
function bday_range(string $from, ?string $to = null): array
{
    $h = day_start_hour();
    $to = $to ?? $from;
    $start = date('Y-m-d H:i:s', strtotime($from . ' 00:00:00') + $h * 3600);
    $end = date('Y-m-d H:i:s', strtotime($to . ' 00:00:00') + 86400 + $h * 3600 - 1);
    return [$start, $end];
}

/** SQL expression: kisi column ka kaarobari din (GROUP BY ke liye) */
function bday_sql(string $col): string
{
    $h = day_start_hour();
    if ($h === 0) {
        return "DATE($col)";
    }
    return db_driver() === 'sqlite' ? "date($col, '-$h hours')" : "DATE(DATE_SUB($col, INTERVAL $h HOUR))";
}

/** "Aaj" ka label: din badalne ke waqt ke hisaab se */
function bday_label(string $day): string
{
    $today = business_day();
    if ($day === $today) {
        return 'Today';
    }
    if ($day === date('Y-m-d', strtotime($today . ' -1 day'))) {
        return 'Yesterday';
    }
    return date('D, d M Y', strtotime($day));
}


/** Excel mein khulne wali CSV file bhejo */
function csv_download(string $filename, array $header, iterable $rows): never
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header, ',', '"', '\\');
    foreach ($rows as $r) {
        // Excel formula injection se bachao
        $r = array_map(fn($v) => is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false && !is_numeric($v) ? "'" . $v : $v, $r);
        fputcsv($out, $r, ',', '"', '\\');
    }
    fclose($out);
    exit;
}
