<?php
/** Konten harian: rundown, materi, dan dokumentasi kegiatan. */

const MATERI_EXT = ['pdf', 'ppt', 'pptx', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png'];
const MATERI_MAX_MB = 20;

/** Alamat dasar LMS, mis. https://lms.arthasafetyindonesia.com/ (untuk penampil dokumen Office). */
function base_url(): string {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  $dir = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/index.php'))), '/');
  return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

/** Hari yang boleh diakses: PIC batch tersebut, atau peserta di batch tersebut. */
function hari_for_user(array $user, string $idHari): array {
  $hari = get_hari($idHari);
  if (is_pic($user)) batch_for_pic($user, $hari['id_batch']);
  elseif ($user['id_batch'] !== $hari['id_batch']) throw new LmsError('Data ini bukan untuk batch Anda.');
  return $hari;
}

function folder_konten(array $batch, string $jenis, array $hari): string {
  return 'uploads/' . $batch['folder'] . '/' . $jenis . '/hari-' . $hari['hari_ke'];
}

// ---------------------------------------------------------------- Rundown

function rundown_hari(string $idHari): array {
  $list = rows('SELECT * FROM rundown WHERE id_hari = ?', [$idHari]);
  usort($list, fn($a, $b) => (int)$a['urutan'] <=> (int)$b['urutan']);
  return $list;
}

function rundown_view(array $r): array {
  return ['id_rundown' => $r['id_rundown'], 'urutan' => $r['urutan'], 'jam_mulai' => $r['jam_mulai'], 'jam_selesai' => $r['jam_selesai'],
    'kegiatan' => $r['kegiatan'], 'pemateri' => $r['pemateri'], 'instansi' => $r['instansi'], 'keterangan' => $r['keterangan']];
}

function rundownGet(array $data, array $user): array {
  $hari = hari_for_user($user, (string)($data['id_hari'] ?? ''));
  $list = rundown_hari($hari['id_hari']);
  $terbit = (bool)array_filter($list, fn($r) => $r['status'] === 'terbit');
  return ['status' => !$list ? 'kosong' : ($terbit ? 'terbit' : 'draft'), 'items' => array_map('rundown_view', $list)];
}

/** Menyimpan seluruh rundown satu hari sekaligus (menggantikan yang lama). status: 'draft' | 'terbit'. */
function rundownSimpan(array $data, array $user): array {
  $hari = hari_for_user($user, (string)($data['id_hari'] ?? ''));
  $status = ($data['status'] ?? '') === 'terbit' ? 'terbit' : 'draft';
  $items = [];
  foreach (($data['items'] ?? []) as $i => $it) {
    $x = ['jam_mulai' => trim((string)($it['jam_mulai'] ?? '')), 'jam_selesai' => trim((string)($it['jam_selesai'] ?? '')),
      'kegiatan' => trim((string)($it['kegiatan'] ?? '')), 'pemateri' => trim((string)($it['pemateri'] ?? '')),
      'instansi' => trim((string)($it['instansi'] ?? '')), 'keterangan' => trim((string)($it['keterangan'] ?? '')), '_i' => $i];
    if ($x['kegiatan'] !== '' || $x['jam_mulai'] !== '' || $x['jam_selesai'] !== '') $items[] = $x;
  }
  foreach ($items as $i => $it) {
    $no = 'Baris ' . ($i + 1) . ': ';
    if ($it['kegiatan'] === '') throw new LmsError($no . 'nama kegiatan wajib diisi.');
    if (!is_time($it['jam_mulai']) || !is_time($it['jam_selesai'])) throw new LmsError($no . 'jam mulai/selesai wajib diisi (JJ:MM).');
    if ($it['jam_selesai'] <= $it['jam_mulai']) throw new LmsError($no . 'jam selesai harus setelah jam mulai.');
  }
  usort($items, fn($a, $b) => strcmp($a['jam_mulai'], $b['jam_mulai']) ?: $a['_i'] <=> $b['_i']);

  $pdo = db();
  $pdo->beginTransaction();
  try {
    $lama = rundown_hari($hari['id_hari']);
    // Pertahankan id kegiatan yang sama agar foto dokumentasi yang terkait tidak kehilangan kaitannya.
    $sisa = array_column($lama, null, 'id_rundown');
    q('DELETE FROM rundown WHERE id_hari = ?', [$hari['id_hari']]);
    foreach ($items as $i => $it) {
      $id = null;
      foreach ($sisa as $rid => $r) if ($r['kegiatan'] === $it['kegiatan']) { $id = $rid; unset($sisa[$rid]); break; }
      insert('rundown', ['id_rundown' => $id ?? new_id('r'), 'id_batch' => $hari['id_batch'], 'id_hari' => $hari['id_hari'], 'urutan' => $i + 1,
        'jam_mulai' => $it['jam_mulai'], 'jam_selesai' => $it['jam_selesai'], 'kegiatan' => $it['kegiatan'], 'pemateri' => $it['pemateri'],
        'instansi' => $it['instansi'], 'keterangan' => $it['keterangan'], 'status' => $status]);
    }
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
  log_($user, 'rundown_' . $status, 'hari ' . $hari['hari_ke'] . ': ' . count($items) . ' kegiatan');
  return rundownGet(['id_hari' => $hari['id_hari']], $user);
}

/** Menyalin rundown dari hari lain sebagai draft (bisa dari batch lain milik PIC yang sama). */
function rundownSalin(array $data, array $user): array {
  $asal = hari_for_user($user, (string)($data['id_hari_asal'] ?? ''));
  $items = array_map('rundown_view', rundown_hari($asal['id_hari']));
  if (!$items) throw new LmsError('Rundown hari asal masih kosong.');
  return rundownSimpan(['id_hari' => $data['id_hari'] ?? '', 'items' => $items, 'status' => 'draft'], $user);
}

// ---------------------------------------------------------------- Materi

function materi_view(array $m): array {
  $file = $m['file_id'] !== '';
  $url = $file ? url_publik($m['file_id']) : $m['link'];
  // Dokumen Office dibuka lewat penampil online Microsoft agar bisa dilihat di HP tanpa aplikasi.
  $office = $file && in_array($m['tipe_file'], ['ppt', 'pptx', 'doc', 'docx', 'xls', 'xlsx'], true);
  return ['id_materi' => $m['id_materi'], 'judul' => $m['judul'], 'kategori' => $m['kategori'], 'tipe_file' => $m['tipe_file'],
    'tampil_mulai' => $m['tampil_mulai'],
    'buka' => $office ? 'https://view.officeapps.live.com/op/view.aspx?src=' . rawurlencode(base_url() . $url) : $url,
    'unduh' => $file ? 'api/unduh.php?id=' . rawurlencode($m['id_materi']) : ''];
}

function materi_hari(string $idHari): array {
  $list = rows('SELECT * FROM materi WHERE id_hari = ?', [$idHari]);
  usort($list, fn($a, $b) => strcmp($a['kategori'], $b['kategori']) ?: strcmp($a['diupload_pada'], $b['diupload_pada']));
  return $list;
}

/** Materi tampil ke peserta mulai tanggal harinya, dan mulai jam tampil_mulai jika diisi. */
function materi_terlihat(array $m, array $hari, array $now): bool {
  if ($hari['tanggal'] < $now['date']) return true;
  if ($hari['tanggal'] > $now['date']) return false;
  return $m['tampil_mulai'] === '' || $now['time'] >= $m['tampil_mulai'];
}

function materiList(array $data, array $user): array {
  $hari = hari_for_user($user, (string)($data['id_hari'] ?? ''));
  return array_map('materi_view', materi_hari($hari['id_hari']));
}

/** Menambah materi berupa file ({nama, data: data URL base64}) atau link (https://...). */
function materiTambah(array $data, array $user): array {
  $hari = hari_for_user($user, (string)($data['id_hari'] ?? ''));
  $batch = get_batch($hari['id_batch']);
  $kategori = ($data['kategori'] ?? '') === 'latihan' ? 'latihan' : 'materi';
  $tampil = (string)($data['tampil_mulai'] ?? '');
  if ($tampil !== '' && !is_time($tampil)) throw new LmsError('Jam tampil tidak valid (JJ:MM).');
  $fileId = '';
  $link = '';
  $tipe = 'link';
  $judul = trim((string)($data['judul'] ?? ''));
  if (!empty($data['file'])) {
    $nama = (string)($data['file']['nama'] ?? 'materi');
    $tipe = strtolower(pathinfo($nama, PATHINFO_EXTENSION));
    if (!in_array($tipe, MATERI_EXT, true)) throw new LmsError('Jenis file tidak didukung. Gunakan: ' . implode(', ', MATERI_EXT));
    if (!preg_match('#^data:([^;]*);base64,(.+)$#s', (string)($data['file']['data'] ?? ''), $m)) throw new LmsError('File tidak terbaca. Coba pilih ulang file.');
    $bytes = base64_decode($m[2], true);
    if ($bytes === false) throw new LmsError('File tidak terbaca. Coba pilih ulang file.');
    if (strlen($bytes) > MATERI_MAX_MB * 1024 * 1024) {
      throw new LmsError('File lebih dari ' . MATERI_MAX_MB . ' MB. Simpan sebagai PDF atau upload ke Google Drive lalu gunakan link.');
    }
    $fileId = simpan_bytes(folder_konten($batch, 'materi', $hari), $bytes, $nama);
    if ($judul === '') $judul = pathinfo($nama, PATHINFO_FILENAME);
  } else {
    $link = trim((string)($data['link'] ?? ''));
    if (!preg_match('#^https://\S+$#', $link)) throw new LmsError('Pilih file atau isi link yang diawali https://');
    if ($judul === '') throw new LmsError('Judul wajib diisi untuk materi berupa link.');
  }
  $m = insert('materi', ['id_materi' => new_id('m'), 'id_batch' => $hari['id_batch'], 'id_hari' => $hari['id_hari'], 'judul' => $judul,
    'kategori' => $kategori, 'file_id' => $fileId, 'tipe_file' => $tipe, 'link' => $link, 'tampil_mulai' => $tampil,
    'diupload_oleh' => $user['nama'], 'diupload_pada' => now_()['stamp']]);
  log_($user, 'materi_tambah', 'hari ' . $hari['hari_ke'] . ': ' . $judul);
  return materi_view($m);
}

function materiHapus(array $data, array $user): bool {
  $m = row('SELECT * FROM materi WHERE id_materi = ?', [(string)($data['id_materi'] ?? '')]);
  if (!$m) throw new LmsError('Materi tidak ditemukan.');
  hari_for_user($user, $m['id_hari']);
  hapus_file($m['file_id']);
  hapus('materi', $m);
  log_($user, 'materi_hapus', $m['judul']);
  return true;
}

// ---------------------------------------------------------------- Dokumentasi

function dok_view(array $d): array {
  return ['id_dok' => $d['id_dok'], 'id_rundown' => $d['id_rundown'], 'keterangan' => $d['keterangan'],
    'masuk_laporan' => $d['masuk_laporan'] === 'ya',
    'thumb' => url_publik($d['file_kecil_id'] !== '' ? $d['file_kecil_id'] : $d['file_id']), 'buka' => url_publik($d['file_id'])];
}

function dok_hari(string $idHari): array {
  $list = rows('SELECT * FROM dokumentasi WHERE id_hari = ?', [$idHari]);
  usort($list, fn($a, $b) => strcmp($a['diupload_pada'], $b['diupload_pada']) ?: strcmp($a['id_dok'], $b['id_dok']));
  return $list;
}

function dokList(array $data, array $user): array {
  $hari = hari_for_user($user, (string)($data['id_hari'] ?? ''));
  return array_map('dok_view', dok_hari($hari['id_hari']));
}

/** Menambah satu foto dokumentasi (sudah dikompres di browser) + versi kecil untuk galeri & laporan. */
function dokTambah(array $data, array $user): array {
  $hari = hari_for_user($user, (string)($data['id_hari'] ?? ''));
  $batch = get_batch($hari['id_batch']);
  $idRundown = (string)($data['id_rundown'] ?? '');
  if ($idRundown !== '' && !row('SELECT id_rundown FROM rundown WHERE id_rundown = ? AND id_hari = ?', [$idRundown, $hari['id_hari']])) {
    throw new LmsError('Kegiatan rundown tidak ditemukan.');
  }
  $dir = folder_konten($batch, 'dokumentasi', $hari);
  $nama = 'H' . $hari['hari_ke'] . '_dok.jpg';
  $fileId = simpan_data_url($dir, (string)($data['foto'] ?? ''), $nama);
  $kecil = !empty($data['foto_kecil']) ? simpan_data_url($dir . '/kecil', (string)$data['foto_kecil'], $nama) : '';
  $d = insert('dokumentasi', ['id_dok' => new_id('d'), 'id_batch' => $hari['id_batch'], 'id_hari' => $hari['id_hari'], 'id_rundown' => $idRundown,
    'file_id' => $fileId, 'keterangan' => trim((string)($data['keterangan'] ?? '')), 'masuk_laporan' => 'ya',
    'diupload_oleh' => $user['nama'], 'diupload_pada' => now_()['stamp'], 'file_kecil_id' => $kecil]);
  return dok_view($d);
}

function dok_for_pic(array $user, string $idDok): array {
  $d = row('SELECT * FROM dokumentasi WHERE id_dok = ?', [$idDok]);
  if (!$d) throw new LmsError('Foto tidak ditemukan.');
  hari_for_user($user, $d['id_hari']);
  return $d;
}

function dokUbah(array $data, array $user): array {
  $d = dok_for_pic($user, (string)($data['id_dok'] ?? ''));
  $changes = [];
  if (array_key_exists('keterangan', $data)) $changes['keterangan'] = trim((string)$data['keterangan']);
  if (array_key_exists('masuk_laporan', $data)) $changes['masuk_laporan'] = $data['masuk_laporan'] ? 'ya' : 'tidak';
  if (array_key_exists('id_rundown', $data)) $changes['id_rundown'] = (string)$data['id_rundown'];
  return dok_view(update('dokumentasi', $d, $changes));
}

function dokHapus(array $data, array $user): bool {
  $d = dok_for_pic($user, (string)($data['id_dok'] ?? ''));
  hapus_file($d['file_id']);
  hapus_file($d['file_kecil_id']);
  hapus('dokumentasi', $d);
  log_($user, 'dok_hapus', $d['id_dok']);
  return true;
}

// ---------------------------------------------------------------- Tampilan peserta

/** Rundown terbit (semua hari), materi yang sudah waktunya tampil, dan dokumentasi sampai hari ini. */
function kontenPeserta(array $data, array $user): array {
  wajib_peserta($user);
  $batch = get_batch($user['id_batch']);
  $now = now_();
  return ['tanggal' => $now['date'], 'jam' => $now['time'], 'hari' => array_map(fn($h) => [
    'id_hari' => $h['id_hari'], 'hari_ke' => $h['hari_ke'], 'tanggal' => $h['tanggal'],
    'rundown' => array_map('rundown_view', array_values(array_filter(rundown_hari($h['id_hari']), fn($r) => $r['status'] === 'terbit'))),
    'materi' => array_map('materi_view', array_values(array_filter(materi_hari($h['id_hari']), fn($m) => materi_terlihat($m, $h, $now)))),
    'dok' => $h['tanggal'] <= $now['date'] ? array_map('dok_view', dok_hari($h['id_hari'])) : [],
  ], hari_batch($batch['id_batch']))];
}
