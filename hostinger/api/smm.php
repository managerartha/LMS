<?php
/**
 * API baca-saja untuk aplikasi SMM (Sistem Manajemen Mutu).
 *
 *   GET /api/smm/kelas?sejak=YYYY-MM-DD
 *   GET /api/smm/survei?sejak=YYYY-MM-DD
 *   GET /api/smm/keluhan?sejak=YYYY-MM-DD
 *   Header: Authorization: Bearer <token>   (token di file app/.env → SMM_API_TOKEN=...)
 *
 * TIDAK mengirim data pribadi peserta (nama, HP, email, NIK, alamat, kode login, id pengguna).
 * Hanya angka rekap, skor, komentar (data pribadi yang diketik disamarkan), dan ID referensi.
 */
require __DIR__ . '/../app/api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function smm_keluar(int $kode, array $isi): void {
  http_response_code($kode);
  echo json_encode($isi, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}

/** Baca nilai dari file app/.env (format KUNCI=nilai; baris # diabaikan). */
function env_lms(string $kunci): string {
  static $env = null;
  if ($env === null) {
    $env = [];
    $f = __DIR__ . '/../app/.env';
    if (is_file($f)) {
      foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $baris) {
        $baris = trim($baris);
        if ($baris === '' || $baris[0] === '#' || !str_contains($baris, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $baris, 2));
        $env[$k] = trim($v, "\"'");
      }
    }
  }
  return $env[$kunci] ?? (string)(getenv($kunci) ?: '');
}

function header_otorisasi(): string {
  foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) if (!empty($_SERVER[$k])) return (string)$_SERVER[$k];
  if (function_exists('getallheaders')) foreach (getallheaders() as $k => $v) if (strcasecmp($k, 'Authorization') === 0) return (string)$v;
  return '';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') smm_keluar(405, ['error' => 'Hanya GET (baca-saja).']);

$token = env_lms('SMM_API_TOKEN');
if (strlen($token) < 24) smm_keluar(503, ['error' => 'API SMM belum diaktifkan (SMM_API_TOKEN di app/.env belum diisi).']);
$auth = header_otorisasi();
if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) || !hash_equals($token, $m[1])) {
  header('WWW-Authenticate: Bearer');
  smm_keluar(401, ['error' => 'Token tidak valid.']);
}

$rute = (string)($_GET['r'] ?? '');
if ($rute === '' && !empty($_SERVER['PATH_INFO'])) $rute = trim((string)$_SERVER['PATH_INFO'], '/');
$sejak = (string)($_GET['sejak'] ?? '');
if ($sejak !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sejak)) smm_keluar(400, ['error' => 'Format sejak harus YYYY-MM-DD.']);

try {
  pastikan_skema();
  $data = match ($rute) {
    'kelas' => smm_kelas($sejak),
    'survei' => smm_survei($sejak),
    'keluhan' => smm_keluhan($sejak),
    default => smm_keluar(404, ['error' => 'Endpoint tidak dikenal. Gunakan /api/smm/kelas, /api/smm/survei, atau /api/smm/keluhan.']),
  };
  smm_keluar(200, ['data' => $data]);
} catch (Throwable $e) {
  error_log('LMS API SMM: ' . $e);
  smm_keluar(500, ['error' => 'Terjadi kesalahan di server LMS.']);
}

/** Rekap per kelas (batch) yang selesai pada/sesudah tanggal `sejak`. */
function smm_kelas(string $sejak): array {
  $list = $sejak !== '' ? rows('SELECT * FROM batch WHERE tgl_selesai >= ? ORDER BY tgl_mulai', [$sejak]) : rows('SELECT * FROM batch ORDER BY tgl_mulai');
  $out = [];
  foreach ($list as $b) {
    $id = $b['id_batch'];
    $peserta = (int)row("SELECT COUNT(*) AS n FROM pengguna WHERE role = 'peserta' AND id_batch = ? AND aktif <> 'tidak'", [$id])['n'];
    $hadir = (int)row("SELECT COUNT(DISTINCT a.id_user) AS n FROM absensi a JOIN pengguna p ON p.id_user = a.id_user
      WHERE a.id_batch = ? AND a.status = 'hadir' AND p.role = 'peserta' AND p.aktif <> 'tidak'", [$id])['n'];
    [$lulus, $rata] = smm_nilai($b);
    $trainer = [];
    foreach (rows("SELECT pemateri, status FROM rundown WHERE id_batch = ?", [$id]) as $r) {
      $n = trim(preg_replace('/\s+/', ' ', (string)$r['pemateri']));
      if ($n !== '' && ($r['status'] ?? '') !== 'draft') $trainer[mb_strtolower($n)] = $n;
    }
    $out[] = ['kode_batch' => $b['kode_batch'], 'nama_pelatihan' => $b['nama_pelatihan'], 'tgl_mulai' => $b['tgl_mulai'], 'tgl_selesai' => $b['tgl_selesai'],
      'jumlah_peserta' => $peserta, 'hadir' => $hadir, 'lulus' => $lulus, 'rata_nilai' => $rata, 'trainer' => array_values($trainer)];
  }
  return $out;
}

/** [jumlah lulus, rata-rata nilai akhir] — null bila belum ada data nilai. */
function smm_nilai(array $b): array {
  $lulus = null;
  $rata = null;
  try {
    $set = setelan_nilai($b);
    if ($set['mode'] === 'kemnaker' && row('SELECT id_nilai FROM nilai WHERE id_batch = ? LIMIT 1', [$b['id_batch']])) {
      $list = daftar_nilai($b, $set);
      $lulus = count(array_filter($list, fn($x) => $x['lulus']));
      $angka = array_column(array_filter($list, fn($x) => $x['lengkap'] && !$x['tidak_ikut']), 'rata');
      $rata = $angka ? round(array_sum($angka) / count($angka), 2) : null;
    }
  } catch (Throwable $e) { /* nilai belum diatur */ }
  if ($lulus === null) {
    $info = json_decode($b['info_laporan'] ?: '{}', true);
    if (is_array($info) && isset($info['jumlah_lulus']) && is_numeric($info['jumlah_lulus'])) $lulus = (int)$info['jumlah_lulus'];
  }
  return [$lulus, $rata];
}

function smm_survei(string $sejak): array {
  $list = $sejak !== '' ? rows('SELECT * FROM survei WHERE tanggal >= ? ORDER BY waktu', [$sejak]) : rows('SELECT * FROM survei ORDER BY waktu');
  $out = [];
  foreach ($list as $r) {
    $x = ['id_respons' => $r['id_respons'], 'kode_batch' => $r['kode_batch'], 'jenis_survei' => $r['jenis'], 'tanggal' => $r['tanggal'], 'skor' => (float)$r['skor']];
    foreach ((array)json_decode($r['jawaban'], true) as $kode => $v) $x['aspek_' . $kode] = (int)$v;
    $x['trainer'] = $r['jenis'] === 'evaluasi_trainer' ? $r['trainer'] : null;
    $x['komentar'] = samarkan_pribadi((string)$r['komentar'], nama_peserta_batch($r['id_batch']));
    $out[] = $x;
  }
  return $out;
}

function smm_keluhan(string $sejak): array {
  $list = $sejak !== '' ? rows('SELECT * FROM keluhan WHERE tanggal >= ? ORDER BY waktu', [$sejak]) : rows('SELECT * FROM keluhan ORDER BY waktu');
  return array_map(fn($k) => ['id_keluhan' => $k['id_keluhan'], 'kode_batch' => $k['kode_batch'] !== '' ? $k['kode_batch'] : null, 'tanggal' => $k['tanggal'],
    'kategori' => $k['kategori'], 'uraian' => samarkan_pribadi((string)$k['uraian'], array_merge(nama_peserta_batch($k['id_batch']),
      array_filter([$k['nama_pelapor']])))], $list);
}
