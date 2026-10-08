<?php
/** Tampilan laporan siap cetak (window.print). */
[$from, $to] = date_range();
$type = (string)(g('type', 'sales') ?? 'sales');
$opt = ['id' => gi('id'), 'menu_id' => gi('menu_id'), 'ingredient_id' => gi('ingredient_id')];
try {
    $rep = report_definitions($pdo, $type, $from, $to, $opt);
} catch (Throwable $e) {
    echo '<div class="card"><h2>Laporan tidak tersedia</h2><p>' . e($e->getMessage()) . '</p>'
        . '<a class="btn" href="' . e(app_url('reports')) . '">Kembali ke daftar laporan</a></div>';
    return;
}
$business = setting_get($pdo, 'business_name', 'Dapur Fizzul');
$periode = $rep['periode'] . ' (' . tgl($from) . ' – ' . tgl($to) . ')';
$isNumeric = function ($v) { return is_int($v) || is_float($v); };
?>
<div class="card">
    <div class="card-head no-print">
        <h2><?= e($rep['title']) ?></h2>
        <div class="spacer"></div>
        <button class="btn btn-primary" onclick="window.print()">🖨 Cetak / Simpan PDF</button>
        <a class="btn" href="<?= e(export_base()) ?>?type=<?= e($type) ?>&from=<?= e($from) ?>&to=<?= e($to) ?><?= $opt['menu_id'] ? '&menu_id=' . (int)$opt['menu_id'] : '' ?><?= $opt['id'] ? '&id=' . (int)$opt['id'] : '' ?>">⇩ Ekspor CSV</a>
        <a class="btn" href="<?= e(app_url('reports', ['from' => $from, 'to' => $to])) ?>">← Daftar laporan</a>
    </div>

    <div style="border-bottom:2px solid var(--line-strong);padding-bottom:12px;margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:flex-end;">
            <div>
                <div style="font-size:17px;font-weight:700;"><?= e($business) ?></div>
                <div style="font-size:12.5px;color:var(--muted);">Sistem Informasi Manajemen Operasional · Laporan Internal</div>
                <div style="font-size:12.5px;color:var(--muted);"><?= e((string)setting_get($pdo, 'address', 'Ambon, Maluku')) ?>
                    <?= setting_get($pdo, 'phone') ? ' · ' . e((string)setting_get($pdo, 'phone')) : '' ?></div>
            </div>
            <div style="text-align:right;">
                <div style="font-size:15px;font-weight:700;"><?= e($rep['title']) ?></div>
                <div style="font-size:12.5px;color:var(--muted);">Periode: <?= e($periode) ?></div>
                <div style="font-size:12px;color:var(--muted);">Dicetak: <?= tgl(now_str(), true) ?> oleh <?= e(current_user()['name']) ?></div>
            </div>
        </div>
    </div>

    <?php if (!empty($rep['summary'])): ?>
        <div class="grid grid-4" style="margin-bottom:16px;">
            <?php foreach ($rep['summary'] as $label => $val): ?>
                <div class="card" style="box-shadow:none;padding:12px 14px;">
                    <div class="k-label"><?= e((string)$label) ?></div>
                    <div style="font-size:15px;font-weight:700;"><?= e((string)$val) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="table-wrap">
        <table class="data compact">
            <thead><tr>
                <th>#</th>
                <?php foreach ($rep['columns'] as $c): ?><th><?= e((string)$c) ?></th><?php endforeach; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($rep['rows'] as $i => $row): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <?php foreach (array_values($row) as $v): ?>
                        <td class="<?= $isNumeric($v) ? 'num' : '' ?>">
                            <?php
                            if ($v === null || $v === '') echo '—';
                            elseif ($isNumeric($v)) echo str_contains((string)$v, '.') ? num($v, 3) : number_format((float)$v, 0, ',', '.');
                            else echo e(str_contains((string)$v, ':') && strtotime((string)$v) ? tgl((string)$v, true) : (string)$v);
                            ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rep['rows']): ?>
                <tr><td colspan="<?= count($rep['columns']) + 1 ?>" class="empty">Tidak ada data pada periode ini.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div style="margin-top:14px;font-size:11.5px;color:var(--muted);">
        Jumlah baris: <?= count($rep['rows']) ?> · Sumber data: database operasional SIMOP Dapur Fizzul (SQLite) ·
        Laporan ini dihasilkan otomatis dari data transaksi yang tersimpan.
    </div>

    <div class="print-only" style="margin-top:36px;display:flex;justify-content:space-between;">
        <div>Dibuat oleh: ____________________</div>
        <div>Disetujui (Owner): ____________________</div>
    </div>
</div>
