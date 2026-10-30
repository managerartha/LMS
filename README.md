# LMS Artha Safety Indonesia

Learning Management System untuk peserta pelatihan Artha Safety Indonesia:
absensi 2 sesi/hari (foto + timestamp + tanda tangan + lokasi GPS), rundown, materi,
dokumentasi kegiatan, dan laporan kegiatan resmi otomatis dalam bentuk PDF.

**Versi utama: Hostinger (PHP + MySQL)**: tampilan HTML/CSS/JS dan server PHP di satu hosting,
data di MySQL, file di hosting sendiri. Jauh lebih cepat daripada versi Google Apps Script.

| Bagian | Isi |
|---|---|
| [docs/PANDUAN-HOSTINGER.md](docs/PANDUAN-HOSTINGER.md) | **Cara memasang di Hostinger, langkah demi langkah** |
| [docs/PANDUAN-SETUP.md](docs/PANDUAN-SETUP.md) | Cara memakai LMS (PIC/Admin/peserta); bagian A–B untuk versi Apps Script lama |
| [docs/KONSEP.md](docs/KONSEP.md) | Konsep, fitur, struktur data |
| [web/](web) | Tampilan LMS (HTML/CSS/JS) |
| [hostinger/](hostinger) | Server PHP (API, installer, laporan PDF dompdf) |
| `tools/build-hostinger.sh` | Menyusun `dist/lms-hostinger.zip` (tampilan + server + pustaka PDF) siap upload |
| [apps-script/](apps-script) | Versi lama (Google Apps Script + Spreadsheet + Drive), tidak dikembangkan lagi |
