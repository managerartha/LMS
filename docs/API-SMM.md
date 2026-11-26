# API LMS untuk aplikasi SMM (Sistem Manajemen Mutu)

API **baca-saja** dari LMS Artha Safety Indonesia. Data pribadi peserta **tidak pernah dikirim**: tidak ada nama, No. HP, email, NIK, alamat, kode login, atau ID pengguna.
Yang dikirim hanya angka rekap, skor, komentar, dan ID referensi.

## Akses

- Alamat dasar: `https://lms.arthasafetyindonesia.com/api/smm/`
- Metode: **GET** saja (metode lain → `405`)
- Header wajib: `Authorization: Bearer <token>`
  - Token disimpan di server LMS pada file `public_html/app/.env` → `SMM_API_TOKEN=...` (bukan di URL).
  - Token salah/kosong → `401 {"error":"Token tidak valid."}`; token belum dipasang di server → `503`.
- Parameter `sejak=YYYY-MM-DD` (opsional). Format salah → `400`. Tanpa `sejak` = semua data.
- Jawaban: JSON UTF-8 `{"data":[...]}`.

Contoh:

```bash
curl -H "Authorization: Bearer TOKEN_ANDA" "https://lms.arthasafetyindonesia.com/api/smm/survei?sejak=2026-11-01"
```

## 1. `GET /api/smm/kelas?sejak=YYYY-MM-DD`

Kelas (batch) yang **selesai pada/sesudah** `sejak`.

| Kolom | Isi |
|---|---|
| `kode_batch` | Kode batch, mis. `B42` (ID referensi) |
| `nama_pelatihan` | Nama pelatihan |
| `tgl_mulai`, `tgl_selesai` | `YYYY-MM-DD` |
| `jumlah_peserta` | Jumlah peserta aktif |
| `hadir` | Jumlah peserta yang hadir minimal 1 sesi |
| `lulus` | Jumlah peserta lulus (dari nilai ujian Kemnaker, atau data laporan); `null` bila belum ada |
| `rata_nilai` | Rata-rata nilai akhir peserta (nilai ujian Kemnaker); `null` bila belum ada |
| `trainer` | Daftar nama trainer/pemateri dari rundown |

## 2. `GET /api/smm/survei?sejak=YYYY-MM-DD`

Respons survei dengan `tanggal` ≥ `sejak`.

| Kolom | Isi |
|---|---|
| `id_respons` | ID unik, mis. `SRV-261106-AA5FE` |
| `kode_batch` | Kode batch |
| `jenis_survei` | `kepuasan_peserta` · `evaluasi_trainer` · `evaluasi_klien` |
| `tanggal` | `YYYY-MM-DD` |
| `skor` | Rata-rata semua aspek (1–5) |
| `aspek_<kode>` | Skor 1–5 per pertanyaan. Pertanyaan diatur Admin LMS, jadi kolom bisa bertambah. Bawaan: |
| | kepuasan_peserta: `aspek_materi`, `aspek_trainer`, `aspek_fasilitas`, `aspek_pelayanan` |
| | evaluasi_trainer: `aspek_penguasaan`, `aspek_penyampaian`, `aspek_interaksi`, `aspek_waktu` |
| | evaluasi_klien: `aspek_kesesuaian`, `aspek_trainer`, `aspek_pelayanan`, `aspek_administrasi` |
| `trainer` | Nama trainer yang dinilai (hanya `evaluasi_trainer`, selain itu `null`) |
| `komentar` | Komentar; No. HP, email, NIK, nomor panjang, dan nama peserta batch disamarkan otomatis |

Jawaban peserta anonim. Kode pertanyaan tidak pernah berubah; pertanyaan yang tidak dipakai lagi hanya dinonaktifkan.

## 3. `GET /api/smm/keluhan?sejak=YYYY-MM-DD`

| Kolom | Isi |
|---|---|
| `id_keluhan` | ID unik / nomor tiket, mis. `KLH-261106-4750B` |
| `kode_batch` | Kode batch, `null` bila pelapor tidak mengisi |
| `tanggal` | `YYYY-MM-DD` |
| `kategori` | `materi` · `trainer` · `fasilitas` · `pelayanan` · `jadwal` · `dokumen` · `lainnya` |
| `uraian` | Uraian keluhan (data pribadi yang tertulis disamarkan) |

Kontak pelapor hanya tersimpan di LMS. Bila perlu menghubungi pelapor, Admin SMM menanyakan nomor tiket ke Admin LMS.
