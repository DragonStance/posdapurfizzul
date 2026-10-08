<?php
declare(strict_types=1);

/**
 * Lapisan analitik & business intelligence.
 * SELURUH angka dihitung dari data transaksi yang benar-benar tersimpan
 * di database (sales, sale_items, purchases, purchase_items,
 * inventory_movements, waste_records, recipes).
 */

/* ---------- Penjualan ---------- */

function a_sales_daily(PDO $pdo, string $from, string $to): array
{
    $rows = q($pdo, 'SELECT date(sold_at) AS d, COUNT(*) AS trx, COALESCE(SUM(total),0) AS omzet,
                            COALESCE(SUM(hpp_total),0) AS hpp
                     FROM sales WHERE status = "selesai" AND date(sold_at) BETWEEN ? AND ?
                     GROUP BY date(sold_at)', [$from, $to]);
    $byDate = [];
    foreach ($rows as $r) {
        $r['laba'] = (float)$r['omzet'] - (float)$r['hpp'];
        $byDate[$r['d']] = $r;
    }
    $out = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
        $d = date('Y-m-d', $t);
        $out[] = $byDate[$d] ?? ['d' => $d, 'trx' => 0, 'omzet' => 0.0, 'hpp' => 0.0, 'laba' => 0.0];
    }
    return $out;
}

function a_sales_weekly(PDO $pdo, string $from, string $to): array
{
    $week = sql_week('sold_at');
    return q($pdo, "SELECT $week AS w, date(MIN(sold_at)) AS mulai,
                           COUNT(*) AS trx, COALESCE(SUM(total),0) AS omzet, COALESCE(SUM(hpp_total),0) AS hpp
                    FROM sales WHERE status = 'selesai' AND date(sold_at) BETWEEN ? AND ?
                    GROUP BY w ORDER BY w ASC", [$from, $to]);
}

function a_sales_monthly(PDO $pdo, string $from, string $to): array
{
    $month = sql_month('sold_at');
    return q($pdo, "SELECT $month AS m, COUNT(*) AS trx,
                           COALESCE(SUM(total),0) AS omzet, COALESCE(SUM(hpp_total),0) AS hpp
                    FROM sales WHERE status = 'selesai' AND date(sold_at) BETWEEN ? AND ?
                    GROUP BY m ORDER BY m ASC", [$from, $to]);
}

function a_sales_by_menu(PDO $pdo, string $from, string $to, ?int $limit = null): array
{
    $sql = 'SELECT m.id, m.name, m.category, m.price,
                   COALESCE(SUM(si.qty),0) AS qty,
                   COALESCE(SUM(si.subtotal),0) AS omzet,
                   COALESCE(SUM(si.hpp_total),0) AS hpp
            FROM sale_items si
            JOIN sales s ON s.id = si.sale_id AND s.status = "selesai"
            JOIN menus m ON m.id = si.menu_id
            WHERE date(s.sold_at) BETWEEN ? AND ?
            GROUP BY m.id, m.name, m.category, m.price
            ORDER BY omzet DESC' . ($limit ? ' LIMIT ' . (int)$limit : '');
    return q($pdo, $sql, [$from, $to]);
}

function a_sales_by_channel(PDO $pdo, string $from, string $to): array
{
    return q($pdo, 'SELECT channel, COUNT(*) AS trx, COALESCE(SUM(total),0) AS omzet, COALESCE(SUM(hpp_total),0) AS hpp
                    FROM sales WHERE status = "selesai" AND date(sold_at) BETWEEN ? AND ?
                    GROUP BY channel ORDER BY omzet DESC', [$from, $to]);
}

function a_sales_by_payment(PDO $pdo, string $from, string $to): array
{
    return q($pdo, 'SELECT payment_method, COUNT(*) AS trx, COALESCE(SUM(total),0) AS omzet
                    FROM sales WHERE status = "selesai" AND date(sold_at) BETWEEN ? AND ?
                    GROUP BY payment_method ORDER BY omzet DESC', [$from, $to]);
}

/* ---------- Inventory / penggunaan bahan ---------- */

function a_usage_by_ingredient(PDO $pdo, string $from, string $to, ?int $limit = null, bool $onlySales = false): array
{
    $types = $onlySales ? "('penjualan','penggunaan_manual')" : "('penjualan','penggunaan_manual')";
    $sql = "SELECT i.id, i.name, i.unit, c.name AS category_name,
                   COALESCE(SUM(-m.qty),0) AS qty,
                   COALESCE(SUM(m.total_cost),0) AS cost,
                   COUNT(m.id) AS frekuensi
            FROM inventory_movements m
            JOIN ingredients i ON i.id = m.ingredient_id
            LEFT JOIN ingredient_categories c ON c.id = i.category_id
            WHERE m.qty < 0 AND m.movement_type IN $types AND date(m.moved_at) BETWEEN ? AND ?
            GROUP BY i.id, i.name, i.unit, c.name
            ORDER BY qty DESC" . ($limit ? ' LIMIT ' . (int)$limit : '');
    return q($pdo, $sql, [$from, $to]);
}

function a_usage_daily(PDO $pdo, string $from, string $to, ?int $ingredientId = null): array
{
    if ($ingredientId) {
        $rows = q($pdo, 'SELECT date(moved_at) AS d, COALESCE(SUM(-qty),0) AS qty, COALESCE(SUM(total_cost),0) AS cost
                         FROM inventory_movements
                         WHERE movement_type IN ("penjualan","penggunaan_manual") AND qty < 0
                           AND ingredient_id = ? AND date(moved_at) BETWEEN ? AND ?
                         GROUP BY date(moved_at)', [$ingredientId, $from, $to]);
    } else {
        $rows = q($pdo, 'SELECT date(moved_at) AS d, COALESCE(SUM(-qty),0) AS qty, COALESCE(SUM(total_cost),0) AS cost
                         FROM inventory_movements
                         WHERE movement_type IN ("penjualan","penggunaan_manual") AND qty < 0
                           AND date(moved_at) BETWEEN ? AND ?
                         GROUP BY date(moved_at)', [$from, $to]);
    }
    $byDate = array_column($rows, null, 'd');
    $out = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
        $d = date('Y-m-d', $t);
        $out[] = $byDate[$d] ?? ['d' => $d, 'qty' => 0.0, 'cost' => 0.0];
    }
    return $out;
}

/** Perputaran stok (stock turnover) = total penggunaan / rata-rata stok periode */
function a_stock_turnover(PDO $pdo, string $from, string $to): array
{
    return q($pdo, 'SELECT i.id, i.name, i.unit, i.current_stock, i.min_stock,
                           COALESCE(u.used,0) AS used,
                           COALESCE(u.cost,0) AS cost
                    FROM ingredients i
                    LEFT JOIN (
                        SELECT ingredient_id, SUM(-qty) AS used, SUM(total_cost) AS cost
                        FROM inventory_movements
                        WHERE qty < 0 AND movement_type IN ("penjualan","penggunaan_manual","waste")
                          AND date(moved_at) BETWEEN ? AND ?
                        GROUP BY ingredient_id
                    ) u ON u.ingredient_id = i.id
                    WHERE i.is_active = 1
                    ORDER BY used DESC', [$from, $to]);
}

/** Berapa kali stok berada di bawah stok minimum setelah sebuah pergerakan */
function a_low_stock_frequency(PDO $pdo, string $from, string $to): array
{
    return q($pdo, 'SELECT i.id, i.name, i.unit, i.min_stock, i.current_stock,
                           SUM(CASE WHEN m.stock_after < i.min_stock THEN 1 ELSE 0 END) AS kali,
                           MIN(m.stock_after) AS min_tercapai
                    FROM inventory_movements m
                    JOIN ingredients i ON i.id = m.ingredient_id
                    WHERE i.min_stock > 0 AND date(m.moved_at) BETWEEN ? AND ?
                    GROUP BY i.id, i.name, i.unit, i.min_stock, i.current_stock
                    HAVING kali > 0
                    ORDER BY kali DESC', [$from, $to]);
}

/* ---------- Pembelian ---------- */

function a_purchase_summary(PDO $pdo, string $from, string $to): array
{
    $r = q1($pdo, 'SELECT COUNT(*) AS n, COALESCE(SUM(total_cost),0) AS total
                   FROM purchases WHERE date(purchased_at) BETWEEN ? AND ?', [$from, $to]);
    return ['n' => (int)($r['n'] ?? 0), 'total' => (float)($r['total'] ?? 0)];
}

function a_purchase_by_supplier(PDO $pdo, string $from, string $to): array
{
    return q($pdo, 'SELECT s.id, s.name, COUNT(DISTINCT p.id) AS trx, COALESCE(SUM(p.total_cost),0) AS total
                    FROM purchases p LEFT JOIN suppliers s ON s.id = p.supplier_id
                    WHERE date(p.purchased_at) BETWEEN ? AND ?
                    GROUP BY s.id, s.name ORDER BY total DESC', [$from, $to]);
}

function a_purchase_by_ingredient(PDO $pdo, string $from, string $to): array
{
    return q($pdo, 'SELECT i.id, i.name, i.unit, SUM(pi.qty) AS qty, SUM(pi.total_cost) AS total,
                           AVG(pi.unit_cost) AS avg_cost
                    FROM purchase_items pi
                    JOIN purchases p ON p.id = pi.purchase_id
                    JOIN ingredients i ON i.id = pi.ingredient_id
                    WHERE date(p.purchased_at) BETWEEN ? AND ?
                    GROUP BY i.id, i.name, i.unit ORDER BY total DESC', [$from, $to]);
}

/** Tren harga beli per bahan per bulan */
function a_price_trend(PDO $pdo, string $from, string $to, ?int $ingredientId = null): array
{
    $month = sql_month('p.purchased_at');
    $sql = "SELECT i.id, i.name, i.unit, $month AS periode,
                   SUM(pi.qty) AS qty, SUM(pi.total_cost) AS total,
                   SUM(pi.total_cost) * 1.0 / NULLIF(SUM(pi.qty),0) AS unit_cost_avg
            FROM purchase_items pi
            JOIN purchases p ON p.id = pi.purchase_id
            JOIN ingredients i ON i.id = pi.ingredient_id
            WHERE date(p.purchased_at) BETWEEN ? AND ?";
    $params = [$from, $to];
    if ($ingredientId) { $sql .= ' AND i.id = ?'; $params[] = $ingredientId; }
    $sql .= ' GROUP BY i.id, i.name, i.unit, periode ORDER BY i.name, periode';
    return q($pdo, $sql, $params);
}

/** Harga terakhir tiap supplier untuk setiap bahan (perbandingan antar supplier) */
function a_price_by_supplier(PDO $pdo, string $from, string $to): array
{
    return q($pdo, 'SELECT i.name AS ingredient_name, i.unit, s.name AS supplier_name,
                           AVG(pi.unit_cost) AS avg_cost, MIN(pi.unit_cost) AS min_cost, MAX(pi.unit_cost) AS max_cost,
                           SUM(pi.qty) AS qty, COUNT(*) AS n
                    FROM purchase_items pi
                    JOIN purchases p ON p.id = pi.purchase_id
                    JOIN ingredients i ON i.id = pi.ingredient_id
                    LEFT JOIN suppliers s ON s.id = p.supplier_id
                    WHERE date(p.purchased_at) BETWEEN ? AND ?
                    GROUP BY i.name, i.unit, s.name ORDER BY i.name, avg_cost ASC', [$from, $to]);
}

function a_purchase_daily(PDO $pdo, string $from, string $to): array
{
    $rows = q($pdo, 'SELECT date(purchased_at) AS d, COALESCE(SUM(total_cost),0) AS total, COUNT(*) AS n
                     FROM purchases WHERE date(purchased_at) BETWEEN ? AND ?
                     GROUP BY date(purchased_at)', [$from, $to]);
    $byDate = array_column($rows, null, 'd');
    $out = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
        $d = date('Y-m-d', $t);
        $out[] = $byDate[$d] ?? ['d' => $d, 'total' => 0.0, 'n' => 0];
    }
    return $out;
}

/* ---------- Waste ---------- */

function a_waste_summary(PDO $pdo, string $from, string $to): array
{
    $r = q1($pdo, 'SELECT COUNT(*) AS n, COALESCE(SUM(est_cost),0) AS cost
                   FROM waste_records WHERE status = "confirmed" AND date(wasted_at) BETWEEN ? AND ?', [$from, $to]);
    return ['n' => (int)($r['n'] ?? 0), 'cost' => (float)($r['cost'] ?? 0)];
}

function a_waste_by_ingredient(PDO $pdo, string $from, string $to): array
{
    return q($pdo, 'SELECT i.id, i.name, i.unit, SUM(w.qty) AS qty, SUM(w.est_cost) AS cost, COUNT(*) AS n
                    FROM waste_records w JOIN ingredients i ON i.id = w.ingredient_id
                    WHERE w.status = "confirmed" AND date(w.wasted_at) BETWEEN ? AND ?
                    GROUP BY i.id, i.name, i.unit ORDER BY cost DESC', [$from, $to]);
}

function a_waste_by_reason(PDO $pdo, string $from, string $to): array
{
    return q($pdo, 'SELECT reason, COUNT(*) AS n, SUM(qty) AS qty, SUM(est_cost) AS cost
                    FROM waste_records WHERE status = "confirmed" AND date(wasted_at) BETWEEN ? AND ?
                    GROUP BY reason ORDER BY cost DESC', [$from, $to]);
}

function a_waste_daily(PDO $pdo, string $from, string $to): array
{
    $rows = q($pdo, 'SELECT date(wasted_at) AS d, COALESCE(SUM(est_cost),0) AS cost, COUNT(*) AS n
                     FROM waste_records WHERE status = "confirmed" AND date(wasted_at) BETWEEN ? AND ?
                     GROUP BY date(wasted_at)', [$from, $to]);
    $byDate = array_column($rows, null, 'd');
    $out = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
        $d = date('Y-m-d', $t);
        $out[] = $byDate[$d] ?? ['d' => $d, 'cost' => 0.0, 'n' => 0];
    }
    return $out;
}

/* ---------- Profitabilitas menu ---------- */

function a_menu_profitability(PDO $pdo, string $from, string $to): array
{
    $rows = q($pdo, 'SELECT m.id, m.code, m.name, m.category, m.price, m.is_active,
                            COALESCE(s.qty,0) AS qty, COALESCE(s.omzet,0) AS omzet, COALESCE(s.hpp,0) AS hpp_terjual,
                            COALESCE(s.trx,0) AS trx
                     FROM menus m
                     LEFT JOIN (
                        SELECT si.menu_id, SUM(si.qty) AS qty, SUM(si.subtotal) AS omzet,
                               SUM(si.hpp_total) AS hpp, COUNT(DISTINCT s.id) AS trx
                        FROM sale_items si JOIN sales s ON s.id = si.sale_id AND s.status = "selesai"
                        WHERE date(s.sold_at) BETWEEN ? AND ?
                        GROUP BY si.menu_id
                     ) s ON s.menu_id = m.id
                     ORDER BY omzet DESC, m.name', [$from, $to]);

    foreach ($rows as &$r) {
        $r['hpp_unit'] = menu_hpp($pdo, (int)$r['id'], 1.0);          // HPP saat ini (harga bahan terkini)
        $r['laba_unit'] = round((float)$r['price'] - $r['hpp_unit'], 2);
        $r['margin'] = (float)$r['price'] > 0 ? $r['laba_unit'] / (float)$r['price'] * 100 : 0.0;
        $r['qty'] = (float)$r['qty'];
        // Laba memakai HPP historis transaksi bila ada, agar akurat
        $r['hpp_total'] = $r['qty'] > 0 ? (float)$r['hpp_terjual'] : 0.0;
        $r['laba_total'] = round((float)$r['omzet'] - $r['hpp_total'], 2);
        $r['margin_real'] = (float)$r['omzet'] > 0 ? $r['laba_total'] / (float)$r['omzet'] * 100 : 0.0;
    }
    unset($r);
    return $rows;
}

/* ---------- Ringkasan dashboard ---------- */

function a_dashboard_today(PDO $pdo): array
{
    $t = today_str();
    $s = sales_summary($pdo, $t, $t);
    $w = a_waste_summary($pdo, $t, $t);
    $low = low_stock_rows($pdo);
    return [
        'sales' => $s,
        'waste_cost' => $w['cost'],
        'low_count' => count($low),
        'critical_count' => count(array_filter($low, fn($r) => stock_status((float)$r['current_stock'], (float)$r['min_stock'])[0] !== 'Stok Rendah')),
    ];
}
