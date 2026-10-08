<?php
declare(strict_types=1);

/**
 * Penanganan seluruh aksi POST (CRUD, transaksi, approval).
 * Dipanggil dari app.php (front controller) setelah verifikasi login + token CSRF.
 */
$pdo = $pdo ?? db();
$action = (string)($_POST['_action'] ?? '');

/** URL kembali (relatif, divalidasi agar tidak bisa dipakai open redirect) */
$back = (string)($_POST['_back'] ?? app_url(landing_page()));
// hanya menerima URL relatif internal (mis. ../penjualan/?from=...) => cegah open redirect
if (!preg_match('#^(\.\./)?[A-Za-z0-9_\-]+/(\?[A-Za-z0-9_%&=\.\-\+]*)?$#', $back)) {
    $back = app_url(landing_page());
}
$go = function (string $flashType, string $msg, ?string $to = null) use ($back): void {
    flash($flashType, $msg);
    header('Location: ' . ($to ?? $back));
    exit;
};

function pstr(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? null;
    return is_string($v) ? trim($v) : $default;
}
function pint(string $key, int $default = 0): int { return (int)parseNum($_POST[$key] ?? $default); }
function pfloat(string $key, float $default = 0.0): float { return parseNum($_POST[$key] ?? $default, $default); }
/** Kuantitas dari input <input type="number"> (desimal titik) */
function pqty(string $key, float $default = 0.0): float { return num_input($_POST[$key] ?? null, $default); }
/** Daftar kuantitas */
function pqty_arr(string $key): array { return array_map(fn($v) => num_input($v), parr($key)); }
function pbool(string $key): int { return !empty($_POST[$key]) ? 1 : 0; }
function parr(string $key): array { $v = $_POST[$key] ?? []; return is_array($v) ? $v : []; }
function pdt(string $key, ?string $fallback = null): ?string
{
    $v = pstr($key);
    if ($v === '') return $fallback;
    $v = str_replace('T', ' ', $v);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return $v . ' ' . date('H:i:s');
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v)) return $v . ':00';
    $ts = strtotime($v);
    return $ts ? date('Y-m-d H:i:s', $ts) : $fallback;
}

try {
    switch ($action) {

        /* =============================== MASTER: BAHAN BAKU =============================== */
        case 'save_ingredient': {
            require_perm('master');
            $id = pint('id');
            $name = pstr('name');
            if ($name === '') throw new RuntimeException('Nama bahan wajib diisi.');
            $unit = pstr('unit', 'kg');
            if (!isset(UNITS[$unit])) throw new RuntimeException('Satuan tidak dikenali.');
            $data = [
                'name' => $name,
                'category_id' => pint('category_id') ?: null,
                'unit' => $unit,
                'min_stock' => pqty('min_stock'),
                'supplier_id' => pint('supplier_id') ?: null,
                'storage_location' => pstr('storage_location') ?: null,
                'expiry_info' => pstr('expiry_info') ?: null,
                'is_active' => pbool('is_active'),
                'updated_at' => now_str(),
            ];
            tx($pdo, function (PDO $p) use ($id, $data, $name, $unit) {
                if ($id > 0) {
                    $old = q1($p, 'SELECT * FROM ingredients WHERE id = ?', [$id]);
                    if (!$old) throw new RuntimeException('Bahan baku tidak ditemukan.');
                    update_row($p, 'ingredients', $id, $data);
                    if (pbool('reset_harga')) {
                        $last = (float)qv($p, 'SELECT unit_cost FROM purchase_items WHERE ingredient_id = ? ORDER BY id DESC LIMIT 1', [$id], 0);
                        update_row($p, 'ingredients', $id, ['avg_cost' => pfloat('avg_cost'), 'last_cost' => $last > 0 ? $last : pfloat('avg_cost')]);
                    }
                    audit($p, 'update', 'ingredients', $id, 'Ubah bahan baku: ' . $name
                        . ' (stok tidak diubah langsung; perubahan stok hanya via pergerakan stok)', ['before' => $old['name'], 'after' => $name]);
                } else {
                    $code = pstr('code') ?: sprintf('BB-%03d', (int)qv($p, 'SELECT COUNT(*) FROM ingredients') + 1);
                    $data['code'] = $code;
                    $data['current_stock'] = 0;
                    $data['avg_cost'] = pfloat('avg_cost');
                    $data['last_cost'] = pfloat('avg_cost');
                    $data['created_at'] = now_str();
                    $newId = insert_row($p, 'ingredients', $data);
                    audit($p, 'create', 'ingredients', $newId, 'Tambah bahan baku: ' . $name . ' (' . $unit . ')');
                }
            });
            $go('ok', 'Data bahan baku berhasil disimpan.');
        }

        case 'adjust_stock': {
            require_perm('settings');
            $ingId = pint('ingredient_id');
            $mode = pstr('mode', 'set');
            $val = pqty('qty');
            $reason = pstr('reason');
            if ($reason === '') throw new RuntimeException('Alasan penyesuaian stok wajib diisi (audit trail).');
            $res = tx($pdo, function (PDO $p) use ($ingId, $mode, $val, $reason) {
                $ing = q1($p, 'SELECT * FROM ingredients WHERE id = ?', [$ingId]);
                if (!$ing) throw new RuntimeException('Bahan baku tidak ditemukan.');
                $delta = $mode === 'set' ? ($val - (float)$ing['current_stock']) : $val;
                if (abs($delta) < 0.00001) return null;
                $mid = apply_movement($p, $ingId, 'penyesuaian', $delta, [
                    'unit' => $ing['unit'], 'ref_type' => 'manual_adjust', 'ref_code' => 'ADJ-' . date('YmdHis'),
                    'note' => 'Penyesuaian stok manual (Owner): ' . $reason,
                ]);
                audit($p, 'adjust_stock', 'ingredients', $ingId, sprintf('Penyesuaian stok manual %s: %s -> %s %s. Alasan: %s',
                    $ing['name'], num($ing['current_stock']), num($ing['current_stock'] + $delta), $ing['unit'], $reason));
                return $mid;
            });
            $go('ok', $res ? 'Penyesuaian stok tersimpan beserta catatan pergerakan stok.' : 'Tidak ada perubahan stok (nilai sama).');
        }

        /* =============================== MASTER: SUPPLIER =============================== */
        case 'save_supplier': {
            require_perm('master');
            $id = pint('id');
            $name = pstr('name');
            if ($name === '') throw new RuntimeException('Nama supplier wajib diisi.');
            $data = [
                'name' => $name, 'contact_person' => pstr('contact_person') ?: null,
                'phone' => pstr('phone') ?: null, 'email' => pstr('email') ?: null,
                'address' => pstr('address') ?: null, 'notes' => pstr('notes') ?: null,
                'is_active' => pbool('is_active'),
            ];
            tx($pdo, function (PDO $p) use ($id, $data, $name) {
                if ($id > 0) {
                    update_row($p, 'suppliers', $id, $data);
                    audit($p, 'update', 'suppliers', $id, 'Ubah supplier: ' . $name);
                } else {
                    $data['code'] = pstr('code') ?: sprintf('SUP-%03d', (int)qv($p, 'SELECT COUNT(*) FROM suppliers') + 1);
                    $data['created_at'] = now_str();
                    $nid = insert_row($p, 'suppliers', $data);
                    audit($p, 'create', 'suppliers', $nid, 'Tambah supplier: ' . $name);
                }
            });
            $go('ok', 'Data supplier berhasil disimpan.');
        }

        /* =============================== MASTER: MENU =============================== */
        case 'save_menu': {
            require_perm('master');
            $id = pint('id');
            $name = pstr('name');
            if ($name === '') throw new RuntimeException('Nama menu wajib diisi.');
            $data = [
                'name' => $name, 'category' => pstr('category', 'Makanan Utama'),
                'price' => pfloat('price'), 'description' => pstr('description') ?: null,
                'is_active' => pbool('is_active'),
            ];
            tx($pdo, function (PDO $p) use ($id, $data, $name) {
                if ($id > 0) {
                    update_row($p, 'menus', $id, $data);
                    audit($p, 'update', 'menus', $id, 'Ubah menu: ' . $name . ' (harga jual ' . rp($data['price']) . ')');
                } else {
                    $data['code'] = pstr('code') ?: sprintf('MN-%03d', (int)qv($p, 'SELECT COUNT(*) FROM menus') + 1);
                    $data['created_at'] = now_str();
                    $nid = insert_row($p, 'menus', $data);
                    audit($p, 'create', 'menus', $nid, 'Tambah menu: ' . $name);
                }
            });
            $go('ok', 'Data menu berhasil disimpan.');
        }

        case 'toggle_menu': {
            require_perm('master');
            $id = pint('id');
            tx($pdo, function (PDO $p) use ($id) {
                $m = q1($p, 'SELECT * FROM menus WHERE id = ?', [$id]);
                if (!$m) throw new RuntimeException('Menu tidak ditemukan.');
                $new = (int)$m['is_active'] === 1 ? 0 : 1;
                update_row($p, 'menus', $id, ['is_active' => $new]);
                audit($p, 'toggle', 'menus', $id, ($new ? 'Aktifkan' : 'Nonaktifkan') . ' menu: ' . $m['name']);
            });
            $go('ok', 'Status menu diperbarui.');
        }

        /* =============================== MASTER: KATEGORI =============================== */
        case 'save_category': {
            require_perm('master');
            $id = pint('id');
            $name = pstr('name');
            if ($name === '') throw new RuntimeException('Nama kategori wajib diisi.');
            tx($pdo, function (PDO $p) use ($id, $name) {
                $data = ['name' => $name, 'description' => pstr('description') ?: null];
                if ($id > 0) {
                    update_row($p, 'ingredient_categories', $id, $data);
                    audit($p, 'update', 'ingredient_categories', $id, 'Ubah kategori bahan: ' . $name);
                } else {
                    $nid = insert_row($p, 'ingredient_categories', $data);
                    audit($p, 'create', 'ingredient_categories', $nid, 'Tambah kategori bahan: ' . $name);
                }
            });
            $go('ok', 'Kategori bahan berhasil disimpan.');
        }

        /* =============================== MASTER: PENGGUNA =============================== */
        case 'save_user': {
            require_perm('users');
            $id = pint('id');
            $username = strtolower(preg_replace('/[^a-z0-9_\.]/i', '', pstr('username')));
            $name = pstr('name');
            $roleId = pint('role_id');
            if ($username === '' || $name === '' || $roleId <= 0) throw new RuntimeException('Nama, username, dan role wajib diisi.');
            $password = (string)($_POST['password'] ?? '');
            tx($pdo, function (PDO $p) use ($id, $username, $name, $roleId, $password) {
                $exists = qv($p, 'SELECT id FROM users WHERE username = ? AND id != ?', [$username, $id]);
                if ($exists) throw new RuntimeException('Username sudah dipakai pengguna lain.');
                $data = [
                    'username' => $username, 'name' => $name, 'role_id' => $roleId,
                    'email' => pstr('email') ?: null, 'phone' => pstr('phone') ?: null,
                    'is_active' => pbool('is_active'),
                ];
                if ($password !== '') {
                    if (strlen($password) < 6) throw new RuntimeException('Kata sandi minimal 6 karakter.');
                    $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                }
                if ($id > 0) {
                    if ($id === (int)current_user()['id'] && pbool('is_active') === 0) {
                        throw new RuntimeException('Anda tidak dapat menonaktifkan akun Anda sendiri.');
                    }
                    update_row($p, 'users', $id, $data);
                    audit($p, 'update', 'users', $id, 'Ubah pengguna: ' . $username . ($password !== '' ? ' (kata sandi diubah)' : ''));
                } else {
                    if ($password === '') throw new RuntimeException('Kata sandi wajib diisi untuk pengguna baru.');
                    $data['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
                    $data['created_at'] = now_str();
                    $nid = insert_row($p, 'users', $data);
                    audit($p, 'create', 'users', $nid, 'Tambah pengguna: ' . $username . ' (' . $name . ')');
                }
            });
            $go('ok', 'Data pengguna berhasil disimpan.');
        }

        case 'reset_password': {
            require_perm('users');
            $id = pint('id');
            $password = (string)($_POST['password'] ?? '');
            if (strlen($password) < 6) throw new RuntimeException('Kata sandi minimal 6 karakter.');
            tx($pdo, function (PDO $p) use ($id, $password) {
                $u = q1($p, 'SELECT * FROM users WHERE id = ?', [$id]);
                if (!$u) throw new RuntimeException('Pengguna tidak ditemukan.');
                update_row($p, 'users', $id, ['password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
                audit($p, 'reset_password', 'users', $id, 'Reset kata sandi pengguna: ' . $u['username']);
            });
            $go('ok', 'Kata sandi berhasil direset.');
        }

        /* =============================== RESEP / BOM =============================== */
        case 'save_recipe': {
            require_perm('recipes');
            $menuId = pint('menu_id');
            $yieldQty = pfloat('yield_qty', 1) ?: 1;
            $ings = parr('ingredient_id');
            $qtys = parr('qty');
            $units = parr('unit');
            $notes = parr('note');
            $items = [];
            foreach ($ings as $i => $ingId) {
                $ingId = (int)$ingId;
                $q = num_input($qtys[$i] ?? 0);
                if ($ingId <= 0 || $q <= 0) continue;
                $u = (string)($units[$i] ?? 'gram');
                if (!isset(UNITS[$u])) $u = 'gram';
                $items[] = ['ingredient_id' => $ingId, 'qty' => $q, 'unit' => $u, 'note' => trim((string)($notes[$i] ?? '')) ?: null];
            }
            if (!$items) throw new RuntimeException('Resep harus memiliki minimal satu bahan dengan jumlah > 0.');
            tx($pdo, function (PDO $p) use ($menuId, $yieldQty, $items) {
                $menu = q1($p, 'SELECT * FROM menus WHERE id = ?', [$menuId]);
                if (!$menu) throw new RuntimeException('Menu tidak ditemukan.');
                $recipe = q1($p, 'SELECT * FROM recipes WHERE menu_id = ?', [$menuId]);
                if ($recipe) {
                    $rid = (int)$recipe['id'];
                    $p->prepare(sql_prepare('DELETE FROM recipe_items WHERE recipe_id = ?'))->execute([$rid]);
                    update_row($p, 'recipes', $rid, [
                        'yield_qty' => $yieldQty, 'yield_unit' => pstr('yield_unit', 'porsi') ?: 'porsi',
                        'notes' => pstr('notes') ?: null, 'updated_at' => now_str(),
                    ]);
                } else {
                    $rid = insert_row($p, 'recipes', [
                        'menu_id' => $menuId, 'yield_qty' => $yieldQty,
                        'yield_unit' => pstr('yield_unit', 'porsi') ?: 'porsi',
                        'notes' => pstr('notes') ?: null, 'updated_at' => now_str(),
                    ]);
                }
                foreach ($items as $it) {
                    $it['recipe_id'] = $rid;
                    insert_row($p, 'recipe_items', $it);
                }
                $hpp = menu_hpp($p, $menuId, 1);
                audit($p, 'update', 'recipes', $rid, sprintf('Perbarui resep %s: %d bahan, estimasi HPP %s/porsi',
                    $menu['name'], count($items), rp($hpp)), ['items' => count($items), 'hpp' => $hpp]);
            });
            $go('ok', 'Resep berhasil disimpan. Estimasi HPP dihitung dari harga bahan terkini.');
        }

        /* =============================== PENJUALAN =============================== */
        case 'save_sale': {
            require_perm('sales');
            $items = [];
            $menuIds = parr('menu_id');
            $qtys = parr('qty');
            $prices = parr('unit_price');
            foreach ($menuIds as $i => $mid) {
                if ((int)$mid <= 0) continue;
                $items[] = ['menu_id' => (int)$mid, 'qty' => num_input($qtys[$i] ?? 0), 'unit_price' => parseNum($prices[$i] ?? 0)];
            }
            if (!$items) throw new RuntimeException('Tambahkan minimal satu menu pada transaksi.');
            // Waktu transaksi manual hanya dicatat saat penyimpanan (tidak diisi pengguna).
            // Data dengan tanggal sendiri (marketplace) masuk lewat menu Impor Penjualan.
            $res = tx($pdo, fn(PDO $p) => record_sale($p, [
                'sold_at' => now_str(),
                'channel' => pstr('channel', 'Kasir'),
                'payment_method' => pstr('payment_method', 'Tunai'),
                'discount' => pfloat('discount'),
                'customer_note' => pstr('customer_note') ?: null,
                'source' => 'manual',
            ], $items));
            $msg = sprintf('Transaksi %s tersimpan: %s. Stok bahan berkurang otomatis sesuai resep.', $res['invoice_no'], rp($res['total']));
            if ($res['warnings']) {
                flash('warn', 'Perhatian stok: ' . implode(' | ', array_slice($res['warnings'], 0, 4)));
            }
            $go('ok', $msg, app_url('sale_detail', ['id' => $res['id']]));
        }

        case 'void_sale': {
            require_perm('sales_void');
            $id = pint('id');
            $reason = pstr('reason');
            if ($reason === '') throw new RuntimeException('Alasan pembatalan wajib diisi.');
            tx($pdo, function (PDO $p) use ($id, $reason) {
                $before = qv($p, 'SELECT status FROM sales WHERE id = ?', [$id]);
                if ($before === 'void') throw new RuntimeException('Transaksi sudah dibatalkan.');
                void_sale($p, $id, $reason);
            });
            $go('warn', 'Transaksi dibatalkan. Stok bahan dikembalikan dan tercatat pada pergerakan stok.');
        }

        /* =============================== IMPOR PENJUALAN =============================== */
        case 'import_preview': {
            require_perm('sales');
            $file = $_FILES['file'] ?? null;
            if (!$file || (int)($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Silakan pilih berkas CSV/Excel terlebih dahulu.');
            }
            $rows = read_upload_rows($file);
            if (count($rows) < 2) throw new RuntimeException('Berkas tidak memiliki data (hanya baris judul).');
            $header = array_shift($rows);
            $map = sales_header_map($header);
            if (!isset($map['menu']) || !isset($map['qty'])) {
                throw new RuntimeException('Kolom wajib tidak ditemukan. Berkas harus memiliki kolom "menu" dan "qty/jumlah". '
                    . 'Gunakan tombol "Unduh Template CSV".');
            }
            $defaultChannel = pstr('channel_default', 'Marketplace');
            $menus = q($pdo, 'SELECT id, code, name, price FROM menus');
            $byName = [];
            foreach ($menus as $m) {
                $byName[strtolower(trim((string)$m['name']))] = $m;
                $byName[strtolower(trim((string)$m['code']))] = $m;
            }
            $parsed = [];
            $errors = [];
            foreach ($rows as $i => $r) {
                $line = $i + 2;
                $rawMenu = clean_cell($r[$map['menu']] ?? '');
                $qty = parseNum($r[$map['qty']] ?? 0);
                if ($rawMenu === '' && $qty <= 0) continue;
                $menu = $byName[strtolower($rawMenu)] ?? null;
                $dt = parse_datetime($r[$map['tanggal'] ?? -1] ?? '', '12:00:00') ?? pdt('default_date', now_str());
                $hargaRaw = isset($map['harga']) ? clean_cell($r[$map['harga']] ?? '') : '';
                $price = $hargaRaw !== '' ? parseNum($hargaRaw) : ($menu['price'] ?? 0);
                $channel = isset($map['channel']) ? clean_cell($r[$map['channel']] ?? '') : '';
                if ($channel === '') $channel = $defaultChannel;
                $channel = ucfirst(strtolower($channel));
                if (!in_array($channel, CHANNELS, true)) {
                    $channel = in_array('Marketplace', CHANNELS, true) ? 'Marketplace' : CHANNELS[0];
                }
                $note = isset($map['catatan']) ? clean_cell($r[$map['catatan']] ?? '') : '';
                $err = [];
                if (!$menu) $err[] = 'menu "' . $rawMenu . '" tidak terdaftar';
                if ($qty <= 0) $err[] = 'jumlah tidak valid';
                $parsed[] = [
                    'line' => $line, 'menu_raw' => $rawMenu, 'menu_id' => $menu['id'] ?? null,
                    'menu_name' => $menu['name'] ?? null, 'qty' => $qty, 'unit_price' => $price,
                    'sold_at' => $dt, 'channel' => $channel, 'note' => $note !== '' ? $note : null,
                    'error' => $err ? implode(', ', $err) : null,
                ];
                if ($err) $errors[] = $line . ': ' . implode(', ', $err);
            }
            $_SESSION['import_preview'] = [
                'rows' => $parsed, 'file' => (string)$file['name'],
                'channel_default' => $defaultChannel, 'at' => now_str(),
            ];
            flash($errors ? 'warn' : 'ok', sprintf('%d baris terbaca dari %s. %d baris valid, %d baris bermasalah.',
                count($parsed), $file['name'], count(array_filter($parsed, fn($p) => $p['error'] === null)), count($errors)));
            $go('ok', 'Pratinjau impor siap diperiksa. Pastikan menu sudah benar sebelum menyimpan.', app_url('sales_import'));
        }

        case 'import_commit': {
            require_perm('sales');
            $preview = $_SESSION['import_preview'] ?? null;
            if (!$preview || empty($preview['rows'])) {
                throw new RuntimeException('Tidak ada pratinjau impor. Silakan unggah berkas terlebih dahulu.');
            }
            $valid = array_values(array_filter($preview['rows'], fn($r) => $r['error'] === null));
            if (!$valid) throw new RuntimeException('Tidak ada baris valid untuk disimpan.');
            $result = tx($pdo, function (PDO $p) use ($valid, $preview) {
                $batch = insert_row($p, 'import_batches', [
                    'created_at' => now_str(), 'file_name' => $preview['file'], 'rows_total' => count($preview['rows']),
                    'rows_ok' => count($valid), 'rows_failed' => count($preview['rows']) - count($valid),
                    'total_value' => 0, 'created_by' => current_user()['id'], 'created_by_name' => current_user()['name'],
                    'note' => 'Impor penjualan dari berkas ' . $preview['file'],
                ]);
                $sum = 0.0; $warn = []; $n = 0;
                foreach ($valid as $r) {
                    $res = record_sale($p, [
                        'sold_at' => $r['sold_at'], 'channel' => $r['channel'],
                        'payment_method' => str_starts_with($r['channel'], 'Marketplace') ? 'Marketplace'
                            : ($r['channel'] === 'QRIS' ? 'QRIS' : ($r['channel'] === 'Online' ? 'Transfer Bank' : 'Lainnya')),
                        'customer_note' => $r['note'], 'source' => 'import',
                        'import_ref' => $preview['file'] . ' baris ' . $r['line'],
                    ], [['menu_id' => $r['menu_id'], 'qty' => $r['qty'], 'unit_price' => $r['unit_price']]]);
                    $sum += $res['total'];
                    $n++;
                    foreach ($res['warnings'] as $w) $warn[$w] = true;
                }
                update_row($p, 'import_batches', $batch, ['total_value' => round($sum, 2)]);
                audit($p, 'import', 'sales', $batch, sprintf('Impor %d transaksi penjualan dari berkas %s (total %s)',
                    $n, $preview['file'], rp($sum)), ['file' => $preview['file'], 'rows' => $n]);
                return ['total' => $sum, 'n' => $n, 'warn' => array_keys($warn)];
            });
            unset($_SESSION['import_preview']);
            if ($result['warn']) {
                flash('warn', 'Catatan stok: ' . implode(' | ', array_slice($result['warn'], 0, 3)));
            }
            $go('ok', sprintf('%d transaksi impor tersimpan (total %s). Stok bahan otomatis berkurang sesuai resep masing-masing menu.',
                $result['n'], rp($result['total'])), app_url('sales'));
        }

        case 'import_cancel': {
            unset($_SESSION['import_preview']);
            $go('warn', 'Pratinjau impor dibatalkan.', app_url('sales_import'));
        }

        /* =============================== PEMBELIAN =============================== */
        case 'save_purchase': {
            require_perm('purchase');
            $purchasedAt = pdt('purchased_at', now_str());
            $supplierId = pint('supplier_id') ?: null;
            $ingIds = parr('ingredient_id');
            $qtys = parr('qty');
            $units = parr('unit');
            $costs = parr('unit_cost');
            $expiries = parr('expiry_date');
            $notes = parr('note');
            $items = [];
            foreach ($ingIds as $i => $iid) {
                $iid = (int)$iid;
                $q = num_input($qtys[$i] ?? 0);
                if ($iid <= 0 || $q <= 0) continue;
                $ing = q1($pdo, 'SELECT * FROM ingredients WHERE id = ?', [$iid]);
                if (!$ing) continue;
                $u = (string)($units[$i] ?? $ing['unit']);
                if (!isset(UNITS[$u])) $u = (string)$ing['unit'];
                $cost = parseNum($costs[$i] ?? 0);
                $exp = trim((string)($expiries[$i] ?? ''));
                $items[] = [
                    'ingredient_id' => $iid, 'qty' => $q, 'unit' => $u, 'unit_cost' => $cost,
                    'total_cost' => round($q * $cost, 2),
                    'expiry_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $exp) ? $exp : null,
                    'note' => trim((string)($notes[$i] ?? '')) ?: null,
                    'qty_base' => convert_unit($q, $u, (string)$ing['unit']),
                    'unit_base' => (string)$ing['unit'],
                ];
            }
            if (!$items) throw new RuntimeException('Tambahkan minimal satu bahan pada pembelian.');
            $total = array_sum(array_column($items, 'total_cost'));
            $res = tx($pdo, function (PDO $p) use ($purchasedAt, $supplierId, $items, $total) {
                $code = next_code($p, 'PRC', 'purchases');
                $pid = insert_row($p, 'purchases', [
                    'code' => $code, 'purchased_at' => $purchasedAt, 'supplier_id' => $supplierId,
                    'invoice_no' => pstr('invoice_no') ?: null, 'total_cost' => round($total, 2),
                    'note' => pstr('note') ?: null, 'created_by' => current_user()['id'], 'created_at' => now_str(),
                ]);
                foreach ($items as $it) {
                    insert_row($p, 'purchase_items', [
                        'purchase_id' => $pid, 'ingredient_id' => $it['ingredient_id'], 'qty' => $it['qty'],
                        'unit' => $it['unit'], 'unit_cost' => $it['unit_cost'], 'total_cost' => $it['total_cost'],
                        'expiry_date' => $it['expiry_date'], 'note' => $it['note'],
                    ]);
                    $ing = q1($p, 'SELECT * FROM ingredients WHERE id = ?', [$it['ingredient_id']]);
                    // harga satuan disimpan dalam satuan stok bahan
                    $unitCostBase = $it['qty_base'] > 0 ? $it['total_cost'] / $it['qty_base'] : $it['unit_cost'];
                    $before = max((float)$ing['current_stock'], 0);
                    $newAvg = ($before * (float)$ing['avg_cost'] + $it['qty_base'] * $unitCostBase)
                        / max($before + $it['qty_base'], 0.0001);
                    update_row($p, 'ingredients', (int)$it['ingredient_id'], [
                        'avg_cost' => round($newAvg, 4), 'last_cost' => round($unitCostBase, 4), 'updated_at' => now_str(),
                    ]);
                    apply_movement($p, (int)$it['ingredient_id'], 'pembelian', round($it['qty_base'], 4), [
                        'unit' => $it['unit_base'], 'unit_cost' => round($unitCostBase, 4),
                        'total_cost' => $it['total_cost'], 'ref_type' => 'purchase', 'ref_id' => $pid,
                        'ref_code' => $code, 'note' => 'Penerimaan bahan (pembelian)', 'moved_at' => $purchasedAt,
                    ]);
                }
                audit($p, 'create', 'purchases', $pid, sprintf('Pembelian %s sebesar %s (%d jenis bahan) menambah stok',
                    $code, rp($total), count($items)), ['total' => $total]);
                return ['code' => $code, 'id' => $pid];
            });
            $go('ok', sprintf('Pembelian %s (%s) tersimpan. Stok bertambah dan pergerakan stok tercatat.', $res['code'], rp($total)));
        }

        /* =============================== WASTE =============================== */
        case 'save_waste': {
            require_perm('waste');
            $ingId = pint('ingredient_id');
            $qty = pqty('qty');
            $reason = pstr('reason');
            $wastedAt = pdt('wasted_at', now_str());
            if ($ingId <= 0 || $qty <= 0) throw new RuntimeException('Bahan dan jumlah waste wajib diisi.');
            if (!in_array($reason, WASTE_REASONS, true)) throw new RuntimeException('Alasan waste tidak valid.');
            $res = tx($pdo, function (PDO $p) use ($ingId, $qty, $reason, $wastedAt) {
                $ing = q1($p, 'SELECT * FROM ingredients WHERE id = ?', [$ingId]);
                if (!$ing) throw new RuntimeException('Bahan baku tidak ditemukan.');
                $cost = round($qty * (float)$ing['avg_cost'], 2);
                $code = next_code($p, 'WST', 'waste_records');
                $wid = insert_row($p, 'waste_records', [
                    'code' => $code, 'wasted_at' => $wastedAt, 'ingredient_id' => $ingId, 'qty' => $qty,
                    'unit' => (string)$ing['unit'], 'reason' => $reason, 'est_cost' => $cost,
                    'note' => pstr('note') ?: null, 'status' => 'confirmed', 'movement_id' => null,
                    'created_by' => current_user()['id'], 'created_by_name' => current_user()['name'],
                    'created_at' => now_str(),
                ]);
                $mid = apply_movement($p, $ingId, 'waste', -1 * $qty, [
                    'unit' => (string)$ing['unit'], 'unit_cost' => (float)$ing['avg_cost'], 'total_cost' => $cost,
                    'ref_type' => 'waste', 'ref_id' => $wid, 'ref_code' => $code,
                    'note' => 'Waste (' . $reason . ')', 'moved_at' => $wastedAt,
                ]);
                update_row($p, 'waste_records', $wid, ['movement_id' => $mid]);
                audit($p, 'create', 'waste_records', $wid, sprintf('Catat waste %s: %s %s (%s), estimasi biaya %s',
                    $code, num($qty), $ing['unit'], $reason, rp($cost)));
                return ['code' => $code, 'cost' => $cost];
            });
            $go('ok', sprintf('Waste %s tercatat. Stok berkurang %s %s dan biaya waste %s dihitung otomatis.',
                $res['code'], num($qty), '', rp($res['cost'])));
        }

        case 'void_waste': {
            require_perm('waste_void');
            $id = pint('id');
            $reason = pstr('reason');
            if ($reason === '') throw new RuntimeException('Alasan pembatalan wajib diisi.');
            tx($pdo, function (PDO $p) use ($id, $reason) {
                $w = q1($p, 'SELECT w.*, i.name AS ing_name, i.unit AS ing_unit FROM waste_records w
                             JOIN ingredients i ON i.id = w.ingredient_id WHERE w.id = ?', [$id]);
                if (!$w) throw new RuntimeException('Data waste tidak ditemukan.');
                if ($w['status'] === 'void') throw new RuntimeException('Data waste sudah dibatalkan.');
                apply_movement($p, (int)$w['ingredient_id'], 'penyesuaian', (float)$w['qty'], [
                    'unit' => $w['ing_unit'], 'total_cost' => (float)$w['est_cost'],
                    'ref_type' => 'waste_void', 'ref_id' => $id, 'ref_code' => $w['code'],
                    'note' => 'Koreksi pembatalan waste ' . $w['code'] . ' — ' . $reason,
                ]);
                update_row($p, 'waste_records', $id, [
                    'status' => 'void', 'voided_by' => current_user()['id'], 'voided_at' => now_str(), 'void_reason' => $reason,
                ]);
                audit($p, 'void', 'waste_records', $id, sprintf('Batalkan waste %s (%s %s). Alasan: %s',
                    $w['code'], num($w['qty']), $w['ing_unit'], $reason));
            });
            $go('warn', 'Waste dibatalkan dan stok dikembalikan melalui pergerakan penyesuaian.');
        }

        /* =============================== PENGGUNAAN MANUAL =============================== */
        case 'manual_usage': {
            require_perm('manual_usage');
            $ingId = pint('ingredient_id');
            $qty = pqty('qty');
            $note = pstr('note');
            if ($ingId <= 0 || $qty <= 0) throw new RuntimeException('Bahan dan jumlah penggunaan wajib diisi.');
            if ($note === '') throw new RuntimeException('Keterangan penggunaan manual wajib diisi.');
            tx($pdo, function (PDO $p) use ($ingId, $qty, $note) {
                $ing = q1($p, 'SELECT * FROM ingredients WHERE id = ?', [$ingId]);
                if (!$ing) throw new RuntimeException('Bahan baku tidak ditemukan.');
                apply_movement($p, $ingId, 'penggunaan_manual', -1 * $qty, [
                    'unit' => (string)$ing['unit'], 'unit_cost' => (float)$ing['avg_cost'],
                    'total_cost' => round($qty * (float)$ing['avg_cost'], 2), 'ref_type' => 'manual',
                    'ref_code' => 'MANUAL-' . date('YmdHis'), 'note' => 'Penggunaan manual: ' . $note,
                ]);
                audit($p, 'manual_usage', 'ingredients', $ingId, sprintf('Penggunaan manual %s sebanyak %s %s. Keterangan: %s',
                    $ing['name'], num($qty), $ing['unit'], $note));
            });
            $go('ok', 'Penggunaan bahan manual tercatat pada pergerakan stok.');
        }

        /* =============================== STOCK OPNAME =============================== */
        case 'create_opname': {
            require_perm('opname');
            $date = pstr('opname_date') ?: today_str();
            $catId = pint('category_id');
            $items = $catId > 0
                ? q($pdo, 'SELECT id, current_stock, unit FROM ingredients WHERE is_active = 1 AND category_id = ? ORDER BY name', [$catId])
                : q($pdo, 'SELECT id, current_stock, unit FROM ingredients WHERE is_active = 1 ORDER BY name');
            if (!$items) throw new RuntimeException('Tidak ada bahan aktif pada cakupan yang dipilih.');
            $res = tx($pdo, function (PDO $p) use ($date, $items, $catId) {
                $code = next_code($p, 'OPN', 'stock_opnames');
                $oid = insert_row($p, 'stock_opnames', [
                    'code' => $code, 'opname_date' => $date,
                    'scope' => $catId > 0 ? (string)qv($p, 'SELECT name FROM ingredient_categories WHERE id = ?', [$catId], 'Kategori') : 'Semua Bahan',
                    'status' => 'draft', 'note' => pstr('note') ?: null,
                    'created_by' => current_user()['id'], 'created_by_name' => current_user()['name'],
                    'submitted_at' => null, 'approved_by' => null, 'approved_by_name' => null,
                    'approved_at' => null, 'approval_note' => null, 'created_at' => now_str(),
                ]);
                foreach ($items as $it) {
                    insert_row($p, 'stock_opname_items', [
                        'opname_id' => $oid, 'ingredient_id' => (int)$it['id'], 'system_qty' => (float)$it['current_stock'],
                        'physical_qty' => null, 'diff_qty' => null, 'unit' => (string)$it['unit'],
                        'reason' => null, 'movement_id' => null,
                    ]);
                }
                audit($p, 'create', 'stock_opnames', $oid, sprintf('Buat sesi stock opname %s (%d bahan, stok sistem diambil sebagai acuan)',
                    $code, count($items)));
                return ['id' => $oid, 'code' => $code];
            });
            $go('ok', 'Sesi stock opname ' . $res['code'] . ' dibuat. Silakan isi hasil hitung fisik.',
                app_url('opname_detail', ['id' => $res['id']]));
        }

        case 'save_opname_items': {
            require_perm('opname');
            $oid = pint('opname_id');
            $phys = parr('physical_qty');
            $reasons = parr('reason');
            $oid2 = tx($pdo, function (PDO $p) use ($oid, $phys, $reasons) {
                $op = q1($p, 'SELECT * FROM stock_opnames WHERE id = ?', [$oid]);
                if (!$op) throw new RuntimeException('Sesi stock opname tidak ditemukan.');
                if (in_array($op['status'], ['disetujui', 'ditolak'], true)) {
                    throw new RuntimeException('Sesi opname sudah ' . $op['status'] . ' dan tidak dapat diubah lagi.');
                }
                $n = 0;
                foreach (q($p, 'SELECT * FROM stock_opname_items WHERE opname_id = ?', [$oid]) as $it) {
                    $raw = trim((string)($phys[$it['id']] ?? ''));
                    $itemId = (int)$it['id'];
                    if ($raw === '') {
                        $p->prepare(sql_prepare('UPDATE stock_opname_items SET physical_qty = NULL, diff_qty = NULL, reason = ? WHERE id = ?'))
                          ->execute([trim((string)($reasons[$itemId] ?? '')) ?: null, $itemId]);
                        continue;
                    }
                    $fisik = num_input($raw);
                    $diff = round($fisik - (float)$it['system_qty'], 4);
                    $p->prepare(sql_prepare('UPDATE stock_opname_items SET physical_qty = ?, diff_qty = ?, reason = ? WHERE id = ?'))
                      ->execute([$fisik, $diff, trim((string)($reasons[$itemId] ?? '')) ?: ($diff != 0 ? 'Belum dijelaskan' : 'Sesuai stok sistem'), $itemId]);
                    $n++;
                }
                audit($p, 'update', 'stock_opnames', $oid, 'Input hasil hitung fisik stock opname ' . $op['code'] . ' (' . $n . ' bahan terisi)');
                return $n;
            });
            $go('ok', $oid2 . ' baris hasil hitung fisik tersimpan.');
        }

        case 'submit_opname': {
            require_perm('opname');
            $oid = pint('opname_id');
            tx($pdo, function (PDO $p) use ($oid) {
                $op = q1($p, 'SELECT * FROM stock_opnames WHERE id = ?', [$oid]);
                if (!$op) throw new RuntimeException('Sesi tidak ditemukan.');
                $kosong = (int)qv($p, 'SELECT COUNT(*) FROM stock_opname_items WHERE opname_id = ? AND physical_qty IS NULL', [$oid]);
                if ($kosong > 0) throw new RuntimeException('Masih ada ' . $kosong . ' bahan yang belum diisi jumlah fisiknya.');
                update_row($p, 'stock_opnames', $oid, ['status' => 'diajukan', 'submitted_at' => now_str()]);
                audit($p, 'submit', 'stock_opnames', $oid, 'Ajukan stock opname ' . $op['code'] . ' untuk persetujuan Owner');
            });
            $go('ok', 'Stock opname diajukan. Menunggu persetujuan Owner/Admin.');
        }

        case 'approve_opname': {
            require_perm('opname_approve');
            $oid = pint('opname_id');
            $res = tx($pdo, function (PDO $p) use ($oid) {
                $op = q1($p, 'SELECT * FROM stock_opnames WHERE id = ?', [$oid]);
                if (!$op) throw new RuntimeException('Sesi tidak ditemukan.');
                if ($op['status'] === 'disetujui') throw new RuntimeException('Sesi opname ini sudah disetujui.');
                $items = q($p, 'SELECT oi.*, i.name AS ing_name, i.current_stock, i.avg_cost FROM stock_opname_items oi
                               JOIN ingredients i ON i.id = oi.ingredient_id WHERE oi.opname_id = ?', [$oid]);
                $n = 0; $totalNilai = 0.0;
                foreach ($items as $it) {
                    if ($it['physical_qty'] === null) continue;
                    $diff = round((float)$it['physical_qty'] - (float)$it['current_stock'], 4);
                    if (abs($diff) < 0.00001) {
                        $p->prepare(sql_prepare('UPDATE stock_opname_items SET system_qty = ?, diff_qty = 0 WHERE id = ?'))
                          ->execute([(float)$it['current_stock'], (int)$it['id']]);
                        continue;
                    }
                    $mid = apply_movement($p, (int)$it['ingredient_id'], 'opname', $diff, [
                        'unit' => $it['unit'], 'unit_cost' => (float)$it['avg_cost'],
                        'total_cost' => round(abs($diff) * (float)$it['avg_cost'], 2),
                        'ref_type' => 'opname', 'ref_id' => $oid, 'ref_code' => $op['code'],
                        'note' => 'Penyesuaian hasil stock opname ' . $op['code'] . ($it['reason'] ? ' — ' . $it['reason'] : ''),
                    ]);
                    $p->prepare(sql_prepare('UPDATE stock_opname_items SET system_qty = ?, diff_qty = ?, movement_id = ? WHERE id = ?'))
                      ->execute([(float)$it['current_stock'], $diff, $mid, (int)$it['id']]);
                    $n++;
                    $totalNilai += abs($diff) * (float)$it['avg_cost'];
                }
                update_row($p, 'stock_opnames', $oid, [
                    'status' => 'disetujui', 'approved_by' => current_user()['id'],
                    'approved_by_name' => current_user()['name'], 'approved_at' => now_str(),
                    'approval_note' => pstr('approval_note') ?: 'Penyesuaian disetujui.',
                    'submitted_at' => $op['submitted_at'] ?: now_str(),
                ]);
                audit($p, 'approve', 'stock_opnames', $oid, sprintf('Setujui stock opname %s: %d penyesuaian stok diterapkan (nilai selisih %s)',
                    $op['code'], $n, rp($totalNilai)), ['adjustments' => $n, 'value' => $totalNilai]);
                return ['n' => $n];
            });
            $go('ok', $res['n'] . ' penyesuaian stok diterapkan dan tercatat pada pergerakan stok + audit trail.');
        }

        case 'reject_opname': {
            require_perm('opname_approve');
            $oid = pint('opname_id');
            $note = pstr('approval_note');
            if ($note === '') throw new RuntimeException('Alasan penolakan wajib diisi.');
            tx($pdo, function (PDO $p) use ($oid, $note) {
                $op = q1($p, 'SELECT * FROM stock_opnames WHERE id = ?', [$oid]);
                if (!$op) throw new RuntimeException('Sesi tidak ditemukan.');
                update_row($p, 'stock_opnames', $oid, [
                    'status' => 'ditolak', 'approved_by' => current_user()['id'],
                    'approved_by_name' => current_user()['name'], 'approved_at' => now_str(), 'approval_note' => $note,
                ]);
                audit($p, 'reject', 'stock_opnames', $oid, 'Tolak stock opname ' . $op['code'] . '. Alasan: ' . $note);
            });
            $go('warn', 'Stock opname ditolak tanpa mengubah stok. Sesi dapat dibuka kembali untuk diperbaiki.');
        }

        /* =============================== PENGATURAN =============================== */
        case 'save_settings': {
            require_perm('settings');
            tx($pdo, function (PDO $p) {
                setting_set($p, 'business_name', pstr('business_name', 'Dapur Fizzul'));
                setting_set($p, 'address', pstr('address'));
                setting_set($p, 'phone', pstr('phone'));
                audit($p, 'update', 'settings', null, 'Perbarui identitas usaha pada pengaturan sistem');
            });
            $go('ok', 'Pengaturan sistem disimpan.');
        }

        /* =============================== HAPUS / ARSIPKAN MASTER (Owner) =============================== */
        case 'delete_ingredient': {
            require_perm('master');
            $id = pint('id');
            $res = tx($pdo, function (PDO $p) use ($id) {
                $ing = q1($p, 'SELECT * FROM ingredients WHERE id = ?', [$id]);
                if (!$ing) throw new RuntimeException('Bahan baku tidak ditemukan.');
                $refs = [
                    'pergerakan stok' => (int)qv($p, 'SELECT COUNT(*) FROM inventory_movements WHERE ingredient_id = ?', [$id]),
                    'baris pembelian' => (int)qv($p, 'SELECT COUNT(*) FROM purchase_items WHERE ingredient_id = ?', [$id]),
                    'catatan waste'   => (int)qv($p, 'SELECT COUNT(*) FROM waste_records WHERE ingredient_id = ?', [$id]),
                    'item stock opname' => (int)qv($p, 'SELECT COUNT(*) FROM stock_opname_items WHERE ingredient_id = ?', [$id]),
                    'baris resep'     => (int)qv($p, 'SELECT COUNT(*) FROM recipe_items WHERE ingredient_id = ?', [$id]),
                ];
                $total = array_sum($refs);
                if ($total === 0) {
                    $p->prepare(sql_prepare('DELETE FROM ingredients WHERE id = ?'))->execute([$id]);
                    audit($p, 'delete', 'ingredients', $id, sprintf('Hapus permanen bahan baku %s (%s) — belum memiliki riwayat transaksi/resep',
                        $ing['name'], $ing['code']));
                    return ['mode' => 'hapus', 'name' => $ing['name'], 'refs' => []];
                }
                // Sudah memiliki riwayat -> arsipkan agar audit trail & laporan tetap utuh
                update_row($p, 'ingredients', $id, ['is_active' => 0, 'updated_at' => now_str()]);
                $rincian = [];
                foreach ($refs as $label => $n) { if ($n > 0) $rincian[] = $label . ': ' . $n; }
                audit($p, 'archive', 'ingredients', $id, sprintf('Arsipkan bahan baku %s (%s) karena memiliki riwayat (%s)',
                    $ing['name'], $ing['code'], implode(', ', $rincian)));
                return ['mode' => 'arsip', 'name' => $ing['name'], 'refs' => $rincian];
            });
            if ($res['mode'] === 'hapus') {
                $go('ok', 'Bahan baku "' . $res['name'] . '" dihapus permanen (belum pernah dipakai pada resep/transaksi).');
            }
            $go('warn', 'Bahan baku "' . $res['name'] . '" diarsipkan (disembunyikan dari daftar aktif), bukan dihapus permanen, '
                . 'karena masih memiliki riwayat: ' . implode(', ', $res['refs'])
                . '. Riwayat transaksi & laporan tetap utuh; gunakan tombol "Aktifkan" untuk mengembalikannya.');
        }

        case 'toggle_ingredient': {
            require_perm('master');
            $id = pint('id');
            tx($pdo, function (PDO $p) use ($id) {
                $ing = q1($p, 'SELECT * FROM ingredients WHERE id = ?', [$id]);
                if (!$ing) throw new RuntimeException('Bahan baku tidak ditemukan.');
                $new = (int)$ing['is_active'] === 1 ? 0 : 1;
                update_row($p, 'ingredients', $id, ['is_active' => $new, 'updated_at' => now_str()]);
                audit($p, 'toggle', 'ingredients', $id, ($new ? 'Aktifkan kembali' : 'Nonaktifkan/arsipkan') . ' bahan baku: ' . $ing['name']);
            });
            $go('ok', 'Status bahan baku diperbarui.');
        }

        case 'delete_supplier': {
            require_perm('master');
            $id = pint('id');
            $res = tx($pdo, function (PDO $p) use ($id) {
                $sup = q1($p, 'SELECT * FROM suppliers WHERE id = ?', [$id]);
                if (!$sup) throw new RuntimeException('Supplier tidak ditemukan.');

                // ==== RESTRICTION DELETE ====
                // Supplier yang sudah memiliki riwayat tidak boleh dihapus sama sekali,
                // agar foreign key & histori transaksi/pembelian tetap utuh.
                $nBahan = (int)qv($p, 'SELECT COUNT(*) FROM ingredients WHERE supplier_id = ?', [$id]);
                $nNota = (int)qv($p, 'SELECT COUNT(*) FROM purchases WHERE supplier_id = ?', [$id]);
                if ($nBahan > 0 || $nNota > 0) {
                    $rincian = [];
                    if ($nNota > 0) $rincian[] = $nNota . ' nota pembelian';
                    if ($nBahan > 0) $rincian[] = $nBahan . ' bahan baku terkait';
                    throw new RuntimeException(sprintf(
                        'Supplier "%s" TIDAK DAPAT DIHAPUS karena sudah memiliki riwayat (%s). '
                        . 'Data pembelian, harga, dan laporan harus tetap utuh. Gunakan tombol "Arsipkan" '
                        . 'untuk menonaktifkannya sehingga tidak muncul lagi pada pilihan supplier baru.',
                        $sup['name'], implode(' dan ', $rincian)
                    ));
                }

                $p->prepare(sql_prepare('DELETE FROM suppliers WHERE id = ?'))->execute([$id]);
                audit($p, 'delete', 'suppliers', $id, sprintf(
                    'Hapus permanen supplier %s (%s) — belum memiliki bahan baku maupun nota pembelian',
                    $sup['name'], $sup['code']));
                return $sup['name'];
            });
            $go('ok', 'Supplier "' . $res . '" dihapus permanen (belum pernah dipakai pada bahan baku/pembelian).');
        }

        case 'toggle_supplier': {
            require_perm('master');
            $id = pint('id');
            tx($pdo, function (PDO $p) use ($id) {
                $sup = q1($p, 'SELECT * FROM suppliers WHERE id = ?', [$id]);
                if (!$sup) throw new RuntimeException('Supplier tidak ditemukan.');
                $new = (int)$sup['is_active'] === 1 ? 0 : 1;
                update_row($p, 'suppliers', $id, ['is_active' => $new]);
                audit($p, 'toggle', 'suppliers', $id, ($new ? 'Aktifkan kembali' : 'Nonaktifkan/arsipkan') . ' supplier: ' . $sup['name']);
            });
            $go('ok', 'Status supplier diperbarui.');
        }

        case 'delete_menu': {
            require_perm('master');
            $id = pint('id');
            $res = tx($pdo, function (PDO $p) use ($id) {
                $menu = q1($p, 'SELECT * FROM menus WHERE id = ?', [$id]);
                if (!$menu) throw new RuntimeException('Menu tidak ditemukan.');
                $nSale = (int)qv($p, 'SELECT COUNT(*) FROM sale_items WHERE menu_id = ?', [$id]);
                $nResep = (int)qv($p, 'SELECT COUNT(*) FROM recipe_items ri JOIN recipes r ON r.id = ri.recipe_id WHERE r.menu_id = ?', [$id]);
                if ($nSale === 0) {
                    // resep & item resep ikut terhapus (ON DELETE CASCADE); stok tidak terpengaruh
                    $p->prepare(sql_prepare('DELETE FROM menus WHERE id = ?'))->execute([$id]);
                    audit($p, 'delete', 'menus', $id, sprintf('Hapus permanen menu %s (%s)%s',
                        $menu['name'], $menu['code'], $nResep > 0 ? ' beserta resepnya (' . $nResep . ' baris bahan)' : ''));
                    return ['mode' => 'hapus', 'name' => $menu['name'], 'nResep' => $nResep];
                }
                update_row($p, 'menus', $id, ['is_active' => 0]);
                audit($p, 'archive', 'menus', $id, sprintf('Arsipkan menu %s (%s) karena sudah memiliki %d baris penjualan',
                    $menu['name'], $menu['code'], $nSale));
                return ['mode' => 'arsip', 'name' => $menu['name'], 'nSale' => $nSale];
            });
            if ($res['mode'] === 'hapus') {
                $go('ok', 'Menu "' . $res['name'] . '" dihapus permanen'
                    . ($res['nResep'] > 0 ? ' beserta ' . $res['nResep'] . ' baris resepnya' : '') . '.');
            }
            $go('warn', 'Menu "' . $res['name'] . '" diarsipkan (tidak dijual lagi), bukan dihapus permanen, karena sudah memiliki '
                . $res['nSale'] . ' baris penjualan. Laporan & analitik periode lalu tetap akurat; gunakan tombol "Aktifkan" untuk menjualnya kembali.');
        }

        case 'delete_recipe': {
            require_perm('recipes');
            $id = pint('id');           // id menu
            $res = tx($pdo, function (PDO $p) use ($id) {
                $menu = q1($p, 'SELECT * FROM menus WHERE id = ?', [$id]);
                if (!$menu) throw new RuntimeException('Menu tidak ditemukan.');
                $recipe = q1($p, 'SELECT * FROM recipes WHERE menu_id = ?', [$id]);
                if (!$recipe) throw new RuntimeException('Menu ini belum memiliki resep.');
                $nItems = (int)qv($p, 'SELECT COUNT(*) FROM recipe_items WHERE recipe_id = ?', [(int)$recipe['id']]);
                $nSale = (int)qv($p, 'SELECT COUNT(*) FROM sale_items WHERE menu_id = ?', [$id]);
                $hppSebelum = menu_hpp($p, $id, 1);
                $p->prepare(sql_prepare('DELETE FROM recipe_items WHERE recipe_id = ?'))->execute([(int)$recipe['id']]);
                $p->prepare(sql_prepare('DELETE FROM recipes WHERE id = ?'))->execute([(int)$recipe['id']]);
                audit($p, 'delete', 'recipes', (int)$recipe['id'], sprintf(
                    'Hapus resep menu %s (%s): %d baris bahan dihapus%s. HPP/porsi sebelum dihapus %s',
                    $menu['name'], $menu['code'], $nItems,
                    $nSale > 0 ? ', riwayat ' . $nSale . ' baris penjualan tetap tersimpan (HPP transaksi tidak berubah)' : '',
                    rp($hppSebelum)));
                return ['name' => $menu['name'], 'nItems' => $nItems, 'nSale' => $nSale];
            });
            $go('warn', 'Resep menu "' . $res['name'] . '" dihapus (' . $res['nItems'] . ' baris bahan). '
                . ($res['nSale'] > 0 ? 'Riwayat penjualan & HPP transaksi lama tetap utuh. ' : '')
                . 'Selama resep belum dibuat ulang, penjualan menu ini tidak akan mengurangi stok bahan secara otomatis.');
        }

        default:
            throw new RuntimeException('Aksi tidak dikenali: ' . $action);
    }
} catch (RuntimeException $e) {
    $go('danger', $e->getMessage());
} catch (Throwable $e) {
    error_log('[SIMOP] action ' . $action . ' error: ' . $e->getMessage());
    $go('danger', 'Terjadi kesalahan sistem saat memproses data: ' . $e->getMessage());
}
