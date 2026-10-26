<?php
declare(strict_types=1);

/** Menu: [label, link, icon, permission ('admin' = sirf owner)] */
function nav_items(): array
{
    return [
        'billing'   => ['Billing', 'billing.php', 'M3 5h18v4H3zM5 9v10h14V9M9 13h6', 'billing'],
        'products'  => ['Products', 'products.php', 'M4 7l8-4 8 4-8 4-8-4zm0 0v10l8 4 8-4V7M12 11v10', 'admin'],
        'stock'     => ['Stock', 'stock.php', 'M4 20V10M10 20V4M16 20v-7M22 20H2', 'stock_in'],
        'sales'     => ['Bills', 'sales.php', 'M6 2h12v20l-3-2-3 2-3-2-3 2V2zM9 7h6M9 11h6M9 15h4', 'sales_own'],
        'customers' => ['Credit', 'customers.php', 'M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM19 8v6M22 11h-6', 'customers'],
        'expenses'  => ['Expenses', 'expenses.php', 'M3 7h18v12H3zM3 7l3-4h12l3 4M16 13h2', 'expense'],
        'hisaab'    => ['Day Close', 'hisaab.php', 'M5 3h14v18H5zM8 7h8M8 11h2M12 11h2M8 15h2M12 15h4', 'hisaab_close'],
        'reports'   => ['Reports', 'reports.php', 'M3 3v18h18M7 15l4-4 3 3 5-6', 'admin'],
        'users'     => ['Staff', 'users.php', 'M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zM23 21v-2a4 4 0 00-3-3.9M16 3.1a4 4 0 010 7.8', 'admin'],
        'settings'  => ['Settings', 'settings.php', 'M12 15a3 3 0 100-6 3 3 0 000 6zM19.4 15a1.6 1.6 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.6 1.6 0 00-1.8-.3 1.6 1.6 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.6 1.6 0 00-1-1.5 1.6 1.6 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.6 1.6 0 00.3-1.8 1.6 1.6 0 00-1.5-1H3a2 2 0 110-4h.1a1.6 1.6 0 001.5-1 1.6 1.6 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.6 1.6 0 001.8.3H9a1.6 1.6 0 001-1.5V3a2 2 0 114 0v.1a1.6 1.6 0 001 1.5 1.6 1.6 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.6 1.6 0 00-.3 1.8V9a1.6 1.6 0 001.5 1H21a2 2 0 110 4h-.1a1.6 1.6 0 00-1.5 1z', 'login'],
    ];
}

function nav_allowed(string $perm): bool
{
    return $perm === 'login' ? (bool)current_user() : ($perm === 'admin' ? is_admin() : can($perm));
}

function icon(string $path, int $size = 20): string
{
    return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $path . '"/></svg>';
}

function layout_start(string $title, string $active = ''): void
{
    if (is_admin()) {
        require_once __DIR__ . '/backup_lib.php';
        auto_backup_if_due();
    }
    $shop = setting('shop_name', 'MartPOS');
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0f5c52">
<meta name="csrf" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> — <?= e($shop) ?></title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" href="assets/icons/icon-192.png">
<link rel="stylesheet" href="assets/css/app.css?v=<?= APP_VERSION ?>">
</head>
<body class="admin">
<header class="topbar">
    <a class="brand" href="billing.php"><span class="brand-mark sm">M</span><span><?= e($shop) ?></span></a>
    <nav class="nav">
        <?php foreach (nav_items() as $key => [$label, $href, $ico, $perm]): if (!nav_allowed($perm)) continue; ?>
            <a href="<?= e($href) ?>" class="<?= $key === $active ? 'active' : '' ?>"><?= icon($ico, 18) ?><span><?= e($label) ?></span></a>
        <?php endforeach; ?>
    </nav>
    <div class="topbar-right">
        <span class="sync-pill" id="syncPill" hidden></span>
        <?php if ($u = current_user()): ?>
            <span class="who"><b><?= e($u['name']) ?></b><span class="badge <?= $u['role'] === 'admin' ? 'ok' : '' ?>"><?= $u['role'] === 'admin' ? 'Admin' : 'Staff' ?></span></span>
        <?php endif; ?>
        <form method="post" action="logout.php"><?= csrf_field() ?><button class="btn ghost sm" type="submit">Logout</button></form>
    </div>
</header>
<main class="page">
    <?php foreach (flashes() as $fl): ?>
        <div class="alert <?= e($fl['type']) ?>"><?= e($fl['msg']) ?></div>
    <?php endforeach;
}

function layout_end(array $scripts = []): void
{
    ?>
</main>
<script src="assets/js/store.js?v=<?= APP_VERSION ?>"></script>
<?php foreach ($scripts as $s): ?>
<script src="<?= e($s) ?>?v=<?= APP_VERSION ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

/** Simple pagination links */
function pager(int $page, int $pages, array $query = []): string
{
    if ($pages <= 1) {
        return '';
    }
    $html = '<div class="pager">';
    $mk = function (int $p, string $label, bool $active = false) use ($query) {
        $query['page'] = $p;
        return '<a class="' . ($active ? 'active' : '') . '" href="?' . e(http_build_query($query)) . '">' . $label . '</a>';
    };
    if ($page > 1) {
        $html .= $mk($page - 1, '&lsaquo; Previous');
    }
    $html .= '<span>Page ' . $page . ' / ' . $pages . '</span>';
    if ($page < $pages) {
        $html .= $mk($page + 1, 'Next &rsaquo;');
    }
    return $html . '</div>';
}
