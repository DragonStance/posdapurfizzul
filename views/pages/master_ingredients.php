<?php
/** Master Data — Bahan Baku. */
require_perm('master');
$catF = gi('category_id');
$search = (string)(g('q', '') ?? '');
$w = '1=1'; $p = [];
if ($catF > 0) { $w .= ' AND i.category_id = ?'; $p[] = $catF; }
if ($search !== '') { $w .= ' AND (i.name LIKE ? OR i.code LIKE ? OR i.storage_location LIKE ?)'; $p = array_merge($p, ['%' . $search . '%', '%' . $search . '%', '%' . $search . '%']); }
$rows = q($pdo, "SELECT i.*, c.name AS category_name, s.name AS supplier_name,
                        (SELECT COUNT(*) FROM inventory_movements m WHERE m.ingredient_id = i.id) AS n_move,
                        (SELECT COUNT(*) FROM recipe_items ri WHERE ri.ingredient_id = i.id) AS n_recipe
                 FROM ingredients i
                 LEFT JOIN ingredient_categories c ON c.id = i.category_id
                 LEFT JOIN suppliers s ON s.id = i.supplier_id
                 WHERE $w ORDER BY i.name", $p);
$cats = q($pdo, 'SELECT id, name FROM ingredient_categories ORDER BY name');
$sups = q($pdo, 'SELECT id, name FROM suppliers ORDER BY name');
?>
<div class="card">
    <div class="card-head">
        <h2>Master Bahan Baku</h2>
        <span class="badge info"><?= count($rows) ?> bahan</span>
        <div class="spacer"></div>
        <input type="text" data-filter="#tbl-ing" placeholder="🔍 Cari bahan…" style="max-width:220px;">
        <a class="btn btn-primary" data-modal="m-ing" data-reset href="#">＋ Tambah Bahan</a>
        <a class="btn" href="<?= e(export_base()) ?>?type=ingredients">⇩ Ekspor CSV</a>
    </div>
    <form class="filter-bar" method="get" action="<?= e(self_url()) ?>" style="margin-bottom:14px;">
        <div class="field"><label>Kategori</label><select name="category_id"><option value="">Semua kategori</option>
            <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $catF === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="field"><label>Cari</label><input type="text" name="q" value="<?= e($search) ?>" placeholder="nama / kode / lokasi"></div>
        <button class="btn btn-primary" type="submit">Filter</button>
        <a class="btn" href="<?= e(app_url('master_ingredients')) ?>">Reset</a>
    </form>
    <div class="table-wrap"><table class="data" id="tbl-ing">
        <thead><tr><th>Kode</th><th>Nama Bahan</th><th>Kategori</th><th>Satuan</th><th class="num">Stok Min.</th>
            <th class="num">Stok Saat Ini</th><th class="num">Harga Rata-rata</th><th>Supplier</th><th>Lokasi</th>
            <th class="mid">Status</th><th class="mid">Dipakai Resep</th><th class="mid">Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e($r['code']) ?></td>
                <td><b><?= e($r['name']) ?></b>
                    <?php if ($r['expiry_info']): ?><div class="li-sub"><?= e(str_short($r['expiry_info'], 40)) ?></div><?php endif; ?></td>
                <td><?= e($r['category_name'] ?? '-') ?></td>
                <td><?= e(unit_label($r['unit'])) ?></td>
                <td class="num"><?= num($r['min_stock']) ?></td>
                <td class="num"><?= num($r['current_stock']) ?></td>
                <td class="num"><?= rp($r['avg_cost']) ?></td>
                <td><?= e($r['supplier_name'] ?? '-') ?></td>
                <td><?= e($r['storage_location'] ?? '-') ?></td>
                <td class="mid"><?= (int)$r['is_active'] === 1 ? '<span class="badge ok">Aktif</span>' : '<span class="badge muted">Diarsipkan</span>' ?></td>
                <td class="mid"><?= (int)$r['n_recipe'] ?> menu</td>
                <td class="mid">
                    <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                        <button class="btn btn-sm" data-fill="m-ing" data-title-text="Ubah Bahan Baku"
                            data-json='<?= e(json_encode([
                                'id' => $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'category_id' => $r['category_id'],
                                'unit' => $r['unit'], 'min_stock' => (float)$r['min_stock'], 'supplier_id' => $r['supplier_id'],
                                'storage_location' => $r['storage_location'], 'expiry_info' => $r['expiry_info'],
                                'avg_cost' => (float)$r['avg_cost'], 'is_active' => (int)$r['is_active'],
                            ], JSON_UNESCAPED_UNICODE)) ?>'>Ubah</button>
                        <form method="post" action="<?= e(self_url()) ?>" style="display:inline;">
                            <?= app_csrf_field() ?>
                            <input type="hidden" name="_action" value="toggle_ingredient">
                            <input type="hidden" name="_back" value="<?= e(app_url('master_ingredients')) ?>">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-sm" type="submit"><?= (int)$r['is_active'] === 1 ? 'Arsipkan' : 'Aktifkan' ?></button>
                        </form>
                        <button class="btn btn-sm btn-danger" type="button" data-del
                                data-action="delete_ingredient" data-id="<?= (int)$r['id'] ?>"
                                data-name="<?= e($r['name']) ?>" data-stock="<?= e(num($r['current_stock']) . ' ' . unit_label($r['unit'])) ?>"
                                data-riwayat="<?= (int)$r['n_move'] ?> pergerakan stok, <?= (int)$r['n_recipe'] ?> baris resep">Hapus</button>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="12" class="empty">Belum ada bahan baku.</td></tr><?php endif; ?>
        </tbody></table></div>
</div>

<div class="modal-backdrop" id="m-del">
    <div class="modal">
        <form method="post" action="<?= e(self_url()) ?>">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="delete_ingredient" id="del-action">
            <input type="hidden" name="_back" value="<?= e(app_url('master_ingredients')) ?>">
            <input type="hidden" name="id" value="" id="del-id">
            <div class="modal-head"><h3>Hapus Bahan Baku</h3><button class="x-btn" data-close type="button">&times;</button></div>
            <div class="modal-body">
                <div class="notice" style="border-color:rgba(220,38,38,.35);background:rgba(254,242,242,.85);color:#991b1b;margin-bottom:14px;">
                    <span>⚠️</span><span>Anda akan menghapus <b id="del-name"></b> (stok saat ini: <span id="del-stock"></span>).
                    Perubahan ini tercatat pada audit trail.</span></div>
                <p style="font-size:13px;margin:0 0 8px;">Aturan keamanan data yang diterapkan sistem:</p>
                <ul style="font-size:12.5px;color:var(--muted);margin:0;padding-left:20px;">
                    <li>Jika bahan <b>belum pernah</b> dipakai pada resep/transaksi → data <b>dihapus permanen</b>.</li>
                    <li>Jika sudah punya riwayat → bahan <b>diarsipkan</b> (tidak muncul di daftar aktif) supaya
                        histori stok, HPP, dan laporan tetap akurat. Bisa diaktifkan kembali kapan saja.</li>
                </ul>
                <div class="field" style="margin-top:12px;">
                    <label class="check"><input type="checkbox" required id="del-confirm"> Saya memahami dan setuju menghapus bahan ini</label>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn" data-close type="button">Batal</button>
                <button class="btn btn-danger" type="submit">Hapus Bahan</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="m-ing">
    <div class="modal">
        <form method="post" action="<?= e(self_url()) ?>">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="save_ingredient">
            <input type="hidden" name="_back" value="<?= e(app_url('master_ingredients')) ?>">
            <input type="hidden" name="id" value="">
            <div class="modal-head"><h3 data-title>Tambah Bahan Baku</h3><button class="x-btn" data-close type="button">&times;</button></div>
            <div class="modal-body">
                <div class="notice info" style="margin-bottom:14px;"><span>ℹ️</span>
                    <span><b>Stok tidak diubah dari form ini.</b> Perubahan stok hanya melalui Pembelian, Penjualan, Waste,
                    Stock Opname, Penggunaan Manual, atau Penyesuaian Stok oleh Owner.</span></div>
                <div class="form-grid">
                    <div class="field"><label>Kode Bahan</label><input type="text" name="code" placeholder="otomatis bila kosong"></div>
                    <div class="field span-2"><label>Nama Bahan</label><input type="text" name="name" required></div>
                    <div class="field"><label>Kategori</label><select name="category_id"><option value="">— tanpa kategori —</option>
                        <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Satuan Stok</label><select name="unit"><?= opts(unit_options(), 'kg') ?></select>
                        <span class="hint">Satuan acuan stok, harga, dan minimum.</span></div>
                    <div class="field"><label>Stok Minimum</label><input type="number" step="0.001" min="0" name="min_stock" value="0"></div>
                    <div class="field"><label>Supplier Utama</label><select name="supplier_id"><option value="">— tanpa supplier —</option>
                        <?php foreach ($sups as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>Lokasi Penyimpanan</label><input type="text" name="storage_location" placeholder="mis. Chiller 1 / Rak Kering A"></div>
                    <div class="field"><label>Harga Rata-rata per Satuan</label><input type="text" name="avg_cost" data-currency value="0">
                        <span class="hint">Hanya dipakai bila ingin mengoreksi harga acuan.</span></div>
                    <div class="field span-3"><label>Informasi Kedaluwarsa / Penyimpanan</label>
                        <input type="text" name="expiry_info" placeholder="mis. cepat rusak — maksimal 2-3 hari di chiller"></div>
                    <div class="field"><label class="check"><input type="checkbox" name="is_active" value="1" checked> Bahan aktif</label></div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn" data-close type="button">Batal</button>
                <button class="btn btn-primary" type="submit">Simpan Bahan</button>
            </div>
        </form>
    </div>
</div>
