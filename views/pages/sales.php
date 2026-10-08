<?php
/** Modul Penjualan: input manual, impor, histori, filter, pembatalan. */
[$from, $to] = date_range();
$channelF = (string)(g('channel', '') ?? '');
$menuF = gi('menu_id');
$statusF = (string)(g('status', '') ?? '');
$search = (string)(g('q', '') ?? '');

$where = ['date(s.sold_at) BETWEEN :from AND :to'];
$params = [':from' => $from, ':to' => $to];
if ($channelF !== '') { $where[] = 's.channel = :ch'; $params[':ch'] = $channelF; }
if ($statusF !== '') { $where[] = 's.status = :st'; $params[':st'] = $statusF; }
if ($menuF > 0) { $where[] = 'EXISTS (SELECT 1 FROM sale_items x WHERE x.sale_id = s.id AND x.menu_id = :mi)'; $params[':mi'] = $menuF; }
if ($search !== '') {
    $where[] = '(s.invoice_no LIKE :q OR s.customer_note LIKE :q OR s.channel LIKE :q
                 OR EXISTS (SELECT 1 FROM sale_items x JOIN menus m ON m.id = x.menu_id WHERE x.sale_id = s.id AND m.name LIKE :q))';
    $params[':q'] = '%' . $search . '%';
}
$w = implode(' AND ', $where);
$total = (int)qv($pdo, "SELECT COUNT(*) FROM sales s WHERE $w", $params);
[$page, $per, $offset, $pages, $total] = paginate($total, 25);

$menuConcat = sql_group_concat('m.name', ', ');
$rows = q($pdo, "SELECT s.*, u.name AS kasir,
                        (SELECT COUNT(*) FROM sale_items x WHERE x.sale_id = s.id) AS n_item,
                        (SELECT $menuConcat FROM sale_items x JOIN menus m ON m.id = x.menu_id WHERE x.sale_id = s.id) AS menu_list
                 FROM sales s LEFT JOIN users u ON u.id = s.created_by
                 WHERE $w ORDER BY s.sold_at DESC, s.id DESC LIMIT $per OFFSET $offset", $params);

$sum = q1($pdo, "SELECT COALESCE(SUM(s.total),0) AS omzet, COALESCE(SUM(s.hpp_total),0) AS hpp, COUNT(*) AS n
                 FROM sales s WHERE $w", $params);
$menus = q($pdo, 'SELECT id, name, price, is_active FROM menus ORDER BY name');
// Penanda ketersediaan pada pilihan menu (dihitung dari resep × stok bahan)
$availMap = [];
foreach (menu_availability($pdo) as $av) {
    $availMap[(int)$av['id']] = $av;
}
$ingredients = q($pdo, 'SELECT id, name, unit FROM ingredients WHERE is_active = 1 ORDER BY name');
$importBatch = q1($pdo, 'SELECT * FROM import_batches ORDER BY id DESC LIMIT 1');
$backUrl = app_url('sales', array_filter(['from' => $from, 'to' => $to, 'channel' => $channelF ?: null,
    'menu_id' => $menuF ?: null, 'status' => $statusF ?: null, 'q' => $search ?: null, 'page' => $page > 1 ? $page : null]));
?>
<div class="grid grid-kpi">
    <?= kpi_card('Omzet Periode', rp($sum['omzet']), tgl($from) . ' – ' . tgl($to), '💰', true) ?>
    <?= kpi_card('Jumlah Transaksi', number_format((int)$sum['n'], 0, ',', '.'), 'Rata-rata ' . rp((int)$sum['n'] > 0 ? $sum['omzet'] / (int)$sum['n'] : 0), '🧾') ?>
    <?= kpi_card('Estimasi HPP', rp($sum['hpp']), 'Dari resep & harga bahan', '📉') ?>
    <?= kpi_card('Estimasi Laba Kotor', rp((float)$sum['omzet'] - (float)$sum['hpp']),
        'Margin ' . pct((float)$sum['omzet'] > 0 ? ((float)$sum['omzet'] - (float)$sum['hpp']) / (float)$sum['omzet'] * 100 : 0), '📈', true) ?>
</div>

<div class="card section">
    <div class="card-head">
        <h2>Histori Penjualan</h2>
        <div class="spacer"></div>
        <?php if (can('sales')): ?>
            <a class="btn btn-primary" data-modal="m-sale" href="#"><span>＋</span> Input Penjualan</a>
            <a class="btn" href="<?= e(app_url('sales_import')) ?>">⬆ Impor CSV/Excel</a>
        <?php endif; ?>
        <a class="btn" href="<?= e(export_base()) ?>?type=sales&<?= e(range_params()) ?>" target="_blank">⇩ Ekspor CSV</a>
    </div>

    <form class="filter-bar" method="get" action="<?= e(self_url()) ?>" style="margin-bottom:16px;">
        <div class="field"><label>Dari</label><input type="date" name="from" value="<?= e($from) ?>"></div>
        <div class="field"><label>Sampai</label><input type="date" name="to" value="<?= e($to) ?>"></div>
        <div class="field"><label>Channel</label><select name="channel"><option value="">Semua channel</option><?= opts(CHANNELS, $channelF) ?></select></div>
        <div class="field"><label>Menu</label><select name="menu_id"><option value="">Semua menu</option>
            <?php foreach ($menus as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $menuF === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="field"><label>Status</label><select name="status"><option value="">Semua</option>
            <option value="selesai" <?= $statusF === 'selesai' ? 'selected' : '' ?>>Selesai</option>
            <option value="void" <?= $statusF === 'void' ? 'selected' : '' ?>>Dibatalkan (void)</option></select></div>
        <div class="field"><label>Cari</label><input type="text" name="q" value="<?= e($search) ?>" placeholder="Invoice / menu / catatan"></div>
        <button class="btn btn-primary" type="submit">Filter</button>
        <a class="btn" href="<?= e(app_url('sales')) ?>">Reset</a>
    </form>

    <div class="table-wrap">
        <table class="data" id="tbl-sales">
            <thead><tr>
                <th>Waktu</th><th>Invoice</th><th>Menu</th><th class="mid">Channel</th><th class="mid">Pembayaran</th>
                <th class="num">Total</th><th class="num">HPP</th><th class="mid">Sumber</th><th class="mid">Status</th><th class="mid">Aksi</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= tgl($r['sold_at'], true) ?><div class="li-sub"><?= e($r['kasir'] ?? '-') ?></div></td>
                    <td><a href="<?= e(app_url('sale_detail', ['id' => $r['id']])) ?>"><?= e($r['invoice_no']) ?></a></td>
                    <td style="max-width:230px;"><?= e(str_short($r['menu_list'] ?? '-', 46)) ?><div class="li-sub"><?= (int)$r['n_item'] ?> item</div></td>
                    <td class="mid"><span class="badge muted"><?= e($r['channel']) ?></span></td>
                    <td class="mid"><?= e($r['payment_method']) ?></td>
                    <td class="num"><b><?= rp($r['total']) ?></b></td>
                    <td class="num"><?= rp($r['hpp_total']) ?></td>
                    <td class="mid"><span class="badge <?= $r['source'] === 'import' ? 'info' : 'muted' ?>"><?= $r['source'] === 'import' ? 'Impor' : 'Manual' ?></span></td>
                    <td class="mid"><span class="badge <?= $r['status'] === 'void' ? 'danger' : 'ok' ?>"><?= $r['status'] === 'void' ? 'Void' : 'Selesai' ?></span></td>
                    <td class="mid">
                        <?php if (can('sales_void') && $r['status'] !== 'void'): ?>
                            <button class="btn btn-sm" type="button" data-void-sale="<?= (int)$r['id'] ?>"
                                    data-invoice="<?= e($r['invoice_no']) ?>" data-total="<?= e(rp($r['total'])) ?>">Batalkan</button>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="10" class="empty">Tidak ada transaksi pada filter ini.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= pager_html($page, $pages, $total) ?>
</div>

<?php if (can('sales')): ?>
<div class="modal-backdrop" id="m-sale">
    <div class="modal wide">
        <form method="post" action="<?= e(self_url()) ?>">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="save_sale">
            <input type="hidden" name="_back" value="<?= e($backUrl) ?>">
            <div class="modal-head"><h3>Input Transaksi Penjualan</h3><button class="x-btn" data-close type="button">&times;</button></div>
            <div class="modal-body">
                <div class="notice info" style="margin-bottom:14px;"><span>ℹ️</span>
                    <span>Saat transaksi disimpan, sistem mengambil <b>standard recipe</b> setiap menu, menghitung estimasi
                    penggunaan bahan, lalu mengurangi stok bahan dan membuat catatan pergerakan stok otomatis.
                    Penanda <b>⛔ stok bahan habis</b> / <b>⚠ sisa n porsi</b> pada pilihan menu berasal dari
                    <a href="<?= e(app_url('availability')) ?>">Cek Ketersediaan Menu</a>.</span></div>
                <div class="form-grid">
                    <div class="field"><label>Waktu Transaksi</label>
                        <input type="text" value="Dicatat otomatis saat data disimpan" readonly>
                        <span class="hint">Tanggal &amp; jam mengikuti waktu penyimpanan (tidak dapat diisi manual).
                            Untuk data marketplace/kanal online yang punya tanggal sendiri, gunakan menu
                            <a href="<?= e(app_url('sales_import')) ?>">Impor Penjualan</a>.</span></div>
                    <div class="field"><label>Channel Penjualan</label><select name="channel"><?= opts(CHANNELS, 'Kasir') ?></select></div>
                    <div class="field"><label>Metode Pembayaran</label><select name="payment_method"><?= opts(PAYMENT_METHODS, 'Tunai') ?></select></div>
                    <div class="field"><label>Diskon (Rp)</label><input type="text" name="discount" id="discount" data-currency value="0"></div>
                    <div class="field span-2"><label>Catatan (opsional)</label><input type="text" name="customer_note" placeholder="mis. pesanan meja 4 / pemesanan marketplace"></div>
                </div>
                <h3 style="margin:18px 0 10px;">Item Menu</h3>
                <div class="item-rows" id="sale-rows"></div>
                <template id="sale-row-template">
                    <div class="item-row sale">
                        <div class="field"><label>Menu</label><select name="menu_id[]">
                            <option value="">— pilih menu —</option>
                            <?php foreach ($menus as $m): if ((int)$m['is_active'] !== 1) continue;
                                $av = $availMap[(int)$m['id']] ?? null;
                                $mark = '';
                                if ($av && $av['status'] === 'Tidak Tersedia') {
                                    $mark = ' — ⛔ stok bahan habis';
                                } elseif ($av && $av['status'] === 'Terbatas') {
                                    $mark = ' — ⚠ sisa ' . (int)$av['porsi'] . ' porsi';
                                } elseif ($av && $av['status'] === 'Resep Belum Diatur') {
                                    $mark = ' — resep belum diatur';
                                } ?>
                                <option value="<?= (int)$m['id'] ?>" data-price="<?= (float)$m['price'] ?>"><?= e($m['name']) ?> (<?= rp($m['price']) ?>)<?= e($mark) ?></option>
                            <?php endforeach; ?>
                        </select></div>
                        <div class="field"><label>Jumlah</label><input type="number" name="qty[]" min="0.5" step="0.5" value="1"></div>
                        <div class="field"><label>Harga Satuan</label><input type="text" name="unit_price[]" data-currency placeholder="otomatis"></div>
                        <div class="field"><label>Subtotal</label><div class="row-total" style="padding-top:8px;">Rp 0</div></div>
                        <button class="rm" type="button" onclick="removeItemRow(this)" title="Hapus baris">×</button>
                    </div>
                </template>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;gap:10px;flex-wrap:wrap;">
                    <button class="btn btn-sm" type="button" onclick="addItemRow('sale')">＋ Tambah baris</button>
                    <div>Total transaksi: <b id="sale-total" style="font-size:18px;">Rp 0</b></div>
                </div>
            </div>
            <div class="modal-foot">
                <button class="btn" data-close type="button">Batal</button>
                <button class="btn btn-primary" type="submit">Simpan &amp; Kurangi Stok</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (can('sales_void')): ?>
<div class="modal-backdrop" id="m-void">
    <div class="modal">
        <form method="post" action="<?= e(self_url()) ?>">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="void_sale">
            <input type="hidden" name="_back" value="<?= e($backUrl) ?>">
            <input type="hidden" name="id" id="void-sale-id">
            <div class="modal-head"><h3>Batalkan Transaksi</h3><button class="x-btn" data-close type="button">&times;</button></div>
            <div class="modal-body">
                <div class="notice" style="border-color:rgba(220,38,38,.35);background:rgba(254,242,242,.85);color:#991b1b;margin-bottom:14px;">
                    <span>⚠️</span><span>Transaksi <b id="void-invoice"></b> (<span id="void-total"></span>) akan dibatalkan.
                    Stok bahan yang sebelumnya terpakai akan dikembalikan melalui catatan pergerakan stok
                    (jenis <b>Penyesuaian Stok</b>) dan tercatat pada audit trail.</span></div>
                <div class="field"><label>Alasan pembatalan</label>
                    <input type="text" name="reason" required placeholder="mis. salah input menu / transaksi marketplace batal"></div>
            </div>
            <div class="modal-foot">
                <button class="btn" data-close type="button">Batal</button>
                <button class="btn btn-danger" type="submit">Ya, Batalkan Transaksi</button>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-void-sale]');
    if (!b) return;
    document.getElementById('void-sale-id').value = b.getAttribute('data-void-sale');
    document.getElementById('void-invoice').textContent = b.getAttribute('data-invoice');
    document.getElementById('void-total').textContent = b.getAttribute('data-total');
    if (window.openModal) window.openModal('m-void');
});
</script>
<?php endif; ?>
