# Konsep LMS Artha Safety Indonesia

> Dokumen konsep v0.2 — 30 Oktober 2026
> Perubahan dari v0.1: teknologi diganti menjadi **HTML + Google Apps Script**, data di **Google Spreadsheet**, file di **Google Drive**; login pakai **kode peserta**; tanpa status terlambat; absen **wajib di lokasi (GPS)**; tanda tangan PIC **digital**.

---

## 1. Ringkasan

LMS Artha Safety Indonesia adalah aplikasi web berbasis **file HTML** (bisa dibuka di HP maupun laptop) untuk mengelola **satu batch pelatihan** berisi **20–30 peserta** yang diawasi oleh **2 orang PIC**. Seluruh data disimpan di **Google Spreadsheet** dan seluruh file (foto, tanda tangan, materi, dokumentasi, laporan) disimpan di **Google Drive** milik Artha Safety.

| # | Fitur | Dibuat oleh | Dilihat oleh |
|---|-------|-------------|--------------|
| 1 | Absensi 2 sesi/hari (foto + timestamp + tanda tangan + lokasi) | Peserta | Peserta (miliknya), PIC (semua) |
| 2 | Rundown kegiatan | PIC | Peserta & PIC |
| 3 | Dokumentasi kegiatan (foto harian) | PIC | Peserta & PIC |
| 4 | Materi harian (PPT, PDF, lembar latihan) | PIC | Peserta & PIC |
| 5 | Laporan kegiatan otomatis (PDF) | Sistem | PIC (download) |

---

## 2. Arsitektur: HTML + Google Apps Script + Sheets + Drive

### 2.1 Kenapa Google Apps Script?

Aplikasi dibuat sebagai file `index.html` (+ beberapa file HTML/JS/CSS pendukung) yang **di-hosting gratis oleh Google Apps Script** sebagai *Web App*. Apps Script juga berfungsi sebagai "backend" yang membaca/menulis Spreadsheet dan menyimpan file ke Drive.

```
 HP Peserta / Laptop PIC
 (browser: Chrome, dll)
          │  https://script.google.com/macros/s/.../exec
          ▼
 ┌──────────────────────────────┐
 │  Google Apps Script Web App   │
 │  - index.html (tampilan)     │
 │  - Code.gs (logika server)   │
 └──────┬──────────────┬────────┘
        │              │
        ▼              ▼
 Google Spreadsheet   Google Drive
 (data: peserta,      (file: foto absen, tanda tangan,
  absensi, rundown,    materi, dokumentasi, laporan PDF)
  materi, dll)
```

**Kenapa tidak cukup file HTML yang dibuka langsung dari laptop (`file://`)?**

1. **Peserta absen dari HP masing-masing.** File HTML yang hanya ada di laptop PIC tidak bisa dibuka dari HP peserta, jadi aplikasi harus punya alamat (link) yang bisa diakses semua orang.
2. **Kamera dan GPS di browser hanya bisa dipakai lewat HTTPS.** File yang dibuka lokal sering diblokir aksesnya ke kamera dan lokasi, terutama di HP.
3. **Jam harus dari server.** Supaya peserta tidak bisa mengakali timestamp dengan mengubah jam HP.

Apps Script menyelesaikan ketiganya: tetap berupa file HTML, gratis, otomatis HTTPS, dan langsung terhubung ke Sheets & Drive tanpa server sendiri.

> Alternatif bila ingin `index.html` di-hosting di tempat lain (mis. GitHub Pages atau hosting sendiri): file HTML tetap sama, dan Apps Script hanya dipakai sebagai API ke Sheets/Drive. Untuk tahap awal, opsi Apps Script penuh lebih sederhana.

### 2.2 Batasan yang perlu diketahui

| Hal | Batasan | Solusi di desain |
|-----|---------|------------------|
| Ukuran kiriman foto | Upload besar lambat & bisa gagal | Foto dikompres di HP sebelum dikirim (±100–150 KB) |
| Waktu proses per permintaan | Maks. 6 menit per eksekusi | Laporan PDF dibuat **per hari**; laporan batch = gabungan laporan harian |
| Banyak peserta absen bersamaan | Sheets bisa bentrok jika ditulis serentak | Pakai `LockService` agar penulisan antre dengan aman |
| Kuota Google (akun gratis) | Cukup untuk skala 1 batch 30 orang | Disarankan pakai akun Google khusus LMS (mis. `lms.arthasafety@gmail.com`) atau Google Workspace |
| Akses peserta | Peserta tidak perlu punya akun Google | Web App di-*deploy* "Execute as: Me, Access: Anyone"; login lewat kode peserta |

---

## 3. Peran Pengguna & Login

### 3.1 Peran
- **PIC (2 orang per batch)**: membuat batch, input peserta, susun rundown, upload materi & dokumentasi, pantau absensi, generate laporan. Salah satu PIC bisa ditandai sebagai **Admin** untuk membuat batch baru.
- **Peserta (20–30 orang)**: absen, melihat rundown, materi, dokumentasi, dan riwayat absensinya.

### 3.2 Login dengan kode peserta
- Saat PIC menginput peserta (manual atau tempel dari Excel), **LMS otomatis membuat kode unik** untuk tiap peserta, contoh: `ASI-B07-014` atau kode acak 6 karakter `K7P2QX`.
- Halaman login peserta: **pilih nama dari daftar** (dropdown/pencarian) → **masukkan kode**. Nama memudahkan peserta, kode menjamin hanya pemiliknya yang bisa masuk.
- PIC bisa **mencetak/membagikan kartu kode** (daftar nama + kode, atau QR code) di hari pertama.
- PIC login dengan kode PIC + PIN.
- Sesi login disimpan di HP (tidak perlu login ulang setiap absen) sampai batch selesai atau peserta logout.

---

## 4. Struktur Data (Google Spreadsheet)

Satu file Spreadsheet **"LMS Artha Safety - Database"** dengan sheet berikut:

| Sheet | Kolom |
|-------|-------|
| `Batch` | id_batch, nama_pelatihan, kode_batch, lokasi, tgl_mulai, tgl_selesai, sesi1_mulai, sesi1_selesai, sesi2_mulai, sesi2_selesai, lat, lng, radius_meter, folder_drive_id, status |
| `Pengguna` | id_user, id_batch, nama, role (pic/peserta/admin), kode_login, pin (khusus PIC), instansi/jabatan, no_hp, ttd_pic_url, aktif |
| `Hari` | id_hari, id_batch, tanggal, hari_ke |
| `Absensi` | id_absen, id_hari, id_user, sesi (1/2), waktu_server, foto_url, ttd_url, lat, lng, jarak_meter, status (hadir/izin/sakit/alpa), keterangan, diubah_oleh, diubah_pada |
| `Rundown` | id_rundown, id_hari, urutan, jam_mulai, jam_selesai, kegiatan, pemateri, keterangan, status (draft/terbit) |
| `Materi` | id_materi, id_hari, judul, kategori (materi/latihan), file_id_drive, tipe_file, tampil_mulai, diupload_oleh |
| `Dokumentasi` | id_dok, id_hari, id_rundown (opsional), file_id_drive, keterangan, masuk_laporan (ya/tidak), diupload_oleh |
| `Laporan` | id_laporan, id_batch, jenis (harian/lengkap), id_hari, file_id_drive, dibuat_oleh, dibuat_pada |
| `Log` | waktu, id_user, aksi, detail (mis. "PIC ubah status Andi Sesi 1: alpa → sakit") |

Struktur folder di Google Drive:

```
LMS Artha Safety/
└── Batch 07 - K3 Umum (Nov 2026)/
     ├── Absensi/
     │    ├── Hari-1/   (foto & ttd: H1_S1_Andi-Pratama_foto.jpg, ..._ttd.png)
     │    └── Hari-2/
     ├── Materi/
     │    ├── Hari-1/
     │    └── Hari-2/
     ├── Dokumentasi/
     │    ├── Hari-1/
     │    └── Hari-2/
     └── Laporan/
          ├── Laporan Hari-1.pdf
          └── Laporan Lengkap Batch 07.pdf
```

Keuntungannya: PIC dan kantor tetap bisa membuka data langsung dari Spreadsheet/Drive, misalnya untuk rekap manual atau arsip, tanpa membuka LMS.

---

## 5. Fitur Detail

### 5.1 Absensi

| Sesi | Jendela absen |
|------|---------------|
| Sesi 1 — Pagi | 08.00 – 12.00 |
| Sesi 2 — Siang | 13.00 – 17.00 |

(Jam bisa diubah PIC di pengaturan batch.)

**Alur absen peserta:**

```
[Dashboard] → tombol "Absen Sesi 1" (aktif hanya 08.00–12.00)
      │
      ▼
[1. Cek Lokasi]     GPS HP dibaca → dihitung jaraknya ke titik lokasi pelatihan
      │             Di luar radius (mis. > 200 m) → absen ditolak + pesan
      ▼
[2. Foto Selfie]    kamera langsung (bukan dari galeri), dikompres di HP
      │
      ▼
[3. Tanda Tangan]   kanvas tanda tangan di layar
      │
      ▼
[4. Kirim]          server mencatat waktu server (WIB), simpan foto & ttd ke Drive,
                    tulis 1 baris ke sheet Absensi
```

**Watermark timestamp di foto** — tertanam langsung di foto:

```
┌──────────────────────────────┐
│                              │
│        (foto selfie)         │
│                              │
│▓ Andi Pratama | Sesi 1        ▓│
│▓ Kamis, 30 Okt 2026 08:12 WIB ▓│
│▓ -6.2088, 106.8456 (45 m)     ▓│
└──────────────────────────────┘
```

**Aturan:**
- Satu peserta hanya bisa absen **1x per sesi** (dicek di server).
- **Tidak ada status terlambat**: selama absen di dalam jendela sesi, statusnya **Hadir**.
- **Lokasi wajib**: absen hanya diterima jika GPS aktif dan peserta berada dalam radius lokasi pelatihan. Koordinat dan jarak disimpan sebagai bukti.
- Jam absen yang tercatat adalah **jam server Google (WIB)**, bukan jam HP.
- Setelah sesi ditutup, peserta yang belum absen otomatis berstatus **Alpa**. PIC bisa mengubahnya menjadi **Izin / Sakit** + keterangan (tercatat di sheet `Log`).

**Monitor absensi PIC (real-time):**

```
Hari ke-2 — Kamis, 30 Okt 2026         Sesi 1: 24/28 hadir   Sesi 2: belum dibuka
┌────┬──────────────────┬──────────────────┬───────────────┐
│ No │ Nama Peserta     │ Sesi 1           │ Sesi 2        │
├────┼──────────────────┼──────────────────┼───────────────┤
│ 1  │ Andi Pratama     │ ✅ 08.05 [📷 ✍️]  │ —             │
│ 2  │ Budi Santoso     │ ✅ 08.41 [📷 ✍️]  │ —             │
│ 3  │ Citra Lestari    │ ❌ belum          │ —             │
└────┴──────────────────┴──────────────────┴───────────────┘
```

### 5.2 Rundown
- PIC menyusun rundown per hari: jam mulai, jam selesai, kegiatan, pemateri, dan keterangan.
- Bisa **salin dari hari sebelumnya**.
- Status **draft / terbit**: rundown yang sudah diterbitkan langsung tampil di akun semua peserta.
- Di dashboard peserta, agenda yang sedang berjalan disorot.

### 5.3 Dokumentasi Kegiatan
- PIC upload banyak foto sekaligus per hari (opsional dikaitkan ke agenda rundown) dan bisa menambahkan keterangan.
- Foto dikompres sebelum upload (±1600 px).
- Peserta melihat foto dalam bentuk galeri per hari.
- PIC mencentang foto mana yang **masuk laporan**.

### 5.4 Materi Harian
- PIC upload PPT/PDF/DOC/XLS atau link per hari, dengan kategori **Materi** atau **Lembar Latihan**.
- File disimpan di Drive dan dibagikan "siapa saja yang punya link: dapat melihat". Peserta bisa melihat preview (Google Drive viewer, termasuk PPT) dan download.
- Opsional: materi baru tampil mulai jam tertentu.

### 5.5 Laporan Kegiatan Otomatis (PDF)

PIC memilih **Laporan Harian** atau **Laporan Lengkap Batch**, lalu klik **Buat PDF**. File tersimpan di folder `Laporan/` di Drive dan langsung bisa diunduh.

Isi laporan:

```
1. Sampul        : logo, judul pelatihan, batch, tanggal, lokasi, nama PIC
2. Ringkasan     : jumlah peserta, jumlah hari, rekap % kehadiran per sesi
3. Per hari      :
   a. Rundown kegiatan (tabel)
   b. Rekap absensi (No | Nama | Sesi 1 jam & status | Sesi 2 jam & status)
   c. Lampiran absensi: grid foto ber-timestamp + tanda tangan tiap peserta
      (±9–12 peserta per halaman per sesi)
   d. Dokumentasi kegiatan (foto terpilih + keterangan)
   e. Daftar materi
4. Pengesahan    : tanda tangan digital PIC 1 & PIC 2 (dibuat sekali di profil PIC)
```

Cara pembuatan: Apps Script menyusun laporan dari template HTML lalu mengubahnya menjadi PDF (`HtmlService` → `getAs('application/pdf')`). Foto di dalam laporan memakai versi kecil supaya ukuran PDF tetap ringan. Format laporan resmi Artha Safety bisa ditambahkan nanti.

---

## 6. Peta Halaman

**Peserta**
```
Login (pilih nama + kode)
└── Dashboard hari ini
     ├── Kartu Absen Sesi 1 / Sesi 2
     ├── Rundown hari ini
     ├── Materi hari ini
     └── Dokumentasi terbaru
├── Rundown (semua hari)
├── Materi (semua hari)
├── Dokumentasi (galeri)
└── Riwayat Absensi Saya
```

**PIC**
```
Login (kode PIC + PIN)
└── Dashboard Batch (kehadiran real-time)
├── Peserta (input/tempel dari Excel → kode otomatis, cetak kartu kode/QR)
├── Absensi (monitor, lihat foto & ttd, ubah izin/sakit)
├── Rundown (susun, salin, draft/terbit)
├── Materi (upload per hari)
├── Dokumentasi (upload, pilih untuk laporan)
├── Laporan (harian / lengkap → PDF)
└── Pengaturan Batch (jam sesi, titik lokasi & radius, tanda tangan PIC)
```

---

## 7. Struktur File Project

```
LMS/
├── docs/KONSEP.md
└── apps-script/
     ├── appsscript.json      konfigurasi (timezone Asia/Jakarta, akses web app)
     ├── Code.gs              routing doGet, fungsi API umum
     ├── Auth.gs              login kode peserta / PIC
     ├── Absensi.gs           absen, validasi jendela sesi & radius, auto-alpa
     ├── Konten.gs            rundown, materi, dokumentasi
     ├── Laporan.gs           pembuatan PDF
     ├── Setup.gs             buat Spreadsheet & folder Drive otomatis (sekali jalan)
     ├── index.html           kerangka aplikasi (single page)
     ├── css.html             gaya tampilan (mobile-first)
     ├── js-peserta.html      halaman peserta (kamera, GPS, tanda tangan)
     ├── js-pic.html          halaman PIC
     └── laporan-template.html template PDF
```

Kode dikelola di GitHub, lalu di-*upload* ke Apps Script (copy-paste atau pakai `clasp`).

---

## 8. Keamanan & Privasi

- Peserta hanya melihat data batch-nya dan absensi miliknya sendiri. Semua pengecekan dilakukan di server (Apps Script), bukan hanya di tampilan.
- Spreadsheet & folder Drive **tidak dibagikan publik**. Hanya materi & dokumentasi yang dibagikan via link view-only, sedangkan foto absen dan tanda tangan tetap privat.
- Kode login bisa di-*reset* PIC bila bocor.
- Saat login pertama, peserta menyetujui penggunaan foto wajah, tanda tangan, dan lokasi untuk keperluan absensi pelatihan (UU PDP).
- Semua perubahan status absensi oleh PIC tercatat di sheet `Log`.

---

## 9. Tahapan Pengembangan

| Tahap | Isi |
|-------|-----|
| **1 — Fondasi & Absensi** | Setup otomatis Sheets/Drive, batch & input peserta + kode otomatis, login, absensi (GPS + foto + watermark + ttd), monitor PIC, auto-alpa |
| **2 — Konten** | Rundown, materi, dokumentasi + galeri |
| **3 — Laporan** | PDF harian & lengkap, tanda tangan digital PIC |
| **4 — Uji coba** | Uji di 1 batch nyata, perbaikan, panduan singkat untuk PIC |

---

## 10. Keputusan yang Sudah Disepakati

| Topik | Keputusan |
|-------|-----------|
| Teknologi | File HTML + Google Apps Script (web app) |
| Penyimpanan | Google Spreadsheet (data) + Google Drive (file) |
| Login peserta | Nama + kode yang dibuat otomatis oleh LMS saat PIC input peserta |
| Status terlambat | Tidak dipakai, cukup Hadir / Izin / Sakit / Alpa |
| Lokasi | Absen wajib dalam radius lokasi pelatihan (GPS) |
| Format laporan | Pakai format dari LMS dulu, format resmi menyusul |
| Tanda tangan PIC | Digital |

## 11. Masih Perlu Dikonfirmasi

1. Akun Google yang akan jadi "pemilik" LMS (disarankan akun khusus, bukan akun pribadi).
2. Radius lokasi yang diinginkan (saran 100–200 m). Apakah lokasi pelatihan selalu sama atau berbeda tiap batch?
3. Format kode peserta: berpola (`ASI-B07-014`) atau acak (`K7P2QX`)?
4. Apakah satu Spreadsheet dipakai untuk semua batch, atau satu Spreadsheet per batch?
