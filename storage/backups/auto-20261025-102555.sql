-- MartPOS backup
-- Dukaan: Flingr
-- Waqt: 2026-10-25 10:25:55
-- Wapas laane ke liye: MartPOS -> Settings -> Backup restore.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(60) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS settings (
        k VARCHAR(64) NOT NULL PRIMARY KEY,
        v TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS products (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        barcode VARCHAR(64) NULL,
        name VARCHAR(190) NOT NULL,
        unit VARCHAR(10) NOT NULL DEFAULT 'pcs',
        purchase_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        sale_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        stock DECIMAL(12,3) NOT NULL DEFAULT 0,
        low_stock DECIMAL(12,3) NULL,
        quick_key INT NULL,
        deleted_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE UNIQUE INDEX ux_products_barcode ON products (barcode);
CREATE INDEX ix_products_updated ON products (updated_at);
CREATE INDEX ix_products_name ON products (name);
CREATE TABLE IF NOT EXISTS sales (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        uuid VARCHAR(40) NOT NULL,
        bill_no VARCHAR(40) NOT NULL,
        created_at DATETIME NOT NULL,
        synced_at DATETIME NOT NULL,
        items_count INT NOT NULL DEFAULT 0,
        subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
        discount DECIMAL(12,2) NOT NULL DEFAULT 0,
        total DECIMAL(12,2) NOT NULL DEFAULT 0,
        paid DECIMAL(12,2) NOT NULL DEFAULT 0,
        change_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        returned_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        status VARCHAR(12) NOT NULL DEFAULT 'completed',
        cancelled_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE UNIQUE INDEX ux_sales_uuid ON sales (uuid);
CREATE INDEX ix_sales_created ON sales (created_at);
CREATE INDEX ix_sales_billno ON sales (bill_no);
CREATE TABLE IF NOT EXISTS sale_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sale_id INT UNSIGNED NOT NULL,
        product_id INT UNSIGNED NULL,
        barcode VARCHAR(64) NULL,
        name VARCHAR(190) NOT NULL,
        unit VARCHAR(10) NOT NULL DEFAULT 'pcs',
        qty DECIMAL(12,3) NOT NULL,
        price DECIMAL(12,2) NOT NULL,
        cost DECIMAL(12,2) NOT NULL DEFAULT 0,
        line_total DECIMAL(12,2) NOT NULL,
        returned_qty DECIMAL(12,3) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX ix_items_sale ON sale_items (sale_id);
CREATE INDEX ix_items_product ON sale_items (product_id);
CREATE TABLE IF NOT EXISTS returns (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        sale_id INT UNSIGNED NOT NULL,
        sale_item_id INT UNSIGNED NOT NULL,
        qty DECIMAL(12,3) NOT NULL,
        amount DECIMAL(12,2) NOT NULL,
        cost_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX ix_returns_created ON returns (created_at);
CREATE TABLE IF NOT EXISTS stock_moves (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED NOT NULL,
        qty_change DECIMAL(12,3) NOT NULL,
        type VARCHAR(16) NOT NULL,
        ref_id INT UNSIGNED NULL,
        note VARCHAR(190) NULL,
        created_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX ix_moves_product ON stock_moves (product_id);
ALTER TABLE users ADD COLUMN full_name VARCHAR(80) NULL;
ALTER TABLE users ADD COLUMN role VARCHAR(10) NOT NULL DEFAULT 'admin';
ALTER TABLE users ADD COLUMN active INT NOT NULL DEFAULT 1;
ALTER TABLE users ADD COLUMN pin_hash VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN pin_salt VARCHAR(64) NULL;
ALTER TABLE users ADD COLUMN pin_kdf VARCHAR(128) NULL;
ALTER TABLE users ADD COLUMN last_login DATETIME NULL;
CREATE UNIQUE INDEX ux_users_username ON users (username);
ALTER TABLE products ADD COLUMN needs_review INT NOT NULL DEFAULT 0;
ALTER TABLE sales ADD COLUMN user_id INT UNSIGNED NULL;
ALTER TABLE sales ADD COLUMN cashier VARCHAR(80) NULL;
ALTER TABLE sales ADD COLUMN customer_id INT UNSIGNED NULL;
CREATE INDEX ix_sales_user ON sales (user_id);
CREATE INDEX ix_sales_customer ON sales (customer_id);
ALTER TABLE returns ADD COLUMN user_id INT UNSIGNED NULL;
ALTER TABLE returns ADD COLUMN refund_method VARCHAR(12) NULL;
CREATE TABLE IF NOT EXISTS sale_payments (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                sale_id INT UNSIGNED NOT NULL,
                method VARCHAR(12) NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                ref VARCHAR(80) NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX ix_pay_sale ON sale_payments (sale_id);
CREATE INDEX ix_pay_created ON sale_payments (created_at);
CREATE TABLE IF NOT EXISTS customers (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                uuid VARCHAR(40) NOT NULL,
                name VARCHAR(120) NOT NULL,
                phone VARCHAR(30) NULL,
                address VARCHAR(190) NULL,
                credit_limit DECIMAL(12,2) NULL,
                active INT NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE UNIQUE INDEX ux_customers_uuid ON customers (uuid);
CREATE INDEX ix_customers_name ON customers (name);
CREATE TABLE IF NOT EXISTS customer_ledger (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                customer_id INT UNSIGNED NOT NULL,
                type VARCHAR(12) NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                method VARCHAR(12) NULL,
                ref VARCHAR(80) NULL,
                sale_id INT UNSIGNED NULL,
                note VARCHAR(190) NULL,
                user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX ix_ledger_customer ON customer_ledger (customer_id);
CREATE INDEX ix_ledger_created ON customer_ledger (created_at);
CREATE TABLE IF NOT EXISTS cash_moves (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(12) NOT NULL,
                category VARCHAR(40) NULL,
                amount DECIMAL(12,2) NOT NULL,
                note VARCHAR(190) NULL,
                user_id INT UNSIGNED NULL,
                created_at DATETIME NOT NULL,
                deleted_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX ix_cash_created ON cash_moves (created_at);
CREATE TABLE IF NOT EXISTS day_closes (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                day DATE NOT NULL,
                opening_cash DECIMAL(12,2) NOT NULL DEFAULT 0,
                status VARCHAR(10) NOT NULL DEFAULT 'open',
                expected DECIMAL(12,2) NULL,
                counted DECIMAL(12,2) NULL,
                diff DECIMAL(12,2) NULL,
                note VARCHAR(190) NULL,
                opened_by INT UNSIGNED NULL,
                closed_by INT UNSIGNED NULL,
                closed_at DATETIME NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE UNIQUE INDEX ux_days_day ON day_closes (day);
CREATE TABLE IF NOT EXISTS activity_log (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                user_name VARCHAR(80) NULL,
                action VARCHAR(40) NOT NULL,
                details VARCHAR(255) NULL,
                created_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX ix_log_created ON activity_log (created_at);

INSERT INTO `settings` (`k`, `v`) VALUES
('auto_backup_day', '2026-10-25'),
('currency', 'Rs'),
('data_version', '282de798217b'),
('label_height', '25'),
('label_width', '38'),
('lf:0db93f29691ccb1e0a301474', '0'),
('login_fails', '0'),
('low_stock', '5'),
('receipt_footer', 'Shukriya! Phir tashreef laiye.'),
('schema_version', '2'),
('shop_address', ''),
('shop_name', 'Flingr'),
('shop_phone', '');

INSERT INTO `users` (`id`, `username`, `password_hash`, `created_at`, `full_name`, `role`, `active`, `pin_hash`, `pin_salt`, `pin_kdf`, `last_login`) VALUES
(1, 'ahtisham', '$2y$10$RLcdOLADzIQypOmO55Y2AO1mHI1lEYzqYo44DetUpZ9KpgRNyjQ..', '2026-10-24 22:19:38', 'ahtisham', 'admin', 1, '$2y$10$AHdvnC5d4uUxaqDqIiyuFejZuhbby4suhFFS4X1ajzdHycoI84qRe', 'de24a5263e45bb38ca14d9bd5c83696a', '70b2db2f4be209bc064b12a1a15d374aaa2b499b2dbd191b96c1a3604ae47dc4', '2026-10-25 10:25:47'),
(2, 'saud', '$2y$10$9mqpkry34BzCl2EFSMbXluPkmAPdOzNyRqrtthMBWs6DzoLKELHaS', '2026-10-25 04:09:44', 'saud hoath', 'staff', 1, NULL, NULL, NULL, '2026-10-25 04:29:42');

INSERT INTO `products` (`id`, `barcode`, `name`, `unit`, `purchase_price`, `sale_price`, `stock`, `low_stock`, `quick_key`, `deleted_at`, `created_at`, `updated_at`, `needs_review`) VALUES
(1, '2000000000015', 'Sugar (500)mg', 'pcs', '120.00', '150.00', '-9.000', '5.000', 2, NULL, '2026-10-24 22:37:03', '2026-10-25 04:28:59', 0),
(2, '2000000000022', 'orange', 'pcs', '300.00', '600.00', '15.000', NULL, NULL, NULL, '2026-10-25 04:18:16', '2026-10-25 04:18:16', 0),
(3, '2000000000039', 'sabzi', 'pcs', '120.00', '250.00', '34.000', NULL, NULL, NULL, '2026-10-25 04:26:14', '2026-10-25 04:26:14', 0);


INSERT INTO `sales` (`id`, `uuid`, `bill_no`, `created_at`, `synced_at`, `items_count`, `subtotal`, `discount`, `total`, `paid`, `change_amount`, `returned_amount`, `status`, `cancelled_at`, `user_id`, `cashier`, `customer_id`) VALUES
(1, '9af67052-57d3-4c51-bf4e-86411ffc8192', '261024-001', '2026-10-24 22:58:55', '2026-10-24 22:59:25', 1, '1200.00', '240.00', '960.00', '960.00', '0.00', '0.00', 'completed', NULL, NULL, NULL, NULL),
(2, 'bd72efda-32b8-478a-a9df-6b2edd4c59ad', '261024-002', '2026-10-24 22:59:41', '2026-10-24 23:00:29', 1, '150.00', '0.00', '150.00', '300.00', '150.00', '0.00', 'completed', NULL, NULL, NULL, NULL),
(3, 'a825f98a-bfbd-4f9e-878a-753cd5c67c38', '261025-001', '2026-10-25 03:15:25', '2026-10-25 03:15:41', 1, '150.00', '0.00', '150.00', '500.00', '350.00', '0.00', 'completed', NULL, NULL, NULL, NULL),
(4, '9d8b2422-8171-4634-887a-e98e158610ae', '261025-002', '2026-10-25 04:23:10', '2026-10-25 04:23:13', 1, '300.00', '0.00', '300.00', '300.00', '0.00', '0.00', 'completed', NULL, 2, 'saud hoath', NULL),
(5, 'd292d544-c2e9-4d84-9cdc-cafd49d9d68e', '261025-003', '2026-10-25 04:23:25', '2026-10-25 04:23:32', 1, '300.00', '0.00', '300.00', '600.00', '300.00', '0.00', 'completed', NULL, 2, 'saud hoath', NULL),
(6, 'fbda55ec-7b53-4716-b318-8c2f54c3408a', '261025-004', '2026-10-25 04:28:57', '2026-10-25 04:28:59', 1, '1500.00', '0.00', '1500.00', '1500.00', '0.00', '0.00', 'completed', NULL, 2, 'saud hoath', NULL);

INSERT INTO `sale_items` (`id`, `sale_id`, `product_id`, `barcode`, `name`, `unit`, `qty`, `price`, `cost`, `line_total`, `returned_qty`) VALUES
(1, 1, 1, '2000000000015', 'Sugar (500)mg', 'pcs', '8.000', '150.00', '120.00', '1200.00', '0.000'),
(2, 2, 1, '2000000000015', 'Sugar (500)mg', 'pcs', '1.000', '150.00', '120.00', '150.00', '0.000'),
(3, 3, 1, '2000000000015', 'Sugar (500)mg', 'pcs', '1.000', '150.00', '120.00', '150.00', '0.000'),
(4, 4, 1, '2000000000015', 'Sugar (500)mg', 'pcs', '2.000', '150.00', '120.00', '300.00', '0.000'),
(5, 5, 1, '2000000000015', 'Sugar (500)mg', 'pcs', '2.000', '150.00', '120.00', '300.00', '0.000'),
(6, 6, 1, '2000000000015', 'Sugar (500)mg', 'pcs', '10.000', '150.00', '120.00', '1500.00', '0.000');

INSERT INTO `sale_payments` (`id`, `sale_id`, `method`, `amount`, `ref`, `created_at`) VALUES
(1, 1, 'cash', '960.00', NULL, '2026-10-24 22:58:55'),
(2, 2, 'cash', '150.00', NULL, '2026-10-24 22:59:41'),
(3, 3, 'cash', '150.00', NULL, '2026-10-25 03:15:25'),
(4, 4, 'cash', '300.00', NULL, '2026-10-25 04:23:10'),
(5, 5, 'cash', '300.00', NULL, '2026-10-25 04:23:25'),
(6, 6, 'cash', '1500.00', NULL, '2026-10-25 04:28:57');




INSERT INTO `day_closes` (`id`, `day`, `opening_cash`, `status`, `expected`, `counted`, `diff`, `note`, `opened_by`, `closed_by`, `closed_at`, `created_at`) VALUES
(1, '2026-10-25', '1000.00', 'closed', '1150.00', '9000.00', '7850.00', NULL, 2, 2, '2026-10-25 04:14:07', '2026-10-25 04:13:39');

INSERT INTO `stock_moves` (`id`, `product_id`, `qty_change`, `type`, `ref_id`, `note`, `created_at`) VALUES
(1, 1, '15.000', 'opening', NULL, 'Naya product', '2026-10-24 22:37:03'),
(2, 1, '-8.000', 'sale', 1, 'Bill 261024-001', '2026-10-24 22:59:25'),
(3, 1, '-1.000', 'sale', 2, 'Bill 261024-002', '2026-10-24 23:00:29'),
(4, 1, '-1.000', 'sale', 3, 'Bill 261025-001', '2026-10-25 03:15:41'),
(5, 2, '15.000', 'opening', NULL, 'Naya product', '2026-10-25 04:18:16'),
(6, 1, '-2.000', 'sale', 4, 'Bill 261025-002', '2026-10-25 04:23:13'),
(7, 1, '-2.000', 'sale', 5, 'Bill 261025-003', '2026-10-25 04:23:32'),
(8, 3, '34.000', 'opening', NULL, 'Naya product', '2026-10-25 04:26:14'),
(9, 1, '-10.000', 'sale', 6, 'Bill 261025-004', '2026-10-25 04:28:59');

INSERT INTO `activity_log` (`id`, `user_id`, `user_name`, `action`, `details`, `created_at`) VALUES
(1, 1, 'ahtisham', 'staff_add', 'saud hoath (staff)', '2026-10-25 04:09:44'),
(2, 1, 'ahtisham', 'staff_edit', 'saud hoath: password badla', '2026-10-25 04:10:06'),
(3, 1, 'ahtisham', 'staff_edit', 'ahtisham', '2026-10-25 04:10:50'),
(4, 1, 'ahtisham', 'staff_edit', 'ahtisham: PIN set', '2026-10-25 04:10:50'),
(5, 1, 'ahtisham', 'login', 'Login', '2026-10-25 04:11:29'),
(6, 1, 'ahtisham', 'staff_edit', 'saud hoath: password badla', '2026-10-25 04:11:43'),
(7, 2, 'saud hoath', 'login', 'Login', '2026-10-25 04:11:51'),
(8, 2, 'saud hoath', 'day_open', '2026-10-25: galle mein 1,000', '2026-10-25 04:13:39'),
(9, 2, 'saud hoath', 'day_close', '2026-10-25: gine 9,000, hone chahiye 1,150, farq +7,850', '2026-10-25 04:14:07'),
(10, 1, 'ahtisham', 'login', 'Login', '2026-10-25 04:15:07'),
(11, 1, 'ahtisham', 'product_add', 'orange — 600 [2000000000022]', '2026-10-25 04:18:16'),
(12, 2, 'saud hoath', 'login', 'Login', '2026-10-25 04:19:45'),
(13, 1, 'ahtisham', 'login', 'Login', '2026-10-25 04:25:04'),
(14, 1, 'ahtisham', 'product_add', 'sabzi — 250 [2000000000039]', '2026-10-25 04:26:14'),
(15, 2, 'saud hoath', 'login', 'Login', '2026-10-25 04:26:33'),
(16, 2, 'saud hoath', 'login', 'Login', '2026-10-25 04:29:42'),
(17, 1, 'ahtisham', 'login', 'Login', '2026-10-25 10:25:47');

SET FOREIGN_KEY_CHECKS = 1;
