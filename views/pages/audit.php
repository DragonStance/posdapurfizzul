<?php
/** Audit trail: riwayat aktivitas pengguna dan perubahan data. */
[$from, $to] = date_range(null, null, 14);
$userF = (string)(g('user', '') ?? '');
$actionF = (string)(g('action', '') ?? '');
$search = (string)(g('q', '') ?? '');
$where = ['date(logged_at) BETWEEN :from AND :to'];
$params = [':from' => $from, ':to' => $to];
if ($userF !== '') { $where[] = 'user_name = :u'; $params[':u'] = $userF; }
if ($actionF !== '') { $where[] = 'action = :a'; $params[':a'] = $actionF; }
if ($search !== '') { $where[] = '(description LIKE :q OR entity LIKE :q OR user_name LIKE :q)'; $params[':q'] = '%' . $search . '%'; }
$w = implode(' AND ', $where);
$total = (int)qv($pdo, "SELECT COUNT(*) FROM audit_logs WHERE $w", $params);
[$page, $per, $offset, $pages, $total] = paginate($total, 40);
$rows = q($pdo, "SELECT * FROM audit_logs WHERE $w ORDER BY logged_at DESC, id DESC LIMIT $per OFFSET $offset", $params);
$actions = array_column(q($pdo, 'SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'action');
$usernames = array_column(q($pdo, 'SELECT DISTINCT user_name FROM audit_logs WHERE user_name IS NOT NULL ORDER BY user_name'), 'user_name');
$byAction = q($pdo, "SELECT action, COUNT(*) AS n FROM audit_logs WHERE $w GROUP BY action ORDER BY n DESC LIMIT 8", $params);
?>
<div class="grid grid-kpi">
    <?= kpi_card('Total Aktivitas', number_format($total, 0, ',', '.'), tgl($from) . ' – ' . tgl($to), '🕵️', true) ?>
    <?= kpi_card('Aktivitas Terbanyak', $byAction ? ucfirst(str_replace('_', ' ', $byAction[0]['action'])) : '—',
        $byAction ? (int)$byAction[0]['n'] . ' kejadian' : 'Belum ada', '📌') ?>
    <?= kpi_card('Pengguna Aktif', (string)count($usernames), 'Punya jejak aktivitas pada log', '👥') ?>
    <?= kpi_card('Jenis Aksi', (string)count($actions), 'Aksi yang tercatat sistem', '🗂️') ?>
</div>

<div class="notice info section" style="margin-bottom:20px;">
    <span>ℹ️</span><span>Audit trail mencatat siapa, kapan, dan apa yang diubah — termasuk transaksi penjualan, pembelian, waste,
    penyesuaian stok, persetujuan stock opname, perubahan master data, ekspor data, dan aktivitas login. Log tidak dapat
    diubah dari antarmuka aplikasi.</span>
</div>

<div class="card">
    <div class="card-head"><h2>Riwayat Aktivitas</h2>
        <div class="spacer"></div>
        <a class="btn" href="<?= e(export_base()) ?>?type=audit&from=<?= e($from) ?>&to=<?= e($to) ?>">⇩ Ekspor CSV</a>
    </div>
    <form class="filter-bar" method="get" action="<?= e(self_url()) ?>" style="margin-bottom:14px;">
        <div class="field"><label>Dari</label><input type="date" name="from" value="<?= e($from) ?>"></div>
        <div class="field"><label>Sampai</label><input type="date" name="to" value="<?= e($to) ?>"></div>
        <div class="field"><label>Pengguna</label><select name="user"><option value="">Semua pengguna</option><?= opts($usernames, $userF) ?></select></div>
        <div class="field"><label>Aksi</label><select name="action"><option value="">Semua aksi</option><?= opts($actions, $actionF) ?></select></div>
        <div class="field"><label>Cari</label><input type="text" name="q" value="<?= e($search) ?>" placeholder="deskripsi / entitas"></div>
        <button class="btn btn-primary" type="submit">Filter</button>
        <a class="btn" href="<?= e(app_url('audit')) ?>">Reset</a>
    </form>
    <div class="table-wrap"><table class="data">
        <thead><tr><th>Waktu</th><th>Pengguna</th><th class="mid">Aksi</th><th>Entitas</th><th>Deskripsi</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= tgl($r['logged_at'], true) ?></td>
                <td><?= e($r['user_name'] ?? '-') ?></td>
                <td class="mid"><?= action_badge((string)$r['action']) ?></td>
                <td><?= e($r['entity'] ?? '-') ?><?= $r['entity_id'] ? ' #' . (int)$r['entity_id'] : '' ?></td>
                <td style="max-width:560px;"><?= e((string)$r['description']) ?></td>
                <td><?= e((string)$r['ip']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="6" class="empty">Tidak ada aktivitas pada filter ini.</td></tr><?php endif; ?>
        </tbody></table></div>
    <?= pager_html($page, $pages, $total) ?>
</div>
