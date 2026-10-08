<?php
/**
 * Cek Ketersediaan Menu (modul Operasional — kasir & owner).
 *
 * Ketersediaan dihitung langsung dari STANDARD RECIPE menu × stok bahan yang
 * tersimpan: untuk tiap bahan dihitung berapa porsi yang masih dapat dibuat,
 * lalu diambil nilai terkecil sebagai bahan penghambat. Tidak ada estimasi AI:
 * angka berasal dari takaran resep di database dan stok bahan nyata.
 */
$all = menu_availability($pdo);
$sum = availability_summary($all);

$statusF = (string)(g('status', '') ?? '');
$catF = (string)(g('category', '') ?? '');
$search = (string)(g('q', '') ?? '');
$hanyaBermasalah = (int)(g('masalah', '0') ?? 0) === 1;

$items = array_values(array_filter($all, function ($m) use ($statusF, $catF, $search, $hanyaBermasalah) {
    if ($statusF !== '' && $m['status'] !== $statusF) return false;
    if ($catF !== '' && $m['category'] !== $catF) return false;
    if ($hanyaBermasalah && $m['status'] === 'Tersedia') return false;
    if ($search !== '' && stripos((string)$m['name'], $search) === false && stripos((string)$m['code'], $search) === false) return false;
    return true;
}));
usort($items, function ($a, $b) {
    $rank = ['Tidak Tersedia' => 0, 'Terbatas' => 1, 'Resep Belum Diatur' => 2, 'Tersedia' => 3];
    $r = ($rank[$a['status']] ?? 9) <=> ($rank[$b['status']] ?? 9);
    if ($r !== 0) return $r;
    return ($a['porsi'] ?? 9999) <=> ($b['porsi'] ?? 9999);
});
$cats = array_values(array_unique(array_column($all, 'category')));
sort($cats);
$showCost = can('costs');
?>
<div class="notice info" style="margin-bottom:20px;">
    <span>ℹ️</span><span>Cek ketersediaan menghubungkan <b>menu → resep (BOM) → stok bahan</b> secara langsung. Untuk setiap menu,
    sistem menghitung berapa porsi yang masih dapat dibuat dari stok bahan saat ini, lalu menandai <b>bahan penghambat</b>
    (bahan yang paling membatasi). Angka selalu mengikuti stok terkini setelah pembelian, penjualan, waste, dan stock opname.</span>
</div>

<div class="grid grid-kpi">
    <?= kpi_card('Menu Tersedia', (string)$sum['counts']['Tersedia'], 'Stok bahan mencukupi untuk ≥ 5 porsi', '✅', true) ?>
    <?= kpi_card('Menu Terbatas', (string)$sum['counts']['Terbatas'], 'Stok hanya cukup untuk 1–4 porsi', '⚠️') ?>
    <?= kpi_card('Menu Tidak Tersedia', (string)$sum['counts']['Tidak Tersedia'], 'Ada bahan yang stoknya habis', '⛔') ?>
    <?= kpi_card('Resep Belum Diatur', (string)$sum['counts']['Resep Belum Diatur'], 'Menu tanpa resep — tidak dapat dicek', '📖') ?>
</div>

<?php if ($sum['blockers']): ?>
<div class="card section">
    <div class="card-head"><h3>⛔ Bahan Penghambat Utama</h3>
        <div class="spacer"></div><span class="badge warn"><?= count($sum['blockers']) ?> bahan menahan menu</span></div>
    <p style="font-size:12.5px;color:var(--muted);">Bahan berikut stoknya sudah habis sehingga membuat menu terkait tidak dapat dijual.
        Lakukan pembelian atau stock opname untuk memperbaikinya.</p>
    <div class="table-wrap"><table class="data compact">
        <thead><tr><th>Bahan</th><th class="num">Stok Saat Ini</th><th class="num">Menu Terdampak</th><th class="mid">Tindakan</th></tr></thead>
        <tbody>
        <?php foreach ($sum['blockers'] as $name => $n): ?>
            <?php $stok = (float)qv($pdo, 'SELECT current_stock FROM ingredients WHERE name = ?', [$name], 0); ?>
            <tr><td><?= e($name) ?></td>
                <td class="num"><span class="badge danger"><?= num($stok) ?> <?= e((string)qv($pdo, 'SELECT unit FROM ingredients WHERE name = ?', [$name], '')) ?></span></td>
                <td class="num"><?= (int)$n ?> menu</td>
                <td class="mid"><?php if (can('purchase')): ?><a class="btn btn-sm" href="<?= e(app_url('purchases')) ?>">Catat pembelian</a><?php else: ?>—<?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>
<?php endif; ?>

<div class="card section">
    <div class="card-head">
        <h2>Ketersediaan Menu Saat Ini</h2>
        <span class="badge info"><?= count($items) ?> menu ditampilkan</span>
        <div class="spacer"></div>
        <input type="text" data-filter="#tbl-avail" placeholder="🔍 Cari menu…" style="max-width:200px;">
        <a class="btn btn-sm" href="<?= e(export_base()) ?>?type=ketersediaan">⇩ Ekspor CSV</a>
    </div>
    <form class="filter-bar" method="get" action="<?= e(self_url()) ?>" style="margin-bottom:16px;">
        <div class="field"><label>Status</label><select name="status"><option value="">Semua status</option>
            <?= opts(['Tersedia', 'Terbatas', 'Tidak Tersedia', 'Resep Belum Diatur'], $statusF) ?></select></div>
        <div class="field"><label>Kategori</label><select name="category"><option value="">Semua kategori</option><?= opts($cats, $catF) ?></select></div>
        <div class="field"><label>Cari menu</label><input type="text" name="q" value="<?= e($search) ?>" placeholder="nama / kode menu"></div>
        <div class="field"><label class="check" style="padding-bottom:8px;">
            <input type="checkbox" name="masalah" value="1" <?= $hanyaBermasalah ? 'checked' : '' ?>> Sembunyikan menu yang tersedia</label></div>
        <button class="btn btn-primary" type="submit">Terapkan</button>
        <a class="btn" href="<?= e(app_url('availability')) ?>">Reset</a>
    </form>

    <div class="table-wrap"><table class="data" id="tbl-avail">
        <thead><tr>
            <th>Menu</th><th>Kategori</th><th class="mid">Status</th><th class="num">Estimasi Porsi Tersedia</th>
            <th>Bahan Penghambat</th><th class="num">Bahan Habis</th>
            <?php if ($showCost): ?><th class="num">HPP / Porsi</th><?php endif; ?>
            <th class="mid">Detail</th>
        </tr></thead>
        <tbody>
        <?php foreach ($items as $m): ?>
            <tr>
                <td><b><?= e($m['name']) ?></b><div class="li-sub"><?= e($m['code']) ?> · harga <?= rp($m['price']) ?></div></td>
                <td><?= e($m['category']) ?></td>
                <td class="mid">
                    <span class="badge <?= e($m['badge']) ?>"><?= e($m['status']) ?></span>
                    <?php if ($m['status'] === 'Terbatas'): ?><div class="li-sub">sisa sedikit</div><?php endif; ?>
                </td>
                <td class="num"><b style="font-size:14px;"><?= $m['porsi'] === null ? '—' : num($m['porsi'], 0) ?></b>
                    <?= $m['porsi'] === null ? '' : ' porsi' ?></td>
                <td><?= $m['limit'] ? e($m['limit']) : '<span class="li-sub">bahan siap</span>' ?>
                    <?php if ($m['limit']): ?>
                        <div class="li-sub">paling membatasi (<?= (int)$m['porsi'] ?> porsi)</div>
                    <?php endif; ?></td>
                <td class="num">
                    <?php if ($m['porsi'] === null): ?>—
                    <?php elseif ($m['habis'] > 0): ?><span class="badge danger"><?= (int)$m['habis'] ?> bahan</span>
                    <?php else: ?><span class="badge ok">0</span><?php endif; ?></td>
                <?php if ($showCost): ?>
                    <td class="num"><?= $m['porsi'] === null ? '—' : rp($m['hpp']) ?>
                        <?php if ($m['price'] > 0 && $m['porsi'] !== null): ?>
                            <div class="li-sub">margin <?= pct($m['margin']) ?></div>
                        <?php endif; ?></td>
                <?php endif; ?>
                <td class="mid"><button class="btn btn-sm" type="button" onclick="toggleAvail(<?= (int)$m['id'] ?>)">Rincian</button></td>
            </tr>
            <tr id="av-<?= (int)$m['id'] ?>" style="display:none;background:rgba(243,240,255,.55);">
                <td colspan="<?= $showCost ? 8 : 7 ?>">
                    <div style="padding:10px 4px;">
                        <b style="font-size:12.5px;">Kebutuhan bahan per 1 porsi <?= e($m['name']) ?></b>
                        <?php if (!$m['rows']): ?>
                            <div class="notice" style="margin-top:8px;"><span>📖</span><span>Menu ini belum memiliki resep,
                                sehingga ketersediaan tidak dapat dihitung. Lengkapi resepnya di menu Resep &amp; HPP.</span></div>
                        <?php else: ?>
                            <div class="table-wrap" style="margin-top:8px;"><table class="data compact">
                                <thead><tr><th>Bahan</th><th class="num">Takaran Resep</th><th class="num">Kebutuhan / Porsi</th>
                                    <th class="num">Stok Bahan</th><th class="num">Cukup Untuk</th><th class="mid">Status</th></tr></thead>
                                <tbody>
                                <?php foreach ($m['rows'] as $r): ?>
                                    <tr>
                                        <td><?= e($r['ingredient_name']) ?>
                                            <?= $r['ingredient_active'] ? '' : ' <span class="badge muted">diarsipkan</span>' ?></td>
                                        <td class="num"><?= num($r['recipe_qty'], 3) ?> <?= e(unit_label($r['recipe_unit'])) ?></td>
                                        <td class="num"><?= num($r['need'], 4) ?> <?= e(unit_label($r['unit'])) ?></td>
                                        <td class="num"><?= num($r['stock']) ?> <?= e($r['unit']) ?>
                                            <?php if ($r['min_stock'] > 0 && $r['stock'] < $r['min_stock']): ?>
                                                <div class="li-sub">di bawah minimum (<?= num($r['min_stock']) ?>)</div>
                                            <?php endif; ?></td>
                                        <td class="num"><b><?= num($r['porsi_dari_bahan'], 0) ?> porsi</b></td>
                                        <td class="mid">
                                            <?php if ($r['porsi_dari_bahan'] <= 0): ?>
                                                <span class="badge danger">Habis</span>
                                            <?php elseif ($r['porsi_dari_bahan'] < 5): ?>
                                                <span class="badge warn">Terbatas</span>
                                            <?php else: ?>
                                                <span class="badge ok">Cukup</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody></table></div>
                            <div style="font-size:11.5px;color:var(--muted);margin-top:8px;">
                                Estimasi porsi menu ini dibatasi oleh bahan dengan angka "Cukup Untuk" paling kecil,
                                yaitu <b><?= e((string)$m['limit']) ?></b> → <b><?= num($m['porsi'], 0) ?> porsi</b>.
                            </div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?><tr><td colspan="<?= $showCost ? 8 : 7 ?>" class="empty">Tidak ada menu pada filter ini.</td></tr><?php endif; ?>
        </tbody></table></div>
    <div style="font-size:11.5px;color:var(--muted);margin-top:10px;">
        Angka "Estimasi Porsi Tersedia" dihitung dari stok bahan pada saat halaman ini dibuka dan akan berubah otomatis
        setelah ada pembelian, penjualan, waste, atau penyesuaian stock opname.
    </div>
</div>

<script>
function toggleAvail(id) {
    var r = document.getElementById('av-' + id);
    if (r) r.style.display = (r.style.display === 'none' || !r.style.display) ? '' : 'none';
}
</script>
