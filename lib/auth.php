<?php
declare(strict_types=1);

/* ============================ Autentikasi & RBAC ============================ */

function current_user(): ?array
{
    static $cached = null;
    if (isset($_SESSION['uid'])) {
        if ($cached === null) {
            $cached = q1(db(), 'SELECT u.*, r.code AS role_code, r.name AS role_name, r.permissions
                              FROM users u JOIN roles r ON r.id = u.role_id
                              WHERE u.id = ? AND u.is_active = 1', [(int)$_SESSION['uid']]);
        }
        return $cached;
    }
    return null;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function user_role(): string
{
    return (string)(current_user()['role_code'] ?? '');
}

function can(string $permission): bool
{
    $u = current_user();
    if (!$u) return false;
    // Sumber kebenaran hak akses: config.php (PERMISSIONS) berdasarkan role pengguna
    $perms = PERMISSIONS[(string)$u['role_code']] ?? [];
    return in_array($permission, $perms, true);
}

function require_login(): void
{
    if (!is_logged_in()) {
        app_redirect('login');
    }
}

function require_perm(string $permission): void
{
    require_login();
    if (!can($permission)) {
        http_response_code(403);
        include VIEW_DIR . '/pages/403.php';
        exit;
    }
}

/**
 * Halaman pertama yang boleh dibuka oleh pengguna (dipakai setelah login,
 * agar role tanpa akses Dashboard langsung dibawa ke halaman yang relevan).
 */
function landing_page(): string
{
    if (!is_logged_in()) {
        return 'login';
    }
    $order = [
        'dashboard' => 'dashboard',
        'sales' => 'sales',
        'availability' => 'availability',
        'purchases' => 'purchase',
        'waste' => 'waste',
        'inventory' => 'inventory',
        'movements' => 'inventory',
        'opname' => 'opname',
        'recipes' => 'recipes_view',
        'analytics' => 'analytics',
        'reports' => 'reports',
        'master_ingredients' => 'master',
        'master_users' => 'users',
        'audit' => 'audit',
        'settings' => 'settings',
    ];
    foreach ($order as $page => $perm) {
        if (can($perm)) {
            return $page;
        }
    }
    return 'login';
}

function attempt_login(string $username, string $password): ?array
{
    $pdo = db();
    $user = q1($pdo, 'SELECT u.*, r.code AS role_code, r.name AS role_name
                      FROM users u JOIN roles r ON r.id = u.role_id
                      WHERE u.username = ?', [trim($username)]);
    if (!$user || (int)$user['is_active'] !== 1) {
        return null;
    }
    if (!password_verify($password, (string)$user['password_hash'])) {
        return null;
    }
    return $user;
}

function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    // token baru setelah login (mencegah session fixation) + perbarui cookie cadangan
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
    csrf_set_cookie((string)$_SESSION['csrf']);
    tx(db(), function (PDO $pdo) use ($user) {
        update_row($pdo, 'users', (int)$user['id'], ['last_login' => now_str()]);
        audit($pdo, 'login', 'users', (int)$user['id'], 'Login berhasil: ' . $user['username']);
    });
}

function logout_user(): void
{
    if (is_logged_in()) {
        tx(db(), fn(PDO $pdo) => audit($pdo, 'logout', 'users', (int)current_user()['id'], 'Logout'));
    }
    csrf_clear_cookie();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
    }
    session_destroy();
    // Pastikan permintaan berikutnya memakai ID sesi baru, sehingga baris sesi lama
    // (mis. pada tabel `sessions` MySQL) benar-benar tidak dapat dipakai lagi.
    session_id('');
}
