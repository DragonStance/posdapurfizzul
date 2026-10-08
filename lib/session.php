<?php
declare(strict_types=1);

/**
 * Manajemen sesi pengguna.
 *
 * Masalah yang diselesaikan berkas ini:
 *  1. Sesi berbasis berkas pada hosting bersama bisa hilang di antara dua
 *     permintaan (penyimpanan sesi tidak dapat ditulis, GC terlalu agresif,
 *     atau beberapa pekerja PHP tanpa penyimpanan bersama). Akibatnya token
 *     keamanan (CSRF) tidak cocok dan pengguna melihat "sesi kedaluwarsa".
 *     Solusinya: opsi penyimpanan sesi di tabel MySQL (`sessions`) yang dibuat
 *     otomatis oleh aplikasi — pengguna TIDAK perlu membuatnya manual.
 *  2. Halaman HTML harus selalu diambil segar dari server (bukan dari cache
 *     halaman hosting), supaya token pada form tidak pernah basi.
 */

const CSRF_COOKIE = 'simop_csrf';

/** Driver sesi yang aktif: 'file' (default) atau 'mysql' */
function session_driver(): string
{
    $d = defined('SESSION_DRIVER') ? (string)SESSION_DRIVER : 'file';
    if ($d === 'mysql' && db_driver() !== 'mysql') {
        return 'file'; // sesi MySQL hanya berlaku saat basis data MySQL
    }
    return $d === 'mysql' ? 'mysql' : 'file';
}

/** Nama tabel sesi (mengikuti prefix MySQL bila ada) */
function session_table(): string
{
    return sql_table(defined('SESSION_TABLE') ? (string)SESSION_TABLE : 'sessions');
}

/** Umur sesi (detik) */
function session_lifetime(): int
{
    $l = defined('SESSION_LIFETIME') ? (int)SESSION_LIFETIME : 0;
    if ($l > 0) {
        return $l;
    }
    $ini = (int)ini_get('session.gc_maxlifetime');
    return $ini >= 300 ? $ini : 7200;
}

/**
 * Handler sesi berbasis tabel MySQL.
 * Data sesi disimpan apa adanya (aman karena memakai prepared statement).
 */
final class MysqlSessionHandler implements SessionHandlerInterface
{
    private string $table;
    private int $lifetime;

    public function __construct(string $table, int $lifetime)
    {
        $this->table = $table;
        $this->lifetime = max(300, $lifetime);
    }

    /** Pastikan tabel sesi tersedia (dibuat otomatis bila belum ada) */
    public static function ensure_table(PDO $pdo, string $table): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS $table (
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
            KEY `idx_session_expiry` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    #[\ReturnTypeWillChange]
    public function open($savePath, $sessionName): bool
    {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function close(): bool
    {
        return true;
    }

    #[\ReturnTypeWillChange]
    public function read($id): string
    {
        try {
            $stmt = db()->prepare("SELECT `data` FROM {$this->table} WHERE `id` = ? AND `expires_at` > NOW() LIMIT 1");
            $stmt->execute([(string)$id]);
            $data = $stmt->fetchColumn();
            return $data === false ? '' : (string)$data;
        } catch (Throwable $e) {
            return '';
        }
    }

    #[\ReturnTypeWillChange]
    public function write($id, $data): bool
    {
        try {
            $user = current_user();
            $expires = date('Y-m-d H:i:s', time() + $this->lifetime);
            $stmt = db()->prepare("INSERT INTO {$this->table}
                    (`id`, `data`, `user_id`, `user_name`, `ip`, `user_agent`, `created_at`, `updated_at`, `expires_at`)
                    VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW(), ?)
                    ON DUPLICATE KEY UPDATE `data` = VALUES(`data`), `user_id` = VALUES(`user_id`),
                        `user_name` = VALUES(`user_name`), `ip` = VALUES(`ip`),
                        `user_agent` = VALUES(`user_agent`), `updated_at` = NOW(), `expires_at` = VALUES(`expires_at`)");
            $stmt->execute([
                (string)$id,
                (string)$data,
                $user['id'] ?? null,
                $user['name'] ?? null,
                substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
                substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                $expires,
            ]);
            return true;
        } catch (Throwable $e) {
            error_log('[SIMOP] gagal menyimpan sesi ke MySQL: ' . $e->getMessage());
            return false;
        }
    }

    #[\ReturnTypeWillChange]
    public function destroy($id): bool
    {
        try {
            $stmt = db()->prepare("DELETE FROM {$this->table} WHERE `id` = ?");
            $stmt->execute([(string)$id]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    #[\ReturnTypeWillChange]
    public function gc($max_lifetime): int|false
    {
        try {
            $stmt = db()->prepare("DELETE FROM {$this->table} WHERE `expires_at` < NOW()");
            $stmt->execute();
            return $stmt->rowCount();
        } catch (Throwable $e) {
            return false;
        }
    }
}

/**
 * Siapkan & mulai sesi aplikasi.
 * Dipanggil sekali dari lib/bootstrap.php sebelum ada keluaran apa pun.
 */
function session_bootstrap(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '0'); // jangan tolak ID dari cookie pengguna sendiri
    ini_set('session.gc_maxlifetime', (string)session_lifetime());
    ini_set('session.cookie_lifetime', '0');

    session_name('simop_fizzul');

    if (session_driver() === 'mysql') {
        try {
            $pdo = db();
            MysqlSessionHandler::ensure_table($pdo, session_table());
            session_set_save_handler(new MysqlSessionHandler(session_table(), session_lifetime()), true);
        } catch (Throwable $e) {
            error_log('[SIMOP] sesi MySQL tidak dapat disiapkan, beralih ke berkas: ' . $e->getMessage());
        }
    } else {
        // Pastikan folder penyimpanan sesi ada & dapat ditulis (umum gagal di hosting bersama)
        $path = (string)ini_get('session.save_path');
        if ($path === '' || !is_dir($path) || !is_writable($path)) {
            $fallback = DATA_DIR . '/sessions';
            if (!is_dir($fallback)) {
                @mkdir($fallback, 0775, true);
            }
            if (is_dir($fallback) && is_writable($fallback)) {
                session_save_path($fallback);
            }
        }
    }

    session_start();

    // Diagnostik ringan: apakah sesi benar-benar bertahan antar permintaan?
    $_SESSION['_seen'] = ($_SESSION['_seen'] ?? 0) + 1;
    $_SESSION['_last_seen_at'] = now_str();
}

/**
 * Header anti-cache untuk seluruh halaman HTML.
 * Mencegah cache halaman hosting menyajikan form dengan token keamanan basi
 * (salah satu penyebab "sesi kedaluwarsa" saat login).
 */
function send_no_cache_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Vary: Cookie');
    header_remove('Last-Modified');
}

/** Ringkasan status sesi untuk halaman Pengaturan */
function session_status_info(): array
{
    $path = (string)ini_get('session.save_path');
    return [
        'driver' => session_driver(),
        'name' => (string)session_name(),
        'id' => (string)session_id(),
        'save_path' => session_driver() === 'mysql' ? session_table() . ' (tabel MySQL)' : ($path !== '' ? $path : '(bawaan PHP)'),
        'save_path_writable' => session_driver() === 'mysql' ? true : ($path === '' || is_writable($path)),
        'persisted' => ((int)($_SESSION['_seen'] ?? 0)) > 1,
        'requests' => (int)($_SESSION['_seen'] ?? 0),
        'last_seen' => (string)($_SESSION['_last_seen_at'] ?? '-'),
        'lifetime' => session_lifetime(),
        'secure_cookie' => (bool)ini_get('session.cookie_secure'),
        'httponly' => (bool)ini_get('session.cookie_httponly'),
        'samesite' => (string)(ini_get('session.cookie_samesite') ?: 'Lax'),
    ];
}
