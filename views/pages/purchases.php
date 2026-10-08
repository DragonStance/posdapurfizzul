<?php
/** Modul Pembelian / Bahan Masuk. */
[$from, $to] = date_range();
$supF = gi('supplier_id');
$search = (string)(g('q', '') ?? '');
$where = ['date(p.purchased_at) BETWEEN :from AND :to'];
$params = [':from' => $from, ':to' => $to];
if ($supF > 0) { $where[] = 'p.supplier_id = :sup'; $params[':sup'] = $supF; }
if ($search !== '') {
    $where[] = '(p.code LIKE :q OR p.invoice_no LIKE :q OR p.note LIKE :q OR s.name LIKE :q
                 OR EXISTS (SELECT 1 FROM purchase_items x JOIN ingredients i ON i.id = x.ingredient_id
                            WHERE x.purchase_id = p.id AND i.name LIKE :q))';
    $params[':q'] = '%' . $search . '%';
}
$w = implode(' AND ', $where);
$total = (int)qv($pdo, "SELECT COUNT(*) FROM purchases p LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE $w", $params);
[$page, $per, $offset, $pages, $total] = paginate($total, 20);
$rows = q($pdo, "SELECT p.*, s.name AS supplier_name, u.name AS petugas,
                        (SELECT COUNT(*) FROM purchase_items x WHERE x.purchase_id = p.id) AS n_item
                 FROM purchases p LEFT JOIN suppliers s ON s.id = p.supplier_id LEFT JOIN users u ON u.id = p.created_by
                 WHERE $w ORDER BY p.purchased_at DESC, p.id DESC LIMIT $per OFFSET $offset", $params);
$itemMap = [];
if ($rows) {
    $ids = array_map(fn($r) => (int)$r['id'], $rows);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    foreach (q($pdo, "SELECT pi.*, i.name AS ing_name, i.unit AS ing_unit FROM purchase_items pi
                      JOIN ingredients i ON i.id = pi.ingredient_id WHERE pi.purchase_id IN ($ph) ORDER BY i.name", $ids) as $it) {
        $itemMap[(int)$it['purchase_id']][] = $it;
    }
}
$sum = q1($pdo, "SELECT COALESCE(SUM(p.total_cost),0) AS total, COUNT(*) AS n FROM purchases p
                 LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE $w", $params);
$ingredients = q($pdo, 'SELECT i.id, i.name, i.unit, i.avg_cost, i.last_cost, i.current_stock, s.name AS supplier_name
                        FROM ingredients i LEFT JOIN suppliers s ON s.id = i.supplier_id
                        WHERE i.is_active = 1 ORDER BY i.name');
$suppliers = q($pdo, 'SELECT id, name FROM suppliers WHERE is_active = 1 ORDER BY name');
$priceChanges = q($pdo, 'SELECT pi.unit_cost, pi.qty, pi.unit, i.name AS ing_name, i.last_cost, p.purchased_at,
                               p.code, s.name AS supplier_name
                        FROM purchase_items pi JOIN purchases p ON p.id = pi.purchase_id
                        JOIN ingredients i ON i.id = pi.ingredient_id
                        LEFT JOIN suppliers s ON s.id = p.supplier_id
                        WHERE date(p.purchased_at) BETWEEN ? AND ?
                        ORDER BY p.purchased_at DESC, pi.id DESC LIMIT 12', [$from, $to]);
$backUrl = app_url('purchases', array_filter(['from' => $from, 'to' => $to, 'supplier_id' => $supF ?: null,
    'q' => $search ?: null, 'page' => $page > 1 ? $page : null]));
?>
<div class="grid grid-kpi">
    <?= kpi_card('Total Biaya Pembelian', rp($sum['total']), tgl($from) . ' – ' . tgl($to), '💳', true) ?>
    <?= kpi_card('Jumlah Nota', number_format((int)$sum['n'], 0, ',', '.'), 'Rata-rata ' . rp((int)$sum['n'] > 0 ? $sum['total'] / (int)$sum['n'] : 0) . ' / nota', '🧾') ?>
    <?= kpi_card('Bahan Aktif', number_format(count($ingredients), 0, ',', '.'), 'Siap dibeli & dicatat penerimaannya', '🧂') ?>
    <?= kpi_card('Supplier Aktif', number_format(count($suppliers), 0, ',', '.'), 'Master supplier', '🚚') ?>
</div>

<div class="card section">
    <div class="card-head">
        <h2>Histori Pembelian &amp; Penerimaan Bahan</h2>
        <div class="spacer"></div>
        <?php if (can('purchase')): ?>
            <a class="btn btn-primary" data-modal="m-purchase" href="#"><span>＋</span> Catat Pembelian</a>
        <?php endif; ?>
        <a class="btn" href="<?= e(export_base()) ?>?type=purchases&<?= e(range_params()) ?>" target="_blank">⇩ Ekspor CSV</a>
    </div>
    <form class="filter-bar" method="get" action="<?= e(self_url()) ?>" style="margin-bottom:16px;">
        <div class="field"><label>Dari</label><input type="date" name="from" value="<?= e($from) ?>"></div>
        <div class="field"><label>Sampai</label><input type="date" name="to" value="<?= e($to) ?>"></div>
        <div class="field"><label>Supplier</label><select name="supplier_id"><option value="">Semua supplier</option>
            <?php foreach (q($pdo, 'SELECT id, name FROM suppliers ORDER BY name') as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= $supF === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
            <?php endforeach; ?></select></div>
        <div class="field"><label>Cari</label><input type="text" name="q" value="<?= e($search) ?>" placeholder="Kode / nota / bahan"></div>
        <button class="btn btn-primary" type="submit">Filter</button>
        <a class="btn" href="<?= e(app_url('purchases')) ?>">Reset</a>
    </form>

    <div class="table-wrap"><table class="data">
        <thead><tr><th>Tanggal</th><th>Kode</th><th>Supplier</th><th>No. Nota</th><th class="num">Item</th>
            <th class="num">Total Biaya</th><th>Dicatat oleh</th><th class="mid">Detail</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= tgl($r['purchased_at'], true) ?></td>
                <td><b><?= e($r['code']) ?></b></td>
                <td><?= e($r['supplier_name'] ?? '-') ?></td>
                <td><?= e($r['invoice_no'] ?? '-') ?></td>
                <td class="num"><?= (int)$r['n_item'] ?></td>
                <td class="num"><b><?= rp($r['total_cost']) ?></b></td>
                <td><?= e($r['petugas'] ?? '-') ?></td>
                <td class="mid"><button class="btn btn-sm" type="button" onclick="toggleDetail(<?= (int)$r['id'] ?>)">Lihat</button></td>
            </tr>
            <tr id="pd-<?= (int)$r['id'] ?>" style="display:none;background:rgba(243,240,255,.55);">
                <td colspan="8">
                    <div style="padding:8px 4px;">
                        <b style="font-size:12.5px;">Rincian bahan <?= e($r['code']) ?></b>
                        <?php if ($r['note']): ?><div class="li-sub">Catatan: <?= e($r['note']) ?></div><?php endif; ?>
                        <div class="table-wrap" style="margin-top:8px;"><table class="data compact">
                            <thead><tr><th>Bahan</th><th class="num">Jumlah</th><th class="num">Harga Satuan</th>
                                <th class="num">Total</th><th>Kedaluwarsa</th></tr></thead>
                            <tbody>
                            <?php foreach ($itemMap[(int)$r['id']] ?? [] as $it): ?>
                                <tr><td><?= e($it['ing_name']) ?></td>
                                    <td class="num"><?= num($it['qty']) ?> <?= e($it['unit']) ?></td>
                                    <td class="num"><?= rp($it['unit_cost']) ?></td>
                                    <td class="num"><?= rp($it['total_cost']) ?></td>
                                    <td><?= $it['expiry_date'] ? tgl($it['expiry_date']) : '—' ?></td></tr>
                            <?php endforeach; ?>
                            </tbody></table></div>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="8" class="empty">Belum ada data pembelian pada filter ini.</td></tr><?php endif; ?>
        </tbody></table></div>
    <?= pager_html($page, $pages, $total) ?>
</div>

<div class="card section">
    <div class="card-head"><h2>Histori Perubahan Harga Bahan (12 terbaru)</h2>
        <div class="spacer"></div>
        <a class="btn btn-sm" href="<?= e(app_url('analytics', ['tab' => 'pembelian', 'from' => $from, 'to' => $to])) ?>">Analitik pembelian</a></div>
    <div class="table-wrap table-scroll"><table class="data compact">
        <thead><tr><th>Tanggal</th><th>Bahan</th><th>Supplier</th><th class="num">Qty Beli</th>
            <th class="num">Harga Beli</th><th class="num">Harga Rata-rata Saat Ini</th><th class="num">Selisih</th></tr></thead>
        <tbody>
        <?php foreach ($priceChanges as $c): ?>
            <?php $diff = (float)$c['unit_cost'] - (float)$c['last_cost']; ?>
            <tr><td><?= tgl($c['purchased_at']) ?></td><td><?= e($c['ing_name']) ?></td><td><?= e($c['supplier_name'] ?? '-') ?></td>
                <td class="num"><?= num($c['qty']) ?> <?= e($c['unit']) ?></td>
                <td class="num"><?= rp($c['unit_cost']) ?> <small>/<?= e($c['unit']) ?></small></td>
                <td class="num"><?= rp($c['last_cost']) ?></td>
                <td class="num" style="color:<?= $diff > 0 ? 'var(--danger)' : ($diff < 0 ? 'var(--ok)' : 'var(--muted)') ?>;">
                    <?= ($diff > 0 ? '+' : '') . rp($diff) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$priceChanges): ?><tr><td colspan="7" class="empty">Belum ada pembelian pada periode ini.</td></tr><?php endif; ?>
        </tbody></table></div>
</div>

<?php if (can('purchase')): ?>
<div class="modal-backdrop" id="m-purchase">
    <div class="modal wide">
        <form method="post" action="<?= e(self_url()) ?>">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="save_purchase">
            <input type="hidden" name="_back" value="<?= e($backUrl) ?>">
            <div class="modal-head"><h3>Catat Pembelian / Penerimaan Bahan</h3><button class="x-btn" data-close type="button">&times;</button></div>
            <div class="modal-body">
                <div class="notice info" style="margin-bottom:14px;"><span>ℹ️</span>
                    <span>Saat pembelian disimpan: <b>stok bertambah</b>, dibuat catatan pergerakan stok, histori pembelian tersimpan,
                    dan <b>histori harga bahan</b> (harga rata-rata &amp; harga terakhir) diperbarui.</span></div>
                <div class="form-grid">
                    <div class="field"><label>Tanggal / Waktu</label><input type="datetime-local" name="purchased_at" value="<?= date('Y-m-d\TH:i') ?>"></div>
                    <div class="field"><label>Supplier</label><select name="supplier_id"><option value="">— pilih supplier —</option>
                        <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Nomor Nota / Invoice</label><input type="text" name="invoice_no" placeholder="mis. NOTA-2291"></div>
                    <div class="field"><label>Catatan</label><input type="text" name="note" placeholder="mis. kiriman pagi, harga naik"></div>
                </div>
                <h3 style="margin:18px 0 10px;">Rincian Bahan Diterima</h3>
                <div class="item-rows" id="purchase-rows"></div>
                <template id="purchase-row-template">
                    <div class="item-row purchase">
                        <div class="field"><label>Bahan</label><select name="ingredient_id[]">
                            <option value="">— pilih bahan —</option>
                            <?php foreach ($ingredients as $i): ?>
                                <option value="<?= (int)$i['id'] ?>" data-unit="<?= e($i['unit']) ?>" data-cost="<?= (float)$i['last_cost'] ?>">
                                    <?= e($i['name']) ?> &middot; stok <?= num($i['current_stock']) ?> <?= e($i['unit']) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                        <div class="field"><label>Jumlah</label><input type="number" name="qty[]" step="0.001" min="0.001" value="1"></div>
                        <div class="field"><label>Satuan</label><select name="unit[]"><?= opts(unit_options(), 'kg') ?></select></div>
                        <div class="field"><label>Harga Satuan</label><input type="text" name="unit_cost[]" data-currency></div>
                        <div class="field"><label>Total</label><div class="row-total" style="padding-top:8px;">Rp 0</div></div>
                        <div class="field"><label>Kedaluwarsa</label><input type="date" name="expiry_date[]"></div>
                        <button class="rm" type="button" onclick="removeItemRow(this)" title="Hapus baris">×</button>
                    </div>
                </template>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;gap:10px;flex-wrap:wrap;">
                    <button class="btn btn-sm" type="button" onclick="addItemRow('purchase')">＋ Tambah baris</button>
                    <div>Total pembelian: <b id="purchase-total" style="font-size:18px;">Rp 0</b></div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn" data-close type="button">Batal</button>
                <button class="btn btn-primary" type="submit">Simpan &amp; Tambah Stok</button>
            </div>
        </form>
    </div>
</div>
<script>
function toggleDetail(id) {
    var r = document.getElementById('pd-' + id);
    if (r) r.style.display = (r.style.display === 'none' || !r.style.display) ? '' : 'none';
}
document.addEventListener('change', function (e) {
    if (e.target.matches('#purchase-rows select[name="ingredient_id[]"]')) {
        var opt = e.target.selectedOptions[0];
        if (opt) {
            var cost = opt.getAttribute('data-cost');
            var input = e.target.closest('.item-row').querySelector('[name="unit_cost[]"]');
            if (input && (!input.value || input.value === '0')) input.value = cost;
        }
    }
});
</script>
<?php endif; ?>
