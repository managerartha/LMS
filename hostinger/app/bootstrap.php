<?php
/**
 * LMS Artha Safety Indonesia — server PHP + MySQL (Hostinger).
 * Fondasi: konfigurasi, database, waktu, file, dan fungsi bantu umum.
 */
declare(strict_types=1);

const TZ = 'Asia/Jakarta';
const API_VERSI = 'php-1';
date_default_timezone_set(TZ);

define('ROOT', dirname(__DIR__));
define('STORAGE', ROOT . '/storage');   // file privat (foto absen, tanda tangan, laporan) — tidak bisa dibuka langsung
define('UPLOADS', ROOT . '/uploads');   // file publik (materi, dokumentasi) — nama file acak

require_once __DIR__ . '/schema.php';

/** Kesalahan yang pesannya boleh ditampilkan ke pengguna. */
class LmsError extends Exception {}

function config(): array {
  static $cfg = null;
  if ($cfg === null) {
    $file = __DIR__ . '/config.php';
    if (!is_file($file)) throw new LmsError('LMS belum dipasang. Buka install.php untuk memasang.');
    $cfg = require $file;
  }
  return $cfg;
}

function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $c = config();
    if (($c['driver'] ?? 'mysql') === 'sqlite') {
      $pdo = new PDO('sqlite:' . $c['sqlite']);
    } else {
      $pdo = new PDO('mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=utf8mb4', $c['user'], $c['pass']);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  }
  return $pdo;
}

/**
 * Menambahkan tabel/kolom baru (fitur baru) ke database yang sudah terpasang, tanpa mengubah data lama.
 * Berjalan sekali per versi skema; setelah itu hanya pengecekan file penanda.
 */
function pastikan_skema(): void {
  $penanda = STORAGE . '/skema-' . SCHEMA_VERSI . '.ok';
  if (is_file($penanda)) return;
  $driver = (config()['driver'] ?? 'mysql') === 'sqlite' ? 'sqlite' : 'mysql';
  foreach (schema_sql($driver) as $sql) {
    try { db()->exec($sql); } catch (PDOException $e) {
      if (stripos($sql, 'CREATE INDEX') !== 0) throw $e; // index yang sudah ada boleh dilewati
    }
  }
  foreach (SCHEMA as $table => $cols) {
    $ada = $driver === 'sqlite'
      ? array_column(db()->query("PRAGMA table_info(`$table`)")->fetchAll(), 'name')
      : array_column(db()->query("SHOW COLUMNS FROM `$table`")->fetchAll(), 'Field');
    foreach (array_diff($cols, $ada) as $c) {
      $type = in_array($c, SCHEMA_TEXT, true) ? 'TEXT' : 'VARCHAR(191)';
      db()->exec("ALTER TABLE `$table` ADD COLUMN `$c` $type NULL");
    }
  }
  @file_put_contents($penanda, date('c'));
}

function q(string $sql, array $p = []): PDOStatement {
  $st = db()->prepare($sql);
  $st->execute(array_values($p));
  return $st;
}

/** Semua nilai sebagai string; NULL menjadi '' (sama seperti versi Spreadsheet). */
function bersih(array $r): array {
  foreach ($r as $k => $v) $r[$k] = $v === null ? '' : (string)$v;
  return $r;
}

function rows(string $sql, array $p = []): array {
  return array_map('bersih', q($sql, $p)->fetchAll());
}

function row(string $sql, array $p = []): ?array {
  $r = q($sql, $p)->fetch();
  return $r ? bersih($r) : null;
}

function str_val($v): string {
  if ($v === null) return '';
  if (is_bool($v)) return $v ? '1' : '';
  return (string)$v;
}

/** Menyisipkan satu baris; kolom yang tidak diisi bernilai ''. Mengembalikan baris lengkap. */
function insert(string $table, array $data): array {
  $cols = SCHEMA[$table];
  $vals = array_map(fn($c) => str_val($data[$c] ?? ''), $cols);
  q('INSERT INTO `' . $table . '` (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')', $vals);
  return array_combine($cols, $vals);
}

/** Mengubah beberapa kolom satu baris (berdasarkan primary key). Mengembalikan baris yang sudah diubah. */
function update(string $table, array $rec, array $changes): array {
  if (!$changes) return $rec;
  $pk = SCHEMA[$table][0];
  $sets = [];
  $vals = [];
  foreach ($changes as $k => $v) {
    if (!in_array($k, SCHEMA[$table], true)) throw new LmsError("Kolom $k tidak ada di tabel $table.");
    $sets[] = "`$k` = ?";
    $vals[] = $rec[$k] = str_val($v);
  }
  $vals[] = $rec[$pk];
  q("UPDATE `$table` SET " . implode(', ', $sets) . " WHERE `$pk` = ?", $vals);
  return $rec;
}

function hapus(string $table, array $rec): void {
  $pk = SCHEMA[$table][0];
  q("DELETE FROM `$table` WHERE `$pk` = ?", [$rec[$pk]]);
}

function new_id(string $prefix): string {
  return $prefix . base_convert((string)intdiv(lms_ms(), 1), 10, 36) . bin2hex(random_bytes(3));
}

function log_(?array $user, string $aksi, string $detail = ''): void {
  try {
    insert('log', ['id_log' => new_id('g'), 'waktu' => now_()['stamp'], 'id_user' => $user['id_user'] ?? '',
      'nama' => $user['nama'] ?? '', 'aksi' => $aksi, 'detail' => $detail]);
  } catch (Throwable $e) { /* log gagal tidak boleh menggagalkan aksi utama */ }
}

function pengaturan(string $kunci): string {
  $r = row('SELECT nilai FROM pengaturan WHERE kunci = ?', [$kunci]);
  return $r ? $r['nilai'] : '';
}

function pengaturan_simpan(string $kunci, string $nilai): void {
  if (row('SELECT kunci FROM pengaturan WHERE kunci = ?', [$kunci])) {
    q('UPDATE pengaturan SET nilai = ? WHERE kunci = ?', [$nilai, $kunci]);
  } else {
    q('INSERT INTO pengaturan (kunci, nilai) VALUES (?, ?)', [$kunci, $nilai]);
  }
}

// ---------------------------------------------------------------- Waktu & lokasi

/** Waktu sekarang (detik). LMS_WAKTU_UJI hanya dipakai saat pengujian otomatis. */
function lms_time(): float {
  if (defined('LMS_WAKTU_UJI')) return LMS_WAKTU_UJI;
  $f = STORAGE . '/waktu-uji.txt';
  if (!empty(config()['uji']) && is_file($f)) return (float)trim((string)file_get_contents($f));
  return microtime(true);
}

function lms_ms(): int {
  return (int)round(lms_time() * 1000);
}

function now_(): array {
  $d = (new DateTime('@' . (int)floor(lms_time())))->setTimezone(new DateTimeZone(TZ));
  return ['date' => $d->format('Y-m-d'), 'time' => $d->format('H:i'), 'stamp' => $d->format('Y-m-d H:i:s')];
}

function is_date($s): bool { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$s); }
function is_time($s): bool { return (bool)preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$s); }

/** Jarak dua titik GPS dalam meter (haversine). */
function distance_m(float $lat1, float $lng1, float $lat2, float $lng2): int {
  $r = 6371000;
  $dLat = deg2rad($lat2 - $lat1);
  $dLng = deg2rad($lng2 - $lng1);
  $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
  return (int)round(2 * $r * asin(sqrt($a)));
}

/** HTTP GET sederhana (dipakai untuk alamat & link Google Maps). */
function http_get(string $url, bool $follow = true, int $timeout = 6): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_HEADER => !$follow,
    CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_USERAGENT => 'LMS-Artha-Safety-Indonesia/1.0 (info@arthasafetyindonesia.co.id)',
    CURLOPT_HTTPHEADER => ['Accept-Language: id'],
  ]);
  $body = curl_exec($ch);
  $info = curl_getinfo($ch);
  curl_close($ch);
  return ['body' => $body === false ? '' : (string)$body, 'status' => (int)($info['http_code'] ?? 0),
    'redirect' => (string)($info['redirect_url'] ?? '')];
}

/**
 * Alamat dari koordinat GPS (OpenStreetMap Nominatim, gratis). Disimpan di tabel geo_cache per ±100 m
 * agar peserta di lokasi yang sama tidak memanggil layanan berulang. '' jika gagal; absen tetap tersimpan.
 */
function alamat_($lat, $lng): string {
  if ($lat === '' || $lat === null || !is_numeric($lat) || !is_numeric($lng)) return '';
  $key = sprintf('%.3f,%.3f', (float)$lat, (float)$lng);
  static $memo = [];
  if (isset($memo[$key])) return $memo[$key];
  $r = row('SELECT alamat FROM geo_cache WHERE kunci = ?', [$key]);
  if ($r && $r['alamat'] !== '') return $memo[$key] = $r['alamat'];
  $a = '';
  if (empty(config()['uji'])) {
    $res = http_get('https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=18&accept-language=id&lat='
      . urlencode((string)$lat) . '&lon=' . urlencode((string)$lng), true, 5);
    $j = json_decode($res['body'], true);
    $a = is_array($j) && !empty($j['display_name']) ? (string)$j['display_name'] : '';
  } else {
    $a = 'Jl. Kapten Haryadi No.8, Sardonoharjo, Ngaglik, Sleman, Daerah Istimewa Yogyakarta, 55581, Indonesia';
  }
  if ($a !== '') {
    try { insert('geo_cache', ['kunci' => $key, 'alamat' => $a]); } catch (Throwable $e) { /* sudah ada */ }
  }
  return $memo[$key] = $a;
}

// ---------------------------------------------------------------- File

function folder_aman(string $s): string {
  return trim(preg_replace('/[^A-Za-z0-9._-]+/', '-', $s), '-') ?: 'x';
}

function pastikan_folder(string $dir): void {
  if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) throw new LmsError('Folder penyimpanan tidak bisa dibuat.');
}

/**
 * Menyimpan data URL gambar (data:image/jpeg|png;base64,...). Mengembalikan path relatif terhadap
 * ROOT, mis. 'storage/B42/absensi/hari-1/H1_S1_001-Kikis_foto.jpg' atau 'uploads/...'.
 * File publik diberi akhiran acak agar tidak bisa ditebak.
 */
function simpan_data_url(string $relDir, string $dataUrl, string $nama, int $maksByte = 3 * 1024 * 1024): string {
  if (!preg_match('#^data:(image/(?:jpeg|png));base64,(.+)$#s', $dataUrl, $m)) throw new LmsError('Format gambar tidak valid.');
  $bytes = base64_decode($m[2], true);
  if ($bytes === false) throw new LmsError('Format gambar tidak valid.');
  if (strlen($bytes) > $maksByte) throw new LmsError('Ukuran gambar terlalu besar.');
  return simpan_bytes($relDir, $bytes, $nama);
}

function simpan_bytes(string $relDir, string $bytes, string $nama): string {
  $dir = ROOT . '/' . $relDir;
  pastikan_folder($dir);
  $info = pathinfo($nama);
  $dasar = folder_aman($info['filename'] ?? 'file');
  $ext = strtolower(folder_aman($info['extension'] ?? 'bin'));
  $acak = str_starts_with($relDir, 'uploads') ? '_' . bin2hex(random_bytes(6)) : '';
  $file = $dasar . $acak . '.' . $ext;
  $i = 1;
  while (is_file($dir . '/' . $file)) $file = $dasar . $acak . '-' . ($i++) . '.' . $ext;
  if (file_put_contents($dir . '/' . $file, $bytes) === false) throw new LmsError('File tidak bisa disimpan. Periksa kapasitas hosting.');
  return $relDir . '/' . $file;
}

function hapus_file(string $rel): void {
  if ($rel === '' || str_contains($rel, '..')) return;
  $f = ROOT . '/' . $rel;
  if (is_file($f)) @unlink($f);
}

function mime_file(string $rel): string {
  $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
  return ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'][$ext] ?? 'application/octet-stream';
}

/** Isi file sebagai data URL (untuk foto absen privat & laporan PDF). */
function file_data_url(string $rel): string {
  if ($rel === '' || str_contains($rel, '..')) return '';
  $f = ROOT . '/' . $rel;
  if (!is_file($f)) return '';
  return 'data:' . mime_file($rel) . ';base64,' . base64_encode((string)file_get_contents($f));
}

/** URL publik untuk file di folder uploads (relatif terhadap halaman LMS). */
function url_publik(string $rel): string {
  return $rel === '' ? '' : implode('/', array_map('rawurlencode', explode('/', $rel)));
}
