<?php
/**
 * Berkas peserta: persyaratan (KTP, ijazah, …) & asesmen (portofolio, tugas DIT, …) untuk program tertentu (mis. BNSP).
 * - Admin menyusun daftar berkas per program (template) dan memasang jembatan Google Drive (Apps Script milik akun Artha).
 * - PIC menempel link folder Google Drive lembaga per batch, mengatur batas waktu, dan memverifikasi berkas.
 * - Peserta mengupload di LMS → berkas disimpan di LMS (cadangan, privat) → dikirim ke Drive lembaga di belakang layar,
 *   ke folder "<Nama Peserta>/Persyaratan" atau "<Nama Peserta>/Asesmen" (dibuat otomatis jika belum ada).
 * Apps Script hanya dipakai untuk mengirim berkas; proses LMS lain tidak melewatinya.
 */

const KATEGORI_BERKAS = ['persyaratan' => 'Persyaratan', 'asesmen' => 'Asesmen'];
const FORMAT_BERKAS = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip'];
const MIME_BERKAS = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
  'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
  'zip' => 'application/zip'];
const STATUS_BERKAS = ['menunggu' => 'Menunggu verifikasi', 'diterima' => 'Diterima', 'revisi' => 'Perlu revisi'];

// ---------------------------------------------------------------- Bantuan

function items_berkas(array $batch): array {
  if (($batch['id_program'] ?? '') === '') return [];
  $list = rows("SELECT * FROM berkas_item WHERE id_program = ? AND aktif <> 'tidak'", [$batch['id_program']]);
  usort($list, fn($a, $b) => strcmp($b['kategori'], $a['kategori']) ?: (int)$a['urutan'] <=> (int)$b['urutan'] ?: strcmp($a['nama'], $b['nama']));
  return $list; // persyaratan dulu, lalu asesmen
}

function format_item(array $it): array {
  $f = array_values(array_filter(array_map(fn($x) => strtolower(trim($x)), explode(',', $it['format']))));
  return $f ?: ['pdf', 'jpg', 'jpeg', 'png'];
}

function berkas_batch_set(string $idBatch): array {
  return row('SELECT * FROM berkas_batch WHERE id_batch = ?', [$idBatch])
    ?? ['id_batch' => $idBatch, 'drive_link' => '', 'drive_root' => '', 'batas' => '', 'riwayat_link' => '', 'diubah_oleh' => '', 'diubah_pada' => ''];
}

function batas_item(array $set, string $idItem): string {
  $b = json_decode($set['batas'] ?: '{}', true);
  return is_array($b) ? (string)($b[$idItem] ?? '') : '';
}

/** ID folder dari link Google Drive (…/folders/<ID>…, ?id=<ID>) atau ID langsung. */
function drive_folder_id(string $link): string {
  $link = trim($link);
  if (preg_match('#/folders/([A-Za-z0-9_-]{10,})#', $link, $m)) return $m[1];
  if (preg_match('#[?&]id=([A-Za-z0-9_-]{10,})#', $link, $m)) return $m[1];
  if (preg_match('#^[A-Za-z0-9_-]{10,}$#', $link)) return $link;
  return '';
}

function nama_folder_peserta(array $u): string {
  return trim(preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $u['nama']));
}

/** Memanggil jembatan Apps Script. Mengembalikan data jawaban; gagal → LmsError dengan pesan yang bisa dipahami. */
function jembatan(array $req, int $timeout = 90): array {
  $url = pengaturan('drive_jembatan_url');
  $rahasia = pengaturan('drive_jembatan_rahasia');
  if ($url === '' || $rahasia === '') throw new LmsError('Jembatan Google Drive belum dipasang. Admin memasangnya di menu Pustaka Pelatihan.');
  $res = http_post_json($url, array_merge($req, ['secret' => $rahasia]), $timeout);
  $j = json_decode($res['body'], true);
  if (!is_array($j)) {
    $info = $res['error'] !== '' ? $res['error'] : 'HTTP ' . $res['status'];
    throw new LmsError('Jembatan Google Drive tidak menjawab dengan benar (' . $info . '). Periksa URL Web App dan akses "Siapa saja".');
  }
  if (empty($j['ok'])) throw new LmsError('Google Drive: ' . ($j['error'] ?? 'gagal'));
  return $j;
}

// ---------------------------------------------------------------- Admin: jembatan & daftar berkas per program

function jembatanSimpan(array $data, array $user): array {
  $url = trim((string)($data['url'] ?? ''));
  if ($url !== '' && !preg_match('#^https://script\.google(usercontent)?\.com/\S+$#', $url) && empty(config()['uji'])) {
    throw new LmsError('URL harus URL Web App Apps Script (https://script.google.com/macros/s/…/exec).');
  }
  pengaturan_simpan('drive_jembatan_url', $url);
  if (!empty($data['rahasia_baru']) || pengaturan('drive_jembatan_rahasia') === '') pengaturan_simpan('drive_jembatan_rahasia', bin2hex(random_bytes(16)));
  log_($user, 'jembatan_drive', $url);
  return jembatan_info();
}

function jembatan_info(): array {
  return ['url' => pengaturan('drive_jembatan_url'), 'rahasia' => pengaturan('drive_jembatan_rahasia')];
}

function jembatanCek(array $data, array $user): array {
  $j = jembatan(['action' => 'ping'], 30);
  return ['akun' => $j['akun'] ?? '', 'versi' => $j['versi'] ?? ''];
}

function berkasItemList(array $data, array $user): array {
  return ['jembatan' => jembatan_info(),
    'item' => rows('SELECT * FROM berkas_item ORDER BY id_program, kategori DESC, CAST(urutan AS SIGNED), nama')];
}

/** Tambah/ubah item berkas program. Item yang sudah punya upload tidak dihapus, cukup dinonaktifkan. */
function berkasItemSimpan(array $data, array $user): array {
  $p = row('SELECT * FROM program WHERE id_program = ?', [(string)($data['id_program'] ?? '')]);
  if (!$p) throw new LmsError('Pilih program.');
  $nama = preg_replace('/\s+/', ' ', trim((string)($data['nama'] ?? '')));
  $kat = (string)($data['kategori'] ?? '');
  if ($nama === '') throw new LmsError('Nama berkas wajib diisi.');
  if (!array_key_exists($kat, KATEGORI_BERKAS)) throw new LmsError('Pilih kategori: persyaratan atau asesmen.');
  $fmt = array_values(array_intersect(array_map(fn($x) => strtolower(trim($x)), explode(',', (string)($data['format'] ?? 'pdf,jpg,png'))), FORMAT_BERKAS));
  if (!$fmt) throw new LmsError('Format tidak dikenali. Contoh: pdf, jpg, png');
  $c = ['id_program' => $p['id_program'], 'kategori' => $kat, 'nama' => mb_substr($nama, 0, 120), 'keterangan' => mb_substr(trim((string)($data['keterangan'] ?? '')), 0, 300),
    'wajib' => !empty($data['wajib']) ? 'ya' : '', 'format' => implode(',', $fmt), 'maks_mb' => max(1, min(25, (int)($data['maks_mb'] ?? 10))),
    'urutan' => (int)($data['urutan'] ?? 1), 'aktif' => array_key_exists('aktif', $data) && !$data['aktif'] ? 'tidak' : 'ya'];
  $id = (string)($data['id_item'] ?? '');
  if ($id === '') {
    insert('berkas_item', array_merge($c, ['id_item' => new_id('f')]));
  } else {
    $it = row('SELECT * FROM berkas_item WHERE id_item = ?', [$id]);
    if (!$it) throw new LmsError('Item tidak ditemukan.');
    update('berkas_item', $it, $c);
  }
  log_($user, 'berkas_item', $p['nama'] . ': ' . $nama);
  return berkasItemList([], $user);
}

/** Hapus item berkas. Item yang sudah ada upload peserta tidak dihapus (data peserta dijaga) — cukup dinonaktifkan. */
function berkasItemHapus(array $data, array $user): array {
  $it = row('SELECT * FROM berkas_item WHERE id_item = ?', [(string)($data['id_item'] ?? '')]);
  if (!$it) throw new LmsError('Item tidak ditemukan.');
  $n = (int)(row('SELECT COUNT(*) AS n FROM berkas WHERE id_item = ?', [$it['id_item']])['n'] ?? 0);
  if ($n > 0) throw new LmsError("Berkas \"{$it['nama']}\" sudah diupload $n peserta, jadi tidak bisa dihapus. Klik Ubah lalu hilangkan centang Aktif agar tidak diminta lagi.");
  hapus('berkas_item', $it);
  log_($user, 'berkas_item_hapus', $it['nama']);
  return berkasItemList([], $user);
}

// ---------------------------------------------------------------- Peserta

function berkas_view(?array $b): ?array {
  return $b ? ['id_berkas' => $b['id_berkas'], 'nama_file' => $b['nama_file'], 'tipe' => $b['tipe'], 'ukuran' => (int)$b['ukuran'],
    'diupload_pada' => $b['diupload_pada'], 'status' => $b['status'], 'catatan' => $b['catatan_pic'], 'diverifikasi_oleh' => $b['diverifikasi_oleh'],
    'drive_status' => $b['drive_status'], 'drive_url' => $b['drive_url'], 'drive_error' => $b['drive_error']] : null;
}

function berkas_peserta(array $user, array $batch): array {
  $set = berkas_batch_set($batch['id_batch']);
  $punya = array_column(rows('SELECT * FROM berkas WHERE id_user = ?', [$user['id_user']]), null, 'id_item');
  $today = now_()['date'];
  return array_map(function ($it) use ($set, $punya, $today) {
    $b = $punya[$it['id_item']] ?? null;
    $batas = batas_item($set, $it['id_item']);
    $lewat = $batas !== '' && $today > $batas;
    return ['id_item' => $it['id_item'], 'kategori' => $it['kategori'], 'nama' => $it['nama'], 'keterangan' => $it['keterangan'], 'wajib' => $it['wajib'] === 'ya',
      'format' => format_item($it), 'maks_mb' => (int)$it['maks_mb'], 'batas' => $batas, 'lewat' => $lewat, 'berkas' => berkas_view($b),
      // boleh upload: belum diterima, dan belum lewat batas (kecuali diminta revisi)
      'boleh_upload' => (!$b || $b['status'] !== 'diterima') && (!$lewat || ($b && $b['status'] === 'revisi'))];
  }, items_berkas($batch));
}

/** Ringkasan untuk Dashboard peserta (kartu pengingat hanya jika ada yang kurang / revisi). */
function berkas_ringkas(array $user): array {
  $batch = get_batch($user['id_batch']);
  if (!items_berkas($batch)) return ['aktif' => false];
  $l = berkas_peserta($user, $batch);
  return ['aktif' => true, 'kurang' => count(array_filter($l, fn($x) => $x['wajib'] && !$x['berkas'] && $x['boleh_upload'])),
    'revisi' => count(array_filter($l, fn($x) => $x['berkas'] && $x['berkas']['status'] === 'revisi'))];
}

function berkasSaya(array $data, array $user): array {
  wajib_peserta($user);
  $batch = get_batch($user['id_batch']);
  return ['item' => berkas_peserta($user, $batch), 'ringkas' => berkas_ringkas($user)];
}

/** Upload berkas peserta: disimpan di LMS (cadangan privat), lalu dikirim ke Drive lembaga setelah jawaban terkirim. */
function berkasUpload(array $data, array $user): array {
  wajib_peserta($user);
  $batch = get_batch($user['id_batch']);
  if ($batch['status'] !== 'aktif') throw new LmsError('Batch sudah tidak aktif.');
  $it = null;
  foreach (items_berkas($batch) as $x) if ($x['id_item'] === (string)($data['id_item'] ?? '')) $it = $x;
  if (!$it) throw new LmsError('Jenis berkas tidak ditemukan.');
  $info = null;
  foreach (berkas_peserta($user, $batch) as $x) if ($x['id_item'] === $it['id_item']) $info = $x;
  if (!$info['boleh_upload']) throw new LmsError($info['berkas'] && $info['berkas']['status'] === 'diterima' ? 'Berkas ini sudah diterima PIC.' : 'Batas waktu upload berkas ini sudah lewat. Hubungi PIC.');
  $nama = (string)($data['file']['nama'] ?? 'berkas');
  $ext = strtolower(pathinfo($nama, PATHINFO_EXTENSION));
  if (!in_array($ext, format_item($it), true)) throw new LmsError('Format file harus: ' . implode(', ', format_item($it)) . '.');
  if (!preg_match('#^data:([^;]*);base64,(.+)$#s', (string)($data['file']['data'] ?? ''), $m)) throw new LmsError('File tidak terbaca. Coba pilih ulang file.');
  $bytes = base64_decode($m[2], true);
  if ($bytes === false || $bytes === '') throw new LmsError('File tidak terbaca. Coba pilih ulang file.');
  if (strlen($bytes) > (int)$it['maks_mb'] * 1048576) throw new LmsError('Ukuran file lebih dari ' . $it['maks_mb'] . ' MB.');
  $bersih = fn($x) => trim(preg_replace('/\s+/', ' ', preg_replace('/[\\\\\/:*?"<>|]+/', ' ', $x)));
  $namaRapi = $bersih($it['nama']) . ' - ' . $bersih($user['nama']) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext); // mis. "KTP - Andi Saputra.pdf"
  $rel = simpan_bytes('storage/' . $batch['folder'] . '/berkas/' . folder_aman($user['kode_login']), $bytes, $namaRapi);
  $lama = row('SELECT * FROM berkas WHERE id_user = ? AND id_item = ?', [$user['id_user'], $it['id_item']]);
  $c = ['file_id' => $rel, 'nama_file' => $namaRapi, 'tipe' => $ext, 'ukuran' => strlen($bytes), 'diupload_pada' => now_()['stamp'],
    'status' => 'menunggu', 'catatan_pic' => $lama['catatan_pic'] ?? '', 'diverifikasi_oleh' => '', 'diverifikasi_pada' => '',
    'drive_status' => 'antri', 'drive_error' => ''];
  if ($lama) {
    hapus_file($lama['file_id']);
    $rec = update('berkas', $lama, $c); // drive_file lama dipakai untuk mengganti file di Drive
  } else {
    $rec = insert('berkas', array_merge($c, ['id_berkas' => new_id('b'), 'id_batch' => $batch['id_batch'], 'id_user' => $user['id_user'],
      'id_item' => $it['id_item'], 'drive_file' => '', 'drive_url' => '', 'drive_root' => '', 'drive_waktu' => '']));
  }
  log_($user, 'berkas_upload', $it['nama']);
  $id = $rec['id_berkas'];
  setelah_respon(fn() => kirim_berkas($id));
  return berkasSaya([], $user);
}

/** Unduh / lihat berkas: peserta pemiliknya, PIC batch, atau Admin. */
function berkasUnduh(array $data, array $user): array {
  $b = row('SELECT * FROM berkas WHERE id_berkas = ?', [(string)($data['id_berkas'] ?? '')]);
  if (!$b) throw new LmsError('Berkas tidak ditemukan.');
  if ($user['role'] === 'peserta' ? $b['id_user'] !== $user['id_user'] : !can_access_batch($user, get_batch($b['id_batch']))) throw new LmsError('Anda tidak berhak membuka berkas ini.');
  $f = ROOT . '/' . $b['file_id'];
  if ($b['file_id'] === '' || str_contains($b['file_id'], '..') || !is_file($f)) throw new LmsError('File tidak ditemukan di server.');
  return ['nama' => $b['nama_file'], 'mime' => MIME_BERKAS[$b['tipe']] ?? 'application/octet-stream', 'data' => base64_encode((string)file_get_contents($f))];
}

// ---------------------------------------------------------------- Pengiriman ke Google Drive

/** Mengirim satu berkas ke Drive lembaga (dipanggil di belakang layar). Status tercatat di kolom drive_*. */
function kirim_berkas(string $idBerkas): bool {
  $b = row('SELECT * FROM berkas WHERE id_berkas = ?', [$idBerkas]);
  if (!$b || $b['drive_status'] === 'terkirim') return true;
  $set = berkas_batch_set($b['id_batch']);
  $catat = function (array $c) use ($b) { update('berkas', row('SELECT * FROM berkas WHERE id_berkas = ?', [$b['id_berkas']]), $c + ['drive_waktu' => now_()['stamp']]); };
  if ($set['drive_root'] === '') { $catat(['drive_status' => 'antri', 'drive_error' => 'Link Google Drive batch belum diisi PIC.']); return false; }
  $u = row('SELECT * FROM pengguna WHERE id_user = ?', [$b['id_user']]);
  $it = row('SELECT * FROM berkas_item WHERE id_item = ?', [$b['id_item']]);
  $f = ROOT . '/' . $b['file_id'];
  if (!$u || !$it || !is_file($f)) { $catat(['drive_status' => 'gagal', 'drive_error' => 'File atau data peserta tidak ditemukan.']); return false; }
  try {
    $j = jembatan(['action' => 'upload', 'root' => $set['drive_root'], 'folder' => nama_folder_peserta($u),
      'sub' => KATEGORI_BERKAS[$it['kategori']] ?? 'Persyaratan', 'name' => $b['nama_file'], 'mime' => MIME_BERKAS[$b['tipe']] ?? 'application/octet-stream',
      'data' => base64_encode((string)file_get_contents($f)),
      // file lama di Drive diganti hanya jika masih di folder lembaga yang sama
      'replace' => $b['drive_root'] === $set['drive_root'] ? $b['drive_file'] : ''], 120);
    $catat(['drive_status' => 'terkirim', 'drive_file' => (string)($j['id'] ?? ''), 'drive_url' => (string)($j['url'] ?? ''), 'drive_root' => $set['drive_root'], 'drive_error' => '']);
    return true;
  } catch (Throwable $e) {
    $catat(['drive_status' => 'gagal', 'drive_error' => mb_substr($e->getMessage(), 0, 300)]);
    return false;
  }
}

/** Kirim berkas yang masih antri/gagal di satu batch (maks. $maks berkas sekali jalan). */
function kirim_tertunda(string $idBatch, int $maks = 15): array {
  $n = ['terkirim' => 0, 'gagal' => 0];
  foreach (rows("SELECT id_berkas FROM berkas WHERE id_batch = ? AND drive_status IN ('antri', 'gagal') ORDER BY diupload_pada LIMIT " . (int)$maks, [$idBatch]) as $r) {
    kirim_berkas($r['id_berkas']) ? $n['terkirim']++ : $n['gagal']++;
  }
  return $n;
}

// ---------------------------------------------------------------- PIC

function berkasBatch(array $data, array $user): array {
  $batch = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $items = items_berkas($batch);
  $set = berkas_batch_set($batch['id_batch']);
  $prog = $batch['id_program'] !== '' ? row('SELECT nama FROM program WHERE id_program = ?', [$batch['id_program']]) : null;
  $semua = [];
  foreach (rows('SELECT * FROM berkas WHERE id_batch = ?', [$batch['id_batch']]) as $b) $semua[$b['id_user']][$b['id_item']] = $b;
  $hitung = ['antri' => 0, 'gagal' => 0, 'terkirim' => 0, 'menunggu' => 0];
  foreach ($semua as $per) foreach ($per as $b) {
    $hitung[$b['drive_status']] = ($hitung[$b['drive_status']] ?? 0) + 1;
    if ($b['status'] === 'menunggu') $hitung['menunggu']++;
  }
  // Berkas yang tertunda dicoba dikirim ulang di belakang layar saat PIC membuka menu ini.
  if (($hitung['antri'] || $hitung['gagal']) && $set['drive_root'] !== '' && pengaturan('drive_jembatan_url') !== '' && empty($data['tanpa_kirim'])) {
    $id = $batch['id_batch'];
    setelah_respon(fn() => kirim_tertunda($id, 10));
  }
  $batas = json_decode($set['batas'] ?: '{}', true) ?: [];
  return ['aktif' => (bool)$items, 'program' => $prog ? $prog['nama'] : '', 'jembatan' => pengaturan('drive_jembatan_url') !== '',
    'drive_link' => $set['drive_link'], 'riwayat_link' => json_decode($set['riwayat_link'] ?: '[]', true) ?: [], 'hitung' => $hitung,
    'item' => array_map(fn($it) => ['id_item' => $it['id_item'], 'kategori' => $it['kategori'], 'nama' => $it['nama'], 'wajib' => $it['wajib'] === 'ya',
      'batas' => (string)($batas[$it['id_item']] ?? '')], $items),
    'peserta' => array_map(fn($u) => ['id_user' => $u['id_user'], 'no_absen' => $u['no_absen'], 'nama' => $u['nama'], 'kode_login' => $u['kode_login'],
      'berkas' => array_map(fn($it) => berkas_view($semua[$u['id_user']][$it['id_item']] ?? null), $items)], peserta_batch($batch['id_batch'], false))];
}

/** Simpan link folder Drive lembaga (boleh diganti kapan saja; riwayat disimpan) dan batas waktu per berkas. */
function berkasDriveSimpan(array $data, array $user): array {
  $batch = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $set = berkas_batch_set($batch['id_batch']);
  $c = ['diubah_oleh' => $user['nama'], 'diubah_pada' => now_()['stamp']];
  if (array_key_exists('link', $data)) {
    $link = trim((string)$data['link']);
    $root = $link === '' ? '' : drive_folder_id($link);
    if ($link !== '' && $root === '') throw new LmsError('Link Google Drive tidak dikenali. Salin link folder, mis. https://drive.google.com/drive/folders/…');
    if ($root !== $set['drive_root']) {
      $riw = json_decode($set['riwayat_link'] ?: '[]', true) ?: [];
      if ($set['drive_link'] !== '') $riw[] = ['link' => $set['drive_link'], 'sampai' => now_()['stamp']];
      $c += ['drive_link' => $link, 'drive_root' => $root, 'riwayat_link' => json_encode(array_slice($riw, -10))];
      // berkas yang belum terkirim ke link lama ikut ke link baru
    }
  }
  if (isset($data['batas']) && is_array($data['batas'])) {
    $b = [];
    foreach ($data['batas'] as $id => $tgl) if ($tgl !== '' && is_date($tgl)) $b[$id] = $tgl;
    $c['batas'] = json_encode($b);
  }
  row('SELECT id_batch FROM berkas_batch WHERE id_batch = ?', [$batch['id_batch']]) ? update('berkas_batch', $set, $c) : insert('berkas_batch', array_merge($set, $c));
  log_($user, 'berkas_drive', $batch['kode_batch']);
  return berkasBatch(['id_batch' => $batch['id_batch'], 'tanpa_kirim' => true], $user);
}

function berkasDriveCek(array $data, array $user): array {
  $batch = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $set = berkas_batch_set($batch['id_batch']);
  if ($set['drive_root'] === '') throw new LmsError('Isi dan simpan link Google Drive lembaga terlebih dahulu.');
  $j = jembatan(['action' => 'cek', 'root' => $set['drive_root']], 45);
  return ['nama' => $j['nama'] ?? '', 'bisa_tulis' => !empty($j['bisa_tulis']), 'jumlah_folder' => (int)($j['jumlah_folder'] ?? 0)];
}

/** Buatkan folder "<Nama>/Persyaratan" & "<Nama>/Asesmen" untuk semua peserta (folder yang sudah ada dipakai). */
function berkasBuatFolder(array $data, array $user): array {
  $batch = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $set = berkas_batch_set($batch['id_batch']);
  if ($set['drive_root'] === '') throw new LmsError('Isi dan simpan link Google Drive lembaga terlebih dahulu.');
  $nama = array_map('nama_folder_peserta', peserta_batch($batch['id_batch'], false));
  if (!$nama) throw new LmsError('Belum ada peserta di batch ini.');
  $j = jembatan(['action' => 'folders', 'root' => $set['drive_root'], 'names' => $nama], 300);
  log_($user, 'berkas_folder', $batch['kode_batch'] . ': ' . count($nama));
  return ['dibuat' => (int)($j['dibuat'] ?? 0), 'sudah_ada' => (int)($j['sudah_ada'] ?? 0)];
}

function berkasVerifikasi(array $data, array $user): array {
  $b = row('SELECT * FROM berkas WHERE id_berkas = ?', [(string)($data['id_berkas'] ?? '')]);
  if (!$b) throw new LmsError('Berkas tidak ditemukan.');
  batch_for_pic($user, $b['id_batch']);
  $st = (string)($data['status'] ?? '');
  if (!in_array($st, ['diterima', 'revisi', 'menunggu'], true)) throw new LmsError('Status tidak valid.');
  $cat = trim((string)($data['catatan'] ?? ''));
  if ($st === 'revisi' && $cat === '') throw new LmsError('Tulis catatan revisi untuk peserta (mis. "Foto KTP buram").');
  update('berkas', $b, ['status' => $st, 'catatan_pic' => mb_substr($cat, 0, 300), 'diverifikasi_oleh' => $st === 'menunggu' ? '' : $user['nama'],
    'diverifikasi_pada' => $st === 'menunggu' ? '' : now_()['stamp']]);
  log_($user, 'berkas_verifikasi', $b['nama_file'] . ' → ' . $st);
  return berkasBatch(['id_batch' => $b['id_batch'], 'tanpa_kirim' => true], $user);
}

/** Kirim sekarang (bukan di belakang layar) berkas yang antri/gagal, lalu tampilkan hasilnya. */
function berkasKirimUlang(array $data, array $user): array {
  $batch = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  @set_time_limit(300);
  $n = kirim_tertunda($batch['id_batch'], 20);
  return ['hasil' => $n, 'data' => berkasBatch(['id_batch' => $batch['id_batch'], 'tanpa_kirim' => true], $user)];
}

/** Rekap kelengkapan berkas (CSV, terbuka di Excel). */
function berkasRekap(array $data, array $user): array {
  $d = berkasBatch(['id_batch' => (string)($data['id_batch'] ?? ''), 'tanpa_kirim' => true], $user);
  $batch = get_batch((string)$data['id_batch']);
  $rows = [array_merge(['No', 'Nama', 'Kode'], array_map(fn($i) => $i['nama'] . ($i['wajib'] ? ' (wajib)' : ''), $d['item']))];
  foreach ($d['peserta'] as $p) {
    $rows[] = array_merge([$p['no_absen'], $p['nama'], $p['kode_login']], array_map(fn($b) => !$b ? 'Belum upload'
      : (STATUS_BERKAS[$b['status']] ?? $b['status']) . ' / Drive: ' . ($b['drive_status'] === 'terkirim' ? 'terkirim' : ($b['drive_status'] === 'gagal' ? 'gagal' : 'antri')), $p['berkas']));
  }
  $csv = "\xEF\xBB\xBF" . implode("\r\n", array_map(fn($r) => implode(';', array_map(fn($x) => '"' . str_replace('"', '""', (string)$x) . '"', $r)), $rows));
  return ['nama' => preg_replace('#[\\\\/:*?"<>|]#', '-', 'Rekap Berkas ' . $batch['kode_batch'] . '.csv'), 'data' => base64_encode($csv)];
}

// ---------------------------------------------------------------- Dashboard PIC

function pengingat_berkas(array $b, string $fase): array {
  if ($fase === 'selesai' || !items_berkas($b)) return [];
  $set = berkas_batch_set($b['id_batch']);
  $p = [];
  if ($set['drive_root'] === '') $p[] = pengingat_('warn', 'Link Google Drive lembaga untuk berkas peserta belum diisi.', 'berkas');
  $gagal = (int)row("SELECT COUNT(*) AS n FROM berkas WHERE id_batch = ? AND drive_status = 'gagal'", [$b['id_batch']])['n'];
  if ($gagal) $p[] = pengingat_('warn', $gagal . ' berkas peserta gagal terkirim ke Google Drive.', 'berkas');
  $tunggu = (int)row("SELECT COUNT(*) AS n FROM berkas WHERE id_batch = ? AND status = 'menunggu'", [$b['id_batch']])['n'];
  if ($tunggu) $p[] = pengingat_('info', $tunggu . ' berkas peserta menunggu verifikasi.', 'berkas');
  return $p;
}
