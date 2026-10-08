<?php
declare(strict_types=1);

/**
 * Modul penjualan.
 * Alur: transaksi diinput -> resep/BOM menu diambil -> kebutuhan bahan dihitung
 * -> stok berkurang + catatan pergerakan stok dibuat -> HPP & analitik diperbarui.
 */

/**
 * Simpan satu transaksi penjualan.
 * @param array $data ['sold_at','channel','payment_method','discount','customer_note','source','import_ref']
 * @param array $items [['menu_id'=>int,'qty'=>float,'unit_price'=>float], ...]
 */
function record_sale(PDO $pdo, array $data, array $items): array
{
    $items = array_values(array_filter($items, fn($it) => (int)($it['menu_id'] ?? 0) > 0 && (float)($it['qty'] ?? 0) > 0));
    if (!$items) {
        throw new RuntimeException('Transaksi harus memiliki minimal satu menu dengan jumlah > 0.');
    }

    $user = current_user();
    $soldAt = $data['sold_at'] ?? now_str();
    if (strlen($soldAt) === 10) $soldAt .= ' ' . date('H:i:s');

    $subtotal = 0.0;
    $hppTotal = 0.0;
    $prepared = [];
    foreach ($items as $it) {
        $menu = q1($pdo, 'SELECT * FROM menus WHERE id = ?', [(int)$it['menu_id']]);
        if (!$menu) throw new RuntimeException('Menu tidak ditemukan.');
        $qty = (float)$it['qty'];
        $price = array_key_exists('unit_price', $it) && $it['unit_price'] !== null && $it['unit_price'] !== ''
            ? (float)$it['unit_price'] : (float)$menu['price'];
        $line = round($qty * $price, 2);
        $hppUnit = menu_hpp($pdo, (int)$menu['id'], 1.0);
        $hppLine = round($hppUnit * $qty, 2);
        $subtotal += $line;
        $hppTotal += $hppLine;
        $prepared[] = [
            'menu_id' => (int)$menu['id'], 'menu_name' => $menu['name'], 'qty' => $qty,
            'unit_price' => $price, 'subtotal' => $line, 'hpp_unit' => $hppUnit, 'hpp_total' => $hppLine,
        ];
    }
    $discount = (float)($data['discount'] ?? 0);
    $total = round($subtotal - $discount, 2);

    $saleId = insert_row($pdo, 'sales', [
        'invoice_no'     => next_code($pdo, 'INV', 'sales', 'invoice_no'),
        'sold_at'        => $soldAt,
        'channel'        => (string)($data['channel'] ?? 'Kasir'),
        'payment_method' => (string)($data['payment_method'] ?? 'Tunai'),
        'subtotal'       => round($subtotal, 2),
        'discount'       => $discount,
        'total'          => $total,
        'hpp_total'      => round($hppTotal, 2),
        'customer_note'  => $data['customer_note'] ?? null,
        'source'         => (string)($data['source'] ?? 'manual'),
        'import_ref'     => $data['import_ref'] ?? null,
        'status'         => 'selesai',
        'created_by'     => $user['id'] ?? null,
        'created_at'     => now_str(),
    ]);
    $sale = q1($pdo, 'SELECT * FROM sales WHERE id = ?', [$saleId]);

    // Kebutuhan bahan agregat per bahan untuk transaksi ini
    $need = [];
    foreach ($prepared as $p) {
        insert_row($pdo, 'sale_items', [
            'sale_id' => $saleId, 'menu_id' => $p['menu_id'], 'qty' => $p['qty'],
            'unit_price' => $p['unit_price'], 'subtotal' => $p['subtotal'],
            'hpp_unit' => $p['hpp_unit'], 'hpp_total' => $p['hpp_total'],
        ]);
        foreach (recipe_requirements($pdo, $p['menu_id'], $p['qty']) as $req) {
            $id = $req['ingredient_id'];
            if (!isset($need[$id])) {
                $need[$id] = ['qty' => 0.0, 'unit' => $req['unit'], 'name' => $req['ingredient_name'], 'cost' => 0.0];
            }
            $need[$id]['qty'] += $req['qty_base'];
            $need[$id]['cost'] += $req['qty_base'] * $req['unit_cost'];
        }
    }

    // Kurangi stok + catat pergerakan stok
    $warnings = [];
    foreach ($need as $ingId => $n) {
        $ing = q1($pdo, 'SELECT * FROM ingredients WHERE id = ?', [$ingId]);
        $short = (float)$ing['current_stock'] < $n['qty'];
        if ($short) {
            $warnings[] = sprintf('%s: stok sistem %s %s, kebutuhan %s %s (stok menjadi minus — segera lakukan stock opname)',
                $ing['name'], num($ing['current_stock']), $ing['unit'], num($n['qty']), $ing['unit']);
        }
        apply_movement($pdo, (int)$ingId, 'penjualan', -1 * round($n['qty'], 4), [
            'unit'          => $n['unit'],
            'unit_cost'     => (float)$ing['avg_cost'],
            'total_cost'    => round($n['cost'], 2),
            'ref_type'      => 'sale',
            'ref_id'        => $saleId,
            'ref_code'      => $sale['invoice_no'],
            'note'          => 'Penggunaan bahan otomatis dari resep (scan penjualan)',
            'moved_at'      => $soldAt,
            'allow_negative'=> true,
        ]);
    }

    audit($pdo, 'create', 'sales', $saleId,
        'Transaksi penjualan ' . $sale['invoice_no'] . ' sebesar ' . rp($total)
        . ' (' . count($prepared) . ' item menu) menambah pengurangan stok otomatis ' . count($need) . ' bahan',
        ['total' => $total, 'channel' => $sale['channel'], 'items' => count($items)]);

    return ['id' => $saleId, 'invoice_no' => $sale['invoice_no'], 'total' => $total, 'hpp' => $hppTotal, 'warnings' => $warnings];
}

/** Batalkan transaksi + kembalikan stok (dengan catatan pergerakan stok) */
function void_sale(PDO $pdo, int $saleId, string $reason): void
{
    $sale = q1($pdo, 'SELECT * FROM sales WHERE id = ?', [$saleId]);
    if (!$sale) throw new RuntimeException('Transaksi tidak ditemukan.');
    if ($sale['status'] === 'void') throw new RuntimeException('Transaksi ini sudah dibatalkan.');

    $refunds = [];
    foreach (q($pdo, 'SELECT * FROM inventory_movements WHERE ref_type = "sale" AND ref_id = ?', [$saleId]) as $m) {
        $id = (int)$m['ingredient_id'];
        if (!isset($refunds[$id])) $refunds[$id] = ['qty' => 0.0, 'unit' => $m['unit'], 'cost' => 0.0];
        $refunds[$id]['qty'] += abs((float)$m['qty']);
        $refunds[$id]['cost'] += (float)$m['total_cost'];
    }
    foreach ($refunds as $ingId => $r) {
        apply_movement($pdo, (int)$ingId, 'penyesuaian', round($r['qty'], 4), [
            'unit' => $r['unit'], 'total_cost' => round($r['cost'], 2),
            'ref_type' => 'sale_void', 'ref_id' => $saleId, 'ref_code' => $sale['invoice_no'],
            'note' => 'Pengembalian stok akibat pembatalan transaksi ' . $sale['invoice_no'] . ' — ' . $reason,
        ]);
    }
    update_row($pdo, 'sales', $saleId, ['status' => 'void', 'customer_note' => trim((string)$sale['customer_note'] . ' | VOID: ' . $reason)]);
    audit($pdo, 'void', 'sales', $saleId, 'Batalkan transaksi ' . $sale['invoice_no'] . ' (' . rp($sale['total']) . '). Alasan: ' . $reason);
}

/* ============================ Ringkasan penjualan ============================ */

function sales_summary(PDO $pdo, string $from, string $to): array
{
    $r = q1($pdo, 'SELECT COALESCE(SUM(total),0) AS omzet, COUNT(*) AS trx, COALESCE(SUM(hpp_total),0) AS hpp
                   FROM sales WHERE status = "selesai" AND date(sold_at) BETWEEN ? AND ?', [$from, $to]);
    $omzet = (float)($r['omzet'] ?? 0);
    $hpp = (float)($r['hpp'] ?? 0);
    return [
        'omzet' => $omzet,
        'trx' => (int)($r['trx'] ?? 0),
        'hpp' => $hpp,
        'laba' => $omzet - $hpp,
        'margin' => $omzet > 0 ? ($omzet - $hpp) / $omzet * 100 : 0.0,
        'avg_trx' => (int)($r['trx'] ?? 0) > 0 ? $omzet / (int)$r['trx'] : 0.0,
        'qty' => (float)qv($pdo, 'SELECT COALESCE(SUM(si.qty),0) FROM sale_items si JOIN sales s ON s.id = si.sale_id
                                 WHERE s.status = "selesai" AND date(s.sold_at) BETWEEN ? AND ?', [$from, $to], 0),
    ];
}
