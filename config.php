<?php
/**
 * Sistem Informasi Manajemen Operasional Dapur Fizzul
 * Konfigurasi dasar aplikasi.
 */
declare(strict_types=1);

define('APP_NAME', 'Sistem Informasi Manajemen Operasional Dapur Fizzul');
define('APP_SHORT', 'SIMOP Dapur Fizzul');
define('APP_VERSION', '1.0.0');
define('SCHEMA_VERSION', '1.1.0');

define('BASE_DIR', __DIR__);
define('LIB_DIR', BASE_DIR . '/lib');
define('VIEW_DIR', BASE_DIR . '/views');
define('DATA_DIR', BASE_DIR . '/data');
/* ============================ Database ============================
 * Aplikasi dapat berjalan di SQLite (default) maupun MySQL/MariaDB.
 * Pada hosting lain, buat berkas config.local.php di folder aplikasi untuk
 * menimpa pengaturan di bawah, contoh:
 *
 *   <?php
 *   define('DB_DRIVER', 'mysql');
 *   define('MYSQL_HOST', 'localhost');
 *   define('MYSQL_DB',   'namadb_hosting');
 *   define('MYSQL_USER', 'user_hosting');
 *   define('MYSQL_PASS', 'sandi_hosting');
 *   define('MYSQL_PREFIX', '');   // isi mis. 'fizzul_' bila satu DB dipakai bersama aplikasi lain
 */
if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

// Berkas basis data SQLite. Dapat diarahkan lewat SIMOP_DB (dipakai saat pengujian).
if (!defined('DB_FILE')) {
    define('DB_FILE', getenv('SIMOP_DB') ?: (DATA_DIR . '/fizzul.sqlite'));
}
if (!defined('DB_DRIVER'))   define('DB_DRIVER', getenv('SIMOP_DB_DRIVER') ?: 'sqlite');
if (!defined('MYSQL_HOST'))  define('MYSQL_HOST', getenv('SIMOP_MYSQL_HOST') ?: 'localhost');
if (!defined('MYSQL_PORT'))  define('MYSQL_PORT', (int)(getenv('SIMOP_MYSQL_PORT') ?: 3306));
if (!defined('MYSQL_DB'))    define('MYSQL_DB', getenv('SIMOP_MYSQL_DB') ?: '');
if (!defined('MYSQL_USER'))  define('MYSQL_USER', getenv('SIMOP_MYSQL_USER') ?: '');
if (!defined('MYSQL_PASS'))  define('MYSQL_PASS', getenv('SIMOP_MYSQL_PASS') ?: '');
if (!defined('MYSQL_PREFIX')) define('MYSQL_PREFIX', getenv('SIMOP_MYSQL_PREFIX') ?: '');

/**
 * Penyimpanan sesi login:
 *  'file'  (default) — memakai penyimpanan sesi bawaan PHP.
 *  'mysql'           — sesi disimpan di tabel `sessions` pada database MySQL.
 *                      Tabel dibuat OTOMATIS oleh aplikasi (tidak perlu dibuat
 *                      manual di phpMyAdmin). Pakai opsi ini bila hosting Anda
 *                      sering kehilangan sesi (login terasa "kedaluwarsa").
 * Dapat disetel di config.local.php atau env SIMOP_SESSION_DRIVER.
 */
if (!defined('SESSION_DRIVER'))   define('SESSION_DRIVER', getenv('SIMOP_SESSION_DRIVER') ?: 'file');
if (!defined('SESSION_TABLE'))    define('SESSION_TABLE', getenv('SIMOP_SESSION_TABLE') ?: 'sessions');
if (!defined('SESSION_LIFETIME')) define('SESSION_LIFETIME', (int)(getenv('SIMOP_SESSION_LIFETIME') ?: 0));

define('TZ', 'Asia/Jakarta');
date_default_timezone_set(TZ);

/** Konstanta bisnis */
const CHANNELS = ['Kasir', 'QRIS', 'Marketplace', 'Online', 'Lainnya'];
const PAYMENT_METHODS = ['Tunai', 'QRIS', 'Transfer Bank', 'Marketplace', 'Lainnya'];
const WASTE_REASONS = ['Rusak', 'Kedaluwarsa', 'Busuk', 'Kesalahan Produksi', 'Sisa Produksi', 'Lainnya'];
const MENU_CATEGORIES = ['Makanan Utama', 'Lauk & Pendamping', 'Minuman', 'Camilan'];
const MOVEMENT_TYPES = [
    'saldo_awal'   => 'Saldo Awal',
    'pembelian'    => 'Pembelian',
    'penjualan'    => 'Penggunaan (Penjualan)',
    'waste'        => 'Waste / Terbuang',
    'penyesuaian'  => 'Penyesuaian Stok',
    'opname'       => 'Stock Opname',
    'penggunaan_manual' => 'Penggunaan Manual',
];
const OPNAME_STATUS = [
    'draft'     => 'Draft (Input Fisik)',
    'diajukan'  => 'Menunggu Persetujuan',
    'disetujui' => 'Disetujui & Diterapkan',
    'ditolak'   => 'Ditolak',
];
const ROLES = [
    'owner'   => 'Owner / Admin',
    'manager' => 'Manager',
    'kasir'   => 'Staff Kasir',
    'dapur'   => 'Staff Dapur / Inventory',
];

/**
 * Matriks hak akses per role (ditegakkan di server pada setiap halaman & aksi).
 *
 * Pembatasan modul:
 * - Kasir : TIDAK boleh Dashboard, Pembelian, Waste, seluruh isi modul Inventory
 *           (stok, pergerakan stok, stock opname, resep), maupun modul
 *           Analitik & Laporan. Modul Operasional kasir = Penjualan,
 *           Impor Penjualan, dan Cek Ketersediaan Menu.
 * - Dapur : TIDAK boleh Dashboard dan Penjualan (Operasional hanya Pembelian & Waste),
 *           modul Inventory & penggunaan bahan manual tetap boleh.
 * - Manager: hanya modul Operasional (Dashboard, Penjualan, Ketersediaan Menu, Pembelian, Waste),
 *           Inventory (stok, pergerakan, opname + persetujuannya, resep), dan Analitik & Laporan.
 *           TIDAK memiliki Master Data, Pengguna, Audit Trail, dan Pengaturan.
 */
const PERMISSIONS = [
    'owner' => ['dashboard','sales','sales_void','purchase','inventory','opname','opname_approve','waste','waste_void',
                'recipes','recipes_view','master','users','analytics','reports','audit','settings','costs','availability'],
    'manager' => ['dashboard','sales','sales_void','availability','purchase','waste','waste_void',
                  'inventory','opname','opname_approve','recipes','recipes_view','analytics','reports','costs'],
    'kasir' => ['sales','availability','costs'],
    'dapur' => ['purchase','inventory','opname','waste','recipes_view','manual_usage'],
];

/** Satuan & konversi (dimension + faktor terhadap satuan dasar dimensi) */
const UNITS = [
    'gram'  => ['dim' => 'massa',  'factor' => 1,    'label' => 'gram'],
    'kg'    => ['dim' => 'massa',  'factor' => 1000, 'label' => 'kg'],
    'ons'   => ['dim' => 'massa',  'factor' => 100,  'label' => 'ons'],
    'ml'    => ['dim' => 'volume', 'factor' => 1,    'label' => 'ml'],
    'liter' => ['dim' => 'volume', 'factor' => 1000, 'label' => 'liter'],
    'pcs'   => ['dim' => 'buah',   'factor' => 1,    'label' => 'pcs'],
    'ekor'  => ['dim' => 'buah',   'factor' => 1,    'label' => 'ekor'],
    'ikat'  => ['dim' => 'ikat',   'factor' => 1,    'label' => 'ikat'],
    'pack'  => ['dim' => 'pack',   'factor' => 1,    'label' => 'pack'],
    'botol' => ['dim' => 'botol',  'factor' => 1,    'label' => 'botol'],
];

/** Threshold stok */
const STOCK_CRITICAL_RATIO = 0.5; // < 50% stok minimum => Kritis
const EXPIRY_WARNING_DAYS = 14;   // peringatan mendekati kedaluwarsa
