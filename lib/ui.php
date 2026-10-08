<?php
declare(strict_types=1);

/** Komponen UI yang dipakai berulang di halaman. */

function kpi_card(string $label, string $value, string $sub = '', string $icon = '', bool $accent = false, string $valueClass = ''): string
{
    return '<div class="card kpi' . ($accent ? ' accent' : '') . '">'
        . '<div style="display:flex;align-items:center;gap:10px;">'
        . ($icon !== '' ? '<div class="k-icon">' . $icon . '</div>' : '')
        . '<div class="k-label">' . e($label) . '</div></div>'
        . '<div class="k-value ' . e($valueClass) . '">' . $value . '</div>'
        . ($sub !== '' ? '<div class="k-sub">' . $sub . '</div>' : '')
        . '</div>';
}

function chart_box(string $id, array $config, string $heightClass = ''): string
{
    return '<div class="chart-box ' . e($heightClass) . '"><canvas id="' . e($id) . '"></canvas></div>'
        . '<script type="application/json" data-chart="' . e($id) . '">'
        . json_encode($config, JSON_UNESCAPED_UNICODE) . '</script>';
}

/** Form filter rentang tanggal (GET) */
function range_form(string $page, array $extra = [], string $submit = 'Terapkan'): string
{
    [$from, $to] = date_range();
    $html = '<form class="filter-bar" method="get" action="' . e(self_url()) . '">';
    foreach ($extra as $k => $v) {
        $html .= '<input type="hidden" name="' . e((string)$k) . '" value="' . e((string)$v) . '">';
    }
    $html .= '<div class="field"><label>Dari tanggal</label><input type="date" name="from" value="' . e($from) . '"></div>';
    $html .= '<div class="field"><label>Sampai tanggal</label><input type="date" name="to" value="' . e($to) . '"></div>';
    $html .= '<button class="btn btn-primary" type="submit">' . e($submit) . '</button>';
    $html .= '<a class="btn" href="' . e(app_url($page)) . '">Reset</a>';
    $html .= '</form>';
    return $html;
}

/** Opsi <option> dari array sederhana atau asosiatif */
function opts(array $items, $selected = null, bool $keepKeys = false): string
{
    $html = '';
    foreach ($items as $k => $v) {
        $value = $keepKeys ? (string)$k : (string)$v;
        $label = (string)$v;
        $sel = ((string)$selected === $value) ? ' selected' : '';
        $html .= '<option value="' . e($value) . '"' . $sel . '>' . e($label) . '</option>';
    }
    return $html;
}

function stock_badge(float $stock, float $min): string
{
    [$label, $cls] = stock_status($stock, $min);
    return '<span class="badge ' . e($cls) . '">' . e($label) . '</span>';
}

function action_badge(string $type): string
{
    $map = [
        'create' => 'ok', 'update' => 'info', 'void' => 'danger', 'approve' => 'ok', 'reject' => 'danger',
        'login' => 'muted', 'logout' => 'muted', 'login_gagal' => 'danger', 'import' => 'info',
        'submit' => 'warn', 'adjust_stock' => 'warn', 'seed' => 'muted', 'manual_usage' => 'info',
        'reset_password' => 'warn', 'toggle' => 'warn',
    ];
    return '<span class="badge ' . ($map[$type] ?? 'muted') . '">' . e(ucfirst(str_replace('_', ' ', $type))) . '</span>';
}

/** Tabel sederhana: $cols = [label => callable(row)|string field], $opts: num keys */
function mini_table(array $cols, array $rows, string $emptyMsg = 'Belum ada data'): string
{
    if (!$rows) return '<div class="empty">' . e($emptyMsg) . '</div>';
    $html = '<div class="table-wrap"><table class="data compact"><thead><tr>';
    foreach ($cols as $label => $def) {
        $num = str_starts_with((string)$def, ':num');
        $html .= '<th class="' . ($num ? 'num' : '') . '">' . e((string)$label) . '</th>';
    }
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $html .= '<tr>';
        foreach ($cols as $label => $def) {
            if (is_callable($def)) {
                $val = $def($r);
                $cls = str_starts_with((string)$label, '~') ? 'num' : '';
                $html .= '<td class="' . $cls . '">' . $val . '</td>';
            } else {
                $field = ltrim((string)$def, ':num~');
                $cls = str_starts_with((string)$def, ':num') || str_starts_with((string)$label, '~') ? 'num' : '';
                $html .= '<td class="' . $cls . '">' . e((string)($r[$field] ?? '')) . '</td>';
            }
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}

/** Inisial nama (aman walau ekstensi mbstring tidak tersedia) */
function str_initials(string $name, int $max = 2): string
{
    $out = '';
    foreach (preg_split('/\s+/', trim($name)) as $w) {
        if ($w === '') continue;
        $c = function_exists('mb_substr') ? mb_substr($w, 0, 1) : substr($w, 0, 1);
        $out .= function_exists('mb_strtoupper') ? mb_strtoupper($c) : strtoupper($c);
        if ((function_exists('mb_strlen') ? mb_strlen($out) : strlen($out)) >= $max) break;
    }
    return $out;
}

/** Potong teks panjang */
function str_short(?string $s, int $len): string
{
    $s = (string)$s;
    if (function_exists('mb_strimwidth')) return mb_strimwidth($s, 0, $len, '…');
    return strlen($s) > $len ? substr($s, 0, $len - 1) . '…' : $s;
}
