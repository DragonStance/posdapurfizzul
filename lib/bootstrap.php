<?php
declare(strict_types=1);

/**
 * Bootstrap aplikasi: konfigurasi, session, dan seluruh library.
 */
require_once __DIR__ . '/../config.php';

require_once LIB_DIR . '/sql.php';
require_once LIB_DIR . '/db.php';
require_once LIB_DIR . '/session.php';
require_once LIB_DIR . '/schema.php';
require_once LIB_DIR . '/schema_mysql.php';
require_once LIB_DIR . '/helpers.php';
require_once LIB_DIR . '/auth.php';
require_once LIB_DIR . '/audit.php';
require_once LIB_DIR . '/inventory.php';
require_once LIB_DIR . '/sales.php';
require_once LIB_DIR . '/analytics.php';
require_once LIB_DIR . '/csv.php';
require_once LIB_DIR . '/ui.php';
require_once LIB_DIR . '/mysql_export.php';
require_once LIB_DIR . '/reports.php';
require_once LIB_DIR . '/routes.php';
require_once LIB_DIR . '/seed.php';

// Sesi pengguna: penyimpanan berkas (default) atau tabel MySQL (opsional).
// Lihat lib/session.php — termasuk pembuatan tabel sesi otomatis.
session_bootstrap();

// Token keamanan disiapkan lebih dulu (sebelum keluaran) agar cookie cadangan
// benar-benar terkirim ke browser — kunci perbaikan masalah "sesi kedaluwarsa".
csrf_init();

// Seluruh halaman HTML harus selalu segar agar token keamanan pada form tidak basi
send_no_cache_headers();

// Pesan error tetap informatif untuk prototype internal
ini_set('display_errors', '0');
error_reporting(E_ALL);
