# Konsep LMS Artha Safety Indonesia

> Dokumen konsep v0.5 — 30 Oktober 2026
> **Arsitektur terbaru: Hostinger (PHP + MySQL)**, lihat bagian 12 dan [PANDUAN-HOSTINGER.md](PANDUAN-HOSTINGER.md). Bagian 2 & 4 di bawah menjelaskan versi Apps Script sebelumnya.
> Status: **Tahap 1, 2 & 3 sudah dibuat** (folder `apps-script/`). Cara pasang: [PANDUAN-SETUP.md](PANDUAN-SETUP.md).
> Perubahan v0.3: format kode login `001-Kikis` (nomor absen + nama depan), lokasi absen bisa diubah **per hari** oleh PIC/Admin, **satu Spreadsheet untuk semua batch**.
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
- Saat PIC menginput peserta (manual atau tempel dari Excel), **LMS otomatis membuat kode** dengan format **nomor absen 3 digit + nama depan**:

  | No absen | Nama lengkap | Kode login |
  |---------:|--------------|------------|
  | 1 | Kikis Ramadhan | `001-Kikis` |
  | 2 | Andi Pratama | `002-Andi` |
  | 3 | Andi Saputra | `003-Andi` |

  Nomor absen membuat kode tetap unik walaupun ada nama depan yang sama. Huruf besar/kecil tidak dibedakan saat login (`001-kikis` = `001-Kikis`).
- Kode unik **di dalam satu batch**. Batch lain boleh punya `001-...` sendiri karena setiap peserta juga terikat ke batch-nya.
- Halaman login peserta: **pilih batch** (otomatis terpilih jika hanya ada satu batch aktif) → **ketik kode**.
- PIC bisa **mencetak/membagikan kartu kode** (daftar nama + kode, atau QR code) di hari pertama.
- **Pengaman tambahan.** Kode berpola ini mudah ditebak teman sekelas, jadi ada **ikat perangkat**: login pertama mengikat akun ke HP itu. Login dari HP lain perlu persetujuan PIC (tombol "Reset perangkat"). Ditambah foto selfie dan GPS saat absen, titip absen jadi sulit dilakukan.
- PIC login dengan kode PIC + PIN.
- Sesi login disimpan di HP (tidak perlu login ulang setiap absen) sampai batch selesai atau peserta logout.

---

## 4. Struktur Data (Google Spreadsheet)

**Satu file Spreadsheet untuk semua batch**: **"LMS Artha Safety - Database"**. Setiap baris data punya `id_batch`, sehingga data antar-batch terpisah tapi tetap di satu tempat dan mudah direkap lintas batch. Sheet-nya:

| Sheet | Kolom |
|-------|-------|
| `Batch` | id_batch, nama_pelatihan, kode_batch, lokasi_nama (default), tgl_mulai, tgl_selesai, sesi1_mulai, sesi1_selesai, sesi2_mulai, sesi2_selesai, lat, lng, radius_meter (default), lewati_weekend, pic_ids (2 PIC), folder_id, status (aktif/selesai), dibuat_pada |
| `Pengguna` | id_user, id_batch (khusus peserta), no_absen, nama, role (pic/peserta/admin), kode_login (mis. `001-Kikis` / `PIC-Dina`), pin_hash (khusus PIC/Admin), instansi, no_hp, device_id, token, setuju_privasi, ttd_id (tanda tangan PIC), aktif, dibuat_pada |
| `Hari` | id_hari, id_batch, tanggal, hari_ke, lokasi_nama, lat, lng, radius_meter (kosong = pakai default batch), diubah_oleh |
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
- **Lokasi wajib**: absen hanya diterima jika GPS aktif dan peserta berada dalam radius lokasi pelatihan **hari itu**. Koordinat dan jarak disimpan sebagai bukti.
- **Lokasi per hari**: batch punya lokasi default. Jika hari tertentu pindah tempat (mis. praktik lapangan), PIC/Admin mengubah lokasi khusus hari itu (lihat 5.6).
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

### 5.6 Pengaturan Lokasi per Hari (PIC / Admin)

```
Pengaturan Batch → Lokasi
┌───────────────────────────────────────────────────────────────┐
│ Lokasi default: Kantor ASI, Jakarta (radius 150 m)    [Ubah]  │
├────────┬──────────────┬───────────────────────────┬───────────┤
│ Hari   │ Tanggal      │ Lokasi                    │           │
├────────┼──────────────┼───────────────────────────┼───────────┤
│ Hari 1 │ Sen 3 Nov    │ (default)                 │ [Ubah]    │
│ Hari 2 │ Sel 4 Nov    │ (default)                 │ [Ubah]    │
│ Hari 3 │ Rab 5 Nov    │ 📍 Site PT XYZ, Cikarang  │ [Ubah] [↺]│
└────────┴──────────────┴───────────────────────────┴───────────┘
```

Cara mengatur titik lokasi (pilih salah satu):
1. **"Pakai lokasi saya sekarang"**: PIC yang sudah berada di tempat pelatihan menekan tombol ini, lalu koordinat diambil dari GPS HP PIC. Cara ini paling mudah dan akurat.
2. **Tempel link Google Maps** atau koordinat (mis. `-6.2088, 106.8456`).

Setiap lokasi bisa diberi **nama** dan **radius** (saran 100–200 m; untuk area pabrik/site yang luas bisa lebih besar). Tombol **↺** mengembalikan hari itu ke lokasi default. Perubahan lokasi tercatat di sheet `Log`. Nama lokasi per hari juga ikut tampil di laporan PDF.

---

## 6. Peta Halaman

**Peserta**
```
Login (pilih batch + kode, mis. 001-Kikis)
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
└── Pengaturan Batch (jam sesi, lokasi default & lokasi per hari, tanda tangan PIC, reset perangkat peserta)
```

---

## 7. Struktur File Project

```
LMS/
├── docs/                    KONSEP.md, PANDUAN-SETUP.md
├── web/                     tampilan — upload ke cPanel (lms.arthasafetyindonesia.com)
│    ├── index.html           kerangka aplikasi (single page)
│    ├── .htaccess            paksa HTTPS, cache, izin kamera & GPS
│    ├── css/app.css          gaya tampilan (mobile-first, identitas Artha Safety)
│    ├── js/config.js         alamat API (URL /exec Apps Script)
│    ├── js/common.js         panggilan API, login, GPS, waktu WIB
│    ├── js/peserta.js        dashboard peserta, absen (kamera, watermark, ttd)
│    ├── js/pic.js            halaman PIC & Admin
│    └── img/                 logo.png, ikon.png, foto.webp
└── apps-script/             API & data — salin ke script.google.com
     ├── appsscript.json      zona waktu, izin, akses web app
     ├── Code.gs              doPost/doGet, pintu api(), helper Spreadsheet & Drive
     ├── Setup.gs             buat Spreadsheet & folder Drive otomatis
     ├── Auth.gs              login peserta (kode + ikat HP) / PIC (kode + PIN)
     ├── Batch.gs             batch, hari, lokasi default & per hari, peserta
     ├── Absensi.gs           absen, validasi jam & radius, auto-alpa, monitor
     ├── Konten.gs            rundown, materi, dokumentasi
     ├── Laporan.gs           laporan PDF, tanda tangan digital PIC
     └── aset-logo.html       logo untuk sampul laporan PDF (data URI)
```

Kode dikelola di GitHub. Folder `web/` di-upload ke cPanel, folder `apps-script/` ditempel ke Apps Script (atau pakai `clasp`).

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
| **1 — Fondasi & Absensi** ✅ | Setup otomatis Sheets/Drive, batch & input peserta + kode otomatis (`001-Kikis`), lokasi default & per hari, login, absensi (GPS + foto + watermark + ttd), monitor PIC, auto-alpa |
| **2 — Konten** ✅ | Rundown (draft/terbit, salin antar-hari), materi (file/link, jam tampil), dokumentasi (upload banyak foto, tandai untuk laporan) + tampilan peserta |
| **3 — Laporan** ✅ | PDF harian & lengkap, tanda tangan digital PIC |
| **4 — Uji coba** | Uji di 1 batch nyata, perbaikan, panduan singkat untuk PIC |

---

## 10. Keputusan yang Sudah Disepakati

| Topik | Keputusan |
|-------|-----------|
| Teknologi | File HTML + Google Apps Script (web app) |
| Penyimpanan | **Satu** Google Spreadsheet untuk semua batch (data) + Google Drive (file, folder per batch) |
| Login peserta | Kode otomatis `nomor absen-nama depan` (mis. `001-Kikis`) + ikat perangkat |
| Status terlambat | Tidak dipakai, cukup Hadir / Izin / Sakit / Alpa |
| Lokasi | Absen wajib dalam radius (GPS); lokasi default per batch, bisa diubah per hari oleh PIC/Admin |
| Format laporan | Pakai format dari LMS dulu, format resmi menyusul |
| Tanda tangan PIC | Digital |

| Akun Google | **manag3r.artha@gmail.com** (pemilik Spreadsheet, folder Drive, dan web app) |

## 11. Catatan Implementasi Tahap 1

- **Status Alpa dihitung otomatis**: peserta tanpa catatan absen di sesi yang sudah ditutup tampil sebagai Alpa. Tidak perlu trigger terjadwal.
- **PIC bersifat global**: satu akun PIC bisa ditugaskan ke beberapa batch (kolom `pic_ids` di sheet Batch).
- **Watermark** digambar di HP memakai jam server (selisih jam HP dikoreksi otomatis). Jam resmi yang tercatat di Spreadsheet tetap jam server saat data diterima.
- **Kamera**: LMS mencoba membuka kamera langsung di halaman. Jika browser HP tidak mengizinkannya, LMS membuka aplikasi kamera bawaan HP. Foto lama dari galeri (lebih dari 5 menit) ditolak.
- **Keterbatasan GPS**: aplikasi "fake GPS" di HP Android tidak bisa dideteksi dari browser. Pencegahnya adalah foto selfie ber-watermark yang diperiksa PIC.
- **Laporan PDF** disusun di server sebagai HTML (tata letak tabel, CSS dasar) lalu dikonversi ke PDF oleh Google. Foto absen memakai thumbnail Drive, dan foto dokumentasi memakai versi 800 px yang dibuat saat upload, supaya ukuran PDF tetap wajar. Laporan lengkap untuk batch besar bisa mendekati batas 6 menit Apps Script; alternatifnya laporan harian.
- **Identitas visual**: warna mengikuti logo Artha Safety (navy `#1B1E4F`, hijau `#22EDA4`) dengan font Plus Jakarta Sans. Halaman login menampilkan logo, foto Safety Champions, dan tagline. Dashboard peserta memakai menu samping (desktop) atau menu geser (HP), kartu sambutan bergradasi biru berisi tombol absen dan kegiatan yang sedang berlangsung, serta kartu Program, Kehadiran, dan Progres pelatihan.
- **Kecepatan**: halaman pertama dikirim bersama data login (`me` membawa dashboard peserta / daftar batch PIC). Dashboard peserta langsung tampil dari data terakhir di perangkat lalu diperbarui di belakang. Daftar hari PIC disimpan selama batch yang sama. Penulisan ke Spreadsheet digabung per baris, dan log ditulis tanpa membaca sheet Log. Banner "This application was created by a Google Apps Script user" muncul untuk web app dari akun Gmail biasa dan tidak bisa dihapus lewat kode; pilihannya adalah menyematkan LMS di Google Sites, memakai akun Google Workspace, atau memindahkan tampilan ke hosting sendiri (Apps Script hanya sebagai API).
- **Arsitektur sekarang (migrasi domain)**: tampilan LMS adalah file statis di folder `web/` (HTML/CSS/JS + gambar) yang di-hosting di cPanel `lms.arthasafetyindonesia.com`. Tampilan memanggil API Apps Script lewat `fetch` POST ke URL `/exec` (body `text/plain` berisi JSON `{action, data}` agar tidak memicu preflight CORS). Apps Script hanya berisi kode server (`doPost` → `api()`), sedangkan `doGet` mengarahkan ke alamat LMS. Data tetap di Google Spreadsheet & Drive milik manag3r.artha@gmail.com. Kunci akses Google tidak pernah ada di browser.
- **Laporan resmi (format Artha Safety)**: cover, Laporan Penyelenggaraan Kegiatan (A–E, tanda tangan + cap penandatangan), Jadwal Kegiatan, Daftar Materi dan Pemateri (dari rundown: pemateri + instansi), Laporan Absensi per peserta (identitas permohonan di atas; tiap sesi: tanggal, jam, alamat lokasi hasil reverse geocoding, foto, tanda tangan), dan Dokumentasi Kegiatan. Kop dari `aset-laporan.html` (tanpa footer). Data perusahaan disimpan di Script Properties `ORG`, data laporan per batch di kolom `Batch.info_laporan` (JSON). Foto absen 480 px, tanda tangan 400 px, dokumentasi 1280 px (versi laporan 640 px).

## 12. Arsitektur Hostinger (PHP + MySQL), versi utama

Dipindahkan dari Apps Script karena lambat (1–3 detik per permintaan, laporan bisa melewati batas 6 menit).

```
Browser (HP/laptop) ──HTTPS──▶ lms.arthasafetyindonesia.com (Hostinger Cloud)
                                 ├── index.html, css/, js/, img/   tampilan (sama dengan web/)
                                 ├── api/index.php                 POST JSON {action, data} → api()
                                 ├── api/unduh.php                 unduh file materi
                                 ├── app/*.php                     logika server (port 1:1 dari *.gs)
                                 ├── vendor/                       dompdf (pembuat PDF)
                                 ├── storage/  (privat)            foto absen, tanda tangan, laporan PDF
                                 ├── uploads/  (publik, nama acak) materi & dokumentasi
                                 └── MySQL                         tabel = sheet versi lama (+ token, pengaturan, geo_cache, log)
```

- **Kecepatan**: permintaan biasa ±0,1–0,3 detik; laporan resmi ±1 detik (diuji: 3 peserta × 2 hari = 0,7 detik).
- **Keamanan**: `app/`, `storage/`, `vendor/` ditolak lewat `.htaccess`; skrip tidak bisa dijalankan di `uploads/`; PIN di-hash (`password_hash`); token login di tabel `token`; absen dobel dicegah oleh UNIQUE (hari, peserta, sesi).
- **Alamat lokasi** dari OpenStreetMap Nominatim (gratis), di-cache per ±100 m di tabel `geo_cache`.
- **Dokumen Office** (PPT/Word/Excel) dibuka lewat penampil online Microsoft; PDF & gambar dibuka langsung.
- **Pemasangan**: `install.php` mengecek hosting, membuat `app/config.php`, tabel, dan akun Admin (ADMIN/123456). Pengecekan: `/api/?cek=1`.


## 13. Surat Keterangan Lulus (SKL)

- **PIC/Admin** (tab **SKL**): isi jenis pelatihan batch (Offline/Online/Blended) serta tempat & tanggal lahir peserta, lalu centang peserta → **Terbitkan SKL yang dipilih** (semua atau sebagian). SKL bisa **ditahan** (mis. administrasi belum lunas) dan diterbitkan lagi kemudian.
- **Peserta**: menu **Surat Keterangan Lulus** dan pemberitahuan di Dashboard; tombol unduh hanya muncul setelah SKL terbit. Peserta yang SKL-nya terbit tetap bisa login walaupun batch sudah *selesai*.
- **Isi SKL**: nama, tempat & tanggal lahir (data peserta), judul pelatihan (`nama_pelatihan`, bukan kode batch), periode (tanggal batch), jenis pelatihan, nomor SKL unik, tanggal terbit (tanggal SKL diterbitkan).
- **Nomor SKL**: urut per tahun, format diatur Admin di *Data perusahaan* (default `{no}/SKL/ASI/{bulan}/{tahun}`, mis. `001/SKL/ASI/XI/2026`). Nomor tetap walaupun SKL ditahan lalu diterbitkan lagi; database menolak nomor kembar.
- Data: tabel `skl` (satu baris per peserta), kolom `pengguna.tempat_lahir`, `pengguna.tgl_lahir`, `batch.jenis_pelatihan`. Ditambahkan otomatis ke database yang sudah terpasang (`pastikan_skema()`).
- PDF dibuat saat diunduh (`app/skl.php`, fungsi `skl_html`) dari data terbaru; PIC juga bisa mengunduh semua SKL terbit satu batch dalam satu PDF.
