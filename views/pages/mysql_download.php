<?php
/**
 * Halaman unduh: berkas database MySQL untuk dipindahkan ke hosting lain.
 * Khusus Owner/Admin (perm 'settings').
 */
$prev = mysql_dump_preview();
$driverAktif = db_driver();
// information_schema hanya ada di MySQL; di SQLite cukup tampilkan jumlah tabel skema
$tabelTerpasang = $driverAktif === 'mysql'
    ? (int)qv($pdo, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()', [], 0)
    : count(mysql_ddl(''));
$driverMysqlTersedia = extension_loaded('pdo_mysql');
?>
<div class="notice info" style="margin-bottom:20px;">
    <span>🗄️</span><span>Halaman ini menghasilkan <b>berkas .sql siap impor untuk MySQL/MariaDB</b> — dipakai bila aplikasi ingin
    dipindahkan ke hosting lain (cPanel/phpMyAdmin) yang memakai MySQL. Berkas berisi <b>struktur 20 tabel</b>
    (19 tabel data + tabel <code>sessions</code> untuk penyimpanan sesi login; InnoDB, utf8mb4, foreign key lengkap)
    dan opsional <b>data demo</b> yang dibangkitkan ulang dari mesin seed aplikasi.
    Berkas ini <b>tidak memuat data transaksi nyata</b> milik usaha Anda.</span>
</div>

<div class="grid grid-kpi">
    <?= kpi_card('Tabel dalam berkas', (string)$prev['tabel'], $prev['fk'] . ' foreign key · ' . $prev['index'] . ' index', '🗄️', true) ?>
    <?= kpi_card('Tabel sesi login', 'sessions', 'Dibuat otomatis — tidak perlu dibuat manual di phpMyAdmin', '🔐') ?>
    <?= kpi_card('Driver basis data saat ini', strtoupper($driverAktif), $driverAktif === 'mysql'
        ? 'Aplikasi sedang berjalan di MySQL' : 'Aplikasi sedang berjalan di SQLite', '⚙️') ?>
    <?= kpi_card('Tabel aktif di database', (string)($driverAktif === 'mysql' ? $tabelTerpasang : 19), $driverAktif === 'mysql'
        ? e(MYSQL_DB) : 'data/fizzul.sqlite', '📦') ?>
    <?= kpi_card('Dukungan MySQL di server', $driverMysqlTersedia ? 'Tersedia' : 'Tidak ada',
        $driverMysqlTersedia ? 'Ekstensi pdo_mysql aktif' : 'Aktifkan ekstensi pdo_mysql di hosting', '🔌') ?>
</div>

<div class="grid grid-2 section">
    <div class="card">
        <div class="card-head"><h2>1. Pilih Isi Berkas</h2></div>
        <form method="get" action="<?= e(base_rel() . 'mysql-download.php') ?>">
            <div class="form-grid two">
                <div class="field span-2">
                    <label>Isi berkas</label>
                    <label class="check"><input type="radio" name="mode" value="base" checked>
                        <span><b>Struktur + akun awal</b> — mulai dari database kosong</span></label>
                    <label class="check"><input type="radio" name="mode" value="demo">
                        <span><b>Struktur + data demo lengkap</b> — bahan, menu Maluku, resep, penjualan 30 hari,
                        pembelian, waste, stock opname, pergerakan stok</span></label>
                </div>
                <div class="field"><label>Prefix tabel (opsional)</label>
                    <input type="text" name="prefix" placeholder="mis. fizzul_">
                    <span class="hint">Isi bila satu database dipakai bersama aplikasi lain (mis. WordPress),
                        supaya tabel tidak bertabrakan.</span></div>
                <div class="field"><label>Nama database pada CREATE DATABASE</label>
                    <input type="text" name="db" placeholder="mis. dapur_fizzul">
                    <span class="hint">Kosongkan bila database dibuat manual lebih dulu di cPanel (umumnya begitu).</span></div>
                <div class="field"><label class="check"><input type="checkbox" name="drop" value="1">
                    Sertakan <code>DROP TABLE IF EXISTS</code> (impor ulang dari nol)</label></div>
                <div class="field"><label class="check"><input type="checkbox" name="users" value="1" checked>
                    Sertakan 3 akun awal (owner/kasir/dapur)</label></div>
            </div>
            <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
                <button class="btn btn-primary" type="submit">⇩ Unduh Berkas .sql</button>
                <a class="btn" href="laravel-guide.php">📘 Panduan Integrasi Laravel</a>
                <span class="hint" style="align-self:center;">Struktur ±20 KB · dengan data demo ±130 KB</span>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-head"><h2>2. Cara Impor di Hosting Tujuan</h2></div>
        <ol style="font-size:13px;color:var(--muted);padding-left:20px;line-height:1.75;">
            <li>Buat database baru di cPanel (mis. <code>dapur_fizzul</code>) beserta penggunanya,
                lalu catat nama database, user, dan sandi.</li>
            <li>Buka <b>phpMyAdmin</b> → pilih database tadi → tab <b>Import</b> → pilih berkas
                <code>dapur-fizzul-mysql-*.sql</code> yang baru diunduh → <b>Go</b>.</li>
            <li>Unggah kode aplikasi ke hosting (folder publik, mis. <code>public_html</code>).</li>
            <li>Buat berkas <code>config.local.php</code> di folder aplikasi:
                <pre style="background:rgba(243,240,255,.8);padding:10px;border-radius:10px;font-size:12px;overflow:auto;">&lt;?php
define('DB_DRIVER', 'mysql');
define('MYSQL_HOST', 'localhost');
define('MYSQL_DB',   'dapur_fizzul');
define('MYSQL_USER', 'user_database');
define('MYSQL_PASS', 'sandi_database');
define('MYSQL_PREFIX', '');</pre>
            </li>
            <li>Buka aplikasi di browser → login. Selesai.</li>
        </ol>
        <div class="notice" style="border-color:rgba(217,119,6,.35);background:rgba(255,249,235,.85);color:#7c4a03;">
            <span>🔑</span><span>Akun awal: <b>owner/fizzul123</b>, <b>kasir/kasir123</b>, <b>dapur/dapur123</b> —
            <b>segera ganti kata sandinya</b> melalui menu Pengguna setelah login pertama.</span></div>
    </div>
</div>

<div class="card section">
    <div class="card-head"><h2>3. Struktur Tabel yang Disertakan</h2>
        <div class="spacer"></div><span class="badge info"><?= (int)$prev['tabel'] ?> tabel relasional</span></div>
    <div class="table-wrap"><table class="data compact">
        <thead><tr><th>No</th><th>Nama Tabel</th><th>Isi Utama</th></tr></thead>
        <tbody>
        <?php
        $keterangan = [
            'settings' => 'Pengaturan sistem (versi skema, status seed, identitas usaha)',
            'roles' => 'Role & matriks hak akses (Owner, Kasir, Dapur)',
            'users' => 'Pengguna sistem dengan kata sandi ter-hash',
            'ingredient_categories' => 'Kategori bahan baku',
            'suppliers' => 'Data supplier/pemasok',
            'ingredients' => 'Bahan baku: satuan, stok minimum, stok saat ini, harga rata-rata & terakhir',
            'menus' => 'Menu khas Maluku: kategori, harga jual, status aktif',
            'recipes' => 'Header resep standar (BOM) per menu',
            'recipe_items' => 'Takaran bahan tiap resep (gram/kg/ml/liter/ikat)',
            'sales' => 'Header transaksi penjualan: channel, metode bayar, HPP, total',
            'sale_items' => 'Rincian item menu per transaksi beserta HPP tersimpan',
            'purchases' => 'Header pembelian/penerimaan bahan',
            'purchase_items' => 'Rincian bahan dibeli + tanggal kedaluwarsa',
            'inventory_movements' => 'Audit trail seluruh pergerakan stok (stok sebelum/sesudah, referensi, pengguna)',
            'stock_opnames' => 'Sesi stock opname & status persetujuan',
            'stock_opname_items' => 'Stok sistem vs stok fisik per bahan + selisih',
            'waste_records' => 'Catatan bahan terbuang beserta alasan & estimasi biaya',
            'audit_logs' => 'Riwayat aktivitas pengguna & perubahan data',
            'import_batches' => 'Riwayat impor penjualan dari CSV/Excel',
            'sessions' => 'Penyimpanan sesi login bila SESSION_DRIVER=mysql (dibuat otomatis, boleh dikosongkan)',
        ];
        $no = 0;
        foreach (array_keys($keterangan) as $t):
            $no++;
        ?>
            <tr><td><?= $no ?></td><td><code><?= e($t) ?></code></td><td><?= e($keterangan[$t]) ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <div style="font-size:11.5px;color:var(--muted);margin-top:10px;">
        Seluruh tabel memakai <b>InnoDB</b>, rangkaian karakter <b>utf8mb4</b> (aman untuk karakter Indonesia),
        kunci utama, kunci unik, indeks penunjang, dan <b>foreign key</b> agar referensi antar data tetap terjaga.
        Tipe angka memakai DECIMAL sehingga nilai uang/stok tidak dibulatkan seperti FLOAT.
    </div>
</div>

<div class="card section">
    <div class="card-head"><h2>4. Cara Kerja Saat Aplikasi Sudah Berjalan di MySQL</h2></div>
    <div class="table-wrap"><table class="data compact">
        <thead><tr><th>Kondisi database tujuan</th><th>Perilaku aplikasi</th></tr></thead>
        <tbody>
            <tr><td>Sudah diimpor berkas ini (tabel lengkap)</td>
                <td>Aplikasi langsung dapat dipakai; pemeriksaan skema hanya berupa satu query singkat pada akses pertama.</td></tr>
            <tr><td>Tabel belum ada sama sekali</td>
                <td>Aplikasi memasang sendiri seluruh skema MySQL pada akses pertama, lalu mengisi akun awal dan data demo.</td></tr>
            <tr><td>Impor mode "struktur + akun awal"</td>
                <td>Sistem mulai dari database kosong: belum ada bahan/menu/transaksi, siap diisi data nyata usaha.</td></tr>
            <tr><td>Prefix tabel diaktifkan</td>
                <td>Semua query memakai nama tabel berprefix, sehingga aplikasi lain di database yang sama tidak terganggu.</td></tr>
            <tr><td>Mengalami "sesi kedaluwarsa" saat login</td>
                <td>Aktifkan <code>define('SESSION_DRIVER', 'mysql');</code> pada <code>config.local.php</code>. Sesi login
                akan disimpan pada tabel <code>sessions</code> (dibuat otomatis oleh aplikasi), sehingga tidak lagi bergantung
                pada penyimpanan sesi berkas milik hosting. Statusnya dapat dilihat di menu Pengaturan → Diagnostik Sesi.</td></tr>
        </tbody></table></div>
    <div class="notice info" style="margin-top:12px;"><span>ℹ️</span>
        <span>Setelah pindah ke MySQL, halaman ini akan menampilkan driver aktif <b>MYSQL</b> beserta nama database yang dipakai,
        sehingga Anda dapat memastikan aplikasi benar-benar memakai MySQL. Status tersebut juga tampil pada menu
        <a href="<?= e(app_url('settings')) ?>">Pengaturan → Informasi Sistem</a>.</span></div>
</div>
