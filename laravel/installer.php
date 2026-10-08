<?php
declare(strict_types=1);

/**
 * ============================================================================
 *  Installer integrasi SIMOP Dapur Fizzul ke dalam proyek Laravel
 * ============================================================================
 *
 *  Cara pakai (dari baris perintah, di root proyek Laravel):
 *
 *      php dapur-fizzul/laravel/installer.php                 # deteksi otomatis
 *      php dapur-fizzul/laravel/installer.php --prefix=fizzul_ --force
 *      php dapur-fizzul/laravel/installer.php /path/ke/laravel
 *
 *  Yang dilakukan installer (idempotent — aman dijalankan berulang):
 *    1. Menulis config.local.php untuk aplikasi (mengikuti pengaturan .env Laravel)
 *    2. Menulis app/Http/Controllers/DapurFizzulController.php
 *    3. Menulis app/Providers/DapurFizzulServiceProvider.php
 *    4. Mendaftarkan provider tersebut di bootstrap/providers.php (Laravel 11+)
 *       atau config/app.php (Laravel <= 10)
 *    5. Menyalin aset (CSS/JS/Chart.js) ke public/dapur-fizzul/assets
 *    6. Menampilkan langkah verifikasi
 *
 *  Aplikasi TIDAK menyentuh basis data Laravel: bila Laravel memakai MySQL,
 *  tabel aplikasi otomatis diberi prefix (bawaan: fizzul_) agar tidak
 *  bertabrakan dengan tabel milik Laravel (users, sessions, jobs, cache).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Installer hanya dapat dijalankan dari baris perintah (CLI).');
}

$APP_DIR = dirname(__DIR__);          // folder aplikasi (…/dapur-fizzul)

/* ------------------------------ argumen ------------------------------ */
$args = array_slice($argv, 1);
$laravelRoot = null;
$prefixOpt = null;
$force = false;
$skipProviders = false;
foreach ($args as $a) {
    if (str_starts_with($a, '--prefix=')) {
        $prefixOpt = preg_replace('/[^a-zA-Z0-9_]/', '', substr($a, 9));
    } elseif ($a === '--force') {
        $force = true;
    } elseif ($a === '--tanpa-provider' || $a === '--no-provider') {
        $skipProviders = true;
    } elseif (!str_starts_with($a, '--')) {
        $laravelRoot = rtrim($a, '/');
    }
}
$laravelRoot = $laravelRoot ?: dirname($APP_DIR);

function info(string $s): void { echo "  $s\n"; }
function ok(string $s): void { echo "  \033[32m✔\033[0m $s\n"; }
function warn(string $s): void { echo "  \033[33m!\033[0m $s\n"; }
function fail(string $s): void { echo "  \033[31m✘\033[0m $s\n"; }

echo "\n=== Integrasi SIMOP Dapur Fizzul ke Laravel ===\n";
info('Folder aplikasi : ' . $APP_DIR);
info('Root Laravel    : ' . $laravelRoot);

/* ------------------------- validasi root Laravel ------------------------- */
$mustHave = ['artisan', 'composer.json', 'bootstrap/app.php'];
$missing = array_values(array_filter($mustHave, fn($f) => !file_exists($laravelRoot . '/' . $f)));
if ($missing) {
    fail('Folder tujuan bukan proyek Laravel (tidak ditemukan: ' . implode(', ', $missing) . ')');
    info('Letakkan folder dapur-fizzul/ di dalam root proyek Laravel, lalu jalankan lagi.');
    info('Contoh susunan: laravel-app/{app,bootstrap,public,routes,dapur-fizzul}/');
    exit(1);
}
ok('Struktur proyek Laravel terdeteksi');

/* ------------------------- baca konfigurasi .env ------------------------- */
function env_value(string $key, string $default = ''): string
{
    global $laravelRoot;
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $path = $laravelRoot . '/.env';
        if (is_file($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) continue;
                if (!str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $v = trim($v);
                if (strlen($v) > 1 && $v[0] === '"' && str_ends_with($v, '"')) $v = substr($v, 1, -1);
                if ($v !== '' && !str_starts_with($v, '#')) $cache[trim($k)] = $v;
            }
        }
    }
    return $cache[$key] ?? $default;
}

$connection = strtolower(env_value('DB_CONNECTION', 'sqlite'));
$dbHost = env_value('DB_HOST', '127.0.0.1');
$dbPort = env_value('DB_PORT', '3306');
$dbName = env_value('DB_DATABASE', '');
$dbUser = env_value('DB_USERNAME', '');
$dbPass = env_value('DB_PASSWORD', '');
$isMysql = in_array($connection, ['mysql', 'mariadb'], true);

/* --------------------------- tentukan prefix --------------------------- */
// Laravel punya tabel users/sessions/cache/jobs di database yang sama, jadi saat
// memakai MySQL tabel aplikasi WAJIB diberi prefix supaya tidak bertabrakan.
if ($prefixOpt !== null) {
    $prefix = $prefixOpt;
} elseif ($isMysql) {
    $prefix = 'fizzul_';
} else {
    $prefix = '';   // SQLite: aplikasi memakai berkasnya sendiri, tidak bertabrakan
}

/* ----------------------- tulis config.local.php ----------------------- */
$configPath = $APP_DIR . '/config.local.php';
$configBody = "<?php\n"
    . "/**\n"
    . " * Konfigurasi database aplikasi (dibuat otomatis oleh laravel/installer.php).\n"
    . " * Dibuat: " . date('d-m-Y H:i') . "\n"
    . " *\n"
    . " * Nilai di bawah diambil dari berkas .env proyek Laravel. Ubah bila perlu,\n"
    . " * lalu hapus berkas ini bila ingin memakai SQLite bawaan aplikasi.\n"
    . " */\n";
if ($isMysql) {
    $configBody .= "define('DB_DRIVER', 'mysql');\n"
        . "define('MYSQL_HOST', " . var_export($dbHost, true) . ");\n"
        . "define('MYSQL_PORT', " . var_export((int)$dbPort, true) . ");\n"
        . "define('MYSQL_DB',   " . var_export($dbName, true) . ");\n"
        . "define('MYSQL_USER', " . var_export($dbUser, true) . ");\n"
        . "define('MYSQL_PASS', " . var_export($dbPass, true) . ");\n"
        . "define('MYSQL_PREFIX', " . var_export($prefix, true) . ");\n"
        . "\n// Sesi login disimpan di tabel MySQL (paling andal di hosting)\n"
        . "define('SESSION_DRIVER', 'mysql');\n"
        . "define('SESSION_TABLE', 'sessions');\n";
} else {
    $configBody .= "// Laravel memakai SQLite: aplikasi memakai berkasnya sendiri di data/fizzul.sqlite\n"
        . "define('DB_DRIVER', 'sqlite');\n";
}
if (file_exists($configPath) && !$force) {
    $backup = $configPath . '.bak-' . date('Ymd-His');
    copy($configPath, $backup);
    warn('config.local.php sudah ada — versi lama disimpan sebagai ' . basename($backup));
}
file_put_contents($configPath, $configBody);
ok('config.local.php ditulis (' . ($isMysql ? 'MySQL ' . $dbName . ', prefix "' . $prefix . '"' : 'SQLite') . ')');

/* --------------------- buat folder tujuan di Laravel --------------------- */
@mkdir($laravelRoot . '/app/Http/Controllers', 0775, true);
@mkdir($laravelRoot . '/app/Providers', 0775, true);

/* -------------------------- controller Laravel -------------------------- */
$controllerPath = $laravelRoot . '/app/Http/Controllers/DapurFizzulController.php';
$controller = <<<'PHPCODE'
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Menjalankan aplikasi SIMOP Dapur Fizzul (PHP native) di dalam Laravel.
 *
 * Aplikasi memakai bootstrap, sesi, dan token keamanannya sendiri sehingga tidak
 * bergantung pada session/CSRF milik Laravel. Route didaftarkan oleh
 * App\Providers\DapurFizzulServiceProvider (tanpa middleware grup "web").
 *
 * Tidak ada berkas Laravel yang diubah selain menambah satu provider.
 */
class DapurFizzulController extends Controller
{
    /** Folder halaman aplikasi (masing-masing punya index.php sendiri) */
    private const APP_FOLDERS = [
        'masuk', 'dashboard', 'penjualan', 'impor-penjualan', 'ketersediaan', 'transaksi',
        'pembelian', 'stok', 'pergerakan-stok', 'opname', 'waste', 'resep', 'analitik',
        'laporan', 'bahan', 'supplier', 'menu', 'kategori-bahan', 'pengguna', 'audit',
        'pengaturan', 'unduh-mysql', 'keluar',
    ];

    /** Titik masuk berupa berkas (bukan folder) */
    private const APP_FILES = ['export.php', 'mysql-download.php'];

    public function handle(Request $request, string $path = '')
    {
        // 1) URL harus berakhir "/" — seluruh tautan aplikasi bersifat relatif,
        //    sehingga /dapur-fizzul/penjualan perlu diarahkan ke .../penjualan/.
        $uriPath = (string) strtok((string) $request->server('REQUEST_URI', '/'), '?');
        $lastSegment = basename($uriPath);
        $isFileEntry = str_contains($lastSegment, '.');   // mis. export.php, fizzul.sqlite
        if ($uriPath !== '' && ! str_ends_with($uriPath, '/') && ! $isFileEntry
            && in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            $qs = $request->getQueryString();
            header('Location: ' . $uriPath . '/' . ($qs ? '?' . $qs : ''), true, 308);
            exit;
        }

        $appDir = base_path('dapur-fizzul');
        $segment = trim(explode('/', trim((string) $path, '/'))[0] ?? '', '/');

        $target = match (true) {
            $segment === '' => $appDir . '/index.php',
            in_array($segment, self::APP_FILES, true) => $appDir . '/' . $segment,
            in_array($segment, self::APP_FOLDERS, true) => $appDir . '/' . $segment . '/index.php',
            default => null,
        };

        if ($target === null || ! is_file($target)) {
            abort(404, 'Halaman aplikasi Dapur Fizzul tidak ditemukan.');
        }

        // 2) Serahkan permintaan kepada aplikasi. Titik masuk aplikasi menangani
        //    sendiri header, sesi, dan keluaran, lalu mengakhiri permintaan.
        require $target;
        exit;
    }
}
PHPCODE;
file_put_contents($controllerPath, $controller);
ok('app/Http/Controllers/DapurFizzulController.php ditulis');

/* --------------------------- service provider --------------------------- */
$providerPath = $laravelRoot . '/app/Providers/DapurFizzulServiceProvider.php';
$provider = <<<'PHPCODE'
<?php

namespace App\Providers;

use App\Http\Controllers\DapurFizzulController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Mendaftarkan route aplikasi SIMOP Dapur Fizzul di dalam Laravel.
 *
 * Route didaftarkan TANPA middleware grup "web" (tanpa session, CSRF, dan
 * enkripsi cookie milik Laravel) karena aplikasi memakai sesi & token
 * keamanannya sendiri. Route lain milik Laravel tidak terpengaruh.
 */
class DapurFizzulServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::any('/dapur-fizzul/{path?}', [DapurFizzulController::class, 'handle'])
            ->where('path', '.*')
            ->name('dapur-fizzul');
    }
}
PHPCODE;
file_put_contents($providerPath, $provider);
ok('app/Providers/DapurFizzulServiceProvider.php ditulis');

/* --------------------- daftarkan provider di aplikasi --------------------- */
if ($skipProviders) {
    warn('Pendaftaran provider dilewati (--tanpa-provider). Daftarkan manual: App\Providers\DapurFizzulServiceProvider::class');
} else {
    $providersFile = $laravelRoot . '/bootstrap/providers.php';
    $registered = false;
    if (is_file($providersFile)) {
        $content = file_get_contents($providersFile);
        if (str_contains($content, 'DapurFizzulServiceProvider')) {
            ok('Provider sudah terdaftar di bootstrap/providers.php');
        } else {
            $patched = preg_replace('/return\s*\[/', "return [\n    App\\Providers\\DapurFizzulServiceProvider::class,", $content, 1, $count);
            if ($count === 1) {
                file_put_contents($providersFile, $patched);
                ok('Provider didaftarkan di bootstrap/providers.php');
            } else {
                warn('Tidak dapat menulis otomatis ke bootstrap/providers.php — tambahkan baris berikut:');
                info("App\\Providers\\DapurFizzulServiceProvider::class,");
            }
        }
        $registered = true;
    }
    if (!$registered && is_file($laravelRoot . '/config/app.php')) {
        $appCfg = file_get_contents($laravelRoot . '/config/app.php');
        if (str_contains($appCfg, 'DapurFizzulServiceProvider')) {
            ok('Provider sudah terdaftar di config/app.php');
        } else {
            $patched = preg_replace("/('providers'\s*=>\s*\[)/", "$1\n        App\\Providers\\DapurFizzulServiceProvider::class,", $appCfg, 1, $count);
            if ($count === 1) {
                file_put_contents($laravelRoot . '/config/app.php', $patched);
                ok('Provider didaftarkan di config/app.php (Laravel 10 ke bawah)');
            } else {
                warn('Daftarkan provider secara manual di config/app.php:');
                info("App\\Providers\\DapurFizzulServiceProvider::class,");
            }
        }
    }
}

/* ---------------------------- salin aset publik ---------------------------- */
function copy_dir(string $src, string $dst): int
{
    $n = 0;
    if (!is_dir($src)) return 0;
    if (!is_dir($dst) && !@mkdir($dst, 0775, true) && !is_dir($dst)) return 0;
    foreach (scandir($src) as $item) {
        if ($item === '.' || $item === '..') continue;
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) {
            $n += copy_dir($s, $d);
        } else {
            if (@copy($s, $d)) $n++;
        }
    }
    return $n;
}
$assetSrc = $APP_DIR . '/assets';
$assetDst = $laravelRoot . '/public/dapur-fizzul/assets';
$copied = copy_dir($assetSrc, $assetDst);
if ($copied > 0) {
    ok($copied . ' berkas aset disalin ke public/dapur-fizzul/assets');
} else {
    warn('Aset tidak tersalin — periksa izin tulis folder public/ Laravel');
}

/* ------------------------- proteksi folder data ------------------------- */
// Aplikasi memakai SQLite di data/ → jangan sampai berkas basis data dapat diunduh.
$dataDir = $laravelRoot . '/public/dapur-fizzul/data';
@mkdir($dataDir, 0775, true);
$htaccess = $dataDir . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Require all denied\n");
}
ok('public/dapur-fizzul/data diamankan (Apache: Require all denied)');

/* -------------------------------- ringkasan -------------------------------- */
echo "\n=== Selesai. Langkah berikutnya ===\n";
info('1. Pastikan folder aplikasi dapat ditulis (SQLite & sesi):');
info('     chmod -R 775 dapur-fizzul/data ' . ($isMysql ? '' : '(wajib untuk SQLite)'));
if ($isMysql) {
    info('2. Tabel aplikasi memakai prefix "' . $prefix . '" dan dibuat OTOMATIS pada');
    info('   akses pertama, sehingga tidak bertabrakan dengan tabel Laravel');
    info('   (users, sessions, cache, jobs, migrations). Boleh juga diimpor dari berkas');
    info('   .sql (menu Unduh Database MySQL di dalam aplikasi) dengan prefix yang sama.');
} else {
    info('2. Basis data aplikasi terpisah: dapur-fizzul/data/fizzul.sqlite');
}
info('3. Jalankan Laravel lalu buka alamat berikut:');
info('     php artisan serve');
info('     http://127.0.0.1:8000/dapur-fizzul/   → otomatis ke /dapur-fizzul/masuk/');
info('   Akun awal: owner/fizzul123 · kasir/kasir123 · dapur/dapur123 (segera ganti sandinya).');
info('4. Halaman Laravel Anda yang lain tetap normal (route ini hanya /dapur-fizzul/*).');
echo "\n";
info('Catatan: setelah mengubah berkas di folder dapur-fizzul/, tidak perlu build ulang.');
info('Laravel hanya memanggil titik masuk aplikasi pada setiap permintaan /dapur-fizzul/*.');
echo "\n";
exit(0);
