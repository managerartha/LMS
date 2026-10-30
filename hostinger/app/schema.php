<?php
/**
 * Struktur tabel database. Kolom pertama = primary key.
 * Nama tabel & kolom sama dengan versi Google Spreadsheet sebelumnya.
 */
const SCHEMA = [
  'batch' => ['id_batch', 'nama_pelatihan', 'kode_batch', 'lokasi_nama', 'tgl_mulai', 'tgl_selesai',
    'sesi1_mulai', 'sesi1_selesai', 'sesi2_mulai', 'sesi2_selesai', 'lat', 'lng', 'radius_meter',
    'lewati_weekend', 'pic_ids', 'folder', 'status', 'dibuat_pada', 'info_laporan'],
  'pengguna' => ['id_user', 'id_batch', 'no_absen', 'nama', 'role', 'kode_login', 'pin_hash', 'instansi',
    'no_hp', 'device_id', 'setuju_privasi', 'ttd_id', 'aktif', 'dibuat_pada'],
  'token' => ['token', 'id_user', 'dibuat_pada'],
  'hari' => ['id_hari', 'id_batch', 'tanggal', 'hari_ke', 'lokasi_nama', 'lat', 'lng', 'radius_meter', 'diubah_oleh'],
  'absensi' => ['id_absen', 'id_batch', 'id_hari', 'id_user', 'sesi', 'waktu_server', 'foto_id', 'ttd_id',
    'lat', 'lng', 'akurasi_meter', 'jarak_meter', 'status', 'keterangan', 'diubah_oleh', 'diubah_pada', 'alamat'],
  'rundown' => ['id_rundown', 'id_batch', 'id_hari', 'urutan', 'jam_mulai', 'jam_selesai', 'kegiatan',
    'pemateri', 'keterangan', 'status', 'instansi'],
  'materi' => ['id_materi', 'id_batch', 'id_hari', 'judul', 'kategori', 'file_id', 'tipe_file', 'link',
    'tampil_mulai', 'diupload_oleh', 'diupload_pada'],
  'dokumentasi' => ['id_dok', 'id_batch', 'id_hari', 'id_rundown', 'file_id', 'keterangan', 'masuk_laporan',
    'diupload_oleh', 'diupload_pada', 'file_kecil_id'],
  'laporan' => ['id_laporan', 'id_batch', 'jenis', 'id_hari', 'file_id', 'dibuat_oleh', 'dibuat_pada', 'nama_file'],
  'log' => ['id_log', 'waktu', 'id_user', 'nama', 'aksi', 'detail'],
  'pengaturan' => ['kunci', 'nilai'],
  'geo_cache' => ['kunci', 'alamat'],
];

/** Kolom berisi teks panjang (TEXT); kolom lain VARCHAR(191). */
const SCHEMA_TEXT = ['info_laporan', 'alamat', 'keterangan', 'detail', 'nilai', 'link', 'kegiatan', 'judul', 'pic_ids'];

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
];

/** Satu absen per peserta per sesi per hari (mencegah absen dobel di tingkat database). */
const SCHEMA_UNIQUE = [
  'absensi' => ['id_hari', 'id_user', 'sesi'],
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
