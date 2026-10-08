<?php
declare(strict_types=1);

/**
 * Lapisan dialek SQL: aplikasi ini dapat berjalan di SQLite (default) maupun
 * MySQL/MariaDB. Semua perbedaan dialek dikumpulkan di file ini agar kode
 * halaman/analitik tetap satu versi.
 */

/** Daftar tabel aplikasi (dipakai untuk penulisan ulang nama tabel saat prefix aktif) */
const APP_TABLES = [
    'settings', 'roles', 'users', 'ingredient_categories', 'suppliers', 'ingredients',
    'menus', 'recipes', 'recipe_items', 'sales', 'sale_items', 'purchases', 'purchase_items',
    'inventory_movements', 'stock_opnames', 'stock_opname_items', 'waste_records',
    'audit_logs', 'import_batches',
];

/**
 * Siapkan SQL agar sesuai dialek yang aktif.
 * - MySQL dengan prefix tabel (mis. "fizzul_") akan menulis ulang nama tabel.
 * - SQLite tidak diubah sama sekali.
 */
function sql_prepare(string $sql): string
{
    if (db_driver() !== 'mysql') {
        return $sql;
    }
    if (db_prefix() !== '') {
        $pattern = '/\b(' . implode('|', APP_TABLES) . ')\b/';
        $sql = preg_replace_callback($pattern, fn($m) => db_prefix() . $m[1], $sql);
    }
    return $sql;
}

/** Nama tabel yang siap dipakai di dalam SQL (dialek aktif) */
function sql_table(string $name): string
{
    return db_driver() === 'mysql' ? (db_prefix() . $name) : $name;
}

/* ------------------------- Ekspresi tanggal ------------------------- */

/** Bagian bulan sebagai teks: 2026-09 (dipakai untuk tren bulanan) */
function sql_month(string $col): string
{
    return db_driver() === 'mysql'
        ? "DATE_FORMAT($col, '%Y-%m')"
        : "strftime(\"%Y-%m\", $col)";
}

/** Minggu (dipakai untuk tren mingguan) */
function sql_week(string $col): string
{
    return db_driver() === 'mysql'
        ? "DATE_FORMAT($col, '%x-W%v')"
        : "strftime(\"%Y-W%W\", $col)";
}

/** Tanggal hari ini (waktu lokal aplikasi) */
function sql_today(): string
{
    return db_driver() === 'mysql' ? 'CURDATE()' : "date('now','localtime')";
}

/** Selisih hari antara sebuah kolom tanggal dan hari ini */
function sql_days_from_today(string $dateCol): string
{
    return db_driver() === 'mysql'
        ? "DATEDIFF($dateCol, CURDATE())"
        : "CAST(julianday($dateCol) - julianday(date('now','localtime')) AS INTEGER)";
}

/* ------------------------- Upsert ------------------------- */

/**
 * Bangun pernyataan upsert (INSERT ... ON CONFLICT/DUPLICATE KEY) untuk
 * tabel dengan kunci unik. Hanya kolom pada $update yang diperbarui.
 */
function sql_upsert(string $table, array $insertCols, array $updateCols): string
{
    $cols = implode(', ', $insertCols);
    $ph = implode(', ', array_map(fn($c) => ':' . $c, $insertCols));
    if (db_driver() === 'mysql') {
        $sets = implode(', ', array_map(fn($c) => "$c = VALUES($c)", $updateCols));
        return "INSERT INTO $table ($cols) VALUES ($ph) ON DUPLICATE KEY UPDATE $sets";
    }
    $conflict = $insertCols[0];
    $sets = implode(', ', array_map(fn($c) => "$c = excluded.$c", $updateCols));
    return "INSERT INTO $table ($cols) VALUES ($ph) ON CONFLICT($conflict) DO UPDATE SET $sets";
}

/** GROUP_CONCAT lintas dialek (dengan pemisah khusus) */
function sql_group_concat(string $expr, string $separator): string
{
    $sep = str_replace("'", "''", $separator);
    return db_driver() === 'mysql'
        ? "GROUP_CONCAT($expr SEPARATOR '$sep')"
        : "GROUP_CONCAT($expr, '$sep')";
}

/* ------------------------- Klausa khusus ------------------------- */

/**
 * Klausa "berkedaluwarsa dalam N hari ke depan" untuk tabel purchase_items.
 * Mengembalikan [sql_where, parameter_tambahan].
 */
function sql_expiry_window(string $dateCol, int $days): array
{
    if (db_driver() === 'mysql') {
        return ["$dateCol IS NOT NULL AND $dateCol <= DATE_ADD(CURDATE(), INTERVAL $days DAY)", []];
    }
    return ["$dateCol IS NOT NULL AND julianday($dateCol) <= julianday(date('now','localtime')) + ?", [$days]];
}
