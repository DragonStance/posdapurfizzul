<?php
/** Editor standard recipe (BOM) satu menu. */
$menuId = gi('menu') ?: gi('id');   // URL rapi: /resep/?menu=<id menu>
$menu = q1($pdo, 'SELECT * FROM menus WHERE id = ?', [$menuId]);
if (!$menu) {
    echo '<div class="card"><h2>Menu tidak ditemukan</h2><a class="btn" href="' . e(app_url('recipes')) . '">Kembali</a></div>';
    return;
}
$recipe = q1($pdo, 'SELECT * FROM recipes WHERE menu_id = ?', [$menuId]);
$items = recipe_rows($pdo, $menuId);
$detail = menu_hpp_detail($pdo, $menuId);
$ingredients = q($pdo, 'SELECT i.id, i.name, i.unit, i.avg_cost, i.current_stock, i.is_active, c.name AS category_name
                        FROM ingredients i LEFT JOIN ingredient_categories c ON c.id = i.category_id
                        ORDER BY i.name');
$backUrl = app_url('recipe_edit', ['id' => $menuId]);
$jsUnits = [];
foreach (UNITS as $code => $u) { $jsUnits[$code] = [$u['dim'], (float)$u['factor']]; }
?>
<div class="card">
    <div class="card-head">
        <h2>Resep: <?= e($menu['name']) ?></h2>
        <span class="badge muted"><?= e($menu['code']) ?></span>
        <span class="badge info"><?= e($menu['category']) ?></span>
        <span class="badge ok">Harga jual <?= rp($menu['price']) ?></span>
        <div class="spacer"></div>
        <a class="btn" href="<?= e(app_url('recipes')) ?>">← Daftar resep</a>
        <?php if (can('recipes') && $items): ?>
            <button class="btn btn-danger" type="button" data-del data-action="delete_recipe"
                    data-id="<?= (int)$menuId ?>" data-name="<?= e($menu['name']) ?>"
                    data-stock="<?= count($items) ?> baris bahan &middot; HPP saat ini <?= rp($detail['total']) ?>/porsi">Hapus Resep</button>
        <?php endif; ?>
    </div>
    <div class="grid grid-4">
        <div class="stat-line" style="flex-direction:column;align-items:flex-start;"><span>Jumlah bahan</span>
            <b><?= count($items) ?> bahan</b></div>
        <div class="stat-line" style="flex-direction:column;align-items:flex-start;"><span>Estimasi HPP saat ini</span>
            <b style="font-size:16px;"><?= rp($detail['total']) ?> / porsi</b></div>
        <div class="stat-line" style="flex-direction:column;align-items:flex-start;"><span>Estimasi laba kotor</span>
            <b style="font-size:16px;"><?= rp((float)$menu['price'] - $detail['total']) ?></b></div>
        <div class="stat-line" style="flex-direction:column;align-items:flex-start;"><span>Margin kotor</span>
            <b style="font-size:16px;"><?= pct((float)$menu['price'] > 0 ? ((float)$menu['price'] - $detail['total']) / (float)$menu['price'] * 100 : 0) ?></b></div>
    </div>
    <?php if ($recipe && $recipe['notes']): ?>
        <div class="notice info" style="margin-top:12px;"><span>📖</span><span><?= e($recipe['notes']) ?></span></div>
    <?php endif; ?>
</div>

<?php if (can('recipes') && $items): ?>
<div class="modal-backdrop" id="m-del">
    <div class="modal">
        <form method="post" action="<?= e(self_url()) ?>">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="delete_recipe" id="del-action">
            <input type="hidden" name="_back" value="<?= e($backUrl) ?>">
            <input type="hidden" name="id" value="<?= (int)$menuId ?>" id="del-id">
            <div class="modal-head"><h3>Hapus Resep Menu</h3><button class="x-btn" data-close type="button">&times;</button></div>
            <div class="modal-body">
                <div class="notice" style="border-color:rgba(220,38,38,.35);background:rgba(254,242,242,.85);color:#991b1b;margin-bottom:14px;">
                    <span>⚠️</span><span>Resep menu <b><?= e($menu['name']) ?></b> (<span id="del-stock"></span>) akan dihapus,
                    termasuk seluruh baris takaran bahannya.</span></div>
                <ul style="font-size:12.5px;color:var(--muted);margin:0;padding-left:20px;">
                    <li>Riwayat penjualan yang sudah tersimpan <b>tidak terpengaruh</b> (HPP tiap transaksi tersimpan permanen).</li>
                    <li>Setelah resep dihapus, penjualan menu ini <b>tidak lagi mengurangi stok bahan otomatis</b> dan
                        HPP/porsi menjadi Rp 0 sampai resep dibuat ulang.</li>
                </ul>
                <div class="field" style="margin-top:12px;">
                    <label class="check"><input type="checkbox" required id="del-confirm"> Saya memahami dan setuju menghapus resep ini</label>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn" data-close type="button">Batal</button>
                <button class="btn btn-danger" type="submit">Hapus Resep</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="grid grid-2 section">
    <div class="card">
        <div class="card-head"><h2>Komposisi Bahan (Standard Recipe)</h2></div>
        <div class="table-wrap"><table class="data compact">
            <thead><tr><th>Bahan</th><th class="num">Takaran</th><th class="num">Setara Satuan Stok</th>
                <th class="num">Harga Satuan</th><th class="num">Biaya</th><th class="num">% HPP</th></tr></thead>
            <tbody>
            <?php foreach ($detail['rows'] as $r): ?>
                <tr>
                    <td><?= e($r['ingredient_name']) ?></td>
                    <td class="num"><?= num($r['recipe_qty'], 3) ?> <?= e(unit_label($r['recipe_unit'])) ?></td>
                    <td class="num"><?= num($r['qty_base'], 4) ?> <?= e(unit_label($r['unit'])) ?></td>
                    <td class="num"><?= rp($r['unit_cost']) ?> <small>/<?= e(unit_label($r['unit'])) ?></small></td>
                    <td class="num"><?= rp($r['cost']) ?></td>
                    <td class="num"><?= pct($detail['total'] > 0 ? $r['cost'] / $detail['total'] * 100 : 0) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$detail['rows']): ?><tr><td colspan="6" class="empty">Menu ini belum memiliki resep.</td></tr><?php endif; ?>
            <tr class="total-row"><td colspan="4">Total HPP per porsi</td><td class="num"><?= rp($detail['total']) ?></td><td class="num">100%</td></tr>
            </tbody></table></div>
        <div style="font-size:11.5px;color:var(--muted);margin-top:10px;">
            Kolom "Setara Satuan Stok" adalah hasil konversi takaran resep (mis. gram) ke satuan stok bahan (mis. kg),
            inilah nilai yang dipakai untuk mengurangi stok saat menu terjual.
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Contoh Perhitungan Stok</h2></div>
        <p style="font-size:12.5px;color:var(--muted);">Simulasi penggunaan bahan otomatis untuk penjualan menu ini:</p>
        <div class="table-wrap"><table class="data compact">
            <thead><tr><th>Bahan</th><th class="num">1 Porsi</th><th class="num">10 Porsi</th><th class="num">25 Porsi</th></tr></thead>
            <tbody>
            <?php foreach ($detail['rows'] as $r): ?>
                <tr><td><?= e($r['ingredient_name']) ?></td>
                    <td class="num">-<?= num($r['qty_base'], 4) ?> <?= e(unit_label($r['unit'])) ?></td>
                    <td class="num">-<?= num($r['qty_base'] * 10, 3) ?> <?= e(unit_label($r['unit'])) ?></td>
                    <td class="num">-<?= num($r['qty_base'] * 25, 3) ?> <?= e(unit_label($r['unit'])) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$detail['rows']): ?><tr><td colspan="4" class="empty">Belum ada resep.</td></tr><?php endif; ?>
            </tbody></table></div>
        <?php if ($detail['rows']): ?>
            <div class="stat-line" style="margin-top:12px;"><span>Estimasi HPP 10 porsi</span><b><?= rp($detail['total'] * 10) ?></b></div>
            <div class="stat-line"><span>Estimasi laba kotor 10 porsi</span><b><?= rp(((float)$menu['price'] - $detail['total']) * 10) ?></b></div>
        <?php endif; ?>
    </div>
</div>

<?php if (can('recipes')): ?>
<form method="post" action="<?= e(self_url()) ?>" class="section">
    <?= app_csrf_field() ?>
    <input type="hidden" name="_action" value="save_recipe">
    <input type="hidden" name="_back" value="<?= e($backUrl) ?>">
    <input type="hidden" name="menu_id" value="<?= (int)$menuId ?>">
    <div class="card">
        <div class="card-head"><h2>Ubah Takaran Resep</h2>
            <div class="spacer"></div>
            <div style="font-size:12.5px;">Estimasi HPP baru: <b id="new-hpp">Rp 0</b></div>
        </div>
        <div class="form-grid">
            <div class="field"><label>Hasil resep (yield)</label><input type="number" name="yield_qty" step="0.5" min="0.5"
                value="<?= e((string)($recipe['yield_qty'] ?? 1)) ?>"></div>
            <div class="field"><label>Satuan hasil</label><input type="text" name="yield_unit" value="<?= e($recipe['yield_unit'] ?? 'porsi') ?>"></div>
            <div class="field span-2"><label>Catatan resep</label>
                <input type="text" name="notes" value="<?= e((string)($recipe['notes'] ?? '')) ?>" placeholder="mis. takaran untuk 1 porsi standar"></div>
        </div>
        <h3 style="margin:18px 0 10px;">Bahan &amp; Takaran</h3>
        <div class="item-rows" id="recipe-rows" style="--x:1;">
            <?php $existing = $items ?: []; ?>
            <?php foreach ($existing as $it): ?>
                <div class="item-row" style="grid-template-columns:minmax(0,3fr) 110px 120px minmax(0,2fr) 40px;">
                    <div class="field"><label>Bahan</label><select name="ingredient_id[]">
                        <?php foreach ($ingredients as $i): ?>
                            <option value="<?= (int)$i['id'] ?>" data-unit="<?= e($i['unit']) ?>" data-cost="<?= (float)$i['avg_cost'] ?>"
                                <?= (int)$i['id'] === (int)$it['ingredient_id'] ? 'selected' : '' ?>>
                                <?= e($i['name']) ?> (<?= e($i['unit']) ?>)<?= (int)$i['is_active'] === 1 ? '' : ' — nonaktif' ?></option>
                        <?php endforeach; ?>
                    </select></div>
                    <div class="field"><label>Takaran</label><input type="number" name="qty[]" step="0.001" min="0"
                        value="<?= e((string)(float)$it['qty']) ?>" class="rq"></div>
                    <div class="field"><label>Satuan</label><select name="unit[]" class="ru"><?= opts(unit_options(), $it['unit']) ?></select></div>
                    <div class="field"><label>Catatan (opsional)</label><input type="text" name="note[]" value="<?= e((string)($it['note'] ?? '')) ?>"></div>
                    <button class="rm" type="button" onclick="this.closest('.item-row').remove(); calcHpp();">×</button>
                </div>
            <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap;">
            <button class="btn btn-sm" type="button" onclick="addRecipeRow()">＋ Tambah bahan</button>
            <button class="btn btn-primary" type="submit">💾 Simpan Resep</button>
        </div>
        <div style="font-size:11.5px;color:var(--muted);margin-top:10px;">
            Perubahan resep langsung memengaruhi perhitungan HPP dan pengurangan stok pada transaksi penjualan berikutnya.
            Riwayat perubahan resep tercatat pada audit trail.
        </div>
    </div>
</form>

<script>
var UNITS = <?= json_encode($jsUnits) ?>;
var ING = {};
document.querySelectorAll('#recipe-rows select[name="ingredient_id[]"]').forEach(function (s) { cacheIng(s); });
function cacheIng(sel) {
    var o = sel.selectedOptions[0];
    if (!o) return;
    ING[o.value] = { cost: parseFloat(o.getAttribute('data-cost')) || 0, unit: o.getAttribute('data-unit') };
}
function conv(qty, from, to) {
    var f = UNITS[from], t = UNITS[to];
    if (!f || !t) return qty;
    if (from === to) return qty;
    if (f[0] !== t[0]) return qty;
    return qty * f[1] / t[1];
}
function calcHpp() {
    var total = 0;
    document.querySelectorAll('#recipe-rows .item-row').forEach(function (row) {
        var sel = row.querySelector('select[name="ingredient_id[]"]');
        var qty = parseFloat(row.querySelector('[name="qty[]"]').value.replace(',', '.')) || 0;
        var unit = row.querySelector('[name="unit[]"]').value;
        cacheIng(sel);
        var d = ING[sel.value];
        if (!d) return;
        total += conv(qty, unit, d.unit) * d.cost;
    });
    var price = <?= (float)$menu['price'] ?>;
    document.getElementById('new-hpp').textContent = 'Rp ' + Math.round(total).toLocaleString('id-ID')
        + (price > 0 ? '  ·  margin ' + (100 * (price - total) / price).toFixed(1) + '%' : '');
}
function addRecipeRow() {
    var host = document.getElementById('recipe-rows');
    var first = host.querySelector('.item-row');
    var node = first.cloneNode(true);
    node.querySelector('[name="qty[]"]').value = '';
    node.querySelector('[name="note[]"]').value = '';
    host.appendChild(node);
    calcHpp();
}
document.addEventListener('input', function (e) {
    if (e.target.closest('#recipe-rows')) calcHpp();
});
document.addEventListener('change', function (e) {
    if (e.target.closest('#recipe-rows')) calcHpp();
});
calcHpp();
</script>
<?php else: ?>
<div class="notice section"><span>🔒</span><span>Role Anda hanya dapat melihat resep. Perubahan resep hanya dapat dilakukan Owner/Admin.</span></div>
<?php endif; ?>
