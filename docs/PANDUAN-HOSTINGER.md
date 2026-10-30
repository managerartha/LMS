# Panduan Pemasangan LMS di Hostinger (Cloud)

Panduan ini untuk pemula, langkah demi langkah. Perkiraan waktu: **30–45 menit**, cukup sekali.

Hasil akhirnya: LMS berjalan di **https://lms.arthasafetyindonesia.com**, dengan data di database MySQL Hostinger dan file (foto, materi, laporan) di hosting Anda sendiri. Tidak memakai Google Apps Script atau Google Drive lagi.

---

## Yang perlu disiapkan

- [ ] Akun Hostinger dengan paket **Cloud** yang aktif, bisa login ke **hPanel** (https://hpanel.hostinger.com)
- [ ] File **`lms-hostinger.zip`** (dari Claude). **Jangan di-extract di komputer**, cukup simpan saja
- [ ] Akses ke pengaturan **DNS domain** arthasafetyindonesia.com (lihat Langkah 1)
- [ ] Catatan kosong untuk menyimpan nama database, username, dan password

---

## Langkah 1: Pastikan di mana domain Anda diatur

Subdomain `lms.arthasafetyindonesia.com` perlu diarahkan ke Hostinger. Caranya tergantung di mana **DNS** domain Anda diatur:

1. Buka hPanel → menu atas **Domains**.
2. Lihat apakah **arthasafetyindonesia.com** ada di daftar dan statusnya aktif.
   - **Ada di Hostinger** → ikuti **Langkah 2A** (paling mudah).
   - **Tidak ada** (domain dibeli/diatur di tempat lain, mis. Niagahoster, Rumahweb, Domainesia, Cloudflare, atau diatur oleh Copilot Spy) → ikuti **Langkah 2B**.

> Tidak yakin? Tanyakan ke orang yang dulu membeli domain atau mengatur website **arthasafetyindonesia.com**: "DNS domain kita diatur di mana?"

---

## Langkah 2A: Buat subdomain (domain sudah di Hostinger)

1. hPanel → **Websites** → pada **arthasafetyindonesia.com** klik **Manage** (Kelola).
2. Menu kiri: **Domains** → **Subdomains**.
3. Di kolom **Create a new subdomain**, ketik: `lms`
4. Biarkan centang folder default, lalu klik **Create**.
5. Di daftar subdomain akan muncul `lms.arthasafetyindonesia.com` beserta **foldernya**, biasanya `public_html/lms`. **Catat folder ini.**

Lanjut ke **Langkah 3**.

## Langkah 2B: Tambah website subdomain (domain diatur di tempat lain)

1. hPanel → **Websites** → **Add website** (Tambah website) → pilih **Empty PHP/HTML website** (atau "Skip, I'll create an empty website").
2. Saat diminta domain, pilih **Use an existing domain**, lalu ketik: `lms.arthasafetyindonesia.com`
3. Selesaikan sampai website muncul di daftar **Websites**.
4. Klik **Manage** pada website itu. Di **Dashboard** atau **Website details**, cari **IP address** (mis. `153.92.x.x`). **Catat IP ini.**
5. Buka pengaturan DNS domain di tempat domain diatur, lalu tambahkan record:

   | Type | Name / Host | Value / Points to | TTL |
   |---|---|---|---|
   | **A** | `lms` | IP dari langkah 4 | 3600 (atau default) |

6. Simpan. Perubahan DNS butuh **5 menit sampai beberapa jam**. Sambil menunggu, lanjutkan langkah berikutnya.

> Record ini **hanya untuk `lms`**, jadi website utama dan halaman Copilot Spy di arthasafetyindonesia.com **tidak terganggu**.

---

## Langkah 3: Atur versi PHP

1. hPanel → **Websites** → **Manage** (website / subdomain LMS).
2. Menu kiri: **Advanced** → **PHP Configuration**.
3. Tab **PHP version**: pilih **8.2** atau **8.3**, lalu **Update/Save**.
4. Tab **PHP options** (jika ada), ubah:
   - `upload_max_filesize` → **64M**
   - `post_max_size` → **64M**
   - `memory_limit` → **1024M** (atau nilai terbesar yang tersedia)
   - `max_execution_time` → **600**

   Klik **Save**. (Paket LMS juga berisi file `.user.ini` dengan nilai yang sama, jadi langkah ini cadangan.)

---

## Langkah 4: Buat database MySQL

1. Menu kiri: **Databases** → **Management** (Manajemen Database).
2. Di bagian **Create a New MySQL Database and Database User**, isi:
   - **MySQL database name**: `lms` → hasil akhirnya mis. **`u123456789_lms`**
   - **MySQL username**: `lmsadmin` → hasil akhirnya mis. **`u123456789_lmsadmin`**
   - **Password**: buat password kuat (huruf besar, huruf kecil, angka, simbol). Bisa klik **Generate**.
3. Klik **Create**.
4. **Catat** ketiganya **lengkap dengan awalan `u123456789_`**, seperti yang tampil di daftar **List of Current MySQL Databases and Users**:
   - Nama database: `u123456789_lms`
   - Username: `u123456789_lmsadmin`
   - Password: (yang tadi dibuat)

---

## Langkah 5: Upload file LMS

1. Menu kiri: **Files** → **File Manager**.
2. Buka folder subdomain LMS:
   - Langkah 2A → `public_html/lms` (folder yang dicatat di Langkah 2A)
   - Langkah 2B → `public_html` milik website `lms.arthasafetyindonesia.com`
3. Jika di folder itu ada file bawaan seperti `default.php` atau `index.html` lama, **hapus** dulu (klik kanan → Delete).
4. Klik ikon **Upload** (panah ke atas) → **File** → pilih **`lms-hostinger.zip`**. Tunggu sampai 100%.
5. Klik kanan **`lms-hostinger.zip`** → **Extract** → pastikan tujuan ekstrak adalah **folder yang sedang dibuka** (`.` atau nama folder subdomain, **bukan** folder baru), lalu klik **Extract**.
6. Setelah selesai, isi folder harus seperti ini:

   ```
   api/   app/   css/   img/   js/   storage/   uploads/   vendor/
   .htaccess   .user.ini   composer.json   index.html   install.php
   ```

   > `.htaccess` dan `.user.ini` adalah file tersembunyi. Jika tidak terlihat, klik ikon ⚙️ **Settings** di File Manager → centang **Show hidden files**.

7. Hapus **`lms-hostinger.zip`** (klik kanan → Delete) agar tidak memenuhi penyimpanan.

> **Upload lewat "Buat website" / "Upload file website" Hostinger?** Boleh. Abaikan pesan *"Sertakan juga file database Anda"*, karena LMS tidak memakai file `.sql`; tabel dibuat oleh `install.php`. Setelah website jadi, cek isi `public_html` seperti daftar di atas.
>
> **Kesalahan yang sering terjadi:** hasil extract masuk ke folder baru `lms-hostinger/`. Jika itu terjadi, buka folder tersebut, pilih semua isinya (Select All), klik **Move**, lalu pindahkan ke folder subdomain.

---

## Langkah 6: Aktifkan SSL (HTTPS)

**Wajib**, karena tanpa HTTPS browser HP memblokir kamera dan GPS.

1. Menu kiri: **Security** → **SSL**.
2. Jika `lms.arthasafetyindonesia.com` belum berstatus **Active**, klik **Install SSL** (gratis) dan pilih subdomain tersebut.
3. Tunggu sampai status **Active** (beberapa menit). Untuk Langkah 2B, SSL baru bisa aktif setelah DNS dari Langkah 2B tersambung.

Cek: buka **https://lms.arthasafetyindonesia.com** di browser. Harus ada ikon gembok.

---

## Langkah 7: Jalankan installer

1. Buka **https://lms.arthasafetyindonesia.com/install.php**
2. Bagian **1. Kesiapan hosting**: semua harus ✅.
   - ❌ **PHP versi** → ulangi Langkah 3.
   - ❌ **Folder … bisa ditulis** → File Manager → klik kanan folder `storage` / `uploads` / `app` → **Permissions** → `755` → Save.
   - ❌ **vendor** → ulangi Langkah 5 (extract belum lengkap).
3. Bagian **2. Data database MySQL**, isi dari catatan Langkah 4:
   - Host database: `localhost`
   - Nama database: `u123456789_lms`
   - Username database: `u123456789_lmsadmin`
   - Password database: (password Langkah 4)
4. Klik **Pasang LMS**. Jika berhasil muncul **✅ Pemasangan berhasil**.
5. **Wajib:** kembali ke File Manager, lalu **hapus `install.php`** (klik kanan → Delete).

---

## Langkah 8: Cek pemasangan

Buka **https://lms.arthasafetyindonesia.com/api/?cek=1**. Harus tampil antara lain:

```
"ok": true,
"database": "ok",
"folder_storage_bisa_ditulis": true,
"folder_uploads_bisa_ditulis": true,
"pembuat_pdf": "ok"
```

Lalu cek bahwa data privat tidak bisa dibuka dari luar. Buka **https://lms.arthasafetyindonesia.com/app/config.php**: harus muncul **403 Forbidden**. Jika isinya terlihat, berarti `.htaccess` belum ter-upload; ulangi Langkah 5.

---

## Langkah 9: Login pertama & siapkan LMS

1. Buka **https://lms.arthasafetyindonesia.com** → tab **PIC / Admin**.
2. Login: Kode **`ADMIN`**, PIN **`123456`**.
3. **Segera ganti PIN**: tombol **PIN** di kanan atas.
4. Lanjutkan seperti biasa (cara pakai sama dengan sebelumnya, lihat [PANDUAN-SETUP.md](PANDUAN-SETUP.md) Langkah 10–13):
   - **Akun PIC**: tambah 2 PIC.
   - **Batch**: buat batch (hari pelatihan dibuat otomatis).
   - **Jadwal & Lokasi**: lokasi default (📍 Pakai lokasi saya sekarang) dan lokasi per hari.
   - **Peserta**: tempel daftar nama dari Excel, lalu cetak kartu kode.
   - **Laporan**: isi **Data perusahaan & penandatangan** (Admin, sekali) serta upload tanda tangan + cap.
5. **Uji coba dari HP**: buat batch percobaan dengan tanggal hari ini, lalu absen dengan satu akun peserta. Izinkan **Lokasi** dan **Kamera** saat diminta.

---

## Backup data

Data (database + foto) sekarang ada di hosting Anda sendiri, jadi pastikan ada cadangannya:

- hPanel → **Files** → **Backups**: paket Cloud punya **backup harian otomatis**. Dari sini Anda bisa mengunduh atau memulihkan **Files** dan **Database**.
- Saran: setiap batch selesai, unduh **Laporan Resmi** (PDF) dan simpan juga di Google Drive kantor sebagai arsip.

---

## Memperbarui LMS (versi berikutnya)

Saat Claude memberi file pembaruan:

1. File Manager → folder LMS → **Upload** ZIP pembaruan → klik kanan → **Extract** ke folder yang sama → setujui **Replace/Overwrite** jika ditanya.
2. **Jangan** menghapus folder `storage/`, `uploads/`, atau file `app/config.php`, karena di situlah data dan koneksi database Anda.
3. Buka `/api/?cek=1` untuk memastikan semuanya `ok`.

Tabel atau kolom database baru (mis. untuk fitur SKL) ditambahkan **otomatis** saat LMS pertama kali dibuka setelah pembaruan. Data lama tidak diubah dan tidak perlu menjalankan installer lagi.

---

## Masalah yang sering terjadi

| Masalah | Solusi |
|---|---|
| Situs tidak bisa dibuka / "DNS_PROBE…" | DNS belum tersambung (Langkah 2B). Tunggu hingga beberapa jam, lalu periksa kembali A record `lms`. |
| "Not secure" / kamera & GPS tidak jalan | SSL belum aktif (Langkah 6). |
| install.php: "Username atau password database salah" | Pakai nama **lengkap** dengan awalan `u123456789_`. Jika lupa password: Databases → Management → ⋮ pada user → **Change password**. |
| install.php: "Nama database tidak ditemukan" | Salin nama database persis dari daftar di Databases → Management. |
| Halaman login menampilkan "Server LMS membalas tidak sesuai (status 403)" | Pakai ZIP versi terbaru, atau di File Manager edit `.htaccess` dan ubah baris `DirectoryIndex index.html` menjadi `DirectoryIndex index.html index.php`. |
| Halaman kosong / error 500 | Advanced → PHP Configuration → pastikan PHP 8.2/8.3. Lalu buka `/api/?cek=1` dan kirim hasilnya ke Claude. |
| "Server LMS membalas tidak sesuai" | Buka `/api/?cek=1`. Jika ada yang bukan `ok`/`true`, perbaiki sesuai keterangannya. |
| Upload materi gagal (file besar) | Langkah 3 bagian PHP options (`upload_max_filesize` & `post_max_size` = 64M). |
| Alamat lokasi absen kosong di laporan | Layanan peta gratis (OpenStreetMap) sedang sibuk. Alamat dicoba lagi otomatis saat laporan dibuat; jika tetap gagal, koordinat yang ditampilkan. |
| Lupa PIN Admin | Hubungi Claude. Reset bisa dilakukan lewat phpMyAdmin (hPanel → Databases → phpMyAdmin). |

---

## Di mana data tersimpan?

| Data | Lokasi |
|---|---|
| Batch, peserta, absensi, rundown, materi, log | Database MySQL `u123456789_lms` (bisa dilihat di hPanel → Databases → **phpMyAdmin**) |
| Foto absen, tanda tangan, laporan PDF | Folder `storage/` (privat, tidak bisa dibuka dari browser) |
| File materi & foto dokumentasi | Folder `uploads/` (bisa dibuka lewat LMS; nama file acak) |
| Koneksi database | `app/config.php` (privat, **jangan dibagikan**) |
