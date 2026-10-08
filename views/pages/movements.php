<?php
/** Pergerakan Stok — audit trail seluruh perubahan stok. */
[$from, $to] = date_range(null, null, 7);
$ingF = gi('ingredient_id');
$typeF = (string)(g('type', '') ?? '');
$search = (string)(g('q', '') ?? '');
$where = ['date(m.moved_at) BETWEEN :from AND :to'];
$params = [':from' => $from, ':to' => $to];
if ($ingF > 0) { $where[] = 'm.ingredient_id = :ing'; $params[':ing'] = $ingF; }
if ($typeF !== '' && isset(MOVEMENT_TYPES[$typeF])) { $where[] = 'm.movement_type = :t'; $params[':t'] = $typeF; }
if ($search !== '') {
    $where[] = '(i.name LIKE :q OR m.ref_code LIKE :q OR m.note LIKE :q OR m.user_name LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
$w = implode(' AND ', $where);

$total = (int)qv($pdo, "SELECT COUNT(*) FROM inventory_movements m JOIN ingredients i ON i.id = m.ingredient_id WHERE $w", $params);
[$page, $per, $offset, $pages, $total] = paginate($total, 30);
$rows = q($pdo, "SELECT m.*, i.name AS ing_name, i.unit AS ing_unit FROM inventory_movements m
                 JOIN ingredients i ON i.id = m.ingredient_id
                 WHERE $w ORDER BY m.moved_at DESC, m.id DESC LIMIT $per OFFSET $offset", $params);
$agg = q($pdo, "SELECT m.movement_type, COUNT(*) AS n, COALESCE(SUM(m.qty),0) AS qty, COALESCE(SUM(m.total_cost),0) AS cost
                FROM inventory_movements m JOIN ingredients i ON i.id = m.ingredient_id
                WHERE $w GROUP BY m.movement_type ORDER BY n DESC", $params);
$masuk = 0.0; $keluar = 0.0; $biaya = 0.0;
foreach ($agg as $a) { if ((float)$a['qty'] > 0) $masuk += (float)$a['qty']; else $keluar += abs((float)$a['qty']); $biaya += (float)$a['cost']; }
$ingredients = q($pdo, 'SELECT id, name FROM ingredients ORDER BY name');
?>
<div class="grid grid-kpi">
    <?= kpi_card('Total Pergerakan', number_format($total, 0, ',', '.'), tgl($from) . ' – ' . tgl($to), '🔄', true) ?>
    <?= kpi_card('Volume Masuk', num($masuk, 2), 'Satuan campuran mengikuti bahan', '⬆️') ?>
    <?= kpi_card('Volume Keluar', num($keluar, 2), 'Pemakaian penjualan, waste, dll.', '⬇️') ?>
    <?= kpi_card('Nilai Tercatat', rp($biaya), 'Estimasi nilai pergerakan', '💵') ?>
</div>

<div class="card section">
    <div class="card-head"><h2>Histori Pergerakan Stok</h2>
        <div class="spacer"></div>
        <a class="btn" href="<?= e(export_base()) ?>?type=movements&from=<?= e($from) ?>&to=<?= e($to) ?><?= $ingF ? '&ingredient_id=' . $ingF : '' ?>" target="_blank">⇩ Ekspor CSV</a>
    </div>
    <form class="filter-bar" method="get" action="<?= e(self_url()) ?>" style="margin-bottom:16px;">
        <div class="field"><label>Dari</label><input type="date" name="from" value="<?= e($from) ?>"></div>
        <div class="field"><label>Sampai</label><input type="date" name="to" value="<?= e($to) ?>"></div>
        <div class="field"><label>Bahan</label><select name="ingredient_id"><option value="">Semua bahan</option>
            <?php foreach ($ingredients as $i): ?><option value="<?= (int)$i['id'] ?>" <?= $ingF === (int)$i['id'] ? 'selected' : '' ?>><?= e($i['name']) ?></option><?php endforeach; ?>
        </select></div>
        <div class="field"><label>Jenis</label><select name="type"><option value="">Semua jenis</option><?= opts(MOVEMENT_TYPES, $typeF, true) ?></select></div>
        <div class="field"><label>Cari</label><input type="text" name="q" value="<?= e($search) ?>" placeholder="referensi / keterangan / pengguna"></div>
        <button class="btn btn-primary" type="submit">Filter</button>
        <a class="btn" href="<?= e(app_url('movements')) ?>">Reset</a>
    </form>

    <div class="grid grid-3" style="margin-bottom:16px;">
        <?php foreach ($agg as $a): ?>
            <div class="card" style="box-shadow:none;padding:12px 14px;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <span class="badge <?= e(movement_badge_class($a['movement_type'])) ?>"><?= e(movement_label($a['movement_type'])) ?></span>
                    <span class="spacer" style="margin-left:auto;"></span>
                    <b><?= (int)$a['n'] ?> catatan</b>
                </div>
                <div style="font-size:12px;color:var(--muted);margin-top:6px;">
                    Volume: <?= num($a['qty'], 2) ?> · Nilai: <?= rp($a['cost']) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="table-wrap">
        <table class="data" id="tbl-mov">
            <thead><tr><th>Waktu</th><th>Bahan</th><th class="mid">Jenis</th><th class="num">Jumlah</th>
                <th class="num">Stok Sebelum</th><th class="num">Stok Setelah</th><th>Referensi</th><th>Keterangan</th><th>Pengguna</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $m): ?>
                <tr>
                    <td><?= tgl($m['moved_at'], true) ?></td>
                    <td><?= e($m['ing_name']) ?></td>
                    <td class="mid"><span class="badge <?= e(movement_badge_class($m['movement_type'])) ?>"><?= e(movement_label($m['movement_type'])) ?></span></td>
                    <td class="num" style="color:<?= (float)$m['qty'] < 0 ? 'var(--danger)' : 'var(--ok)' ?>;">
                        <b><?= ((float)$m['qty'] > 0 ? '+' : '') . num($m['qty']) ?></b> <?= e($m['unit']) ?></td>
                    <td class="num"><?= num($m['stock_before']) ?></td>
                    <td class="num"><?= num($m['stock_after']) ?></td>
                    <td><?php if ($m['ref_code']): ?>
                            <?php if ($m['ref_type'] === 'sale'): ?>
                                <a href="<?= e(app_url('sale_detail', ['id' => $m['ref_id']])) ?>"><?= e($m['ref_code']) ?></a>
                            <?php else: ?><?= e($m['ref_code']) ?><?php endif; ?>
                        <?php else: ?>—<?php endif; ?></td>
                    <td style="max-width:260px;"><?= e(str_short((string)$m['note'], 58)) ?>
                        <div class="li-sub">Nilai: <?= rp($m['total_cost']) ?></div></td>
                    <td><?= e($m['user_name'] ?? '-') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="9" class="empty">Tidak ada pergerakan pada filter ini.</td></tr><?php endif; ?>
            </tbody></table>
    </div>
    <?= pager_html($page, $pages, $total) ?>
</div>
