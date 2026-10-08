<?php
declare(strict_types=1);

/**
 * Inti logika inventory: SATU-SATUNYA pintu perubahan stok.
 * Setiap perubahan stok WAJIB melalui apply_movement() sehingga selalu
 * menghasilkan catatan pergerakan stok (audit trail) dan stok tidak pernah
 * berubah diam-diam.
 */
function apply_movement(PDO $pdo, int $ingredientId, string $type, float $qty, array $opts = []): int
{
    $ing = q1($pdo, 'SELECT * FROM ingredients WHERE id = ?', [$ingredientId]);
    if (!$ing) {
        throw new RuntimeException('Bahan baku tidak ditemukan (id=' . $ingredientId . ')');
    }
    $before = (float)$ing['current_stock'];
    $after  = round($before + $qty, 4);
    if ($after < 0 && empty($opts['allow_negative'])) {
        throw new RuntimeException(sprintf(
            'Stok %s tidak mencukupi: tersedia %s %s, dibutuhkan %s %s.',
            $ing['name'], num($before, 3), $ing['unit'], num(abs($qty), 3), $ing['unit']
        ));
    }

    $user = current_user();
    $movedAt = $opts['moved_at'] ?? now_str();
    $unitCost = (float)($opts['unit_cost'] ?? $ing['avg_cost']);
    $totalCost = array_key_exists('total_cost', $opts)
        ? (float)$opts['total_cost']
        : round(abs($qty) * $unitCost, 2);

    update_row($pdo, 'ingredients', $ingredientId, [
        'current_stock' => $after,
        'updated_at'    => now_str(),
    ]);

    return insert_row($pdo, 'inventory_movements', [
        'moved_at'      => $movedAt,
        'ingredient_id' => $ingredientId,
        'movement_type' => $type,
        'qty'           => round($qty, 4),
        'unit'          => (string)($opts['unit'] ?? $ing['unit']),
        'stock_before'  => $before,
        'stock_after'   => $after,
        'unit_cost'     => $unitCost,
        'total_cost'    => $totalCost,
        'ref_type'      => $opts['ref_type'] ?? null,
        'ref_id'        => $opts['ref_id'] ?? null,
        'ref_code'      => $opts['ref_code'] ?? null,
        'note'          => $opts['note'] ?? null,
        'user_id'       => $opts['user_id'] ?? ($user['id'] ?? null),
        'user_name'     => $opts['user_name'] ?? ($user['name'] ?? 'Sistem'),
        'created_at'    => now_str(),
    ]);
}

/** Hitung ulang seluruh stok dari pergerakan (dipakai untuk validasi/konsistensi) */
function rebuild_stock(PDO $pdo, ?int $ingredientId = null): void
{
    $sql = 'SELECT id, unit FROM ingredients' . ($ingredientId ? ' WHERE id = ' . (int)$ingredientId : '');
    foreach (q($pdo, $sql) as $ing) {
        $total = (float)qv($pdo, 'SELECT COALESCE(SUM(qty),0) FROM inventory_movements WHERE ingredient_id = ?', [$ing['id']], 0);
        update_row($pdo, 'ingredients', (int)$ing['id'], ['current_stock' => round($total, 4)]);
    }
    // Sinkronkan stock_before/stock_after berdasarkan urutan kronologis
    $ids = $ingredientId ? [$ingredientId] : array_column(q($pdo, 'SELECT id FROM ingredients'), 'id');
    $upd = $pdo->prepare(sql_prepare('UPDATE inventory_movements SET stock_before = ?, stock_after = ? WHERE id = ?'));
    $sel = $pdo->prepare(sql_prepare('SELECT id, qty FROM inventory_movements WHERE ingredient_id = ? ORDER BY moved_at ASC, id ASC'));
    foreach ($ids as $id) {
        $sel->execute([$id]);
        $running = 0.0;
        foreach ($sel->fetchAll() as $m) {
            $before = $running;
            $running = round($running + (float)$m['qty'], 4);
            $upd->execute([$before, $running, $m['id']]);
        }
    }
}

/** Resep sebuah menu beserta kebutuhan bahan dalam satuan stok bahan */
function recipe_rows(PDO $pdo, int $menuId): array
{
    return q($pdo, 'SELECT ri.*, i.name AS ingredient_name, i.unit AS ingredient_unit,
                           i.avg_cost, i.current_stock, i.is_active AS ingredient_active
                    FROM recipes r
                    JOIN recipe_items ri ON ri.recipe_id = r.id
                    JOIN ingredients i ON i.id = ri.ingredient_id
                    WHERE r.menu_id = ?
                    ORDER BY i.name', [$menuId]);
}

/** Kebutuhan bahan untuk N porsi menu (qty dikonversi ke satuan stok bahan) */
function recipe_requirements(PDO $pdo, int $menuId, float $porsi = 1.0): array
{
    $recipe = q1($pdo, 'SELECT * FROM recipes WHERE menu_id = ?', [$menuId]);
    if (!$recipe) return [];
    $yield = max(0.0001, (float)$recipe['yield_qty']);
    $out = [];
    foreach (recipe_rows($pdo, $menuId) as $r) {
        $qtyNeeded = convert_unit((float)$r['qty'], (string)$r['unit'], (string)$r['ingredient_unit']);
        $out[] = [
            'ingredient_id'   => (int)$r['ingredient_id'],
            'ingredient_name' => $r['ingredient_name'],
            'unit'            => $r['ingredient_unit'],
            'recipe_qty'      => (float)$r['qty'],
            'recipe_unit'     => $r['unit'],
            'qty_base'        => round($qtyNeeded * ($porsi / $yield), 4),
            'unit_cost'       => (float)$r['avg_cost'],
        ];
    }
    return $out;
}

/** HPP satu porsi menu berdasarkan harga bahan saat ini */
function menu_hpp(PDO $pdo, int $menuId, float $porsi = 1.0): float
{
    $total = 0.0;
    foreach (recipe_requirements($pdo, $menuId, $porsi) as $r) {
        $total += $r['qty_base'] * $r['unit_cost'];
    }
    return round($total, 2);
}

/** Rincian HPP menu (per bahan) */
function menu_hpp_detail(PDO $pdo, int $menuId, float $porsi = 1.0): array
{
    $rows = [];
    $total = 0.0;
    foreach (recipe_requirements($pdo, $menuId, $porsi) as $r) {
        $cost = round($r['qty_base'] * $r['unit_cost'], 2);
        $total += $cost;
        $r['cost'] = $cost;
        $rows[] = $r;
    }
    return ['rows' => $rows, 'total' => round($total, 2)];
}

/* ============================ Peringatan ============================ */

function low_stock_rows(PDO $pdo, ?float $limitRatio = null): array
{
    $rows = q($pdo, 'SELECT i.*, c.name AS category_name, s.name AS supplier_name
                     FROM ingredients i
                     LEFT JOIN ingredient_categories c ON c.id = i.category_id
                     LEFT JOIN suppliers s ON s.id = i.supplier_id
                     WHERE i.is_active = 1 AND i.min_stock > 0 AND i.current_stock < i.min_stock
                     ORDER BY (i.current_stock * 1.0 / i.min_stock) ASC, i.name');
    return $rows;
}

/** Batch pembelian yang mendekati/melewati kedaluwarsa */
function expiring_rows(PDO $pdo, int $days = EXPIRY_WARNING_DAYS): array
{
    $daysLeft = sql_days_from_today('pi.expiry_date');
    [$window, $windowParams] = sql_expiry_window('pi.expiry_date', $days);
    // SQLite menyimpan tanggal kosong sebagai '', MySQL menolak perbandingan itu
    $notEmpty = db_driver() === 'sqlite' ? "pi.expiry_date != ''" : '1 = 1';
    return q($pdo, "SELECT pi.id, pi.ingredient_id, pi.qty, pi.unit, pi.expiry_date, pi.unit_cost,
                           i.name AS ingredient_name, i.unit AS ingredient_unit, i.current_stock,
                           p.code AS purchase_code, p.purchased_at, s.name AS supplier_name,
                           $daysLeft AS days_left
                    FROM purchase_items pi
                    JOIN purchases p ON p.id = pi.purchase_id
                    JOIN ingredients i ON i.id = pi.ingredient_id
                    LEFT JOIN suppliers s ON s.id = p.supplier_id
                    WHERE pi.expiry_date IS NOT NULL AND $notEmpty
                      AND i.is_active = 1
                      AND $window
                    ORDER BY pi.expiry_date ASC", $windowParams);
}

/* ============================ Cek ketersediaan menu ============================ */

/**
 * Ketersediaan setiap menu dihitung LANGSUNG dari standard recipe × stok bahan
 * yang tersimpan (bukan estimasi): untuk tiap bahan dihitung berapa porsi yang
 * masih bisa dibuat, lalu diambil nilai terkecil sebagai "bahan penghambat".
 *
 * Status: Tersedia (>= AMBANG_TERSEDIA porsi), Terbatas (1..AMBANG-1),
 *         Tidak Tersedia (0 porsi), Resep Belum Diatur (menu tanpa resep).
 */
function menu_availability(PDO $pdo, int $threshold = 5): array
{
    $rows = q($pdo, 'SELECT m.id AS menu_id, m.code, m.name, m.category, m.price,
                            r.yield_qty,
                            ri.ingredient_id, ri.qty AS recipe_qty, ri.unit AS recipe_unit,
                            i.name AS ingredient_name, i.unit AS ingredient_unit,
                            i.current_stock, i.min_stock, i.is_active AS ingredient_active
                     FROM menus m
                     JOIN recipes r ON r.menu_id = m.id
                     JOIN recipe_items ri ON ri.recipe_id = r.id
                     JOIN ingredients i ON i.id = ri.ingredient_id
                     WHERE m.is_active = 1
                     ORDER BY m.category, m.name, i.name');

    $menus = [];
    foreach ($rows as $r) {
        $mid = (int)$r['menu_id'];
        if (!isset($menus[$mid])) {
            $menus[$mid] = [
                'id' => $mid, 'code' => $r['code'], 'name' => $r['name'], 'category' => $r['category'],
                'price' => (float)$r['price'], 'rows' => [], 'porsi' => null, 'limit' => null,
                'kurang' => [], 'habis' => 0, 'bahan' => 0, 'biaya' => 0.0,
            ];
        }
        $yield = max(0.0001, (float)$r['yield_qty']);
        $need = convert_unit((float)$r['recipe_qty'], (string)$r['recipe_unit'], (string)$r['ingredient_unit']) / $yield;
        $stock = (float)$r['current_stock'];
        $porsi = $need > 0 ? (int)floor(max($stock, 0) / $need) : 9999;
        $sisa = $need > 0 ? max($stock, 0) - ($porsi * $need) : $stock;
        $menus[$mid]['rows'][] = [
            'ingredient_id' => (int)$r['ingredient_id'],
            'ingredient_name' => $r['ingredient_name'],
            'unit' => $r['ingredient_unit'],
            'need' => round($need, 4),
            'recipe_qty' => (float)$r['recipe_qty'],
            'recipe_unit' => $r['recipe_unit'],
            'stock' => round($stock, 4),
            'porsi_dari_bahan' => $porsi,
            'sisa_stok' => round($sisa, 4),
            'min_stock' => (float)$r['min_stock'],
            'ingredient_active' => (int)$r['ingredient_active'] === 1,
        ];
        $menus[$mid]['bahan']++;
        $menus[$mid]['biaya'] += $need * (float)qv($pdo, 'SELECT avg_cost FROM ingredients WHERE id = ?', [(int)$r['ingredient_id']], 0);
        if ($stock <= 0.00001) $menus[$mid]['habis']++;
        if ($menus[$mid]['porsi'] === null || $porsi < $menus[$mid]['porsi']) {
            $menus[$mid]['porsi'] = $porsi;
            $menus[$mid]['limit'] = $r['ingredient_name'];
        }
    }

    // Menu aktif tanpa resep tetap ditampilkan sebagai peringatan data
    foreach (q($pdo, 'SELECT m.id, m.code, m.name, m.category, m.price FROM menus m
                      WHERE m.is_active = 1 AND NOT EXISTS (SELECT 1 FROM recipes r WHERE r.menu_id = m.id)
                      ORDER BY m.category, m.name') as $m) {
        $menus[(int)$m['id']] = [
            'id' => (int)$m['id'], 'code' => $m['code'], 'name' => $m['name'], 'category' => $m['category'],
            'price' => (float)$m['price'], 'rows' => [], 'porsi' => null, 'limit' => null,
            'kurang' => [], 'habis' => 0, 'bahan' => 0, 'biaya' => 0.0,
        ];
    }

    foreach ($menus as &$m) {
        if ($m['porsi'] === null) {
            $m['status'] = 'Resep Belum Diatur';
            $m['badge'] = 'muted';
        } elseif ($m['porsi'] <= 0) {
            $m['status'] = 'Tidak Tersedia';
            $m['badge'] = 'danger';
        } elseif ($m['porsi'] < $threshold) {
            $m['status'] = 'Terbatas';
            $m['badge'] = 'warn';
        } else {
            $m['status'] = 'Tersedia';
            $m['badge'] = 'ok';
        }
        if ($m['porsi'] !== null) {
            $m['kurang'] = array_values(array_filter($m['rows'], fn($x) => $x['porsi_dari_bahan'] <= 0));
            $m['hpp'] = round($m['biaya'], 2);
            $m['margin'] = $m['price'] > 0 ? ($m['price'] - $m['hpp']) / $m['price'] * 100 : 0.0;
        } else {
            $m['hpp'] = 0.0;
            $m['margin'] = 0.0;
        }
    }
    unset($m);

    return array_values($menus);
}

/** Ringkasan ketersediaan: jumlah per status + bahan penghambat terbanyak */
function availability_summary(array $items): array
{
    $sum = ['Tersedia' => 0, 'Terbatas' => 0, 'Tidak Tersedia' => 0, 'Resep Belum Diatur' => 0];
    $blocker = [];
    foreach ($items as $m) {
        $sum[$m['status']] = ($sum[$m['status']] ?? 0) + 1;
        if ($m['porsi'] !== null && $m['porsi'] <= 0 && $m['limit']) {
            $blocker[$m['limit']] = ($blocker[$m['limit']] ?? 0) + 1;
        }
    }
    arsort($blocker);
    return ['counts' => $sum, 'blockers' => $blocker];
}
