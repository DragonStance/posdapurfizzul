<?php
/** Daftar resep / standard recipe & estimasi HPP per menu. */
[$from, $to] = date_range();
$prof = a_menu_profitability($pdo, $from, $to);
$search = (string)(g('q', '') ?? '');
$catF = (string)(g('category', '') ?? '');
$menus = q($pdo, 'SELECT id, code, name, category, price, is_active FROM menus ORDER BY name');
$recipeCounts = [];
foreach (q($pdo, 'SELECT r.menu_id, COUNT(ri.id) AS n FROM recipes r LEFT JOIN recipe_items ri ON ri.recipe_id = r.id GROUP BY r.menu_id') as $r) {
    $recipeCounts[(int)$r['menu_id']] = (int)$r['n'];
}
$totHpp = 0.0; $nResep = 0;
foreach ($menus as $m) {
    $h = menu_hpp($pdo, (int)$m['id']);
    $totHpp += $h;
    if (($recipeCounts[(int)$m['id']] ?? 0) > 0) $nResep++;
}
$avgMargin = 0.0; $n = 0;
foreach ($menus as $m) {
    if ((float)$m['price'] <= 0) continue;
    $avgMargin += ((float)$m['price'] - menu_hpp($pdo, (int)$m['id'])) / (float)$m['price'] * 100;
    $n++;
}
?>
<div class="grid grid-kpi">
    <?= kpi_card('Menu Terdaftar', (string)count($menus), $nResep . ' menu sudah memiliki resep', '🍽️', true) ?>
    <?= kpi_card('Rata-rata HPP / Porsi', rp($n > 0 ? $totHpp / $n : 0), 'Dihitung dari harga bahan terkini', '📉') ?>
    <?= kpi_card('Rata-rata Margin Kotor', pct($n > 0 ? $avgMargin / $n : 0), 'Sebelum biaya operasional', '📈', true) ?>
    <?= kpi_card('Sumber Takaran', 'Standard Recipe', 'Bukan estimasi AI — tersimpan di database', '📖') ?>
</div>

<div class="notice info section" style="margin-bottom:20px;">
    <span>ℹ️</span><span>Estimasi <b>HPP/COGS per porsi</b> dihitung otomatis dari <b>takaran resep yang tersimpan</b> × harga rata-rata
    bahan saat ini. Ketika sebuah menu terjual, sistem memakai resep ini untuk menghitung penggunaan bahan dan mengurangi stok.</span>
</div>

<?php if (can('recipes')): ?>
<div class="modal-backdrop" id="m-del">
    <div class="modal">
        <form method="post" action="<?= e(self_url()) ?>">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="delete_recipe" id="del-action">
            <input type="hidden" name="_back" value="<?= e(app_url('recipes')) ?>">
            <input type="hidden" name="id" value="" id="del-id">
            <div class="modal-head"><h3>Hapus Resep Menu</h3><button class="x-btn" data-close type="button">&times;</button></div>
            <div class="modal-body">
                <div class="notice" style="border-color:rgba(220,38,38,.35);background:rgba(254,242,242,.85);color:#991b1b;margin-bottom:14px;">
                    <span>⚠️</span><span>Resep menu <b id="del-name"></b> (<span id="del-stock"></span>) akan dihapus beserta
                    seluruh baris takaran bahannya.</span></div>
                <ul style="font-size:12.5px;color:var(--muted);margin:0;padding-left:20px;">
                    <li>Riwayat penjualan lama tetap utuh (HPP tiap transaksi tersimpan permanen).</li>
                    <li>Penjualan menu ini tidak lagi mengurangi stok bahan otomatis sampai resep dibuat ulang.</li>
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

<div class="card">
    <div class="card-head"><h2>Resep Standar &amp; Estimasi HPP</h2>
        <div class="spacer"></div>
        <input type="text" data-filter="#tbl-recipe" placeholder="🔍 Cari menu…" style="max-width:220px;">
        <a class="btn" href="<?= e(export_base()) ?>?type=recipes" target="_blank">⇩ Ekspor CSV</a>
    </div>
    <div class="table-wrap"><table class="data" id="tbl-recipe">
        <thead><tr><th>Menu</th><th>Kategori</th><th class="num">Harga Jual</th><th class="num">Bahan</th>
            <th class="num">HPP / Porsi</th><th class="num">Laba Kotor / Porsi</th><th class="num">Margin</th>
            <th class="num">Terjual (periode)</th><th class="mid">Aksi</th></tr></thead>
        <tbody>
        <?php
        $profByMenu = [];
        foreach ($prof as $p) $profByMenu[(int)$p['id']] = $p;
        foreach ($menus as $m):
            $hpp = menu_hpp($pdo, (int)$m['id']);
            $laba = (float)$m['price'] - $hpp;
            $margin = (float)$m['price'] > 0 ? $laba / (float)$m['price'] * 100 : 0;
            $nBahan = $recipeCounts[(int)$m['id']] ?? 0;
        ?>
            <tr>
                <td><b><?= e($m['name']) ?></b><div class="li-sub"><?= e($m['code']) ?><?= (int)$m['is_active'] === 1 ? '' : ' · NONAKTIF' ?></div></td>
                <td><?= e($m['category']) ?></td>
                <td class="num"><?= rp($m['price']) ?></td>
                <td class="num"><?= $nBahan > 0 ? $nBahan . ' bahan' : '<span class="badge danger">belum ada resep</span>' ?></td>
                <td class="num"><b><?= rp($hpp) ?></b></td>
                <td class="num"><?= rp($laba) ?></td>
                <td class="num"><span class="badge <?= $margin >= 50 ? 'ok' : ($margin >= 30 ? 'warn' : 'danger') ?>"><?= pct($margin) ?></span></td>
                <td class="num"><?= num((float)($profByMenu[(int)$m['id']]['qty'] ?? 0), 0) ?></td>
                <td class="mid">
                    <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                        <?php if (can('recipes')): ?>
                            <a class="btn btn-sm" href="<?= e(app_url('recipe_edit', ['id' => $m['id']])) ?>">Kelola resep</a>
                        <?php endif; ?>
                        <a class="btn btn-sm" href="<?= e(app_url('reports', ['type' => 'recipe', 'menu_id' => $m['id']])) ?>">Rincian</a>
                        <?php if (can('recipes') && $nBahan > 0): ?>
                            <button class="btn btn-sm btn-danger" type="button" data-del data-action="delete_recipe"
                                    data-id="<?= (int)$m['id'] ?>" data-name="<?= e($m['name']) ?>"
                                    data-stock="<?= $nBahan ?> baris bahan &middot; HPP saat ini <?= rp($hpp) ?>/porsi">Hapus resep</button>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>
