<?php
/** Pengguna & hak akses (role-based access control). */
$users = q($pdo, 'SELECT u.*, r.code AS role_code, r.name AS role_name,
                         (SELECT COUNT(*) FROM sales s WHERE s.created_by = u.id) AS n_sale,
                         (SELECT COUNT(*) FROM purchases p WHERE p.created_by = u.id) AS n_purchase,
                         (SELECT COUNT(*) FROM waste_records w WHERE w.created_by = u.id) AS n_waste
                  FROM users u JOIN roles r ON r.id = u.role_id ORDER BY r.id, u.name');
$roles = q($pdo, 'SELECT * FROM roles ORDER BY id');
$labels = [
    'dashboard' => 'Dashboard', 'sales' => 'Input penjualan', 'sales_void' => 'Batalkan penjualan',
    'purchase' => 'Pencatatan pembelian', 'inventory' => 'Lihat stok', 'opname' => 'Stock opname',
    'opname_approve' => 'Setujui penyesuaian stok', 'waste' => 'Catat waste', 'waste_void' => 'Batalkan waste',
    'recipes' => 'Kelola resep (edit)', 'recipes_view' => 'Lihat resep & HPP', 'manual_usage' => 'Penggunaan bahan manual',
    'master' => 'Kelola master data', 'users' => 'Kelola pengguna', 'analytics' => 'Analitik', 'reports' => 'Laporan',
    'audit' => 'Audit trail', 'settings' => 'Pengaturan sistem', 'costs' => 'Lihat data biaya/HPP',
];
$allPerms = [];
foreach (PERMISSIONS as $list) { foreach ($list as $perm) $allPerms[$perm] = true; }
ksort($allPerms);
?>
<div class="card">
    <div class="card-head">
        <h2>Pengguna Sistem</h2><span class="badge info"><?= count($users) ?> akun</span>
        <div class="spacer"></div>
        <a class="btn btn-primary" data-modal="m-user" data-reset href="#">＋ Tambah Pengguna</a>
    </div>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>Nama</th><th>Username</th><th>Role</th><th>Kontak</th><th class="num">Aktivitas</th>
            <th>Login Terakhir</th><th class="mid">Status</th><th class="mid">Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><b><?= e($u['name']) ?></b><?= (int)$u['id'] === (int)current_user()['id'] ? ' <span class="badge info">Anda</span>' : '' ?></td>
                <td><?= e($u['username']) ?></td>
                <?php
                $badgeRole = match ($u['role_code']) {
                    'owner' => 'info', 'manager' => 'info', 'kasir' => 'ok', default => 'warn',
                };
                ?>
                <td><span class="badge <?= $badgeRole ?>"><?= e($u['role_name']) ?></span></td>
                <td><?= e($u['email'] ?? '-') ?><div class="li-sub"><?= e($u['phone'] ?? '') ?></div></td>
                <td class="num">
                    <div class="li-sub"><?= (int)$u['n_sale'] ?> penjualan</div>
                    <div class="li-sub"><?= (int)$u['n_purchase'] ?> pembelian · <?= (int)$u['n_waste'] ?> waste</div>
                </td>
                <td><?= $u['last_login'] ? tgl($u['last_login'], true) : 'belum pernah' ?></td>
                <td class="mid"><?= (int)$u['is_active'] === 1 ? '<span class="badge ok">Aktif</span>' : '<span class="badge muted">Nonaktif</span>' ?></td>
                <td class="mid">
                    <div style="display:flex;gap:6px;justify-content:center;">
                        <button class="btn btn-sm" data-fill="m-user" data-title-text="Ubah Pengguna"
                            data-json='<?= e(json_encode([
                                'id' => $u['id'], 'name' => $u['name'], 'username' => $u['username'], 'email' => $u['email'],
                                'phone' => $u['phone'], 'role_id' => $u['role_id'], 'is_active' => (int)$u['is_active'],
                            ], JSON_UNESCAPED_UNICODE)) ?>'>Ubah</button>
                        <button class="btn btn-sm" data-fill="m-pass" data-title-text="Reset Kata Sandi: <?= e($u['name']) ?>"
                            data-json='<?= e(json_encode(['id' => $u['id'], 'nama' => $u['name']], JSON_UNESCAPED_UNICODE)) ?>'>Reset sandi</button>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>

<div class="card section">
    <div class="card-head"><h2>Matriks Hak Akses (Role-Based Access Control)</h2></div>
    <div class="table-wrap"><table class="data compact">
        <thead><tr><th>Hak Akses</th><?php foreach ($roles as $r): ?><th class="mid"><?= e($r['name']) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($allPerms as $perm => $_) : ?>
            <tr><td><?= e($labels[$perm] ?? $perm) ?> <div class="li-sub"><?= e($perm) ?></div></td>
                <?php foreach ($roles as $r): ?>
                    <td class="mid"><?= in_array($perm, PERMISSIONS[$r['code']] ?? [], true)
                        ? '<span class="badge ok">✔</span>' : '<span class="badge muted">—</span>' ?></td>
                <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <div class="notice info" style="margin-top:12px;"><span>ℹ️</span>
        <span><b>Manager</b> mengatur modul Operasional (Dashboard, Penjualan, Ketersediaan Menu, Pembelian, Waste),
        Inventory (stok, pergerakan stok, stock opname beserta persetujuan penyesuaian, resep &amp; HPP), serta
        Analitik &amp; Laporan — tanpa akses ke Master Data, Pengguna, Audit Trail, dan Pengaturan.</span></div>
    <div style="font-size:11.5px;color:var(--muted);margin-top:10px;">
        Hak akses ditegakkan di sisi server pada setiap halaman dan aksi (bukan hanya disembunyikan di tampilan).
        Role Staff Dapur/Inventory tidak memiliki akses ke analitik biaya dan pengelolaan pengguna.
    </div>
</div>

<div class="modal-backdrop" id="m-user">
    <div class="modal">
        <form method="post" action="<?= e(self_url()) ?>">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="save_user">
            <input type="hidden" name="_back" value="<?= e(app_url('master_users')) ?>">
            <input type="hidden" name="id" value="">
            <div class="modal-head"><h3 data-title>Tambah Pengguna</h3><button class="x-btn" data-close type="button">&times;</button></div>
            <div class="modal-body">
                <div class="form-grid">
                    <div class="field span-2"><label>Nama Lengkap</label><input type="text" name="name" required></div>
                    <div class="field"><label>Username</label><input type="text" name="username" required placeholder="huruf kecil, tanpa spasi"></div>
                    <div class="field"><label>Role</label><select name="role_id" required>
                        <?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?>
                    </select></div>
                    <div class="field"><label>Email</label><input type="email" name="email"></div>
                    <div class="field"><label>Telepon</label><input type="tel" name="phone"></div>
                    <div class="field span-3"><label>Kata Sandi <span class="hint">(kosongkan bila tidak diubah)</span></label>
                        <input type="password" name="password" placeholder="minimal 6 karakter">
                        <span class="hint">Kata sandi disimpan dalam bentuk hash (password_hash bcrypt), tidak pernah disimpan sebagai teks biasa.</span></div>
                    <div class="field"><label class="check"><input type="checkbox" name="is_active" value="1" checked> Akun aktif</label></div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn" data-close type="button">Batal</button>
                <button class="btn btn-primary" type="submit">Simpan Pengguna</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="m-pass">
    <div class="modal">
        <form method="post" action="<?= e(self_url()) ?>">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="reset_password">
            <input type="hidden" name="_back" value="<?= e(app_url('master_users')) ?>">
            <input type="hidden" name="id" value="">
            <div class="modal-head"><h3 data-title>Reset Kata Sandi</h3><button class="x-btn" data-close type="button">&times;</button></div>
            <div class="modal-body">
                <div class="notice" style="border-color:rgba(217,119,6,.35);background:rgba(255,249,235,.85);color:#7c4a03;margin-bottom:14px;">
                    <span>🔑</span><span>Kata sandi baru untuk <b data-fill-name></b> akan langsung berlaku. Sampaikan kepada
                    pengguna terkait dan minta segera menggantinya.</span></div>
                <div class="field"><label>Kata Sandi Baru</label><input type="password" name="password" required placeholder="minimal 6 karakter"></div>
            </div>
            <div class="modal-foot">
                <button class="btn" data-close type="button">Batal</button>
                <button class="btn btn-primary" type="submit">Reset Kata Sandi</button>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-fill="m-pass"]');
    if (!b) return;
    try {
        var d = JSON.parse(b.getAttribute('data-json'));
        var el = document.querySelector('#m-pass [data-fill-name]');
        if (el) el.textContent = d.nama || '';
    } catch (err) {}
});
</script>
