<?php
declare(strict_types=1);

/**
 * URL rapi (tanpa index.php).
 *
 * Setiap halaman punya folder publiknya sendiri yang berisi index.php tipis,
 * sehingga server melayani /dapur-fizzul/penjualan/ sebagai front controller.
 * Tidak ada aturan rewrite server yang dibutuhkan, dan seluruh tautan/aset
 * tetap relatif sehingga benar baik di alamat default maupun custom domain.
 */
const ROUTES = [
    'masuk'           => 'login',
    'dashboard'       => 'dashboard',
    'penjualan'       => 'sales',
    'impor-penjualan' => 'sales_import',
    'ketersediaan'    => 'availability',
    'transaksi'       => 'sale_detail',
    'pembelian'       => 'purchases',
    'stok'            => 'inventory',
    'pergerakan-stok' => 'movements',
    'opname'          => 'opname',
    'waste'           => 'waste',
    'resep'           => 'recipes',
    'analitik'        => 'analytics',
    'laporan'         => 'reports',
    'bahan'           => 'master_ingredients',
    'supplier'        => 'master_suppliers',
    'menu'            => 'master_menus',
    'kategori-bahan'  => 'master_categories',
    'pengguna'        => 'master_users',
    'audit'           => 'audit',
    'pengaturan'      => 'settings',
    'unduh-mysql'     => 'mysql_download',
    'keluar'          => 'logout',
];

/** Nama halaman internal -> folder URL-nya */
const PAGE_DIR = [
    'login'              => 'masuk',
    'dashboard'          => 'dashboard',
    'sales'              => 'penjualan',
    'sales_import'       => 'impor-penjualan',
    'sale_detail'        => 'transaksi',
    'availability'       => 'ketersediaan',
    'purchases'          => 'pembelian',
    'inventory'          => 'stok',
    'movements'          => 'pergerakan-stok',
    'opname'             => 'opname',
    'opname_detail'      => 'opname',
    'waste'              => 'waste',
    'recipes'            => 'resep',
    'recipe_edit'        => 'resep',
    'analytics'          => 'analitik',
    'reports'            => 'laporan',
    'report_view'        => 'laporan',
    'master_ingredients' => 'bahan',
    'master_suppliers'   => 'supplier',
    'master_menus'       => 'menu',
    'master_categories'  => 'kategori-bahan',
    'master_users'       => 'pengguna',
    'audit'              => 'audit',
    'settings'           => 'pengaturan',
    'mysql_download'     => 'unduh-mysql',
    'logout'             => 'keluar',
];

/** Halaman detail yang berada di folder yang sama dengan daftarnya, dibedakan lewat query */
const PAGE_DETAIL = [
    'opname'      => ['param' => 'id', 'page' => 'opname_detail'],
    'recipes'     => ['param' => 'menu', 'page' => 'recipe_edit'],
    'reports'     => ['param' => 'type', 'page' => 'report_view'],
];
