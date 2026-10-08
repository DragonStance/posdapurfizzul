<?php
declare(strict_types=1);

/**
 * Titik masuk root. Seluruh halaman memiliki URL rapi sendiri
 * (mis. /dashboard/, /penjualan/, /stok/) sehingga tidak ada lagi
 * "index.php" pada alamat yang dilihat pengguna.
 */
require __DIR__ . '/lib/bootstrap.php';

$GLOBALS['SIMOP_DEPTH'] = 0; // index.php berada di akar aplikasi

/* Tautan lama (index.php?p=halaman) tetap diarahkan ke URL rapi yang sesuai */
$legacy = (string)(g('p', '') ?? '');
if ($legacy !== '' && isset(PAGE_DIR[$legacy])) {
    $params = $_GET;
    unset($params['p']);
    header('Location: ' . app_url($legacy, $params), true, 301);
    exit;
}

header('Location: ' . (is_logged_in() ? app_url(landing_page()) : app_url('login')));
exit;
