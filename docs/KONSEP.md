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
- **Nomor SKL**: format diatur Admin di *Data perusahaan* (default `{no}/{kode}/SKL/PT-ARSINDO/{bulan}/{tahun}`, mis. `09/A06B087KEM/SKL/PT-ARSINDO/X/2026`; `{no}` urut per batch, `{kode}` diisi di tab SKL). Nomor tetap walaupun SKL ditahan lalu diterbitkan lagi; database menolak nomor kembar.
- Data: tabel `skl` (satu baris per peserta), kolom `pengguna.tempat_lahir`, `pengguna.tgl_lahir`, `batch.jenis_pelatihan`. Ditambahkan otomatis ke database yang sudah terpasang (`pastikan_skema()`).
- PDF dibuat saat diunduh (`app/skl.php`, fungsi `skl_html`) dari data terbaru; PIC juga bisa mengunduh semua SKL terbit satu batch dalam satu PDF.
- **Tata letak**: sama dengan template "Surat Keterangan Mengikuti" Artha Safety (kertas 624×870,5 pt). Latar, logo, judul, tanda tangan & cap direktur diambil dari template (`app/skl-latar.jpg`); teks diisi di posisi yang sama.

## 14. Dashboard PIC/Admin, menu, arsip, kategori materi, edit akun PIC

- **Dashboard** (menu pertama setelah login PIC/Admin, `app/dasbor.php`): ringkasan batch berjalan, peserta aktif, kehadiran hari ini, dan **pengingat** per batch (bukan arsip) dengan tombol langsung ke menu terkait:
  - lokasi absen hari ini / hari pelatihan berikutnya (H-3) belum diatur;
  - rundown hari ini/berikutnya belum dibuat atau masih draft; materi belum diupload;
  - dokumentasi hari pelatihan sebelumnya (dan hari ini setelah sesi 2) belum diupload;
  - peserta belum ditambahkan (≤ 7 hari sebelum mulai); peserta yang belum absen pada sesi berjalan;
  - setelah selesai: laporan resmi belum dibuat, SKL belum diterbitkan, batch perlu ditutup/diarsipkan.
- **Menu** PIC/Admin memakai gaya yang sama dengan menu peserta: menu samping berikon & berkelompok (Kegiatan harian, Persiapan, Akhir pelatihan, Admin) di layar lebar, menu geser di HP.
- **Arsip batch**: status `arsip` (tombol *Arsipkan* di menu Batch untuk batch yang selesai/lewat tanggal). Batch arsip disembunyikan dari pilihan batch & Dashboard; datanya tetap bisa dilihat (*Lihat data*) atau dipulihkan (*Pulihkan* → status Selesai). Peserta dengan SKL terbit tetap bisa mengunduh SKL.
- **Kategori materi**: Materi presentasi, Modul, Bahan ajar, Lembar latihan.
- **Edit akun PIC** (Admin, `picUbah`): nama, kode login, no HP, hak akses (PIC/Admin), aktif/nonaktif (nonaktif = langsung keluar dari semua perangkat), dan batch yang ditangani. Admin tidak bisa menurunkan/menonaktifkan akunnya sendiri; minimal satu Admin aktif.

## 15. Absensi narasumber

- Menu **Absensi Narasumber** (PIC/Admin saja, `app/narasumber.php`): narasumber cukup **tanda tangan digital** di perangkat PIC/Admin, tanpa foto. Waktu tercatat dari server.
- Daftar per hari diambil otomatis dari **rundown** (setiap kegiatan yang mencantumkan pemateri); narasumber di luar rundown bisa dicatat lewat **＋ Narasumber lain**. Satu tanda tangan per kegiatan; **Ulangi** menghapus tanda tangan agar bisa diisi ulang. Hanya bisa diisi pada hari pelatihan itu atau sesudahnya.
- Tabel `absen_narasumber` (snapshot nama, instansi, materi, jam; tanda tangan PNG di `storage/<batch>/narasumber/`), ditambahkan otomatis (skema versi 3).
- Rekap otomatis: laporan resmi mendapat halaman **DAFTAR HADIR NARASUMBER** (setelah daftar materi & pemateri; hanya jika ada narasumber) dan persyaratan *Daftar Hadir Pemateri* tercentang otomatis bila ada tanda tangan; laporan harian mendapat tabel **Daftar Hadir Narasumber** di bawah rundown.
- Dashboard: pengingat narasumber yang sesinya sudah dimulai (hari ini) atau hari pelatihan sebelumnya tetapi belum tanda tangan.

## 16. Hapus batch

- Menu **Batch** (Admin): tombol **🗑 Hapus** di setiap batch (aktif, selesai, maupun arsip). Wajib mengetik ulang kode batch.
- Menghapus permanen batch beserta peserta (dan sesi login-nya), hari, absensi, rundown, materi, dokumentasi, laporan, SKL, absensi narasumber, serta folder file `storage/<batch>` dan `uploads/<batch>`. Akun PIC/Admin dan batch lain tidak tersentuh.
- Untuk pelatihan sungguhan yang sudah selesai gunakan **Arsipkan**; hapus ditujukan untuk batch percobaan/dummy.

## 17. Program pelatihan, tata tertib & guideline, video pengarahan, tanggal pilihan

Semua perubahan bersifat **tambahan** (skema versi 4: tabel `program`, `dokumen_program`, `persetujuan`, `video`, `video_tonton`; kolom `batch.id_program`, `batch.tanggal_custom`). Data lama tidak diubah; batch lama berjalan seperti sebelumnya sampai Admin memilihkan program. Diuji dengan sidik jari (md5) isi 11 tabel lama dan semua file sebelum/sesudah update: identik.

- **Program pelatihan** (Admin, menu *Pustaka Pelatihan*): pengelompok batch sejenis. Batch dihubungkan ke program + skema (Offline/Online/Blended, kolom `jenis_pelatihan` yang sama dengan SKL) di form Batch.
- **Tata tertib & guideline** (`app/pustaka.php`): upload sekali per program × skema (atau *Semua skema*) × jenis. Dokumen untuk skema batch diutamakan, lalu *Semua skema*. **Revisi** = upload ulang: dipakai batch yang mulai sejak tanggal revisi; batch yang sudah berjalan tetap memakai versi yang berlaku saat batch mulai (versi terakhir ≤ tanggal mulai, atau versi pertama). Peserta bisa membuka/mengunduh dan menekan **Saya sudah membaca dan menyetujui** (tabel `persetujuan`).
- **Video pengarahan** (YouTube, semua batch): kartu *Video Wajib Ditonton* paling atas di Dashboard peserta dan di menu *Panduan Pelatihan*. Tercatat ditonton otomatis saat diputar ±90% (YouTube IFrame API); jika API tidak bisa dimuat, muncul tombol cadangan *Saya sudah menonton*. Hanya pengingat, tidak menghalangi absen.
- **Rekap PIC** (menu *Tata Tertib & Video*): dokumen & video yang berlaku untuk batch, serta siapa yang sudah menyetujui / menonton. Dashboard memberi pengingat (program belum dipilih, dokumen belum di-upload, peserta belum setuju/menonton).
- **Tanggal pilihan**: form batch punya mode *Rentang tanggal* atau *Pilih tanggal sendiri* (mis. 1, 3, 9, 14). `tgl_mulai/tgl_selesai` = tanggal pertama/terakhir; hari pelatihan dibuat dari daftar itu. Laporan resmi & SKL menulis *"1, 3, 9 dan 14 Juni 2026"* (`tanggal_batch_teks`). Tanggal yang sudah berisi absensi tidak dihapus.

## 18. Aturan lokasi absen: wajib di lokasi (offline) atau bebas (online)

- **Batch** (`batch.lokasi_bebas`): kosong = **wajib di lokasi** (perilaku lama, berlaku untuk semua batch yang sudah ada), `ya` = **bebas dari mana saja** (online). Batch baru dengan skema *Online* otomatis bebas. Diatur PIC/Admin di *Jadwal & Lokasi → Aturan lokasi absen*.
- **Per hari** (`hari.lokasi_bebas`): kosong = ikut batch, `ya` = online (mis. hari online pada batch blended), `tidak` = wajib di lokasi.
- Absen saat bebas: tanpa cek radius dan tanpa wajib GPS; jika GPS tersedia tetap dicatat (lat/lng/akurasi/alamat), `jarak_meter` kosong. Laporan menulis *Online · lokasi bebas*. Absensi yang sudah masuk tidak berubah.
- Dashboard tidak lagi mengingatkan "lokasi belum diatur" untuk hari yang bebas lokasi. Skema database versi 5.

## 19. Upload tanda tangan narasumber & duplikat batch

- **Absensi narasumber**: selain tanda tangan langsung, bisa **upload file** (foto/scan JPG/PNG). Di browser, latar kertas dibuang otomatis (piksel terang → transparan dengan tepi halus, goresan dipertegas), gambar dipotong rapat dan diperkecil (maks. 600×240), lalu disimpan sebagai **PNG transparan** (`ttdTransparan` di `pic.js`). Server tidak berubah: tetap menerima data URL PNG.
- **Duplikat batch** (Admin, tombol *⧉ Duplikat* di menu Batch; `batchDuplikat` di `app/batch.php`): membuat batch baru dengan nama, kode, program, skema, tanggal (rentang atau pilihan) dan PIC baru, lalu menyalin jam sesi, data laporan resmi (kecuali nomor permohonan, jumlah pendaftar/lulus, kode SKL), **rundown** per Hari ke-N sebagai *draft* (nama narasumber & instansi bisa dikosongkan) dan **materi** (file disalin ke folder batch baru sehingga tetap aman walau batch sumber dihapus). Tidak disalin: peserta, absensi, dokumentasi, laporan, SKL, absensi narasumber, lokasi. Jika batch baru lebih sedikit harinya, hari terakhir sumber dilewati dan diberitahukan. Gagal di tengah jalan → batch baru dihapus otomatis. Batch sumber tidak diubah (terverifikasi sidik jari data & file).

## 20. Lembaga penerbit sertifikat di SKL

- Tab **SKL** punya pilihan **Lembaga penerbit sertifikat**: *KEMNAKER RI* (bawaan, sama seperti sebelumnya), *BNSP*, atau *PT. Artha Safety Indonesia*. Disimpan per batch di `info_laporan.lembaga_skl` (tanpa perubahan database; ikut tersalin saat duplikat batch).
- Teks SKL "Sertifikasi **…**" dan "sedang dalam proses penerbitan oleh **…**" mengikuti pilihan ini; "… *Training*" mengikuti skema batch (Offline/Online/Blended). SKL batch yang tidak diubah tercetak persis sama seperti sebelum update (diuji).

## 21. Login peserta batch selesai/arsip

- Peserta batch berstatus **Selesai** atau **Diarsipkan** tetap bisa login seperti biasa (semua batch tampil di halaman login, batch aktif di atas, batch lain bertanda *(selesai)*) untuk melihat materi, dokumentasi, riwayat absen, panduan, dan SKL. **Absen** tetap hanya untuk batch aktif. SKL tetap hanya bisa diunduh jika sudah terbit.
- Memulihkan arsip (Admin): menu **Batch → Arsip pelatihan → ↩ Pulihkan** (status kembali *Selesai*), lalu bila perlu **Ubah → Status: Aktif**.

## 22. Dua perangkat per peserta & catatan jenis perangkat

- Peserta boleh login dan absen dari **maksimal 2 perangkat** (mis. HP + laptop); perangkat ke-3 ditolak dengan pesan berisi perangkat yang sudah terhubung. Sesi login di kedua perangkat aktif bersamaan (token per perangkat).
- Tabel `perangkat` (skema versi 6): jenis (HP / Laptop/PC / Tablet), sistem, browser — dibaca dari User-Agent saat login — serta waktu pertama login & terakhir aktif. Perangkat lama (`pengguna.device_id` dari sebelum fitur ini) tetap dihitung sebagai perangkat pertama; jenisnya tercatat saat peserta login berikutnya.
- PIC/Admin: menu **Peserta** menampilkan perangkat terhubung (📱/💻/📲); di **Kelola** bisa **Lepas** satu perangkat (sesi di perangkat itu berakhir) atau **Reset semua perangkat**.
- Setiap absen mencatat perangkat yang dipakai (`absensi.perangkat`), tampil di menu **Absensi** (ikon + detail) dan **laporan harian**. Laporan resmi tidak diubah.
- Absen offline (wajib lokasi) tetap memakai GPS & radius; laptop tidak punya GPS akurat, jadi untuk kelas offline PIC menyampaikan agar absen memakai HP.

## 23. Inventaris pelatihan

Semua tambahan (skema versi 7); akses dan tampilan peserta tidak berubah (`peserta.js`/`common.js` tidak diubah, data `user` peserta sama).

- **Peran**: Admin dan PIC yang dicentang **Petugas inventaris** (menu Akun PIC → Edit; kolom `pengguna.hak_inventaris`) mengelola katalog & stok gudang dan menandatangani berita acara sebagai bagian inventaris.
- **Gudang Inventaris** (`app/inventaris.php`): katalog barang — jenis *Diberikan* (training kit, kaos/kemeja) atau *Dipinjamkan* (APD, alat praktik), satuan, **ukuran** opsional (mis. S, M, L, XL, XXL), batas stok menipis. Stok = jumlah mutasi di `inv_mutasi` (buku besar): **Tambah stok** (+), **Koreksi** (+/−), disiapkan untuk batch (−), batal dari batch (+), kembali dari batch saat ditutup (+). Stok ≤ batas = menipis (per ukuran), minus ditandai merah. Riwayat keluar-masuk per barang.
- **Inventaris batch** (PIC & Admin): pilih barang dari katalog; jumlah disiapkan diisi manual (per ukuran untuk barang berukuran; tombol *Isi sesuai permintaan peserta*). Selisih jumlah otomatis memotong/mengembalikan stok gudang; stok kurang → konfirmasi (boleh minus).
  - *Per peserta* (`inv_peserta`): ukuran, status Belum / Diambil (Dipinjam) / Dikembalikan + kondisi Baik/Rusak/Hilang, catatan, waktu ambil & kembali; tombol *Tandai semua sudah diambil*.
  - *Alat bersama* (`inv_transaksi`): keluar N / kembali baik-rusak-hilang.
  - **Tutup & kembalikan ke gudang**: sisa barang + pinjaman yang kembali baik masuk lagi ke stok; pinjaman belum kembali perlu konfirmasi dan tercatat di berita acara. **Buka kembali** membatalkan pengembalian & tanda tangan.
  - **Berita acara PDF** (disimpan juga di riwayat laporan): ringkasan per barang & ukuran, rincian per peserta, riwayat alat bersama, daftar perlu tindak lanjut, tanda tangan digital **PIC Batch** dan **Bagian Inventaris** (verifikasi dari menu Gudang).
- **Dashboard PIC/Admin**: pinjaman belum kembali (hari terakhir), inventaris belum ditutup, berita acara belum ditandatangani; untuk petugas inventaris: stok menipis dan berita acara menunggu verifikasi.
- **Duplikat batch**: daftar barang ikut tersalin dengan jumlah 0 dan saran jumlah dari batch sumber (stok baru terpotong saat jumlah diisi). **Hapus batch**: alokasi barang batch ikut terhapus sehingga stok kembali.

## 24. Berkas peserta → Google Drive lembaga

Semua tambahan (skema versi 8). Menu **Berkas Saya** hanya muncul untuk peserta batch yang programnya punya daftar berkas (mis. program BNSP); peserta program lain tidak melihat perubahan apa pun.

- **Jembatan Apps Script** (`apps-script-jembatan/KirimBerkas.gs`): dipasang sekali di akun Google Artha (info.arthasafetyindonesia@gmail.com) sebagai *Aplikasi web* (jalankan sebagai: Saya, akses: Siapa saja). Hanya dipakai untuk **mengirim berkas** ke Drive; proses LMS lain tetap di Hostinger. Dilindungi **kode rahasia** yang dibuat LMS (Admin → Pustaka Pelatihan → Jembatan Google Drive: tempel URL `/exec`, Simpan, Cek jembatan).
- **Daftar berkas per program** (`berkas_item`, Admin di Pustaka): kategori *Persyaratan* / *Asesmen*, nama, format (pdf, jpg, png, docx, …), ukuran maks (≤ 25 MB), wajib/opsional, keterangan, urutan.
- **Per batch** (`berkas_batch`, PIC → menu **Berkas Peserta**): link folder Google Drive lembaga (akses siapa saja dengan link = Editor; boleh diganti kapan saja, riwayat link disimpan), **Cek koneksi**, **Buatkan folder semua peserta**, batas waktu upload per berkas.
- **Struktur di Drive**: `<folder lembaga>/<Nama Peserta>/Persyaratan` dan `/Asesmen`. Folder yang sudah dibuat PIC dipakai (nama dicocokkan tanpa membedakan huruf besar/kecil); yang belum ada dibuat otomatis. Nama file: `<Nama berkas> - <Nama Peserta>.<ext>`.
- **Upload peserta** (`berkas`): file disimpan dulu di LMS (salinan cadangan di `storage/…/berkas/`), peserta langsung mendapat jawaban, lalu pengiriman ke Drive berjalan **di belakang layar** setelah respons terkirim (`setelah_respon`). Foto besar dikompres di HP sebelum upload. Status Drive: antre / terkirim / gagal (+ alasan). Yang gagal dicoba lagi otomatis saat PIC membuka menu Berkas Peserta, atau lewat tombol **Kirim ulang**.
- **Verifikasi PIC**: Menunggu → **Diterima** atau **Perlu revisi** (wajib catatan; peserta melihat catatannya dan bisa upload ulang — file lama di Drive dipindah ke sampah bila masih di folder lembaga yang sama). Berkas yang sudah diterima atau lewat batas waktu tidak bisa diganti peserta (kecuali status revisi).
- **Rekap (Excel/CSV)** kelengkapan per peserta; **Dashboard** PIC/Admin: pengingat berkas wajib belum lengkap, menunggu verifikasi, gagal terkirim ke Drive, link Drive belum diisi. Beranda peserta: banner berkas kurang/perlu revisi.
- Hak akses: peserta hanya bisa melihat berkasnya sendiri; PIC/Admin semua berkas batch. **Hapus batch** ikut menghapus data berkas batch tersebut.

## 25. Sertifikat pelatihan (BNSP, BST, HSE) & tanggal SKL

Semua tambahan (skema versi 9). Peserta tidak melihat perubahan apa pun sampai Admin menerbitkan sertifikat.

- **Jenis**: *Sertifikat BNSP* (1 lembar, A4 tegak), *Basic Safety Training/BST* (3 lembar A4 mendatar: Basic First Aid, Basic Fire Fighting, Self Contained Breathing Apparatus), *HSE Training* (1 lembar per topik, bawaan 40 topik). PDF dibuat di atas latar asli desain Artha Safety (`app/sert-*.jpg`, termasuk logo & tanda tangan direktur).
- **Pengaturan per batch** (PIC & Admin, menu **Sertifikat**; tabel `sertifikat_batch`): centang jenis yang didapat; kode batch pada nomor per jenis; BNSP: judul pelatihan & periode **diisi manual** (hari pelatihan saja, tanpa hari asesmen; tanggal tanda tangan = tanggal selesai); BST: tanggal pelaksanaan (bawaan hari terakhir batch, bisa diubah) & tempat versi Inggris/Indonesia; HSE: periode (bawaan tanggal batch) & topik terpilih. HSE otomatis tercentang untuk batch KEMNAKER RI/BNSP yang belum diatur (inhouse/webinar tidak).
- **Topik HSE** (`sertifikat_topik`, Admin di Pustaka): kode, judul, bahasa (Inggris/Indonesia), daftar materi. 40 topik awal diambil dari sertifikat contoh; kode yang dulu sama-sama **SL** diganti kode unik (CWP, ERG, EXP, FM, HACCP, HMM, HI, HWP, HSER, AUD, FSMS, LKK, MCU, PAK, PPKL, SI, SMSM, SMSO, ST, DGB, SOPAB, WM, WAH; Safety Leadership tetap SL).
- **Nomor**: format per jenis diatur Admin (bawaan `{no}/{kode}/BNSP/ARSINDO/{bulan}/{tahun}`, `{no}/{kode}/{lembar}-ARSINDO/{bulan}/{tahun}`, `{no}/{topik}/{kode}/HSE-ARSINDO/{bulan}/{tahun}`); `{no}` = nomor absen 3 digit; bulan/tahun = tanggal sertifikat. Nomor disimpan saat terbit (`sertifikat.daftar_nomor`), nomor kembar ditolak.
- **Penerbitan hanya Admin**: Terbitkan / Tahan (nomor tetap) / Batalkan (nomor dihapus, dibuat ulang saat terbit lagi). BNSP butuh tempat & tanggal lahir. Jenis yang sudah ada sertifikat terbit tidak bisa dimatikan sebelum dibatalkan.
- **PIC**: melihat status, pratinjau (bertanda PRATINJAU), unduh per peserta dan semua (BNSP/BST satu PDF, HSE ZIP satu PDF per peserta, disusun bertahap). Dashboard: pemberitahuan 14 hari setelah sertifikat terbit + pengingat data belum lengkap; Admin: pengingat sertifikat belum terbit setelah batch selesai.
- **Peserta**: menu **Sertifikat** dan banner Dashboard muncul setelah ada sertifikat terbit; unduh satu PDF per jenis.
- **SKL**: tanggal di SKL = **hari terakhir pelatihan** (dulu tanggal diterbitkan); bulan/tahun nomor SKL baru juga mengikuti hari terakhir. Nomor SKL yang sudah terbit tidak berubah.

## 26. Nilai ujian peserta

Semua tambahan (skema versi 10). Menu **Nilai Ujian** (PIC & Admin, grup Akhir pelatihan); peserta hanya melihat menu **Nilai Ujian** setelah nilai dipublikasikan.

- **Jenis penilaian per batch** (`nilai_batch.mode`): *Kemnaker* (nilai diketik) atau *BNSP* (upload PDF dari asesor); bawaan mengikuti lembaga batch (KEMNAKER RI / BNSP), bisa diganti.
- **Template per program** (`nilai_program`, Admin di Pustaka): kategori & komponen ujian (bawaan Ujian Seminar: Praktek, Laporan, Paparan, Wawancara; Ujian Teori: Teori) dan batas lulus (bawaan 65). Batch memakai template programnya dan bisa disesuaikan per batch.
- **Kemnaker**: tabel peserta × komponen (angka 0–100, boleh desimal), bisa **tempel blok dari Excel**. Nilai rata-rata = rata-rata **semua komponen**, dibulatkan; **LULUS** bila ≥ batas lulus. *Tidak ikut ujian* = nilai 0, tidak lulus. Keterangan per peserta bisa diisi khusus (mis. "Tidak lulus ujian teori & seminar").
- **Rekap PDF** (A4 mendatar, kop surat Artha di setiap halaman sesuai contoh): Daftar Peserta · {jenis} Training · judul rekap · periode; kolom No, Nama, Perusahaan (kosong = UMUM), Keterangan, Nilai per komponen, Nilai Rata-rata; baris tidak lulus berwarna merah; keterangan di bawah tabel (otomatis bila ada peserta tidak lulus, bisa diubah); tanda tangan **PIC Pelatihan** (PIC yang mengunduh, atau PIC batch pertama yang punya tanda tangan), tanggal = hari terakhir pelatihan.
- **Laporan resmi**: rekap nilai (A4 tegak) masuk setelah absensi, sebelum dokumentasi; "Jumlah Peserta Lulus" terisi otomatis dari nilai bila belum diisi manual.
- **BNSP**: satu PDF berisi semua peserta (maks. 20 MB), bisa diganti; setelah dipublikasikan peserta bisa mengunduhnya.
- **Publikasi** (PIC & Admin): Kemnaker hanya bisa dipublikasikan bila semua peserta sudah lengkap / ditandai tidak ikut; BNSP butuh file. Bisa ditarik kembali. Peserta Kemnaker hanya melihat nilainya sendiri.
- **Dashboard**: setelah batch selesai, pengingat nilai belum lengkap / belum dipublikasikan / PDF BNSP belum diupload. **Hapus batch** ikut menghapus nilai batch.
