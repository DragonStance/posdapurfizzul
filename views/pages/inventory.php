<?php
/** Modul Inventory / Stok: stok saat ini, status, kedaluwarsa, penggunaan manual & penyesuaian. */
$catF = gi('category_id');
$statusF = (string)(g('status', '') ?? '');
$search = (string)(g('q', '') ?? '');
$sortMap = ['name' => 'i.name', 'stock' => 'i.current_stock', 'value' => '(i.current_stock * i.avg_cost)', 'min' => 'i.min_stock'];
[$orderCol, $dir, $sortKey] = sort_state($sortMap, 'name', 'asc');

$where = ['1=1'];
$params = [];
if ($catF > 0) { $where[] = 'i.category_id = :cat'; $params[':cat'] = $catF; }
if ($search !== '') { $where[] = '(i.name LIKE :q OR i.code LIKE :q OR i.storage_location LIKE :q)'; $params[':q'] = '%' . $search . '%'; }
if ((int)(g('aktif', '1')) === 1) $where[] = 'i.is_active = 1';
$w = implode(' AND ', $where);

$all = q($pdo, "SELECT i.*, c.name AS category_name, s.name AS supplier_name,
                       (SELECT MAX(m.moved_at) FROM inventory_movements m WHERE m.ingredient_id = i.id) AS last_move,
                       (SELECT MIN(pi.expiry_date) FROM purchase_items pi WHERE pi.ingredient_id = i.id
                          AND pi.expiry_date IS NOT NULL AND pi.expiry_date >= " . sql_today() . ") AS next_expiry,
                       (SELECT COUNT(*) FROM inventory_movements m WHERE m.ingredient_id = i.id) AS n_move
                FROM ingredients i
                LEFT JOIN ingredient_categories c ON c.id = i.category_id
                LEFT JOIN suppliers s ON s.id = i.supplier_id
                WHERE $w ORDER BY $orderCol $dir", $params);

if ($statusF !== '') {
    $all = array_values(array_filter($all, function ($r) use ($statusF) {
        [$label] = stock_status((float)$r['current_stock'], (float)$r['min_stock']);
        return strcasecmp($label, $statusF) === 0;
    }));
}
$nilaiStok = array_sum(array_map(fn($r) => (float)$r['current_stock'] * (float)$r['avg_cost'], $all));
$counts = ['Normal' => 0, 'Stok Rendah' => 0, 'Kritis' => 0, 'Habis' => 0];
foreach ($all as $r) { $counts[stock_status((float)$r['current_stock'], (float)$r['min_stock'])[0]]++; }
$cats = q($pdo, 'SELECT id, name FROM ingredient_categories ORDER BY name');
$ingredients = q($pdo, 'SELECT id, name, unit FROM ingredients WHERE is_active = 1 ORDER BY name');
$showCost = can('costs');
$nCol = $showCost ? 10 : 8;
$backUrl = app_url('inventory', array_filter(['category_id' => $catF ?: null, 'status' => $statusF ?: null,
    'q' => $search ?: null, 'sort' => $sortKey, 'dir' => $dir]));
?>
<div class="grid grid-kpi">
    <?php if ($showCost): ?>
        <?= kpi_card('Nilai Stok (harga rata-rata)', rp($nilaiStok), count($all) . ' jenis bahan ditampilkan', '🏷️', true) ?>
    <?php else: ?>
        <?= kpi_card('Jenis Bahan Ditampilkan', (string)count($all), 'Nilai stok hanya untuk role dengan akses biaya', '🏷️', true) ?>
    <?php endif; ?>
    <?= kpi_card('Stok Rendah', (string)$counts['Stok Rendah'], 'Di bawah stok minimum', '⚠️') ?>
    <?= kpi_card('Kritis', (string)$counts['Kritis'], 'Di bawah 50% stok minimum', '🚨') ?>
    <?= kpi_card('Habis', (string)$counts['Habis'], 'Stok nol — perlu segera dibeli', '🚫') ?>
</div>

<div class="card section">
    <div class="card-head">
        <h2>Daftar Stok Bahan Baku</h2>
        <div class="spacer"></div>
        <?php if (can('manual_usage')): ?>
            <a class="btn" data-modal="m-usage" href="#">🍳 Penggunaan Manual</a>
        <?php endif; ?>
        <?php if (can('settings')): ?>
            <a class="btn btn-primary" data-modal="m-adjust" href="#">⚖️ Penyesuaian Stok (Owner)</a>
        <?php endif; ?>
        <a class="btn" href="<?= e(export_base()) ?>?type=inventory" target="_blank">⇩ Ekspor CSV</a>
    </div>

    <form class="filter-bar" method="get" action="<?= e(self_url()) ?>" style="margin-bottom:16px;">
        <div class="field"><label>Kategori</label><select name="category_id"><option value="">Semua kategori</option>
            <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $catF === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="field"><label>Status stok</label><select name="status"><option value="">Semua status</option>
            <?= opts(['Normal', 'Stok Rendah', 'Kritis', 'Habis'], $statusF) ?></select></div>
        <div class="field"><label>Cari bahan</label><input type="text" name="q" value="<?= e($search) ?>" placeholder="nama / kode / lokasi"></div>
        <div class="field"><label>Tampilkan</label><select name="aktif"><option value="1">Hanya bahan aktif</option>
            <option value="0" <?= (int)(g('aktif', '1')) === 0 ? 'selected' : '' ?>>Termasuk nonaktif</option></select></div>
        <button class="btn btn-primary" type="submit">Filter</button>
        <a class="btn" href="<?= e(app_url('inventory')) ?>">Reset</a>
    </form>

    <div class="table-wrap">
        <table class="data" id="tbl-inv">
            <thead><tr>
                <th><?= sort_link('Bahan', 'name', $sortMap) ?></th>
                <th>Kategori</th>
                <th><?= sort_link('Stok Saat Ini', 'stock', $sortMap) ?></th>
                <th><?= sort_link('Stok Min.', 'min', $sortMap) ?></th>
                <th class="mid">Status</th>
                <?php if ($showCost): ?>
                <th class="num">Harga Rata-rata</th>
                <th class="num"><?= sort_link('Nilai Stok', 'value', $sortMap) ?></th>
                <?php endif; ?>
                <th>Kedaluwarsa Terdekat</th>
                <th>Pergerakan Terakhir</th>
                <th class="mid">Aksi</th>
            </tr></thead>
            <tbody>
            <?php foreach ($all as $r):
                $exp = $r['next_expiry'] ? strtotime($r['next_expiry']) : null;
                $daysLeft = $exp ? (int)floor(($exp - strtotime(today_str())) / 86400) : null;
            ?>
                <tr>
                    <td><b><?= e($r['name']) ?></b>
                        <div class="li-sub"><?= e($r['code']) ?> · satuan <?= e(unit_label($r['unit'])) ?>
                            <?= $r['storage_location'] ? '· ' . e($r['storage_location']) : '' ?></div></td>
                    <td><?= e($r['category_name'] ?? '-') ?><div class="li-sub"><?= e($r['supplier_name'] ?? 'tanpa supplier') ?></div></td>
                    <td class="num"><b style="font-size:14px;"><?= num($r['current_stock']) ?></b> <?= e($r['unit']) ?></td>
                    <td class="num"><?= num($r['min_stock']) ?> <?= e($r['unit']) ?></td>
                    <td class="mid"><?= stock_badge((float)$r['current_stock'], (float)$r['min_stock']) ?></td>
                    <?php if ($showCost): ?>
                    <td class="num"><?= rp($r['avg_cost']) ?><div class="li-sub">terakhir <?= rp($r['last_cost']) ?></div></td>
                    <td class="num"><?= rp((float)$r['current_stock'] * (float)$r['avg_cost']) ?></td>
                    <?php endif; ?>
                    <td>
                        <?php if ($daysLeft === null): ?><span class="badge muted">Tidak berkedaluwarsa</span>
                        <?php else: ?>
                            <span class="badge <?= $daysLeft <= 3 ? 'danger' : ($daysLeft <= EXPIRY_WARNING_DAYS ? 'warn' : 'ok') ?>">
                                <?= tgl($r['next_expiry']) ?> · <?= $daysLeft ?> hari</span>
                        <?php endif; ?>
                        <div class="li-sub"><?= e($r['expiry_info'] ?? '') ?></div>
                    </td>
                    <td><?= $r['last_move'] ? tgl($r['last_move'], true) : '—' ?>
                        <div class="li-sub"><a href="<?= e(app_url('movements', ['ingredient_id' => $r['id']])) ?>"><?= (int)$r['n_move'] ?> pergerakan</a></div></td>
                    <td class="mid"><div style="display:flex;gap:6px;justify-content:center;">
                        <?php if (can('manual_usage')): ?>
                            <button class="btn btn-sm" type="button" data-usage="<?= (int)$r['id'] ?>"
                                    data-name="<?= e($r['name']) ?>" data-unit="<?= e($r['unit']) ?>"
                                    data-stock="<?= e(num($r['current_stock'])) ?>">Pakai</button>
                        <?php endif; ?>
                        <button class="btn btn-sm" type="button" data-adjust="<?= (int)$r['id'] ?>"
                                data-name="<?= e($r['name']) ?>" data-unit="<?= e($r['unit']) ?>"
                                data-stock="<?= e((string)(float)$r['current_stock']) ?>">Sesuaikan</button>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$all): ?><tr><td colspan="<?= $nCol ?>" class="empty">Tidak ada bahan pada filter ini.</td></tr><?php endif; ?>
            </tbody></table>
    </div>
    <div style="font-size:12px;color:var(--muted);margin-top:10px;">
        Stok hanya dapat berubah melalui <b>pergerakan stok</b> (pembelian, penjualan, waste, stock opname, penggunaan manual,
        atau penyesuaian oleh Owner). Tidak ada perubahan stok langsung tanpa jejak audit.
    </div>
</div>

<?php if (can('manual_usage')): ?>
<div class="modal-backdrop" id="m-usage">
    <div class="modal">
        <form method="post" action="<?= e(self_url()) ?>">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="manual_usage">
            <input type="hidden" name="_back" value="<?= e($backUrl) ?>">
            <div class="modal-head"><h3>Catat Penggunaan Bahan Manual</h3><button class="x-btn" data-close type="button">&times;</button></div>
            <div class="modal-body">
                <div class="notice info" style="margin-bottom:14px;"><span>ℹ️</span>
                    <span>Untuk pemakaian bahan di luar penjualan (uji rasa, persiapan katering, makan karyawan).
                    Stok akan berkurang dan tercatat sebagai <b>Penggunaan Manual</b>.</span></div>
                <div class="form-grid">
                    <div class="field"><label>Bahan</label><select name="ingredient_id" id="usage-ing" required>
                        <option value="">— pilih bahan —</option>
                        <?php foreach ($ingredients as $i): ?><option value="<?= (int)$i['id'] ?>"><?= e($i['name']) ?> (<?= e($i['unit']) ?>)</option><?php endforeach; ?>
                    </select></div>
                    <div class="field"><label>Jumlah</label><input type="number" name="qty" step="0.001" min="0.001" required></div>
                    <div class="field"><label>Satuan</label><input type="text" id="usage-unit" readonly placeholder="satuan bahan"></div>
                    <div class="field span-3"><label>Keterangan <span class="hint">(wajib — masuk audit trail)</span></label>
                        <input type="text" name="note" required placeholder="mis. persiapan pesanan katering 50 porsi"></div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn" data-close type="button">Batal</button>
                <button class="btn btn-primary" type="submit">Simpan Penggunaan</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (can('settings')): ?>
<div class="modal-backdrop" id="m-adjust">
    <div class="modal">
        <form method="post" action="<?= e(self_url()) ?>">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="adjust_stock">
            <input type="hidden" name="_back" value="<?= e($backUrl) ?>">
            <div class="modal-head"><h3>Penyesuaian Stok (Owner/Admin)</h3><button class="x-btn" data-close type="button">&times;</button></div>
            <div class="modal-body">
                <div class="notice" style="border-color:rgba(217,119,6,.35);background:rgba(255,249,235,.85);color:#7c4a03;margin-bottom:14px;">
                    <span>⚠️</span><span>Gunakan hanya untuk koreksi data. Setiap penyesuaian <b>wajib</b> memiliki alasan dan akan
                    tercatat sebagai pergerakan stok <b>Penyesuaian Stok</b> + audit trail. Untuk penyesuaian rutin,
                    gunakan modul <b>Stock Opname</b>.</span></div>
                <div class="form-grid two">
                    <div class="field span-2"><label>Bahan</label><select name="ingredient_id" id="adj-ing" required>
                        <option value="">— pilih bahan —</option>
                        <?php foreach ($ingredients as $i): ?><option value="<?= (int)$i['id'] ?>"><?= e($i['name']) ?> (<?= e($i['unit']) ?>)</option><?php endforeach; ?>
                    </select></div>
                    <div class="field"><label>Mode</label><select name="mode">
                        <option value="set">Tetapkan stok baru (nilai akhir)</option>
                        <option value="delta">Tambah / kurangi (selisih)</option></select></div>
                    <div class="field"><label>Jumlah (satuan bahan)</label><input type="number" name="qty" step="0.001" required>
                        <span class="hint" id="adj-hint"></span></div>
                    <div class="field span-2"><label>Alasan penyesuaian <span class="hint">(wajib)</span></label>
                        <input type="text" name="reason" required placeholder="mis. koreksi salah input pembelian 11 Sep"></div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn" data-close type="button">Batal</button>
                <button class="btn btn-primary" type="submit">Simpan Penyesuaian</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
function fillUnit(sel, target) {
    var o = sel.selectedOptions[0];
    var u = o ? (o.textContent.match(/\(([^)]+)\)$/) || [])[1] : '';
    if (target) target.value = u || '';
}
document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-usage],[data-adjust]');
    if (!b) return;
    if (b.hasAttribute('data-usage')) {
        document.getElementById('usage-ing').value = b.getAttribute('data-usage');
        document.getElementById('usage-unit').value = b.getAttribute('data-unit');
        document.getElementById('usage-ing').closest('form').querySelector('[name=qty]').value = '';
        if (window.openModal) window.openModal('m-usage');
    } else {
        document.getElementById('adj-ing').value = b.getAttribute('data-adjust');
        document.getElementById('adj-hint').textContent = 'Stok saat ini: ' + b.getAttribute('data-stock') + ' ' + b.getAttribute('data-unit');
        var form = document.getElementById('adj-ing').closest('form');
        form.querySelector('[name=qty]').value = '';
        form.querySelector('[name=reason]').value = '';
        if (window.openModal) window.openModal('m-adjust');
    }
});
</script>
