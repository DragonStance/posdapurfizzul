<?php
/** Pusat laporan: cetak & ekspor CSV. */
[$from, $to] = date_range();
$catalog = report_catalog();
$menus = q($pdo, 'SELECT id, name FROM menus ORDER BY name');
$batches = q($pdo, 'SELECT b.*, u.name AS petugas FROM import_batches b LEFT JOIN users u ON u.id = b.created_by ORDER BY b.id DESC LIMIT 5');
?>
<div class="card">
    <div class="card-head"><h2>Periode Laporan</h2>
        <span class="badge info"><?= tgl($from) ?> – <?= tgl($to) ?></span></div>
    <?= range_form('reports') ?>
    <div style="font-size:11.5px;color:var(--muted);margin-top:10px;">
        Laporan yang tidak bergantung periode (inventory, resep, stock opname) selalu menampilkan data terkini.
    </div>
</div>

<div class="grid grid-3 section">
    <?php foreach ($catalog as $key => [$title, $desc, $perPeriod]): ?>
        <div class="card">
            <h3><?= e($title) ?></h3>
            <p style="font-size:12.5px;color:var(--muted);min-height:44px;"><?= e($desc) ?></p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <a class="btn btn-primary btn-sm" href="<?= e(app_url('report_view', ['type' => $key, 'from' => $from, 'to' => $to])) ?>">
                    🖨 Lihat &amp; Cetak</a>
                <a class="btn btn-sm" href="<?= e(export_base()) ?>?type=<?= e($key) ?>&from=<?= e($from) ?>&to=<?= e($to) ?>">⇩ CSV</a>
            </div>
            <div class="li-sub" style="margin-top:8px;"><?= $perPeriod ? 'Mengikuti rentang tanggal di atas' : 'Data terkini (tanpa filter tanggal)' ?></div>
        </div>
    <?php endforeach; ?>
</div>

<div class="grid grid-2 section">
    <div class="card">
        <div class="card-head"><h3>Ekspor Data Mentah (CSV/Excel)</h3></div>
        <p style="font-size:12.5px;color:var(--muted);">Berkas CSV dapat dibuka langsung di Microsoft Excel / Google Sheets
            (sudah menyertakan pemisah kolom dan BOM UTF-8 agar karakter Indonesia tampil benar).</p>
        <div class="table-wrap"><table class="data compact">
            <thead><tr><th>Data</th><th>Keterangan</th><th class="mid">Ekspor</th></tr></thead>
            <tbody>
                <tr><td>Transaksi penjualan</td><td>Header transaksi (per invoice)</td>
                    <td class="mid"><a class="btn btn-sm" href="<?= e(export_base()) ?>?type=sales&from=<?= e($from) ?>&to=<?= e($to) ?>">CSV</a></td></tr>
                <tr><td>Detail penjualan per menu</td><td>Rincian item setiap transaksi</td>
                    <td class="mid"><a class="btn btn-sm" href="<?= e(export_base()) ?>?type=sale_items&from=<?= e($from) ?>&to=<?= e($to) ?>">CSV</a></td></tr>
                <tr><td>Pergerakan stok</td><td>Audit trail stok</td>
                    <td class="mid"><a class="btn btn-sm" href="<?= e(export_base()) ?>?type=movements&from=<?= e($from) ?>&to=<?= e($to) ?>">CSV</a></td></tr>
                <tr><td>Pembelian (detail bahan)</td><td>Baris bahan tiap nota</td>
                    <td class="mid"><a class="btn btn-sm" href="<?= e(export_base()) ?>?type=purchases&from=<?= e($from) ?>&to=<?= e($to) ?>">CSV</a></td></tr>
                <tr><td>Waste</td><td>Catatan bahan terbuang</td>
                    <td class="mid"><a class="btn btn-sm" href="<?= e(export_base()) ?>?type=waste&from=<?= e($from) ?>&to=<?= e($to) ?>">CSV</a></td></tr>
                <tr><td>Stok bahan</td><td>Posisi stok &amp; nilai</td>
                    <td class="mid"><a class="btn btn-sm" href="<?= e(export_base()) ?>?type=inventory">CSV</a></td></tr>
                <tr><td>Profitabilitas menu</td><td>HPP, laba, margin</td>
                    <td class="mid"><a class="btn btn-sm" href="<?= e(export_base()) ?>?type=profitability&from=<?= e($from) ?>&to=<?= e($to) ?>">CSV</a></td></tr>
                <tr><td>Audit trail</td><td>Aktivitas pengguna</td>
                    <td class="mid"><a class="btn btn-sm" href="<?= e(export_base()) ?>?type=audit&from=<?= e($from) ?>&to=<?= e($to) ?>">CSV</a></td></tr>
            </tbody></table></div>
    </div>

    <div class="card">
        <div class="card-head"><h3>Laporan per Menu (Resep &amp; Profitabilitas)</h3></div>
        <form class="filter-bar" method="get" action="<?= e(self_url()) ?>" style="margin-bottom:14px;">
            <input type="hidden" name="type" value="recipes">
            <div class="field"><label>Pilih menu</label><select name="menu_id">
                <option value="0">Semua menu</option>
                <?php foreach ($menus as $m): ?><option value="<?= (int)$m['id'] ?>"><?= e($m['name']) ?></option><?php endforeach; ?>
            </select></div>
            <button class="btn btn-primary" type="submit">Tampilkan</button>
        </form>
        <h3 style="margin-top:10px;">Riwayat Impor Penjualan</h3>
        <div class="table-wrap"><table class="data compact">
            <thead><tr><th>Waktu</th><th>Berkas</th><th class="num">Berhasil</th><th class="num">Gagal</th><th class="num">Nilai</th></tr></thead>
            <tbody>
            <?php foreach ($batches as $b): ?>
                <tr><td><?= tgl($b['created_at'], true) ?></td><td><?= e($b['file_name']) ?></td>
                    <td class="num"><?= (int)$b['rows_ok'] ?></td><td class="num"><?= (int)$b['rows_failed'] ?></td>
                    <td class="num"><?= rp($b['total_value']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$batches): ?><tr><td colspan="5" class="empty">Belum ada impor.</td></tr><?php endif; ?>
            </tbody></table></div>
    </div>
</div>
