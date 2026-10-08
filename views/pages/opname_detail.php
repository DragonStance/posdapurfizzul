<?php
/** Detail stock opname: input stok fisik, pengajuan, persetujuan penyesuaian. */
$id = gi('id');
$op = q1($pdo, 'SELECT * FROM stock_opnames WHERE id = ?', [$id]);
if (!$op) {
    echo '<div class="card"><h2>Sesi stock opname tidak ditemukan</h2><a class="btn" href="' . e(app_url('opname')) . '">Kembali</a></div>';
    return;
}
$items = q($pdo, 'SELECT oi.*, i.name AS ing_name, i.code AS ing_code, i.min_stock, i.current_stock, i.avg_cost,
                         c.name AS category_name, m.moved_at AS adjusted_at
                  FROM stock_opname_items oi
                  JOIN ingredients i ON i.id = oi.ingredient_id
                  LEFT JOIN ingredient_categories c ON c.id = i.category_id
                  LEFT JOIN inventory_movements m ON m.id = oi.movement_id
                  WHERE oi.opname_id = ? ORDER BY i.name', [$id]);
$editable = in_array($op['status'], ['draft', 'diajukan'], true) && can('opname');
$nIsi = count(array_filter($items, fn($r) => $r['physical_qty'] !== null));
$nSelisih = count(array_filter($items, fn($r) => $r['diff_qty'] !== null && abs((float)$r['diff_qty']) > 0.0001));
$nilaiSelisih = array_sum(array_map(fn($r) => abs((float)$r['diff_qty']) * (float)$r['avg_cost'], $items));
$backUrl = app_url('opname_detail', ['id' => $id]);
?>
<div class="card">
    <div class="card-head">
        <h2>Sesi <?= e($op['code']) ?></h2>
        <span class="badge <?= e(opname_status_badge($op['status'])) ?>"><?= e(OPNAME_STATUS[$op['status']] ?? $op['status']) ?></span>
        <div class="spacer"></div>
        <a class="btn" href="<?= e(app_url('opname')) ?>">← Daftar opname</a>
        <button class="btn" onclick="window.print()">🖨 Cetak</button>
        <a class="btn" href="<?= e(export_base()) ?>?type=opname_items&id=<?= (int)$id ?>" target="_blank">⇩ Ekspor CSV</a>
    </div>
    <div class="grid grid-4">
        <div class="stat-line" style="flex-direction:column;align-items:flex-start;"><span>Tanggal Opname</span><b><?= tgl($op['opname_date']) ?></b></div>
        <div class="stat-line" style="flex-direction:column;align-items:flex-start;"><span>Cakupan</span><b><?= e($op['scope']) ?></b></div>
        <div class="stat-line" style="flex-direction:column;align-items:flex-start;"><span>Dibuat oleh</span><b><?= e($op['created_by_name'] ?? '-') ?></b></div>
        <div class="stat-line" style="flex-direction:column;align-items:flex-start;"><span>Disetujui oleh</span>
            <b><?= e($op['approved_by_name'] ?? '—') ?><?= $op['approved_at'] ? ' · ' . tgl($op['approved_at'], true) : '' ?></b></div>
    </div>
    <?php if ($op['note']): ?><div class="notice info" style="margin-top:12px;"><span>📝</span><span><?= e($op['note']) ?></span></div><?php endif; ?>
    <?php if ($op['approval_note']): ?><div class="notice" style="margin-top:12px;"><span>✅</span><span><b>Catatan persetujuan:</b> <?= e($op['approval_note']) ?></span></div><?php endif; ?>
</div>

<div class="grid grid-kpi section">
    <?= kpi_card('Bahan Diperiksa', (string)count($items), $nIsi . ' sudah diisi jumlah fisik', '🧂') ?>
    <?= kpi_card('Bahan Berselisih', (string)$nSelisih, 'Selisih ≠ 0 (di luar toleransi)', '⚖️', true) ?>
    <?= kpi_card('Nilai Selisih', rp($nilaiSelisih), 'Estimasi nilai penyesuaian stok', '💵') ?>
    <?= kpi_card('Status', OPNAME_STATUS[$op['status']] ?? $op['status'], $op['submitted_at'] ? 'Diajukan ' . tgl($op['submitted_at'], true) : 'Belum diajukan', '📌') ?>
</div>

<form method="post" action="<?= e(self_url()) ?>" class="section">
    <?= app_csrf_field() ?>
    <input type="hidden" name="_action" value="save_opname_items">
    <input type="hidden" name="_back" value="<?= e($backUrl) ?>">
    <input type="hidden" name="opname_id" value="<?= (int)$id ?>">
    <div class="card">
        <div class="card-head"><h2>Tabel Hitung Fisik</h2>
            <div class="spacer"></div>
            <input type="text" data-filter="#tbl-op" placeholder="🔍 Cari bahan…" style="max-width:240px;">
            <?php if ($editable): ?>
                <button class="btn btn-primary" type="submit">💾 Simpan Hasil Hitung</button>
            <?php endif; ?>
        </div>
        <div class="table-wrap table-scroll" style="max-height:600px;">
            <table class="data" id="tbl-op">
                <thead><tr><th>Bahan</th><th>Kategori</th><th class="num">Stok Sistem</th><th class="num">Stok Fisik</th>
                    <th class="num">Selisih</th><th>Alasan / Catatan Selisih</th><th class="num">Stok Sekarang</th><th class="mid">Sudah Disesuaikan</th></tr></thead>
                <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><b><?= e($it['ing_name']) ?></b><div class="li-sub"><?= e($it['ing_code']) ?> · satuan <?= e($it['unit']) ?></div></td>
                        <td><?= e($it['category_name'] ?? '-') ?></td>
                        <td class="num"><?= num($it['system_qty']) ?> <?= e($it['unit']) ?></td>
                        <td class="num" style="min-width:130px;">
                            <?php if ($editable): ?>
                                <input type="number" step="0.001" min="0" name="physical_qty[<?= (int)$it['id'] ?>]"
                                       value="<?= $it['physical_qty'] !== null ? e((string)(float)$it['physical_qty']) : '' ?>"
                                       data-system="<?= (float)$it['system_qty'] ?>" class="phys" placeholder="—">
                            <?php else: ?>
                                <?= $it['physical_qty'] !== null ? num($it['physical_qty']) : '<span class="badge muted">belum diisi</span>' ?>
                            <?php endif; ?>
                        </td>
                        <td class="num diff-cell">
                            <?php if ($it['diff_qty'] !== null): ?>
                                <span class="badge <?= abs((float)$it['diff_qty']) < 0.0001 ? 'ok' : ((float)$it['diff_qty'] < 0 ? 'danger' : 'info') ?>">
                                    <?= ((float)$it['diff_qty'] > 0 ? '+' : '') . num($it['diff_qty']) ?></span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td style="min-width:200px;">
                            <?php if ($editable): ?>
                                <input type="text" name="reason[<?= (int)$it['id'] ?>]" value="<?= e((string)($it['reason'] ?? '')) ?>"
                                       placeholder="mis. susut penimbangan / tumpah">
                            <?php else: ?><?= e($it['reason'] ?? '—') ?><?php endif; ?>
                        </td>
                        <td class="num"><?= num($it['current_stock']) ?> <?= e($it['unit']) ?></td>
                        <td class="mid"><?= $it['movement_id'] ? '<span class="badge ok">Ya</span>' : '<span class="badge muted">Belum</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!$editable): ?>
            <div class="notice" style="margin-top:12px;"><span>🔒</span><span>Sesi ini sudah final (<?= e(OPNAME_STATUS[$op['status']]) ?>)
                sehingga data hitung fisik tidak dapat diubah lagi.</span></div>
        <?php endif; ?>
    </div>
</form>

<?php if (can('opname') && $op['status'] !== 'disetujui'): ?>
<div class="card section">
    <div class="card-head"><h2>Persetujuan &amp; Penyesuaian Stok</h2></div>
    <div class="grid grid-3">
        <div class="card" style="box-shadow:none;">
            <h3>1. Ajukan Hasil</h3>
            <p style="font-size:12.5px;color:var(--muted);">Staff mengajukan hasil hitung fisik untuk diperiksa Owner.</p>
            <form method="post" action="<?= e(self_url()) ?>">
                <?= app_csrf_field() ?>
                <input type="hidden" name="_action" value="submit_opname">
                <input type="hidden" name="_back" value="<?= e($backUrl) ?>">
                <input type="hidden" name="opname_id" value="<?= (int)$id ?>">
                <button class="btn" type="submit" <?= $nIsi < count($items) ? 'disabled' : '' ?>>📤 Ajukan untuk Persetujuan</button>
            </form>
            <?php if ($nIsi < count($items)): ?>
                <div class="hint" style="margin-top:8px;color:var(--warn);">Lengkapi <?= count($items) - $nIsi ?> bahan yang belum diisi.</div>
            <?php endif; ?>
        </div>
        <?php if (can('opname_approve')): ?>
            <div class="card" style="box-shadow:none;">
                <h3>2a. Setujui (Owner)</h3>
                <p style="font-size:12.5px;color:var(--muted);">Selisih akan diterapkan sebagai <b>penyesuaian stok</b>
                    dengan catatan pergerakan stok + audit trail.</p>
                <form method="post" action="<?= e(self_url()) ?>" data-confirm-modal="Setujui stock opname <?= e($op['code']) ?> dan terapkan <?= $nSelisih ?> penyesuaian stok?">
                    <?= app_csrf_field() ?>
                    <input type="hidden" name="_action" value="approve_opname">
                    <input type="hidden" name="_back" value="<?= e($backUrl) ?>">
                    <input type="hidden" name="opname_id" value="<?= (int)$id ?>">
                    <div class="field" style="margin-bottom:10px;"><label>Catatan persetujuan</label>
                        <input type="text" name="approval_note" value="Selisih wajar, penyesuaian disetujui."></div>
                    <button class="btn btn-primary" type="submit">✅ Setujui &amp; Terapkan Penyesuaian</button>
                </form>
            </div>
            <div class="card" style="box-shadow:none;">
                <h3>2b. Tolak (Owner)</h3>
                <p style="font-size:12.5px;color:var(--muted);">Menolak tidak mengubah stok; sesi dapat diperbaiki ulang.</p>
                <form method="post" action="<?= e(self_url()) ?>">
                    <?= app_csrf_field() ?>
                    <input type="hidden" name="_action" value="reject_opname">
                    <input type="hidden" name="_back" value="<?= e($backUrl) ?>">
                    <input type="hidden" name="opname_id" value="<?= (int)$id ?>">
                    <div class="field" style="margin-bottom:10px;"><label>Alasan penolakan (wajib)</label>
                        <input type="text" name="approval_note" required placeholder="mis. hasil hitung perlu diulang"></div>
                    <button class="btn btn-danger" type="submit">Tolak Sesi Ini</button>
                </form>
            </div>
        <?php else: ?>
            <div class="card" style="box-shadow:none;">
                <h3>2. Persetujuan Owner</h3>
                <p style="font-size:12.5px;color:var(--muted);">Role Anda (<?= e(ROLES[user_role()] ?? user_role()) ?>)
                    tidak berwenang menyetujui penyesuaian stok. Penyesuaian hanya diterapkan setelah Owner/Admin menyetujui.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('input', function (e) {
    if (!e.target.classList.contains('phys')) return;
    var row = e.target.closest('tr');
    var sys = parseFloat(e.target.getAttribute('data-system')) || 0;
    var val = e.target.value === '' ? null : parseFloat(e.target.value.replace(',', '.'));
    var cell = row.querySelector('.diff-cell');
    if (val === null || isNaN(val)) { cell.innerHTML = '—'; return; }
    var diff = val - sys;
    var cls = Math.abs(diff) < 0.0001 ? 'ok' : (diff < 0 ? 'danger' : 'info');
    var txt = (diff > 0 ? '+' : '') + diff.toLocaleString('id-ID', {maximumFractionDigits: 3});
    cell.innerHTML = '<span class="badge ' + cls + '">' + txt + '</span>';
});
</script>
