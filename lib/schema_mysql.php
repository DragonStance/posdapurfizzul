<?php
declare(strict_types=1);

/**
 * Skema database untuk MySQL / MariaDB (InnoDB, utf8mb4).
 *
 * Nama tabel dan kolom SAMA PERSIS dengan versi SQLite (lib/schema.php) supaya
 * seluruh kode aplikasi berjalan tanpa perubahan. Perbedaan tipe mengikuti
 * kebiasaan MySQL:
 *   - angka uang  -> DECIMAL(18,2)
 *   - jumlah/stok -> DECIMAL(16,4)
 *   - tanggal     -> DATE / DATETIME
 *   - teks pendek -> VARCHAR, catatan -> TEXT
 *   - aktif/nonaktif -> TINYINT(1)
 *
 * $prefix dipakai bila hosting hanya memberi satu database yang sudah berisi
 * tabel lain (mis. "fizzul_").
 */
function mysql_ddl(string $prefix = ''): array
{
    $t = fn(string $name): string => '`' . $prefix . $name . '`';
    $sql = [];

    $sql[] = "CREATE TABLE IF NOT EXISTS {$t('settings')} (
        `k` VARCHAR(64) NOT NULL,
        `v` TEXT NOT NULL,
        PRIMARY KEY (`k`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$t('roles')} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `code` VARCHAR(20) NOT NULL,
        `name` VARCHAR(80) NOT NULL,
        `permissions` TEXT NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_{$prefix}roles_code` (`code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$t('users')} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `username` VARCHAR(60) NOT NULL,
        `name` VARCHAR(120) NOT NULL,
        `email` VARCHAR(160) NULL,
        `phone` VARCHAR(40) NULL,
        `role_id` INT UNSIGNED NOT NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `last_login` DATETIME NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_{$prefix}users_username` (`username`),
        KEY `idx_{$prefix}users_role` (`role_id`),
        CONSTRAINT `fk_{$prefix}users_role` FOREIGN KEY (`role_id`) REFERENCES {$t('roles')} (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$t('ingredient_categories')} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(120) NOT NULL,
        `description` TEXT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_{$prefix}categories_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$t('suppliers')} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `code` VARCHAR(40) NOT NULL,
        `name` VARCHAR(160) NOT NULL,
        `contact_person` VARCHAR(120) NULL,
        `phone` VARCHAR(40) NULL,
        `email` VARCHAR(160) NULL,
        `address` TEXT NULL,
        `notes` TEXT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_{$prefix}suppliers_code` (`code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$t('ingredients')} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `code` VARCHAR(40) NOT NULL,
        `name` VARCHAR(160) NOT NULL,
        `category_id` INT UNSIGNED NULL,
        `unit` VARCHAR(16) NOT NULL,
        `min_stock` DECIMAL(16,4) NOT NULL DEFAULT 0,
        `current_stock` DECIMAL(16,4) NOT NULL DEFAULT 0,
        `avg_cost` DECIMAL(18,4) NOT NULL DEFAULT 0,
        `last_cost` DECIMAL(18,4) NOT NULL DEFAULT 0,
        `supplier_id` INT UNSIGNED NULL,
        `storage_location` VARCHAR(120) NULL,
        `expiry_info` VARCHAR(255) NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_{$prefix}ingredients_code` (`code`),
        KEY `idx_{$prefix}ingredients_cat` (`category_id`),
        KEY `idx_{$prefix}ingredients_sup` (`supplier_id`),
        CONSTRAINT `fk_{$prefix}ingredients_cat` FOREIGN KEY (`category_id`) REFERENCES {$t('ingredient_categories')} (`id`),
        CONSTRAINT `fk_{$prefix}ingredients_sup` FOREIGN KEY (`supplier_id`) REFERENCES {$t('suppliers')} (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$t('menus')} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `code` VARCHAR(40) NOT NULL,
        `name` VARCHAR(160) NOT NULL,
        `category` VARCHAR(60) NOT NULL DEFAULT 'Makanan Utama',
        `price` DECIMAL(18,2) NOT NULL DEFAULT 0,
        `description` TEXT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_{$prefix}menus_code` (`code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$t('recipes')} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `menu_id` INT UNSIGNED NOT NULL,
        `yield_qty` DECIMAL(16,4) NOT NULL DEFAULT 1,
        `yield_unit` VARCHAR(20) NOT NULL DEFAULT 'porsi',
        `notes` TEXT NULL,
        `updated_at` DATETIME NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_{$prefix}recipes_menu` (`menu_id`),
        CONSTRAINT `fk_{$prefix}recipes_menu` FOREIGN KEY (`menu_id`) REFERENCES {$t('menus')} (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$t('recipe_items')} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `recipe_id` INT UNSIGNED NOT NULL,
        `ingredient_id` INT UNSIGNED NOT NULL,
        `qty` DECIMAL(16,4) NOT NULL,
        `unit` VARCHAR(16) NOT NULL,
        `note` TEXT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_{$prefix}recipe_items_recipe` (`recipe_id`),
        KEY `idx_{$prefix}recipe_items_ing` (`ingredient_id`),
        CONSTRAINT `fk_{$prefix}recipe_items_recipe` FOREIGN KEY (`recipe_id`) REFERENCES {$t('recipes')} (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_{$prefix}recipe_items_ing` FOREIGN KEY (`ingredient_id`) REFERENCES {$t('ingredients')} (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$t('sales')} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `invoice_no` VARCHAR(60) NOT NULL,
        `sold_at` DATETIME NOT NULL,
        `channel` VARCHAR(40) NOT NULL,
        `payment_method` VARCHAR(40) NOT NULL,
        `subtotal` DECIMAL(18,2) NOT NULL DEFAULT 0,
        `discount` DECIMAL(18,2) NOT NULL DEFAULT 0,
        `total` DECIMAL(18,2) NOT NULL DEFAULT 0,
        `hpp_total` DECIMAL(18,2) NOT NULL DEFAULT 0,
        `customer_note` TEXT NULL,
        `source` VARCHAR(20) NOT NULL DEFAULT 'manual',
        `import_ref` VARCHAR(255) NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'selesai',
        `created_by` INT UNSIGNED NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_{$prefix}sales_invoice` (`invoice_no`),
        KEY `idx_{$prefix}sales_date` (`sold_at`),
        KEY `idx_{$prefix}sales_channel` (`channel`),
        KEY `idx_{$prefix}sales_status` (`status`),
        KEY `idx_{$prefix}sales_user` (`created_by`),
        CONSTRAINT `fk_{$prefix}sales_user` FOREIGN KEY (`created_by`) REFERENCES {$t('users')} (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$t('sale_items')} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `sale_id` INT UNSIGNED NOT NULL,
        `menu_id` INT UNSIGNED NOT NULL,
        `qty` DECIMAL(16,4) NOT NULL,
        `unit_price` DECIMAL(18,2) NOT NULL,
        `subtotal` DECIMAL(18,2) NOT NULL,
        `hpp_unit` DECIMAL(18,2) NOT NULL DEFAULT 0,
        `hpp_total` DECIMAL(18,2) NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_{$prefix}sale_items_sale` (`sale_id`),
        KEY `idx_{$prefix}sale_items_menu` (`menu_id`),
        CONSTRAINT `fk_{$prefix}sale_items_sale` FOREIGN KEY (`sale_id`) REFERENCES {$t('sales')} (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_{$prefix}sale_items_menu` FOREIGN KEY (`menu_id`) REFERENCES {$t('menus')} (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$t('purchases')} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `code` VARCHAR(40) NOT NULL,
        `purchased_at` DATETIME NOT NULL,
        `supplier_id` INT UNSIGNED NULL,
        `invoice_no` VARCHAR(60) NULL,
        `total_cost` DECIMAL(18,2) NOT NULL DEFAULT 0,
        `note` TEXT NULL,
        `created_by` INT UNSIGNED NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_{$prefix}purchases_code` (`code`),
        KEY `idx_{$prefix}purchases_date` (`purchased_at`),
        KEY `idx_{$prefix}purchases_sup` (`supplier_id`),
        CONSTRAINT `fk_{$prefix}purchases_sup` FOREIGN KEY (`supplier_id`) REFERENCES {$t('suppliers')} (`id`),
        CONSTRAINT `fk_{$prefix}purchases_user` FOREIGN KEY (`created_by`) REFERENCES {$t('users')} (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$t('purchase_items')} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `purchase_id` INT UNSIGNED NOT NULL,
        `ingredient_id` INT UNSIGNED NOT NULL,
        `qty` DECIMAL(16,4) NOT NULL,
        `unit` VARCHAR(16) NOT NULL,
        `unit_cost` DECIMAL(18,4) NOT NULL,
        `total_cost` DECIMAL(18,2) NOT NULL,
        `expiry_date` DATE NULL,
        `note` TEXT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_{$prefix}purchase_items_p` (`purchase_id`),
        KEY `idx_{$prefix}purchase_items_ing` (`ingredient_id`),
        CONSTRAINT `fk_{$prefix}purchase_items_p` FOREIGN KEY (`purchase_id`) REFERENCES {$t('purchases')} (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_{$prefix}purchase_items_ing` FOREIGN KEY (`ingredient_id`) REFERENCES {$t('ingredients')} (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$t('inventory_movements')} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `moved_at` DATETIME NOT NULL,
        `ingredient_id` INT UNSIGNED NOT NULL,
        `movement_type` VARCHAR(30) NOT NULL,
        `qty` DECIMAL(16,4) NOT NULL,
        `unit` VARCHAR(16) NOT NULL,
        `stock_before` DECIMAL(16,4) NOT NULL,
        `stock_after` DECIMAL(16,4) NOT NULL,
        `unit_cost` DECIMAL(18,4) NOT NULL DEFAULT 0,
        `total_cost` DECIMAL(18,2) NOT NULL DEFAULT 0,
        `ref_type` VARCHAR(30) NULL,
        `ref_id` INT UNSIGNED NULL,
        `ref_code` VARCHAR(60) NULL,
        `note` TEXT NULL,
        `user_id` INT UNSIGNED NULL,
        `user_name` VARCHAR(120) NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_{$prefix}mov_ing_date` (`ingredient_id`, `moved_at`),
        KEY `idx_{$prefix}mov_date` (`moved_at`),
        KEY `idx_{$prefix}mov_type` (`movement_type`),
        KEY `idx_{$prefix}mov_ref` (`ref_type`, `ref_id`),
        CONSTRAINT `fk_{$prefix}mov_ing` FOREIGN KEY (`ingredient_id`) REFERENCES {$t('ingredients')} (`id`),
        CONSTRAINT `fk_{$prefix}mov_user` FOREIGN KEY (`user_id`) REFERENCES {$t('users')} (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$t('stock_opnames')} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `code` VARCHAR(40) NOT NULL,
        `opname_date` DATE NOT NULL,
        `scope` VARCHAR(60) NOT NULL DEFAULT 'Semua Bahan',
        `status` VARCHAR(20) NOT NULL DEFAULT 'draft',
        `note` TEXT NULL,
        `created_by` INT UNSIGNED NULL,
        `created_by_name` VARCHAR(120) NULL,
        `submitted_at` DATETIME NULL,
        `approved_by` INT UNSIGNED NULL,
        `approved_by_name` VARCHAR(120) NULL,
        `approved_at` DATETIME NULL,
        `approval_note` TEXT NULL,
        `created_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_{$prefix}opnames_code` (`code`),
        KEY `idx_{$prefix}opnames_date` (`opname_date`),
        CONSTRAINT `fk_{$prefix}opnames_user` FOREIGN KEY (`created_by`) REFERENCES {$t('users')} (`id`),
        CONSTRAINT `fk_{$prefix}opnames_approver` FOREIGN KEY (`approved_by`) REFERENCES {$t('users')} (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$t('stock_opname_items')} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `opname_id` INT UNSIGNED NOT NULL,
        `ingredient_id` INT UNSIGNED NOT NULL,
        `system_qty` DECIMAL(16,4) NOT NULL DEFAULT 0,
        `physical_qty` DECIMAL(16,4) NULL,
        `diff_qty` DECIMAL(16,4) NULL,
        `unit` VARCHAR(16) NOT NULL,
        `reason` VARCHAR(255) NULL,
        `movement_id` INT UNSIGNED NULL,
        PRIMARY KEY (`id`),
        KEY `idx_{$prefix}opname_items_op` (`opname_id`),
        KEY `idx_{$prefix}opname_items_ing` (`ingredient_id`),
        CONSTRAINT `fk_{$prefix}opname_items_op` FOREIGN KEY (`opname_id`) REFERENCES {$t('stock_opnames')} (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_{$prefix}opname_items_ing` FOREIGN KEY (`ingredient_id`) REFERENCES {$t('ingredients')} (`id`),
        CONSTRAINT `fk_{$prefix}opname_items_mov` FOREIGN KEY (`movement_id`) REFERENCES {$t('inventory_movements')} (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$t('waste_records')} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `code` VARCHAR(40) NOT NULL,
        `wasted_at` DATETIME NOT NULL,
        `ingredient_id` INT UNSIGNED NOT NULL,
        `qty` DECIMAL(16,4) NOT NULL,
        `unit` VARCHAR(16) NOT NULL,
        `reason` VARCHAR(60) NOT NULL,
        `est_cost` DECIMAL(18,2) NOT NULL DEFAULT 0,
        `note` TEXT NULL,
        `status` VARCHAR(20) NOT NULL DEFAULT 'confirmed',
        `movement_id` INT UNSIGNED NULL,
        `created_by` INT UNSIGNED NULL,
        `created_by_name` VARCHAR(120) NULL,
        `created_at` DATETIME NOT NULL,
        `voided_by` INT UNSIGNED NULL,
        `voided_at` DATETIME NULL,
        `void_reason` TEXT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_{$prefix}waste_code` (`code`),
        KEY `idx_{$prefix}waste_date` (`wasted_at`),
        KEY `idx_{$prefix}waste_ing` (`ingredient_id`),
        CONSTRAINT `fk_{$prefix}waste_ing` FOREIGN KEY (`ingredient_id`) REFERENCES {$t('ingredients')} (`id`),
        CONSTRAINT `fk_{$prefix}waste_mov` FOREIGN KEY (`movement_id`) REFERENCES {$t('inventory_movements')} (`id`),
        CONSTRAINT `fk_{$prefix}waste_user` FOREIGN KEY (`created_by`) REFERENCES {$t('users')} (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$t('audit_logs')} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `logged_at` DATETIME NOT NULL,
        `user_id` INT UNSIGNED NULL,
        `user_name` VARCHAR(120) NULL,
        `action` VARCHAR(30) NOT NULL,
        `entity` VARCHAR(40) NULL,
        `entity_id` INT UNSIGNED NULL,
        `description` TEXT NULL,
        `meta` TEXT NULL,
        `ip` VARCHAR(45) NULL,
        PRIMARY KEY (`id`),
        KEY `idx_{$prefix}audit_date` (`logged_at`),
        KEY `idx_{$prefix}audit_action` (`action`),
        CONSTRAINT `fk_{$prefix}audit_user` FOREIGN KEY (`user_id`) REFERENCES {$t('users')} (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    // Tabel penyimpanan sesi login (opsional, dipakai bila SESSION_DRIVER='mysql').
    // Dibuat otomatis oleh aplikasi bila belum ada, jadi tidak perlu dibuat manual.
    $sql[] = "CREATE TABLE IF NOT EXISTS {$t('sessions')} (
        `id` VARCHAR(128) NOT NULL,
        `data` MEDIUMTEXT NOT NULL,
        `user_id` INT UNSIGNED NULL,
        `user_name` VARCHAR(120) NULL,
        `ip` VARCHAR(45) NULL,
        `user_agent` VARCHAR(255) NULL,
        `created_at` DATETIME NOT NULL,
        `updated_at` DATETIME NOT NULL,
        `expires_at` DATETIME NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_{$prefix}session_expiry` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    $sql[] = "CREATE TABLE IF NOT EXISTS {$t('import_batches')} (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `created_at` DATETIME NOT NULL,
        `file_name` VARCHAR(255) NULL,
        `rows_total` INT NOT NULL DEFAULT 0,
        `rows_ok` INT NOT NULL DEFAULT 0,
        `rows_failed` INT NOT NULL DEFAULT 0,
        `total_value` DECIMAL(18,2) NOT NULL DEFAULT 0,
        `created_by` INT UNSIGNED NULL,
        `created_by_name` VARCHAR(120) NULL,
        `note` TEXT NULL,
        PRIMARY KEY (`id`),
        CONSTRAINT `fk_{$prefix}batches_user` FOREIGN KEY (`created_by`) REFERENCES {$t('users')} (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    return $sql;
}
