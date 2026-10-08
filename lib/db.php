<?php
declare(strict_types=1);

/**
 * Koneksi database + bootstrap skema/seed.
 *
 * SQLite (default): WAL + busy_timeout, seluruh bootstrap dibungkus SATU
 * transaksi (BEGIN IMMEDIATE) agar tidak terjadi fsync per statement.
 * MySQL/MariaDB: InnoDB, utf8mb4, sql_mode disetel agar hasil agregasi sama
 * dengan SQLite (ONLY_FULL_GROUP_BY dimatikan).
 */

/**
 * Driver yang sedang aktif: 'sqlite' atau 'mysql'.
 * SIMOP_DIALECT_OVERRIDE dipakai saat membangkitkan data demo untuk ekspor
 * MySQL (mesin seed aplikasi berjalan di atas SQLite sementara).
 */
function db_driver(): string
{
    $override = $GLOBALS['SIMOP_DIALECT_OVERRIDE'] ?? null;
    if ($override === 'sqlite' || $override === 'mysql') {
        return $override;
    }
    return DB_DRIVER === 'mysql' ? 'mysql' : 'sqlite';
}

/** Prefix tabel (hanya berlaku untuk MySQL) */
function db_prefix(): string
{
    return db_driver() === 'mysql' ? (string)MYSQL_PREFIX : '';
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    if (db_driver() === 'mysql') {
        $dsn = 'mysql:host=' . MYSQL_HOST . ';port=' . MYSQL_PORT
            . (MYSQL_DB !== '' ? ';dbname=' . MYSQL_DB : '') . ';charset=utf8mb4';
        $opts[PDO::ATTR_EMULATE_PREPARES] = false;
        $pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASS, $opts);
        // Samakan perilaku agregasi & zona waktu dengan versi SQLite
        try { $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'"); } catch (Throwable $e) {}
        try { $pdo->exec("SET SESSION time_zone = '+07:00'"); } catch (Throwable $e) {}
    } else {
        if (!is_dir(DATA_DIR)) {
            @mkdir(DATA_DIR, 0775, true);
        }
        $pdo = new PDO('sqlite:' . DB_FILE, null, null, $opts);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA synchronous = NORMAL');
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    ensure_ready($pdo);
    return $pdo;
}

/** Nama tabel untuk pesan galat/log */
function db_label(): string
{
    return db_driver() === 'mysql'
        ? 'MySQL ' . MYSQL_HOST . '/' . MYSQL_DB . (db_prefix() !== '' ? ' (prefix ' . db_prefix() . ')' : '')
        : 'SQLite ' . DB_FILE;
}

/**
 * Memastikan skema & data demo siap. Cepat pada request normal (1 SELECT),
 * dan hanya menjalankan skema+seed sekali saat aplikasi pertama kali dijalankan.
 */
function ensure_ready(PDO $pdo): void
{
    try {
        $stmt = $pdo->prepare(sql_prepare("SELECT COUNT(*) FROM settings
                               WHERE (k = 'schema_version' AND v = :v) OR (k = 'seeded' AND v = '1')"));
        $stmt->execute([':v' => SCHEMA_VERSION]);
        if ((int)$stmt->fetchColumn() === 2) {
            return; // sudah siap
        }
    } catch (Throwable $e) {
        // tabel settings belum ada => lanjut bootstrap
    }

    $pdo->exec(db_driver() === 'mysql' ? 'START TRANSACTION' : 'BEGIN IMMEDIATE');
    try {
        migrate_schema($pdo);
        if (setting_get($pdo, 'seeded') !== '1') {
            seed_demo_data($pdo);
            setting_set($pdo, 'seeded', '1');
        }
        setting_set($pdo, 'schema_version', SCHEMA_VERSION);
        $pdo->exec('COMMIT');
    } catch (Throwable $e) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {}
        throw $e;
    }
}

/** Ambil setting (tanpa transaksi sendiri) */
function setting_get(PDO $pdo, string $key, ?string $default = null): ?string
{
    try {
        $stmt = $pdo->prepare(sql_prepare('SELECT v FROM settings WHERE k = ?'));
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val === false ? $default : (string)$val;
    } catch (Throwable $e) {
        return $default;
    }
}

function setting_set(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare(sql_upsert(sql_table('settings'), ['k', 'v'], ['v']));
    $stmt->execute([':k' => $key, ':v' => $value]);
}

/** Jalankan sekumpulan statement dalam satu transaksi tulis */
function tx(PDO $pdo, callable $fn)
{
    $pdo->exec(db_driver() === 'mysql' ? 'START TRANSACTION' : 'BEGIN IMMEDIATE');
    try {
        $result = $fn($pdo);
        $pdo->exec('COMMIT');
        return $result;
    } catch (Throwable $e) {
        $pdo->exec('ROLLBACK');
        throw $e;
    }
}

/** Helper query: banyak baris */
function q(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare(sql_prepare($sql));
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Helper query: satu baris */
function q1(PDO $pdo, string $sql, array $params = []): ?array
{
    $stmt = $pdo->prepare(sql_prepare($sql));
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** Helper query: satu nilai */
function qv(PDO $pdo, string $sql, array $params = [], $default = 0)
{
    $stmt = $pdo->prepare(sql_prepare($sql));
    $stmt->execute($params);
    $val = $stmt->fetchColumn();
    return $val === false || $val === null ? $default : $val;
}

/** Insert generik, mengembalikan id */
function insert_row(PDO $pdo, string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = 'INSERT INTO ' . sql_table($table) . ' (' . implode(', ', $cols) . ') VALUES ('
        . implode(', ', array_map(fn($c) => ':' . $c, $cols)) . ')';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($data);
    return (int)$pdo->lastInsertId();
}

/** Update generik berdasarkan kolom id */
function update_row(PDO $pdo, string $table, int $id, array $data): void
{
    $sets = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($data)));
    $data['id'] = $id;
    $stmt = $pdo->prepare("UPDATE " . sql_table($table) . " SET $sets WHERE id = :id");
    $stmt->execute($data);
}
