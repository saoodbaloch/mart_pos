<?php
declare(strict_types=1);

/**
 * Do role:
 *  admin  = owner, sab kuch
 *  staff  = cashier: billing, maal aana, kharcha, udhaar, din band
 */
const STAFF_PERMS = [
    'billing',          // billing screen
    'stock_in',         // naya maal aaya (sirf quantity)
    'sales_own',        // apne aaj ke bills
    'expense',          // kharcha likhna
    'hisaab_close',     // din band (blind count)
    'customers',        // udhaar khata dekhna / customer banana
    'customer_payment', // udhaar wapas lena
];

const ROLE_LABELS = ['admin' => 'Admin (Owner)', 'staff' => 'Staff (Cashier)'];

/** Login wala user, har request par database se taaza (band account foran bahar) */
function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $id = $_SESSION['user']['id'] ?? null;
    if (!$id) {
        return $user = null;
    }
    $row = q1('SELECT id, username, full_name, role, active, pin_hash FROM users WHERE id = ?', [(int)$id]);
    if (!$row || !(int)$row['active']) {
        $_SESSION = [];
        return $user = null;
    }
    $user = [
        'id' => (int)$row['id'],
        'username' => $row['username'],
        'name' => $row['full_name'] ?: $row['username'],
        'role' => $row['role'] === 'staff' ? 'staff' : 'admin',
        'has_pin' => !empty($row['pin_hash']),
    ];
    return $user;
}

function is_admin(): bool
{
    return (current_user()['role'] ?? '') === 'admin';
}

function can(string $perm): bool
{
    $u = current_user();
    if (!$u) {
        return false;
    }
    return $u['role'] === 'admin' || in_array($perm, STAFF_PERMS, true);
}

function require_login(): void
{
    if (current_user()) {
        return;
    }
    if (is_api()) {
        json_out(['ok' => false, 'error' => 'auth', 'message' => 'Please log in again.'], 401);
    }
    $back = basename($_SERVER['SCRIPT_NAME'] ?? '');
    redirect('login.php' . ($back && $back !== 'login.php' ? '?next=' . urlencode($back) : ''));
}

function deny(): never
{
    if (is_api()) {
        json_out(['ok' => false, 'error' => 'forbidden', 'message' => 'Only the owner (admin) can do this.'], 403);
    }
    flash('warn', 'This page is for the owner (admin) only.');
    redirect('billing.php');
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        deny();
    }
}

function require_perm(string $perm): void
{
    require_login();
    if (!can($perm)) {
        deny();
    }
}

/**
 * Login check. 5 ghalat koshishon ke baad 5 minute ke liye band.
 * Returns: true = kamyab, string = error message
 */
function attempt_login(string $username, string $password)
{
    $username = trim($username);
    // Har username ka apna lock — staff ki ghalti se owner band na ho
    $key = substr(md5(mb_strtolower($username)), 0, 24);
    $lockUntil = (int)setting('ll:' . $key, '0');
    if ($lockUntil > time()) {
        $mins = (int)ceil(($lockUntil - time()) / 60);
        return "Too many wrong attempts for this username. Try again in $mins minute(s).";
    }

    $user = q1('SELECT * FROM users WHERE username = ?', [$username]);
    if ($user && password_verify($password, $user['password_hash'])) {
        if (isset($user['active']) && !(int)$user['active']) {
            return 'This account is disabled. Please contact the owner.';
        }
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$user['id']];
        unset($_SESSION['csrf']);
        set_setting('lf:' . $key, '0');
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            qexec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $user['id']]);
        }
        qexec('UPDATE users SET last_login = ? WHERE id = ?', [now(), $user['id']]);
        log_activity('login', 'Login', ['id' => (int)$user['id'], 'name' => $user['full_name'] ?: $user['username']]);
        return true;
    }

    $fails = (int)setting('lf:' . $key, '0') + 1;
    set_setting('lf:' . $key, (string)$fails);
    if ($fails >= 5) {
        set_setting('ll:' . $key, (string)(time() + 300));
        set_setting('lf:' . $key, '0');
        log_activity('login_lock', 'Wrong password 5 times: "' . mb_substr($username, 0, 40) . '" (5 minute lock)', ['id' => null, 'name' => '-']);
    }
    usleep(400000);
    return 'Wrong username or password.';
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'] ?? '', (bool)$p['secure'], (bool)$p['httponly']);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/* ---------- Admin PIN (staff ke kaam ki ijazat) ---------- */

const PIN_ITERATIONS = 120000;

/** PIN save: server ke liye password_hash, offline billing ke liye PBKDF2 */
function set_user_pin(int $userId, ?string $pin): void
{
    if ($pin === null || $pin === '') {
        qexec('UPDATE users SET pin_hash = NULL, pin_salt = NULL, pin_kdf = NULL WHERE id = ?', [$userId]);
        return;
    }
    $salt = bin2hex(random_bytes(16));
    $kdf = hash_pbkdf2('sha256', $pin, $salt, PIN_ITERATIONS, 64);
    qexec('UPDATE users SET pin_hash = ?, pin_salt = ?, pin_kdf = ? WHERE id = ?',
        [password_hash($pin, PASSWORD_DEFAULT), $salt, $kdf, $userId]);
}

function valid_pin_format(string $pin): bool
{
    return (bool)preg_match('/^\d{4,8}$/', $pin);
}

/** PIN ki ghalat koshishein: 5 dafa ke baad 10 minute band (har user ke liye) */
function pin_locked_minutes(): int
{
    $u = current_user();
    $until = (int)setting('pl:' . ($u['id'] ?? 0), '0');
    return $until > time() ? (int)ceil(($until - time()) / 60) : 0;
}

/** Kisi bhi active admin ka PIN sahi ho to us admin ki row */
function verify_admin_pin(string $pin): ?array
{
    $u = current_user();
    $uid = $u['id'] ?? 0;
    if (pin_locked_minutes() > 0) {
        return null;
    }
    $found = null;
    if (valid_pin_format($pin)) {
        foreach (q("SELECT id, username, full_name, pin_hash FROM users WHERE role = 'admin' AND active = 1 AND pin_hash IS NOT NULL") as $a) {
            if (password_verify($pin, $a['pin_hash'])) {
                $found = ['id' => (int)$a['id'], 'name' => $a['full_name'] ?: $a['username']];
                break;
            }
        }
    }
    if ($found) {
        set_setting('pf:' . $uid, '0');
        return $found;
    }
    $fails = (int)setting('pf:' . $uid, '0') + 1;
    set_setting('pf:' . $uid, (string)$fails);
    if ($fails >= 5) {
        set_setting('pl:' . $uid, (string)(time() + 600));
        set_setting('pf:' . $uid, '0');
        log_activity('limit_breach', 'Admin PIN wrong 5 times — locked for 10 minutes');
    }
    usleep(300000);
    return null;
}

/** Billing page (offline) ke liye admins ke PIN ka PBKDF2 data */
function admin_pin_records(): array
{
    return array_map(fn($a) => ['id' => (int)$a['id'], 'name' => $a['full_name'] ?: $a['username'], 'salt' => $a['pin_salt'], 'kdf' => $a['pin_kdf']],
        q("SELECT id, username, full_name, pin_salt, pin_kdf FROM users WHERE role = 'admin' AND active = 1 AND pin_kdf IS NOT NULL"));
}

/* ---------- Activity log ---------- */

function log_activity(string $action, string $details = '', ?array $who = null): void
{
    try {
        $who = $who ?? current_user();
        qexec('INSERT INTO activity_log (user_id, user_name, action, details, created_at) VALUES (?,?,?,?,?)',
            [$who['id'] ?? null, mb_substr((string)($who['name'] ?? '-'), 0, 80), $action, mb_substr($details, 0, 255), now()]);
    } catch (Throwable $e) {
        // log na likha jaye to kaam na ruke
    }
}
