<?php
/** Halaman login (satu-satunya halaman publik). */
$pdo = db();
$error = null;
$lastUser = '';
// isi ulang nama pengguna bila halaman login sempat ditampilkan ulang (token diperbarui)
if (!empty($_SESSION['login_retry'])) {
    $lastUser = (string)($_SESSION['login_retry']['username'] ?? '');
    unset($_SESSION['login_retry']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check_login();
    $lastUser = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    $user = attempt_login($lastUser, $pass);
    if ($user) {
        login_user($user);
        flash('ok', 'Selamat datang, ' . $user['name'] . '.');
        app_redirect(landing_page());
    }
    $error = 'Nama pengguna atau kata sandi salah, atau akun tidak aktif.';
    tx($pdo, fn(PDO $p) => audit($p, 'login_gagal', 'users', null, 'Percobaan login gagal untuk username: ' . $lastUser));
}
$flashes = take_flash();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Masuk · <?= e(APP_SHORT) ?></title>
<link rel="stylesheet" href="<?= e(app_asset('css/app.css')) ?>?v=<?= e(APP_VERSION) ?>">
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="card">
            <div class="brand">
                <div class="brand-logo">DF</div>
                <div class="brand-text">
                    <b>Dapur Fizzul</b>
                    <span>Sistem Informasi Manajemen Operasional</span>
                </div>
            </div>
            <h1 style="font-size:18px;">Masuk ke Sistem</h1>
            <div class="sub" style="color:var(--muted);font-size:12.5px;margin:6px 0 16px;">
                Aplikasi internal untuk pengelolaan penjualan, resep, bahan baku, stok, pembelian, waste,
                stock opname, dan analitik operasional.
            </div>

            <?php if ($error): ?>
                <div class="notice" style="border-color:rgba(220,38,38,.35);background:rgba(254,242,242,.85);color:#991b1b;margin-bottom:14px;">
                    <span>⚠️</span><span><?= e($error) ?></span>
                </div>
            <?php endif; ?>
            <?php foreach ($flashes as $f): ?>
                <div class="notice info" style="margin-bottom:14px;"><span>ℹ️</span><span><?= e($f['message']) ?></span></div>
            <?php endforeach; ?>

            <form method="post" action="<?= e(self_url()) ?>" class="form-grid one">
                <?= app_csrf_field() ?>
                <div class="field">
                    <label for="username">Nama Pengguna</label>
                    <input type="text" id="username" name="username" value="<?= e($lastUser) ?>" required autofocus autocomplete="username">
                </div>
                <div class="field">
                    <label for="password">Kata Sandi</label>
                    <input type="password" id="password" name="password" required autocomplete="current-password">
                </div>
                <button class="btn btn-primary" type="submit" style="justify-content:center;">Masuk</button>
            </form>

            <div class="demo-cred">
                <b style="font-size:12px;">AKUN DATA DEMO</b>
                <table style="margin-top:6px;">
                    <tr><td>Owner / Admin</td><td><code>owner</code> / <code>fizzul123</code></td></tr>
                    <tr><td>Manager</td><td><code>manager</code> / <code>manager123</code></td></tr>
                    <tr><td>Staff Kasir</td><td><code>kasir</code> / <code>kasir123</code></td></tr>
                    <tr><td>Staff Dapur / Inventory</td><td><code>dapur</code> / <code>dapur123</code></td></tr>
                </table>
                <div style="margin-top:6px;color:#7c4a03;">
                    Sistem ini telah diisi data contoh (penjualan 30 hari terakhir, pembelian, waste, stock opname)
                    agar dashboard dan analitik dapat langsung diuji.
                </div>
            </div>
        </div>
    </div>
</div>
<script src="<?= e(app_asset('js/app.js')) ?>?v=<?= e(APP_VERSION) ?>"></script>
</body>
</html>
