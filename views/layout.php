<?php
/**
 * Layout utama: sidebar + topbar + konten.
 * Variabel dari app.php (front controller): $page, $pageTitle, $pageSubtitle, $NAV, $view, $pdo
 */
$user = current_user();
$initials = str_initials((string)$user['name']);
$lowCount = can('inventory') ? count(low_stock_rows($pdo)) : 0;
$flashes = take_flash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> · <?= e(APP_SHORT) ?></title>
<meta name="description" content="Sistem informasi manajemen operasional internal Dapur Fizzul (UMKM rumah makan khas Maluku).">
<link rel="stylesheet" href="<?= e(app_asset('css/app.css')) ?>?v=<?= e(APP_VERSION) ?>">
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-logo">DF</div>
            <div class="brand-text">
                <b>Dapur Fizzul</b>
                <span>Sistem Operasional Internal</span>
            </div>
        </div>
        <?php foreach ($NAV as $group => $items): ?>
            <?php
            $visible = array_values(array_filter($items, fn($it) => can($it[3])));
            if (!$visible) continue;
            ?>
            <div class="nav-group">
                <div class="nav-title"><?= e($group) ?></div>
                <?php foreach ($visible as [$pg, $label, $icon, $perm]): ?>
                    <a class="nav-link <?= $page === $pg ? 'active' : '' ?>" data-nav="<?= e($pg) ?>" href="<?= e(app_url($pg)) ?>">
                        <span class="ico"><?= $icon ?></span>
                        <span><?= e($label) ?></span>
                        <?php if ($pg === 'inventory' && $lowCount > 0): ?>
                            <span class="nav-badge"><?= $lowCount ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <div class="nav-group">
            <a class="nav-link" href="<?= e(app_url('logout')) ?>"><span class="ico">🚪</span><span>Keluar</span></a>
        </div>
        <div style="padding:14px 10px 0;font-size:10.5px;color:#8F8AC0;">
            v<?= e(APP_VERSION) ?> · Prototipe Capstone<br>Sistem Informasi Manajemen Operasional
        </div>
    </aside>

    <main class="main">
        <div class="topbar">
            <button class="burger" type="button" aria-label="Buka menu">☰</button>
            <div>
                <h1><?= e($pageTitle) ?></h1>
                <div class="sub"><?= e($pageSubtitle) ?></div>
            </div>
            <div class="topbar-actions">
                <?php if (setting_get($pdo, 'demo_mode') === '1'): ?>
                    <span class="demo-tag" title="Aplikasi berisi data contoh untuk pengujian">DATA DEMO</span>
                <?php endif; ?>
                <div class="user-chip">
                    <div class="avatar"><?= e($initials) ?></div>
                    <div>
                        <div class="u-name"><?= e($user['name']) ?></div>
                        <div class="u-role"><?= e(ROLES[$user['role_code']] ?? $user['role_name']) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <?php include $view; ?>

        <div style="margin-top:36px;font-size:11.5px;color:var(--muted);text-align:center;">
            © <?= date('Y') ?> Dapur Fizzul · Sistem Informasi Manajemen Operasional Internal ·
            Seluruh angka dihitung dari data transaksi yang tersimpan di database (SQLite).
        </div>
    </main>
</div>

<div class="toast-stack">
    <?php foreach ($flashes as $f): ?>
        <div class="toast <?= e($f['type']) ?>"><b><?= $f['type'] === 'ok' ? 'Berhasil' : ($f['type'] === 'danger' ? 'Gagal' : 'Perhatian') ?></b><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?php if (!empty($GLOBALS['inline_toasts'])): foreach ($GLOBALS['inline_toasts'] as $f): ?>
        <div class="toast <?= e($f[0]) ?>"><b><?= $f[0] === 'ok' ? 'Berhasil' : ($f[0] === 'danger' ? 'Gagal' : 'Perhatian') ?></b><?= e($f[1]) ?></div>
    <?php endforeach; endif; ?>
</div>

<div id="confirm-modal" class="modal-backdrop">
    <div class="modal">
        <div class="modal-head"><h3 id="confirm-title">Konfirmasi</h3><button class="x-btn" data-close type="button">&times;</button></div>
        <div class="modal-body" id="confirm-body">Apakah Anda yakin?</div>
        <div class="modal-foot">
            <button class="btn" data-close type="button">Batal</button>
            <button class="btn btn-danger" id="confirm-ok" type="button">Ya, lanjutkan</button>
        </div>
    </div>
</div>

<script src="<?= e(app_asset('js/chart.umd.min.js')) ?>"></script>
<script src="<?= e(app_asset('js/app.js')) ?>?v=<?= e(APP_VERSION) ?>"></script>
<script>
/* Dialog konfirmasi generik: form[data-confirm-modal] akan memakai modal ini */
document.addEventListener('submit', function (e) {
    var f = e.target;
    var msg = f.getAttribute('data-confirm-modal');
    if (!msg || f.dataset.confirmed === '1') return;
    e.preventDefault();
    var m = document.getElementById('confirm-modal');
    document.getElementById('confirm-body').textContent = msg;
    document.getElementById('confirm-title').textContent = f.getAttribute('data-confirm-title') || 'Konfirmasi';
    m.classList.add('open');
    document.getElementById('confirm-ok').onclick = function () {
        m.classList.remove('open');
        f.dataset.confirmed = '1';
        f.submit();
    };
});
</script>
</body>
</html>
