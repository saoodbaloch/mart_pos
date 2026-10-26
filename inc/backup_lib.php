<?php
declare(strict_types=1);

const BACKUP_TABLES = ['settings', 'users', 'products', 'customers', 'sales', 'sale_items', 'sale_payments', 'returns',
    'customer_ledger', 'cash_moves', 'day_closes', 'stock_moves', 'activity_log'];

/** SQL string (MySQL style escape, har database se ek jaisi) */
function sql_str(string $s): string
{
    return "'" . strtr($s, ["\\" => "\\\\", "'" => "\\'", "\n" => "\\n", "\r" => "\\r", "\0" => "\\0", "\x1a" => "\\Z"]) . "'";
}

function sql_lit($v): string
{
    if ($v === null) {
        return 'NULL';
    }
    if (is_int($v) || is_float($v)) {
        return (string)$v;
    }
    return sql_str((string)$v);
}

/** Poora backup $out (file handle) mein likho */
function backup_dump($out): void
{
    $pdo = db();
    fwrite($out, "-- MartPOS backup\n-- Shop: " . str_replace("\n", ' ', (string)setting('shop_name')) . "\n-- Time: " . now() . "\n");
    fwrite($out, "-- To restore: MartPOS -> Settings -> Restore backup.\n\n");
    fwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
    foreach (full_schema_mysql() as $sql) {
        fwrite($out, $sql . ";\n");
    }
    fwrite($out, "\n");

    foreach (BACKUP_TABLES as $t) {
        $st = $pdo->query("SELECT * FROM $t");
        $batch = [];
        $cols = null;
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            if ($cols === null) {
                $cols = '`' . implode('`, `', array_keys($row)) . '`';
            }
            $batch[] = '(' . implode(', ', array_map('sql_lit', array_values($row))) . ')';
            if (count($batch) >= 200) {
                fwrite($out, "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
                $batch = [];
            }
        }
        if ($batch) {
            fwrite($out, "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
        }
        fwrite($out, "\n");
    }
    fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
}

/** Restore se pehle mojooda data ki copy storage/backups mein */
function backup_to_file(string $prefix): ?string
{
    $dir = ROOT . '/storage/backups';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
        return null;
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    $path = $dir . '/' . $prefix . '-' . date('Ymd-His') . '.sql';
    $fh = @fopen($path, 'w');
    if (!$fh) {
        return null;
    }
    backup_dump($fh);
    fclose($fh);
    return $path;
}

class RestoreError extends RuntimeException
{
}

/** "(...), (...);" wali values parh kar PHP arrays */
function parse_tuples(string $s, int &$p): array
{
    $len = strlen($s);
    $ws = function () use ($s, $len, &$p) {
        while ($p < $len && ctype_space($s[$p])) {
            $p++;
        }
    };
    $map = ['n' => "\n", 'r' => "\r", '0' => "\0", 'Z' => "\x1a", 't' => "\t"];
    $rows = [];
    while (true) {
        $ws();
        if (($s[$p] ?? '') !== '(') {
            throw new RestoreError('Backup file is damaged (values).');
        }
        $p++;
        $row = [];
        while (true) {
            $ws();
            $c = $s[$p] ?? '';
            if ($c === "'") {
                $p++;
                $buf = '';
                while (true) {
                    if ($p >= $len) {
                        throw new RestoreError('Backup file is incomplete.');
                    }
                    $ch = $s[$p];
                    if ($ch === '\\') {
                        $n = $s[$p + 1] ?? '';
                        $buf .= $map[$n] ?? $n;
                        $p += 2;
                        continue;
                    }
                    if ($ch === "'") {
                        if (($s[$p + 1] ?? '') === "'") {
                            $buf .= "'";
                            $p += 2;
                            continue;
                        }
                        $p++;
                        break;
                    }
                    // tez raftar: agle khaas character tak ek saath
                    $chunk = strcspn($s, "\\'", $p);
                    $buf .= substr($s, $p, $chunk);
                    $p += $chunk;
                }
                $row[] = $buf;
            } elseif (preg_match('/\G(NULL|-?\d+(?:\.\d+)?(?:[eE][-+]?\d+)?)/i', $s, $m, 0, $p)) {
                $row[] = strtoupper($m[1]) === 'NULL' ? null : $m[1];
                $p += strlen($m[1]);
            } else {
                throw new RestoreError('Backup file is damaged (value).');
            }
            $ws();
            $c = $s[$p] ?? '';
            if ($c === ',') {
                $p++;
                continue;
            }
            if ($c === ')') {
                $p++;
                break;
            }
            throw new RestoreError('Backup file is damaged (row).');
        }
        $rows[] = $row;
        $ws();
        $c = $s[$p] ?? '';
        if ($c === ',') {
            $p++;
            continue;
        }
        if ($c === ';') {
            $p++;
            break;
        }
        throw new RestoreError('Backup file is damaged (end).');
    }
    return $rows;
}

/**
 * Backup file se saara data wapas. Pehle poori file parhi aur check hoti hai,
 * phir ek hi transaction mein purana data hat kar backup wala aata hai.
 * @return array<string,int> har table mein kitni rows aayin
 */
function backup_restore(string $sql, ?string &$safety = null): array
{
    if (!str_starts_with(ltrim($sql, "\xEF\xBB\xBF \r\n"), '-- MartPOS backup')) {
        throw new RestoreError('This is not a MartPOS backup file. Choose a .sql file downloaded from Settings.');
    }

    $pdo = db();
    // har table ke asal columns (naye/purane version mein farq ho to bhi chale)
    $known = [];
    foreach (BACKUP_TABLES as $t) {
        $st = $pdo->query("SELECT * FROM $t LIMIT 0");
        $cols = [];
        for ($i = 0; $i < $st->columnCount(); $i++) {
            $cols[] = $st->getColumnMeta($i)['name'];
        }
        $known[$t] = array_flip($cols);
    }

    $data = array_fill_keys(BACKUP_TABLES, []);
    $colsOf = [];
    $p = 0;
    while (preg_match('/INSERT INTO `(\w+)` \(([^)]*)\) VALUES/', $sql, $m, PREG_OFFSET_CAPTURE, $p)) {
        $table = $m[1][0];
        $p = $m[0][1] + strlen($m[0][0]);
        if (!isset($known[$table])) {
            throw new RestoreError('Unknown table in backup: ' . $table);
        }
        $cols = array_map(fn($c) => trim($c, " `"), explode(',', $m[2][0]));
        $rows = parse_tuples($sql, $p);
        foreach ($rows as $r) {
            if (count($r) !== count($cols)) {
                throw new RestoreError('Backup file is damaged (columns).');
            }
            $data[$table][] = array_combine($cols, $r);
        }
        $colsOf[$table] = $cols;
    }

    if (!$data['users'] || !$data['settings']) {
        throw new RestoreError('Backup has no login or settings data. The file looks incomplete.');
    }

    // File theek hai — ab hata ne se pehle abhi wale data ki copy server par
    $safety = backup_to_file('before-restore');

    $pdo->beginTransaction();
    try {
        foreach (array_reverse(BACKUP_TABLES) as $t) {
            $pdo->exec("DELETE FROM $t");
        }
        $counts = [];
        foreach (BACKUP_TABLES as $t) {
            $counts[$t] = count($data[$t]);
            if (!$data[$t]) {
                continue;
            }
            $use = array_values(array_filter($colsOf[$t], fn($c) => isset($known[$t][$c])));
            $ins = $pdo->prepare("INSERT INTO $t (" . implode(', ', $use) . ') VALUES (' . implode(', ', array_fill(0, count($use), '?')) . ')');
            foreach ($data[$t] as $row) {
                $ins->execute(array_map(fn($c) => $row[$c], $use));
            }
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        throw new RestoreError('Restore failed; your existing data is unchanged. (' . $ex->getMessage() . ')');
    }

    // Purane (1.0.x) backup mein payments nahi hote: sab bills cash
    $pdo->exec("INSERT INTO sale_payments (sale_id, method, amount, created_at)
                SELECT id, 'cash', total, created_at FROM sales WHERE id NOT IN (SELECT sale_id FROM sale_payments)");
    // Kam az kam ek chalu admin
    if (!(int)qv("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")) {
        $pdo->exec("UPDATE users SET role = 'admin', active = 1 WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM users) t)");
    }
    set_setting('schema_version', (string)SCHEMA_VERSION);
    log_activity('restore', 'Backup restore: ' . $counts['products'] . ' products, ' . $counts['sales'] . ' bills');

    // Billing screens ko batao ke poori product list dobara lein
    set_setting('data_version', bin2hex(random_bytes(6)));
    return $counts;
}

function data_version(): string
{
    $v = setting('data_version');
    if (!$v) {
        $v = bin2hex(random_bytes(6));
        set_setting('data_version', $v);
    }
    return $v;
}


/** Roz ek automatic backup server par (sirf owner ke page kholne par), aakhri 14 rakho */
function auto_backup_if_due(): void
{
    if (setting('auto_backup_day') === date('Y-m-d')) {
        return;
    }
    set_setting('auto_backup_day', date('Y-m-d'));
    try {
        backup_to_file('auto');
        $files = glob(ROOT . '/storage/backups/auto-*.sql') ?: [];
        rsort($files);
        foreach (array_slice($files, 14) as $old) {
            @unlink($old);
        }
    } catch (Throwable $e) {
        error_log('MartPOS auto backup: ' . $e->getMessage());
    }
}

/** Server par rakhe backups (naya pehle) */
function server_backups(): array
{
    $files = glob(ROOT . '/storage/backups/*.sql') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return array_map(fn($f) => ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)], $files);
}
