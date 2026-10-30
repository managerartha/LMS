# Konsep LMS Artha Safety Indonesia

> Dokumen konsep v0.1 — 30 Oktober 2026
> Tujuan: menyepakati fitur, alur, dan arsitektur sebelum mulai coding.

---

## 1. Ringkasan

LMS Artha Safety Indonesia adalah aplikasi web (bisa dibuka di HP maupun laptop) untuk mengelola **satu batch pelatihan** yang berisi **20–30 peserta** dan diawasi oleh **2 orang PIC**.

Fungsi utamanya:

| # | Fitur | Dibuat oleh | Dilihat oleh |
|---|-------|-------------|--------------|
| 1 | Absensi 2 sesi/hari (foto + timestamp + tanda tangan) | Peserta | Peserta (miliknya), PIC (semua) |
| 2 | Rundown kegiatan | PIC | Peserta & PIC |
| 3 | Dokumentasi kegiatan (foto harian) | PIC | Peserta & PIC |
| 4 | Materi harian (PPT, PDF, lembar latihan) | PIC | Peserta & PIC |
| 5 | Laporan kegiatan otomatis (PDF) | Sistem | PIC (download) |

---

## 2. Peran Pengguna

### 2.1 Admin (Artha Safety — kantor pusat)
- Membuat batch pelatihan baru (nama, tanggal mulai–selesai, lokasi).
- Menunjuk 2 PIC untuk batch tersebut.
- Bisa melihat semua batch (arsip).
- *Opsional:* peran ini bisa dirangkap oleh salah satu PIC jika tidak ada admin terpisah.

### 2.2 PIC (2 orang per batch)
- Mendaftarkan / import peserta (manual atau dari Excel).
- Menyusun rundown per hari.
- Upload materi harian dan dokumentasi kegiatan.
- Memantau absensi secara real-time, memberi status **Izin / Sakit / Alpa** secara manual bila perlu.
- Generate & download laporan PDF.

### 2.3 Peserta (20–30 orang per batch)
- Login, melakukan absensi sesi 1 & sesi 2.
- Melihat rundown, materi, dan dokumentasi.
- Melihat riwayat absensinya sendiri.

---

## 3. Struktur Data Inti

```
Batch
 ├── PIC (2 orang)
 ├── Peserta (20–30 orang)
 └── Hari Pelatihan (Hari ke-1 … Hari ke-N)
       ├── Sesi 1  (08.00 – 12.00)  → absensi peserta
       ├── Sesi 2  (13.00 – 17.00)  → absensi peserta
       ├── Rundown (daftar agenda per jam)
       ├── Materi  (PPT / PDF / lembar latihan)
       └── Dokumentasi (foto kegiatan + keterangan)
```

Semua waktu menggunakan **WIB (Asia/Jakarta)** dan diambil dari **jam server**, bukan jam HP peserta.

---

## 4. Fitur Detail

### 4.1 Absensi

**Jadwal sesi (default, bisa diubah PIC per batch):**

| Sesi | Jendela absen | Keterangan |
|------|---------------|------------|
| Sesi 1 — Pagi | 08.00 – 12.00 | Tombol absen hanya aktif di jam ini |
| Sesi 2 — Siang | 13.00 – 17.00 | Tombol absen hanya aktif di jam ini |

**Alur absen peserta:**

```
[Dashboard] → tombol "Absen Sesi 1" (aktif 08.00–12.00)
      │
      ▼
[1. Ambil Foto Selfie]  ← kamera langsung (tidak bisa pilih dari galeri)
      │
      ▼
[2. Tanda Tangan]       ← kanvas tanda tangan di layar (jari / mouse)
      │
      ▼
[3. Konfirmasi & Kirim]
      │
      ▼
Server mencatat: waktu server, foto, tanda tangan, (opsional) lokasi GPS
Foto otomatis diberi watermark:
  "Nama Peserta | Sesi 1 | Kamis, 30 Okt 2026 08:12:45 WIB | Lokasi"
```

**Aturan & anti-kecurangan:**
- Satu peserta hanya bisa absen **1 kali per sesi**.
- **Timestamp diambil dari server** saat data diterima → jam HP tidak berpengaruh.
- Foto wajib dari kamera langsung (bukan upload galeri).
- Watermark timestamp ditanam ke foto di server, jadi foto di laporan sudah berisi waktu.
- *Opsional:* simpan koordinat GPS dan batasi radius dari lokasi pelatihan (mis. 200 m).
- *Opsional:* status **Tepat Waktu / Terlambat** — misalnya absen sesi 1 setelah 08.30 ditandai "Terlambat" (batas bisa diatur PIC).
- Peserta yang tidak absen sampai jendela ditutup otomatis berstatus **Alpa**, dan PIC bisa mengubahnya menjadi **Izin / Sakit** beserta keterangan.

**Tampilan PIC — Monitor Absensi (real-time):**

```
Hari ke-2 — Kamis, 30 Okt 2026            Sesi 1: 24/28 hadir   Sesi 2: belum dibuka
┌────┬──────────────────┬───────────────┬───────────────┐
│ No │ Nama Peserta     │ Sesi 1        │ Sesi 2        │
├────┼──────────────────┼───────────────┼───────────────┤
│ 1  │ Andi Pratama     │ ✅ 08.05 [📷✍️] │ —             │
│ 2  │ Budi Santoso     │ ⚠️ 08.41 telat │ —             │
│ 3  │ Citra Lestari    │ ❌ belum       │ —             │
│ …  │                  │               │               │
└────┴──────────────────┴───────────────┴───────────────┘
```

### 4.2 Rundown
- PIC menyusun rundown per hari: **jam mulai, jam selesai, kegiatan, pemateri/fasilitator, keterangan**.
- Bisa **copy rundown dari hari sebelumnya** atau dari template batch lama, supaya tidak mengetik ulang.
- Begitu disimpan, rundown **langsung tampil otomatis** di akun semua peserta batch tersebut.
- Di dashboard peserta, agenda yang sedang berlangsung disorot ("Sedang berlangsung").
- Status draft / terbit: PIC bisa menyimpan sebagai draft dulu sebelum ditampilkan ke peserta.

### 4.3 Dokumentasi Kegiatan
- PIC upload foto (bisa banyak sekaligus) setiap hari, dikelompokkan per **hari** dan opsional per **agenda rundown**.
- Setiap foto bisa diberi keterangan singkat.
- Foto otomatis dikompres (mis. maks 1600 px, ±300 KB) agar hemat penyimpanan dan cepat dibuka di HP.
- Peserta melihat dalam bentuk galeri per hari.
- PIC memilih foto mana yang **masuk laporan PDF** (centang "tampilkan di laporan").

### 4.4 Materi Harian
- PIC upload file per hari: **PPT/PPTX, PDF, DOC/DOCX, XLS/XLSX**, atau link (mis. video YouTube).
- Kategori: **Materi Presentasi** atau **Lembar Latihan**.
- Peserta bisa **lihat langsung** (preview PDF di browser) dan **download**.
- *Rekomendasi:* PPT juga di-upload dalam versi PDF agar mudah dibuka di HP tanpa aplikasi tambahan (atau sistem mengonversi otomatis).
- PIC bisa mengatur materi tampil mulai jam tertentu (mis. lembar latihan baru muncul jam 13.00).

### 4.5 Laporan Kegiatan Otomatis (PDF)

PIC memilih: **Laporan Harian** (1 hari) atau **Laporan Lengkap Batch** (semua hari) → klik **Download PDF**.

**Susunan isi laporan:**

```
1. Halaman Sampul
   - Logo Artha Safety Indonesia
   - Judul pelatihan, nama batch, tanggal, lokasi
   - Nama PIC

2. Ringkasan
   - Jumlah peserta, jumlah hari
   - Rekap kehadiran (% hadir per sesi per hari)

3. Per Hari (diulang untuk setiap hari pelatihan)
   a. Rundown kegiatan hari itu (tabel)
   b. Rekap absensi (tabel: No | Nama | Sesi 1 jam & status | Sesi 2 jam & status)
   c. Lampiran absensi: grid foto timestamp + tanda tangan per peserta
        ┌─────────────┬─────────────┬─────────────┐
        │ [foto+wm]   │ [foto+wm]   │ [foto+wm]   │
        │ Andi P.     │ Budi S.     │ Citra L.    │
        │ 08.05 WIB   │ 08.41 WIB   │ 08.10 WIB   │
        │ [ttd]       │ [ttd]       │ [ttd]       │
        └─────────────┴─────────────┴─────────────┘
        (± 9–12 peserta per halaman, per sesi)
   d. Dokumentasi kegiatan (foto terpilih + keterangan)
   e. Daftar materi yang diberikan

4. Halaman Pengesahan
   - Kolom tanda tangan PIC 1 & PIC 2 (bisa pakai tanda tangan digital yang tersimpan)
```

**Catatan teknis:**
- PDF dibuat di server dari template HTML → hasilnya rapi dan konsisten.
- Untuk 30 peserta × 2 sesi × 5 hari ≈ 300 foto absen; foto diperkecil khusus untuk PDF (thumbnail ±400 px) supaya ukuran file tetap wajar (target < 20 MB).
- Laporan lengkap batch diproses di latar belakang; PIC mendapat notifikasi saat siap diunduh.

---

## 5. Peta Halaman (Sitemap)

**Peserta**
```
Login
└── Dashboard (hari ini)
     ├── Kartu Absensi Sesi 1 / Sesi 2 (status + tombol absen)
     ├── Rundown hari ini
     ├── Materi hari ini
     └── Dokumentasi terbaru
├── Rundown (semua hari)
├── Materi (semua hari)
├── Dokumentasi (galeri per hari)
├── Riwayat Absensi Saya
└── Profil
```

**PIC**
```
Login
└── Dashboard Batch
     ├── Ringkasan kehadiran hari ini (real-time)
     └── Shortcut: upload dokumentasi, upload materi
├── Peserta (tambah / import Excel / reset password)
├── Absensi (monitor, ubah status izin/sakit, lihat foto & ttd)
├── Rundown (susun per hari, copy, draft/terbit)
├── Materi (upload per hari)
├── Dokumentasi (upload per hari, pilih untuk laporan)
├── Laporan (harian / lengkap → download PDF)
└── Pengaturan Batch (jam sesi, batas terlambat, lokasi & radius GPS)
```

---

## 6. Rancangan Database (ringkas)

| Tabel | Kolom utama |
|-------|-------------|
| `users` | id, nama, email/no HP, password, role (admin/pic/peserta), foto profil |
| `batches` | id, nama_pelatihan, kode_batch, lokasi, tgl_mulai, tgl_selesai, jam_sesi1, jam_sesi2, batas_terlambat, lat, lng, radius |
| `batch_members` | batch_id, user_id, role_di_batch (pic/peserta) |
| `training_days` | id, batch_id, tanggal, hari_ke |
| `attendances` | id, day_id, user_id, sesi (1/2), waktu_server, foto_url, ttd_url, lat, lng, status (hadir/terlambat/izin/sakit/alpa), keterangan, diubah_oleh |
| `rundown_items` | id, day_id, jam_mulai, jam_selesai, kegiatan, pemateri, keterangan, urutan, status (draft/terbit) |
| `materials` | id, day_id, judul, kategori (materi/latihan), file_url, tipe_file, tampil_mulai, diupload_oleh |
| `documentations` | id, day_id, rundown_item_id (opsional), foto_url, keterangan, masuk_laporan, diupload_oleh |
| `reports` | id, batch_id, jenis (harian/lengkap), day_id, file_url, status, dibuat_oleh, dibuat_pada |

Constraint penting: `attendances` unik pada **(day_id, user_id, sesi)** → mencegah absen ganda.

---

## 7. Rekomendasi Teknologi

| Bagian | Pilihan | Alasan |
|--------|---------|--------|
| Frontend + Backend | **Next.js (React) + TypeScript** | Satu project untuk web & API, mudah di-deploy |
| Tampilan | Tailwind CSS, desain **mobile-first** + **PWA** | Peserta absen dari HP, bisa "di-install" seperti aplikasi |
| Database & Auth | **Supabase** (PostgreSQL + Auth + Storage) | Login, database, dan penyimpanan file dalam satu layanan; ada paket gratis |
| Penyimpanan file | Supabase Storage (atau S3 / Cloudflare R2) | Foto absen, tanda tangan, materi, dokumentasi |
| Tanda tangan | `signature_pad` (kanvas → PNG) | Ringan, jalan di HP |
| Watermark foto | `sharp` di server | Tanam teks timestamp ke foto |
| Generate PDF | Template HTML → PDF via **Playwright/Chromium** | Layout laporan fleksibel, mendukung tabel & grid foto |
| Hosting | Vercel (app) + Supabase (data) | Murah/gratis untuk skala 1 batch, mudah dikelola |

**Perkiraan penyimpanan per batch** (30 peserta, 5 hari):
- Foto absen: 30 × 2 × 5 × ±250 KB ≈ **75 MB**
- Tanda tangan: 300 × ±20 KB ≈ **6 MB**
- Dokumentasi: ±50 foto/hari × 5 × 300 KB ≈ **75 MB**
- Materi: ±50–200 MB
- **Total ± 200–350 MB per batch** → masih masuk paket gratis/murah.

---

## 8. Keamanan & Privasi

- Login dengan email/no HP + password; PIC bisa reset password peserta.
- Hak akses ketat: peserta hanya melihat batch-nya sendiri dan data absensinya sendiri; foto & tanda tangan peserta lain tidak bisa diakses.
- File disimpan privat, diakses lewat link bertanda tangan (signed URL) yang kedaluwarsa.
- Data pribadi (wajah, tanda tangan, lokasi) hanya untuk keperluan pelatihan — perlu persetujuan peserta saat login pertama (sesuai UU PDP).
- Log perubahan: setiap PIC mengubah status absensi, tercatat siapa & kapan.

---

## 9. Tahapan Pengembangan (Roadmap)

| Tahap | Isi | Estimasi |
|-------|-----|----------|
| **Tahap 1 — MVP** | Login & peran, kelola batch & peserta, absensi (foto + timestamp + ttd), monitor absensi PIC, rundown | 2–3 minggu |
| **Tahap 2** | Materi harian, dokumentasi kegiatan, galeri peserta | 1–2 minggu |
| **Tahap 3** | Laporan PDF harian & lengkap, halaman pengesahan | 1–2 minggu |
| **Tahap 4 — Penyempurnaan** | GPS radius, status terlambat, import Excel, notifikasi, PWA, uji coba dengan 1 batch nyata | 1–2 minggu |

---

## 10. Hal yang Perlu Dikonfirmasi

1. **Admin terpisah?** Apakah ada admin kantor yang membuat batch, atau PIC sendiri yang membuat batch?
2. **Login peserta:** pakai email, no HP, atau NIK/kode peserta? Akun dibuatkan PIC atau peserta daftar sendiri dengan kode batch?
3. **Status terlambat:** perlu ada batas terlambat (mis. lewat 08.30 / 13.30), atau cukup "hadir" selama masih di jendela sesi?
4. **Validasi lokasi GPS:** wajib absen di lokasi pelatihan, atau boleh dari mana saja (mis. pelatihan online)?
5. **Durasi batch:** biasanya berapa hari? Apakah hari Sabtu/Minggu ikut?
6. **Format laporan:** apakah ada format laporan baku Artha Safety (kop surat, nomor dokumen) yang harus diikuti?
7. **Tanda tangan PIC di laporan:** tanda tangan digital di sistem, atau dicetak lalu ditandatangani basah?
8. **Fitur lanjutan** yang mungkin diinginkan nanti: pre-test/post-test, nilai, sertifikat otomatis, evaluasi pelatihan?
9. **Domain & hosting:** sudah punya domain (mis. `lms.arthasafety.co.id`)?
