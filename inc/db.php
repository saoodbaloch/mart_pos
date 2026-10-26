<?php
declare(strict_types=1);

/** Database connection (MySQL, ya testing ke liye SQLite) */
function db(?array $override = null): PDO
{
    static $pdo = null;
    if ($override !== null) {
        $pdo = make_pdo($override);
        return $pdo;
    }
    if ($pdo === null) {
        global $CONFIG;
        if (!$CONFIG) {
            throw new RuntimeException('config.php not found. Please run install.php.');
        }
        $pdo = make_pdo($CONFIG['db']);
    }
    return $pdo;
}

function make_pdo(array $c): PDO
{
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    if (($c['driver'] ?? 'mysql') === 'sqlite') {
        $pdo = new PDO('sqlite:' . $c['path'], null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        return $pdo;
    }
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $c['host'] ?? 'localhost', (int)($c['port'] ?? 3306), $c['name']);
    $pdo = new PDO($dsn, $c['user'], $c['pass'], $opts);
    $pdo->exec("SET time_zone = '" . date('P') . "'");
    return $pdo;
}

function db_driver(): string
{
    return db()->getAttribute(PDO::ATTR_DRIVER_NAME);
}

/** Saari rows */
function q(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Ek row */
function q1(string $sql, array $params = []): ?array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** Ek value */
function qv(string $sql, array $params = [])
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? null : $v;
}

function qexec(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/** Tables banane ki SQL (MySQL ya SQLite) */
function schema_sql(string $driver): array
{
    $my = $driver === 'mysql';
    $id = $my ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $fk = $my ? 'INT UNSIGNED' : 'INTEGER';
    $money = $my ? 'DECIMAL(12,2)' : 'REAL';
    $qty = $my ? 'DECIMAL(12,3)' : 'REAL';
    $tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $text = $my ? 'TEXT' : 'TEXT';

    $sql = [];
    $sql[] = "CREATE TABLE IF NOT EXISTS users (
        id $id,
        username VARCHAR(60) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL
    )$tail";

    $sql[] = "CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(64) NOT NULL PRIMARY KEY,
        v $text
    )$tail";

    $sql[] = "CREATE TABLE IF NOT EXISTS products (
        id $id,
        barcode VARCHAR(64) NULL,
        name VARCHAR(190) NOT NULL,
        unit VARCHAR(10) NOT NULL DEFAULT 'pcs',
        purchase_price $money NOT NULL DEFAULT 0,
        sale_price $money NOT NULL DEFAULT 0,
        stock $qty NOT NULL DEFAULT 0,
        low_stock $qty NULL,
        quick_key INT NULL,
        deleted_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )$tail";
    $sql[] = 'CREATE UNIQUE INDEX ' . ($my ? '' : 'IF NOT EXISTS ') . 'ux_products_barcode ON products (barcode)';
    $sql[] = 'CREATE INDEX ' . ($my ? '' : 'IF NOT EXISTS ') . 'ix_products_updated ON products (updated_at)';
    $sql[] = 'CREATE INDEX ' . ($my ? '' : 'IF NOT EXISTS ') . 'ix_products_name ON products (name)';

    $sql[] = "CREATE TABLE IF NOT EXISTS sales (
        id $id,
        uuid VARCHAR(40) NOT NULL,
        bill_no VARCHAR(40) NOT NULL,
        created_at DATETIME NOT NULL,
        synced_at DATETIME NOT NULL,
        items_count INT NOT NULL DEFAULT 0,
        subtotal $money NOT NULL DEFAULT 0,
        discount $money NOT NULL DEFAULT 0,
        total $money NOT NULL DEFAULT 0,
        paid $money NOT NULL DEFAULT 0,
        change_amount $money NOT NULL DEFAULT 0,
        returned_amount $money NOT NULL DEFAULT 0,
        status VARCHAR(12) NOT NULL DEFAULT 'completed',
        cancelled_at DATETIME NULL
    )$tail";
    $sql[] = 'CREATE UNIQUE INDEX ' . ($my ? '' : 'IF NOT EXISTS ') . 'ux_sales_uuid ON sales (uuid)';
    $sql[] = 'CREATE INDEX ' . ($my ? '' : 'IF NOT EXISTS ') . 'ix_sales_created ON sales (created_at)';
    $sql[] = 'CREATE INDEX ' . ($my ? '' : 'IF NOT EXISTS ') . 'ix_sales_billno ON sales (bill_no)';

    $sql[] = "CREATE TABLE IF NOT EXISTS sale_items (
        id $id,
        sale_id $fk NOT NULL,
        product_id $fk NULL,
        barcode VARCHAR(64) NULL,
        name VARCHAR(190) NOT NULL,
        unit VARCHAR(10) NOT NULL DEFAULT 'pcs',
        qty $qty NOT NULL,
        price $money NOT NULL,
        cost $money NOT NULL DEFAULT 0,
        line_total $money NOT NULL,
        returned_qty $qty NOT NULL DEFAULT 0
    )$tail";
    $sql[] = 'CREATE INDEX ' . ($my ? '' : 'IF NOT EXISTS ') . 'ix_items_sale ON sale_items (sale_id)';
    $sql[] = 'CREATE INDEX ' . ($my ? '' : 'IF NOT EXISTS ') . 'ix_items_product ON sale_items (product_id)';

    $sql[] = "CREATE TABLE IF NOT EXISTS returns (
        id $id,
        sale_id $fk NOT NULL,
        sale_item_id $fk NOT NULL,
        qty $qty NOT NULL,
        amount $money NOT NULL,
        cost_amount $money NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL
    )$tail";
    $sql[] = 'CREATE INDEX ' . ($my ? '' : 'IF NOT EXISTS ') . 'ix_returns_created ON returns (created_at)';

    $sql[] = "CREATE TABLE IF NOT EXISTS stock_moves (
        id $id,
        product_id $fk NOT NULL,
        qty_change $qty NOT NULL,
        type VARCHAR(16) NOT NULL,
        ref_id $fk NULL,
        note VARCHAR(190) NULL,
        created_at DATETIME NOT NULL
    )$tail";
    $sql[] = 'CREATE INDEX ' . ($my ? '' : 'IF NOT EXISTS ') . 'ix_moves_product ON stock_moves (product_id)';

    return $sql;
}

const SCHEMA_VERSION = 3;

/**
 * Database ke upgrades. Naye install aur purane (1.0.x) dono isi se
 * latest version par aate hain. Har version ek dafa chalta hai.
 */
function schema_migrations(string $driver): array
{
    $my = $driver === 'mysql';
    $id = $my ? 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $fk = $my ? 'INT UNSIGNED' : 'INTEGER';
    $money = $my ? 'DECIMAL(12,2)' : 'REAL';
    $tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    $ix = fn(string $name, string $table, string $cols, bool $unique = false) =>
        'CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . ($my ? '' : 'IF NOT EXISTS ') . "$name ON $table ($cols)";

    return [
        2 => [
            // Staff aur roles
            "ALTER TABLE users ADD COLUMN full_name VARCHAR(80) NULL",
            "ALTER TABLE users ADD COLUMN role VARCHAR(10) NOT NULL DEFAULT 'admin'",
            "ALTER TABLE users ADD COLUMN active INT NOT NULL DEFAULT 1",
            "ALTER TABLE users ADD COLUMN pin_hash VARCHAR(255) NULL",
            "ALTER TABLE users ADD COLUMN pin_salt VARCHAR(64) NULL",
            "ALTER TABLE users ADD COLUMN pin_kdf VARCHAR(128) NULL",
            "ALTER TABLE users ADD COLUMN last_login DATETIME NULL",
            $ix('ux_users_username', 'users', 'username', true),
            // Staff ne billing mein banaya product: owner check kare
            "ALTER TABLE products ADD COLUMN needs_review INT NOT NULL DEFAULT 0",
            // Bill kis ne banaya, udhaar kis ka
            "ALTER TABLE sales ADD COLUMN user_id $fk NULL",
            "ALTER TABLE sales ADD COLUMN cashier VARCHAR(80) NULL",
            "ALTER TABLE sales ADD COLUMN customer_id $fk NULL",
            $ix('ix_sales_user', 'sales', 'user_id'),
            $ix('ix_sales_customer', 'sales', 'customer_id'),
            "ALTER TABLE returns ADD COLUMN user_id $fk NULL",
            "ALTER TABLE returns ADD COLUMN refund_method VARCHAR(12) NULL",
            // Payment: cash, jazzcash, easypaisa, bank, card, udhaar
            "CREATE TABLE IF NOT EXISTS sale_payments (
                id $id,
                sale_id $fk NOT NULL,
                method VARCHAR(12) NOT NULL,
                amount $money NOT NULL,
                ref VARCHAR(80) NULL,
                created_at DATETIME NOT NULL
            )$tail",
            $ix('ix_pay_sale', 'sale_payments', 'sale_id'),
            $ix('ix_pay_created', 'sale_payments', 'created_at'),
            // Purane bills: sab cash the
            "INSERT INTO sale_payments (sale_id, method, amount, created_at)
                SELECT id, 'cash', total, created_at FROM sales
                WHERE id NOT IN (SELECT sale_id FROM sale_payments)",
            // Udhaar khata
            "CREATE TABLE IF NOT EXISTS customers (
                id $id,
                uuid VARCHAR(40) NOT NULL,
                name VARCHAR(120) NOT NULL,
                phone VARCHAR(30) NULL,
                address VARCHAR(190) NULL,
                credit_limit $money NULL,
                active INT NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )$tail",
            $ix('ux_customers_uuid', 'customers', 'uuid', true),
            $ix('ix_customers_name', 'customers', 'name'),
            "CREATE TABLE IF NOT EXISTS customer_ledger (
                id $id,
                customer_id $fk NOT NULL,
                type VARCHAR(12) NOT NULL,
                amount $money NOT NULL,
                method VARCHAR(12) NULL,
                ref VARCHAR(80) NULL,
                sale_id $fk NULL,
                note VARCHAR(190) NULL,
                user_id $fk NULL,
                created_at DATETIME NOT NULL
            )$tail",
            $ix('ix_ledger_customer', 'customer_ledger', 'customer_id'),
            $ix('ix_ledger_created', 'customer_ledger', 'created_at'),
            // Kharcha aur galle se nikale paise
            "CREATE TABLE IF NOT EXISTS cash_moves (
                id $id,
                type VARCHAR(12) NOT NULL,
                category VARCHAR(40) NULL,
                amount $money NOT NULL,
                note VARCHAR(190) NULL,
                user_id $fk NULL,
                created_at DATETIME NOT NULL,
                deleted_at DATETIME NULL
            )$tail",
            $ix('ix_cash_created', 'cash_moves', 'created_at'),
            // Roz ka hisaab
            "CREATE TABLE IF NOT EXISTS day_closes (
                id $id,
                day DATE NOT NULL,
                opening_cash $money NOT NULL DEFAULT 0,
                status VARCHAR(10) NOT NULL DEFAULT 'open',
                expected $money NULL,
                counted $money NULL,
                diff $money NULL,
                note VARCHAR(190) NULL,
                opened_by $fk NULL,
                closed_by $fk NULL,
                closed_at DATETIME NULL,
                created_at DATETIME NOT NULL
            )$tail",
            $ix('ux_days_day', 'day_closes', 'day', true),
            // Kis ne kya kiya
            "CREATE TABLE IF NOT EXISTS activity_log (
                id $id,
                user_id $fk NULL,
                user_name VARCHAR(80) NULL,
                action VARCHAR(40) NOT NULL,
                details VARCHAR(255) NULL,
                created_at DATETIME NOT NULL
            )$tail",
            $ix('ix_log_created', 'activity_log', 'created_at'),
        ],
        3 => [
            // English UI: purana default receipt message (agar owner ne nahi badla)
            "UPDATE settings SET v = 'Thank you! Please visit again.' WHERE k = 'receipt_footer' AND v = 'Shukriya! Phir tashreef laiye.'",
        ],
    ];
}

/** Purane database ko latest version par lao (har request par sirf version check) */
function run_migrations(?PDO $pdo = null): void
{
    $pdo = $pdo ?? db();
    $cur = $pdo->query("SELECT v FROM settings WHERE k = 'schema_version'")->fetchColumn();
    $cur = $cur === false ? 1 : (int)$cur;
    if ($cur >= SCHEMA_VERSION) {
        return;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    foreach (schema_migrations($driver) as $ver => $stmts) {
        if ($ver <= $cur) {
            continue;
        }
        foreach ($stmts as $sql) {
            try {
                $pdo->exec($sql);
            } catch (PDOException $ex) {
                $m = strtolower($ex->getMessage());
                // dobara chalne par: column/index pehle se ho to theek
                if (!str_contains($m, 'duplicate column') && !str_contains($m, 'duplicate key name') && !str_contains($m, 'already exists')) {
                    throw $ex;
                }
            }
        }
        $up = $pdo->prepare("UPDATE settings SET v = ? WHERE k = 'schema_version'");
        $up->execute([(string)$ver]);
        if ($up->rowCount() === 0 && !$pdo->query("SELECT 1 FROM settings WHERE k = 'schema_version'")->fetchColumn()) {
            $pdo->prepare("INSERT INTO settings (k, v) VALUES ('schema_version', ?)")->execute([(string)$ver]);
        }
    }
    global $SETTINGS_CACHE;
    $SETTINGS_CACHE = null;
}

/** Poori schema (backup file ke liye): v1 tables + saare upgrades, MySQL mein */
function full_schema_mysql(): array
{
    $out = schema_sql('mysql');
    foreach (schema_migrations('mysql') as $stmts) {
        foreach ($stmts as $s) {
            if (stripos(ltrim($s), 'INSERT') !== 0 && stripos(ltrim($s), 'UPDATE') !== 0) {
                $out[] = $s;
            }
        }
    }
    return $out;
}

function default_settings(): array
{
    return [
        'shop_name'      => 'My Mart',
        'shop_address'   => '',
        'shop_phone'     => '',
        'receipt_footer' => 'Thank you! Please visit again.',
        'currency'       => 'Rs',
        'low_stock'      => '5',
        'label_width'    => '38',
        'label_height'   => '25',
        'staff_discount_pct' => '',
        'pin_for_returns'    => '1',
        'udhaar_limit'       => '5000',
        'pay_jazzcash'       => '',
        'pay_easypaisa'      => '',
        'pay_bank'           => '',
        'day_start_hour'     => '6',
    ];
}
