# Menjalankan SIMOP Dapur Fizzul di dalam Laravel

Panduan ini menjelaskan cara menjalankan aplikasi ini **di dalam proyek Laravel** yang sudah ada,
tanpa mengubah kode aplikasi dan tanpa mengganggu halaman Laravel Anda.

Integrasi ini sudah diuji pada **Laravel 11.57** (PHP 8.2) dengan dua skenario:
aplikasi memakai SQLite sendiri, dan aplikasi **berbagi database MySQL dengan Laravel**
(tabel aplikasi otomatis diberi prefix `fizzul_` sehingga tidak bertabrakan).

---

## 1. Susunan folder

Letakkan folder `dapur-fizzul/` di **root proyek Laravel** (sejajar dengan `app/`, `routes/`, `public/`):

```
laravel-app/
├── app/
├── bootstrap/
├── config/
├── public/
│   └── dapur-fizzul/assets/          ← dibuat oleh installer (CSS/JS/Chart.js)
├── routes/
└── dapur-fizzul/                     ← SALIN FOLDER APLIKASI KE SINI
    ├── app.php, index.php, config.php, lib/, views/, assets/
    └── laravel/installer.php         ← skrip pemasang integrasi
```

Aplikasi **tidak** diletakkan di dalam `public/`, sehingga kode PHP dan berkas basis data
(`data/fizzul.sqlite`) tidak dapat diakses langsung dari browser.

## 2. Pasang integrasi (satu perintah)

```bash
cd /path/ke/laravel-app
php dapur-fizzul/laravel/installer.php
```

Installer akan:

1. Menulis `dapur-fizzul/config.local.php` — mengikuti pengaturan database di `.env` Laravel Anda.
   * Jika Laravel memakai **MySQL**, aplikasi memakai database yang sama dengan prefix **`fizzul_`**
     (dapat diubah: `--prefix=nama_`) dan menyimpan sesi login di tabel `fizzul_sessions`.
   * Jika Laravel memakai **SQLite**, aplikasi memakai berkasnya sendiri di `dapur-fizzul/data/fizzul.sqlite`.
2. Menulis `app/Http/Controllers/DapurFizzulController.php`.
3. Menulis `app/Providers/DapurFizzulServiceProvider.php` (mendaftarkan route `/dapur-fizzul/*`).
4. Mendaftarkan provider itu di `bootstrap/providers.php` (Laravel 11+) atau `config/app.php` (Laravel ≤ 10).
5. Menyalin aset ke `public/dapur-fizzul/assets/` dan mengamankan folder `public/dapur-fizzul/data/`.

Opsi lain:

```bash
php dapur-fizzul/laravel/installer.php                 # deteksi otomatis (folder induk)
php dapur-fizzul/laravel/installer.php /path/ke/laravel
php dapur-fizzul/laravel/installer.php --prefix=df_ --force   # ganti prefix / timpa config
php dapur-fizzul/laravel/installer.php --tanpa-provider       # hanya menulis berkas, tanpa daftar provider
```

## 3. Jalankan

```bash
php artisan serve          # atau langsung ke web server Anda
```

Buka **http://127.0.0.1:8000/dapur-fizzul/** → otomatis diarahkan ke `/dapur-fizzul/masuk/`.

Akun awal (ganti sandinya setelah login pertama):

| Peran | Username | Kata sandi |
|---|---|---|
| Owner / Admin | `owner` | `fizzul123` |
| Manager | `manager` | `manager123` |
| Staff Kasir | `kasir` | `kasir123` |
| Staff Dapur / Inventory | `dapur` | `dapur123` |

## 4. Cara kerja integrasi (ringkas)

* Route tunggal `Route::any('/dapur-fizzul/{path?}', ...)` didaftarkan **tanpa middleware grup `web`**
  (tanpa session, CSRF, dan enkripsi cookie milik Laravel) karena aplikasi memakai sistem sesi dan
  token keamanannya sendiri. Karena itu halaman Laravel lain tidak terpengaruh.
* Controller memetakan segmen URL ke titik masuk aplikasi (`masuk/`, `penjualan/`, `stok/`, …),
  lalu menyerahkan permintaan kepada aplikasi.
* URL tanpa garis miring (`/dapur-fizzul/penjualan`) diarahkan (308) ke versi bergaris miring,
  karena seluruh tautan aplikasi bersifat relatif.
* Aset dilayani sebagai berkas statis dari `public/dapur-fizzul/assets/`.
* **Tidak ada nama fungsi yang bertabrakan dengan helper Laravel.** Aplikasi memakai nama khas
  (`now_str()`, `today_str()`, `app_url()`, `app_redirect()`, `app_asset()`, `app_csrf_token()`,
  `app_csrf_field()`), sedangkan `e()` hanya didefinisikan bila belum ada sehingga helper `e()`
  milik Laravel yang dipakai.

## 5. Hal yang perlu diperhatikan

* **Tabel & prefix.** Jika aplikasi berbagi database dengan Laravel, prefix wajib, mis. `fizzul_`.
  Laravel punya tabel `users`, `sessions`, `cache`, `jobs`, dan `migrations`; aplikasi punya
  `fizzul_users`, `fizzul_sessions`, dan seterusnya. Berkas `.sql` dari menu
  **Unduh Database MySQL** memakai prefix yang sama (`fizzul_`).
* **Izin folder.** Pastikan `dapur-fizzul/data/` dapat ditulis (SQLite & penyimpanan sesi berkas):
  `chmod -R 775 dapur-fizzul/data`.
* **Sesi login.** Untuk keandalan, `config.local.php` hasil installer menyetel `SESSION_DRIVER = 'mysql'`
  saat memakai MySQL; tabel `fizzul_sessions` dibuat otomatis oleh aplikasi
  (tidak perlu dibuat manual di phpMyAdmin). Statusnya dapat dilihat di menu
  **Pengaturan → Diagnostik Sesi Login**.
* **Halaman sambutan Laravel.** Halaman bawaan Laravel (`/`) memakai Vite sehingga butuh
  `npm install && npm run build`; ini tidak berkaitan dengan integrasi aplikasi ini.
* **Jangan ubah** `public/dapur-fizzul/assets/` secara manual — salin ulang dari folder aplikasi
  bila aset berubah (jalankan installer lagi, aman/idempotent).

## 6. Memindahkan aplikasi ke hosting lain

* **Berkas database MySQL** (struktur + opsional data demo): menu **Unduh Database MySQL** di dalam aplikasi
  (khusus Owner/Admin) atau langsung `…/dapur-fizzul/mysql-download.php?mode=demo`.
* **Seluruh kode aplikasi**: fitur **Download Source Code** (VibeCoder Pro, `/pro.php`) menghasilkan satu
  berkas zip berisi folder `dapur-fizzul/` — termasuk folder `laravel/` dan panduan ini.

## 7. Melepas integrasi

1. Hapus route provider dari `bootstrap/providers.php` (atau `config/app.php`).
2. Hapus `app/Providers/DapurFizzulServiceProvider.php`, `app/Http/Controllers/DapurFizzulController.php`,
   dan folder `public/dapur-fizzul/`.
3. Hapus tabel aplikasi bila diperlukan: `DROP TABLE fizzul_*;` (perhatikan urutan foreign key,
   atau gunakan `SET FOREIGN_KEY_CHECKS=0;`).
