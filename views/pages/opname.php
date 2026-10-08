<?php
/** Modul Stock Opname — daftar sesi. */
$rows = q($pdo, 'SELECT o.*,
                        (SELECT COUNT(*) FROM stock_opname_items i WHERE i.opname_id = o.id) AS n_item,
                        (SELECT COUNT(*) FROM stock_opname_items i WHERE i.opname_id = o.id AND i.physical_qty IS NOT NULL) AS n_isi,
                        (SELECT COUNT(*) FROM stock_opname_items i WHERE i.opname_id = o.id AND i.diff_qty IS NOT NULL AND abs(i.diff_qty) > 0.0001) AS n_selisih,
                        (SELECT COALESCE(SUM(abs(i.diff_qty) * ing.avg_cost),0) FROM stock_opname_items i
                            JOIN ingredients ing ON ing.id = i.ingredient_id
                            WHERE i.opname_id = o.id AND i.diff_qty IS NOT NULL) AS nilai_selisih
                 FROM stock_opnames o ORDER BY o.opname_date DESC, o.id DESC LIMIT 40');
$cats = q($pdo, 'SELECT id, name FROM ingredient_categories ORDER BY name');
$nBahan = (int)qv($pdo, 'SELECT COUNT(*) FROM ingredients WHERE is_active = 1');
$pending = count(array_filter($rows, fn($r) => in_array($r['status'], ['draft', 'diajukan'], true)));
?>
<div class="grid grid-kpi">
    <?= kpi_card('Sesi Stock Opname', (string)count($rows), 'Ditampilkan 40 sesi terakhir', '📋', true) ?>
    <?= kpi_card('Menunggu Penyelesaian', (string)$pending, 'Draft / menunggu persetujuan Owner', '⏳') ?>
    <?= kpi_card('Bahan Aktif', (string)$nBahan, 'Cakupan opname "Semua Bahan"', '🧂') ?>
    <?= kpi_card('Alur Opname', 'Hitung → Ajukan → Setujui', 'Penyesuaian stok hanya setelah disetujui', '✅') ?>
</div>

<div class="notice info section" style="margin-bottom:20px;">
    <span>ℹ️</span><span>Alur stock opname: <b>1)</b> buat sesi (sistem menyimpan stok sistem sebagai acuan), <b>2)</b> staff menghitung
    stok fisik dan mengisi selisih + alasan, <b>3)</b> staff mengajukan hasil, <b>4)</b> <b>Owner/Admin menyetujui</b> —
    penyesuaian stok baru diterapkan dan tercatat pada pergerakan stok + audit trail. Pengguna tanpa hak approve tidak dapat
    mengubah stok dari modul ini.</span>
</div>

<div class="card">
    <div class="card-head"><h2>Riwayat Stock Opname</h2>
        <div class="spacer"></div>
        <?php if (can('opname')): ?>
            <a class="btn btn-primary" data-modal="m-opname" href="#">＋ Mulai Stock Opname</a>
        <?php endif; ?>
        <a class="btn" href="<?= e(export_base()) ?>?type=opname" target="_blank">⇩ Ekspor CSV</a>
    </div>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>Kode</th><th>Tanggal</th><th>Cakupan</th><th class="mid">Status</th><th class="num">Bahan</th>
            <th class="num">Terisi</th><th class="num">Berselisih</th><th class="num">Nilai Selisih</th>
            <th>Dibuat oleh</th><th>Disetujui oleh</th><th class="mid">Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><b><?= e($r['code']) ?></b><?php if ($r['note']): ?><div class="li-sub"><?= e(str_short($r['note'], 40)) ?></div><?php endif; ?></td>
                <td><?= tgl($r['opname_date']) ?></td>
                <td><?= e($r['scope']) ?></td>
                <td class="mid"><span class="badge <?= e(opname_status_badge($r['status'])) ?>"><?= e(OPNAME_STATUS[$r['status']] ?? $r['status']) ?></span></td>
                <td class="num"><?= (int)$r['n_item'] ?></td>
                <td class="num"><?= (int)$r['n_isi'] ?></td>
                <td class="num"><?= (int)$r['n_selisih'] ?></td>
                <td class="num"><?= rp($r['nilai_selisih']) ?></td>
                <td><?= e($r['created_by_name'] ?? '-') ?><div class="li-sub"><?= tgl($r['created_at'], true) ?></div></td>
                <td><?= e($r['approved_by_name'] ?? '—') ?><?php if ($r['approved_at']): ?><div class="li-sub"><?= tgl($r['approved_at'], true) ?></div><?php endif; ?></td>
                <td class="mid"><a class="btn btn-sm" href="<?= e(app_url('opname_detail', ['id' => $r['id']])) ?>">Buka</a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="11" class="empty">Belum ada sesi stock opname.</td></tr><?php endif; ?>
        </tbody></table></div>
</div>

<?php if (can('opname')): ?>
<div class="modal-backdrop" id="m-opname">
    <div class="modal">
        <form method="post" action="<?= e(self_url()) ?>">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="create_opname">
            <input type="hidden" name="_back" value="<?= e(app_url('opname')) ?>">
            <div class="modal-head"><h3>Mulai Sesi Stock Opname</h3><button class="x-btn" data-close type="button">&times;</button></div>
            <div class="modal-body">
                <div class="form-grid two">
                    <div class="field"><label>Tanggal Opname</label><input type="date" name="opname_date" value="<?= date('Y-m-d') ?>" required></div>
                    <div class="field"><label>Cakupan Bahan</label><select name="category_id">
                        <option value="0">Semua bahan aktif (<?= $nBahan ?> bahan)</option>
                        <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>">Kategori: <?= e($c['name']) ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="field span-2"><label>Catatan</label><input type="text" name="note" placeholder="mis. stock opname rutin mingguan oleh tim dapur"></div>
                </div>
                <p style="font-size:12.5px;color:var(--muted);">Stok sistem akan diambil sebagai nilai acuan saat sesi dibuat.</p>
            </div>
            <div class="modal-foot">
                <button class="btn" data-close type="button">Batal</button>
                <button class="btn btn-primary" type="submit">Buat Sesi</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
