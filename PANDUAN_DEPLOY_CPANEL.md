# Panduan Lengkap Deploy ke cPanel
## Domain: `dashboard.medilineindonesia.com`

File arsip ZIP project ini sudah disiapkan khusus dan **siap di-upload langsung ke cPanel** tanpa perlu repot menjalankan composer di server.

---

### Isi Paket Deploy
1. **Source Code & Vendor Lengkap**: Sudah termasuk folder `vendor/` lengkap, seluruh assets Filament (`public/css/filament` dan `public/js/filament`), serta gambar storage.
2. **File `.htaccess` Ganda**:
   - `.htaccess` di root: Melindungi file sensitif (`.env`, `storage/`, dll.) dan otomatis meneruskan request ke folder `public/`.
   - `.htaccess` di `public/`: Menangani routing Laravel dan Filament.
3. **Database Siap Pakai (`database_mediline_production.sql`)**: Berisi skema tabel terbaru beserta user login Admin.
4. **Setup Helper Otomatis (`public/cpanel-setup.php`)**: Membantu migrasi, symlink storage, dan optimasi cache langsung dari browser tanpa perlu akses terminal/SSH.

---

### Langkah 1: Buat Subdomain di cPanel
1. Masuk ke **cPanel** hosting Anda.
2. Cari menu **Domains** atau **Subdomains**.
3. Buat subdomain baru:
   - **Domain / Subdomain**: `dashboard.medilineindonesia.com`
   - **Document Root**:
     - *Rekomendasi Terbaik*: `dashboard.medilineindonesia.com/public` (atau `public_html/dashboard/public`)
     - *Opsi Fleksibel*: Jika cPanel Anda tidak mengizinkan menambahkan `/public` pada document root, arahkan saja ke `dashboard.medilineindonesia.com`. Sistem `.htaccess` yang kami pasang di root akan secara otomatis meneruskannya ke `/public/`.

---

### Langkah 2: Buat Database & Import SQL
1. Di cPanel, buka menu **MySQL® Databases**.
2. Buat database baru, contoh: `username_mediline`.
3. Buat user database baru dan password yang kuat, contoh: `username_medilineuser`.
4. Tambahkan user tersebut ke database dengan mencentang **ALL PRIVILEGES**.
5. Buka **phpMyAdmin** dari cPanel.
6. Pilih database yang baru Anda buat, klik tab **Import**.
7. Pilih file `database_mediline_production.sql` (tersedia di root project atau di folder `database/`), lalu klik **Import / Go**.

---

### Langkah 3: Upload & Ekstrak Zip di File Manager
1. Buka **File Manager** di cPanel.
2. Masuk ke folder document root subdomain Anda (misalnya `/public_html/dashboard/` atau `/dashboard.medilineindonesia.com/`).
3. Klik tombol **Upload** di bagian atas, lalu pilih file `mediline-dashboard-cpanel.zip`.
4. Setelah selesai (progress bar hijau 100%), kembali ke File Manager dan klik kanan file zip lalu pilih **Extract**.
5. Pastikan seluruh file dan folder berada langsung di dalam direktori subdomain (bukan bersarang di dalam subfolder ekstraksi).

---

### Langkah 4: Sesuaikan File `.env`
1. Di File Manager cPanel, pastikan opsi **Show Hidden Files (dotfiles)** aktif di menu *Settings* (pojok kanan atas File Manager).
2. Cari file `.env` (jika tidak terlihat, Anda bisa rename file `.env.production` menjadi `.env`).
3. Klik kanan file `.env` lalu pilih **Edit**.
4. Perbarui konfigurasi database sesuai data di Langkah 2:
   ```env
   APP_NAME="Global Mediline"
   APP_ENV=production
   APP_KEY=base64:r7MfiUhmOCcaKyExmWkFKmFHYRaJAxzpBqt0JAoevvY=
   APP_DEBUG=false
   APP_URL=https://dashboard.medilineindonesia.com

   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=username_mediline
   DB_USERNAME=username_medilineuser
   DB_PASSWORD=password_database_anda

   SESSION_DRIVER=file
   CACHE_STORE=file
   FILESYSTEM_DISK=public
   ```
5. Simpan perubahan (**Save Changes**).

---

### Langkah 5: Jalankan Setup Helper via Browser (1-Click)
Buka browser dan akses URL berikut:
```
https://dashboard.medilineindonesia.com/cpanel-setup.php?key=mediline2026
```

Pada halaman tersebut, Anda cukup klik:
1. **1. Test Koneksi DB**: Memastikan koneksi ke database berhasil.
2. **4. Buat Storage Link**: Menghubungkan storage file gambar dan upload ke folder public.
3. **5. Optimasi Cache (Optimize)**: Mengkompilasi konfigurasi dan route agar loading sangat cepat.
4. **Hapus File cpanel-setup.php**: Setelah selesai, klik tombol merah untuk menghapus file helper ini demi keamanan server.

---

### Langkah 6: Login ke Dashboard Admin
Buka website Anda:
👉 **`https://dashboard.medilineindonesia.com`** (otomatis redirect ke `/admin`)

**Kredensial Default:**
- **Email**: `admingmi@gmail.com`
- **Password**: `Gmi_2026`
*(User alternatif: `gbi@gmail.com`)*

---

### Troubleshooting / Tips
- **Error 500 / Permintaan Izin Folder**:
  Pastikan folder `storage/` dan `bootstrap/cache/` memiliki permission **775** atau **755** di cPanel File Manager.
- **Versi PHP**:
  Pastikan versi PHP di cPanel (menu **MultiPHP Manager** atau **Select PHP Version**) disetel ke **PHP 8.2** atau **PHP 8.3 / 8.4** dengan ekstensi aktif: `pdo_mysql`, `mbstring`, `fileinfo`, `gd`, `zip`, `openssl`, `intl`.

