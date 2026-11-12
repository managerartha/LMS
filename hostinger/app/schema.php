<?php
/**
 * Struktur tabel database. Kolom pertama = primary key.
 * Nama tabel & kolom sama dengan versi Google Spreadsheet sebelumnya.
 */
const SCHEMA = [
  'batch' => ['id_batch', 'nama_pelatihan', 'kode_batch', 'lokasi_nama', 'tgl_mulai', 'tgl_selesai',
    'sesi1_mulai', 'sesi1_selesai', 'sesi2_mulai', 'sesi2_selesai', 'lat', 'lng', 'radius_meter',
    'lewati_weekend', 'pic_ids', 'folder', 'status', 'dibuat_pada', 'info_laporan', 'jenis_pelatihan', 'id_program', 'tanggal_custom', 'lokasi_bebas'],
  'pengguna' => ['id_user', 'id_batch', 'no_absen', 'nama', 'role', 'kode_login', 'pin_hash', 'instansi',
    'no_hp', 'device_id', 'setuju_privasi', 'ttd_id', 'aktif', 'dibuat_pada', 'tempat_lahir', 'tgl_lahir', 'hak_inventaris'],
  'token' => ['token', 'id_user', 'dibuat_pada', 'device_id'],
  'hari' => ['id_hari', 'id_batch', 'tanggal', 'hari_ke', 'lokasi_nama', 'lat', 'lng', 'radius_meter', 'diubah_oleh', 'lokasi_bebas'],
  'absensi' => ['id_absen', 'id_batch', 'id_hari', 'id_user', 'sesi', 'waktu_server', 'foto_id', 'ttd_id',
    'lat', 'lng', 'akurasi_meter', 'jarak_meter', 'status', 'keterangan', 'diubah_oleh', 'diubah_pada', 'alamat', 'perangkat'],
  'rundown' => ['id_rundown', 'id_batch', 'id_hari', 'urutan', 'jam_mulai', 'jam_selesai', 'kegiatan',
    'pemateri', 'keterangan', 'status', 'instansi'],
  'materi' => ['id_materi', 'id_batch', 'id_hari', 'judul', 'kategori', 'file_id', 'tipe_file', 'link',
    'tampil_mulai', 'diupload_oleh', 'diupload_pada'],
  'dokumentasi' => ['id_dok', 'id_batch', 'id_hari', 'id_rundown', 'file_id', 'keterangan', 'masuk_laporan',
    'diupload_oleh', 'diupload_pada', 'file_kecil_id'],
  'laporan' => ['id_laporan', 'id_batch', 'jenis', 'id_hari', 'file_id', 'dibuat_oleh', 'dibuat_pada', 'nama_file'],
  'skl' => ['id_skl', 'id_batch', 'id_user', 'nomor', 'urut', 'tahun', 'status', 'tgl_terbit', 'diubah_oleh', 'diubah_pada'],
  'absen_narasumber' => ['id_an', 'id_batch', 'id_hari', 'id_rundown', 'nama', 'instansi', 'materi', 'jam_mulai', 'jam_selesai',
    'ttd_id', 'waktu_server', 'dibuat_oleh'],
  'program' => ['id_program', 'nama', 'aktif', 'dibuat_pada'],
  'dokumen_program' => ['id_dp', 'id_program', 'skema', 'jenis', 'judul', 'file_id', 'tipe_file', 'link', 'diunggah_pada', 'diunggah_oleh'],
  'persetujuan' => ['id_setuju', 'id_user', 'id_dp', 'waktu'],
  'video' => ['id_video', 'judul', 'url', 'youtube_id', 'urutan', 'aktif', 'dibuat_pada'],
  'video_tonton' => ['id_tonton', 'id_user', 'id_video', 'waktu'],
  'perangkat' => ['id_perangkat', 'id_user', 'device_id', 'jenis', 'os', 'browser', 'pertama', 'terakhir'],
  // Inventaris: katalog barang, mutasi stok gudang (buku besar), barang per batch, status per peserta,
  // transaksi alat bersama, dan pengesahan berita acara.
  'inv_barang' => ['id_barang', 'nama', 'jenis', 'satuan', 'ukuran', 'stok_min', 'keterangan', 'aktif', 'dibuat_pada'],
  'inv_mutasi' => ['id_mutasi', 'id_barang', 'ukuran', 'jumlah', 'jenis', 'id_batch', 'catatan', 'oleh', 'waktu'],
  'inv_batch' => ['id_ib', 'id_batch', 'id_barang', 'mode', 'jumlah', 'rincian', 'saran', 'catatan', 'diubah_oleh', 'diubah_pada'],
  'inv_peserta' => ['id_ip', 'id_batch', 'id_ib', 'id_user', 'ukuran', 'status', 'kondisi', 'waktu_ambil', 'waktu_kembali', 'oleh', 'catatan'],
  'inv_transaksi' => ['id_tr', 'id_batch', 'id_ib', 'jenis', 'jumlah', 'baik', 'rusak', 'hilang', 'catatan', 'oleh', 'waktu'],
  'inv_ba' => ['id_batch', 'ditutup', 'ditutup_oleh', 'ditutup_waktu', 'ttd_pic', 'ttd_pic_waktu', 'ttd_gudang', 'ttd_gudang_waktu'],
  // Berkas peserta (persyaratan & asesmen) → Google Drive lembaga lewat jembatan Apps Script.
  'berkas_item' => ['id_item', 'id_program', 'kategori', 'nama', 'keterangan', 'wajib', 'format', 'maks_mb', 'urutan', 'aktif'],
  'berkas_batch' => ['id_batch', 'drive_link', 'drive_root', 'batas', 'riwayat_link', 'diubah_oleh', 'diubah_pada'],
  'berkas' => ['id_berkas', 'id_batch', 'id_user', 'id_item', 'file_id', 'nama_file', 'tipe', 'ukuran', 'diupload_pada',
    'status', 'catatan_pic', 'diverifikasi_oleh', 'diverifikasi_pada', 'drive_status', 'drive_file', 'drive_url', 'drive_root', 'drive_error', 'drive_waktu'],
  // Sertifikat pelatihan (BNSP, BST, HSE): topik HSE, pengaturan per batch, sertifikat per peserta per jenis.
  'sertifikat_topik' => ['id_topik', 'kode', 'judul', 'bahasa', 'isi_materi', 'urutan', 'aktif'],
  'sertifikat_batch' => ['id_batch', 'setelan', 'diubah_oleh', 'diubah_pada'],
  'sertifikat' => ['id_sertifikat', 'id_batch', 'id_user', 'jenis', 'daftar_nomor', 'status', 'tgl_terbit', 'diterbitkan_oleh', 'diubah_oleh', 'diubah_pada'],
  // Nilai ujian: template komponen per program, pengaturan per batch, nilai per peserta.
  'nilai_program' => ['id_program', 'komponen', 'batas_lulus', 'diubah_oleh', 'diubah_pada'],
  'nilai_batch' => ['id_batch', 'mode', 'komponen', 'batas_lulus', 'judul_rekap', 'catatan_rekap', 'terbit', 'terbit_oleh', 'terbit_pada',
    'file_bnsp', 'nama_file_bnsp', 'diubah_oleh', 'diubah_pada'],
  'nilai' => ['id_nilai', 'id_batch', 'id_user', 'isi_nilai', 'tidak_ikut', 'keterangan', 'diubah_oleh', 'diubah_pada'],
  'log' => ['id_log', 'waktu', 'id_user', 'nama', 'aksi', 'detail'],
  'pengaturan' => ['kunci', 'nilai'],
  'geo_cache' => ['kunci', 'alamat'],
];

/** Kolom berisi teks panjang (TEXT); kolom lain VARCHAR(191). */
/** Naikkan setiap kali SCHEMA berubah: tabel/kolom baru ditambahkan otomatis tanpa memasang ulang. */
const SCHEMA_VERSI = '10';

const SCHEMA_TEXT = ['komponen', 'catatan_rekap', 'isi_nilai', 'isi_materi', 'setelan', 'daftar_nomor', 'batas', 'riwayat_link', 'drive_error', 'drive_url', 'catatan_pic', 'rincian', 'tanggal_custom', 'url', 'info_laporan', 'alamat', 'keterangan', 'detail', 'nilai', 'link', 'kegiatan', 'judul', 'pic_ids'];

/** Index tambahan agar pencarian cepat. */
const SCHEMA_INDEX = [
  'pengguna' => [['id_batch'], ['kode_login']],
  'token' => [['id_user']],
  'hari' => [['id_batch', 'tanggal']],
  'absensi' => [['id_batch'], ['id_user']],
  'rundown' => [['id_hari'], ['id_batch']],
  'materi' => [['id_hari']],
  'dokumentasi' => [['id_hari'], ['id_batch']],
  'laporan' => [['id_batch']],
  'skl' => [['id_batch']],
  'absen_narasumber' => [['id_hari'], ['id_batch']],
  'dokumen_program' => [['id_program']],
  'persetujuan' => [['id_user']],
  'video_tonton' => [['id_user']],
  'perangkat' => [['id_user']],
  'inv_mutasi' => [['id_barang'], ['id_batch']],
  'inv_batch' => [['id_batch']],
  'inv_peserta' => [['id_ib'], ['id_batch']],
  'inv_transaksi' => [['id_ib']],
  'berkas_item' => [['id_program']],
  'berkas' => [['id_batch'], ['id_user']],
  'sertifikat' => [['id_batch']],
  'nilai' => [['id_batch']],
];

/** Satu absen per peserta per sesi per hari (mencegah absen dobel di tingkat database). */
const SCHEMA_UNIQUE = [
  'absensi' => ['id_hari', 'id_user', 'sesi'],
  'skl' => ['nomor'], // nomor SKL tidak boleh kembar
  'persetujuan' => ['id_user', 'id_dp'],
  'video_tonton' => ['id_user', 'id_video'],
  'inv_peserta' => ['id_ib', 'id_user'],
  'berkas' => ['id_user', 'id_item'],
  'sertifikat' => ['id_user', 'jenis'],
];

/** Perintah CREATE TABLE untuk MySQL (atau SQLite saat pengujian). */
function schema_sql(string $driver): array {
  $sql = [];
  foreach (SCHEMA as $table => $cols) {
    $defs = [];
    foreach ($cols as $i => $c) {
      $type = in_array($c, SCHEMA_TEXT, true) ? 'TEXT' : 'VARCHAR(191)';
      $defs[] = "`$c` $type" . ($i === 0 ? ' NOT NULL PRIMARY KEY' : ' NULL');
    }
    if (isset(SCHEMA_UNIQUE[$table])) {
      $defs[] = 'UNIQUE (' . implode(', ', array_map(fn($c) => "`$c`", SCHEMA_UNIQUE[$table])) . ')';
    }
    $sql[] = "CREATE TABLE IF NOT EXISTS `$table` (" . implode(', ', $defs) . ')' .
      ($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '');
    foreach (SCHEMA_INDEX[$table] ?? [] as $idx) {
      $name = 'idx_' . $table . '_' . implode('_', $idx);
      $cols = implode(', ', array_map(fn($c) => "`$c`", $idx));
      $sql[] = $driver === 'mysql'
        ? "CREATE INDEX `$name` ON `$table` ($cols)"
        : "CREATE INDEX IF NOT EXISTS `$name` ON `$table` ($cols)";
    }
  }
  return $sql;
}
