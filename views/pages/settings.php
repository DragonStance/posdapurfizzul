<?php
/** Pengaturan sistem + ringkasan data untuk keperluan demonstrasi capstone. */
$counts = [
    'Bahan baku' => (int)qv($pdo, 'SELECT COUNT(*) FROM ingredients'),
    'Kategori bahan' => (int)qv($pdo, 'SELECT COUNT(*) FROM ingredient_categories'),
    'Supplier' => (int)qv($pdo, 'SELECT COUNT(*) FROM suppliers'),
    'Menu' => (int)qv($pdo, 'SELECT COUNT(*) FROM menus'),
    'Baris resep (BOM)' => (int)qv($pdo, 'SELECT COUNT(*) FROM recipe_items'),
    'Transaksi penjualan' => (int)qv($pdo, 'SELECT COUNT(*) FROM sales'),
    'Item penjualan' => (int)qv($pdo, 'SELECT COUNT(*) FROM sale_items'),
    'Nota pembelian' => (int)qv($pdo, 'SELECT COUNT(*) FROM purchases'),
    'Baris pembelian' => (int)qv($pdo, 'SELECT COUNT(*) FROM purchase_items'),
    'Catatan waste' => (int)qv($pdo, 'SELECT COUNT(*) FROM waste_records'),
    'Sesi stock opname' => (int)qv($pdo, 'SELECT COUNT(*) FROM stock_opnames'),
    'Pergerakan stok' => (int)qv($pdo, 'SELECT COUNT(*) FROM inventory_movements'),
    'Pengguna' => (int)qv($pdo, 'SELECT COUNT(*) FROM users'),
    'Baris audit trail' => (int)qv($pdo, 'SELECT COUNT(*) FROM audit_logs'),
];
$dbSize = file_exists(DB_FILE) ? filesize(DB_FILE) : 0;
$firstSale = (string)qv($pdo, 'SELECT MIN(sold_at) FROM sales', [], '');
$lastSale = (string)qv($pdo, 'SELECT MAX(sold_at) FROM sales', [], '');
$demo = setting_get($pdo, 'demo_mode') === '1';
$sesi = session_status_info();
?>
<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2>Identitas Usaha</h2></div>
        <form method="post" action="<?= e(self_url()) ?>" class="form-grid one">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="save_settings">
            <input type="hidden" name="_back" value="<?= e(app_url('settings')) ?>">
            <div class="field"><label>Nama Usaha</label><input type="text" name="business_name"
                value="<?= e((string)setting_get($pdo, 'business_name', 'Dapur Fizzul')) ?>"></div>
            <div class="field"><label>Alamat</label><input type="text" name="address"
                value="<?= e((string)setting_get($pdo, 'address', 'Ambon, Maluku')) ?>"></div>
            <div class="field"><label>Telepon / WhatsApp</label><input type="text" name="phone"
                value="<?= e((string)setting_get($pdo, 'phone', '')) ?>"></div>
            <button class="btn btn-primary" type="submit">Simpan Pengaturan</button>
        </form>
        <div style="font-size:11.5px;color:var(--muted);margin-top:10px;">
            Identitas usaha dipakai pada kepala semua laporan yang dicetak.
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Informasi Sistem</h2></div>
        <div class="stat-line"><span>Nama aplikasi</span><b><?= e(APP_NAME) ?></b></div>
        <div class="stat-line"><span>Versi aplikasi / skema</span><b><?= e(APP_VERSION) ?> / <?= e((string)setting_get($pdo, 'schema_version', SCHEMA_VERSION)) ?></b></div>
        <div class="stat-line"><span>Teknologi</span><b>PHP <?= e(PHP_VERSION) ?> · SQLite (PDO) · Chart.js</b></div>
        <div class="stat-line"><span>Driver basis data</span>
            <b><span class="badge <?= db_driver() === 'mysql' ? 'info' : 'ok' ?>"><?= e(strtoupper(db_driver())) ?></span>
            <?= db_driver() === 'mysql' ? 'server MySQL/MariaDB' : 'berkas SQLite lokal' ?></b></div>
        <div class="stat-line"><span>Basis data</span><b><?= db_driver() === 'mysql'
            ? e(MYSQL_HOST . ':' . MYSQL_PORT . ' → ' . MYSQL_DB . (db_prefix() !== '' ? ' (prefix ' . db_prefix() . ')' : ''))
            : 'data/fizzul.sqlite' ?></b></div>
        <div class="stat-line"><span>Ukuran basis data</span><b><?= db_driver() === 'mysql'
            ? number_format((float)qv($pdo, "SELECT COALESCE(SUM(data_length + index_length),0)/1024 FROM information_schema.tables WHERE table_schema = DATABASE()"), 1, ',', '.') . ' KB (tabel aplikasi)'
            : number_format($dbSize / 1024, 1, ',', '.') . ' KB' ?></b></div>
        <div class="stat-line"><span>Data demo dimuat</span><b><?= e((string)setting_get($pdo, 'seeded_at', '-')) ?></b></div>
        <div class="stat-line"><span>Rentang data penjualan</span><b><?= $firstSale ? tgl($firstSale) . ' – ' . tgl($lastSale) : '-' ?></b></div>
        <div class="stat-line"><span>Mode data demo</span><b><?= $demo ? 'Aktif (label DATA DEMO tampil)' : 'Nonaktif' ?></b></div>
        <div class="notice info" style="margin-top:12px;"><span>🗄️</span>
            <span>Ingin memindahkan aplikasi ini ke hosting lain yang memakai MySQL? Unduh berkas database siap impor di
            <a href="<?= e(app_url('mysql_download')) ?>">Unduh Database MySQL</a>.</span></div>
        <div class="notice demo" style="margin-top:12px;"><span>🧪</span>
            <span>Data demo dimuat sekali saat aplikasi pertama kali dijalankan dan <b>tidak direset otomatis</b>.
            Data yang Anda input melalui aplikasi adalah data nyata dan akan tetap tersimpan. Bila ingin memulai dari nol,
            hapus berkas <code>data/fizzul.sqlite</code> melalui pengelola berkas server, lalu aplikasi akan menyiapkan ulang
            skema + data demo pada akses berikutnya.</span></div>
    </div>
</div>

<div class="card section">
    <div class="card-head"><h2>🔐 Diagnostik Sesi Login</h2>
        <div class="spacer"></div>
        <?php if ($sesi['persisted']): ?>
            <span class="badge ok">Sesi bertahan dengan baik</span>
        <?php else: ?>
            <span class="badge warn">Baru terdeteksi 1 permintaan</span>
        <?php endif; ?>
    </div>
    <p style="font-size:12.5px;color:var(--muted);">Bagian ini membantu memeriksa apakah sesi login tersimpan dengan benar di
        hosting Anda. Bila pengguna sering melihat pesan "sesi kedaluwarsa" saat login, aktifkan penyimpanan sesi di MySQL.</p>
    <div class="grid grid-4">
        <div class="card" style="box-shadow:none;padding:12px 14px;">
            <div class="k-label">Penyimpanan sesi</div>
            <div style="font-size:15px;font-weight:700;"><?= e(strtoupper($sesi['driver'])) ?></div>
            <div class="li-sub"><?= $sesi['driver'] === 'mysql' ? 'Tabel MySQL (disarankan untuk hosting bersama)' : 'Berkas bawaan PHP' ?></div>
        </div>
        <div class="card" style="box-shadow:none;padding:12px 14px;">
            <div class="k-label">Lokasi penyimpanan</div>
            <div style="font-size:13px;font-weight:600;word-break:break-all;"><?= e($sesi['save_path']) ?></div>
            <div class="li-sub"><?= $sesi['save_path_writable'] ? 'Dapat ditulis' : 'TIDAK dapat ditulis — aktifkan sesi MySQL' ?></div>
        </div>
        <div class="card" style="box-shadow:none;padding:12px 14px;">
            <div class="k-label">Permintaan dalam sesi ini</div>
            <div style="font-size:15px;font-weight:700;"><?= (int)$sesi['requests'] ?></div>
            <div class="li-sub">Terakhir: <?= e(tgl($sesi['last_seen'], true)) ?></div>
        </div>
        <div class="card" style="box-shadow:none;padding:12px 14px;">
            <div class="k-label">Cookie sesi</div>
            <div style="font-size:13px;font-weight:600;"><?= e($sesi['name']) ?></div>
            <div class="li-sub">HttpOnly: <?= $sesi['httponly'] ? 'ya' : 'tidak' ?> · SameSite: <?= e($sesi['samesite']) ?>
                · Secure: <?= $sesi['secure_cookie'] ? 'ya' : 'tidak' ?></div>
        </div>
    </div>
    <div class="notice info" style="margin-top:12px;"><span>ℹ️</span>
        <span>Bila angka <b>Permintaan dalam sesi ini</b> tetap 1 saat Anda berpindah halaman (atau kolom lokasi penyimpanan
        tidak dapat ditulis), hosting Anda tidak menyimpan sesi dengan baik. Solusinya: tambahkan
        <code>define('SESSION_DRIVER', 'mysql');</code> pada berkas <code>config.local.php</code>. Aplikasi akan membuat
        tabel <code>sessions</code> sendiri — <b>tidak perlu</b> Anda buat manual di phpMyAdmin. Tabel itu juga sudah termasuk
        bila Anda mengimpor berkas dari halaman <a href="<?= e(app_url('mysql_download')) ?>">Unduh Database MySQL</a>.</span></div>
</div>

<div class="card section">
    <div class="card-head"><h2>Ringkasan Isi Basis Data</h2>
        <div class="spacer"></div><span class="badge info"><?= count($counts) ?> tabel inti</span></div>
    <div class="grid grid-4">
        <?php foreach ($counts as $label => $n): ?>
            <div class="card" style="box-shadow:none;padding:12px 14px;">
                <div class="k-label"><?= e($label) ?></div>
                <div style="font-size:19px;font-weight:700;"><?= number_format($n, 0, ',', '.') ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <div style="font-size:11.5px;color:var(--muted);margin-top:12px;">
        Tabel relasional: users, roles, ingredient_categories, ingredients, suppliers, menus, recipes, recipe_items,
        sales, sale_items, purchases, purchase_items, inventory_movements, stock_opnames, stock_opname_items,
        waste_records, audit_logs, import_batches, settings — dengan primary key, foreign key, dan index penunjang.
    </div>
</div>

<div class="card section">
    <div class="card-head"><h2>Aturan Bisnis yang Ditegakkan Sistem</h2></div>
    <div class="table-wrap"><table class="data compact">
        <thead><tr><th>Aturan</th><th>Implementasi</th></tr></thead>
        <tbody>
            <tr><td>Setiap perubahan stok wajib memiliki catatan pergerakan stok</td>
                <td>Satu-satunya pintu perubahan stok adalah fungsi <code>apply_movement()</code> yang selalu menulis baris pada
                    <code>inventory_movements</code> (jenis, jumlah, stok sebelum/sesudah, referensi, pengguna).</td></tr>
            <tr><td>Penjualan mengurangi stok sesuai standard recipe</td>
                <td>Halaman Penjualan &amp; Impor memakai <code>recipe_requirements()</code> untuk menghitung kebutuhan bahan
                    setiap menu, lalu membuat pergerakan stok jenis "Penggunaan (Penjualan)".</td></tr>
            <tr><td>Pembelian menambah stok</td>
                <td>Modul Pembelian memperbarui stok, harga rata-rata (moving average), harga terakhir, dan histori harga.</td></tr>
            <tr><td>Waste mengurangi stok &amp; menghitung biaya</td>
                <td>Modul Waste membuat pergerakan stok dan menghitung estimasi biaya dari harga rata-rata bahan.</td></tr>
            <tr><td>Stock opname menghasilkan penyesuaian stok</td>
                <td>Selisih hanya diterapkan setelah Owner menyetujui sesi; setiap penyesuaian membuat pergerakan stok + audit.</td></tr>
            <tr><td>Tidak ada perubahan stok tanpa audit trail</td>
                <td>Form master data tidak memiliki kolom stok; penyesuaian manual Owner wajib berisi alasan dan tercatat pada audit trail.</td></tr>
            <tr><td>Takaran resep dapat dikonfigurasi</td>
                <td>Editor resep per menu dengan konversi satuan (gram/kg, ml/liter, ikat, pcs) dan perhitungan HPP langsung.</td></tr>
            <tr><td>HPP dihitung dari biaya bahan</td>
                <td>HPP/porsi = Σ (takaran resep × harga rata-rata bahan); HPP transaksi tersimpan pada <code>sale_items</code>.</td></tr>
            <tr><td>Analitik memakai data nyata</td>
                <td>Seluruh analitik dihitung dengan query agregasi dari tabel transaksi, bukan angka statis.</td></tr>
        </tbody></table></div>
</div>
