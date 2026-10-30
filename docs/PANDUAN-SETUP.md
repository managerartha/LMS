# Panduan Pemasangan LMS Artha Safety Indonesia

LMS terdiri dari dua bagian:

| Bagian | Isi | Dipasang di |
|---|---|---|
| **Tampilan (web)** | Folder [`web/`](../web): HTML, CSS, JS, logo | cPanel, mis. **https://lms.arthasafetyindonesia.com** |
| **API & data** | Folder [`apps-script/`](../apps-script): kode server | Google Apps Script akun **manag3r.artha@gmail.com** |

Peserta dan PIC hanya membuka alamat di domain Anda. Apps Script bekerja di belakang layar untuk membaca dan menulis Spreadsheet dan Drive, jadi peserta tidak pernah melihatnya dan banner "This application was created by a Google Apps Script user" tidak muncul.

Data tetap tersimpan di Google Drive akun tersebut:
- Folder **"LMS Artha Safety"**
- Spreadsheet **"LMS Artha Safety - Database"** di dalam folder itu

> **Sudah pernah memasang versi Apps Script sebelumnya?** Langsung ke bagian [Pindah dari versi lama](#pindah-dari-versi-lama-apps-script-penuh). Data lama tetap dipakai.

---

# Bagian A — API di Google Apps Script

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

Semua file ada di folder [`apps-script/`](../apps-script). Untuk setiap file: buat filenya di editor, hapus isi bawaan, lalu tempel seluruh isi file.

| File di repository | Cara membuat di editor Apps Script | Nama yang diketik |
|---|---|---|
| `appsscript.json` | sudah ada (setelah langkah 2) | — |
| `Code.gs` | sudah ada (ganti isinya) | — |
| `Setup.gs` | **+** → Script | `Setup` |
| `Auth.gs` | **+** → Script | `Auth` |
| `Batch.gs` | **+** → Script | `Batch` |
| `Absensi.gs` | **+** → Script | `Absensi` |
| `Konten.gs` | **+** → Script | `Konten` |
| `Laporan.gs` | **+** → Script | `Laporan` |
| `aset-logo.html` | **+** → HTML | `aset-logo` |

> `aset-logo.html` adalah logo untuk sampul laporan PDF, dalam bentuk teks (base64) yang hanya berisi **satu baris sangat panjang**. Salin semuanya tanpa menambah spasi atau baris baru.
>
> Ketik nama **tanpa** `.gs` / `.html`. Nama harus persis sama.

Jika alamat LMS Anda **bukan** `https://lms.arthasafetyindonesia.com`, ubah baris `const WEB_URL = …` di bagian atas `Code.gs`.

Klik 💾 **Save** (Ctrl+S).

## Langkah 4 — Jalankan setup (sekali saja)

1. Buka file **Setup.gs**.
2. Di toolbar atas, pilih fungsi **`setup`**, lalu klik ▶ **Run**.
3. Google akan meminta izin:
   - Klik **Review permissions** dan pilih akun **manag3r.artha@gmail.com**.
   - Muncul layar "Google hasn't verified this app". Ini normal karena aplikasinya buatan sendiri. Klik **Advanced**, lalu **Go to LMS Artha Safety (unsafe)**.
   - Klik **Allow**. Izin yang diminta: Spreadsheet, Drive, dan akses URL eksternal (untuk membaca link Google Maps).
4. Tunggu sampai **Execution log** menampilkan `Setup selesai.` beserta link folder Drive dan Spreadsheet.

## Langkah 5 — Publikasikan API

1. Klik **Deploy** → **New deployment**.
2. Klik ⚙️ di samping "Select type" dan pilih **Web app**.
3. Isi:
   - Description: `LMS API v1`
   - **Execute as: Me (manag3r.artha@gmail.com)**
   - **Who has access: Anyone**. Ini wajib dipilih supaya tampilan LMS di domain Anda bisa memanggil API tanpa login Google; keamanan diatur oleh kode peserta dan PIN PIC.
4. Klik **Deploy** dan salin **Web app URL** (berakhiran `/exec`). URL ini **tidak dibagikan** ke peserta; dipakai di Langkah 7.

---

# Bagian B — Tampilan di cPanel (arthasafetyindonesia.com)

## Langkah 6 — Buat subdomain + SSL

1. Login ke **cPanel** hosting arthasafetyindonesia.com.
2. Buka **Domains** (atau **Subdomains** di cPanel lama) → **Create A New Domain**.
3. Isi domain: `lms.arthasafetyindonesia.com`. Document Root otomatis terisi, mis. `public_html/lms` atau `lms.arthasafetyindonesia.com`. Catat foldernya, lalu klik **Submit**.
4. Buka **SSL/TLS Status** → centang `lms.arthasafetyindonesia.com` → **Run AutoSSL**. Tunggu sampai gemboknya hijau.
   **SSL (HTTPS) wajib**: tanpa HTTPS, browser memblokir kamera dan GPS.

> Tidak mau memakai subdomain? Bisa juga di folder, mis. `arthasafetyindonesia.com/lms`: cukup upload ke `public_html/lms`. Sesuaikan `WEB_URL` di `Code.gs`.

## Langkah 7 — Isi alamat API

Di folder `web/`, buka file **`js/config.js`** dan ganti `API_URL` dengan Web app URL dari Langkah 5:

```js
window.LMS_CONFIG = {
  API_URL: 'https://script.google.com/macros/s/AKfycb…/exec',
};
```

(Bisa juga diubah langsung di cPanel setelah upload: **File Manager** → buka `js/config.js` → klik kanan **Edit** → **Save Changes**.)

## Langkah 8 — Upload file tampilan

1. Di cPanel buka **File Manager**, masuk ke Document Root dari Langkah 6.
2. Klik **Upload** dan pilih file **`lms-web.zip`**: isi folder `web/` yang sudah di-zip, termasuk file tersembunyi `.htaccess`.
3. Kembali ke File Manager, klik kanan `lms-web.zip` → **Extract** → **Extract Files**. Setelah itu file zip boleh dihapus.
4. Pastikan isi folder seperti ini:
   ```
   .htaccess        (aktifkan "Show Hidden Files" di Settings File Manager untuk melihatnya)
   index.html
   css/app.css
   js/config.js  js/common.js  js/peserta.js  js/pic.js
   img/logo.png  img/ikon.png  img/foto.webp
   ```
5. Buka **https://lms.arthasafetyindonesia.com**: halaman login dengan logo Artha Safety harus tampil.

---

# Bagian C — Mulai memakai LMS

## Langkah 9 — Login pertama & ganti PIN Admin

1. Buka **https://lms.arthasafetyindonesia.com**, pilih tab **PIC / Admin**.
2. Login dengan **Kode `ADMIN`** dan **PIN `123456`**.
3. **Segera** klik tombol **PIN** di kanan atas dan ganti PIN-nya.

## Langkah 10 — Siapkan batch pertama

1. **Akun PIC**: tambahkan 2 PIC (nama, kode login mis. `PIC-Dina`, PIN 4–8 angka).
2. **Batch**: buat batch (nama pelatihan, kode batch mis. `B07`, tanggal mulai–selesai, pilih 2 PIC). Hari pelatihan dibuat otomatis.
3. **Jadwal & Lokasi**:
   - Jam sesi sudah otomatis 08:00–12:00 dan 13:00–17:00, dan bisa diubah.
   - **Lokasi default**: saat berada di tempat pelatihan, tekan **📍 Pakai lokasi saya sekarang**, beri nama lokasi, lalu simpan. Bisa juga dengan menempel link Google Maps.
   - **Lokasi per hari**: jika ada hari yang pindah tempat (mis. praktik lapangan), klik **Ubah** pada hari tersebut. Tombol ↺ mengembalikannya ke lokasi default.
4. **Peserta**: tempel daftar nama (satu per baris, bisa langsung copy kolom dari Excel), lalu klik **Tambah peserta**. Kode seperti `001-Kikis` dibuat otomatis.
5. Klik **🖨 Cetak kartu kode** dan bagikan kartunya ke peserta di hari pertama.

## Langkah 11 — Coba absen dari HP

Sebelum batch dimulai, lakukan uji coba dengan satu akun peserta dari HP:
1. Buka https://lms.arthasafetyindonesia.com, pilih batch, ketik kode, lalu setujui pernyataan privasi.
2. Saat absen, **izinkan akses Lokasi dan Kamera** jika browser meminta.
3. Pastikan foto ber-watermark dan tanda tangan muncul di menu **Absensi** milik PIC.
4. Setelah selesai uji coba, buka **Peserta → Kelola → Reset HP** agar akun bisa dipakai peserta aslinya, dan ubah status absen uji coba di menu Absensi jika perlu.

> Catatan: uji coba hanya bisa dilakukan **di dalam jam sesi** pada **tanggal pelatihan**. Untuk uji coba sebelum batch asli, buat batch percobaan dengan tanggal hari ini.

---

## Langkah 12 — Rundown, materi, dan dokumentasi (PIC)

Semua menu ini ada di akun PIC dan diisi **per hari** (pilih hari di bagian atas setiap menu).

- **Rundown**
  - Isi jam mulai–selesai, kegiatan, pemateri, dan keterangan. Tekan **＋ Tambah kegiatan** untuk baris baru; urutannya otomatis mengikuti jam mulai.
  - **Simpan sebagai draft**: belum tampil ke peserta. **Simpan & terbitkan**: langsung tampil di akun semua peserta batch.
  - **Salin dari hari lain**: mengambil rundown hari lain sebagai draft, lalu tinggal diedit.
- **Materi**
  - Upload file PDF, PPT, Word, Excel, atau gambar (maks. 20 MB), atau tempel link (Google Drive, YouTube, dll.).
  - Pilih kategori **Materi presentasi** atau **Lembar latihan**.
  - **Tampil mulai jam** (opsional): materi baru muncul di akun peserta pada jam tersebut, misalnya lembar latihan baru tampil jam 13:00.
  - Peserta melihat materi mulai tanggal harinya, dan masih bisa membuka materi hari-hari sebelumnya.
- **Dokumentasi**
  - Pilih banyak foto sekaligus. Foto otomatis diperkecil sebelum diupload, jadi lebih cepat dan hemat kuota.
  - Bisa dikaitkan ke kegiatan rundown dan diberi keterangan.
  - Klik foto untuk mengubah keterangan atau kegiatan, menghapus, atau mengatur **Masukkan ke laporan PDF** (default: masuk).

> File materi dan dokumentasi disimpan di folder batch di Drive dan dibagikan sebagai **"siapa saja yang punya link: dapat melihat"** agar bisa dibuka peserta tanpa akun Google. Foto absen dan tanda tangan **tetap privat**.

---

## Langkah 13 — Laporan PDF (PIC)

1. Buka menu **Laporan**.
2. **Sekali saja:** di bagian **Tanda tangan saya**, buat tanda tangan digital. Tanda tangan ini otomatis dipasang di halaman pengesahan. Kalau belum dibuat, kolomnya dikosongkan untuk tanda tangan basah.
3. Pilih **Laporan harian** (lalu pilih harinya) atau **Laporan lengkap batch**, lalu klik **📄 Buat & unduh PDF**.
4. PDF otomatis terunduh dan juga tersimpan di folder **Laporan** batch di Google Drive. Laporan lama bisa diunduh ulang dari **Riwayat laporan**.

Isi laporan:
1. **Sampul**: nama pelatihan, batch, tanggal, lokasi, jumlah peserta, PIC
2. **Ringkasan kehadiran**: jumlah Hadir/Izin/Sakit/Alpa dan % hadir per sesi per hari
3. **Per hari**:
   - A. Rundown kegiatan
   - B. Daftar hadir (jam absen, status, keterangan izin/sakit)
   - C. Lampiran foto timestamp + tanda tangan setiap peserta yang hadir, per sesi
   - D. Dokumentasi kegiatan (hanya foto yang ditandai ✓ "masuk laporan")
   - E. Daftar materi
4. **Pengesahan**: tanda tangan digital PIC batch

> Laporan sebaiknya dibuat setelah Sesi 2 ditutup (setelah 17:00). Kalau dibuat lebih awal, peserta yang belum absen tercatat "Belum absen" dan sesi yang belum dibuka tercatat "–".

---

## Memperbarui kode (versi berikutnya)

**API (Apps Script):**
1. Tempel ulang file `.gs` yang berubah di editor, lalu Save.
2. Jika ada sheet atau kolom baru, jalankan **`setup`** sekali lagi. Ini aman, data lama tidak terhapus.
3. Klik **Deploy** → **Manage deployments** → ✏️ edit → **Version: New version** → **Deploy**.
   URL `/exec` **tetap sama**, jadi `config.js` tidak perlu diubah.

**Tampilan (cPanel):**
1. Upload ulang file yang berubah ke folder yang sama (timpa file lama). **Jangan menimpa `js/config.js`** kecuali memang ingin mengubah alamat API.
2. Di `index.html`, angka `?v=1` di belakang nama file CSS/JS dinaikkan (mis. `?v=2`) setiap ada pembaruan, agar browser peserta langsung memuat versi baru.

> Alternatif untuk developer: gunakan [`clasp`](https://github.com/google/clasp) untuk Apps Script dan FTP/Git untuk cPanel.

## Pindah dari versi lama (Apps Script penuh)

Data (Spreadsheet & Drive) tetap dipakai, tidak perlu `setup` ulang.
1. Di editor Apps Script: ganti isi **`Code.gs`** dengan versi baru.
2. **Hapus** file yang tidak dipakai lagi (klik ⋮ di samping nama file → Delete): `index`, `css`, `js-common`, `js-peserta`, `js-pic`, `aset-ikon`, `aset-foto`. **`aset-logo` tetap disimpan** (dipakai laporan PDF).
3. **Deploy** → **Manage deployments** → ✏️ → **New version** → **Deploy**. URL `/exec` tetap sama.
4. Kerjakan Bagian B (Langkah 6–8) memakai URL `/exec` tersebut.
5. Bagikan alamat baru **https://lms.arthasafetyindonesia.com** ke PIC dan peserta. Link `/exec` lama otomatis mengarahkan ke alamat baru.

> Peserta yang sudah login di versi lama perlu login sekali lagi di alamat baru (HP-nya tetap dikenali). Jika muncul "Akun ini sudah terhubung ke HP lain", PIC cukup menekan **Reset HP**.

---

## Masalah yang sering terjadi

| Masalah | Solusi |
|---|---|
| "Koneksi gagal. Periksa internet Anda" padahal internet lancar | `API_URL` di `js/config.js` belum diisi / salah, atau deployment Apps Script belum **Anyone**. Buka URL `/exec` di browser: harus tampil tulisan "LMS Artha Safety Indonesia ada di …". |
| Perubahan tampilan belum muncul | Naikkan angka `?v=` di `index.html`, lalu muat ulang halaman (Ctrl+Shift+R). |
| Kamera / GPS tidak bisa dipakai | Pastikan alamat diawali **https://** (gembok hijau). Jalankan AutoSSL di cPanel jika belum. |
| "Izin lokasi ditolak" | Di HP: Pengaturan browser → Setelan situs → Lokasi → izinkan. Pastikan GPS HP aktif. |
| Kamera tidak terbuka langsung | LMS otomatis membuka aplikasi kamera HP sebagai gantinya. Jika diminta, izinkan akses Kamera. |
| "Anda berada ± … m dari lokasi" padahal sudah di lokasi | Akurasi GPS sedang rendah. Pindah ke area terbuka dan coba lagi, atau minta PIC memperbesar radius / memperbarui titik lokasi. |
| "Akun ini sudah terhubung ke HP lain" | Peserta ganti HP / browser / menghapus data browser. PIC: **Peserta → Kelola → Reset HP**. |
| "Lokasi pelatihan hari ini belum diatur PIC" | PIC mengisi lokasi default atau lokasi hari itu di **Jadwal & Lokasi**. |
| Peserta tidak absen karena sakit/izin | PIC: menu **Absensi** → klik sel sesi peserta → pilih Izin/Sakit + keterangan. |
| Laporan lengkap gagal / waktu habis | Google membatasi satu proses maksimal 6 menit. Buat **laporan harian** untuk setiap hari. |
| PDF tidak terunduh otomatis | Coba tombol **⬇ Unduh** di Riwayat laporan, atau ambil dari folder **Laporan** batch di Google Drive (login sebagai manag3r.artha@gmail.com). |
| Lupa PIN PIC | Admin: **Akun PIC → Reset PIN**. |
| Lupa PIN Admin | Di Spreadsheet, sheet `Pengguna`, salin nilai `pin_hash` dari akun PIC yang PIN-nya diketahui ke baris ADMIN. PIN Admin sekarang sama dengan PIN PIC tersebut; segera ganti setelah login. |

---

## Di mana data tersimpan?

- **Spreadsheet `LMS Artha Safety - Database`**: semua data (batch, peserta, absensi, log). Bisa dibuka untuk rekap, tapi **jangan mengubah judul kolom atau menghapus sheet**.
- **Folder `LMS Artha Safety / <Kode Batch> - <Nama Pelatihan>`**:
  - `Absensi/Hari-N/`: foto dan tanda tangan absen, mis. `H1_S1_001-Kikis_foto.jpg`
  - `Materi/Hari-N/` dan `Dokumentasi/Hari-N/`: file materi dan foto kegiatan (bisa dibuka lewat link)
  - `Laporan/`: semua laporan PDF yang pernah dibuat
- **Folder `LMS Artha Safety / Tanda Tangan PIC`**: tanda tangan digital PIC untuk halaman pengesahan
- Folder dan Spreadsheet **jangan dibagikan publik**. Foto absen dan tanda tangan hanya bisa dilihat PIC lewat LMS.
