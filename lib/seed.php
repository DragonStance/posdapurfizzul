<?php
declare(strict_types=1);

/**
 * DATA DEMO (SEED) — dijalankan SEKALI saat aplikasi pertama kali diakses.
 * Ditandai jelas di UI sebagai "DATA DEMO".
 *
 * Seluruh pergerakan stok di sini dibuat melalui apply_movement() yang sama
 * dengan aplikasi, sehingga data demo pun konsisten & memiliki audit trail.
 */
function seed_demo_data(PDO $pdo): void
{
    mt_srand(11092025);
    $ts = strtotime('today 08:00:00');

    /* ------------------------- 1. PENGGUNA & ROLE ------------------------- */
    $roles = [];
    foreach (q($pdo, 'SELECT id, code FROM roles') as $r) $roles[$r['code']] = (int)$r['id'];

    $users = [
        ['owner', 'Fizzul Rahman', 'owner@dapurfizzul.id', '0811-2345-678', 'owner', 'fizzul123'],
        ['manager', 'Nurhayati Sialana', 'manager@dapurfizzul.id', '0812-9876-543', 'manager', 'manager123'],
        ['kasir', 'Sari Latuconsina', 'kasir@dapurfizzul.id', '0812-3456-789', 'kasir', 'kasir123'],
        ['dapur', 'Bakri Tuasikal', 'dapur@dapurfizzul.id', '0813-4567-890', 'dapur', 'dapur123'],
    ];
    $userIds = [];
    foreach ($users as [$un, $nama, $email, $phone, $role, $pwd]) {
        $userIds[$un] = insert_row($pdo, 'users', [
            'username' => $un, 'name' => $nama, 'email' => $email, 'phone' => $phone,
            'role_id' => $roles[$role], 'password_hash' => password_hash($pwd, PASSWORD_DEFAULT),
            'is_active' => 1, 'last_login' => null, 'created_at' => date('Y-m-d H:i:s', $ts - 40 * 86400),
        ]);
    }
    $ownerId = $userIds['owner'];
    $managerId = $userIds['manager'];
    $kasirId = $userIds['kasir'];
    $dapurId = $userIds['dapur'];

    /* ------------------------- 2. KATEGORI BAHAN ------------------------- */
    $catIds = [];
    foreach ([
        'Seafood' => 'Ikan dan hasil laut segar',
        'Bahan Segar' => 'Kelapa, santan, buah, dan bahan cepat rusak',
        'Bumbu & Rempah' => 'Bawang, cabai, kunyit, dan rempah',
        'Bahan Kering' => 'Beras, sagu, gula, minyak, dan sembako',
        'Kacang & Biji' => 'Kenari, kacang tanah, dan biji-bijian',
    ] as $nama => $desc) {
        $catIds[$nama] = insert_row($pdo, 'ingredient_categories', ['name' => $nama, 'description' => $desc]);
    }

    /* ------------------------- 3. SUPPLIER ------------------------- */
    $sup = [
        ['SUP-001', 'Pasar Ikan Mardika', 'Pak Anwar S.', '0811-1001-201', '0811-1001-201', 'Jl. Pantai Mardika, Ambon', 'Ikan segar kiriman pagi (04.00-07.00). Harga naik saat cuaca buruk.'],
        ['SUP-002', 'Toko Sembako Sehat Ambon', 'Ibu Merry', '0812-2002-302', '0812-2002-302', 'Jl. Sirimau No. 12, Ambon', 'Sembako & bahan kering, pembayaran tempo 7 hari.'],
        ['SUP-003', 'Supplier Kelapa & Rempah Nusantara', 'Bpk. Dedi', '0813-3003-403', '0813-3003-403', 'Passo, Ambon', 'Santan perasan segar harian dan rempah-rempah.'],
        ['SUP-004', 'Pasar Sayur Mardika', 'Ibu Tine', '0813-4004-504', '0813-4004-504', 'Jl. Pantai Mardika, Ambon', 'Cabai, kemangi, dan sayuran segar.'],
        ['SUP-005', 'UD Bahan Kering Maluku', 'Bpk. Hasan', '0812-5005-605', '0812-5005-605', 'Jl. A.Y. Patty, Ambon', 'Sagu, kenari, dan bahan kering khas Maluku.'],
    ];
    $supIds = [];
    foreach ($sup as $s) {
        $supIds[$s[0]] = insert_row($pdo, 'suppliers', [
            'code' => $s[0], 'name' => $s[1], 'contact_person' => $s[2], 'phone' => $s[3],
            'email' => $s[4], 'address' => $s[5], 'notes' => $s[6], 'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s', $ts - 35 * 86400),
        ]);
    }

    /* ------------------------- 4. BAHAN BAKU ------------------------- */
    // [kode, nama, kategori, satuan, min stok, harga dasar, supplier, lokasi, info kedaluwarsa]
    $ingDef = [
        ['BB-001', 'Ikan Kakap Segar', 'Seafood', 'kg', 8, 60000, 'SUP-001', 'Chiller 1', 'Cepat rusak — maksimal 2-3 hari di chiller'],
        ['BB-002', 'Ikan Cakalang', 'Seafood', 'kg', 6, 55000, 'SUP-001', 'Chiller 1', 'Cepat rusak — maksimal 2-3 hari di chiller'],
        ['BB-003', 'Kenari (Biji Kenari)', 'Kacang & Biji', 'gram', 500, 150, 'SUP-005', 'Rak Kering A', 'Tahan lama, simpan wadah tertutup'],
        ['BB-004', 'Santan Kelapa', 'Bahan Segar', 'liter', 10, 20000, 'SUP-003', 'Chiller 2', 'Perasan segar — habiskan dalam 2 hari'],
        ['BB-005', 'Bawang Merah', 'Bumbu & Rempah', 'kg', 5, 40000, 'SUP-004', 'Rak Rempah', 'Simpan kering & berventilasi'],
        ['BB-006', 'Bawang Putih', 'Bumbu & Rempah', 'kg', 3, 45000, 'SUP-004', 'Rak Rempah', 'Simpan kering & berventilasi'],
        ['BB-007', 'Cabai Merah Keriting', 'Bumbu & Rempah', 'kg', 4, 50000, 'SUP-004', 'Chiller 2', 'Mudah busuk — gunakan dalam 5 hari'],
        ['BB-008', 'Kunyit', 'Bumbu & Rempah', 'gram', 400, 60, 'SUP-003', 'Rak Rempah', 'Tahan lama bila kering'],
        ['BB-009', 'Daun Kemangi', 'Bahan Segar', 'ikat', 10, 3000, 'SUP-004', 'Chiller 2', 'Layu dalam 2 hari'],
        ['BB-010', 'Beras Lokal', 'Bahan Kering', 'kg', 25, 14000, 'SUP-002', 'Gudang Kering', 'Simpan di tempat kering, jauhkan dari air'],
        ['BB-011', 'Tepung Sagu', 'Bahan Kering', 'kg', 10, 12000, 'SUP-005', 'Gudang Kering', 'Simpan wadah tertutup'],
        ['BB-012', 'Gula Merah', 'Bahan Kering', 'kg', 5, 18000, 'SUP-002', 'Gudang Kering', 'Tahan lama'],
        ['BB-013', 'Minyak Goreng', 'Bahan Kering', 'liter', 8, 17000, 'SUP-002', 'Gudang Kering', 'Tahan lama, jauhkan dari panas'],
        ['BB-014', 'Garam', 'Bumbu & Rempah', 'gram', 1000, 15, 'SUP-002', 'Rak Rempah', 'Tahan lama'],
        ['BB-015', 'Buah Rujak (Mangga Muda & Pepaya)', 'Bahan Segar', 'kg', 6, 25000, 'SUP-004', 'Chiller 2', 'Gunakan dalam 4 hari'],
        ['BB-016', 'Kacang Tanah', 'Kacang & Biji', 'gram', 500, 35, 'SUP-005', 'Rak Kering A', 'Tahan lama bila kering'],
    ];
    $ingIds = [];
    $ingMeta = [];
    foreach ($ingDef as $d) {
        $id = insert_row($pdo, 'ingredients', [
            'code' => $d[0], 'name' => $d[1], 'category_id' => $catIds[$d[2]], 'unit' => $d[3],
            'min_stock' => $d[4], 'current_stock' => 0, 'avg_cost' => $d[5], 'last_cost' => $d[5],
            'supplier_id' => $supIds[$d[6]], 'storage_location' => $d[7], 'expiry_info' => $d[8],
            'is_active' => 1, 'created_at' => date('Y-m-d H:i:s', $ts - 35 * 86400), 'updated_at' => now_str(),
        ]);
        $ingIds[$d[0]] = $id;
        $ingMeta[$d[0]] = ['name' => $d[1], 'unit' => $d[3], 'min' => (float)$d[4], 'cost' => (float)$d[5], 'sup' => $d[6]];
    }

    /* ------------------------- 5. MENU & RESEP ------------------------- */
    // [kode, nama, kategori, harga, deskripsi, resep: [kode bahan => [qty, satuan]]]
    $menuDef = [
        ['MN-001', 'Ikan Kuah Kuning Kenari', 'Makanan Utama', 45000,
            'Ikan kakap segar dimasak kuah kuning khas Maluku dengan kenari, santan, dan kunyit.',
            ['BB-001' => [250, 'gram'], 'BB-003' => [30, 'gram'], 'BB-004' => [100, 'ml'], 'BB-005' => [20, 'gram'],
             'BB-008' => [5, 'gram'], 'BB-007' => [10, 'gram'], 'BB-014' => [3, 'gram'], 'BB-009' => [0.25, 'ikat']]],
        ['MN-002', 'Papeda', 'Makanan Utama', 18000,
            'Bubur sagu khas Maluku, disajikan hangat sebagai pendamping ikan kuah kuning.',
            ['BB-011' => [150, 'gram'], 'BB-014' => [2, 'gram']]],
        ['MN-003', 'Ikan Bakar Rica', 'Makanan Utama', 55000,
            'Ikan cakalang bakar dengan bumbu rica pedas dan aroma daun kemangi.',
            ['BB-002' => [300, 'gram'], 'BB-005' => [15, 'gram'], 'BB-007' => [15, 'gram'],
             'BB-008' => [5, 'gram'], 'BB-013' => [20, 'ml'], 'BB-014' => [3, 'gram']]],
        ['MN-004', 'Nasi Kuning Ambon', 'Lauk & Pendamping', 30000,
            'Nasi kuning gurih dengan santan dan kunyit, pendamping lauk khas Maluku.',
            ['BB-010' => [200, 'gram'], 'BB-008' => [5, 'gram'], 'BB-004' => [50, 'ml'],
             'BB-005' => [10, 'gram'], 'BB-014' => [2, 'gram']]],
        ['MN-005', 'Rujak Natsepa', 'Camilan', 20000,
            'Rujak buah khas Pantai Natsepa dengan bumbu gula merah dan kacang tanah.',
            ['BB-015' => [250, 'gram'], 'BB-012' => [30, 'gram'], 'BB-016' => [20, 'gram'],
             'BB-007' => [10, 'gram'], 'BB-014' => [2, 'gram']]],
        ['MN-006', 'Sagu Lempeng Kenari', 'Camilan', 15000,
            'Sagu lempeng panggang dengan taburan kenari dan gula merah.',
            ['BB-011' => [100, 'gram'], 'BB-003' => [20, 'gram'], 'BB-012' => [20, 'gram']]],
        ['MN-007', 'Kopi Sagu', 'Minuman', 12000,
            'Minuman hangat sagu dan gula merah dengan santan, khas Maluku.',
            ['BB-011' => [50, 'gram'], 'BB-012' => [25, 'gram'], 'BB-004' => [50, 'ml']]],
    ];
    $menuIds = [];
    $menuMeta = [];
    foreach ($menuDef as $m) {
        $mid = insert_row($pdo, 'menus', [
            'code' => $m[0], 'name' => $m[1], 'category' => $m[2], 'price' => $m[3],
            'description' => $m[4], 'is_active' => 1, 'created_at' => date('Y-m-d H:i:s', $ts - 35 * 86400),
        ]);
        $menuIds[$m[0]] = $mid;
        $rid = insert_row($pdo, 'recipes', [
            'menu_id' => $mid, 'yield_qty' => 1, 'yield_unit' => 'porsi',
            'notes' => 'Resep standar (standard recipe) — takaran mengikuti standar dapur.',
            'updated_at' => now_str(),
        ]);
        $req = [];
        foreach ($m[5] as $code => [$q, $u]) {
            insert_row($pdo, 'recipe_items', [
                'recipe_id' => $rid, 'ingredient_id' => $ingIds[$code], 'qty' => $q, 'unit' => $u, 'note' => null,
            ]);
            $base = convert_unit($q, $u, $ingMeta[$code]['unit']);
            $req[$code] = $base; // kebutuhan dalam satuan stok
        }
        $menuMeta[$m[0]] = ['id' => $mid, 'name' => $m[1], 'price' => (float)$m[3], 'req' => $req, 'weight' => 10];
    }
    // Bobot popularitas (MN-001 paling populer)
    $weights = ['MN-001' => 30, 'MN-003' => 22, 'MN-004' => 20, 'MN-002' => 14, 'MN-005' => 8, 'MN-006' => 4, 'MN-007' => 6];
    foreach ($weights as $c => $w) $menuMeta[$c]['weight'] = $w;

    /* ------------------------- 6. RENCANA PENJUALAN 30 HARI ------------------------- */
    $DAYS = 30;
    $menuCodes = array_keys($menuMeta);
    $chanMix = array_merge(array_fill(0, 55, 'Kasir'), array_fill(0, 20, 'QRIS'),
                           array_fill(0, 15, 'Marketplace'), array_fill(0, 7, 'Online'), array_fill(0, 3, 'Lainnya'));
    $payMap = ['Kasir' => 'Tunai', 'QRIS' => 'QRIS', 'Marketplace' => 'Marketplace', 'Online' => 'Transfer Bank', 'Lainnya' => 'Lainnya'];

    $salesByDay = [];
    for ($off = $DAYS - 1; $off >= 0; $off--) {
        $dow = (int)date('N', strtotime('today') - $off * 86400);
        $weekend = $dow >= 6;
        $n = (mt_rand(0, 99) < 65 ? 1 : 2) + ($weekend ? 1 : 0);
        $day = [];
        for ($i = 0; $i < $n; $i++) {
            $items = [];
            $nItem = mt_rand(1, 100) < 55 ? 1 : 2;
            for ($k = 0; $k < $nItem; $k++) {
                $menu = pick_weighted($menuMeta);
                $q = mt_rand(1, 100) < 70 ? 1 : (mt_rand(0, 1) ? 2 : 3);
                if (isset($items[$menu])) $items[$menu] += $q; else $items[$menu] = $q;
            }
            $jam = mt_rand(10, 21);
            $day[] = [
                'off' => $off,
                'time' => sprintf('%02d:%02d:%02d', $jam, mt_rand(0, 59), mt_rand(0, 59)),
                'items' => $items,
                'channel' => $chanMix[mt_rand(0, count($chanMix) - 1)],
            ];
        }
        usort($day, fn($a, $b) => strcmp($a['time'], $b['time']));
        $salesByDay[$off] = $day;
    }

    /* ------------------------- 7. SIMULASI & EKSEKUSI ------------------------- */
    $stock = [];
    $avg = [];
    $last = [];

    // 7a. Hitung kebutuhan bahan per hari (untuk menentukan saldo awal & restock)
    $needByDay = [];  // [off][ingCode] => qty
    foreach ($salesByDay as $off => $daySales) {
        foreach ($daySales as $s) {
            foreach ($s['items'] as $mc => $q) {
                foreach ($menuMeta[$mc]['req'] as $ingCode => $qtyBase) {
                    $needByDay[$off][$ingCode] = ($needByDay[$off][$ingCode] ?? 0) + $qtyBase * $q;
                }
            }
        }
    }
    // Rencana waste per hari (5-8% dari pemakaian, dibulatkan wajar)
    $wastePlan = [];
    $wasteReasons = array_merge(array_fill(0, 25, 'Rusak'), array_fill(0, 25, 'Kedaluwarsa'),
        array_fill(0, 20, 'Busuk'), array_fill(0, 12, 'Kesalahan Produksi'),
        array_fill(0, 12, 'Sisa Produksi'), array_fill(0, 6, 'Lainnya'));
    for ($off = $DAYS - 1; $off >= 0; $off--) {
        if (mt_rand(0, 99) > 42) continue; // tidak setiap hari ada waste
        $candidates = array_keys($needByDay[$off] ?? []);
        if (!$candidates) continue;
        shuffle($candidates);
        $nW = min(count($candidates), mt_rand(1, 2));
        for ($i = 0; $i < $nW; $i++) {
            $code = $candidates[$i];
            $qty = $needByDay[$off][$code] * (mt_rand(30, 110) / 1000);
            $unit = $ingMeta[$code]['unit'];
            $minW = $unit === 'gram' ? 20 : 0.15;
            if ($qty < $minW) $qty = $minW;
            $wastePlan[$off][] = ['ing' => $code, 'qty' => round($qty, 3), 'reason' => $wasteReasons[mt_rand(0, count($wasteReasons) - 1)]];
        }
    }

    // 7b. Saldo awal (stock opname awal sebelum sistem dipakai)
    foreach ($ingMeta as $code => $m) {
        $kebutuhan3 = 0.0;
        for ($off = $DAYS - 1; $off >= max(0, $DAYS - 3); $off--) {
            $kebutuhan3 += $needByDay[$off][$code] ?? 0;
        }
        $opening = round(max($m['min'] * 1.5, $kebutuhan3 * 1.2), 3);
        $avg[$code] = $m['cost'];
        $last[$code] = $m['cost'];
        apply_movement($pdo, $ingIds[$code], 'saldo_awal', $opening, [
            'unit' => $m['unit'], 'unit_cost' => $m['cost'], 'total_cost' => round($opening * $m['cost'], 2),
            'ref_type' => 'saldo_awal', 'ref_code' => 'SALDO-AWAL',
            'note' => 'Saldo awal data demo (hasil perhitungan stok fisik awal)',
            'moved_at' => date('Y-m-d H:i:s', strtotime('today') - ($DAYS - 1) * 86400 + 7 * 3600),
            'user_id' => $ownerId, 'user_name' => 'Fizzul Rahman', 'allow_negative' => true,
        ]);
        $stock[$code] = $opening;
    }

    $saleSeq = [];
    $prcSeq = [];
    $wstSeq = [];

    for ($off = $DAYS - 1; $off >= 0; $off--) {
        $dateStr = date('Y-m-d', strtotime('today') - $off * 86400);
        $isToday = ($off === 0);

        /* --- 7c. RESTOCK: bahan dengan stok < 1.8x minimum --- */
        $bySup = [];
        foreach ($ingMeta as $code => $m) {
            if ($stock[$code] < $m['min'] * 1.5) {
                $kebutuhan5 = 0.0;
                for ($d = $off; $d >= max(0, $off - 4); $d--) {
                    $kebutuhan5 += $needByDay[$d][$code] ?? 0;
                }
                $target = max($m['min'] * 1.8, $kebutuhan5 * 1.05);
                $qty = round(max($target - $stock[$code], $m['min'] * 0.5), 3);
                if ($qty <= 0) continue;
                $bySup[$m['sup']][] = ['ing' => $code, 'qty' => $qty, 'cost' => price_variation($m['cost'])];
            }
        }
        foreach ($bySup as $supCode => $items) {
            $prcSeq[$off] = ($prcSeq[$off] ?? 0) + 1;
            $code = sprintf('PRC-%s-%04d', str_replace('-', '', $dateStr), $prcSeq[$off]);
            $total = 0.0;
            foreach ($items as $it) $total += round($it['qty'] * $it['cost'], 2);
            $purchaseId = insert_row($pdo, 'purchases', [
                'code' => $code, 'purchased_at' => $dateStr . ' 08:00:00', 'supplier_id' => $supIds[$supCode],
                'invoice_no' => 'NOTA-' . mt_rand(1000, 9999), 'total_cost' => round($total, 2),
                'note' => 'Pembelian bahan rutin (data demo)', 'created_by' => $dapurId,
                'created_at' => $dateStr . ' 08:05:00',
            ]);
            $expiry = null;
            foreach ($items as $it) {
                $m = $ingMeta[$it['ing']];
                $exp = null;
                if (in_array($it['ing'], ['BB-001', 'BB-002'], true)) {
                    $exp = date('Y-m-d', strtotime($dateStr) + (mt_rand(0, 1) ? 2 : 3) * 86400);
                } elseif (in_array($it['ing'], ['BB-004', 'BB-009', 'BB-015'], true)) {
                    $exp = date('Y-m-d', strtotime($dateStr) + mt_rand(2, 5) * 86400);
                } elseif ($it['ing'] === 'BB-007') {
                    $exp = date('Y-m-d', strtotime($dateStr) + mt_rand(5, 12) * 86400);
                }
                insert_row($pdo, 'purchase_items', [
                    'purchase_id' => $purchaseId, 'ingredient_id' => $ingIds[$it['ing']], 'qty' => $it['qty'],
                    'unit' => $m['unit'], 'unit_cost' => $it['cost'], 'total_cost' => round($it['qty'] * $it['cost'], 2),
                    'expiry_date' => $exp, 'note' => null,
                ]);
                // moving average cost
                $before = max($stock[$it['ing']], 0);
                $avg[$it['ing']] = ($before * $avg[$it['ing']] + $it['qty'] * $it['cost']) / max($before + $it['qty'], 0.0001);
                $last[$it['ing']] = $it['cost'];
                update_row($pdo, 'ingredients', $ingIds[$it['ing']], [
                    'avg_cost' => round($avg[$it['ing']], 4), 'last_cost' => $it['cost'],
                ]);
                apply_movement($pdo, $ingIds[$it['ing']], 'pembelian', $it['qty'], [
                    'unit' => $m['unit'], 'unit_cost' => $it['cost'], 'total_cost' => round($it['qty'] * $it['cost'], 2),
                    'ref_type' => 'purchase', 'ref_id' => $purchaseId, 'ref_code' => $code,
                    'note' => 'Penerimaan bahan dari ' . $supCode, 'moved_at' => $dateStr . ' 08:00:00',
                    'user_id' => $dapurId, 'user_name' => 'Bakri Tuasikal', 'allow_negative' => true,
                ]);
                $stock[$it['ing']] += $it['qty'];
            }
        }

        /* --- 7d. PENJUALAN --- */
        foreach ($salesByDay[$off] as $sale) {
            $time = $sale['time'];
            if ($isToday && strtotime($dateStr . ' ' . $time) > time() - 300) {
                $time = date('H:i:s', max(strtotime($dateStr . ' 07:00:00'), time() - mt_rand(600, 7200)));
            }
            $soldAt = $dateStr . ' ' . $time;
            $saleSeq[$off] = ($saleSeq[$off] ?? 0) + 1;
            $invoice = sprintf('INV-%s-%04d', str_replace('-', '', $dateStr), $saleSeq[$off]);

            $subtotal = 0.0; $hppTotal = 0.0; $lines = []; $need = [];
            foreach ($sale['items'] as $mc => $q) {
                $m = $menuMeta[$mc];
                $hppUnit = 0.0;
                foreach ($m['req'] as $ingCode => $qtyBase) {
                    $hppUnit += $qtyBase * $avg[$ingCode];
                    if (!isset($need[$ingCode])) $need[$ingCode] = 0.0;
                    $need[$ingCode] += $qtyBase * $q;
                }
                $line = round($q * $m['price'], 2);
                $subtotal += $line;
                $hppTotal += $hppUnit * $q;
                $lines[] = ['menu' => $mc, 'qty' => $q, 'price' => $m['price'], 'subtotal' => $line,
                            'hpp_unit' => round($hppUnit, 2), 'hpp_total' => round($hppUnit * $q, 2)];
            }
            $channel = $sale['channel'];
            $payment = $payMap[$channel];
            $saleId = insert_row($pdo, 'sales', [
                'invoice_no' => $invoice, 'sold_at' => $soldAt, 'channel' => $channel,
                'payment_method' => $payment, 'subtotal' => round($subtotal, 2), 'discount' => 0,
                'total' => round($subtotal, 2), 'hpp_total' => round($hppTotal, 2),
                'customer_note' => null, 'source' => 'manual', 'import_ref' => null, 'status' => 'selesai',
                'created_by' => $kasirId, 'created_at' => $soldAt,
            ]);
            foreach ($lines as $l) {
                insert_row($pdo, 'sale_items', [
                    'sale_id' => $saleId, 'menu_id' => $menuIds[$l['menu']], 'qty' => $l['qty'],
                    'unit_price' => $l['price'], 'subtotal' => $l['subtotal'],
                    'hpp_unit' => $l['hpp_unit'], 'hpp_total' => $l['hpp_total'],
                ]);
            }
            foreach ($need as $ingCode => $qty) {
                $qty = round($qty, 4);
                $m = $ingMeta[$ingCode];
                if ($stock[$ingCode] < $qty) { // top-up darurat agar stok demo tidak minus
                    add_emergency_purchase($pdo, $ingCode, $qty - $stock[$ingCode], $dateStr, $m, $ingIds, $supIds, $avg, $last, $stock, $dapurId, $prcSeq, $off);
                }
                apply_movement($pdo, $ingIds[$ingCode], 'penjualan', -$qty, [
                    'unit' => $m['unit'], 'unit_cost' => $avg[$ingCode], 'total_cost' => round($qty * $avg[$ingCode], 2),
                    'ref_type' => 'sale', 'ref_id' => $saleId, 'ref_code' => $invoice,
                    'note' => 'Penggunaan bahan otomatis dari resep', 'moved_at' => $soldAt,
                    'user_id' => $kasirId, 'user_name' => 'Sari Latuconsina', 'allow_negative' => true,
                ]);
                $stock[$ingCode] -= $qty;
            }
        }

        /* --- 7e. WASTE --- */
        foreach ($wastePlan[$off] ?? [] as $w) {
            $m = $ingMeta[$w['ing']];
            $qty = min($w['qty'], max($stock[$w['ing']], 0));
            if ($qty <= 0) continue;
            $wstSeq[$off] = ($wstSeq[$off] ?? 0) + 1;
            $wcode = sprintf('WST-%s-%04d', str_replace('-', '', $dateStr), $wstSeq[$off]);
            $cost = round($qty * $avg[$w['ing']], 2);
            $at = $dateStr . ' 21:30:00';
            $wid = insert_row($pdo, 'waste_records', [
                'code' => $wcode, 'wasted_at' => $at, 'ingredient_id' => $ingIds[$w['ing']], 'qty' => $qty,
                'unit' => $m['unit'], 'reason' => $w['reason'], 'est_cost' => $cost,
                'note' => 'Pencatatan waste harian (data demo)', 'status' => 'confirmed', 'movement_id' => null,
                'created_by' => $dapurId, 'created_by_name' => 'Bakri Tuasikal', 'created_at' => $at,
            ]);
            $mid = apply_movement($pdo, $ingIds[$w['ing']], 'waste', -$qty, [
                'unit' => $m['unit'], 'unit_cost' => $avg[$w['ing']], 'total_cost' => $cost,
                'ref_type' => 'waste', 'ref_id' => $wid, 'ref_code' => $wcode,
                'note' => 'Waste (' . $w['reason'] . ')', 'moved_at' => $at,
                'user_id' => $dapurId, 'user_name' => 'Bakri Tuasikal', 'allow_negative' => true,
            ]);
            update_row($pdo, 'waste_records', $wid, ['movement_id' => $mid]);
            $stock[$w['ing']] -= $qty;
        }
    }

    /* ---------- 7f. KONDISI STOK HARI INI (contoh status: Rendah/Kritis/Habis) ---------- */
    $nowStr = date('Y-m-d H:i:s');
    foreach ([['BB-007', 0.35, 'Penggunaan manual: persiapan pesanan katering (data demo)'],
              ['BB-001', 0.78, 'Penggunaan manual: pesanan tambahan di luar penjualan kasir (data demo)'],
              ['BB-004', 0.80, 'Penggunaan manual: uji resep kuah kuning (data demo)']] as [$code, $ratio, $note]) {
        $m = $ingMeta[$code];
        $target = max(round($m['min'] * $ratio, 3), 0.001);
        $kurang = round($stock[$code] - $target, 3);
        if ($kurang <= 0.0001) continue;
        apply_movement($pdo, $ingIds[$code], 'penggunaan_manual', -$kurang, [
            'unit' => $m['unit'], 'unit_cost' => $avg[$code], 'total_cost' => round($kurang * $avg[$code], 2),
            'ref_type' => 'manual', 'ref_code' => 'MANUAL-HARI-INI', 'note' => $note, 'moved_at' => $nowStr,
            'user_id' => $dapurId, 'user_name' => 'Bakri Tuasikal', 'allow_negative' => true,
        ]);
        $stock[$code] -= $kurang;
    }
    // Satu bahan habis total (busuk saat penyimpanan) — contoh status "Habis"
    $code = 'BB-006';
    if ($stock[$code] > 0.0001) {
        $m = $ingMeta[$code];
        $qty = round($stock[$code], 3);
        $wcode = 'WST-' . date('Ymd') . '-9001';
        $cost = round($qty * $avg[$code], 2);
        $wid = insert_row($pdo, 'waste_records', [
            'code' => $wcode, 'wasted_at' => $nowStr, 'ingredient_id' => $ingIds[$code], 'qty' => $qty,
            'unit' => $m['unit'], 'reason' => 'Busuk', 'est_cost' => $cost,
            'note' => 'Seluruh persediaan bawang putih busuk karena penyimpanan lembap (data demo)', 'status' => 'confirmed',
            'movement_id' => null, 'created_by' => $dapurId, 'created_by_name' => 'Bakri Tuasikal',
            'created_at' => $nowStr,
        ]);
        $mid = apply_movement($pdo, $ingIds[$code], 'waste', -$qty, [
            'unit' => $m['unit'], 'unit_cost' => $avg[$code], 'total_cost' => $cost,
            'ref_type' => 'waste', 'ref_id' => $wid, 'ref_code' => $wcode, 'note' => 'Waste (Busuk)',
            'moved_at' => $nowStr, 'user_id' => $dapurId, 'user_name' => 'Bakri Tuasikal', 'allow_negative' => true,
        ]);
        update_row($pdo, 'waste_records', $wid, ['movement_id' => $mid]);
        $stock[$code] = 0.0;
    }

    /* ------------------------- 8. STOCK OPNAME (disetujui) ------------------------- */
    $opDate = date('Y-m-d', strtotime('today') - 6 * 86400);
    $opCode = 'OPN-' . str_replace('-', '', $opDate) . '-0001';
    $opId = insert_row($pdo, 'stock_opnames', [
        'code' => $opCode, 'opname_date' => $opDate, 'scope' => 'Semua Bahan', 'status' => 'disetujui',
        'note' => 'Stock opname rutin mingguan (data demo)', 'created_by' => $dapurId,
        'created_by_name' => 'Bakri Tuasikal', 'submitted_at' => $opDate . ' 16:00:00',
        'approved_by' => $ownerId, 'approved_by_name' => 'Fizzul Rahman', 'approved_at' => $opDate . ' 17:30:00',
        'approval_note' => 'Selisih kecil wajar (penyusutan & penimbangan), penyesuaian disetujui.',
        'created_at' => $opDate . ' 15:00:00',
    ]);
    foreach ($ingMeta as $code => $m) {
        $sys = round($stock[$code], 3);
        // sebagian bahan punya selisih (penyusutan, tumpah, penimbangan)
        $punyaSelisih = ($code === 'BB-001' || $code === 'BB-004' || $code === 'BB-007' || $code === 'BB-010');
        $fisik = $sys;
        if ($punyaSelisih) {
            $delta = $sys * (mt_rand(20, 60) / 1000);
            if ($m['unit'] === 'gram') $delta = max(20, $delta);
            if ($m['unit'] === 'ikat') $delta = max(1, round($delta));
            $fisik = round(max(0, $sys - $delta), 3);
        }
        $diff = round($fisik - $sys, 3);
        $itemId = insert_row($pdo, 'stock_opname_items', [
            'opname_id' => $opId, 'ingredient_id' => $ingIds[$code], 'system_qty' => $sys,
            'physical_qty' => $fisik, 'diff_qty' => $diff, 'unit' => $m['unit'],
            'reason' => $diff == 0 ? 'Sesuai dengan stok sistem' : 'Selisih penimbangan/penyusutan bahan',
            'movement_id' => null,
        ]);
        if (abs($diff) > 0.0001) {
            $mid = apply_movement($pdo, $ingIds[$code], 'opname', $diff, [
                'unit' => $m['unit'], 'unit_cost' => $avg[$code], 'total_cost' => round(abs($diff) * $avg[$code], 2),
                'ref_type' => 'opname', 'ref_id' => $opId, 'ref_code' => $opCode,
                'note' => 'Penyesuaian hasil stock opname ' . $opCode, 'moved_at' => $opDate . ' 17:30:00',
                'user_id' => $ownerId, 'user_name' => 'Fizzul Rahman', 'allow_negative' => true,
            ]);
            update_row($pdo, 'stock_opname_items', $itemId, ['movement_id' => $mid]);
            $stock[$code] += $diff;
            update_row($pdo, 'ingredients', $ingIds[$code], ['current_stock' => round($stock[$code], 3)]);
        }
    }

    /* ------------------------- 9. STOCK OPNAME (draft, menunggu input fisik) ------------------------- */
    $op2Date = date('Y-m-d');
    $op2Id = insert_row($pdo, 'stock_opnames', [
        'code' => 'OPN-' . str_replace('-', '', $op2Date) . '-0002', 'opname_date' => $op2Date,
        'scope' => 'Semua Bahan', 'status' => 'draft', 'note' => 'Stock opname minggu ini (draft data demo)',
        'created_by' => $dapurId, 'created_by_name' => 'Bakri Tuasikal', 'submitted_at' => null,
        'approved_by' => null, 'approved_by_name' => null, 'approved_at' => null, 'approval_note' => null,
        'created_at' => $op2Date . ' 07:30:00',
    ]);
    foreach ($ingMeta as $code => $m) {
        insert_row($pdo, 'stock_opname_items', [
            'opname_id' => $op2Id, 'ingredient_id' => $ingIds[$code], 'system_qty' => round($stock[$code], 3),
            'physical_qty' => null, 'diff_qty' => null, 'unit' => $m['unit'], 'reason' => null, 'movement_id' => null,
        ]);
    }

    /* ------------------------- 10. SINKRONISASI STOK ------------------------- */
    rebuild_stock($pdo);

    /* ------------------------- 11. PENGATURAN & AUDIT ------------------------- */
    setting_set($pdo, 'business_name', 'Dapur Fizzul');
    setting_set($pdo, 'seeded_at', now_str());
    setting_set($pdo, 'demo_mode', '1');

    insert_row($pdo, 'audit_logs', [
        'logged_at' => now_str(), 'user_id' => $ownerId, 'user_name' => 'Fizzul Rahman',
        'action' => 'seed', 'entity' => 'system', 'entity_id' => null,
        'description' => 'Data DEMO dimuat otomatis saat aplikasi pertama kali dijalankan '
            . '(bahan baku, menu & resep, supplier, penjualan 30 hari, pembelian, waste, stock opname).',
        'meta' => json_encode(['demo' => true]), 'ip' => '127.0.0.1',
    ]);
}

/** Pilih menu acak berbobot popularitas */
function pick_weighted(array $menuMeta): string
{
    $total = 0;
    foreach ($menuMeta as $m) $total += $m['weight'];
    $r = mt_rand(1, $total);
    foreach ($menuMeta as $code => $m) {
        $r -= $m['weight'];
        if ($r <= 0) return $code;
    }
    return array_key_first($menuMeta);
}

/** Variasi harga beli (+/-) agar tren harga terlihat realistis */
function price_variation(float $base): float
{
    $f = 1 + (mt_rand(-50, 90) / 1000);
    $v = $base * $f;
    if ($base >= 1000) return round($v / 100) * 100;
    if ($base >= 100) return round($v);
    return round($v, 2);
}

/** Pembelian darurat saat simulasi (menjaga stok demo tidak minus) */
function add_emergency_purchase(PDO $pdo, string $code, float $kurang, string $dateStr, array $m,
                               array $ingIds, array $supIds, array &$avg, array &$last, array &$stock,
                               int $userId, array &$prcSeq, int $off): void
{
    $qty = round(max($kurang * 2, $m['min']), 3);
    $cost = price_variation($m['cost']);
    $prcSeq[$off] = ($prcSeq[$off] ?? 0) + 1;
    $pcode = sprintf('PRC-%s-%04d', str_replace('-', '', $dateStr), $prcSeq[$off]);
    $pid = insert_row($pdo, 'purchases', [
        'code' => $pcode, 'purchased_at' => $dateStr . ' 07:00:00', 'supplier_id' => $supIds[$m['sup']],
        'invoice_no' => 'NOTA-' . mt_rand(1000, 9999), 'total_cost' => round($qty * $cost, 2),
        'note' => 'Pembelian tambahan (kebutuhan mendesak, data demo)', 'created_by' => $userId,
        'created_at' => $dateStr . ' 07:05:00',
    ]);
    insert_row($pdo, 'purchase_items', [
        'purchase_id' => $pid, 'ingredient_id' => $ingIds[$code], 'qty' => $qty, 'unit' => $m['unit'],
        'unit_cost' => $cost, 'total_cost' => round($qty * $cost, 2), 'expiry_date' => null, 'note' => null,
    ]);
    $before = max($stock[$code], 0);
    $avg[$code] = ($before * $avg[$code] + $qty * $cost) / max($before + $qty, 0.0001);
    $last[$code] = $cost;
    update_row($pdo, 'ingredients', $ingIds[$code], ['avg_cost' => round($avg[$code], 4), 'last_cost' => $cost]);
    apply_movement($pdo, $ingIds[$code], 'pembelian', $qty, [
        'unit' => $m['unit'], 'unit_cost' => $cost, 'total_cost' => round($qty * $cost, 2),
        'ref_type' => 'purchase', 'ref_id' => $pid, 'ref_code' => $pcode,
        'note' => 'Penerimaan bahan tambahan', 'moved_at' => $dateStr . ' 07:00:00',
        'user_id' => $userId, 'user_name' => 'Bakri Tuasikal', 'allow_negative' => true,
    ]);
    $stock[$code] += $qty;
}
