<?php
/** Master Data — Menu (beserta ringkasan resep & HPP). */
$catF = (string)(g('category', '') ?? '');
$search = (string)(g('q', '') ?? '');
$w = '1=1'; $p = [];
if ($catF !== '') { $w .= ' AND m.category = ?'; $p[] = $catF; }
if ($search !== '') { $w .= ' AND (m.name LIKE ? OR m.code LIKE ?)'; $p = array_merge($p, ['%' . $search . '%', '%' . $search . '%']); }
$rows = q($pdo, "SELECT m.*, (SELECT COUNT(*) FROM recipes r JOIN recipe_items ri ON ri.recipe_id = r.id WHERE r.menu_id = m.id) AS n_bahan,
                        (SELECT COALESCE(SUM(si.qty),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id
                           WHERE si.menu_id = m.id AND s.status = 'selesai') AS total_terjual
                 FROM menus m WHERE $w ORDER BY m.category, m.name", $p);
$cats = array_unique(array_merge(MENU_CATEGORIES, array_column(q($pdo, 'SELECT DISTINCT category FROM menus'), 'category')));
sort($cats);
?>
<div class="card">
    <div class="card-head">
        <h2>Master Menu</h2><span class="badge info"><?= count($rows) ?> menu</span>
        <div class="spacer"></div>
        <input type="text" data-filter="#tbl-menu" placeholder="🔍 Cari menu…" style="max-width:200px;">
        <a class="btn btn-primary" data-modal="m-menu" data-reset href="#">＋ Tambah Menu</a>
        <a class="btn" href="<?= e(export_base()) ?>?type=menus">⇩ Ekspor CSV</a>
    </div>
    <form class="filter-bar" method="get" action="<?= e(self_url()) ?>" style="margin-bottom:14px;">
        <div class="field"><label>Kategori</label><select name="category"><option value="">Semua kategori</option><?= opts($cats, $catF) ?></select></div>
        <div class="field"><label>Cari</label><input type="text" name="q" value="<?= e($search) ?>" placeholder="nama / kode menu"></div>
        <button class="btn btn-primary" type="submit">Filter</button>
        <a class="btn" href="<?= e(app_url('master_menus')) ?>">Reset</a>
    </form>
    <div class="table-wrap"><table class="data" id="tbl-menu">
        <thead><tr><th>Kode</th><th>Nama Menu</th><th>Kategori</th><th class="num">Harga Jual</th>
            <th class="num">Bahan Resep</th><th class="num">HPP / Porsi</th><th class="num">Margin</th>
            <th class="num">Total Terjual</th><th class="mid">Status</th><th class="mid">Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $hpp = menu_hpp($pdo, (int)$r['id']);
            $margin = (float)$r['price'] > 0 ? ((float)$r['price'] - $hpp) / (float)$r['price'] * 100 : 0;
        ?>
            <tr>
                <td><?= e($r['code']) ?></td>
                <td><b><?= e($r['name']) ?></b>
                    <?php if ($r['description']): ?><div class="li-sub"><?= e(str_short($r['description'], 60)) ?></div><?php endif; ?></td>
                <td><?= e($r['category']) ?></td>
                <td class="num"><?= rp($r['price']) ?></td>
                <td class="num"><?= (int)$r['n_bahan'] > 0 ? (int)$r['n_bahan'] . ' bahan' : '<span class="badge danger">belum ada</span>' ?></td>
                <td class="num"><?= rp($hpp) ?></td>
                <td class="num"><span class="badge <?= $margin >= 50 ? 'ok' : ($margin >= 30 ? 'warn' : 'danger') ?>"><?= pct($margin) ?></span></td>
                <td class="num"><?= num($r['total_terjual'], 0) ?> porsi</td>
                <td class="mid"><?= (int)$r['is_active'] === 1 ? '<span class="badge ok">Aktif</span>' : '<span class="badge muted">Nonaktif</span>' ?></td>
                <td class="mid">
                    <div style="display:flex;gap:6px;justify-content:center;">
                        <button class="btn btn-sm" data-fill="m-menu" data-title-text="Ubah Menu"
                            data-json='<?= e(json_encode([
                                'id' => $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'category' => $r['category'],
                                'price' => (float)$r['price'], 'description' => $r['description'], 'is_active' => (int)$r['is_active'],
                            ], JSON_UNESCAPED_UNICODE)) ?>'>Ubah</button>
                        <a class="btn btn-sm" href="<?= e(app_url('recipe_edit', ['id' => $r['id']])) ?>">Resep</a>
                        <form method="post" action="<?= e(self_url()) ?>" style="display:inline;">
                            <?= app_csrf_field() ?>
                            <input type="hidden" name="_action" value="toggle_menu">
                            <input type="hidden" name="_back" value="<?= e(app_url('master_menus')) ?>">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-sm" type="submit"><?= (int)$r['is_active'] === 1 ? 'Arsipkan' : 'Aktifkan' ?></button>
                        </form>
                        <button class="btn btn-sm btn-danger" type="button" data-del data-action="delete_menu"
                                data-id="<?= (int)$r['id'] ?>" data-name="<?= e($r['name']) ?>"
                                data-stock="<?= (int)$r['n_bahan'] ?> baris resep &middot; terjual <?= num($r['total_terjual'], 0) ?> porsi"
                                data-riwayat="<?= (int)$r['n_bahan'] ?> baris resep, <?= num($r['total_terjual'], 0) ?> porsi terjual">Hapus</button>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="10" class="empty">Belum ada menu.</td></tr><?php endif; ?>
        </tbody></table></div>
</div>

<div class="modal-backdrop" id="m-del">
    <div class="modal">
        <form method="post" action="<?= e($self = self_url()) ?>">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="delete_menu" id="del-action">
            <input type="hidden" name="_back" value="<?= e(app_url('master_menus')) ?>">
            <input type="hidden" name="id" value="" id="del-id">
            <div class="modal-head"><h3>Hapus Menu</h3><button class="x-btn" data-close type="button">&times;</button></div>
            <div class="modal-body">
                <div class="notice" style="border-color:rgba(220,38,38,.35);background:rgba(254,242,242,.85);color:#991b1b;margin-bottom:14px;">
                    <span>⚠️</span><span>Anda akan menghapus menu <b id="del-name"></b> (<span id="del-stock"></span>).</span></div>
                <ul style="font-size:12.5px;color:var(--muted);margin:0;padding-left:20px;">
                    <li>Menu yang <b>belum pernah terjual</b> → dihapus permanen beserta resepnya.</li>
                    <li>Menu yang <b>sudah pernah terjual</b> → diarsipkan (tidak dijual lagi) supaya laporan penjualan,
                        analitik profitabilitas, dan HPP transaksi lama tetap akurat. Bisa diaktifkan kembali.</li>
                </ul>
                <div class="field" style="margin-top:12px;">
                    <label class="check"><input type="checkbox" required id="del-confirm"> Saya memahami dan setuju menghapus menu ini</label>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn" data-close type="button">Batal</button>
                <button class="btn btn-danger" type="submit">Hapus Menu</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="m-menu">
    <div class="modal">
        <form method="post" action="<?= e(self_url()) ?>">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="save_menu">
            <input type="hidden" name="_back" value="<?= e(app_url('master_menus')) ?>">
            <input type="hidden" name="id" value="">
            <div class="modal-head"><h3 data-title>Tambah Menu</h3><button class="x-btn" data-close type="button">&times;</button></div>
            <div class="modal-body">
                <div class="form-grid">
                    <div class="field"><label>Kode Menu</label><input type="text" name="code" placeholder="otomatis bila kosong"></div>
                    <div class="field span-2"><label>Nama Menu</label><input type="text" name="name" required></div>
                    <div class="field"><label>Kategori</label><select name="category"><?= opts($cats, 'Makanan Utama') ?></select></div>
                    <div class="field"><label>Harga Jual</label><input type="text" name="price" data-currency required></div>
                    <div class="field"><label class="check" style="margin-top:22px;"><input type="checkbox" name="is_active" value="1" checked> Menu aktif / dijual</label></div>
                    <div class="field span-3"><label>Deskripsi</label><textarea name="description" placeholder="deskripsi singkat menu"></textarea></div>
                </div>
                <div class="notice info" style="margin-top:12px;"><span>ℹ️</span>
                    <span>Setelah menu disimpan, lengkapi <b>resep/BOM</b> melalui tombol "Resep" agar HPP dan pengurangan stok
                    otomatis dapat dihitung saat menu terjual.</span></div>
            </div>
            <div class="modal-foot">
                <button class="btn" data-close type="button">Batal</button>
                <button class="btn btn-primary" type="submit">Simpan Menu</button>
            </div>
        </form>
    </div>
</div>
