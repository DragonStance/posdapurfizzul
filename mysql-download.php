<?php
declare(strict_types=1);

/**
 * Unduh berkas .sql untuk MySQL/MariaDB (khusus Owner/Admin).
 *
 * Menghasilkan berkas impor siap pakai dari definisi skema aplikasi.
 * Berkas ini TIDAK berisi data transaksi nyata milik pengguna: mode "data demo"
 * membangkitkan ulang data contoh pada basis data sementara, sehingga aman
 * dibagikan/dipindahkan ke hosting lain.
 */
require __DIR__ . '/lib/bootstrap.php';

require_login();
require_perm('settings');

$mode = (string)(g('mode', 'base') ?? 'base');
$prefix = preg_replace('/[^a-zA-Z0-9_]/', '', (string)(g('prefix', '') ?? ''));
$database = preg_replace('/[^a-zA-Z0-9_]/', '', (string)(g('db', '') ?? ''));
$drop = (int)(g('drop', '0') ?? 0) === 1;
$withUsers = (int)(g('users', '1') ?? 1) === 1;

try {
    $sql = mysql_dump([
        'prefix'      => $prefix,
        'database'    => $database,
        'drop_tables' => $drop,
        'with_demo'   => $mode === 'demo',
        'with_users'  => $withUsers,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Gagal membuat berkas SQL: ' . $e->getMessage());
}

$nama = 'dapur-fizzul-mysql-' . $mode . ($prefix !== '' ? '-' . rtrim($prefix, '_') : '') . '.sql';

db()->exec('BEGIN IMMEDIATE');
try {
    audit(db(), 'export', 'mysql_dump', null, sprintf(
        'Unduh berkas database MySQL (mode %s%s%s, %d byte)',
        $mode === 'demo' ? 'struktur + data demo' : 'struktur + akun awal',
        $prefix !== '' ? ', prefix ' . $prefix : '',
        $drop ? ', dengan DROP TABLE' : '',
        strlen($sql)
    ));
    db()->exec('COMMIT');
} catch (Throwable $e) {
    try { db()->exec('ROLLBACK'); } catch (Throwable $e2) {}
}

header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $nama . '"');
header('Content-Length: ' . strlen($sql));
header('Cache-Control: no-store');
echo $sql;
exit;
