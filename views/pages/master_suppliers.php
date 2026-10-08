<?php
/** Master Data — Supplier. */
$rows = q($pdo, 'SELECT s.*,
                       (SELECT COUNT(*) FROM purchases p WHERE p.supplier_id = s.id) AS n_purchase,
                       (SELECT COALESCE(SUM(p.total_cost),0) FROM purchases p WHERE p.supplier_id = s.id) AS total_belanja,
                       (SELECT COUNT(*) FROM ingredients i WHERE i.supplier_id = s.id) AS n_ing
                FROM suppliers s ORDER BY s.name');
?>
<div class="card">
    <div class="card-head">
        <h2>Master Supplier</h2><span class="badge info"><?= count($rows) ?> supplier</span>
        <div class="spacer"></div>
        <input type="text" data-filter="#tbl-sup" placeholder="🔍 Cari supplier…" style="max-width:220px;">
        <a class="btn btn-primary" data-modal="m-sup" data-reset href="#">＋ Tambah Supplier</a>
        <a class="btn" href="<?= e(export_base()) ?>?type=suppliers">⇩ Ekspor CSV</a>
    </div>
    <div class="table-wrap"><table class="data" id="tbl-sup">
        <thead><tr><th>Kode</th><th>Nama Supplier</th><th>Kontak</th><th>Telepon</th><th>Alamat</th>
            <th class="num">Bahan Terkait</th><th class="num">Nota Pembelian</th><th class="num">Total Belanja</th>
            <th>Catatan</th><th class="mid">Status</th><th class="mid">Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e($r['code']) ?></td>
                <td><b><?= e($r['name']) ?></b></td>
                <td><?= e($r['contact_person'] ?? '-') ?></td>
                <td><?= e($r['phone'] ?? '-') ?></td>
                <td style="max-width:200px;"><?= e(str_short($r['address'] ?? '-', 40)) ?></td>
                <td class="num"><?= (int)$r['n_ing'] ?></td>
                <td class="num"><?= (int)$r['n_purchase'] ?></td>
                <td class="num"><?= rp($r['total_belanja']) ?></td>
                <td style="max-width:240px;"><?= e(str_short($r['notes'] ?? '-', 50)) ?></td>
                <td class="mid"><?= (int)$r['is_active'] === 1 ? '<span class="badge ok">Aktif</span>' : '<span class="badge muted">Diarsipkan</span>' ?></td>
                <td class="mid">
                    <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;">
                        <button class="btn btn-sm" data-fill="m-sup" data-title-text="Ubah Supplier"
                            data-json='<?= e(json_encode([
                                'id' => $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'contact_person' => $r['contact_person'],
                                'phone' => $r['phone'], 'email' => $r['email'], 'address' => $r['address'],
                                'notes' => $r['notes'], 'is_active' => (int)$r['is_active'],
                            ], JSON_UNESCAPED_UNICODE)) ?>'>Ubah</button>
                        <form method="post" action="<?= e(self_url()) ?>" style="display:inline;">
                            <?= app_csrf_field() ?>
                            <input type="hidden" name="_action" value="toggle_supplier">
                            <input type="hidden" name="_back" value="<?= e(app_url('master_suppliers')) ?>">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-sm" type="submit"><?= (int)$r['is_active'] === 1 ? 'Arsipkan' : 'Aktifkan' ?></button>
                        </form>
                        <button class="btn btn-sm btn-danger" type="button" data-del data-action="delete_supplier"
                                data-id="<?= (int)$r['id'] ?>" data-name="<?= e($r['name']) ?>"
                                data-stock="<?= (int)$r['n_ing'] ?> bahan &middot; <?= (int)$r['n_purchase'] ?> nota pembelian"
                                data-riwayat="<?= (int)$r['n_ing'] ?> bahan terkait, <?= (int)$r['n_purchase'] ?> nota pembelian">Hapus</button>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="11" class="empty">Belum ada supplier.</td></tr><?php endif; ?>
        </tbody></table></div>
</div>

<div class="modal-backdrop" id="m-del">
    <div class="modal">
        <form method="post" action="<?= e(self_url()) ?>">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="delete_supplier" id="del-action">
            <input type="hidden" name="_back" value="<?= e(app_url('master_suppliers')) ?>">
            <input type="hidden" name="id" value="" id="del-id">
            <div class="modal-head"><h3>Hapus Supplier</h3><button class="x-btn" data-close type="button">&times;</button></div>
            <div class="modal-body">
                <div class="notice" style="border-color:rgba(220,38,38,.35);background:rgba(254,242,242,.85);color:#991b1b;margin-bottom:14px;">
                    <span>⚠️</span><span>Anda akan menghapus supplier <b id="del-name"></b> (terkait: <span id="del-stock"></span>).</span></div>
                <ul style="font-size:12.5px;color:var(--muted);margin:0;padding-left:20px;">
                    <li>Supplier yang <b>belum pernah dipakai</b> pada bahan baku maupun pembelian → <b>dihapus permanen</b>.</li>
                    <li><b>Restriction delete:</b> supplier yang <b>sudah punya riwayat transaksi</b> (nota pembelian atau
                        bahan baku terkait) <b>TIDAK DAPAT DIHAPUS</b> — sistem akan menolak dengan alasan yang jelas,
                        supaya histori pembelian, harga, dan laporan tetap utuh.</li>
                    <li>Bila supplier tidak lagi dipakai, gunakan tombol <b>Arsipkan</b> pada daftar supplier:
                        supplier dinonaktifkan dan tidak muncul lagi pada pilihan supplier baru, tanpa menghapus datanya.</li>
                </ul>
                <div class="field" style="margin-top:12px;">
                    <label class="check"><input type="checkbox" required id="del-confirm"> Saya memahami dan setuju menghapus supplier ini</label>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn" data-close type="button">Batal</button>
                <button class="btn btn-danger" type="submit">Hapus Supplier</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="m-sup">
    <div class="modal">
        <form method="post" action="<?= e(self_url()) ?>">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="save_supplier">
            <input type="hidden" name="_back" value="<?= e(app_url('master_suppliers')) ?>">
            <input type="hidden" name="id" value="">
            <div class="modal-head"><h3 data-title>Tambah Supplier</h3><button class="x-btn" data-close type="button">&times;</button></div>
            <div class="modal-body">
                <div class="form-grid">
                    <div class="field"><label>Kode Supplier</label><input type="text" name="code" placeholder="otomatis bila kosong"></div>
                    <div class="field span-2"><label>Nama Supplier</label><input type="text" name="name" required></div>
                    <div class="field"><label>Nama Kontak</label><input type="text" name="contact_person"></div>
                    <div class="field"><label>Telepon / WhatsApp</label><input type="tel" name="phone"></div>
                    <div class="field"><label>Email</label><input type="email" name="email"></div>
                    <div class="field span-3"><label>Alamat</label><input type="text" name="address"></div>
                    <div class="field span-3"><label>Catatan</label><textarea name="notes" placeholder="mis. jadwal kirim, syarat pembayaran"></textarea></div>
                    <div class="field"><label class="check"><input type="checkbox" name="is_active" value="1" checked> Supplier aktif</label></div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn" data-close type="button">Batal</button>
                <button class="btn btn-primary" type="submit">Simpan Supplier</button>
            </div>
        </form>
    </div>
</div>
