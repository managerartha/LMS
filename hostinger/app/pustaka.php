<?php
/**
 * Pustaka pelatihan (diatur Admin, berlaku lintas batch):
 * - Program pelatihan: pengelompok batch sejenis (mis. Petugas P3K).
 * - Tata tertib & guideline per program dan skema (Offline/Online/Blended/semua). Upload sekali, tampil otomatis
 *   di semua batch program itu. Revisi berlaku untuk batch yang mulai sejak tanggal revisi; batch yang sudah
 *   berjalan tetap memakai versi sebelumnya.
 * - Video pengarahan (YouTube) untuk semua peserta, dengan catatan sudah ditonton.
 * Peserta menyetujui tata tertib/guideline lewat tombol, dan PIC melihat rekapnya per batch.
 */

const SKEMA_DOKUMEN = ['semua', 'Offline', 'Online', 'Blended'];
const JENIS_DOKUMEN = ['tata_tertib' => 'Tata Tertib', 'guideline' => 'Guideline Pelatihan'];

// ---------------------------------------------------------------- Program pelatihan

function programList(array $data, array $user): array {
  $n = [];
  foreach (rows("SELECT id_program, COUNT(*) AS n FROM batch WHERE id_program <> '' GROUP BY id_program") as $r) $n[$r['id_program']] = (int)$r['n'];
  return array_map(fn($p) => ['id_program' => $p['id_program'], 'nama' => $p['nama'], 'aktif' => $p['aktif'] !== 'tidak',
    'jumlah_batch' => $n[$p['id_program']] ?? 0], rows('SELECT * FROM program ORDER BY nama'));
}

/** Tambah (tanpa id_program) atau ubah nama / status aktif program. */
function programSimpan(array $data, array $user): array {
  $nama = preg_replace('/\s+/', ' ', trim((string)($data['nama'] ?? '')));
  if ($nama === '') throw new LmsError('Nama program wajib diisi.');
  $id = (string)($data['id_program'] ?? '');
  if (row('SELECT id_program FROM program WHERE LOWER(nama) = ? AND id_program <> ?', [mb_strtolower($nama), $id])) {
    throw new LmsError('Program "' . $nama . '" sudah ada.');
  }
  if ($id === '') {
    $p = insert('program', ['id_program' => new_id('p'), 'nama' => mb_substr($nama, 0, 150), 'aktif' => 'ya', 'dibuat_pada' => now_()['stamp']]);
  } else {
    $p = row('SELECT * FROM program WHERE id_program = ?', [$id]);
    if (!$p) throw new LmsError('Program tidak ditemukan.');
    $p = update('program', $p, ['nama' => mb_substr($nama, 0, 150), 'aktif' => array_key_exists('aktif', $data) && !$data['aktif'] ? 'tidak' : 'ya']);
  }
  log_($user, 'program_simpan', $p['nama']);
  return $p;
}

// ---------------------------------------------------------------- Tata tertib & guideline

function dp_view(array $d): array {
  $file = $d['file_id'] !== '';
  $url = $file ? url_publik($d['file_id']) : $d['link'];
  $office = $file && in_array($d['tipe_file'], ['ppt', 'pptx', 'doc', 'docx', 'xls', 'xlsx'], true);
  return ['id_dp' => $d['id_dp'], 'id_program' => $d['id_program'], 'skema' => $d['skema'], 'jenis' => $d['jenis'],
    'jenis_label' => JENIS_DOKUMEN[$d['jenis']] ?? $d['jenis'], 'judul' => $d['judul'], 'tipe_file' => $d['tipe_file'],
    'diunggah_pada' => $d['diunggah_pada'], 'diunggah_oleh' => $d['diunggah_oleh'],
    'buka' => $office ? 'https://view.officeapps.live.com/op/view.aspx?src=' . rawurlencode(base_url() . $url) : $url,
    'unduh' => $file ? 'api/unduh.php?dp=' . rawurlencode($d['id_dp']) : ''];
}

/** Semua dokumen (semua versi) untuk menu Admin, terbaru dulu. */
function pustakaDokList(array $data, array $user): array {
  return array_map('dp_view', rows('SELECT * FROM dokumen_program ORDER BY diunggah_pada DESC, id_dp DESC'));
}

/** Upload dokumen baru, atau versi baru (revisi) untuk program + skema + jenis yang sama. */
function pustakaDokUpload(array $data, array $user): array {
  $p = row('SELECT * FROM program WHERE id_program = ?', [(string)($data['id_program'] ?? '')]);
  if (!$p) throw new LmsError('Pilih program pelatihan.');
  $skema = (string)($data['skema'] ?? '');
  $jenis = (string)($data['jenis'] ?? '');
  if (!in_array($skema, SKEMA_DOKUMEN, true)) throw new LmsError('Pilih skema pelatihan.');
  if (!array_key_exists($jenis, JENIS_DOKUMEN)) throw new LmsError('Pilih jenis dokumen.');
  $judul = trim((string)($data['judul'] ?? ''));
  $fileId = $link = '';
  $tipe = 'link';
  if (!empty($data['file'])) {
    $nama = (string)($data['file']['nama'] ?? 'dokumen');
    $tipe = strtolower(pathinfo($nama, PATHINFO_EXTENSION));
    if (!in_array($tipe, MATERI_EXT, true)) throw new LmsError('Jenis file tidak didukung. Gunakan: ' . implode(', ', MATERI_EXT));
    if (!preg_match('#^data:([^;]*);base64,(.+)$#s', (string)($data['file']['data'] ?? ''), $m)) throw new LmsError('File tidak terbaca. Coba pilih ulang file.');
    $bytes = base64_decode($m[2], true);
    if ($bytes === false) throw new LmsError('File tidak terbaca. Coba pilih ulang file.');
    if (strlen($bytes) > MATERI_MAX_MB * 1024 * 1024) throw new LmsError('File lebih dari ' . MATERI_MAX_MB . ' MB. Simpan sebagai PDF atau gunakan link.');
    $fileId = simpan_bytes('uploads/program/' . folder_aman($p['nama']), $bytes, $nama);
    if ($judul === '') $judul = pathinfo($nama, PATHINFO_FILENAME);
  } else {
    $link = trim((string)($data['link'] ?? ''));
    if (!preg_match('#^https://\S+$#', $link)) throw new LmsError('Pilih file atau isi link yang diawali https://');
  }
  if ($judul === '') $judul = JENIS_DOKUMEN[$jenis] . ' ' . $p['nama'];
  $d = insert('dokumen_program', ['id_dp' => new_id('d'), 'id_program' => $p['id_program'], 'skema' => $skema, 'jenis' => $jenis,
    'judul' => mb_substr($judul, 0, 200), 'file_id' => $fileId, 'tipe_file' => $tipe, 'link' => $link,
    'diunggah_pada' => now_()['stamp'], 'diunggah_oleh' => $user['nama']]);
  log_($user, 'dokumen_program', $p['nama'] . ' / ' . $skema . ' / ' . $jenis . ': ' . $d['judul']);
  return dp_view($d);
}

/** Menghapus satu versi dokumen (beserta persetujuan peserta untuk versi itu). */
function pustakaDokHapus(array $data, array $user): bool {
  $d = row('SELECT * FROM dokumen_program WHERE id_dp = ?', [(string)($data['id_dp'] ?? '')]);
  if (!$d) throw new LmsError('Dokumen tidak ditemukan.');
  q('DELETE FROM persetujuan WHERE id_dp = ?', [$d['id_dp']]);
  hapus('dokumen_program', $d);
  hapus_file($d['file_id']);
  log_($user, 'dokumen_program_hapus', $d['judul']);
  return true;
}

/**
 * Dokumen yang berlaku untuk satu batch: per jenis, skema yang sama dengan batch diutamakan, lalu "semua skema".
 * Versi: yang terakhir diupload paling lambat pada tanggal mulai batch; jika belum ada saat itu, versi pertama.
 */
function dokumen_batch(array $batch): array {
  if ($batch['id_program'] === '') return [];
  $semua = rows('SELECT * FROM dokumen_program WHERE id_program = ? ORDER BY diunggah_pada, id_dp', [$batch['id_program']]);
  $out = [];
  foreach (array_keys(JENIS_DOKUMEN) as $jenis) {
    foreach (array_filter([$batch['jenis_pelatihan'], 'semua']) as $skema) {
      $versi = array_values(array_filter($semua, fn($d) => $d['jenis'] === $jenis && $d['skema'] === $skema));
      if (!$versi) continue;
      $pilih = $versi[0];
      foreach ($versi as $v) if (substr($v['diunggah_pada'], 0, 10) <= $batch['tgl_mulai']) $pilih = $v;
      $out[] = $pilih;
      break;
    }
  }
  return $out;
}

// ---------------------------------------------------------------- Video pengarahan

/** ID video dari link YouTube (watch?v=, youtu.be/, shorts/, embed/, live/) atau ID langsung. */
function youtube_id(string $url): string {
  $url = trim($url);
  if (preg_match('#^[A-Za-z0-9_-]{11}$#', $url)) return $url;
  if (preg_match('#(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $url, $m)) return $m[1];
  return '';
}

function video_view(array $v): array {
  return ['id_video' => $v['id_video'], 'judul' => $v['judul'], 'url' => $v['url'], 'youtube_id' => $v['youtube_id'],
    'urutan' => (int)$v['urutan'], 'aktif' => $v['aktif'] !== 'tidak'];
}

function video_aktif(): array {
  $list = rows("SELECT * FROM video WHERE aktif <> 'tidak'");
  usort($list, fn($a, $b) => (int)$a['urutan'] <=> (int)$b['urutan'] ?: strcmp($a['dibuat_pada'], $b['dibuat_pada']));
  return $list;
}

function videoList(array $data, array $user): array {
  $list = rows('SELECT * FROM video');
  usort($list, fn($a, $b) => (int)$a['urutan'] <=> (int)$b['urutan'] ?: strcmp($a['dibuat_pada'], $b['dibuat_pada']));
  return array_map('video_view', $list);
}

function videoSimpan(array $data, array $user): array {
  $judul = trim((string)($data['judul'] ?? ''));
  $url = trim((string)($data['url'] ?? ''));
  $yt = youtube_id($url);
  if ($judul === '') throw new LmsError('Judul video wajib diisi.');
  if ($yt === '') throw new LmsError('Link YouTube tidak dikenali. Salin link dari tombol Bagikan di YouTube, mis. https://youtu.be/xxxxxxxxxxx');
  $c = ['judul' => mb_substr($judul, 0, 200), 'url' => $url, 'youtube_id' => $yt, 'urutan' => (int)($data['urutan'] ?? 1),
    'aktif' => array_key_exists('aktif', $data) && !$data['aktif'] ? 'tidak' : 'ya'];
  $id = (string)($data['id_video'] ?? '');
  if ($id === '') {
    $v = insert('video', array_merge($c, ['id_video' => new_id('v'), 'dibuat_pada' => now_()['stamp']]));
  } else {
    $v = row('SELECT * FROM video WHERE id_video = ?', [$id]);
    if (!$v) throw new LmsError('Video tidak ditemukan.');
    $v = update('video', $v, $c);
  }
  log_($user, 'video_simpan', $v['judul']);
  return video_view($v);
}

function videoHapus(array $data, array $user): bool {
  $v = row('SELECT * FROM video WHERE id_video = ?', [(string)($data['id_video'] ?? '')]);
  if (!$v) throw new LmsError('Video tidak ditemukan.');
  q('DELETE FROM video_tonton WHERE id_video = ?', [$v['id_video']]);
  hapus('video', $v);
  log_($user, 'video_hapus', $v['judul']);
  return true;
}

// ---------------------------------------------------------------- Peserta

/** Panduan pelatihan untuk peserta: video wajib + tata tertib/guideline batchnya, beserta status. */
function panduan_peserta(array $user): array {
  $batch = get_batch($user['id_batch']);
  $setuju = array_column(rows('SELECT id_dp, waktu FROM persetujuan WHERE id_user = ?', [$user['id_user']]), 'waktu', 'id_dp');
  $tonton = array_column(rows('SELECT id_video, waktu FROM video_tonton WHERE id_user = ?', [$user['id_user']]), 'waktu', 'id_video');
  $prog = $batch['id_program'] !== '' ? row('SELECT nama FROM program WHERE id_program = ?', [$batch['id_program']]) : null;
  return [
    'program' => $prog ? $prog['nama'] : '', 'skema' => $batch['jenis_pelatihan'], 'nama_pelatihan' => $batch['nama_pelatihan'],
    'video' => array_map(fn($v) => array_merge(video_view($v), ['ditonton' => $tonton[$v['id_video']] ?? '']), video_aktif()),
    'dokumen' => array_map(fn($d) => array_merge(dp_view($d), ['disetujui' => $setuju[$d['id_dp']] ?? '']), dokumen_batch($batch)),
  ];
}

function panduanPeserta(array $data, array $user): array {
  wajib_peserta($user);
  return panduan_peserta($user);
}

/** Tombol "Saya sudah membaca dan menyetujui". */
function setujuDokumen(array $data, array $user): array {
  wajib_peserta($user);
  $id = (string)($data['id_dp'] ?? '');
  if (!in_array($id, array_column(dokumen_batch(get_batch($user['id_batch'])), 'id_dp'), true)) throw new LmsError('Dokumen tidak ditemukan.');
  if (!row('SELECT id_setuju FROM persetujuan WHERE id_user = ? AND id_dp = ?', [$user['id_user'], $id])) {
    try {
      insert('persetujuan', ['id_setuju' => new_id('s'), 'id_user' => $user['id_user'], 'id_dp' => $id, 'waktu' => now_()['stamp']]);
    } catch (PDOException $e) { /* sudah tercatat (klik ganda) */ }
    log_($user, 'setuju_dokumen', $id);
  }
  return panduan_peserta($user);
}

/** Dicatat otomatis saat video diputar sampai ±90% (atau tombol cadangan jika pemutar tidak bisa memantau). */
function videoTonton(array $data, array $user): bool {
  wajib_peserta($user);
  $v = row("SELECT * FROM video WHERE id_video = ? AND aktif <> 'tidak'", [(string)($data['id_video'] ?? '')]);
  if (!$v) throw new LmsError('Video tidak ditemukan.');
  if (!row('SELECT id_tonton FROM video_tonton WHERE id_user = ? AND id_video = ?', [$user['id_user'], $v['id_video']])) {
    try {
      insert('video_tonton', ['id_tonton' => new_id('t'), 'id_user' => $user['id_user'], 'id_video' => $v['id_video'], 'waktu' => now_()['stamp']]);
    } catch (PDOException $e) { /* sudah tercatat */ }
  }
  return true;
}

// ---------------------------------------------------------------- Rekap untuk PIC

function kepatuhanBatch(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $dok = dokumen_batch($b);
  $vid = video_aktif();
  $peserta = peserta_batch($b['id_batch'], false);
  $setuju = $tonton = [];
  if ($peserta) {
    $ids = array_column($peserta, 'id_user');
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach (rows("SELECT id_user, id_dp, waktu FROM persetujuan WHERE id_user IN ($in)", $ids) as $r) $setuju[$r['id_user']][$r['id_dp']] = $r['waktu'];
    foreach (rows("SELECT id_user, id_video, waktu FROM video_tonton WHERE id_user IN ($in)", $ids) as $r) $tonton[$r['id_user']][$r['id_video']] = $r['waktu'];
  }
  $prog = $b['id_program'] !== '' ? row('SELECT nama FROM program WHERE id_program = ?', [$b['id_program']]) : null;
  return [
    'program' => $prog ? $prog['nama'] : '', 'skema' => $b['jenis_pelatihan'],
    'dokumen' => array_map('dp_view', $dok), 'video' => array_map('video_view', $vid),
    'peserta' => array_map(fn($u) => ['id_user' => $u['id_user'], 'no_absen' => $u['no_absen'], 'nama' => $u['nama'], 'kode_login' => $u['kode_login'],
      'setuju' => array_map(fn($d) => $setuju[$u['id_user']][$d['id_dp']] ?? '', $dok),
      'tonton' => array_map(fn($v) => $tonton[$u['id_user']][$v['id_video']] ?? '', $vid)], $peserta),
  ];
}

/** Ringkasan untuk Dashboard PIC: jumlah peserta yang belum setuju / belum menonton. */
function kepatuhan_ringkas(array $b): array {
  $dok = dokumen_batch($b);
  $vid = video_aktif();
  $peserta = array_column(peserta_batch($b['id_batch'], false), 'id_user');
  $belumSetuju = $belumTonton = 0;
  foreach ($peserta as $id) {
    $s = array_column(rows('SELECT id_dp FROM persetujuan WHERE id_user = ?', [$id]), 'id_dp');
    $t = array_column(rows('SELECT id_video FROM video_tonton WHERE id_user = ?', [$id]), 'id_video');
    if (array_diff(array_column($dok, 'id_dp'), $s)) $belumSetuju++;
    if (array_diff(array_column($vid, 'id_video'), $t)) $belumTonton++;
  }
  return ['dokumen' => count($dok), 'video' => count($vid), 'belum_setuju' => $belumSetuju, 'belum_tonton' => $belumTonton];
}
