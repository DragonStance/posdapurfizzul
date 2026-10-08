<?php
declare(strict_types=1);

/**
 * Generator berkas SQL untuk MySQL/MariaDB.
 *
 * Berkas hasilnya berisi:
 *   1. DDL tabel (lib/schema_mysql.php) — 19 tabel InnoDB utf8mb4 + FK + index
 *   2. Data master/akun awal (role, pengaturan, akun pengguna demo)
 *   3. OPSIONAL: seluruh data demo (bahan, menu, resep, penjualan 30 hari,
 *      pembelian, waste, stock opname, pergerakan stok) yang dibuat dengan
 *      mesin seed aplikasi yang sama, lalu dikonversi ke pernyataan INSERT MySQL.
 *
 * Catatan penting: berkas ini tidak pernah berisi data transaksi nyata milik
 * pengguna — data demo dibangkitkan ulang dari nol pada basis data sementara.
 */

/** Nilai literal MySQL dari nilai PHP */
function mysql_literal($v, bool $isDateColumn = false): string
{
    if ($v === null) {
        return 'NULL';
    }
    if (is_bool($v)) {
        return $v ? '1' : '0';
    }
    if (is_int($v) || is_float($v)) {
        if (is_float($v)) {
            $s = rtrim(rtrim(sprintf('%.6F', $v), '0'), '.');
            return $s === '' || $s === '-' ? '0' : $s;
        }
        return (string)$v;
    }
    $s = (string)$v;
    if ($s === '') {
        // kolom tanggal/waktu tidak menerima string kosong pada MySQL mode strict
        return $isDateColumn ? 'NULL' : "''";
    }
    return "'" . str_replace(
        ['\\', "'", "\n", "\r", "\x1a", '"'],
        ['\\\\', "\\'", '\\n', '\\r', '\\Z', '\\"'],
        $s
    ) . "'";
}

/** Kolom yang bertipe tanggal/waktu (string kosong -> NULL) */
function mysql_date_columns(): array
{
    return ['expiry_date', 'opname_date', 'sold_at', 'purchased_at', 'moved_at', 'wasted_at',
            'created_at', 'updated_at', 'last_login', 'submitted_at', 'approved_at', 'voided_at', 'logged_at'];
}

/**
 * Konversi seluruh baris satu tabel menjadi pernyataan INSERT (digabung per 50 baris).
 */
function mysql_insert_statements(PDO $src, string $table, string $prefix, array $columns): array
{
    $rows = $src->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return [];
    }
    $dateCols = mysql_date_columns();
    $cols = array_keys($rows[0]);
    $target = '`' . $prefix . $table . '`';
    $colList = implode(', ', array_map(fn($c) => '`' . $c . '`', $cols));
    $out = [];
    foreach (array_chunk($rows, 50) as $chunk) {
        $values = [];
        foreach ($chunk as $row) {
            $vals = [];
            foreach ($cols as $c) {
                $vals[] = mysql_literal($row[$c] ?? null, in_array($c, $dateCols, true) || str_ends_with($c, '_date'));
            }
            $values[] = '(' . implode(', ', $vals) . ')';
        }
        $out[] = "INSERT INTO $target ($colList) VALUES\n  " . implode(",\n  ", $values) . ';';
    }
    return $out;
}

/** Urutan tabel yang aman terhadap foreign key */
function mysql_table_order(): array
{
    return ['settings', 'roles', 'users', 'ingredient_categories', 'suppliers', 'ingredients',
            'menus', 'recipes', 'recipe_items', 'sales', 'sale_items', 'purchases', 'purchase_items',
            'inventory_movements', 'stock_opnames', 'stock_opname_items', 'waste_records',
            'audit_logs', 'import_batches', 'sessions'];
}

/**
 * Bangkitkan data demo pada basis data SQLite sementara, lalu kembalikan PDO-nya.
 * Dialek dipaksa ke SQLite selama proses ini supaya fungsi seed yang sama dengan
 * aplikasi dapat dipakai ulang tanpa perubahan.
 */
function mysql_demo_source(): PDO
{
    $file = sys_get_temp_dir() . '/fizzul_demo_' . bin2hex(random_bytes(4)) . '.sqlite';
    $GLOBALS['SIMOP_DIALECT_OVERRIDE'] = 'sqlite';
    $tmp = new PDO('sqlite:' . $file, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $tmp->exec('PRAGMA foreign_keys = ON');
    $tmp->exec('BEGIN IMMEDIATE');
    migrate_schema($tmp);
    seed_demo_data($tmp);
    $tmp->exec('COMMIT');
    $GLOBALS['SIMOP_DEMO_TMP_FILE'] = $file;
    return $tmp;
}

/** Hapus basis data sementara setelah selesai */
function mysql_demo_cleanup(): void
{
    $file = $GLOBALS['SIMOP_DEMO_TMP_FILE'] ?? '';
    unset($GLOBALS['SIMOP_DIALECT_OVERRIDE']);
    foreach ([$file, $file . '-wal', $file . '-shm'] as $f) {
        if ($f !== '' && file_exists($f)) {
            @unlink($f);
        }
    }
}

/**
 * Hasilkan berkas .sql lengkap untuk MySQL.
 *
 * @param array $opt [
 *   'prefix'      => prefix tabel (mis. 'fizzul_'),
 *   'database'    => nama database untuk CREATE DATABASE ('' = tidak dibuat),
 *   'drop_tables' => true untuk menambahkan DROP TABLE IF EXISTS,
 *   'with_demo'   => true untuk menyertakan seluruh data demo,
 *   'with_users'  => true untuk menyertakan 3 akun pengguna demo,
 * ]
 */
function mysql_dump(array $opt = []): string
{
    $prefix = (string)($opt['prefix'] ?? '');
    $database = (string)($opt['database'] ?? '');
    $drop = (bool)($opt['drop_tables'] ?? false);
    $withDemo = (bool)($opt['with_demo'] ?? false);
    $withUsers = (bool)($opt['with_users'] ?? true);

    $waktu = date('d-m-Y H:i');
    $out = [];
    $out[] = "-- =====================================================================";
    $out[] = "-- SIMOP Dapur Fizzul — Skema database MySQL/MariaDB";
    $out[] = "-- Sistem Informasi Manajemen Operasional Dapur Fizzul (UMKM rumah makan)";
    $out[] = "-- Dibuat: $waktu | Versi aplikasi " . APP_VERSION . " | Skema " . SCHEMA_VERSION;
    $out[] = "-- Target : MySQL 5.7+ / MariaDB 10.2+ (InnoDB, utf8mb4)";
    $out[] = "-- Prefix tabel: " . ($prefix === '' ? '(tanpa prefix)' : $prefix);
    $out[] = "-- Isi data    : struktur" . ($withUsers ? ' + akun pengguna awal' : '') . ($withDemo ? ' + DATA DEMO lengkap' : '');
    $out[] = "--";
    $out[] = "-- CARA IMPOR (cPanel / phpMyAdmin):";
    $out[] = "--   1. Buat database baru, mis. \"dapur_fizzul\" (utf8mb4).";
    $out[] = "--   2. Buka phpMyAdmin -> pilih database -> tab Import -> pilih berkas ini.";
    $out[] = "--   3. Salin kode aplikasi ke hosting, lalu buat berkas config.local.php:";
    $out[] = "--      <?php";
    $out[] = "--      define('DB_DRIVER', 'mysql');";
    $out[] = "--      define('MYSQL_HOST', 'localhost');";
    $out[] = "--      define('MYSQL_DB',   'dapur_fizzul');";
    $out[] = "--      define('MYSQL_USER', 'user_database');";
    $out[] = "--      define('MYSQL_PASS', 'sandi_database');";
    if ($prefix !== '') {
        $out[] = "--      define('MYSQL_PREFIX', '$prefix');   // wajib sama dengan prefix berkas ini";
    } else {
        $out[] = "--      define('MYSQL_PREFIX', '');";
    }
    $out[] = "--";
    $out[] = "-- Catatan: aplikasi akan memeriksa skema ini sekali saja pada akses pertama.";
    $out[] = "-- =====================================================================";
    $out[] = '';
    $out[] = 'SET NAMES utf8mb4;';
    $out[] = 'SET FOREIGN_KEY_CHECKS = 0;';
    $out[] = 'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";';
    $out[] = '';

    if ($database !== '') {
        $out[] = "CREATE DATABASE IF NOT EXISTS `$database` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;";
        $out[] = "USE `$database`;";
        $out[] = '';
    }

    if ($drop) {
        $out[] = '-- Menghapus tabel lama bila ada (untuk impor ulang dari nol)';
        foreach (array_reverse(mysql_table_order()) as $table) {
            $out[] = "DROP TABLE IF EXISTS `$prefix$table`;";
        }
        $out[] = '';
    }

    $out[] = '-- ---------------------------------------------------------------------';
    $out[] = '-- 1. STRUKTUR TABEL';
    $out[] = '-- ---------------------------------------------------------------------';
    foreach (mysql_ddl($prefix) as $ddl) {
        $out[] = $ddl . ';';
        $out[] = '';
    }

    // ------- data awal -------
    $out[] = '-- ---------------------------------------------------------------------';
    $out[] = '-- 2. DATA AWAL (role & pengaturan sistem)';
    $out[] = '-- ---------------------------------------------------------------------';
    $roleRows = [];
    foreach (ROLES as $code => $name) {
        $roleRows[] = '(' . mysql_literal($code) . ', ' . mysql_literal($name) . ', '
            . mysql_literal(implode(',', PERMISSIONS[$code] ?? [])) . ')';
    }
    $out[] = "INSERT INTO `{$prefix}roles` (`code`, `name`, `permissions`) VALUES\n  " . implode(",\n  ", $roleRows) . ';';
    $out[] = '';
    // seeded = 1 => aplikasi tidak mengisi data demo sendiri
    $settings = [
        ['schema_version', SCHEMA_VERSION],
        ['seeded', $withDemo ? '1' : '1'],
        ['business_name', 'Dapur Fizzul'],
        ['seeded_at', date('Y-m-d H:i:s')],
        ['demo_mode', $withDemo ? '1' : '0'],
        ['import_source', 'Berlisensi MySQL dari aplikasi SIMOP Dapur Fizzul'],
    ];
    $setRows = [];
    foreach ($settings as [$k, $v]) {
        $setRows[] = '(' . mysql_literal($k) . ', ' . mysql_literal($v) . ')';
    }
    $out[] = "INSERT INTO `{$prefix}settings` (`k`, `v`) VALUES\n  " . implode(",\n  ", $setRows) . ';';
    $out[] = '';

    // ------- akun pengguna demo -------
    if ($withUsers) {
        $out[] = '-- ---------------------------------------------------------------------';
        $out[] = '-- 3. AKUN PENGGUNA AWAL (SEGERA GANTI SANDI setelah login pertama)';
        $out[] = '--    owner/fizzul123 (Owner-Admin) · manager/manager123 (Manager)';
        $out[] = '--    kasir/kasir123 (Kasir) · dapur/dapur123 (Dapur)';
        $out[] = '-- ---------------------------------------------------------------------';
        $akun = [
            ['owner', 'Fizzul Rahman', 'owner@dapurfizzul.id', '0811-2345-678', 'owner', 'fizzul123'],
            ['manager', 'Nurhayati Sialana', 'manager@dapurfizzul.id', '0812-9876-543', 'manager', 'manager123'],
            ['kasir', 'Sari Latuconsina', 'kasir@dapurfizzul.id', '0812-3456-789', 'kasir', 'kasir123'],
            ['dapur', 'Bakri Tuasikal', 'dapur@dapurfizzul.id', '0813-4567-890', 'dapur', 'dapur123'],
        ];
        $userRows = [];
        foreach ($akun as [$un, $nama, $email, $phone, $roleCode, $pwd]) {
            $userRows[] = sprintf('(%s, %s, %s, %s, (SELECT `id` FROM `%sroles` WHERE `code` = %s), %s, 1, NULL, %s)',
                mysql_literal($un), mysql_literal($nama), mysql_literal($email), mysql_literal($phone),
                $prefix, mysql_literal($roleCode),
                mysql_literal(password_hash($pwd, PASSWORD_DEFAULT)), mysql_literal(now_str()));
        }
        $userCols = '`username`, `name`, `email`, `phone`, `role_id`, `password_hash`, `is_active`, `last_login`, `created_at`';
        $out[] = "INSERT INTO `{$prefix}users` ($userCols) VALUES\n  " . implode(",\n  ", $userRows) . ';';
        $out[] = '';
    }

    // ------- data demo lengkap -------
    if ($withDemo) {
        $out[] = '-- ---------------------------------------------------------------------';
        $out[] = '-- 4. DATA DEMO (dibangkitkan ulang oleh mesin seed aplikasi)';
        $out[] = '--    Bahan baku, menu khas Maluku, resep/BOM, supplier, penjualan 30 hari,';
        $out[] = '--    pembelian, waste, stock opname, dan pergerakan stok.';
        $out[] = '--    Hapus bagian ini bila ingin mulai dari database kosong.';
        $out[] = '-- ---------------------------------------------------------------------';
        $src = mysql_demo_source();
        // roles & users sudah ditulis di atas (id harus sama) => lewati, tulis sisanya
        foreach (mysql_table_order() as $table) {
            // tabel sesi hanya menyimpan sesi login berjalan -> tidak perlu disalin
            if (in_array($table, ['settings', 'roles', 'users', 'sessions'], true)) {
                continue;
            }
            $stmts = mysql_insert_statements($src, $table, $prefix, []);
            if (!$stmts) {
                continue;
            }
            $out[] = "-- Tabel: $prefix$table";
            foreach ($stmts as $st) {
                $out[] = $st;
            }
            $out[] = '';
        }
        mysql_demo_cleanup();
    }

    $out[] = 'SET FOREIGN_KEY_CHECKS = 1;';
    $out[] = '';
    $out[] = '-- Selesai. Pastikan config.local.php menunjuk ke database ini,';
    $out[] = '-- lalu buka aplikasi dan login untuk mulai menggunakan sistem.';

    return implode("\n", $out) . "\n";
}

/** Ringkasan untuk ditampilkan pada halaman unduh */
function mysql_dump_preview(): array
{
    $ddl = mysql_ddl('');
    return [
        'tabel' => count($ddl),
        'fk' => substr_count(implode("\n", $ddl), 'CONSTRAINT'),
        'index' => substr_count(implode("\n", $ddl), 'KEY `idx_'),
        'pernyataan' => substr_count(implode(";\n", $ddl), ';'),
    ];
}
