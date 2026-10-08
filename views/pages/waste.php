<?php
/** Modul Waste / Bahan Terbuang. */
[$from, $to] = date_range();
$reasonF = (string)(g('reason', '') ?? '');
$ingF = gi('ingredient_id');
$search = (string)(g('q', '') ?? '');
$where = ['date(w.wasted_at) BETWEEN :from AND :to'];
$params = [':from' => $from, ':to' => $to];
if ($reasonF !== '') { $where[] = 'w.reason = :rs'; $params[':rs'] = $reasonF; }
if ($ingF > 0) { $where[] = 'w.ingredient_id = :ing'; $params[':ing'] = $ingF; }
if ($search !== '') { $where[] = '(i.name LIKE :q OR w.code LIKE :q OR w.note LIKE :q OR w.reason LIKE :q)'; $params[':q'] = '%' . $search . '%'; }
$w = implode(' AND ', $where);

$total = (int)qv($pdo, "SELECT COUNT(*) FROM waste_records w JOIN ingredients i ON i.id = w.ingredient_id WHERE $w", $params);
[$page, $per, $offset, $pages, $total] = paginate($total, 25);
$rows = q($pdo, "SELECT w.*, i.name AS ing_name, i.unit AS ing_unit FROM waste_records w
                 JOIN ingredients i ON i.id = w.ingredient_id
                 WHERE $w ORDER BY w.wasted_at DESC, w.id DESC LIMIT $per OFFSET $offset", $params);
$sum = q1($pdo, "SELECT COALESCE(SUM(w.est_cost),0) AS cost, COUNT(*) AS n FROM waste_records w
                 JOIN ingredients i ON i.id = w.ingredient_id WHERE $w AND w.status = 'confirmed'", $params);
$byReason = a_waste_by_reason($pdo, $from, $to);
$byIng = a_waste_by_ingredient($pdo, $from, $to);
$daily = a_waste_daily($pdo, $from, $to);
$hppPeriod = (float)qv($pdo, 'SELECT COALESCE(SUM(hpp_total),0) FROM sales WHERE status = "selesai" AND date(sold_at) BETWEEN ? AND ?', [$from, $to], 0);
$ingredients = q($pdo, 'SELECT id, name, unit, current_stock, avg_cost FROM ingredients WHERE is_active = 1 ORDER BY name');
$backUrl = app_url('waste', array_filter(['from' => $from, 'to' => $to, 'reason' => $reasonF ?: null,
    'ingredient_id' => $ingF ?: null, 'q' => $search ?: null, 'page' => $page > 1 ? $page : null]));
?>
<div class="grid grid-kpi">
    <?= kpi_card('Total Biaya Waste', rp($sum['cost']), tgl($from) . ' – ' . tgl($to), '🗑️', true) ?>
    <?= kpi_card('Jumlah Catatan', number_format((int)$sum['n'], 0, ',', '.'), 'Hanya catatan berstatus confirmed', '🧾') ?>
    <?= kpi_card('Rasio terhadap HPP', pct($hppPeriod > 0 ? (float)$sum['cost'] / $hppPeriod * 100 : 0),
        'Estimasi HPP periode ' . rp($hppPeriod), '📉') ?>
    <?= kpi_card('Alasan Terbanyak', $byReason ? e($byReason[0]['reason']) : '—',
        $byReason ? rp($byReason[0]['cost']) . ' dari ' . (int)$byReason[0]['n'] . ' catatan' : 'Belum ada data', '❓') ?>
</div>

<div class="card section">
    <div class="card-head"><h2>Pencatatan Waste</h2>
        <div class="spacer"></div>
        <?php if (can('waste')): ?>
            <a class="btn btn-primary" data-modal="m-waste" href="#"><span>＋</span> Catat Waste</a>
        <?php endif; ?>
        <a class="btn" href="<?= e(export_base()) ?>?type=waste&from=<?= e($from) ?>&to=<?= e($to) ?>">⇩ Ekspor CSV</a>
    </div>
    <form class="filter-bar" method="get" action="<?= e(self_url()) ?>" style="margin-bottom:16px;">
        <div class="field"><label>Dari</label><input type="date" name="from" value="<?= e($from) ?>"></div>
        <div class="field"><label>Sampai</label><input type="date" name="to" value="<?= e($to) ?>"></div>
        <div class="field"><label>Alasan</label><select name="reason"><option value="">Semua alasan</option><?= opts(WASTE_REASONS, $reasonF) ?></select></div>
        <div class="field"><label>Bahan</label><select name="ingredient_id"><option value="">Semua bahan</option>
            <?php foreach (q($pdo, 'SELECT id, name FROM ingredients ORDER BY name') as $i): ?>
                <option value="<?= (int)$i['id'] ?>" <?= $ingF === (int)$i['id'] ? 'selected' : '' ?>><?= e($i['name']) ?></option>
            <?php endforeach; ?></select></div>
        <div class="field"><label>Cari</label><input type="text" name="q" value="<?= e($search) ?>" placeholder="kode / catatan"></div>
        <button class="btn btn-primary" type="submit">Filter</button>
        <a class="btn" href="<?= e(app_url('waste')) ?>">Reset</a>
    </form>

    <div class="table-wrap"><table class="data">
        <thead><tr><th>Waktu</th><th>Kode</th><th>Bahan</th><th class="num">Jumlah</th><th class="mid">Alasan</th>
            <th class="num">Estimasi Biaya</th><th>Catatan</th><th>Dicatat oleh</th><th class="mid">Status</th><th class="mid">Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= tgl($r['wasted_at'], true) ?></td>
                <td><b><?= e($r['code']) ?></b></td>
                <td><?= e($r['ing_name']) ?></td>
                <td class="num"><b style="color:var(--danger);">-<?= num($r['qty']) ?></b> <?= e($r['unit']) ?></td>
                <td class="mid"><span class="badge warn"><?= e($r['reason']) ?></span></td>
                <td class="num"><?= rp($r['est_cost']) ?></td>
                <td style="max-width:220px;"><?= e(str_short((string)$r['note'], 46)) ?></td>
                <td><?= e($r['created_by_name'] ?? '-') ?></td>
                <td class="mid"><?= $r['status'] === 'void'
                    ? '<span class="badge danger">Dibatalkan</span>'
                    : '<span class="badge ok">Tercatat</span>' ?>
                    <?php if ($r['status'] === 'void' && $r['void_reason']): ?><div class="li-sub"><?= e(str_short($r['void_reason'], 30)) ?></div><?php endif; ?></td>
                <td class="mid">
                    <?php if (can('waste_void') && $r['status'] !== 'void'): ?>
                        <button class="btn btn-sm" type="button" data-void-waste="<?= (int)$r['id'] ?>" data-code="<?= e($r['code']) ?>">Batalkan</button>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" class="empty">Belum ada catatan waste pada filter ini.</td></tr><?php endif; ?>
        </tbody></table></div>
    <?= pager_html($page, $pages, $total) ?>
</div>

<div class="grid grid-2 section">
    <div class="card">
        <div class="card-head"><h3>Waste Berdasarkan Alasan</h3></div>
        <?= chart_box('chWasteReason', [
            'type' => 'doughnut', 'format' => 'rp',
            'labels' => array_column($byReason, 'reason'),
            'datasets' => [['label' => 'Biaya', 'data' => array_map(fn($r) => (float)$r['cost'], $byReason)]],
        ], 'short') ?>
        <div class="table-wrap" style="margin-top:12px;"><table class="data compact">
            <thead><tr><th>Alasan</th><th class="num">Catatan</th><th class="num">Volume</th><th class="num">Biaya</th></tr></thead>
            <tbody>
            <?php foreach ($byReason as $r): ?>
                <tr><td><?= e($r['reason']) ?></td><td class="num"><?= (int)$r['n'] ?></td>
                    <td class="num"><?= num($r['qty']) ?></td><td class="num"><?= rp($r['cost']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$byReason): ?><tr><td colspan="4" class="empty">Belum ada data.</td></tr><?php endif; ?>
            </tbody></table></div>
    </div>
    <div class="card">
        <div class="card-head"><h3>Tren Biaya Waste</h3></div>
        <?= chart_box('chWasteTrend', [
            'type' => 'bar', 'format' => 'rp',
            'labels' => array_map(fn($r) => date('d/m', strtotime($r['d'])), $daily),
            'datasets' => [['label' => 'Biaya Waste', 'data' => array_map(fn($r) => (float)$r['cost'], $daily)]],
        ], 'short') ?>
        <div class="table-wrap table-scroll" style="margin-top:12px;max-height:220px;"><table class="data compact">
            <thead><tr><th>Bahan</th><th class="num">Volume</th><th class="num">Biaya</th><th class="num">Catatan</th></tr></thead>
            <tbody>
            <?php foreach ($byIng as $r): ?>
                <tr><td><?= e($r['name']) ?></td><td class="num"><?= num($r['qty']) ?> <?= e($r['unit']) ?></td>
                    <td class="num"><?= rp($r['cost']) ?></td><td class="num"><?= (int)$r['n'] ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
    </div>
</div>

<?php if (can('waste')): ?>
<div class="modal-backdrop" id="m-waste">
    <div class="modal">
        <form method="post" action="<?= e(self_url()) ?>">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="save_waste">
            <input type="hidden" name="_back" value="<?= e($backUrl) ?>">
            <div class="modal-head"><h3>Catat Bahan Terbuang (Waste)</h3><button class="x-btn" data-close type="button">&times;</button></div>
            <div class="modal-body">
                <div class="notice" style="border-color:rgba(220,38,38,.3);background:rgba(254,242,242,.8);color:#991b1b;margin-bottom:14px;">
                    <span>ℹ️</span><span>Saat disimpan: <b>stok berkurang</b>, catatan pergerakan stok dibuat dengan jenis <b>Waste</b>,
                    dan <b>biaya waste</b> dihitung dari harga rata-rata bahan.</span></div>
                <div class="form-grid">
                    <div class="field"><label>Tanggal / Waktu</label><input type="datetime-local" name="wasted_at" value="<?= date('Y-m-d\TH:i') ?>"></div>
                    <div class="field"><label>Bahan</label><select name="ingredient_id" id="w-ing" required>
                        <option value="">— pilih bahan —</option>
                        <?php foreach ($ingredients as $i): ?>
                            <option value="<?= (int)$i['id'] ?>" data-unit="<?= e($i['unit']) ?>" data-cost="<?= (float)$i['avg_cost'] ?>"
                                    data-stock="<?= num($i['current_stock'], 4) ?>"><?= e($i['name']) ?> · stok <?= num($i['current_stock']) ?> <?= e($i['unit']) ?></option>
                        <?php endforeach; ?>
                    </select></div>
                    <div class="field"><label>Jumlah</label><input type="number" name="qty" step="0.001" min="0.001" required id="w-qty">
                        <span class="hint" id="w-hint"></span></div>
                    <div class="field"><label>Alasan</label><select name="reason" required><?= opts(WASTE_REASONS) ?></select></div>
                    <div class="field span-2"><label>Catatan</label><input type="text" name="note" placeholder="mis. ikan sisa display sore tidak layak jual"></div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn" data-close type="button">Batal</button>
                <button class="btn btn-primary" type="submit">Simpan Waste &amp; Kurangi Stok</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (can('waste_void')): ?>
<div class="modal-backdrop" id="m-void-waste">
    <div class="modal">
        <form method="post" action="<?= e(self_url()) ?>">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="void_waste">
            <input type="hidden" name="_back" value="<?= e($backUrl) ?>">
            <input type="hidden" name="id" id="vw-id">
            <div class="modal-head"><h3>Batalkan Catatan Waste</h3><button class="x-btn" data-close type="button">&times;</button></div>
            <div class="modal-body">
                <p style="font-size:13px;">Catatan <b id="vw-code"></b> akan dibatalkan. Stok bahan dikembalikan melalui
                    pergerakan stok jenis <b>Penyesuaian Stok</b> dan tercatat pada audit trail.</p>
                <div class="field"><label>Alasan pembatalan</label><input type="text" name="reason" required></div>
            </div>
            <div class="modal-foot">
                <button class="btn" data-close type="button">Batal</button>
                <button class="btn btn-danger" type="submit">Ya, Batalkan</button>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-void-waste]');
    if (!b) return;
    document.getElementById('vw-id').value = b.getAttribute('data-void-waste');
    document.getElementById('vw-code').textContent = b.getAttribute('data-code');
    if (window.openModal) window.openModal('m-void-waste');
});
</script>
<?php endif; ?>

<script>
document.addEventListener('change', function (e) {
    if (e.target.id !== 'w-ing') return;
    var o = e.target.selectedOptions[0];
    var hint = document.getElementById('w-hint');
    if (o && o.value) {
        hint.textContent = 'Satuan: ' + o.getAttribute('data-unit') + ' · stok tersedia ' + o.getAttribute('data-stock')
            + ' · harga ' + 'Rp ' + Math.round(parseFloat(o.getAttribute('data-cost'))).toLocaleString('id-ID')
            + ' — tulis jumlah sesuai kondisi nyata, tidak boleh melebihi stok tersedia.';
    } else { hint.textContent = ''; }
});
</script>
