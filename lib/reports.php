<?php
declare(strict_types=1);

/**
 * Definisi laporan: satu sumber data dipakai untuk tampilan cetak (HTML) dan ekspor CSV.
 * Setiap laporan mengembalikan: title, periode, columns, rows, summary, extraInfo.
 */
function report_definitions(PDO $pdo, string $type, string $from, string $to, array $opt = []): array
{
    $periode = tgl($from) . ' s/d ' . tgl($to);
    switch ($type) {

        case 'sales': {
            $menuConcat = sql_group_concat('m.name', ' | ');
            $rows = q($pdo, "SELECT s.invoice_no, s.sold_at, s.channel, s.payment_method, s.subtotal, s.discount, s.total,
                                   s.hpp_total, s.status, s.source, u.name AS kasir,
                                   (SELECT $menuConcat FROM sale_items si JOIN menus m ON m.id = si.menu_id WHERE si.sale_id = s.id) AS menu,
                                   (SELECT SUM(si.qty) FROM sale_items si WHERE si.sale_id = s.id) AS qty
                            FROM sales s LEFT JOIN users u ON u.id = s.created_by
                            WHERE date(s.sold_at) BETWEEN ? AND ? ORDER BY s.sold_at ASC, s.id ASC", [$from, $to]);
            $sum = sales_summary($pdo, $from, $to);
            return [
                'title' => 'Laporan Penjualan',
                'periode' => $periode,
                'columns' => ['Invoice', 'Waktu', 'Menu', 'Qty', 'Channel', 'Pembayaran', 'Subtotal', 'Diskon', 'Total', 'HPP', 'Laba Kotor', 'Status', 'Sumber', 'Kasir'],
                'rows' => array_map(fn($r) => [
                    $r['invoice_no'], $r['sold_at'], $r['menu'], $r['qty'], $r['channel'], $r['payment_method'],
                    (float)$r['subtotal'], (float)$r['discount'], (float)$r['total'], (float)$r['hpp_total'],
                    (float)$r['total'] - (float)$r['hpp_total'], $r['status'], $r['source'], $r['kasir'],
                ], $rows),
                'summary' => [
                    'Total transaksi' => number_format($sum['trx'], 0, ',', '.'),
                    'Total porsi terjual' => num($sum['qty'], 0) . ' porsi',
                    'Total penjualan' => rp($sum['omzet']),
                    'Estimasi HPP' => rp($sum['hpp']),
                    'Estimasi laba kotor' => rp($sum['laba']) . ' (' . pct($sum['margin']) . ')',
                    'Rata-rata per transaksi' => rp($sum['avg_trx']),
                ],
            ];
        }

        case 'inventory': {
            $rows = q($pdo, 'SELECT i.code, i.name, c.name AS kategori, i.unit, i.current_stock, i.min_stock,
                                   i.avg_cost, i.last_cost, (i.current_stock * i.avg_cost) AS nilai,
                                   s.name AS supplier, i.storage_location, i.expiry_info, i.is_active
                            FROM ingredients i
                            LEFT JOIN ingredient_categories c ON c.id = i.category_id
                            LEFT JOIN suppliers s ON s.id = i.supplier_id
                            ORDER BY c.name, i.name');
            $totalNilai = 0.0; $low = 0;
            foreach ($rows as $r) {
                $totalNilai += (float)$r['nilai'];
                if ((float)$r['current_stock'] < (float)$r['min_stock']) $low++;
            }
            return [
                'title' => 'Laporan Inventory / Stok',
                'periode' => 'Posisi per ' . tgl(now_str(), true),
                'columns' => ['Kode', 'Bahan', 'Kategori', 'Satuan', 'Stok', 'Stok Min', 'Status', 'Harga Rata-rata', 'Harga Terakhir', 'Nilai Stok', 'Supplier', 'Lokasi', 'Info Kedaluwarsa', 'Aktif'],
                'rows' => array_map(fn($r) => [
                    $r['code'], $r['name'], $r['kategori'], $r['unit'], (float)$r['current_stock'], (float)$r['min_stock'],
                    stock_status((float)$r['current_stock'], (float)$r['min_stock'])[0],
                    (float)$r['avg_cost'], (float)$r['last_cost'], (float)$r['nilai'], $r['supplier'],
                    $r['storage_location'], $r['expiry_info'], (int)$r['is_active'] === 1 ? 'Aktif' : 'Nonaktif',
                ], $rows),
                'summary' => [
                    'Jumlah jenis bahan' => (string)count($rows),
                    'Total nilai stok' => rp($totalNilai),
                    'Bahan di bawah stok minimum' => (string)$low . ' bahan',
                ],
            ];
        }

        case 'movements': {
            $ing = (int)($opt['ingredient_id'] ?? 0);
            $w = 'date(m.moved_at) BETWEEN ? AND ?';
            $p = [$from, $to];
            if ($ing > 0) { $w .= ' AND m.ingredient_id = ?'; $p[] = $ing; }
            $rows = q($pdo, "SELECT m.moved_at, i.code, i.name AS bahan, m.movement_type, m.qty, m.unit,
                                    m.stock_before, m.stock_after, m.unit_cost, m.total_cost,
                                    m.ref_code, m.note, m.user_name
                             FROM inventory_movements m JOIN ingredients i ON i.id = m.ingredient_id
                             WHERE $w ORDER BY m.moved_at ASC, m.id ASC", $p);
            $masuk = $keluar = 0.0; $nilai = 0.0;
            foreach ($rows as $r) { if ((float)$r['qty'] > 0) $masuk += (float)$r['qty']; else $keluar += abs((float)$r['qty']); $nilai += (float)$r['total_cost']; }
            return [
                'title' => 'Laporan Pergerakan Stok (Audit Trail)',
                'periode' => $periode,
                'columns' => ['Waktu', 'Kode Bahan', 'Bahan', 'Jenis Pergerakan', 'Jumlah', 'Satuan', 'Stok Sebelum', 'Stok Setelah', 'Harga Satuan', 'Nilai', 'Referensi', 'Keterangan', 'Pengguna'],
                'rows' => array_map(fn($r) => [
                    $r['moved_at'], $r['code'], $r['bahan'], movement_label((string)$r['movement_type']),
                    (float)$r['qty'], $r['unit'], (float)$r['stock_before'], (float)$r['stock_after'],
                    (float)$r['unit_cost'], (float)$r['total_cost'], $r['ref_code'], $r['note'], $r['user_name'],
                ], $rows),
                'summary' => [
                    'Jumlah pergerakan' => number_format(count($rows), 0, ',', '.'),
                    'Total volume masuk' => num($masuk, 2),
                    'Total volume keluar' => num($keluar, 2),
                    'Nilai pergerakan tercatat' => rp($nilai),
                ],
            ];
        }

        case 'purchases': {
            $rows = q($pdo, 'SELECT p.purchased_at, p.code, s.name AS supplier, p.invoice_no, i.name AS bahan, i.code AS kode_bahan,
                                   pi.qty, pi.unit, pi.unit_cost, pi.total_cost, pi.expiry_date, p.note, u.name AS petugas
                            FROM purchase_items pi
                            JOIN purchases p ON p.id = pi.purchase_id
                            JOIN ingredients i ON i.id = pi.ingredient_id
                            LEFT JOIN suppliers s ON s.id = p.supplier_id
                            LEFT JOIN users u ON u.id = p.created_by
                            WHERE date(p.purchased_at) BETWEEN ? AND ?
                            ORDER BY p.purchased_at ASC, p.id ASC, i.name', [$from, $to]);
            $sum = a_purchase_summary($pdo, $from, $to);
            return [
                'title' => 'Laporan Pembelian & Penerimaan Bahan',
                'periode' => $periode,
                'columns' => ['Tanggal', 'Kode Pembelian', 'Supplier', 'No. Nota', 'Bahan', 'Kode Bahan', 'Qty', 'Satuan', 'Harga Satuan', 'Total', 'Kedaluwarsa', 'Catatan', 'Dicatat oleh'],
                'rows' => array_map(fn($r) => [
                    $r['purchased_at'], $r['code'], $r['supplier'], $r['invoice_no'], $r['bahan'], $r['kode_bahan'],
                    (float)$r['qty'], $r['unit'], (float)$r['unit_cost'], (float)$r['total_cost'],
                    $r['expiry_date'], $r['note'], $r['petugas'],
                ], $rows),
                'summary' => [
                    'Jumlah nota' => number_format($sum['n'], 0, ',', '.'),
                    'Jumlah baris bahan' => number_format(count($rows), 0, ',', '.'),
                    'Total biaya pembelian' => rp($sum['total']),
                ],
            ];
        }

        case 'waste': {
            $rows = q($pdo, 'SELECT w.wasted_at, w.code, i.code AS kode_bahan, i.name AS bahan, w.qty, w.unit, w.reason,
                                   w.est_cost, w.note, w.created_by_name, w.status, w.void_reason
                            FROM waste_records w JOIN ingredients i ON i.id = w.ingredient_id
                            WHERE date(w.wasted_at) BETWEEN ? AND ? ORDER BY w.wasted_at ASC, w.id ASC', [$from, $to]);
            $sum = a_waste_summary($pdo, $from, $to);
            $byReason = a_waste_by_reason($pdo, $from, $to);
            $hpp = (float)qv($pdo, 'SELECT COALESCE(SUM(hpp_total),0) FROM sales WHERE status = "selesai" AND date(sold_at) BETWEEN ? AND ?', [$from, $to], 0);
            return [
                'title' => 'Laporan Waste / Bahan Terbuang',
                'periode' => $periode,
                'columns' => ['Waktu', 'Kode', 'Kode Bahan', 'Bahan', 'Jumlah', 'Satuan', 'Alasan', 'Estimasi Biaya', 'Catatan', 'Dicatat oleh', 'Status', 'Alasan Pembatalan'],
                'rows' => array_map(fn($r) => [
                    $r['wasted_at'], $r['code'], $r['kode_bahan'], $r['bahan'], (float)$r['qty'], $r['unit'],
                    $r['reason'], (float)$r['est_cost'], $r['note'], $r['created_by_name'], $r['status'], $r['void_reason'],
                ], $rows),
                'summary' => array_merge([
                    'Jumlah catatan waste' => number_format($sum['n'], 0, ',', '.'),
                    'Total biaya waste' => rp($sum['cost']),
                    'Rasio terhadap HPP' => pct($hpp > 0 ? $sum['cost'] / $hpp * 100 : 0),
                ], array_map(fn($r) => 'Biaya waste - ' . $r['reason'] . ' (' . (int)$r['n'] . ' catatan)',
                    array_slice($byReason, 0, 4))),
            ];
        }

        case 'recipes': {
            $menuF = (int)($opt['menu_id'] ?? 0);
            $w = '1=1';
            $p = [];
            if ($menuF > 0) { $w = 'm.id = ?'; $p[] = $menuF; }
            $rows = q($pdo, "SELECT m.code AS kode_menu, m.name AS menu, m.category, m.price, i.code AS kode_bahan, i.name AS bahan,
                                    ri.qty, ri.unit, i.unit AS satuan_stok, i.avg_cost
                             FROM recipe_items ri
                             JOIN recipes r ON r.id = ri.recipe_id
                             JOIN menus m ON m.id = r.menu_id
                             JOIN ingredients i ON i.id = ri.ingredient_id
                             WHERE $w ORDER BY m.name, i.name", $p);
            $data = [];
            $hppMap = [];
            foreach ($rows as $r) {
                $base = convert_unit((float)$r['qty'], (string)$r['unit'], (string)$r['satuan_stok']);
                $cost = $base * (float)$r['avg_cost'];
                $hppMap[$r['menu']] = ($hppMap[$r['menu']] ?? 0) + $cost;
                $data[] = [$r['kode_menu'], $r['menu'], $r['category'], (float)$r['price'], $r['kode_bahan'], $r['bahan'],
                    (float)$r['qty'], $r['unit'], $base, $r['satuan_stok'], (float)$r['avg_cost'], round($cost, 2)];
            }
            $summary = [];
            foreach ($hppMap as $menu => $hpp) {
                $price = (float)qv($pdo, 'SELECT price FROM menus WHERE name = ?', [$menu], 0);
                $summary['HPP ' . $menu] = rp($hpp) . ' / porsi · margin ' . pct($price > 0 ? ($price - $hpp) / $price * 100 : 0);
            }
            return [
                'title' => 'Laporan Resep / Standard Recipe (BOM)',
                'periode' => 'Resep standar berlaku (bukan periode)',
                'columns' => ['Kode Menu', 'Menu', 'Kategori', 'Harga Jual', 'Kode Bahan', 'Bahan', 'Takaran', 'Satuan Resep',
                    'Setara Satuan Stok', 'Satuan Stok', 'Harga Satuan', 'Biaya Bahan'],
                'rows' => $data,
                'summary' => $summary,
            ];
        }

        case 'profitability': {
            $prof = a_menu_profitability($pdo, $from, $to);
            $totOmzet = array_sum(array_map(fn($r) => (float)$r['omzet'], $prof));
            $totLaba = array_sum(array_map(fn($r) => (float)$r['laba_total'], $prof));
            $totHpp = array_sum(array_map(fn($r) => (float)$r['hpp_total'], $prof));
            return [
                'title' => 'Laporan Profitabilitas Menu',
                'periode' => $periode,
                'columns' => ['Kode', 'Menu', 'Kategori', 'Terjual (porsi)', 'Harga Jual', 'HPP / Porsi (kini)',
                    'Laba / Porsi', 'Margin / Porsi', 'Total Omzet', 'Total HPP Terjual', 'Estimasi Total Laba', 'Margin Aktual'],
                'rows' => array_map(fn($r) => [
                    $r['code'], $r['name'], $r['category'], (float)$r['qty'], (float)$r['price'], (float)$r['hpp_unit'],
                    (float)$r['laba_unit'], pct((float)$r['margin']), (float)$r['omzet'], (float)$r['hpp_total'],
                    (float)$r['laba_total'], pct((float)$r['margin_real']),
                ], $prof),
                'summary' => [
                    'Total omzet' => rp($totOmzet),
                    'Total HPP terjual' => rp($totHpp),
                    'Estimasi total laba kotor' => rp($totLaba),
                    'Margin rata-rata' => pct($totOmzet > 0 ? $totLaba / $totOmzet * 100 : 0),
                ],
            ];
        }

        case 'opname': {
            $rows = q($pdo, 'SELECT o.*, (SELECT COUNT(*) FROM stock_opname_items i WHERE i.opname_id = o.id) AS n_item,
                                    (SELECT COUNT(*) FROM stock_opname_items i WHERE i.opname_id = o.id AND i.diff_qty IS NOT NULL AND abs(i.diff_qty) > 0.0001) AS n_selisih,
                                    (SELECT COALESCE(SUM(abs(i.diff_qty) * ing.avg_cost),0) FROM stock_opname_items i
                                       JOIN ingredients ing ON ing.id = i.ingredient_id WHERE i.opname_id = o.id) AS nilai_selisih
                             FROM stock_opnames o ORDER BY o.opname_date DESC');
            return [
                'title' => 'Laporan Stock Opname',
                'periode' => 'Seluruh sesi stock opname',
                'columns' => ['Kode', 'Tanggal', 'Cakupan', 'Status', 'Jumlah Bahan', 'Bahan Berselisih', 'Nilai Selisih', 'Dibuat oleh', 'Disetujui oleh', 'Waktu Persetujuan', 'Catatan Persetujuan'],
                'rows' => array_map(fn($r) => [
                    $r['code'], $r['opname_date'], $r['scope'], OPNAME_STATUS[$r['status']] ?? $r['status'],
                    (int)$r['n_item'], (int)$r['n_selisih'], (float)$r['nilai_selisih'], $r['created_by_name'],
                    $r['approved_by_name'], $r['approved_at'], $r['approval_note'],
                ], $rows),
                'summary' => [
                    'Jumlah sesi' => (string)count($rows),
                    'Sesi disetujui' => (string)count(array_filter($rows, fn($r) => $r['status'] === 'disetujui')),
                    'Sesi menunggu' => (string)count(array_filter($rows, fn($r) => in_array($r['status'], ['draft', 'diajukan'], true))),
                ],
            ];
        }

        case 'opname_items': {
            $oid = (int)($opt['id'] ?? 0);
            $rows = q($pdo, 'SELECT oi.system_qty, oi.physical_qty, oi.diff_qty, oi.unit, oi.reason,
                                    i.code AS kode_bahan, i.name AS bahan, i.avg_cost, c.name AS kategori,
                                    oi.movement_id, m.moved_at, u.name AS approved_by
                             FROM stock_opname_items oi
                             JOIN stock_opnames o ON o.id = oi.opname_id
                             JOIN ingredients i ON i.id = oi.ingredient_id
                             LEFT JOIN ingredient_categories c ON c.id = i.category_id
                             LEFT JOIN inventory_movements m ON m.id = oi.movement_id
                             LEFT JOIN users u ON u.id = o.approved_by
                             WHERE oi.opname_id = ? ORDER BY i.name', [$oid]);
            $nilai = 0.0; $nSel = 0;
            foreach ($rows as $r) { if ($r['diff_qty'] !== null && abs((float)$r['diff_qty']) > 0.0001) { $nSel++; $nilai += abs((float)$r['diff_qty']) * (float)$r['avg_cost']; } }
            $op = q1($pdo, 'SELECT * FROM stock_opnames WHERE id = ?', [$oid]);
            return [
                'title' => 'Laporan Detail Stock Opname ' . ($op['code'] ?? ''),
                'periode' => $op ? tgl($op['opname_date']) . ' · ' . (OPNAME_STATUS[$op['status']] ?? $op['status']) : '',
                'columns' => ['Kode Bahan', 'Bahan', 'Kategori', 'Stok Sistem', 'Stok Fisik', 'Selisih', 'Satuan', 'Nilai Selisih', 'Alasan/Catatan', 'Sudah Disesuaikan', 'Waktu Penyesuaian'],
                'rows' => array_map(fn($r) => [
                    $r['kode_bahan'], $r['bahan'], $r['kategori'], (float)$r['system_qty'],
                    $r['physical_qty'] === null ? '' : (float)$r['physical_qty'],
                    $r['diff_qty'] === null ? '' : (float)$r['diff_qty'], $r['unit'],
                    $r['diff_qty'] === null ? 0 : round(abs((float)$r['diff_qty']) * (float)$r['avg_cost'], 2),
                    $r['reason'], $r['movement_id'] ? 'Ya' : 'Belum', $r['moved_at'],
                ], $rows),
                'summary' => [
                    'Jumlah bahan diperiksa' => (string)count($rows),
                    'Bahan berselisih' => (string)$nSel,
                    'Nilai selisih' => rp($nilai),
                ],
            ];
        }

        default:
            throw new RuntimeException('Jenis laporan tidak dikenali.');
    }
}

/** Daftar laporan yang tersedia (untuk halaman Laporan & ekspor) */
function report_catalog(): array
{
    return [
        'sales' => ['Laporan Penjualan', 'Seluruh transaksi penjualan beserta omzet, HPP, dan laba kotor.', true],
        'inventory' => ['Laporan Inventory', 'Posisi stok seluruh bahan, nilai stok, dan status ketersediaan.', false],
        'movements' => ['Laporan Pergerakan Stok', 'Audit trail setiap perubahan stok (masuk, keluar, penyesuaian).', true],
        'purchases' => ['Laporan Pembelian', 'Rincian pembelian & penerimaan bahan per nota dan supplier.', true],
        'waste' => ['Laporan Waste', 'Bahan terbuang beserta alasan dan estimasi biaya.', true],
        'recipes' => ['Laporan Resep (BOM)', 'Standard recipe setiap menu beserta kebutuhan bahan per porsi.', false],
        'profitability' => ['Laporan Profitabilitas Menu', 'Harga jual, HPP, laba kotor, dan margin setiap menu.', true],
        'opname' => ['Laporan Stock Opname', 'Seluruh sesi opname, selisih, dan status persetujuannya.', false],
    ];
}
