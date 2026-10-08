<?php
/** Detail transaksi penjualan + jejak penggunaan bahan (BOM) yang dihasilkan. */
$id = gi('id');
$sale = q1($pdo, 'SELECT s.*, u.name AS kasir FROM sales s LEFT JOIN users u ON u.id = s.created_by WHERE s.id = ?', [$id]);
if (!$sale) {
    echo '<div class="card"><h2>Transaksi tidak ditemukan</h2><a class="btn" href="' . e(app_url('sales')) . '">Kembali</a></div>';
    return;
}
$items = q($pdo, 'SELECT si.*, m.name AS menu_name, m.category, m.code FROM sale_items si
                  JOIN menus m ON m.id = si.menu_id WHERE si.sale_id = ? ORDER BY si.id', [$id]);
$movements = q($pdo, 'SELECT m.*, i.name AS ing_name FROM inventory_movements m
                      JOIN ingredients i ON i.id = m.ingredient_id
                      WHERE m.ref_type = "sale" AND m.ref_id = ?
                      ORDER BY i.name', [$id]);
$refunds = q($pdo, 'SELECT m.*, i.name AS ing_name FROM inventory_movements m
                    JOIN ingredients i ON i.id = m.ingredient_id
                    WHERE m.ref_type IN ("sale_void") AND m.ref_id = ? ORDER BY i.name', [$id]);
$laba = (float)$sale['total'] - (float)$sale['hpp_total'];
?>
<div class="card">
    <div class="card-head">
        <h2>Transaksi <?= e($sale['invoice_no']) ?></h2>
        <span class="badge <?= $sale['status'] === 'void' ? 'danger' : 'ok' ?>"><?= $sale['status'] === 'void' ? 'DIBATALKAN' : 'Selesai' ?></span>
        <span class="badge <?= $sale['source'] === 'import' ? 'info' : 'muted' ?>"><?= $sale['source'] === 'import' ? 'Impor: ' . e((string)$sale['import_ref']) : 'Input manual' ?></span>
        <div class="spacer"></div>
        <a class="btn" href="<?= e(app_url('sales')) ?>">← Daftar penjualan</a>
        <button class="btn" onclick="window.print()">🖨 Cetak</button>
        <?php if (can('sales_void') && $sale['status'] !== 'void'): ?>
            <button class="btn btn-danger" data-modal="m-void-detail">Batalkan transaksi</button>
        <?php endif; ?>
    </div>

    <div class="grid grid-4">
        <div class="stat-line" style="flex-direction:column;align-items:flex-start;">
            <span>Waktu</span><b><?= tgl($sale['sold_at'], true) ?></b></div>
        <div class="stat-line" style="flex-direction:column;align-items:flex-start;">
            <span>Channel / Pembayaran</span><b><?= e($sale['channel']) ?> · <?= e($sale['payment_method']) ?></b></div>
        <div class="stat-line" style="flex-direction:column;align-items:flex-start;">
            <span>Kasir / Pencatat</span><b><?= e($sale['kasir'] ?? '-') ?></b></div>
        <div class="stat-line" style="flex-direction:column;align-items:flex-start;">
            <span>Total Transaksi</span><b style="font-size:17px;"><?= rp($sale['total']) ?></b></div>
    </div>
    <?php if ($sale['customer_note']): ?>
        <div class="notice info" style="margin-top:14px;"><span>📝</span><span><?= e($sale['customer_note']) ?></span></div>
    <?php endif; ?>
</div>

<div class="grid grid-2 section">
    <div class="card">
        <div class="card-head"><h3>Item Menu Terjual</h3></div>
        <div class="table-wrap"><table class="data">
            <thead><tr><th>Menu</th><th class="num">Qty</th><th class="num">Harga</th><th class="num">Subtotal</th><th class="num">HPP</th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr><td><?= e($it['menu_name']) ?><div class="li-sub"><?= e($it['code']) ?> · <?= e($it['category']) ?></div></td>
                    <td class="num"><?= num($it['qty']) ?></td>
                    <td class="num"><?= rp($it['unit_price']) ?></td>
                    <td class="num"><b><?= rp($it['subtotal']) ?></b></td>
                    <td class="num"><?= rp($it['hpp_total']) ?></td></tr>
            <?php endforeach; ?>
            <tr class="total-row"><td colspan="3">Subtotal</td><td class="num"><?= rp($sale['subtotal']) ?></td><td class="num"><?= rp($sale['hpp_total']) ?></td></tr>
            <?php if ((float)$sale['discount'] > 0): ?>
                <tr><td colspan="3">Diskon</td><td class="num">- <?= rp($sale['discount']) ?></td><td></td></tr>
            <?php endif; ?>
            <tr class="total-row"><td colspan="3">Total Dibayar</td><td class="num"><?= rp($sale['total']) ?></td>
                <td class="num">Laba: <?= rp($laba) ?></td></tr>
            </tbody></table></div>
        <div class="stat-line" style="margin-top:12px;"><span>Estimasi margin transaksi</span>
            <b><?= pct((float)$sale['total'] > 0 ? $laba / (float)$sale['total'] * 100 : 0) ?></b></div>
    </div>

    <div class="card">
        <div class="card-head"><h3>Penggunaan Bahan Otomatis (dari Resep/BOM)</h3>
            <div class="spacer"></div><span class="badge info"><?= count($movements) ?> bahan</span></div>
        <?php if ($movements): ?>
            <div class="table-wrap table-scroll"><table class="data compact">
                <thead><tr><th>Bahan</th><th class="num">Terpakai</th><th class="num">Stok Sebelum</th><th class="num">Stok Setelah</th><th class="num">Nilai HPP</th></tr></thead>
                <tbody>
                <?php foreach ($movements as $m): ?>
                    <tr><td><?= e($m['ing_name']) ?></td>
                        <td class="num" style="color:var(--danger);"><?= num($m['qty']) ?> <?= e($m['unit']) ?></td>
                        <td class="num"><?= num($m['stock_before']) ?></td>
                        <td class="num"><?= num($m['stock_after']) ?></td>
                        <td class="num"><?= rp($m['total_cost']) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <div style="font-size:11.5px;color:var(--muted);margin-top:8px;">
                Setiap baris di atas adalah catatan pergerakan stok (audit trail) yang dibuat otomatis saat transaksi disimpan.
            </div>
        <?php else: ?>
            <div class="empty">Tidak ada pergerakan stok pada transaksi ini.</div>
        <?php endif; ?>
        <?php if ($refunds): ?>
            <h3 style="margin-top:18px;">Pengembalian Stok (Pembatalan)</h3>
            <div class="table-wrap table-scroll"><table class="data compact">
                <thead><tr><th>Bahan</th><th class="num">Dikembalikan</th><th>Waktu</th></tr></thead>
                <tbody>
                <?php foreach ($refunds as $m): ?>
                    <tr><td><?= e($m['ing_name']) ?></td><td class="num" style="color:var(--ok);">+<?= num($m['qty']) ?> <?= e($m['unit']) ?></td>
                        <td><?= tgl($m['moved_at'], true) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
        <?php endif; ?>
    </div>
</div>

<?php if (can('sales_void') && $sale['status'] !== 'void'): ?>
<div class="modal-backdrop" id="m-void-detail">
    <div class="modal">
        <form method="post" action="<?= e(self_url()) ?>">
            <?= app_csrf_field() ?>
            <input type="hidden" name="_action" value="void_sale">
            <input type="hidden" name="_back" value="<?= e(app_url('sale_detail', ['id' => $id])) ?>">
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <div class="modal-head"><h3>Batalkan Transaksi <?= e($sale['invoice_no']) ?></h3><button class="x-btn" data-close type="button">&times;</button></div>
            <div class="modal-body">
                <div class="field"><label>Alasan pembatalan</label><input type="text" name="reason" required></div>
                <p style="font-size:12.5px;color:var(--muted);">Stok bahan akan dikembalikan dan tercatat sebagai pergerakan penyesuaian.</p>
            </div>
            <div class="modal-foot">
                <button class="btn" data-close type="button">Batal</button>
                <button class="btn btn-danger" type="submit">Batalkan</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
