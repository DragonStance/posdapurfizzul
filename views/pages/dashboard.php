<?php
/** Dashboard operasional — kartu KPI hari ini + grafik tren (filter rentang tanggal). */
$today = today_str();
$kpi = a_dashboard_today($pdo);
$salesToday = $kpi['sales'];
$gran = (string)(g('gran', 'harian') ?? 'harian');
[$from, $to] = date_range();
$days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;

$periodSales = sales_summary($pdo, $from, $to);
$periodWaste = a_waste_summary($pdo, $from, $to);
$periodPurchase = a_purchase_summary($pdo, $from, $to);

$daily = a_sales_daily($pdo, $from, $to);
$labels = array_map(fn($r) => date('d/m', strtotime($r['d'])), $daily);
$byMenu = a_sales_by_menu($pdo, $from, $to, 8);
$byChannel = a_sales_by_channel($pdo, $from, $to);
$wasteDaily = a_waste_daily($pdo, $from, $to);
$purchaseDaily = a_purchase_daily($pdo, $from, $to);

$topUsage = a_usage_by_ingredient($pdo, $from, $to, 5);
$usageDays = [];
if ($topUsage) {
    $ids = array_map(fn($r) => (int)$r['id'], $topUsage);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    foreach (q($pdo, "SELECT ingredient_id, date(moved_at) AS d, SUM(-qty) AS qty, SUM(total_cost) AS cost
                      FROM inventory_movements
                      WHERE qty < 0 AND movement_type IN ('penjualan','penggunaan_manual')
                        AND ingredient_id IN ($ph) AND date(moved_at) BETWEEN ? AND ?
                      GROUP BY ingredient_id, date(moved_at)", array_merge($ids, [$from, $to])) as $r) {
        $usageDays[(int)$r['ingredient_id']][$r['d']] = (float)$r['qty'];
    }
}
$topMenus = a_sales_by_menu($pdo, $from, $to, 5);
$recentSales = q($pdo, 'SELECT s.*, (SELECT COUNT(*) FROM sale_items si WHERE si.sale_id = s.id) AS n_item
                       FROM sales s WHERE s.status = "selesai" ORDER BY s.sold_at DESC, s.id DESC LIMIT 8');
$recentMovements = q($pdo, 'SELECT m.*, i.name AS ing_name FROM inventory_movements m
                            JOIN ingredients i ON i.id = m.ingredient_id
                            ORDER BY m.moved_at DESC, m.id DESC LIMIT 9');
$lowStock = low_stock_rows($pdo);
$expiring = expiring_rows($pdo, EXPIRY_WARNING_DAYS);
$granular = $gran === 'mingguan' ? a_sales_weekly($pdo, $from, $to) : ($gran === 'bulanan' ? a_sales_monthly($pdo, $from, $to) : null);
if ($granular !== null) {
    $labels = array_map(fn($r) => $gran === 'mingguan'
        ? 'Minggu ' . date('d/m', strtotime($r['mulai'] ?? $r['m'] . '-01'))
        : date('M Y', strtotime($r['m'] . '-01')), $granular);
}
$series = $granular ?? $daily;
?>

<div class="notice demo" style="margin-bottom:20px;">
    <span>🧪</span>
    <span><b>DATA DEMO.</b> Sistem ini telah terisi data contoh realistis 30 hari terakhir (penjualan, pembelian, waste,
    stock opname) agar dashboard, analitik, dan laporan dapat langsung diuji. Data label DEMO hanya muncul pada data seed;
    transaksi baru yang Anda input adalah data nyata.</span>
</div>

<div class="grid grid-kpi">
    <?= kpi_card('Total Penjualan Hari Ini', rp($salesToday['omzet']), $salesToday['trx'] . ' transaksi tercatat', '💰', true) ?>
    <?= kpi_card('Total Transaksi Hari Ini', number_format($salesToday['trx'], 0, ',', '.'), 'Rata-rata ' . rp($salesToday['avg_trx']) . ' / transaksi', '🧾') ?>
    <?= kpi_card('Estimasi HPP / COGS Hari Ini', rp($salesToday['hpp']), 'Berdasarkan resep & harga bahan terkini', '📉') ?>
    <?= kpi_card('Estimasi Laba Kotor Hari Ini', rp($salesToday['laba']), 'Margin ' . pct($salesToday['margin']), '📈', true) ?>
</div>

<div class="grid grid-kpi" style="margin-top:18px;">
    <?= kpi_card('Bahan Stok Rendah', number_format($kpi['low_count'], 0, ',', '.') . ' bahan', $kpi['critical_count'] . ' di antaranya kritis/habis', '⚠️') ?>
    <?= kpi_card('Biaya Waste Hari Ini', rp($kpi['waste_cost']), 'Periode ' . tgl($from) . ' – ' . tgl($to) . ': ' . rp($periodWaste['cost']), '🗑️') ?>
    <?= kpi_card('Porsi Terjual Hari Ini', num($salesToday['qty'], 0) . ' porsi', 'Periode terpilih: ' . num($periodSales['qty'], 0) . ' porsi', '🍽️') ?>
    <?= kpi_card('Pembelian Periode Ini', rp($periodPurchase['total']), $periodPurchase['n'] . ' nota pembelian', '📦') ?>
</div>

<div class="section">
    <div class="card">
        <div class="card-head">
            <h2>Tren &amp; Analisis Periode</h2>
            <span class="badge info"><?= tgl($from) ?> – <?= tgl($to) ?> (<?= $days ?> hari)</span>
            <div class="spacer"></div>
            <div class="tabs" style="border:none;margin:0;">
                <?php foreach (['harian' => 'Harian', 'mingguan' => 'Mingguan', 'bulanan' => 'Bulanan'] as $k => $lbl): ?>
                    <a class="tab <?= $gran === $k ? 'active' : '' ?>" style="padding:6px 12px;"
                       href="<?= e(app_url('dashboard', ['gran' => $k, 'from' => $from, 'to' => $to])) ?>"><?= $lbl ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?= range_form('dashboard', ['gran' => $gran]) ?>

        <div class="grid grid-2" style="margin-top:18px;">
            <div class="card" style="box-shadow:none;">
                <div class="card-head"><h3>Penjualan <?= e(ucfirst($gran)) ?> (Omzet &amp; Laba Kotor)</h3></div>
                <?= chart_box('chSalesTrend', [
                    'type' => 'line', 'format' => 'rp', 'labels' => $labels,
                    'datasets' => [
                        ['label' => 'Omzet', 'data' => array_map(fn($r) => (float)$r['omzet'], $series), 'fill' => true],
                        ['label' => 'Laba Kotor', 'data' => array_map(fn($r) => (float)$r['laba'] ?? ((float)$r['omzet'] - (float)$r['hpp']), $series)],
                    ],
                ]) ?>
            </div>
            <div class="card" style="box-shadow:none;">
                <div class="card-head"><h3>Penjualan Berdasarkan Channel</h3></div>
                <?= chart_box('chChannel', [
                    'type' => 'doughnut', 'format' => 'rp',
                    'labels' => array_column($byChannel, 'channel'),
                    'datasets' => [['label' => 'Omzet', 'data' => array_map(fn($r) => (float)$r['omzet'], $byChannel)]],
                ], 'short') ?>
                <?php foreach ($byChannel as $c): ?>
                    <div class="stat-line"><span><?= e($c['channel']) ?> <small>(<?= (int)$c['trx'] ?> trx)</small></span>
                        <b><?= rp($c['omzet']) ?></b></div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="grid grid-2" style="margin-top:18px;">
            <div class="card" style="box-shadow:none;">
                <div class="card-head"><h3>Penjualan Berdasarkan Menu</h3></div>
                <?= chart_box('chMenu', [
                    'type' => 'bar', 'format' => 'rp',
                    'labels' => array_map(fn($r) => str_short($r['name'], 22), $byMenu),
                    'datasets' => [['label' => 'Omzet', 'data' => array_map(fn($r) => (float)$r['omzet'], $byMenu)]],
                ], 'short') ?>
            </div>
            <div class="card" style="box-shadow:none;">
                <div class="card-head"><h3>Tren Penggunaan Bahan (5 teratas berdasarkan biaya)</h3></div>
                <?php if ($topUsage): ?>
                    <?= chart_box('chUsage', [
                        'type' => 'line', 'format' => 'rp', 'labels' => $labels,
                        'datasets' => array_map(fn($u) => [
                            'label' => $u['name'],
                            'data' => array_map(fn($d) => round(($usageDays[(int)$u['id']][$d['d']] ?? 0) * ((float)$u['cost'] / max((float)$u['qty'], 0.0001)), 2), $daily),
                        ], $topUsage),
                    ], 'short') ?>
                    <div style="font-size:11.5px;color:var(--muted);margin-top:8px;">
                        Nilai dalam Rupiah (estimasi biaya bahan terpakai per hari). Peringkat:
                        <?php foreach ($topUsage as $i => $u): ?><?= $i ? ' · ' : '' ?><?= e($u['name']) ?> <?= num($u['qty']) ?> <?= e(unit_label($u['unit'])) ?><?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty">Belum ada penggunaan bahan pada periode ini.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid grid-2" style="margin-top:18px;">
            <div class="card" style="box-shadow:none;">
                <div class="card-head"><h3>Tren Biaya Waste</h3></div>
                <?= chart_box('chWaste', [
                    'type' => 'bar', 'format' => 'rp', 'labels' => $labels,
                    'datasets' => [['label' => 'Biaya Waste', 'data' => array_map(fn($r) => (float)$r['cost'], $wasteDaily)]],
                ], 'short') ?>
            </div>
            <div class="card" style="box-shadow:none;">
                <div class="card-head"><h3>Tren Biaya Pembelian</h3></div>
                <?= chart_box('chPurchase', [
                    'type' => 'line', 'format' => 'rp', 'labels' => $labels,
                    'datasets' => [['label' => 'Biaya Pembelian', 'data' => array_map(fn($r) => (float)$r['total'], $purchaseDaily), 'fill' => true]],
                ], 'short') ?>
            </div>
        </div>
    </div>
</div>

<div class="grid grid-2 section">
    <div class="card">
        <div class="card-head"><h3>Menu Terlaris (periode terpilih)</h3><div class="spacer"></div>
            <a class="btn btn-sm" href="<?= e(app_url('analytics', ['tab' => 'profit', 'from' => $from, 'to' => $to])) ?>">Analitik menu</a></div>
        <div class="table-wrap"><table class="data compact">
            <thead><tr><th>Menu</th><th class="num">Terjual</th><th class="num">Omzet</th><th class="num">Laba Kotor</th></tr></thead>
            <tbody>
            <?php foreach ($topMenus as $m): ?>
                <tr><td><?= e($m['name']) ?></td><td class="num"><?= num($m['qty'], 0) ?></td>
                    <td class="num"><?= rp($m['omzet']) ?></td>
                    <td class="num"><?= rp((float)$m['omzet'] - (float)$m['hpp']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$topMenus): ?><tr><td colspan="4" class="empty">Belum ada penjualan pada periode ini.</td></tr><?php endif; ?>
            </tbody></table></div>
    </div>

    <div class="card">
        <div class="card-head"><h3>Peringatan Stok Rendah</h3><div class="spacer"></div>
            <span class="badge <?= $lowStock ? 'warn' : 'ok' ?>"><?= count($lowStock) ?> bahan</span></div>
        <div class="table-wrap table-scroll"><table class="data compact">
            <thead><tr><th>Bahan</th><th class="num">Stok</th><th class="num">Min.</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($lowStock as $l): ?>
                <tr><td><?= e($l['name']) ?></td>
                    <td class="num"><?= num($l['current_stock']) ?> <?= e($l['unit']) ?></td>
                    <td class="num"><?= num($l['min_stock']) ?> <?= e($l['unit']) ?></td>
                    <td><?= stock_badge((float)$l['current_stock'], (float)$l['min_stock']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$lowStock): ?><tr><td colspan="4" class="empty">Semua stok bahan berada di atas stok minimum. 👍</td></tr><?php endif; ?>
            </tbody></table></div>
    </div>
</div>

<div class="grid grid-2 section">
    <div class="card">
        <div class="card-head"><h3>Transaksi Terbaru</h3><div class="spacer"></div>
            <a class="btn btn-sm" href="<?= e(app_url('sales')) ?>">Semua penjualan</a></div>
        <div class="table-wrap table-scroll"><table class="data compact">
            <thead><tr><th>Waktu</th><th>Invoice</th><th>Channel</th><th class="num">Total</th></tr></thead>
            <tbody>
            <?php foreach ($recentSales as $s): ?>
                <tr><td><?= tgl($s['sold_at'], true) ?></td>
                    <td><a href="<?= e(app_url('sale_detail', ['id' => $s['id']])) ?>"><?= e($s['invoice_no']) ?></a></td>
                    <td><span class="badge muted"><?= e($s['channel']) ?></span></td>
                    <td class="num"><?= rp($s['total']) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$recentSales): ?><tr><td colspan="4" class="empty">Belum ada transaksi.</td></tr><?php endif; ?>
            </tbody></table></div>
    </div>

    <div class="card">
        <div class="card-head"><h3>Pergerakan Stok Terbaru</h3><div class="spacer"></div>
            <a class="btn btn-sm" href="<?= e(app_url('movements')) ?>">Semua pergerakan</a></div>
        <div class="table-wrap table-scroll"><table class="data compact">
            <thead><tr><th>Waktu</th><th>Bahan</th><th>Jenis</th><th class="num">Jumlah</th><th class="num">Stok Akhir</th></tr></thead>
            <tbody>
            <?php foreach ($recentMovements as $m): ?>
                <tr><td><?= tgl($m['moved_at'], true) ?></td>
                    <td><?= e($m['ing_name']) ?></td>
                    <td><span class="badge <?= e(movement_badge_class($m['movement_type'])) ?>"><?= e(movement_label($m['movement_type'])) ?></span></td>
                    <td class="num" style="color:<?= (float)$m['qty'] < 0 ? 'var(--danger)' : 'var(--ok)' ?>;">
                        <?= ((float)$m['qty'] > 0 ? '+' : '') . num($m['qty']) ?></td>
                    <td class="num"><?= num($m['stock_after']) ?> <?= e($m['unit']) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
    </div>
</div>

<div class="card section">
    <div class="card-head"><h3>Peringatan Kedaluwarsa Bahan (≤ <?= EXPIRY_WARNING_DAYS ?> hari)</h3>
        <div class="spacer"></div><span class="badge <?= $expiring ? 'warn' : 'ok' ?>"><?= count($expiring) ?> batch</span></div>
    <div class="table-wrap table-scroll"><table class="data compact">
        <thead><tr><th>Bahan</th><th>Kedaluwarsa</th><th class="num">Sisa Hari</th><th class="num">Qty Batch</th><th>Nota</th><th>Supplier</th><th>Catatan</th></tr></thead>
        <tbody>
        <?php foreach ($expiring as $x): ?>
            <tr>
                <td><?= e($x['ingredient_name']) ?></td>
                <td><?= tgl($x['expiry_date']) ?></td>
                <td class="num"><span class="badge <?= (int)$x['days_left'] < 0 ? 'danger' : ((int)$x['days_left'] <= 3 ? 'warn' : 'info') ?>">
                    <?= (int)$x['days_left'] < 0 ? 'Lewat ' . abs((int)$x['days_left']) . ' hari' : (int)$x['days_left'] . ' hari' ?></span></td>
                <td class="num"><?= num($x['qty']) ?> <?= e($x['unit']) ?></td>
                <td><?= e($x['purchase_code']) ?></td>
                <td><?= e($x['supplier_name'] ?? '-') ?></td>
                <td><?= e($x['expiry_info'] ?? '') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$expiring): ?><tr><td colspan="7" class="empty">Tidak ada bahan yang mendekati kedaluwarsa. 👍</td></tr><?php endif; ?>
        </tbody></table></div>
</div>
