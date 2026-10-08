<?php
declare(strict_types=1);

/* ============================ Utilitas umum ============================ */

/**
 * Escape HTML untuk keluaran ke halaman.
 *
 * Didefinisikan hanya bila belum ada: bila aplikasi dijalankan di dalam Laravel,
 * helper e() milik Laravel yang dipakai (perilakunya sama: htmlspecialchars dengan
 * ENT_QUOTES dan UTF-8), sehingga tidak terjadi "Cannot redeclare e()".
 * Seluruh fungsi lain di berkas ini memakai nama khas aplikasi (now_str(),
 * app_url(), app_asset(), dan seterusnya) agar tidak bertabrakan dengan helper
 * Laravel seperti now(), url(), asset(), redirect().
 */
if (!function_exists('e')) {
    function e(?string $s): string
    {
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    }
}

function now_str(): string
{
    return date('Y-m-d H:i:s');
}

function today_str(): string
{
    return date('Y-m-d');
}

function rp($n, bool $withPrefix = true): string
{
    $n = (float)$n;
    $s = number_format($n, 0, ',', '.');
    return $withPrefix ? 'Rp ' . $s : $s;
}

/** Format angka: 2 desimal hanya jika perlu -> 2,5 / 250 */
function num($n, int $dec = 2): string
{
    $n = (float)$n;
    $s = number_format($n, $dec, ',', '.');
    $s = rtrim(rtrim($s, '0'), ',');
    return $s === '' ? '0' : $s;
}

function qtyfmt($n, ?string $unit = null): string
{
    $s = num($n, 3);
    return $unit !== null && $unit !== '' ? $s . ' ' . $unit : $s;
}

function pct($n, int $dec = 1): string
{
    return number_format((float)$n, $dec, ',', '.') . '%';
}

function rpShort($n): string
{
    $n = (float)$n;
    if (abs($n) >= 1_000_000_000) return 'Rp ' . number_format($n / 1_000_000_000, 1, ',', '.') . ' M';
    if (abs($n) >= 1_000_000)     return 'Rp ' . number_format($n / 1_000_000, 1, ',', '.') . ' jt';
    if (abs($n) >= 1_000)         return 'Rp ' . number_format($n / 1_000, 0, ',', '.') . ' rb';
    return 'Rp ' . number_format($n, 0, ',', '.');
}

function tgl(?string $dt, bool $withTime = false): string
{
    if (!$dt) return '-';
    $ts = strtotime($dt);
    if ($ts === false) return '-';
    $bulan = [1=>'Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    $s = date('j', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y', $ts);
    if ($withTime) $s .= ' ' . date('H:i', $ts);
    return $s;
}

function tglInput(?string $dt): string
{
    if (!$dt) return '';
    $ts = strtotime($dt);
    return $ts ? date('Y-m-d', $ts) : '';
}

function hariNama(?string $dt): string
{
    $hari = ['Sunday'=>'Minggu','Monday'=>'Senin','Tuesday'=>'Selasa','Wednesday'=>'Rabu','Thursday'=>'Kamis','Friday'=>'Jumat','Saturday'=>'Sabtu'];
    return $hari[date('l', strtotime((string)$dt))] ?? '';
}

/** Normalisasi angka dari input manusia: "1.500,5" -> 1500.5 */
function parseNum($val, float $default = 0.0): float
{
    if (is_int($val) || is_float($val)) return (float)$val;
    $s = trim((string)$val);
    if ($s === '') return $default;
    $s = preg_replace('/[^0-9,.\-]/', '', $s);
    if ($s === '' || $s === '-') return $default;
    if (str_contains($s, ',') && str_contains($s, '.')) {
        // 1.500,50 -> hilangkan titik ribuan, koma jadi desimal
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif (str_contains($s, ',')) {
        $s = str_replace(',', '.', $s);
    } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
        $s = str_replace('.', '', $s);
    }
    return (float)$s;
}

/**
 * Angka dari input HTML <input type="number"> (selalu desimal titik) atau teks bebas.
 * Nilai kanonik seperti "68.653" diperlakukan sebagai 68,653 (desimal titik),
 * sedangkan teks format Indonesia seperti "1.500" tetap dibaca 1500.
 */
function num_input($raw, float $default = 0.0): float
{
    if (is_int($raw) || is_float($raw)) return (float)$raw;
    $s = trim((string)$raw);
    if ($s === '') return $default;
    if (preg_match('/^-?\d+(\.\d+)?$/', $s)) return (float)$s;
    return parseNum($s, $default);
}

/* ============================ Satuan ============================ */

function unit_label(?string $u): string
{
    return UNITS[$u]['label'] ?? (string)$u;
}

function unit_options(): array
{
    return array_keys(UNITS);
}

/** Konversi jumlah antar satuan. Mengembalikan jumlah dalam satuan tujuan. */
function convert_unit(float $qty, string $from, string $to): float
{
    if ($from === $to) return $qty;
    $f = UNITS[$from] ?? null;
    $t = UNITS[$to] ?? null;
    if ($f === null || $t === null || $f['dim'] !== $t['dim']) {
        return $qty; // dimensi berbeda -> dianggap satuan sama (dijaga oleh validasi UI)
    }
    return $qty * (float)$f['factor'] / (float)$t['factor'];
}

/* ============================ Filter & format ============================ */

/** Rentang tanggal dari query string (default: 30 hari terakhir s/d hari ini) */
function date_range(?string $from = null, ?string $to = null, int $defaultDays = 30): array
{
    $from = $from ?? ($_GET['from'] ?? null);
    $to   = $to   ?? ($_GET['to'] ?? null);
    $today = today_str();
    if (!$to || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$to)) $to = $today;
    if (!$from || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$from)) {
        $from = date('Y-m-d', strtotime($to . ' -' . ($defaultDays - 1) . ' days'));
    }
    if (strtotime($from) > strtotime($to)) { [$from, $to] = [$to, $from]; }
    return [$from, $to];
}

function range_params(): string
{
    [$from, $to] = date_range();
    return 'from=' . urlencode($from) . '&to=' . urlencode($to);
}

function stock_status(float $stock, float $min): array
{
    if ($stock <= 0.00001) return ['Habis', 'danger'];
    if ($min > 0 && $stock < $min * STOCK_CRITICAL_RATIO) return ['Kritis', 'danger'];
    if ($min > 0 && $stock < $min) return ['Stok Rendah', 'warn'];
    return ['Normal', 'ok'];
}

function movement_label(string $type): string
{
    return MOVEMENT_TYPES[$type] ?? $type;
}

function movement_badge_class(string $type): string
{
    return match ($type) {
        'pembelian' => 'ok',
        'penjualan' => 'info',
        'waste' => 'danger',
        'penyesuaian', 'opname' => 'warn',
        'saldo_awal' => 'muted',
        default => 'muted',
    };
}

function opname_status_badge(string $s): string
{
    return match ($s) {
        'disetujui' => 'ok',
        'diajukan' => 'warn',
        'ditolak' => 'danger',
        default => 'muted',
    };
}

/* ============================ Flash / session ============================ */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flash(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/**
 * Token keamanan (CSRF).
 *
 * Token disimpan di DUA tempat: sesi (utama) dan cookie khusus (cadangan,
 * pola "double submit"). Bila penyimpanan sesi hosting bermasalah sehingga
 * sesi baru terbentuk di tengah proses login, token dari cookie masih dapat
 * memvalidasi pengiriman form -- inilah yang mencegah munculnya pesan
 * "sesi kedaluwarsa" pada percobaan login pertama.
 * Cookie ini HttpOnly + SameSite=Lax sehingga tidak dapat dibaca/dipalsukan
 * oleh situs lain.
 */
function app_csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    $token = (string)$_SESSION['csrf'];
    // hanya kirim ulang cookie bila memang berbeda (hindari header Set-Cookie ganda)
    if ((string)($_COOKIE[CSRF_COOKIE] ?? '') !== $token) {
        csrf_set_cookie($token);
    }
    return $token;
}

/**
 * Siapkan token keamanan SEBELUM halaman menghasilkan keluaran apa pun.
 * Penting: memanggil cookie setelah HTML mulai dikirim membuat header Set-Cookie
 * diabaikan browser (inilah sebab token cadangan tidak pernah terkirim).
 */
function csrf_init(): void
{
    $sent = (string)($_COOKIE[CSRF_COOKIE] ?? '');
    $validSent = preg_match('/^[a-f0-9]{32,}$/i', $sent) === 1;

    if (empty($_SESSION['csrf'])) {
        // Sesi baru/kosong (mis. penyimpanan sesi hosting bermasalah) tetapi browser
        // masih membawa token dari halaman sebelumnya => token itu DIPAKAI, bukan diganti.
        // Inilah kunci agar login pertama tetap berhasil tanpa refresh.
        $_SESSION['csrf'] = $validSent ? $sent : bin2hex(random_bytes(24));
    }

    $token = (string)$_SESSION['csrf'];
    // Sinkronkan cookie hanya bila berbeda (jangan menimpa token yang baru saja dikirim browser)
    if (!$validSent || !hash_equals($token, $sent)) {
        csrf_set_cookie($token);
    }
}

/** Simpan token pada cookie cadangan (tanpa akses JavaScript) */
function csrf_set_cookie(string $token): void
{
    // Penanda dalam satu permintaan: hindari header Set-Cookie ganda.
    // (Tidak bisa mengandalkan $_COOKIE karena pembaruannya berbeda antar SAPI PHP.)
    static $sudahDikirim = null;
    if ($sudahDikirim === $token) {
        return;
    }
    if (headers_sent()) {
        return;
    }
    $sudahDikirim = $token;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
    setcookie(CSRF_COOKIE, $token, [
        'expires'  => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Hapus token cookie (dipakai saat keluar) */
function csrf_clear_cookie(): void
{
    if (headers_sent()) {
        return;
    }
    setcookie(CSRF_COOKIE, '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
}

function app_csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(app_csrf_token()) . '">';
}

/** Apakah token yang dikirim cocok dengan sesi ATAU cookie cadangan? */
function csrf_valid(string $sent): bool
{
    if ($sent === '') {
        return false;
    }
    $sess = (string)($_SESSION['csrf'] ?? '');
    $cook = (string)($_COOKIE[CSRF_COOKIE] ?? '');
    return ($sess !== '' && hash_equals($sess, $sent))
        || ($cook !== '' && hash_equals($cook, $sent));
}

/**
 * Verifikasi token untuk aksi biasa.
 * Bila tidak cocok, pengguna TIDAK dihadapkan pada halaman galat, melainkan
 * diarahkan kembali dengan pesan yang jelas (token diperbarui / sesi berakhir).
 */
function csrf_check(): void
{
    if (csrf_valid((string)($_POST['_csrf'] ?? ''))) {
        return;
    }
    // perbarui token + cookie agar percobaan berikutnya pasti berhasil
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
    csrf_set_cookie((string)$_SESSION['csrf']);
    $back = (string)($_POST['_back'] ?? '');
    if (is_logged_in() && preg_match('#^(\.\./)?[A-Za-z0-9_\-]+/(\?[A-Za-z0-9_%&=\.\-\+]*)?$#', $back)) {
        flash('warn', 'Token keamanan halaman diperbarui. Silakan ulangi aksi Anda sekali lagi.');
        header('Location: ' . $back, true, 303);
        exit;
    }
    flash('warn', 'Sesi Anda telah berakhir. Silakan masuk kembali untuk melanjutkan.');
    header('Location: ' . app_url('login'), true, 303);
    exit;
}

/**
 * Verifikasi token khusus form login (dijalankan dari views/login.php).
 * Mengembalikan true bila valid; bila tidak, halaman login ditampilkan ulang
 * dengan pesan ramah + nama pengguna yang tadi diisi (tanpa perlu refresh manual).
 */
function csrf_check_login(): bool
{
    if (csrf_valid((string)($_POST['_csrf'] ?? ''))) {
        return true;
    }
    $_SESSION['login_retry'] = [
        'username' => trim((string)($_POST['username'] ?? '')),
        'at'       => now_str(),
    ];
    // token + cookie baru: percobaan login berikutnya dijamin cocok
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
    csrf_set_cookie((string)$_SESSION['csrf']);
    flash('warn', 'Halaman login diperbarui demi keamanan. Silakan tekan tombol "Masuk" sekali lagi — data Anda sudah disiapkan.');
    header('Location: ' . app_url('login'), true, 303);
    exit;
}

/* ============================ Navigasi & tabel ============================ */

/** Prefix relatif menuju akar aplikasi dari halaman yang sedang dibuka */
function base_rel(): string
{
    return str_repeat('../', max(0, (int)($GLOBALS['SIMOP_DEPTH'] ?? 1)));
}

/** URL folder halaman yang sedang dibuka (untuk action form) */
function self_url(): string
{
    return (string)($GLOBALS['SIMOP_SELF'] ?? './');
}

/** Alamat berkas aset (CSS/JS) */
function app_asset(string $path): string
{
    return base_rel() . 'assets/' . ltrim($path, '/');
}

/** Alamat skrip ekspor CSV */
function export_base(): string
{
    return base_rel() . 'export.php';
}

function export_url(string $query): string
{
    return export_base() . '?' . ltrim($query, '?');
}

/**
 * URL rapi sebuah halaman, mis. app_url('sales', ['from' => '2026-01-01'])
 * -> "../penjualan/?from=2026-01-01"
 */
function app_url(string $page, array $params = []): string
{
    $dir = PAGE_DIR[$page] ?? PAGE_DIR['dashboard'];
    // halaman detail di folder yang sama: parameter penanda menyesuaikan folder
    if ($page === 'recipe_edit' && isset($params['id']) && !isset($params['menu'])) {
        $params['menu'] = $params['id'];
        unset($params['id']);
    }
    $q = $params ? '?' . http_build_query($params) : '';
    return base_rel() . $dir . '/' . $q;
}

function app_redirect(string $page, array $params = []): void
{
    header('Location: ' . app_url($page, $params));
    exit;
}

/** Nilai GET aman */
function g(string $key, ?string $default = null): ?string
{
    $v = $_GET[$key] ?? null;
    return is_string($v) && $v !== '' ? $v : $default;
}

function gi(string $key, int $default = 0): int
{
    return (int)parseNum($_GET[$key] ?? $default);
}

/** Sort helper: membangun ORDER BY dari whitelist kolom */
function sort_state(array $whitelist, string $defaultCol, string $defaultDir = 'desc'): array
{
    $col = (string)(g('sort') ?? $defaultCol);
    $dir = strtolower((string)(g('dir') ?? $defaultDir)) === 'asc' ? 'asc' : 'desc';
    if (!isset($whitelist[$col])) { $col = $defaultCol; $dir = $defaultDir; }
    return [$whitelist[$col], $dir, $col];
}

function sort_link(string $label, string $key, array $whitelist): string
{
    [$sql, $dir, $cur] = sort_state($whitelist, '');
    $next = ($cur === $key && $dir === 'asc') ? 'desc' : 'asc';
    $params = $_GET;
    $params['sort'] = $key;
    $params['dir'] = $next;
    $arrow = $cur === $key ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    return '<a class="th-sort" href="?' . e(http_build_query($params)) . '">' . e($label) . $arrow . '</a>';
}

function paginate(int $total, int $perPage = 20): array
{
    $page = max(1, gi('page', 1));
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;
    return [$page, $perPage, $offset, $pages, $total];
}

function pager_html(int $page, int $pages, int $total): string
{
    if ($pages <= 1) return '<div class="pager-meta">' . number_format($total, 0, ',', '.') . ' data</div>';
    $out = '<div class="pager">';
    $params = $_GET;
    foreach ([[1, '« Awal'], [max(1, $page - 1), '‹ Sebelumnya'], [min($pages, $page + 1), 'Berikutnya ›'], [$pages, 'Akhir »']] as [$p, $label]) {
        $params['page'] = $p;
        $cls = ($p === $page || ($p === max(1, $page - 1) && $page === 1) || ($p === min($pages, $page + 1) && $page === $pages)) ? 'pg disabled' : 'pg';
        $out .= '<a class="' . $cls . '" href="?' . e(http_build_query($params)) . '">' . e($label) . '</a>';
    }
    $out .= '<span class="pager-meta">Hal ' . $page . ' / ' . $pages . ' &middot; ' . number_format($total, 0, ',', '.') . ' data</span>';
    $out .= '</div>';
    return $out;
}

/* ============================ JSON helper ============================ */

function json_out($data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}

function json_in(): array
{
    $raw = file_get_contents('php://input') ?: '';
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
