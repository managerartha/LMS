# Panduan Pemasangan LMS Artha Safety Indonesia

Panduan ini memasang LMS di akun Google **manag3r.artha@gmail.com**. Lama pengerjaan sekitar 15–20 menit, cukup sekali saja.

Hasil akhirnya:
- Folder **"LMS Artha Safety"** di Google Drive akun tersebut
- Spreadsheet **"LMS Artha Safety - Database"** di dalam folder itu
- Satu link aplikasi (`https://script.google.com/macros/s/…/exec`) untuk semua PIC dan peserta

---

## Langkah 1 — Buat project Apps Script

1. Buka Chrome dan pastikan sudah login sebagai **manag3r.artha@gmail.com**.
2. Buka **https://script.google.com**, lalu klik **New project / Proyek baru**.
3. Klik judul "Untitled project" di kiri atas dan ganti menjadi **LMS Artha Safety**.

## Langkah 2 — Tampilkan file pengaturan (appsscript.json)

1. Klik ikon ⚙️ **Project Settings / Setelan project** di menu kiri.
2. Centang **"Show 'appsscript.json' manifest file in editor"**.
3. Pastikan **Time zone** = `(GMT+07:00) Jakarta`.
4. Kembali ke ⟨⟩ **Editor**.

## Langkah 3 — Salin file kode

Semua file ada di folder [`apps-script/`](../apps-script) di repository ini. Untuk setiap file: buat filenya di editor, hapus isi bawaan, lalu tempel seluruh isi file dari repository.

| File di repository | Cara membuat di editor Apps Script | Nama yang diketik |
|---|---|---|
| `appsscript.json` | sudah ada (setelah langkah 2) | — |
| `Code.gs` | sudah ada (ganti isinya) | — |
| `Setup.gs` | **+** → Script | `Setup` |
| `Auth.gs` | **+** → Script | `Auth` |
| `Batch.gs` | **+** → Script | `Batch` |
| `Absensi.gs` | **+** → Script | `Absensi` |
| `index.html` | **+** → HTML | `index` |
| `css.html` | **+** → HTML | `css` |
| `js-common.html` | **+** → HTML | `js-common` |
| `js-peserta.html` | **+** → HTML | `js-peserta` |
| `js-pic.html` | **+** → HTML | `js-pic` |

> Ketik nama **tanpa** `.gs` / `.html`, karena editor menambahkannya otomatis. Nama harus persis sama, termasuk huruf kecil dan tanda strip.

Klik 💾 **Save** (Ctrl+S).

## Langkah 4 — Jalankan setup (sekali saja)

1. Buka file **Setup.gs**.
2. Di toolbar atas, pilih fungsi **`setup`**, lalu klik ▶ **Run**.
3. Google akan meminta izin:
   - Klik **Review permissions** dan pilih akun **manag3r.artha@gmail.com**.
   - Muncul layar "Google hasn't verified this app". Ini normal karena aplikasinya buatan sendiri. Klik **Advanced**, lalu **Go to LMS Artha Safety (unsafe)**.
   - Klik **Allow**. Izin yang diminta: Spreadsheet, Drive, dan akses URL eksternal (untuk membaca link Google Maps).
4. Tunggu sampai **Execution log** menampilkan `Setup selesai.` beserta link folder Drive dan Spreadsheet.

## Langkah 5 — Publikasikan sebagai web app

1. Klik **Deploy** → **New deployment**.
2. Klik ⚙️ di samping "Select type" dan pilih **Web app**.
3. Isi:
   - Description: `LMS v1`
   - **Execute as: Me (manag3r.artha@gmail.com)**
   - **Who has access: Anyone**. Ini wajib dipilih supaya peserta tidak perlu punya akun Google; keamanan login diatur oleh kode peserta dan PIN PIC.
4. Klik **Deploy** dan salin **Web app URL** (berakhiran `/exec`). Link inilah yang dibagikan ke PIC dan peserta.

> Tips: buat link pendek (mis. bit.ly/lms-asi) atau QR code dari URL tersebut agar peserta mudah membukanya.

## Langkah 6 — Login pertama & ganti PIN Admin

1. Buka Web app URL, pilih tab **PIC / Admin**.
2. Login dengan **Kode `ADMIN`** dan **PIN `123456`**.
3. **Segera** klik tombol **PIN** di kanan atas dan ganti PIN-nya.

## Langkah 7 — Siapkan batch pertama

1. **Akun PIC**: tambahkan 2 PIC (nama, kode login mis. `PIC-Dina`, PIN 4–8 angka).
2. **Batch**: buat batch (nama pelatihan, kode batch mis. `B07`, tanggal mulai–selesai, pilih 2 PIC). Hari pelatihan dibuat otomatis.
3. **Jadwal & Lokasi**:
   - Jam sesi sudah otomatis 08:00–12:00 dan 13:00–17:00, dan bisa diubah.
   - **Lokasi default**: saat berada di tempat pelatihan, tekan **📍 Pakai lokasi saya sekarang**, beri nama lokasi, lalu simpan. Bisa juga dengan menempel link Google Maps.
   - **Lokasi per hari**: jika ada hari yang pindah tempat (mis. praktik lapangan), klik **Ubah** pada hari tersebut. Tombol ↺ mengembalikannya ke lokasi default.
4. **Peserta**: tempel daftar nama (satu per baris, bisa langsung copy kolom dari Excel), lalu klik **Tambah peserta**. Kode seperti `001-Kikis` dibuat otomatis.
5. Klik **🖨 Cetak kartu kode** dan bagikan kartunya ke peserta di hari pertama.

## Langkah 8 — Coba absen dari HP

Sebelum batch dimulai, lakukan uji coba dengan satu akun peserta dari HP:
1. Buka link, pilih batch, ketik kode, lalu setujui pernyataan privasi.
2. Saat absen, **izinkan akses Lokasi dan Kamera** jika browser meminta.
3. Pastikan foto ber-watermark dan tanda tangan muncul di menu **Absensi** milik PIC.
4. Setelah selesai uji coba, buka **Peserta → Kelola → Reset HP** agar akun bisa dipakai peserta aslinya, dan ubah status absen uji coba di menu Absensi jika perlu.

> Catatan: uji coba hanya bisa dilakukan **di dalam jam sesi** pada **tanggal pelatihan**. Untuk uji coba sebelum batch asli, buat batch percobaan dengan tanggal hari ini.

---

## Memperbarui kode (versi berikutnya)

1. Tempel ulang file yang berubah di editor, lalu Save.
2. Jika ada sheet atau kolom baru, jalankan **`setup`** sekali lagi. Ini aman, data lama tidak terhapus.
3. Klik **Deploy** → **Manage deployments** → ✏️ edit → **Version: New version** → **Deploy**.
   Link `/exec` **tetap sama**, jadi peserta tidak perlu link baru.

> Alternatif untuk developer: gunakan [`clasp`](https://github.com/google/clasp) (`clasp login`, `clasp clone <scriptId>`, salin isi folder `apps-script/`, lalu `clasp push`).

---

## Masalah yang sering terjadi

| Masalah | Solusi |
|---|---|
| "Izin lokasi ditolak" | Di HP: Pengaturan browser → Setelan situs → Lokasi → izinkan. Pastikan GPS HP aktif. |
| Kamera tidak terbuka langsung | LMS otomatis membuka aplikasi kamera HP sebagai gantinya. Jika diminta, izinkan akses Kamera. |
| "Anda berada ± … m dari lokasi" padahal sudah di lokasi | Akurasi GPS sedang rendah. Pindah ke area terbuka dan coba lagi, atau minta PIC memperbesar radius / memperbarui titik lokasi. |
| "Akun ini sudah terhubung ke HP lain" | Peserta ganti HP / browser / menghapus data browser. PIC: **Peserta → Kelola → Reset HP**. |
| "Lokasi pelatihan hari ini belum diatur PIC" | PIC mengisi lokasi default atau lokasi hari itu di **Jadwal & Lokasi**. |
| Peserta tidak absen karena sakit/izin | PIC: menu **Absensi** → klik sel sesi peserta → pilih Izin/Sakit + keterangan. |
| Lupa PIN PIC | Admin: **Akun PIC → Reset PIN**. |
| Lupa PIN Admin | Di Spreadsheet, sheet `Pengguna`, salin nilai `pin_hash` dari akun PIC yang PIN-nya diketahui ke baris ADMIN. PIN Admin sekarang sama dengan PIN PIC tersebut; segera ganti setelah login. |

---

## Di mana data tersimpan?

- **Spreadsheet `LMS Artha Safety - Database`**: semua data (batch, peserta, absensi, log). Bisa dibuka untuk rekap, tapi **jangan mengubah judul kolom atau menghapus sheet**.
- **Folder `LMS Artha Safety / <Kode Batch> - <Nama Pelatihan>`**:
  - `Absensi/Hari-N/`: foto dan tanda tangan absen, mis. `H1_S1_001-Kikis_foto.jpg`
  - `Materi/`, `Dokumentasi/`, `Laporan/`: dipakai mulai Tahap 2 & 3
- Folder dan Spreadsheet **jangan dibagikan publik**. Foto absen dan tanda tangan hanya bisa dilihat PIC lewat LMS.
