<?php
/** Impor penjualan dari CSV/Excel: unggah → pratinjau → konfirmasi. */
$preview = $_SESSION['import_preview'] ?? null;
$menus = q($pdo, 'SELECT name, code, price FROM menus ORDER BY name');
$batches = q($pdo, 'SELECT b.*, u.name AS petugas FROM import_batches b LEFT JOIN users u ON u.id = b.created_by
                    ORDER BY b.id DESC LIMIT 8');
$valid = $preview ? array_values(array_filter($preview['rows'], fn($r) => $r['error'] === null)) : [];
$invalid = $preview ? array_values(array_filter($preview['rows'], fn($r) => $r['error'] !== null)) : [];
$totalValid = array_sum(array_map(fn($r) => $r['qty'] * $r['unit_price'], $valid));
?>
<div class="notice info" style="margin-bottom:20px;">
    <span>ℹ️</span>
    <span>Fitur ini menggantikan kebutuhan integrasi API marketplace: data penjualan dari marketplace/kanal online yang
    diekspor dalam bentuk <b>CSV atau Excel (.xlsx)</b> dapat diunggah, diperiksa, lalu disimpan. Setiap baris yang
    tersimpan otomatis menjalankan alur <b>penjualan → resep → pengurangan stok → analitik</b>.</span>
</div>

<div class="grid grid-2">
    <div class="card">
        <div class="card-head"><h2>1. Unggah Berkas</h2></div>
        <?php if ($preview): ?>
            <div class="notice" style="border-color:rgba(217,119,6,.35);background:rgba(255,249,235,.85);color:#7c4a03;margin-bottom:14px;">
                <span>📄</span><span>Pratinjau dari <b><?= e($preview['file']) ?></b> (diunggah <?= tgl($preview['at'], true) ?>)
                &middot; <?= count($preview['rows']) ?> baris terbaca</span>
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <form method="post" action="<?= e(self_url()) ?>">
                    <?= app_csrf_field() ?>
                    <input type="hidden" name="_action" value="import_commit">
                    <input type="hidden" name="_back" value="<?= e(app_url('sales_import')) ?>">
                    <button class="btn btn-primary" type="submit" <?= $valid ? '' : 'disabled' ?>>
                        ✔ Simpan <?= count($valid) ?> transaksi valid (<?= rp($totalValid) ?>)</button>
                </form>
                <form method="post" action="<?= e(self_url()) ?>">
                    <?= app_csrf_field() ?>
                    <input type="hidden" name="_action" value="import_cancel">
                    <input type="hidden" name="_back" value="<?= e(app_url('sales_import')) ?>">
                    <button class="btn" type="submit">Batalkan pratinjau</button>
                </form>
            </div>
        <?php endif; ?>
        <form method="post" action="<?= e(self_url()) ?>" enctype="multipart/form-data" style="margin-top:14px;" class="form-grid two">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="import_preview">
            <input type="hidden" name="_back" value="<?= e(app_url('sales_import')) ?>">
            <div class="field span-2"><label>Berkas CSV / Excel</label><input type="file" name="file" accept=".csv,.xlsx" required>
                <span class="hint">Format didukung: .csv (pemisah koma atau titik-koma) dan .xlsx (sheet pertama).</span></div>
            <div class="field"><label>Channel default (bila kolom channel kosong)</label><select name="channel_default"><?= opts(CHANNELS, 'Marketplace') ?></select></div>
            <div class="field"><label>Tanggal default (bila kolom tanggal kosong)</label><input type="date" name="default_date" value="<?= date('Y-m-d') ?>"></div>
            <div class="field span-2"><button class="btn btn-primary" type="submit">⬆ Unggah &amp; Pratinjau</button></div>
        </form>
        <div style="margin-top:10px;">
            <a class="btn btn-sm" href="<?= e(export_base()) ?>?type=template_sales">⇩ Unduh Template CSV</a>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>2. Panduan Kolom</h2></div>
        <p style="font-size:12.5px;color:var(--muted);">Kolom <b>menu</b> dan <b>qty/jumlah</b> wajib ada. Kolom lain opsional
        (nama kolom boleh dalam bahasa Indonesia atau Inggris).</p>
        <div class="table-wrap"><table class="data compact">
            <thead><tr><th>Kolom</th><th>Alternatif nama</th><th>Wajib</th></tr></thead>
            <tbody>
                <tr><td>menu</td><td>nama menu, produk, item</td><td>Ya</td></tr>
                <tr><td>qty</td><td>jumlah, quantity, terjual</td><td>Ya</td></tr>
                <tr><td>tanggal</td><td>tgl, date, waktu, datetime</td><td>Tidak</td></tr>
                <tr><td>harga</td><td>harga jual, price, unit price</td><td>Tidak (pakai harga menu)</td></tr>
                <tr><td>channel</td><td>kanal, sumber, platform</td><td>Tidak</td></tr>
                <tr><td>catatan</td><td>note, keterangan</td><td>Tidak</td></tr>
            </tbody></table></div>
        <p style="font-size:12.5px;color:var(--muted);margin-top:10px;">Contoh isi:</p>
        <pre style="background:rgba(243,240,255,.8);padding:10px;border-radius:10px;font-size:12px;overflow:auto;">tanggal,menu,qty,harga,channel,catatan
<?= date('d/m/Y') ?>,<?= e($menus[0]['name'] ?? 'Ikan Kuah Kuning Kenari') ?>,2,,Marketplace,pesanan GoFood
<?= date('d/m/Y') ?>,<?= e($menus[1]['name'] ?? 'Papeda') ?>,1,18000,Online,</pre>
        <div style="font-size:12px;color:var(--muted);margin-top:8px;">Menu yang cocok: <b><?= e(implode(' · ', array_column($menus, 'name'))) ?></b></div>
    </div>
</div>

<?php if ($preview): ?>
<div class="card section">
    <div class="card-head"><h2>3. Pratinjau Impor</h2>
        <span class="badge ok"><?= count($valid) ?> valid</span>
        <span class="badge <?= $invalid ? 'danger' : 'muted' ?>"><?= count($invalid) ?> bermasalah</span>
        <div class="spacer"></div>
        <input type="text" data-filter="#tbl-preview" placeholder="🔍 Cari pada pratinjau…" style="max-width:260px;">
    </div>
    <div class="table-wrap table-scroll" style="max-height:520px;"><table class="data compact" id="tbl-preview">
        <thead><tr><th>Baris</th><th>Tanggal</th><th>Menu (berkas)</th><th>Menu (sistem)</th><th class="num">Qty</th>
            <th class="num">Harga</th><th class="num">Subtotal</th><th>Channel</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($preview['rows'] as $r): ?>
            <tr style="<?= $r['error'] ? 'background:rgba(254,242,242,.6);' : '' ?>">
                <td><?= (int)$r['line'] ?></td>
                <td><?= tgl($r['sold_at'], true) ?></td>
                <td><?= e($r['menu_raw']) ?></td>
                <td><?= e($r['menu_name'] ?? '—') ?></td>
                <td class="num"><?= num($r['qty']) ?></td>
                <td class="num"><?= rp($r['unit_price']) ?></td>
                <td class="num"><?= rp($r['qty'] * $r['unit_price']) ?></td>
                <td><?= e($r['channel']) ?></td>
                <td><?= $r['error'] ? '<span class="badge danger">' . e($r['error']) . '</span>' : '<span class="badge ok">Siap disimpan</span>' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div style="font-size:12px;color:var(--muted);margin-top:10px;">
        Baris bermasalah (menu tidak terdaftar / jumlah tidak valid) <b>tidak akan</b> disimpan. Perbaiki berkas atau
        tambahkan menunya lebih dulu pada menu Master Data → Menu.
    </div>
</div>
<?php endif; ?>

<div class="card section">
    <div class="card-head"><h2>Riwayat Impor</h2></div>
    <div class="table-wrap"><table class="data compact">
        <thead><tr><th>Waktu</th><th>Berkas</th><th>Petugas</th><th class="num">Baris</th><th class="num">Berhasil</th>
            <th class="num">Gagal</th><th class="num">Nilai</th></tr></thead>
        <tbody>
        <?php foreach ($batches as $b): ?>
            <tr><td><?= tgl($b['created_at'], true) ?></td><td><?= e($b['file_name']) ?></td><td><?= e($b['petugas'] ?? '-') ?></td>
                <td class="num"><?= (int)$b['rows_total'] ?></td><td class="num"><?= (int)$b['rows_ok'] ?></td>
                <td class="num"><?= (int)$b['rows_failed'] ?></td><td class="num"><?= rp($b['total_value']) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$batches): ?><tr><td colspan="7" class="empty">Belum ada aktivitas impor.</td></tr><?php endif; ?>
        </tbody></table></div>
</div>
