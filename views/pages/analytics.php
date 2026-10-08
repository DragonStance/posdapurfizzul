<?php
/** Analitik & Business Intelligence (5 seksi, filter rentang tanggal). */
[$from, $to] = date_range();
$tab = (string)(g('tab', 'penjualan') ?? 'penjualan');
$tabs = ['penjualan' => 'Penjualan', 'inventory' => 'Inventory', 'pembelian' => 'Pembelian', 'waste' => 'Waste', 'profit' => 'Profitabilitas Menu'];
if (!isset($tabs[$tab])) $tab = 'penjualan';
$days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
$sum = sales_summary($pdo, $from, $to);
$prevFrom = date('Y-m-d', strtotime($from . ' -' . $days . ' days'));
$prevTo = date('Y-m-d', strtotime($from . ' -1 day'));
$prevSum = sales_summary($pdo, $prevFrom, $prevTo);
$growth = (float)$prevSum['omzet'] > 0 ? ((float)$sum['omzet'] - (float)$prevSum['omzet']) / (float)$prevSum['omzet'] * 100 : 0;
?>
<div class="card">
    <div class="card-head">
        <h2>Analitik Operasional</h2>
        <span class="badge info"><?= tgl($from) ?> – <?= tgl($to) ?> (<?= $days ?> hari)</span>
        <div class="spacer"></div>
        <a class="btn btn-sm" href="<?= e(export_base()) ?>?type=profitability&from=<?= e($from) ?>&to=<?= e($to) ?>">⇩ Ekspor profitabilitas</a>
    </div>
    <?= range_form('analytics', ['tab' => $tab]) ?>
</div>

<div class="tabs" style="margin-top:20px;">
    <?php foreach ($tabs as $k => $lbl): ?>
        <a class="tab <?= $tab === $k ? 'active' : '' ?>" href="<?= e(app_url('analytics', ['tab' => $k, 'from' => $from, 'to' => $to])) ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'penjualan'): ?>
    <?php
    $daily = a_sales_daily($pdo, $from, $to);
    $weekly = a_sales_weekly($pdo, $from, $to);
    $monthly = a_sales_monthly($pdo, $from, $to);
    $byMenu = a_sales_by_menu($pdo, $from, $to);
    $byChannel = a_sales_by_channel($pdo, $from, $to);
    $byPayment = a_sales_by_payment($pdo, $from, $to);
    $best = $byMenu[0] ?? null;
    $worst = $byMenu ? $byMenu[count($byMenu) - 1] : null;
    $avgPerDay = $days > 0 ? (float)$sum['omzet'] / $days : 0;
    ?>
    <div class="grid grid-kpi">
        <?= kpi_card('Total Penjualan', rp($sum['omzet']), ($growth >= 0 ? '▲ ' : '▼ ') . pct(abs($growth)) . ' vs periode sebelumnya', '💰', true) ?>
        <?= kpi_card('Jumlah Transaksi', number_format($sum['trx'], 0, ',', '.'), 'Rata-rata ' . rp($sum['avg_trx']) . ' / transaksi', '🧾') ?>
        <?= kpi_card('Rata-rata Harian', rp($avgPerDay), num($sum['qty'], 0) . ' porsi terjual', '📅') ?>
        <?= kpi_card('Margin Kotor', pct($sum['margin']), 'Laba kotor ' . rp($sum['laba']), '📈', true) ?>
    </div>

    <div class="grid grid-2 section">
        <div class="card">
            <div class="card-head"><h3>Penjualan per Hari</h3></div>
            <?= chart_box('apDaily', [
                'type' => 'line', 'format' => 'rp',
                'labels' => array_map(fn($r) => date('d/m', strtotime($r['d'])), $daily),
                'datasets' => [
                    ['label' => 'Omzet', 'data' => array_map(fn($r) => (float)$r['omzet'], $daily), 'fill' => true],
                    ['label' => 'Jumlah Transaksi', 'data' => array_map(fn($r) => (int)$r['trx'], $daily), 'yAxisID' => 'y'],
                ],
            ]) ?>
            <div class="table-wrap table-scroll" style="margin-top:12px;max-height:230px;"><table class="data compact">
                <thead><tr><th>Tanggal</th><th>Hari</th><th class="num">Transaksi</th><th class="num">Omzet</th><th class="num">Laba Kotor</th></tr></thead>
                <tbody>
                <?php foreach (array_reverse($daily) as $d): if ((int)$d['trx'] === 0) continue; ?>
                    <tr><td><?= tgl($d['d']) ?></td><td><?= hariNama($d['d']) ?></td>
                        <td class="num"><?= (int)$d['trx'] ?></td><td class="num"><?= rp($d['omzet']) ?></td>
                        <td class="num"><?= rp($d['laba']) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
        </div>
        <div class="card">
            <div class="card-head"><h3>Penjualan per Channel &amp; Metode Pembayaran</h3></div>
            <?= chart_box('apChannel', [
                'type' => 'doughnut', 'format' => 'rp',
                'labels' => array_column($byChannel, 'channel'),
                'datasets' => [['label' => 'Omzet', 'data' => array_map(fn($r) => (float)$r['omzet'], $byChannel)]],
            ], 'short') ?>
            <div class="table-wrap" style="margin-top:12px;"><table class="data compact">
                <thead><tr><th>Channel</th><th class="num">Transaksi</th><th class="num">Omzet</th><th class="num">Kontribusi</th></tr></thead>
                <tbody>
                <?php foreach ($byChannel as $c): ?>
                    <tr><td><?= e($c['channel']) ?></td><td class="num"><?= (int)$c['trx'] ?></td><td class="num"><?= rp($c['omzet']) ?></td>
                        <td class="num"><?= pct((float)$sum['omzet'] > 0 ? (float)$c['omzet'] / (float)$sum['omzet'] * 100 : 0) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <div class="table-wrap" style="margin-top:12px;"><table class="data compact">
                <thead><tr><th>Metode Pembayaran</th><th class="num">Transaksi</th><th class="num">Omzet</th></tr></thead>
                <tbody>
                <?php foreach ($byPayment as $p): ?>
                    <tr><td><?= e($p['payment_method']) ?></td><td class="num"><?= (int)$p['trx'] ?></td><td class="num"><?= rp($p['omzet']) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
        </div>
    </div>

    <div class="grid grid-2 section">
        <div class="card">
            <div class="card-head"><h3>Penjualan Berdasarkan Menu</h3></div>
            <?= chart_box('apMenu', [
                'type' => 'bar', 'format' => 'rp',
                'labels' => array_map(fn($r) => str_short($r['name'], 20), $byMenu),
                'datasets' => [['label' => 'Omzet', 'data' => array_map(fn($r) => (float)$r['omzet'], $byMenu)]],
            ]) ?>
            <div class="table-wrap table-scroll" style="margin-top:12px;max-height:240px;"><table class="data compact">
                <thead><tr><th>Menu</th><th class="num">Porsi</th><th class="num">Omzet</th><th class="num">Kontribusi</th></tr></thead>
                <tbody>
                <?php foreach ($byMenu as $m): ?>
                    <tr><td><?= e($m['name']) ?></td><td class="num"><?= num($m['qty']) ?></td><td class="num"><?= rp($m['omzet']) ?></td>
                        <td class="num"><?= pct((float)$sum['omzet'] > 0 ? (float)$m['omzet'] / (float)$sum['omzet'] * 100 : 0) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
        </div>
        <div class="card">
            <div class="card-head"><h3>Perbandingan Periode</h3></div>
            <?= chart_box('apWeekly', [
                'type' => 'bar', 'format' => 'rp',
                'labels' => array_map(fn($r) => 'Minggu ' . date('d/m', strtotime($r['mulai'])), $weekly),
                'datasets' => [['label' => 'Omzet per Minggu', 'data' => array_map(fn($r) => (float)$r['omzet'], $weekly)]],
            ], 'short') ?>
            <div class="table-wrap" style="margin-top:12px;"><table class="data compact">
                <thead><tr><th>Periode</th><th class="num">Transaksi</th><th class="num">Omzet</th><th class="num">HPP</th><th class="num">Laba</th></tr></thead>
                <tbody>
                <?php foreach ($monthly as $m): ?>
                    <tr><td><?= date('M Y', strtotime($m['m'] . '-01')) ?></td><td class="num"><?= (int)$m['trx'] ?></td>
                        <td class="num"><?= rp($m['omzet']) ?></td><td class="num"><?= rp($m['hpp']) ?></td>
                        <td class="num"><?= rp((float)$m['omzet'] - (float)$m['hpp']) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
            <div class="grid grid-2" style="margin-top:12px;">
                <div class="card" style="box-shadow:none;padding:12px;">
                    <div class="k-label">Menu Terlaris</div>
                    <b><?= e($best['name'] ?? '—') ?></b>
                    <div class="li-sub"><?= $best ? num($best['qty']) . ' porsi · ' . rp($best['omzet']) : 'Belum ada penjualan' ?></div>
                </div>
                <div class="card" style="box-shadow:none;padding:12px;">
                    <div class="k-label">Menu Paling Sedikit Terjual</div>
                    <b><?= e($worst['name'] ?? '—') ?></b>
                    <div class="li-sub"><?= $worst ? num($worst['qty']) . ' porsi · ' . rp($worst['omzet']) : 'Belum ada penjualan' ?></div>
                </div>
            </div>
        </div>
    </div>

<?php elseif ($tab === 'inventory'): ?>
    <?php
    $usage = a_usage_by_ingredient($pdo, $from, $to);
    $turnover = a_stock_turnover($pdo, $from, $to);
    $lowFreq = a_low_stock_frequency($pdo, $from, $to);
    $purchaseDaily = a_purchase_daily($pdo, $from, $to);
    $usageDaily = a_usage_daily($pdo, $from, $to);
    $topByCost = $usage;
    usort($topByCost, fn($a, $b) => (float)$b['cost'] <=> (float)$a['cost']);
    $totalUsageCost = array_sum(array_map(fn($r) => (float)$r['cost'], $usage));
    ?>
    <div class="grid grid-kpi">
        <?= kpi_card('Total Nilai Bahan Terpakai', rp($totalUsageCost), count($usage) . ' jenis bahan bergerak', '🏷️', true) ?>
        <?= kpi_card('Bahan Paling Banyak Dipakai', count($topByCost) ? $topByCost[0]['name'] : '—',
            count($topByCost) ? rp($topByCost[0]['cost']) . ' · ' . num($topByCost[0]['qty']) . ' ' . unit_label($topByCost[0]['unit']) : 'Belum ada data', '🥇') ?>
        <?= kpi_card('Bahan Sering Stok Rendah', (string)count($lowFreq), 'Bahan yang sempat di bawah stok minimum', '⚠️') ?>
        <?= kpi_card('Bahan Dinilai Stok', (string)count($turnover), 'Dasar perhitungan perputaran stok', '📦') ?>
    </div>

    <div class="grid grid-2 section">
        <div class="card">
            <div class="card-head"><h3>Bahan Paling Banyak Digunakan (nilai Rupiah)</h3>
                <div class="spacer"></div><span class="badge info">urut biaya</span></div>
            <p style="font-size:12px;color:var(--muted);">Diurutkan berdasarkan nilai biaya bahan terpakai agar satuan berbeda
                (gram, kg, liter, ikat) tetap dapat dibandingkan secara adil.</p>
            <?= chart_box('aiUsage', [
                'type' => 'bar', 'format' => 'rp',
                'labels' => array_map(fn($r) => str_short($r['name'], 18), array_slice($topByCost, 0, 8)),
                'datasets' => [['label' => 'Nilai terpakai', 'data' => array_map(fn($r) => (float)$r['cost'], array_slice($topByCost, 0, 8))]],
            ], 'short') ?>
        </div>
        <div class="card">
            <div class="card-head"><h3>Tren Penggunaan Bahan (total biaya/hari)</h3></div>
            <?= chart_box('aiUsageTrend', [
                'type' => 'line', 'format' => 'rp',
                'labels' => array_map(fn($r) => date('d/m', strtotime($r['d'])), $usageDaily),
                'datasets' => [['label' => 'Nilai bahan terpakai', 'data' => array_map(fn($r) => (float)$r['cost'], $usageDaily), 'fill' => true]],
            ], 'short') ?>
            <div class="card-head" style="margin-top:14px;"><h3>Tren Jumlah Pembelian Bahan</h3></div>
            <?= chart_box('aiPurchaseTrend', [
                'type' => 'bar', 'format' => 'rp',
                'labels' => array_map(fn($r) => date('d/m', strtotime($r['d'])), $purchaseDaily),
                'datasets' => [['label' => 'Biaya pembelian', 'data' => array_map(fn($r) => (float)$r['total'], $purchaseDaily)]],
            ], 'short') ?>
        </div>
    </div>

    <div class="card section">
        <div class="card-head"><h3>Perputaran Stok &amp; Frekuensi Stok Rendah</h3>
            <div class="spacer"></div><input type="text" data-filter="#tbl-turnover" placeholder="🔍 Cari bahan…" style="max-width:220px;"></div>
        <div class="table-wrap table-scroll" style="max-height:460px;"><table class="data compact" id="tbl-turnover">
            <thead><tr><th>Bahan</th><th>Satuan</th><th class="num">Terpakai</th><th class="num">Nilai Terpakai</th>
                <th class="num">Stok Sekarang</th><th class="num">Perputaran (kali)</th><th class="num">Hari Persediaan</th>
                <th class="num">Sering Stok Rendah</th></tr></thead>
            <tbody>
            <?php
            $lowMap = [];
            foreach ($lowFreq as $l) $lowMap[(int)$l['id']] = (int)$l['kali'];
            foreach ($turnover as $t):
                $used = (float)$t['used'];
                $stok = (float)$t['current_stock'];
                $turn = $stok > 0 ? $used / $stok : ($used > 0 ? 99 : 0);
                $hari = $used > 0 ? ($stok / ($used / max($days, 1))) : null;
            ?>
                <tr><td><?= e($t['name']) ?></td><td><?= e($t['unit']) ?></td>
                    <td class="num"><?= num($used) ?></td><td class="num"><?= rp($t['cost']) ?></td>
                    <td class="num"><?= num($stok) ?></td>
                    <td class="num"><b><?= num($turn, 2) ?>×</b></td>
                    <td class="num"><?= $hari === null ? '—' : num($hari, 1) . ' hari' ?></td>
                    <td class="num"><?= $lowMap[(int)$t['id']] ?? 0 ?>×</td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <div style="font-size:11.5px;color:var(--muted);margin-top:10px;">
            Perputaran = total bahan terpakai (penjualan, penggunaan manual, waste) dibagi stok saat ini pada periode terpilih.
            "Hari persediaan" = estimasi berapa hari stok saat ini akan bertahan pada laju pemakaian periode ini.
        </div>
    </div>

<?php elseif ($tab === 'pembelian'): ?>
    <?php
    $pSum = a_purchase_summary($pdo, $from, $to);
    $bySupplier = a_purchase_by_supplier($pdo, $from, $to);
    $byIng = a_purchase_by_ingredient($pdo, $from, $to);
    $trend = a_price_trend($pdo, $from, $to);
    $bySupPrice = a_price_by_supplier($pdo, $from, $to);
    $pDaily = a_purchase_daily($pdo, $from, $to);
    $topPriceChange = [];
    $grouped = [];
    foreach ($trend as $t) { $grouped[$t['name']][$t['periode']] = (float)$t['unit_cost_avg']; }
    foreach ($grouped as $name => $series) {
        $vals = array_values($series);
        if (count($vals) < 2) continue;
        $first = $vals[0]; $last = end($vals);
        $topPriceChange[] = ['name' => $name, 'first' => $first, 'last' => $last,
            'change' => $first > 0 ? ($last - $first) / $first * 100 : 0, 'unit' => ''];
    }
    usort($topPriceChange, fn($a, $b) => abs($b['change']) <=> abs($a['change']));
    ?>
    <div class="grid grid-kpi">
        <?= kpi_card('Total Biaya Pembelian', rp($pSum['total']), $pSum['n'] . ' nota pada periode ini', '💳', true) ?>
        <?= kpi_card('Jumlah Supplier Bertransaksi', (string)count($bySupplier), $bySupplier ? 'Terbesar: ' . $bySupplier[0]['name'] : 'Belum ada', '🚚') ?>
        <?= kpi_card('Jenis Bahan Dibeli', (string)count($byIng), 'Bahan dengan riwayat pembelian', '🧂') ?>
        <?= kpi_card('Rata-rata per Nota', rp($pSum['n'] > 0 ? $pSum['total'] / $pSum['n'] : 0), 'Nilai belanja rata-rata', '🧾') ?>
    </div>

    <div class="grid grid-2 section">
        <div class="card">
            <div class="card-head"><h3>Biaya Pembelian per Supplier</h3></div>
            <?= chart_box('abSupplier', [
                'type' => 'bar', 'format' => 'rp',
                'labels' => array_map(fn($r) => str_short($r['name'] ?? 'Tanpa supplier', 20), $bySupplier),
                'datasets' => [['label' => 'Total belanja', 'data' => array_map(fn($r) => (float)$r['total'], $bySupplier)]],
            ], 'short') ?>
            <div class="table-wrap" style="margin-top:12px;"><table class="data compact">
                <thead><tr><th>Supplier</th><th class="num">Nota</th><th class="num">Total</th><th class="num">Kontribusi</th></tr></thead>
                <tbody>
                <?php foreach ($bySupplier as $s): ?>
                    <tr><td><?= e($s['name'] ?? 'Tanpa supplier') ?></td><td class="num"><?= (int)$s['trx'] ?></td>
                        <td class="num"><?= rp($s['total']) ?></td>
                        <td class="num"><?= pct($pSum['total'] > 0 ? (float)$s['total'] / $pSum['total'] * 100 : 0) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
        </div>
        <div class="card">
            <div class="card-head"><h3>Tren Biaya Pembelian Harian</h3></div>
            <?= chart_box('abDaily', [
                'type' => 'line', 'format' => 'rp',
                'labels' => array_map(fn($r) => date('d/m', strtotime($r['d'])), $pDaily),
                'datasets' => [['label' => 'Biaya pembelian', 'data' => array_map(fn($r) => (float)$r['total'], $pDaily), 'fill' => true]],
            ], 'short') ?>
            <div class="card-head" style="margin-top:14px;"><h3>Perubahan Harga Bahan Terbesar</h3></div>
            <div class="table-wrap" style="max-height:200px;overflow:auto;"><table class="data compact">
                <thead><tr><th>Bahan</th><th class="num">Harga Awal</th><th class="num">Harga Terakhir</th><th class="num">Perubahan</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($topPriceChange, 0, 8) as $c): ?>
                    <tr><td><?= e($c['name']) ?></td><td class="num"><?= rp($c['first']) ?></td><td class="num"><?= rp($c['last']) ?></td>
                        <td class="num" style="color:<?= $c['change'] > 0 ? 'var(--danger)' : 'var(--ok)' ?>;"><?= ($c['change'] > 0 ? '+' : '') . pct($c['change']) ?></td></tr>
                <?php endforeach; ?>
                <?php if (!$topPriceChange): ?><tr><td colspan="4" class="empty">Data perubahan harga belum cukup (butuh pembelian pada lebih dari satu bulan).</td></tr><?php endif; ?>
                </tbody></table></div>
        </div>
    </div>

    <div class="card section">
        <div class="card-head"><h3>Perbandingan Harga Antar Supplier</h3>
            <div class="spacer"></div><input type="text" data-filter="#tbl-price" placeholder="🔍 Cari bahan/supplier…" style="max-width:240px;"></div>
        <div class="table-wrap table-scroll" style="max-height:400px;"><table class="data compact" id="tbl-price">
            <thead><tr><th>Bahan</th><th>Supplier</th><th class="num">Qty Dibeli</th><th class="num">Harga Rata-rata</th>
                <th class="num">Termurah</th><th class="num">Termahal</th><th class="mid">Posisi Harga</th></tr></thead>
            <tbody>
            <?php
            $bestByIng = [];
            foreach ($bySupPrice as $r) {
                $k = $r['ingredient_name'];
                if (!isset($bestByIng[$k]) || (float)$r['avg_cost'] < (float)$bestByIng[$k]) $bestByIng[$k] = (float)$r['avg_cost'];
            }
            foreach ($bySupPrice as $r):
                $isBest = abs((float)$r['avg_cost'] - (float)$bestByIng[$r['ingredient_name']]) < 0.01;
            ?>
                <tr><td><?= e($r['ingredient_name']) ?> <small>(<?= e($r['unit']) ?>)</small></td>
                    <td><?= e($r['supplier_name'] ?? 'Tanpa supplier') ?></td>
                    <td class="num"><?= num($r['qty']) ?></td>
                    <td class="num"><b><?= rp($r['avg_cost']) ?></b></td>
                    <td class="num"><?= rp($r['min_cost']) ?></td>
                    <td class="num"><?= rp($r['max_cost']) ?></td>
                    <td class="mid"><?= $isBest ? '<span class="badge ok">Termurah</span>' : '<span class="badge muted">Lebih mahal</span>' ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$bySupPrice): ?><tr><td colspan="7" class="empty">Belum ada data pembelian pada periode ini.</td></tr><?php endif; ?>
            </tbody></table></div>
    </div>

    <div class="card section">
        <div class="card-head"><h3>Biaya Pembelian per Bahan</h3></div>
        <div class="table-wrap table-scroll" style="max-height:340px;"><table class="data compact">
            <thead><tr><th>Bahan</th><th class="num">Qty Dibeli</th><th class="num">Total Biaya</th><th class="num">Harga Rata-rata</th><th class="num">Kontribusi</th></tr></thead>
            <tbody>
            <?php foreach ($byIng as $r): ?>
                <tr><td><?= e($r['name']) ?></td><td class="num"><?= num($r['qty']) ?> <?= e($r['unit']) ?></td>
                    <td class="num"><?= rp($r['total']) ?></td><td class="num"><?= rp($r['avg_cost']) ?></td>
                    <td class="num"><?= pct($pSum['total'] > 0 ? (float)$r['total'] / $pSum['total'] * 100 : 0) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$byIng): ?><tr><td colspan="5" class="empty">Belum ada data.</td></tr><?php endif; ?>
            </tbody></table></div>
    </div>

<?php elseif ($tab === 'waste'): ?>
    <?php
    $wsum = a_waste_summary($pdo, $from, $to);
    $byReason = a_waste_by_reason($pdo, $from, $to);
    $byIng = a_waste_by_ingredient($pdo, $from, $to);
    $wDaily = a_waste_daily($pdo, $from, $to);
    $hppPeriod = (float)$sum['hpp'];
    $worstIng = $byIng[0] ?? null;
    ?>
    <div class="grid grid-kpi">
        <?= kpi_card('Total Biaya Waste', rp($wsum['cost']), $wsum['n'] . ' catatan waste', '🗑️', true) ?>
        <?= kpi_card('Rasio Waste terhadap HPP', pct($hppPeriod > 0 ? $wsum['cost'] / $hppPeriod * 100 : 0), 'HPP periode ' . rp($hppPeriod), '📉') ?>
        <?= kpi_card('Bahan Paling Banyak Terbuang', $worstIng ? $worstIng['name'] : '—',
            $worstIng ? rp($worstIng['cost']) . ' · ' . num($worstIng['qty']) . ' ' . unit_label($worstIng['unit']) : 'Belum ada data', '🥇') ?>
        <?= kpi_card('Rata-rata per Catatan', rp($wsum['n'] > 0 ? $wsum['cost'] / $wsum['n'] : 0), 'Estimasi biaya per kejadian', '💵') ?>
    </div>

    <div class="grid grid-2 section">
        <div class="card">
            <div class="card-head"><h3>Waste Berdasarkan Alasan</h3></div>
            <?= chart_box('awReason', [
                'type' => 'doughnut', 'format' => 'rp',
                'labels' => array_column($byReason, 'reason'),
                'datasets' => [['label' => 'Biaya', 'data' => array_map(fn($r) => (float)$r['cost'], $byReason)]],
            ], 'short') ?>
            <div class="table-wrap" style="margin-top:12px;"><table class="data compact">
                <thead><tr><th>Alasan</th><th class="num">Catatan</th><th class="num">Volume</th><th class="num">Biaya</th><th class="num">Kontribusi</th></tr></thead>
                <tbody>
                <?php foreach ($byReason as $r): ?>
                    <tr><td><?= e($r['reason']) ?></td><td class="num"><?= (int)$r['n'] ?></td><td class="num"><?= num($r['qty']) ?></td>
                        <td class="num"><?= rp($r['cost']) ?></td>
                        <td class="num"><?= pct($wsum['cost'] > 0 ? (float)$r['cost'] / $wsum['cost'] * 100 : 0) ?></td></tr>
                <?php endforeach; ?>
                </tbody></table></div>
        </div>
        <div class="card">
            <div class="card-head"><h3>Tren Waste dari Waktu ke Waktu</h3></div>
            <?= chart_box('awTrend', [
                'type' => 'bar', 'format' => 'rp',
                'labels' => array_map(fn($r) => date('d/m', strtotime($r['d'])), $wDaily),
                'datasets' => [['label' => 'Biaya waste', 'data' => array_map(fn($r) => (float)$r['cost'], $wDaily)]],
            ], 'short') ?>
            <div class="card-head" style="margin-top:14px;"><h3>Waste Berdasarkan Bahan</h3></div>
            <?= chart_box('awIng', [
                'type' => 'bar', 'format' => 'rp',
                'labels' => array_map(fn($r) => str_short($r['name'], 18), array_slice($byIng, 0, 8)),
                'datasets' => [['label' => 'Biaya waste', 'data' => array_map(fn($r) => (float)$r['cost'], array_slice($byIng, 0, 8))]],
            ], 'short') ?>
        </div>
    </div>

    <div class="card section">
        <div class="card-head"><h3>Rincian Waste per Bahan</h3></div>
        <div class="table-wrap table-scroll" style="max-height:340px;"><table class="data compact">
            <thead><tr><th>Bahan</th><th class="num">Volume</th><th class="num">Biaya</th><th class="num">Kejadian</th><th class="num">Kontribusi Biaya</th></tr></thead>
            <tbody>
            <?php foreach ($byIng as $r): ?>
                <tr><td><?= e($r['name']) ?></td><td class="num"><?= num($r['qty']) ?> <?= e($r['unit']) ?></td>
                    <td class="num"><?= rp($r['cost']) ?></td><td class="num"><?= (int)$r['n'] ?></td>
                    <td class="num"><?= pct($wsum['cost'] > 0 ? (float)$r['cost'] / $wsum['cost'] * 100 : 0) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$byIng): ?><tr><td colspan="5" class="empty">Tidak ada waste pada periode ini.</td></tr><?php endif; ?>
            </tbody></table></div>
    </div>

<?php else: ?>
    <?php
    $prof = a_menu_profitability($pdo, $from, $to);
    $sortKey = (string)(g('psort', 'omzet') ?? 'omzet');
    $dirP = strtolower((string)(g('pdir', 'desc') ?? 'desc')) === 'asc' ? 'asc' : 'desc';
    $sorters = [
        'name' => fn($a, $b) => strcasecmp($a['name'], $b['name']),
        'qty' => fn($a, $b) => $a['qty'] <=> $b['qty'],
        'price' => fn($a, $b) => $a['price'] <=> $b['price'],
        'hpp' => fn($a, $b) => $a['hpp_unit'] <=> $b['hpp_unit'],
        'laba' => fn($a, $b) => $a['laba_total'] <=> $b['laba_total'],
        'margin' => fn($a, $b) => $a['margin'] <=> $b['margin'],
        'omzet' => fn($a, $b) => $a['omzet'] <=> $b['omzet'],
    ];
    $cmp = $sorters[$sortKey] ?? $sorters['omzet'];
    usort($prof, fn($a, $b) => $dirP === 'asc' ? $cmp($a, $b) : $cmp($b, $a));
    $totOmzet = array_sum(array_map(fn($r) => (float)$r['omzet'], $prof));
    $totLaba = array_sum(array_map(fn($r) => (float)$r['laba_total'], $prof));
    $totHpp = array_sum(array_map(fn($r) => (float)$r['hpp_total'], $prof));
    $bestMargin = $prof ? max(array_map(fn($r) => $r['margin'], $prof)) : 0;
    $worstMargin = $prof ? min(array_map(fn($r) => $r['margin'], $prof)) : 0;
    ?>
    <div class="grid grid-kpi">
        <?= kpi_card('Total Omzet Menu', rp($totOmzet), 'Periode ' . tgl($from) . ' – ' . tgl($to), '💰', true) ?>
        <?= kpi_card('Total Estimasi Laba Kotor', rp($totLaba), 'Margin rata-rata ' . pct($totOmzet > 0 ? $totLaba / $totOmzet * 100 : 0), '📈', true) ?>
        <?= kpi_card('Total HPP Menu Terjual', rp($totHpp), 'Dari resep & harga bahan saat transaksi', '📉') ?>
        <?= kpi_card('Sebaran Margin', pct($worstMargin) . ' – ' . pct($bestMargin), 'Margin terendah hingga tertinggi', '⚖️') ?>
    </div>

    <div class="card section">
        <div class="card-head"><h3>Tabel Profitabilitas Menu</h3>
            <div class="spacer"></div>
            <input type="text" data-filter="#tbl-profit" placeholder="🔍 Cari menu…" style="max-width:200px;">
            <a class="btn btn-sm" href="<?= e(export_base()) ?>?type=profitability&from=<?= e($from) ?>&to=<?= e($to) ?>">⇩ Ekspor CSV</a></div>
        <div class="table-wrap table-scroll" style="max-height:560px;"><table class="data" id="tbl-profit">
            <thead><tr>
                <th><a class="th-sort" href="?<?= e(http_build_query(array_merge($_GET, ['psort' => 'name', 'pdir' => $sortKey === 'name' && $dirP === 'asc' ? 'desc' : 'asc']))) ?>">Menu<?= $sortKey === 'name' ? ($dirP === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
                <th>Kategori</th>
                <th><a class="th-sort" href="?<?= e(http_build_query(array_merge($_GET, ['psort' => 'qty', 'pdir' => $sortKey === 'qty' && $dirP === 'asc' ? 'desc' : 'asc']))) ?>">Terjual<?= $sortKey === 'qty' ? ($dirP === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
                <th><a class="th-sort" href="?<?= e(http_build_query(array_merge($_GET, ['psort' => 'price', 'pdir' => $sortKey === 'price' && $dirP === 'asc' ? 'desc' : 'asc']))) ?>">Harga Jual<?= $sortKey === 'price' ? ($dirP === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
                <th><a class="th-sort" href="?<?= e(http_build_query(array_merge($_GET, ['psort' => 'hpp', 'pdir' => $sortKey === 'hpp' && $dirP === 'asc' ? 'desc' : 'asc']))) ?>">HPP / Porsi<?= $sortKey === 'hpp' ? ($dirP === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
                <th class="num">Laba / Porsi</th>
                <th class="num">Total Omzet</th>
                <th class="num">Total HPP</th>
                <th><a class="th-sort" href="?<?= e(http_build_query(array_merge($_GET, ['psort' => 'laba', 'pdir' => $sortKey === 'laba' && $dirP === 'asc' ? 'desc' : 'asc']))) ?>">Est. Total Laba<?= $sortKey === 'laba' ? ($dirP === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
                <th><a class="th-sort" href="?<?= e(http_build_query(array_merge($_GET, ['psort' => 'margin', 'pdir' => $sortKey === 'margin' && $dirP === 'asc' ? 'desc' : 'asc']))) ?>">Margin<?= $sortKey === 'margin' ? ($dirP === 'asc' ? ' ▲' : ' ▼') : '' ?></a></th>
            </tr></thead>
            <tbody>
            <?php foreach ($prof as $r): ?>
                <tr>
                    <td><b><?= e($r['name']) ?></b><div class="li-sub"><?= e($r['code']) ?></div></td>
                    <td><?= e($r['category']) ?></td>
                    <td class="num"><?= num($r['qty']) ?> porsi</td>
                    <td class="num"><?= rp($r['price']) ?></td>
                    <td class="num"><?= rp($r['hpp_unit']) ?></td>
                    <td class="num"><?= rp($r['laba_unit']) ?></td>
                    <td class="num"><?= rp($r['omzet']) ?></td>
                    <td class="num"><?= rp($r['hpp_total']) ?></td>
                    <td class="num"><b><?= rp($r['laba_total']) ?></b></td>
                    <td class="num"><span class="badge <?= $r['margin_real'] >= 50 ? 'ok' : ($r['margin_real'] >= 30 ? 'warn' : 'danger') ?>"><?= pct($r['margin_real']) ?></span></td>
                </tr>
            <?php endforeach; ?>
            <tr class="total-row"><td colspan="6">TOTAL</td><td class="num"><?= rp($totOmzet) ?></td><td class="num"><?= rp($totHpp) ?></td>
                <td class="num"><?= rp($totLaba) ?></td><td class="num"><?= pct($totOmzet > 0 ? $totLaba / $totOmzet * 100 : 0) ?></td></tr>
            </tbody></table></div>
        <div style="font-size:11.5px;color:var(--muted);margin-top:10px;">
            <b>HPP/Porsi</b> = estimasi biaya bahan berdasarkan resep &amp; harga bahan terkini. <b>Total HPP</b> dan <b>Est. Total Laba</b>
            memakai HPP yang tercatat pada masing-masing transaksi (harga bahan pada saat penjualan), sehingga lebih akurat untuk periode lampau.
        </div>
    </div>

    <div class="grid grid-2 section">
        <div class="card">
            <div class="card-head"><h3>Kontribusi Laba per Menu</h3></div>
            <?= chart_box('aprLaba', [
                'type' => 'bar', 'format' => 'rp',
                'labels' => array_map(fn($r) => str_short($r['name'], 18), $prof),
                'datasets' => [['label' => 'Estimasi laba kotor', 'data' => array_map(fn($r) => (float)$r['laba_total'], $prof)]],
            ], 'short') ?>
        </div>
        <div class="card">
            <div class="card-head"><h3>Struktur Harga Jual vs HPP</h3></div>
            <?= chart_box('aprStack', [
                'type' => 'bar',
                'labels' => array_map(fn($r) => str_short($r['name'], 18), $prof),
                'datasets' => [
                    ['label' => 'HPP / porsi', 'data' => array_map(fn($r) => (float)$r['hpp_unit'], $prof)],
                    ['label' => 'Laba kotor / porsi', 'data' => array_map(fn($r) => (float)$r['laba_unit'], $prof)],
                ],
                'format' => 'rp',
            ], 'short') ?>
            <div style="font-size:11.5px;color:var(--muted);margin-top:8px;">Selisih setiap batang menunjukkan margin kotor per porsi tiap menu.</div>
        </div>
    </div>
<?php endif; ?>
