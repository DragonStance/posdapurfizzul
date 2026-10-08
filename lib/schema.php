<?php
declare(strict_types=1);

/**
 * Skema database relasional (ternormalisasi) untuk SIMOP Dapur Fizzul.
 * Idempotent: seluruh statement CREATE IF NOT EXISTS, dijalankan di dalam
 * satu transaksi oleh ensure_ready().
 */
function migrate_schema(PDO $pdo): void
{
    if (db_driver() === 'mysql') {
        foreach (mysql_ddl(db_prefix()) as $sql) {
            $pdo->exec($sql);
        }
        seed_roles($pdo);
        return;
    }

    $ddl = [
        "CREATE TABLE IF NOT EXISTS settings (
            k TEXT PRIMARY KEY,
            v TEXT NOT NULL
        )",

        "CREATE TABLE IF NOT EXISTS roles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            permissions TEXT NOT NULL DEFAULT ''
        )",

        "CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            email TEXT,
            phone TEXT,
            role_id INTEGER NOT NULL REFERENCES roles(id),
            password_hash TEXT NOT NULL,
            is_active INTEGER NOT NULL DEFAULT 1,
            last_login TEXT,
            created_at TEXT NOT NULL
        )",

        "CREATE TABLE IF NOT EXISTS ingredient_categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            description TEXT
        )",

        "CREATE TABLE IF NOT EXISTS suppliers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            contact_person TEXT,
            phone TEXT,
            email TEXT,
            address TEXT,
            notes TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL
        )",

        "CREATE TABLE IF NOT EXISTS ingredients (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            category_id INTEGER REFERENCES ingredient_categories(id),
            unit TEXT NOT NULL,
            min_stock REAL NOT NULL DEFAULT 0,
            current_stock REAL NOT NULL DEFAULT 0,
            avg_cost REAL NOT NULL DEFAULT 0,
            last_cost REAL NOT NULL DEFAULT 0,
            supplier_id INTEGER REFERENCES suppliers(id),
            storage_location TEXT,
            expiry_info TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL,
            updated_at TEXT
        )",

        "CREATE TABLE IF NOT EXISTS menus (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name TEXT NOT NULL,
            category TEXT NOT NULL DEFAULT 'Makanan Utama',
            price REAL NOT NULL DEFAULT 0,
            description TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL
        )",

        "CREATE TABLE IF NOT EXISTS recipes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            menu_id INTEGER NOT NULL UNIQUE REFERENCES menus(id) ON DELETE CASCADE,
            yield_qty REAL NOT NULL DEFAULT 1,
            yield_unit TEXT NOT NULL DEFAULT 'porsi',
            notes TEXT,
            updated_at TEXT
        )",

        "CREATE TABLE IF NOT EXISTS recipe_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            recipe_id INTEGER NOT NULL REFERENCES recipes(id) ON DELETE CASCADE,
            ingredient_id INTEGER NOT NULL REFERENCES ingredients(id),
            qty REAL NOT NULL,
            unit TEXT NOT NULL,
            note TEXT
        )",

        "CREATE TABLE IF NOT EXISTS sales (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_no TEXT NOT NULL UNIQUE,
            sold_at TEXT NOT NULL,
            channel TEXT NOT NULL,
            payment_method TEXT NOT NULL,
            subtotal REAL NOT NULL DEFAULT 0,
            discount REAL NOT NULL DEFAULT 0,
            total REAL NOT NULL DEFAULT 0,
            hpp_total REAL NOT NULL DEFAULT 0,
            customer_note TEXT,
            source TEXT NOT NULL DEFAULT 'manual',
            import_ref TEXT,
            status TEXT NOT NULL DEFAULT 'selesai',
            created_by INTEGER REFERENCES users(id),
            created_at TEXT NOT NULL
        )",

        "CREATE TABLE IF NOT EXISTS sale_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sale_id INTEGER NOT NULL REFERENCES sales(id) ON DELETE CASCADE,
            menu_id INTEGER NOT NULL REFERENCES menus(id),
            qty REAL NOT NULL,
            unit_price REAL NOT NULL,
            subtotal REAL NOT NULL,
            hpp_unit REAL NOT NULL DEFAULT 0,
            hpp_total REAL NOT NULL DEFAULT 0
        )",

        "CREATE TABLE IF NOT EXISTS purchases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            purchased_at TEXT NOT NULL,
            supplier_id INTEGER REFERENCES suppliers(id),
            invoice_no TEXT,
            total_cost REAL NOT NULL DEFAULT 0,
            note TEXT,
            created_by INTEGER REFERENCES users(id),
            created_at TEXT NOT NULL
        )",

        "CREATE TABLE IF NOT EXISTS purchase_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            purchase_id INTEGER NOT NULL REFERENCES purchases(id) ON DELETE CASCADE,
            ingredient_id INTEGER NOT NULL REFERENCES ingredients(id),
            qty REAL NOT NULL,
            unit TEXT NOT NULL,
            unit_cost REAL NOT NULL,
            total_cost REAL NOT NULL,
            expiry_date TEXT,
            note TEXT
        )",

        "CREATE TABLE IF NOT EXISTS inventory_movements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            moved_at TEXT NOT NULL,
            ingredient_id INTEGER NOT NULL REFERENCES ingredients(id),
            movement_type TEXT NOT NULL,
            qty REAL NOT NULL,
            unit TEXT NOT NULL,
            stock_before REAL NOT NULL,
            stock_after REAL NOT NULL,
            unit_cost REAL NOT NULL DEFAULT 0,
            total_cost REAL NOT NULL DEFAULT 0,
            ref_type TEXT,
            ref_id INTEGER,
            ref_code TEXT,
            note TEXT,
            user_id INTEGER REFERENCES users(id),
            user_name TEXT,
            created_at TEXT NOT NULL
        )",

        "CREATE TABLE IF NOT EXISTS stock_opnames (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            opname_date TEXT NOT NULL,
            scope TEXT NOT NULL DEFAULT 'Semua Bahan',
            status TEXT NOT NULL DEFAULT 'draft',
            note TEXT,
            created_by INTEGER REFERENCES users(id),
            created_by_name TEXT,
            submitted_at TEXT,
            approved_by INTEGER REFERENCES users(id),
            approved_by_name TEXT,
            approved_at TEXT,
            approval_note TEXT,
            created_at TEXT NOT NULL
        )",

        "CREATE TABLE IF NOT EXISTS stock_opname_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            opname_id INTEGER NOT NULL REFERENCES stock_opnames(id) ON DELETE CASCADE,
            ingredient_id INTEGER NOT NULL REFERENCES ingredients(id),
            system_qty REAL NOT NULL DEFAULT 0,
            physical_qty REAL,
            diff_qty REAL,
            unit TEXT NOT NULL,
            reason TEXT,
            movement_id INTEGER REFERENCES inventory_movements(id)
        )",

        "CREATE TABLE IF NOT EXISTS waste_records (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            wasted_at TEXT NOT NULL,
            ingredient_id INTEGER NOT NULL REFERENCES ingredients(id),
            qty REAL NOT NULL,
            unit TEXT NOT NULL,
            reason TEXT NOT NULL,
            est_cost REAL NOT NULL DEFAULT 0,
            note TEXT,
            status TEXT NOT NULL DEFAULT 'confirmed',
            movement_id INTEGER REFERENCES inventory_movements(id),
            created_by INTEGER REFERENCES users(id),
            created_by_name TEXT,
            created_at TEXT NOT NULL,
            voided_by INTEGER REFERENCES users(id),
            voided_at TEXT,
            void_reason TEXT
        )",

        "CREATE TABLE IF NOT EXISTS audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            logged_at TEXT NOT NULL,
            user_id INTEGER,
            user_name TEXT,
            action TEXT NOT NULL,
            entity TEXT,
            entity_id INTEGER,
            description TEXT,
            meta TEXT,
            ip TEXT
        )",

        "CREATE TABLE IF NOT EXISTS import_batches (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            file_name TEXT,
            rows_total INTEGER NOT NULL DEFAULT 0,
            rows_ok INTEGER NOT NULL DEFAULT 0,
            rows_failed INTEGER NOT NULL DEFAULT 0,
            total_value REAL NOT NULL DEFAULT 0,
            created_by INTEGER REFERENCES users(id),
            created_by_name TEXT,
            note TEXT
        )",

        // Index
        "CREATE INDEX IF NOT EXISTS idx_mov_ing_date ON inventory_movements (ingredient_id, moved_at)",
        "CREATE INDEX IF NOT EXISTS idx_mov_date ON inventory_movements (moved_at)",
        "CREATE INDEX IF NOT EXISTS idx_mov_type ON inventory_movements (movement_type)",
        "CREATE INDEX IF NOT EXISTS idx_sales_date ON sales (sold_at)",
        "CREATE INDEX IF NOT EXISTS idx_sales_channel ON sales (channel)",
        "CREATE INDEX IF NOT EXISTS idx_sale_items_sale ON sale_items (sale_id)",
        "CREATE INDEX IF NOT EXISTS idx_sale_items_menu ON sale_items (menu_id)",
        "CREATE INDEX IF NOT EXISTS idx_purchase_items_p ON purchase_items (purchase_id)",
        "CREATE INDEX IF NOT EXISTS idx_purchase_items_ing ON purchase_items (ingredient_id)",
        "CREATE INDEX IF NOT EXISTS idx_waste_date ON waste_records (wasted_at)",
        "CREATE INDEX IF NOT EXISTS idx_recipe_items_recipe ON recipe_items (recipe_id)",
        "CREATE INDEX IF NOT EXISTS idx_opname_items_op ON stock_opname_items (opname_id)",
        "CREATE INDEX IF NOT EXISTS idx_audit_date ON audit_logs (logged_at)",
    ];

    foreach ($ddl as $sql) {
        $pdo->exec($sql);
    }

    seed_roles($pdo);
}

/** Isi/perbarui daftar role (idempotent, lintas dialek) */
function seed_roles(PDO $pdo): void
{
    $stmt = $pdo->prepare(sql_upsert(sql_table('roles'), ['code', 'name', 'permissions'], ['name', 'permissions']));
    foreach (ROLES as $code => $name) {
        $stmt->execute([':code' => $code, ':name' => $name, ':permissions' => implode(',', PERMISSIONS[$code] ?? [])]);
    }
}
