<?php
declare(strict_types=1);

/**
 * Ekspor CSV (kompatibel Excel). Semua ekspor memakai sumber data yang sama
 * dengan tampilan laporan agar konsisten.
 */
require __DIR__ . '/lib/bootstrap.php';

$pdo = db();
require_login();

$type = (string)($_GET['type'] ?? 'sales');

/**
 * Pembatasan jenis ekspor sesuai modul & hak akses.
 * Kasir hanya berhak atas data penjualan dan hasil cek ketersediaan menu
 * (modul Operasional kasir); seluruh laporan lain -- termasuk modul
 * Analitik & Laporan -- hanya untuk role yang memiliki hak 'reports'.
 */
$SALES_EXPORTS = ['template_sales', 'sales', 'sale_items'];
$AVAIL_EXPORTS = ['ketersediaan'];
if (in_array($type, $SALES_EXPORTS, true)) {
    require_perm('sales');
} elseif (in_array($type, $AVAIL_EXPORTS, true)) {
    require_perm('availability');
} else {
    require_perm('reports');
}
[$from, $to] = date_range(null, null, 30);
$opt = ['id' => (int)($_GET['id'] ?? 0), 'menu_id' => (int)($_GET['menu_id'] ?? 0), 'ingredient_id' => (int)($_GET['ingredient_id'] ?? 0)];

/** Nilai untuk CSV: angka tetap angka, teks lain tetap teks */
function csv_num($v)
{
    if (is_int($v) || is_float($v)) return (float)$v;
    return $v === null ? '' : (string)$v;
}

tx($pdo, fn(PDO $p) => audit($p, 'export', 'reports', null, 'Ekspor CSV jenis: ' . $type . ' (' . $from . ' s/d ' . $to . ')'));

switch ($type) {

    case 'ketersediaan': {
        $items = menu_availability($pdo);
        csv_download('ketersediaan_menu_' . date('Ymd_His') . '.csv',
            ['kode_menu', 'menu', 'kategori', 'status', 'estimasi_porsi_tersedia', 'bahan_penghambat',
             'bahan_habis', 'harga_jual', 'hpp_per_porsi', 'margin_persen'],
            array_map(fn($r) => array_map('csv_num', $r), array_map(fn($m) => [
                $m['code'], $m['name'], $m['category'], $m['status'],
                $m['porsi'] === null ? '' : $m['porsi'], $m['limit'] ?? '', (int)$m['habis'],
                (float)$m['price'], (float)$m['hpp'], round($m['margin'], 1),
            ], $items)));
    }

    case 'template_sales': {
        csv_download('template_impor_penjualan.csv',
            ['tanggal', 'menu', 'qty', 'harga', 'channel', 'metode_pembayaran', 'catatan'], [
                [date('d/m/Y'), 'Ikan Kuah Kuning Kenari', 2, 45000, 'Marketplace', 'Marketplace', 'contoh — hapus baris ini sebelum impor'],
                [date('d/m/Y'), 'Papeda', 1, 18000, 'Online', 'Transfer Bank', ''],
            ]);
    }

    case 'sale_items': {
        $rows = q($pdo, 'SELECT s.invoice_no, s.sold_at, s.channel, m.code AS kode_menu, m.name AS menu, si.qty,
                                si.unit_price, si.subtotal, si.hpp_unit, si.hpp_total,
                                (si.subtotal - si.hpp_total) AS laba
                         FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN menus m ON m.id = si.menu_id
                         WHERE date(s.sold_at) BETWEEN ? AND ? ORDER BY s.sold_at, s.id', [$from, $to]);
        csv_download('detail_penjualan_' . $from . '_' . $to . '.csv',
            ['invoice', 'waktu', 'channel', 'kode_menu', 'menu', 'qty', 'harga_satuan', 'subtotal', 'hpp_unit', 'hpp_total', 'laba_kotor'],
            array_map(fn($r) => array_map('csv_num', $r), $rows));
    }

    case 'audit': {
        $rows = q($pdo, 'SELECT logged_at, user_name, action, entity, entity_id, description, ip
                         FROM audit_logs WHERE date(logged_at) BETWEEN ? AND ? ORDER BY logged_at DESC', [$from, $to]);
        csv_download('audit_trail_' . $from . '_' . $to . '.csv',
            ['waktu', 'pengguna', 'aksi', 'entitas', 'id_entitas', 'deskripsi', 'ip'],
            array_map(fn($r) => array_map('csv_num', $r), $rows));
    }

    case 'ingredients':
    case 'suppliers':
    case 'menus': {
        $map = [
            'ingredients' => ['master_bahan_baku.csv', 'SELECT i.code, i.name, c.name AS kategori, i.unit, i.min_stock, i.current_stock,
                                      i.avg_cost, i.last_cost, s.name AS supplier, i.storage_location, i.expiry_info, i.is_active,
                                      (i.current_stock * i.avg_cost) AS nilai_stok
                               FROM ingredients i LEFT JOIN ingredient_categories c ON c.id = i.category_id
                               LEFT JOIN suppliers s ON s.id = i.supplier_id ORDER BY i.name',
                ['kode', 'nama_bahan', 'kategori', 'satuan', 'stok_minimum', 'stok_saat_ini', 'harga_rata_rata', 'harga_terakhir',
                 'supplier', 'lokasi_penyimpanan', 'info_kedaluwarsa', 'aktif', 'nilai_stok']],
            'suppliers' => ['master_supplier.csv', 'SELECT code, name, contact_person, phone, email, address, notes, is_active FROM suppliers ORDER BY name',
                ['kode', 'nama_supplier', 'kontak', 'telepon', 'email', 'alamat', 'catatan', 'aktif']],
            'menus' => ['master_menu.csv', 'SELECT m.code, m.name, m.category, m.price, m.description, m.is_active,
                                (SELECT COUNT(*) FROM recipes r JOIN recipe_items ri ON ri.recipe_id = r.id WHERE r.menu_id = m.id) AS jumlah_bahan
                         FROM menus m ORDER BY m.name',
                ['kode', 'nama_menu', 'kategori', 'harga_jual', 'deskripsi', 'aktif', 'jumlah_bahan_resep']],
        ];
        [$file, $sql, $header] = $map[$type];
        csv_download($file, $header, array_map(fn($r) => array_map('csv_num', $r), q($pdo, $sql)));
    }

    default: {
        $cat = report_catalog();
        // opname_items adalah rincian satu sesi opname (bukan laporan berdiri sendiri)
        $extra = ['opname_items'];
        if (!isset($cat[$type]) && !in_array($type, $extra, true)) {
            http_response_code(400);
            exit('Jenis ekspor tidak dikenali.');
        }
        $rep = report_definitions($pdo, $type, $from, $to, $opt);
        $rows = array_map(fn($r) => array_map('csv_num', $r), $rep['rows']);
        $file = str_replace(' ', '_', strtolower($type)) . '_' . $from . '_' . $to . '.csv';
        csv_download($file, $rep['columns'], $rows);
    }
}
