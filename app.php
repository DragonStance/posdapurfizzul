<?php
declare(strict_types=1);

/**
 * SIMOP Dapur Fizzul — front controller bersama.
 *
 * Dipanggil oleh entry tipis di setiap folder URL (mis. /penjualan/index.php
 * yang menetapkan $SIMOP_DIR = 'penjualan'). Seluruh tautan dan aset memakai
 * path RELATIF (lihat app_url(), app_asset(), self_url()) sehingga aplikasi benar baik
 * pada alamat default /<slug>/ maupun bila pengguna memasang custom domain.
 */
require __DIR__ . '/lib/bootstrap.php';

$dir = (string)($SIMOP_DIR ?? '');
$GLOBALS['SIMOP_DEPTH'] = (int)($SIMOP_DEPTH ?? 1);
$GLOBALS['SIMOP_SELF'] = str_repeat('../', $GLOBALS['SIMOP_DEPTH']) . ($dir !== '' ? $dir . '/' : '');

/* Pastikan URL halaman selalu berakhiran "/" supaya tautan relatif tepat
   (server juga sudah 308 ke versi bergaris miring; ini pengaman tambahan). */
if ($GLOBALS['SIMOP_DEPTH'] > 0
    && in_array((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'HEAD'], true)) {
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    $path = strtok($uri, '?');
    $qs = str_contains($uri, '?') ? substr($uri, (int)strpos($uri, '?')) : '';
    if (is_string($path) && $path !== '' && !str_ends_with($path, '/')) {
        header('Location: ' . $path . '/' . $qs, true, 308);
        exit;
    }
}

$pdo = db();

/* Kompatibilitas tautan lama (?p=halaman) -> dialihkan permanen ke URL rapi */
$legacy = (string)(g('p', '') ?? '');
if ($legacy !== '' && isset(PAGE_DIR[$legacy])) {
    $params = $_GET;
    unset($params['p']);
    header('Location: ' . app_url($legacy, $params), true, 301);
    exit;
}

/* ---------------- Penentuan halaman ---------------- */
$page = $dir !== '' ? (ROUTES[$dir] ?? 'dashboard') : 'dashboard';
if (isset(PAGE_DETAIL[$page]) && isset($_GET[PAGE_DETAIL[$page]['param']])) {
    $page = PAGE_DETAIL[$page]['page'];
}
if (isset($SIMOP_PAGE)) {
    $page = (string)$SIMOP_PAGE;
}

/* ---------------- Halaman publik ---------------- */
if ($page === 'login') {
    require VIEW_DIR . '/login.php';
    exit;
}
if ($page === 'logout') {
    logout_user();
    session_start();
    flash('ok', 'Anda telah keluar dari sistem.');
    app_redirect('login');
}

require_login();

/* ---------------- Aksi POST (form) ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require LIB_DIR . '/actions.php';
    if (!headers_sent()) {
        app_redirect(landing_page()); // pengaman bila handler tidak mengalihkan halaman
    }
    exit;
}

/* ---------------- Metadata halaman ---------------- */
$PAGES = [
    'dashboard'        => ['Dashboard Operasional', 'Ringkasan penjualan, stok, waste, dan peringatan hari ini'],
    'sales'            => ['Penjualan', 'Transaksi penjualan, input manual, dan impor data marketplace'],
    'sales_import'     => ['Impor Penjualan', 'Unggah data penjualan dari CSV/Excel (marketplace/kanal online)'],
    'availability'     => ['Cek Ketersediaan Menu', 'Apakah menu masih bisa dibuat — dihitung dari resep dan stok bahan yang tersimpan'],
    'sale_detail'      => ['Detail Transaksi', 'Rincian transaksi, item, dan penggunaan bahan'],
    'purchases'        => ['Pembelian & Bahan Masuk', 'Pencatatan pembelian, penerimaan bahan, dan histori harga'],
    'inventory'        => ['Inventory / Stok', 'Stok saat ini, status, dan informasi kedaluwarsa'],
    'movements'        => ['Pergerakan Stok', 'Audit trail seluruh perubahan stok bahan'],
    'opname'           => ['Stock Opname', 'Perbandingan stok sistem vs stok fisik dan penyesuaiannya'],
    'opname_detail'    => ['Detail Stock Opname', 'Input hasil hitung fisik dan persetujuan penyesuaian'],
    'waste'            => ['Waste / Bahan Terbuang', 'Pencatatan bahan rusak, kedaluwarsa, dan terbuang'],
    'recipes'          => ['Resep & HPP Menu', 'Standard recipe (BOM) setiap menu dan estimasi HPP per porsi'],
    'recipe_edit'      => ['Editor Resep', 'Kelola takaran bahan baku pada standard recipe menu'],
    'analytics'        => ['Analitik & Business Intelligence', 'Analitik penjualan, inventory, pembelian, waste, dan profitabilitas'],
    'reports'          => ['Laporan & Ekspor', 'Cetak dan ekspor laporan operasional (CSV/Excel)'],
    'report_view'      => ['Cetak Laporan', 'Tampilan laporan siap cetak'],
    'master_ingredients' => ['Master Bahan Baku', 'Kelola data bahan baku, satuan, stok minimum, dan supplier'],
    'master_suppliers' => ['Master Supplier', 'Kelola data pemasok bahan baku'],
    'master_menus'     => ['Master Menu', 'Kelola daftar menu, kategori, dan harga jual'],
    'master_categories'=> ['Kategori Bahan', 'Kelompok bahan baku untuk pelaporan'],
    'master_users'     => ['Pengguna & Hak Akses', 'Kelola akun pengguna dan role (Owner, Kasir, Dapur)'],
    'audit'            => ['Audit Trail', 'Riwayat aktivitas pengguna dan perubahan data'],
    'settings'         => ['Pengaturan Sistem', 'Informasi aplikasi, data demo, dan preferensi'],
    'mysql_download'   => ['Unduh Database MySQL', 'Berkas .sql siap impor untuk memindahkan aplikasi ke hosting lain'],
];

$NAV = [
    'Operasional' => [
        ['dashboard', 'Dashboard', '📊', 'dashboard'],
        ['sales', 'Penjualan', '🧾', 'sales'],
        ['availability', 'Cek Ketersediaan Menu', '✅', 'availability'],
        ['purchases', 'Pembelian', '📦', 'purchase'],
        ['waste', 'Waste', '🗑️', 'waste'],
    ],
    'Inventory' => [
        ['inventory', 'Stok Bahan', '🏷️', 'inventory'],
        ['movements', 'Pergerakan Stok', '🔄', 'inventory'],
        ['opname', 'Stock Opname', '📋', 'opname'],
        ['recipes', 'Resep & HPP', '📖', 'recipes_view'],
    ],
    'Analitik & Laporan' => [
        ['analytics', 'Analitik', '📈', 'analytics'],
        ['reports', 'Laporan', '🗂️', 'reports'],
    ],
    'Master Data' => [
        ['master_ingredients', 'Bahan Baku', '🧂', 'master'],
        ['master_suppliers', 'Supplier', '🚚', 'master'],
        ['master_menus', 'Menu', '🍽️', 'master'],
        ['master_categories', 'Kategori Bahan', '📁', 'master'],
    ],
    'Sistem' => [
        ['master_users', 'Pengguna', '👥', 'users'],
        ['audit', 'Audit Trail', '🕵️', 'audit'],
        ['settings', 'Pengaturan', '⚙️', 'settings'],
        ['mysql_download', 'Unduh Database MySQL', '🗄️', 'settings'],
    ],
];

/* ---------------- Hak akses per halaman (RBAC) ---------------- */
$PERMS = [
    'dashboard' => 'dashboard',
    'sales' => 'sales', 'sale_detail' => 'sales', 'sales_import' => 'sales',
    'availability' => 'availability',
    'purchases' => 'purchase',
    'inventory' => 'inventory', 'movements' => 'inventory',
    'opname' => 'opname', 'opname_detail' => 'opname',
    'waste' => 'waste',
    'recipes' => 'recipes_view', 'recipe_edit' => 'recipes_view',
    'analytics' => 'analytics',
    'reports' => 'reports', 'report_view' => 'reports',
    'master_ingredients' => 'master', 'master_suppliers' => 'master',
    'master_menus' => 'master', 'master_categories' => 'master',
    'master_users' => 'users',
    'audit' => 'audit', 'settings' => 'settings', 'mysql_download' => 'settings',
];

if (!isset($PAGES[$page])) {
    $page = 'dashboard';
    $GLOBALS['SIMOP_SELF'] = str_repeat('../', (int)$GLOBALS['SIMOP_DEPTH']) . 'dashboard/';
}
require_perm($PERMS[$page] ?? 'dashboard');
[$pageTitle, $pageSubtitle] = $PAGES[$page];

$view = VIEW_DIR . '/pages/' . $page . '.php';
if (!file_exists($view)) {
    $view = VIEW_DIR . '/pages/404.php';
}

include VIEW_DIR . '/layout.php';
