<?php
/**
 * Penyimpanan hemat: file besar disimpan di Google Drive, bukan di hosting.
 * - Materi & foto dokumentasi → Drive akun Artha "LMS Artha/<batch>/…" (bisa dilihat siapa saja yang punya link → cepat untuk peserta).
 * - Laporan PDF → Drive akun Artha (tidak dibagikan; dibuka Admin dengan akun Artha).
 * - Berkas peserta → sudah dikirim ke Drive lembaga; salinan di hosting dihapus setelah berhasil terkirim (lihat berkas.php).
 * - Foto absen & tanda tangan tetap di hosting (kecil, dibutuhkan cepat untuk monitor & laporan).
 * File di hosting baru dihapus SETELAH dipastikan tersimpan di Drive. Gagal kirim → file tetap di hosting (dicoba lagi nanti).
 */

const DRIVE_ROOT_LMS = 'LMS Artha';

function folder_drive_batch(array $b): string {
  return trim(preg_replace('/\s+/', ' ', preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $b['kode_batch'] . ' - ' . $b['nama_pelatihan'])));
}

function mime_ext(string $ext): string {
  return MIME_BERKAS[$ext] ?? (['gif' => 'image/gif', 'webp' => 'image/webp', 'mp4' => 'video/mp4', 'txt' => 'text/plain'][$ext] ?? 'application/octet-stream');
}

/** Kirim file lokal ke Drive akun Artha. Mengembalikan ['id','url']; gagal → LmsError (file lokal tidak disentuh). */
function kirim_ke_drive_artha(string $rel, array $path, string $nama, bool $publik): array {
  $f = ROOT . '/' . $rel;
  if ($rel === '' || str_contains($rel, '..') || !is_file($f)) throw new LmsError('File di hosting tidak ditemukan.');
  if (pengaturan('drive_jembatan_url') === '') throw new LmsError('Jembatan Google Drive belum dipasang.');
  $j = jembatan(['action' => 'simpan', 'path' => array_merge([DRIVE_ROOT_LMS], $path), 'name' => $nama,
    'mime' => mime_ext(strtolower(pathinfo($nama, PATHINFO_EXTENSION))), 'data' => base64_encode((string)file_get_contents($f)), 'publik' => $publik], 150);
  if (empty($j['id'])) throw new LmsError('Jembatan Google Drive perlu diperbarui ke versi terbaru (jembatan-3).');
  return ['id' => (string)$j['id'], 'url' => (string)($j['url'] ?? '')];
}

function nama_file_drive(string $judul, string $ext): string {
  $j = trim(preg_replace('/\s+/', ' ', preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $judul))) ?: 'file';
  return mb_substr($j, 0, 150) . ($ext !== '' ? '.' . $ext : '');
}

/** Materi → Drive (publik dengan link), lalu salinan di hosting dihapus. */
function pindah_materi(string $id): bool {
  $m = row('SELECT * FROM materi WHERE id_materi = ?', [$id]);
  if (!$m || $m['file_id'] === '' || ($m['drive_id'] ?? '') !== '') return true;
  try {
    $b = get_batch($m['id_batch']);
    $h = get_hari($m['id_hari']);
    $d = kirim_ke_drive_artha($m['file_id'], [folder_drive_batch($b), 'Materi', 'Hari-' . $h['hari_ke']], nama_file_drive($m['judul'], $m['tipe_file']), true);
    $rel = $m['file_id'];
    update('materi', $m, ['drive_id' => $d['id'], 'drive_url' => $d['url'], 'file_id' => '']);
    // file yang sama bisa dipakai materi lain (duplikat batch lama) → hapus hanya jika tidak dipakai lagi
    if (!row('SELECT id_materi FROM materi WHERE file_id = ? LIMIT 1', [$rel])) hapus_file($rel);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

/** Laporan PDF → Drive akun Artha (tidak dibagikan), lalu salinan di hosting dihapus. */
function pindah_laporan(string $id): bool {
  $r = row('SELECT * FROM laporan WHERE id_laporan = ?', [$id]);
  if (!$r || $r['file_id'] === '' || ($r['drive_id'] ?? '') !== '') return true;
  try {
    $b = get_batch($r['id_batch']);
    $nama = $r['nama_file'] !== '' ? $r['nama_file'] : basename($r['file_id']);
    $d = kirim_ke_drive_artha($r['file_id'], [folder_drive_batch($b), 'Laporan'],
      pathinfo($nama, PATHINFO_FILENAME) . ' (' . substr(str_replace(':', '.', $r['dibuat_pada']), 0, 16) . ').pdf', false);
    $rel = $r['file_id'];
    update('laporan', $r, ['drive_id' => $d['id'], 'drive_url' => $d['url'], 'file_id' => '']);
    hapus_file($rel);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

/** Foto dokumentasi besar → Drive (publik dengan link). Versi kecil tetap di hosting untuk galeri & laporan. */
function pindah_dok(string $id): bool {
  $d = row('SELECT * FROM dokumentasi WHERE id_dok = ?', [$id]);
  if (!$d || $d['file_id'] === '' || $d['file_kecil_id'] === '' || ($d['drive_id'] ?? '') !== '') return true;
  try {
    $b = get_batch($d['id_batch']);
    $h = get_hari($d['id_hari']);
    $x = kirim_ke_drive_artha($d['file_id'], [folder_drive_batch($b), 'Dokumentasi', 'Hari-' . $h['hari_ke']], 'H' . $h['hari_ke'] . '_' . $d['id_dok'] . '.jpg', true);
    $rel = $d['file_id'];
    update('dokumentasi', $d, ['drive_id' => $x['id'], 'drive_url' => $x['url'], 'file_id' => '']);
    hapus_file($rel);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

/** Batch yang sudah selesai (foto dokumentasinya boleh dipindah). */
function batch_sudah_selesai(array $b): bool {
  return $b['status'] !== 'aktif' || $b['tgl_selesai'] < now_()['date'];
}

/** Antrean file yang bisa dipindah/dihapus dari hosting untuk satu batch. */
function antrean_pindah(array $b): array {
  $id = $b['id_batch'];
  return [
    'berkas' => rows("SELECT id_berkas AS id FROM berkas WHERE id_batch = ? AND file_id <> '' AND drive_status = 'terkirim' AND drive_file <> ''", [$id]),
    'berkas_file' => rows("SELECT id_file AS id FROM berkas_file WHERE id_batch = ? AND file_id <> '' AND drive_status = 'terkirim' AND drive_file <> ''", [$id]),
    'materi' => rows("SELECT id_materi AS id FROM materi WHERE id_batch = ? AND file_id <> '' AND (drive_id IS NULL OR drive_id = '')", [$id]),
    'laporan' => rows("SELECT id_laporan AS id FROM laporan WHERE id_batch = ? AND file_id <> '' AND (drive_id IS NULL OR drive_id = '')", [$id]),
    'dok' => batch_sudah_selesai($b) ? rows("SELECT id_dok AS id FROM dokumentasi WHERE id_batch = ? AND file_id <> '' AND file_kecil_id <> '' AND (drive_id IS NULL OR drive_id = '')", [$id]) : [],
  ];
}

function ukuran_folder(string $dir): int {
  if (!is_dir($dir)) return 0;
  $n = 0;
  try {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) $n += $f->getSize();
  } catch (Throwable $e) { /* folder tidak terbaca */ }
  return $n;
}

/** Admin: ukuran penyimpanan LMS per batch dan jumlah file yang bisa dipindah ke Drive. */
function penyimpananInfo(array $data, array $user): array {
  $out = [];
  foreach (rows('SELECT * FROM batch ORDER BY tgl_mulai DESC') as $b) {
    $q = antrean_pindah($b);
    $out[] = ['id_batch' => $b['id_batch'], 'kode_batch' => $b['kode_batch'], 'nama_pelatihan' => $b['nama_pelatihan'], 'status' => $b['status'],
      'selesai' => batch_sudah_selesai($b),
      'ukuran' => ukuran_folder(ROOT . '/storage/' . $b['folder']) + ukuran_folder(ROOT . '/uploads/' . $b['folder']),
      'antrean' => array_map('count', $q), 'total_antrean' => array_sum(array_map('count', $q))];
  }
  return ['batch' => $out, 'storage' => ukuran_folder(STORAGE), 'uploads' => ukuran_folder(ROOT . '/uploads'), 'jembatan' => pengaturan('drive_jembatan_url') !== ''];
}

/**
 * Admin: pindahkan file satu batch ke Drive secara bertahap (±20 detik per permintaan; tampilan mengulang sampai selesai).
 * Salinan berkas yang sudah ada di Drive lembaga cukup dihapus dari hosting.
 */
function penyimpananPindah(array $data, array $user): array {
  $b = get_batch((string)($data['id_batch'] ?? ''));
  @set_time_limit(120);
  $batas = microtime(true) + 20;
  $n = ['dipindah' => 0, 'gagal' => 0];
  $gagalId = array_flip((array)($data['lewati'] ?? []));
  foreach (antrean_pindah($b) as $jenis => $list) {
    foreach ($list as $r) {
      if (microtime(true) > $batas) break 2;
      if (isset($gagalId[$r['id']])) continue;
      $ok = match ($jenis) {
        'berkas' => hapus_salinan_berkas($r['id']),
        'berkas_file' => hapus_salinan_file($r['id']),
        'materi' => pindah_materi($r['id']),
        'laporan' => pindah_laporan($r['id']),
        'dok' => pindah_dok($r['id']),
      };
      if ($ok) $n['dipindah']++;
      else { $n['gagal']++; $gagalId[$r['id']] = 1; }
    }
  }
  $ids = array_column(array_merge(...array_values(antrean_pindah($b))), 'id');
  $sisa = count(array_diff($ids, array_keys($gagalId)));
  if ($n['dipindah']) log_($user, 'penyimpanan_pindah', $b['kode_batch'] . ': ' . $n['dipindah'] . ' file');
  return $n + ['sisa' => max(0, $sisa), 'lewati' => array_keys($gagalId),
    'ukuran' => ukuran_folder(ROOT . '/storage/' . $b['folder']) + ukuran_folder(ROOT . '/uploads/' . $b['folder'])];
}

/** Salinan berkas (1 file) yang sudah terkirim ke Drive lembaga dihapus dari hosting. */
function hapus_salinan_berkas(string $id): bool {
  $b = row('SELECT * FROM berkas WHERE id_berkas = ?', [$id]);
  if (!$b || $b['file_id'] === '' || $b['drive_status'] !== 'terkirim' || $b['drive_file'] === '') return true;
  hapus_file($b['file_id']);
  update('berkas', $b, ['file_id' => '']);
  return true;
}

function hapus_salinan_file(string $id): bool {
  $f = row('SELECT * FROM berkas_file WHERE id_file = ?', [$id]);
  if (!$f || $f['file_id'] === '' || $f['drive_status'] !== 'terkirim' || $f['drive_file'] === '') return true;
  hapus_file($f['file_id']);
  update('berkas_file', $f, ['file_id' => '']);
  return true;
}
