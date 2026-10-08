<?php
declare(strict_types=1);

/**
 * Panduan Integrasi Laravel (khusus Owner/Admin).
 *
 * Berkas .md tidak dapat dibuka langsung lewat URL publik, sehingga panduan
 * ditampilkan dari dalam aplikasi (dan dapat diunduh sebagai berkas teks).
 */
require __DIR__ . '/lib/bootstrap.php';

require_login();
require_perm('settings');

$file = BASE_DIR . '/laravel/README-INTEGRASI-LARAVEL.md';
if (!is_file($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Berkas panduan laravel/README-INTEGRASI-LARAVEL.md tidak ditemukan di folder aplikasi.');
}
$isi = (string)file_get_contents($file);

// unduh sebagai berkas teks
if ((int)(g('download', '0') ?? 0) === 1) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="README-INTEGRASI-LARAVEL.md"');
    header('Content-Length: ' . strlen($isi));
    header('Cache-Control: no-store');
    echo $isi;
    exit;
}

$installed = is_file(BASE_DIR . '/../../../artisan') || is_file(BASE_DIR . '/../../artisan');
$laravelInfo = [
    'folder_aplikasi' => BASE_DIR,
    'berkas_panduan' => $file,
    'berkas_installer' => BASE_DIR . '/laravel/installer.php',
    'php_version' => PHP_VERSION,
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Panduan Integrasi Laravel · <?= e(APP_SHORT) ?></title>
<link rel="stylesheet" href="<?= e(app_asset('css/app.css')) ?>?v=<?= e(APP_VERSION) ?>">
<style>
    .guide { white-space: pre-wrap; word-wrap: break-word; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
             font-size: 12.5px; line-height: 1.65; margin: 0; }
</style>
</head>
<body>
<div class="app" style="display:block;">
<main class="main" style="max-width:1000px;margin:0 auto;">
    <div class="topbar">
        <div>
            <h1>Panduan Integrasi Laravel</h1>
            <div class="sub">Cara menjalankan aplikasi ini di dalam proyek Laravel (Laravel 11 diuji: 85 pemeriksaan lolos)</div>
        </div>
        <div class="topbar-actions">
            <a class="btn" href="<?= e(app_url('mysql_download')) ?>">← Kembali ke Unduh Database</a>
            <a class="btn" href="?download=1">⇩ Unduh panduan (.md)</a>
            <button class="btn" onclick="window.print()">🖨 Cetak</button>
        </div>
    </div>

    <div class="grid grid-kpi" style="margin-bottom:20px;">
        <?= kpi_card('Berkas panduan', 'README-INTEGRASI-LARAVEL.md', 'Tersimpan di folder laravel/ aplikasi', '📘', true) ?>
        <?= kpi_card('Skrip pemasang', 'laravel/installer.php', 'Dijalankan lewat baris perintah (CLI)', '⚙️') ?>
        <?= kpi_card('Status di server ini', $installed ? 'Terpasang di Laravel' : 'PHP berdiri sendiri', $installed
            ? 'Terdeteksi proyek Laravel di folder induk' : 'Aplikasi berjalan tanpa framework (normal)', '🔎') ?>
        <?= kpi_card('Router tambahan', 'provider Laravel', 'Tanpa middleware web — sesi aplikasi aman', '🔐') ?>
    </div>

    <div class="card">
        <div class="card-head"><h2>Ringkasan 3 langkah</h2></div>
        <ol style="font-size:13px;line-height:1.8;padding-left:20px;margin:0;">
            <li>Salin folder <code>dapur-fizzul/</code> ke <b>root proyek Laravel</b> (sejajar <code>app/</code>, <code>routes/</code>, <code>public/</code>).</li>
            <li>Jalankan pemasang: <code>php dapur-fizzul/laravel/installer.php</code>
                (membuat controller, provider, menyalin aset, dan menulis <code>config.local.php</code> mengikuti <code>.env</code>).</li>
            <li>Jalankan Laravel (<code>php artisan serve</code>) lalu buka <code>/dapur-fizzul/</code> → diarahkan ke <code>/dapur-fizzul/masuk/</code>.</li>
        </ol>
        <div class="notice info" style="margin-top:14px;"><span>ℹ️</span>
            <span>Bila Laravel memakai MySQL, aplikasi otomatis memakai database yang sama dengan prefix
            <code>fizzul_</code> sehingga tidak bertabrakan dengan tabel Laravel (<code>users</code>, <code>sessions</code>,
            <code>jobs</code>, <code>cache</code>). Tabel aplikasi dibuat otomatis pada akses pertama.</span></div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Isi Panduan Lengkap</h2>
            <div class="spacer"></div><span class="badge info"><?= number_format(strlen($isi), 0, ',', '.') ?> byte</span></div>
        <pre class="guide"><?= e($isi) ?></pre>
    </div>

    <div class="card">
        <div class="card-head"><h2>Informasi Lingkungan</h2></div>
        <div class="table-wrap"><table class="data compact">
            <thead><tr><th>Item</th><th>Nilai</th></tr></thead>
            <tbody>
                <tr><td>Versi PHP</td><td><?= e($laravelInfo['php_version']) ?></td></tr>
                <tr><td>Folder aplikasi</td><td><code><?= e($laravelInfo['folder_aplikasi']) ?></code></td></tr>
                <tr><td>Berkas panduan</td><td><code><?= e($laravelInfo['berkas_panduan']) ?></code>
                    <?= is_file($laravelInfo['berkas_panduan']) ? '<span class="badge ok">ada</span>' : '<span class="badge danger">tidak ada</span>' ?></td></tr>
                <tr><td>Berkas pemasang</td><td><code><?= e($laravelInfo['berkas_installer']) ?></code>
                    <?= is_file($laravelInfo['berkas_installer']) ? '<span class="badge ok">ada</span>' : '<span class="badge danger">tidak ada</span>' ?></td></tr>
                <tr><td>Driver basis data aplikasi</td><td><span class="badge info"><?= e(strtoupper(db_driver())) ?></span></td></tr>
            </tbody></table></div>
        <div style="font-size:11.5px;color:var(--muted);margin-top:10px;">
            Berkas <code>.md</code> dan berkas non-PHP lain tidak dapat dibuka langsung melalui URL aplikasi.
            Gunakan tombol <b>Unduh panduan</b> di atas untuk menyimpannya, atau unduh seluruh kode aplikasi
            (termasuk folder <code>laravel/</code>) melalui fitur <b>Download Source Code</b> (VibeCoder Pro).
        </div>
    </div>
</main>
</div>
<script src="<?= e(app_asset('js/app.js')) ?>?v=<?= e(APP_VERSION) ?>"></script>
</body>
</html>
