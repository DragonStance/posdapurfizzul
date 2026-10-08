<?php
declare(strict_types=1);

/* ============================ Ekspor CSV ============================ */

/** Kirim CSV ke browser (kompatibel Excel: BOM UTF-8 + pemisah koma) */
function csv_download(string $filename, array $header, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $header, ',', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($out, array_map(fn($v) => is_float($v) ? rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') : (string)$v, array_values($r)), ',', '"', '\\');
    }
    fclose($out);
    exit;
}

/* ============================ Impor berkas ============================ */

/** Baca CSV dari path menjadi array baris (array of array). */
function read_csv_rows(string $path): array
{
    $rows = [];
    $fh = fopen($path, 'r');
    if (!$fh) return $rows;
    $first = true;
    while (($line = fgets($fh)) !== false) {
        if ($first) { // buang BOM
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
            $first = false;
        }
        $line = rtrim($line, "\r\n");
        if (trim($line) === '') continue;
        $sep = (substr_count($line, ';') > substr_count($line, ',')) ? ';' : ',';
        $rows[] = array_map(fn($v) => trim((string)$v, " \t\"\xEF\xBB\xBF"), str_getcsv($line, $sep, '"', '\\'));
    }
    fclose($fh);
    return $rows;
}

/** Baca sheet pertama file .xlsx (tanpa library eksternal). */
function read_xlsx_rows(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Dukungan ZIP tidak tersedia di server untuk membaca .xlsx. Silakan simpan file sebagai CSV.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Berkas .xlsx tidak dapat dibuka (rusak atau bukan format Excel).');
    }
    $shared = [];
    if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $xml = simplexml_load_string($ss);
        if ($xml !== false) {
            foreach ($xml->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string)$si->t;
                } else {
                    $txt = '';
                    foreach ($si->r as $r) { $txt .= (string)$r->t; }
                    $shared[] = $txt;
                }
            }
        }
    }
    // Cari worksheet pertama
    $sheetXml = false;
    for ($i = 1; $i <= 5; $i++) {
        $sheetXml = $zip->getFromName('xl/worksheets/sheet' . $i . '.xml');
        if ($sheetXml !== false) break;
    }
    if ($sheetXml === false) {
        $zip->close();
        throw new RuntimeException('Sheet Excel tidak ditemukan di dalam berkas.');
    }
    $xml = simplexml_load_string($sheetXml);
    $zip->close();
    if ($xml === false) throw new RuntimeException('Isi sheet Excel tidak dapat dibaca.');

    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $line = [];
        $maxIdx = -1;
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $idx = xlsx_col_index($ref);
            $type = (string)$c['t'];
            if ($type === 's') {
                $val = $shared[(int)$c->v] ?? '';
            } elseif ($type === 'inlineStr') {
                $val = (string)($c->is->t ?? '');
            } elseif ($type === 'str') {
                $val = (string)$c->v;
            } else {
                $val = (string)$c->v;
            }
            $line[$idx] = $val;
            $maxIdx = max($maxIdx, $idx);
        }
        $out = [];
        for ($i = 0; $i <= $maxIdx; $i++) {
            $out[$i] = $line[$i] ?? '';
        }
        if (count(array_filter($out, fn($v) => trim((string)$v) !== '')) === 0) continue;
        $rows[] = $out;
    }
    return $rows;
}

function xlsx_col_index(string $ref): int
{
    if (!preg_match('/^([A-Z]+)/', strtoupper($ref), $m)) return 0;
    $letters = $m[1];
    $n = 0;
    for ($i = 0; $i < strlen($letters); $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}

/** Konversi tanggal Excel serial -> Y-m-d */
function excel_serial_to_date($v): ?string
{
    if (!is_numeric($v)) return null;
    $n = (float)$v;
    if ($n < 20000 || $n > 60000) return null;
    $ts = (int)round(($n - 25569) * 86400);
    return gmdate('Y-m-d', $ts);
}

/** Baca berkas unggahan (csv/xlsx) menjadi baris array-of-array */
function read_upload_rows(array $file): array
{
    $name = strtolower((string)($file['name'] ?? ''));
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Berkas tidak terunggah dengan benar. Silakan pilih ulang berkas.');
    }
    if (str_ends_with($name, '.xlsx')) {
        return read_xlsx_rows($tmp);
    }
    if (str_ends_with($name, '.xls')) {
        throw new RuntimeException('Format .xls lama belum didukung. Silakan simpan ulang sebagai .xlsx atau .csv.');
    }
    return read_csv_rows($tmp);
}

/* ============================ Impor penjualan ============================ */

/** Pemetaan judul kolom fleksibel -> kolom standar */
function sales_header_map(array $headerRow): array
{
    $aliases = [
        'tanggal'  => ['tanggal', 'tgl', 'date', 'waktu', 'tanggal/waktu', 'datetime', 'tanggal transaksi', 'sold_at'],
        'menu'     => ['menu', 'nama menu', 'produk', 'item', 'nama produk', 'product'],
        'qty'      => ['qty', 'jumlah', 'kuantitas', 'quantity', 'jml', 'terjual'],
        'harga'    => ['harga', 'harga jual', 'harga satuan', 'price', 'unit price'],
        'channel'  => ['channel', 'kanal', 'sumber', 'sumber penjualan', 'platform'],
        'metode'   => ['metode', 'metode pembayaran', 'pembayaran', 'payment', 'payment method'],
        'catatan'  => ['catatan', 'note', 'keterangan', 'remarks'],
        'diskon'   => ['diskon', 'discount', 'potongan'],
    ];
    $map = [];
    foreach ($headerRow as $i => $h) {
        $h = strtolower(trim((string)$h));
        if ($h === '') continue;
        foreach ($aliases as $std => $opts) {
            if ($h === $std || in_array($h, $opts, true)) {
                $map[$std] = $i;
                break;
            }
        }
    }
    return $map;
}

/** Bersihkan nilai dari Excel/CSV */
function clean_cell($v): string
{
    if ($v === null) return '';
    $s = trim((string)$v);
    // Buang format ilmiah yang tidak diinginkan
    return $s;
}

/** Ubah teks tanggal fleksibel menjadi format datetime MySQL/SQLite */
function parse_datetime($raw, ?string $fallbackTime = null): ?string
{
    $raw = clean_cell($raw);
    if ($raw === '') return null;
    if (is_numeric($raw)) {
        $d = excel_serial_to_date($raw);
        if ($d) return $d . ' ' . ($fallbackTime ?? '12:00:00');
    }
    $raw = str_replace(['/', '.'], '-', $raw);
    // dd-mm-yyyy -> yyyy-mm-dd
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})(.*)$/', $raw, $m)) {
        $raw = sprintf('%04d-%02d-%02d%s', (int)$m[3], (int)$m[2], (int)$m[1], $m[4]);
    }
    $ts = strtotime(trim($raw));
    if ($ts === false) return null;
    return date('Y-m-d H:i:s', $ts);
}
