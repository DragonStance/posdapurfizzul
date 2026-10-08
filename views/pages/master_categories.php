<?php
/** Master Data — Kategori Bahan. */
$rows = q($pdo, 'SELECT c.*, (SELECT COUNT(*) FROM ingredients i WHERE i.category_id = c.id) AS n_ing,
                        (SELECT COALESCE(SUM(i.current_stock * i.avg_cost),0) FROM ingredients i WHERE i.category_id = c.id) AS nilai
                 FROM ingredient_categories c ORDER BY c.name');
?>
<div class="card">
    <div class="card-head">
        <h2>Kategori Bahan Baku</h2><span class="badge info"><?= count($rows) ?> kategori</span>
        <div class="spacer"></div>
        <a class="btn btn-primary" data-modal="m-cat" data-reset href="#">＋ Tambah Kategori</a>
    </div>
    <p style="font-size:12.5px;color:var(--muted);">Kategori dipakai untuk mengelompokkan bahan pada laporan, filter stok,
        dan cakupan stock opname per kategori.</p>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>Nama Kategori</th><th>Keterangan</th><th class="num">Jumlah Bahan</th><th class="num">Nilai Stok</th><th class="mid">Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><b><?= e($r['name']) ?></b></td>
                <td><?= e($r['description'] ?? '-') ?></td>
                <td class="num"><?= (int)$r['n_ing'] ?></td>
                <td class="num"><?= rp($r['nilai']) ?></td>
                <td class="mid"><button class="btn btn-sm" data-fill="m-cat" data-title-text="Ubah Kategori"
                    data-json='<?= e(json_encode(['id' => $r['id'], 'name' => $r['name'], 'description' => $r['description']], JSON_UNESCAPED_UNICODE)) ?>'>Ubah</button></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="5" class="empty">Belum ada kategori.</td></tr><?php endif; ?>
        </tbody></table></div>
</div>

<div class="modal-backdrop" id="m-cat">
    <div class="modal">
        <form method="post" action="<?= e(self_url()) ?>">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="save_category">
            <input type="hidden" name="_back" value="<?= e(app_url('master_categories')) ?>">
            <input type="hidden" name="id" value="">
            <div class="modal-head"><h3 data-title>Tambah Kategori</h3><button class="x-btn" data-close type="button">&times;</button></div>
            <div class="modal-body">
                <div class="form-grid one">
                    <div class="field"><label>Nama Kategori</label><input type="text" name="name" required></div>
                    <div class="field"><label>Keterangan</label><input type="text" name="description"></div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn" data-close type="button">Batal</button>
                <button class="btn btn-primary" type="submit">Simpan Kategori</button>
            </div>
        </form>
    </div>
</div>
