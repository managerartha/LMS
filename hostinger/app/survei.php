<?php
/**
 * Survei / feedback & keluhan (dibaca aplikasi SMM lewat api/smm.php).
 *
 * Survei (skor 1–5 per aspek + komentar), tiga jenis:
 *  - kepuasan_peserta : peserta, sekali per batch, mulai hari terakhir batch. WAJIB sebelum unduh SKL
 *                       (untuk batch yang selesai sejak fitur ini dipasang).
 *  - evaluasi_trainer : peserta, per trainer per hari (trainer dari rundown), muncul setelah sesi 2 hari itu dimulai.
 *  - evaluasi_klien   : klien/perusahaan lewat link khusus per batch (tanpa login), mulai hari terakhir batch.
 * Jawaban peserta ANONIM: tabel `survei` tidak menyimpan id peserta; `survei_isi` hanya mencatat bahwa peserta sudah mengisi.
 * Pertanyaan bisa diatur Admin (pengaturan `survei_pertanyaan`); kode pertanyaan menjadi kolom aspek_<kode> di API SMM.
 *
 * Keluhan: peserta (dari LMS, kapan saja) atau klien/umum (link publik). Kontak pelapor hanya disimpan di LMS;
 * isi keluhan tidak ditampilkan di LMS (PIC hanya melihat jumlahnya) — tindak lanjut di aplikasi SMM.
 */

const JENIS_SURVEI = ['kepuasan_peserta' => 'Kepuasan peserta', 'evaluasi_trainer' => 'Evaluasi trainer', 'evaluasi_klien' => 'Evaluasi klien'];

const PERTANYAAN_BAWAAN = [
  'kepuasan_peserta' => [
    ['kode' => 'materi', 'teks' => 'Materi', 'ket' => 'Isi, kelengkapan, dan relevansi dengan pekerjaan'],
    ['kode' => 'trainer', 'teks' => 'Trainer', 'ket' => 'Penguasaan dan cara menyampaikan materi'],
    ['kode' => 'fasilitas', 'teks' => 'Fasilitas', 'ket' => 'Ruang kelas, alat praktik, konsumsi'],
    ['kode' => 'pelayanan', 'teks' => 'Pelayanan', 'ket' => 'Admin, PIC, informasi, dan respons'],
  ],
  'evaluasi_trainer' => [
    ['kode' => 'penguasaan', 'teks' => 'Penguasaan materi', 'ket' => ''],
    ['kode' => 'penyampaian', 'teks' => 'Cara penyampaian', 'ket' => ''],
    ['kode' => 'interaksi', 'teks' => 'Interaksi & tanya jawab', 'ket' => ''],
    ['kode' => 'waktu', 'teks' => 'Ketepatan waktu', 'ket' => ''],
  ],
  'evaluasi_klien' => [
    ['kode' => 'kesesuaian', 'teks' => 'Materi sesuai kebutuhan perusahaan', 'ket' => ''],
    ['kode' => 'trainer', 'teks' => 'Kualitas trainer', 'ket' => ''],
    ['kode' => 'pelayanan', 'teks' => 'Pelayanan & komunikasi', 'ket' => ''],
    ['kode' => 'administrasi', 'teks' => 'Ketepatan jadwal & administrasi', 'ket' => ''],
  ],
];

const KATEGORI_KELUHAN = ['materi' => 'Materi', 'trainer' => 'Trainer', 'fasilitas' => 'Fasilitas / tempat', 'pelayanan' => 'Pelayanan & administrasi',
  'jadwal' => 'Jadwal', 'dokumen' => 'Sertifikat / dokumen', 'lainnya' => 'Lainnya'];

// ================================================================ Pertanyaan (diatur Admin)

/** Semua pertanyaan per jenis (termasuk yang dinonaktifkan; urutan = urutan tampil). */
function pertanyaan_survei(): array {
  $s = json_decode(pengaturan('survei_pertanyaan') ?: '{}', true);
  $out = [];
  foreach (PERTANYAAN_BAWAAN as $jenis => $bawaan) {
    $list = isset($s[$jenis]) && is_array($s[$jenis]) ? $s[$jenis] : array_map(fn($p) => $p + ['aktif' => true], $bawaan);
    $out[$jenis] = array_values(array_map(fn($p) => ['kode' => (string)$p['kode'], 'teks' => (string)$p['teks'], 'ket' => (string)($p['ket'] ?? ''),
      'aktif' => !empty($p['aktif'])], $list));
  }
  return $out;
}

function pertanyaan_aktif(string $jenis): array {
  return array_values(array_filter(pertanyaan_survei()[$jenis] ?? [], fn($p) => $p['aktif']));
}

function surveiPertanyaanGet(array $data, array $user): array {
  return ['pertanyaan' => pertanyaan_survei(), 'jenis' => JENIS_SURVEI];
}

/**
 * Admin menyimpan daftar pertanyaan satu jenis. Pertanyaan lama tidak dihapus (cukup dinonaktifkan) agar riwayat & API SMM konsisten;
 * kode pertanyaan baru dibuat dari teksnya dan tidak berubah lagi.
 */
function surveiPertanyaanSimpan(array $data, array $user): array {
  $jenis = (string)($data['jenis'] ?? '');
  if (!isset(JENIS_SURVEI[$jenis])) throw new LmsError('Jenis survei tidak dikenal.');
  $semua = pertanyaan_survei();
  $lama = array_column($semua[$jenis], null, 'kode');
  $baru = [];
  $kodeDipakai = [];
  foreach ((array)($data['list'] ?? []) as $p) {
    $teks = trim(preg_replace('/\s+/', ' ', (string)($p['teks'] ?? '')));
    if ($teks === '') continue;
    $kode = (string)($p['kode'] ?? '');
    if ($kode === '' || !isset($lama[$kode])) {
      $dasar = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($teks)), '_') ?: 'aspek';
      $dasar = substr($dasar, 0, 30);
      $kode = $dasar;
      for ($i = 2; isset($lama[$kode]) || isset($kodeDipakai[$kode]); $i++) $kode = $dasar . '_' . $i;
    }
    if (isset($kodeDipakai[$kode])) continue;
    $kodeDipakai[$kode] = true;
    $baru[] = ['kode' => $kode, 'teks' => mb_substr($teks, 0, 120), 'ket' => mb_substr(trim((string)($p['ket'] ?? '')), 0, 200), 'aktif' => !empty($p['aktif'])];
  }
  foreach ($lama as $kode => $p) if (!isset($kodeDipakai[$kode])) $baru[] = ['aktif' => false] + $p; // yang dihapus → nonaktif
  if (!array_filter($baru, fn($p) => $p['aktif'])) throw new LmsError('Minimal harus ada 1 pertanyaan aktif.');
  $semua[$jenis] = $baru;
  pengaturan_simpan('survei_pertanyaan', json_encode($semua, JSON_UNESCAPED_UNICODE));
  log_($user, 'survei_pertanyaan', $jenis . ': ' . count(array_filter($baru, fn($p) => $p['aktif'])) . ' aktif');
  return ['pertanyaan' => pertanyaan_survei()];
}

// ================================================================ Jadwal survei per batch

/** Tanggal fitur survei mulai berlaku: batch yang selesai sebelum tanggal ini tidak diwajibkan survei untuk unduh SKL. */
function survei_mulai(): string {
  $v = pengaturan('survei_mulai');
  if ($v === '') { $v = now_()['date']; pengaturan_simpan('survei_mulai', $v); }
  return $v;
}

function hari_terakhir(array $b): string {
  $h = row('SELECT MAX(tanggal) AS t FROM hari WHERE id_batch = ?', [$b['id_batch']]);
  return $h && $h['t'] ? $h['t'] : $b['tgl_selesai'];
}

/** Trainer (pemateri di rundown) per hari; evaluasi dibuka setelah sesi 2 hari itu dimulai. */
function trainer_hari(array $b): array {
  $now = now_();
  $jam = substr($now['stamp'], 11, 5);
  $out = [];
  foreach (hari_batch($b['id_batch']) as $h) {
    $buka = $h['tanggal'] < $now['date'] || ($h['tanggal'] === $now['date'] && $jam >= ($b['sesi2_mulai'] ?: '13:00'));
    $nama = [];
    foreach (rundown_hari($h['id_hari']) as $r) {
      if (($r['status'] ?? '') === 'draft') continue;
      $n = trim(preg_replace('/\s+/', ' ', (string)$r['pemateri']));
      if ($n === '' || isset($nama[mb_strtolower($n)])) continue;
      $nama[mb_strtolower($n)] = ['nama' => $n, 'materi' => trim((string)$r['kegiatan'])];
    }
    foreach ($nama as $x) $out[] = ['id_hari' => $h['id_hari'], 'hari_ke' => (int)$h['hari_ke'], 'tanggal' => $h['tanggal'], 'buka' => $buka] + $x;
  }
  return $out;
}

function sudah_isi(string $idUser, string $jenis, string $kunci): bool {
  return (bool)row('SELECT id_isi FROM survei_isi WHERE id_isi = ?', [md5($idUser . '|' . $jenis . '|' . $kunci)]);
}

/** Wajib survei kepuasan sebelum unduh SKL? (batch selesai sejak fitur dipasang & peserta belum mengisi) */
function perlu_survei_skl(array $user): bool {
  if ($user['role'] !== 'peserta') return false;
  $b = row('SELECT * FROM batch WHERE id_batch = ?', [$user['id_batch']]);
  if (!$b || hari_terakhir($b) < survei_mulai()) return false;
  if (!pertanyaan_aktif('kepuasan_peserta')) return false;
  return !sudah_isi($user['id_user'], 'kepuasan_peserta', $b['id_batch']);
}

// ================================================================ Peserta

function surveiSaya(array $data, array $user): array {
  wajib_peserta($user);
  $b = get_batch($user['id_batch']);
  $akhir = hari_terakhir($b);
  $trainer = array_map(fn($t) => $t + ['sudah' => sudah_isi($user['id_user'], 'evaluasi_trainer', $t['id_hari'] . '|' . $t['nama'])], trainer_hari($b));
  return [
    'kode_batch' => $b['kode_batch'], 'nama_pelatihan' => $b['nama_pelatihan'],
    'kepuasan' => ['buka' => now_()['date'] >= $akhir, 'mulai' => $akhir, 'sudah' => sudah_isi($user['id_user'], 'kepuasan_peserta', $b['id_batch'])],
    'trainer' => $trainer,
    'wajib_skl' => perlu_survei_skl($user),
    'pertanyaan' => ['kepuasan_peserta' => pertanyaan_aktif('kepuasan_peserta'), 'evaluasi_trainer' => pertanyaan_aktif('evaluasi_trainer')],
    'kategori_keluhan' => KATEGORI_KELUHAN,
  ];
}

/** Validasi jawaban: semua pertanyaan aktif wajib dijawab 1–5. */
function jawaban_valid(string $jenis, $jawaban): array {
  $jawaban = is_array($jawaban) ? $jawaban : [];
  $out = [];
  foreach (pertanyaan_aktif($jenis) as $p) {
    $v = (int)($jawaban[$p['kode']] ?? 0);
    if ($v < 1 || $v > 5) throw new LmsError('Mohon beri nilai 1–5 untuk: ' . $p['teks'] . '.');
    $out[$p['kode']] = $v;
  }
  if (!$out) throw new LmsError('Survei ini belum memiliki pertanyaan.');
  return $out;
}

function id_unik(string $awal, string $tabel, string $kolom): string {
  do { $id = $awal . '-' . substr(str_replace('-', '', now_()['date']), 2) . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)); }
  while (row("SELECT $kolom FROM $tabel WHERE $kolom = ?", [$id]));
  return $id;
}

function simpan_respons(array $b, string $jenis, array $jawaban, string $komentar, string $sumber, string $idHari = '', string $trainer = '', string $pengisi = ''): array {
  $now = now_();
  return insert('survei', [
    'id_respons' => id_unik('SRV', 'survei', 'id_respons'), 'id_batch' => $b['id_batch'], 'kode_batch' => $b['kode_batch'], 'jenis' => $jenis,
    'id_hari' => $idHari, 'trainer' => $trainer, 'jawaban' => json_encode($jawaban),
    'skor' => (string)round(array_sum($jawaban) / count($jawaban), 2), 'komentar' => mb_substr(trim($komentar), 0, 2000),
    'tanggal' => $now['date'], 'waktu' => $now['stamp'], 'sumber' => $sumber, 'pengisi' => mb_substr(trim($pengisi), 0, 150),
  ]);
}

function surveiKirim(array $data, array $user): array {
  wajib_peserta($user);
  $b = get_batch($user['id_batch']);
  $jenis = (string)($data['jenis'] ?? '');
  if ($jenis === 'kepuasan_peserta') {
    if (now_()['date'] < hari_terakhir($b)) throw new LmsError('Survei kepuasan dibuka pada hari terakhir pelatihan.');
    $kunci = $b['id_batch'];
    $idHari = '';
    $trainer = '';
  } elseif ($jenis === 'evaluasi_trainer') {
    $idHari = (string)($data['id_hari'] ?? '');
    $trainer = trim((string)($data['trainer'] ?? ''));
    $t = null;
    foreach (trainer_hari($b) as $x) if ($x['id_hari'] === $idHari && $x['nama'] === $trainer) $t = $x;
    if (!$t) throw new LmsError('Trainer tidak ditemukan di jadwal pelatihan.');
    if (!$t['buka']) throw new LmsError('Evaluasi trainer hari ini dibuka setelah sesi 2 dimulai.');
    $kunci = $idHari . '|' . $trainer;
  } else {
    throw new LmsError('Jenis survei tidak dikenal.');
  }
  if (sudah_isi($user['id_user'], $jenis, $kunci)) throw new LmsError('Anda sudah mengisi survei ini. Terima kasih!');
  $jawaban = jawaban_valid($jenis, $data['jawaban'] ?? []);
  db()->beginTransaction();
  try {
    // dicatat terpisah: jawaban tidak terhubung ke identitas peserta
    insert('survei_isi', ['id_isi' => md5($user['id_user'] . '|' . $jenis . '|' . $kunci), 'id_user' => $user['id_user'], 'id_batch' => $b['id_batch'],
      'jenis' => $jenis, 'kunci' => $kunci, 'waktu' => now_()['stamp']]);
    $r = simpan_respons($b, $jenis, $jawaban, (string)($data['komentar'] ?? ''), 'peserta', $idHari, $trainer);
    db()->commit();
  } catch (Throwable $e) {
    db()->rollBack();
    throw $e;
  }
  return ['id_respons' => $r['id_respons']];
}

// ================================================================ Keluhan

function simpan_keluhan(?array $b, string $kategori, string $uraian, string $sumber, string $idUser, string $nama, string $kontak): array {
  if (!isset(KATEGORI_KELUHAN[$kategori])) throw new LmsError('Pilih kategori keluhan.');
  $uraian = trim($uraian);
  if (mb_strlen($uraian) < 10) throw new LmsError('Mohon uraikan keluhan Anda (minimal 10 karakter).');
  $now = now_();
  return insert('keluhan', [
    'id_keluhan' => id_unik('KLH', 'keluhan', 'id_keluhan'), 'id_batch' => $b ? $b['id_batch'] : '', 'kode_batch' => $b ? $b['kode_batch'] : '',
    'kategori' => $kategori, 'uraian' => mb_substr($uraian, 0, 3000), 'tanggal' => $now['date'], 'waktu' => $now['stamp'], 'sumber' => $sumber,
    'id_user' => $idUser, 'nama_pelapor' => mb_substr(trim($nama), 0, 150), 'kontak_pelapor' => mb_substr(trim($kontak), 0, 150),
  ]);
}

function keluhanKirim(array $data, array $user): array {
  wajib_peserta($user);
  $b = get_batch($user['id_batch']);
  $k = simpan_keluhan($b, (string)($data['kategori'] ?? ''), (string)($data['uraian'] ?? ''), 'peserta', $user['id_user'],
    $user['nama'], (string)($user['no_hp'] ?? ''));
  return ['id_keluhan' => $k['id_keluhan']];
}

// ================================================================ Publik (klien, tanpa login)

/** Pembatas sederhana untuk formulir publik: maks. $maks kiriman per jam per alamat IP. */
function batas_publik(string $jenis, int $maks): void {
  $dir = STORAGE . '/tmp/batas';
  pastikan_folder($dir);
  $f = $dir . '/' . md5($jenis . '|' . ($_SERVER['REMOTE_ADDR'] ?? '')) . '.json';
  $t = array_values(array_filter((array)json_decode((string)@file_get_contents($f), true), fn($x) => $x > time() - 3600));
  if (count($t) >= $maks) throw new LmsError('Terlalu banyak kiriman dari perangkat ini. Coba lagi dalam 1 jam.');
  $t[] = time();
  @file_put_contents($f, json_encode($t));
}

function rahasia_survei(): string {
  $s = pengaturan('survei_rahasia');
  if ($s === '') { $s = bin2hex(random_bytes(24)); pengaturan_simpan('survei_rahasia', $s); }
  return $s;
}

/** Kode link evaluasi klien per batch (tetap, tidak bisa ditebak). */
function kode_klien(array $b): string {
  return substr(strtr(base64_encode(hash_hmac('sha256', 'klien|' . $b['id_batch'], rahasia_survei(), true)), '+/', 'xy'), 0, 10);
}

function batch_dari_kode_klien(string $k): array {
  if (!preg_match('/^[A-Za-z0-9]{10}$/', $k)) throw new LmsError('Link evaluasi tidak valid. Mohon minta link terbaru ke PIC pelatihan.');
  foreach (rows('SELECT * FROM batch') as $b) if (hash_equals(kode_klien($b), $k)) return $b;
  throw new LmsError('Link evaluasi tidak valid. Mohon minta link terbaru ke PIC pelatihan.');
}

function surveiKlienInfo(array $data): array {
  $b = batch_dari_kode_klien((string)($data['k'] ?? ''));
  $akhir = hari_terakhir($b);
  return ['kode_batch' => $b['kode_batch'], 'judul' => $b['nama_pelatihan'], 'periode' => periode_opening($b),
    'inhouse' => trim((string)($b['perusahaan_inhouse'] ?? '')), 'buka' => now_()['date'] >= $akhir, 'mulai' => $akhir,
    'pertanyaan' => pertanyaan_aktif('evaluasi_klien')];
}

function surveiKlienKirim(array $data): array {
  if (trim((string)($data['situs'] ?? '')) !== '') return ['id_respons' => '-']; // isian jebakan robot
  $b = batch_dari_kode_klien((string)($data['k'] ?? ''));
  if (now_()['date'] < hari_terakhir($b)) throw new LmsError('Evaluasi klien dibuka pada hari terakhir pelatihan.');
  batas_publik('klien', 10);
  $r = simpan_respons($b, 'evaluasi_klien', jawaban_valid('evaluasi_klien', $data['jawaban'] ?? []), (string)($data['komentar'] ?? ''), 'klien',
    '', '', (string)($data['pengisi'] ?? ''));
  return ['id_respons' => $r['id_respons']];
}

function keluhanPublikInfo(array $data): array {
  return ['kategori' => KATEGORI_KELUHAN];
}

function keluhanPublikKirim(array $data): array {
  if (trim((string)($data['situs'] ?? '')) !== '') return ['id_keluhan' => '-'];
  $kode = trim((string)($data['kode_batch'] ?? ''));
  $b = null;
  if ($kode !== '') {
    $b = row('SELECT * FROM batch WHERE LOWER(kode_batch) = ?', [mb_strtolower($kode)]);
    if (!$b) throw new LmsError('Kode kelas "' . $kode . '" tidak ditemukan. Kosongkan bila tidak tahu.');
  }
  $nama = trim((string)($data['nama'] ?? ''));
  $kontak = trim((string)($data['kontak'] ?? ''));
  if ($nama === '' || $kontak === '') throw new LmsError('Mohon isi nama dan kontak (No. HP / email) untuk tindak lanjut.');
  batas_publik('keluhan', 10);
  $k = simpan_keluhan($b, (string)($data['kategori'] ?? ''), (string)($data['uraian'] ?? ''), 'publik', '', $nama, $kontak);
  return ['id_keluhan' => $k['id_keluhan']];
}

// ================================================================ PIC / Admin: rekap

function rata_aspek(array $list, array $pertanyaan): array {
  $out = [];
  foreach ($pertanyaan as $p) {
    $v = [];
    foreach ($list as $r) { $j = json_decode($r['jawaban'], true); if (isset($j[$p['kode']])) $v[] = (int)$j[$p['kode']]; }
    if ($v || $p['aktif']) $out[] = ['kode' => $p['kode'], 'teks' => $p['teks'], 'rata' => $v ? round(array_sum($v) / count($v), 2) : null, 'n' => count($v)];
  }
  return $out;
}

function rata_skor(array $list): ?float {
  return $list ? round(array_sum(array_map(fn($r) => (float)$r['skor'], $list)) / count($list), 2) : null;
}

function surveiRekap(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $p = pertanyaan_survei();
  $semua = rows('SELECT * FROM survei WHERE id_batch = ? ORDER BY waktu DESC', [$b['id_batch']]);
  $per = ['kepuasan_peserta' => [], 'evaluasi_trainer' => [], 'evaluasi_klien' => []];
  foreach ($semua as $r) if (isset($per[$r['jenis']])) $per[$r['jenis']][] = $r;
  $trainer = [];
  foreach ($per['evaluasi_trainer'] as $r) $trainer[$r['trainer']][] = $r;
  $jadwal = [];
  foreach (trainer_hari($b) as $t) $jadwal[$t['nama']] = true;
  foreach (array_keys($jadwal) as $n) $trainer[$n] = $trainer[$n] ?? [];
  $akhir = hari_terakhir($b);
  return [
    'kepuasan' => ['n' => count($per['kepuasan_peserta']), 'rata' => rata_skor($per['kepuasan_peserta']), 'aspek' => rata_aspek($per['kepuasan_peserta'], $p['kepuasan_peserta'])],
    'trainer' => ['n' => count($per['evaluasi_trainer']), 'rata' => rata_skor($per['evaluasi_trainer']),
      'list' => array_map(fn($n, $l) => ['nama' => $n, 'n' => count($l), 'rata' => rata_skor($l), 'aspek' => rata_aspek($l, $p['evaluasi_trainer'])], array_keys($trainer), $trainer)],
    'klien' => ['n' => count($per['evaluasi_klien']), 'rata' => rata_skor($per['evaluasi_klien']), 'aspek' => rata_aspek($per['evaluasi_klien'], $p['evaluasi_klien']),
      'kode' => kode_klien($b)],
    'komentar' => array_values(array_map(fn($r) => ['jenis' => $r['jenis'], 'tanggal' => $r['tanggal'], 'trainer' => $r['trainer'], 'komentar' => $r['komentar'],
      'pengisi' => $r['jenis'] === 'evaluasi_klien' ? $r['pengisi'] : ''], array_filter($semua, fn($r) => trim($r['komentar']) !== ''))),
    'peserta' => (int)row("SELECT COUNT(*) AS n FROM pengguna WHERE role = 'peserta' AND id_batch = ? AND aktif <> 'tidak'", [$b['id_batch']])['n'],
    'buka_mulai' => $akhir, 'wajib_skl' => $akhir >= survei_mulai(),
    'keluhan' => (int)row('SELECT COUNT(*) AS n FROM keluhan WHERE id_batch = ?', [$b['id_batch']])['n'],
  ];
}

/** Pengingat Dashboard PIC: jumlah keluhan saja (isi & tindak lanjut di aplikasi SMM). */
function pengingat_keluhan(array $b): array {
  try {
    $n = (int)row('SELECT COUNT(*) AS n FROM keluhan WHERE id_batch = ?', [$b['id_batch']])['n'];
  } catch (Throwable $e) {
    return [];
  }
  return $n ? [pengingat_('warn', $n . ' keluhan masuk untuk batch ini. Detail & tindak lanjut ditangani Admin SMM.', 'survei')] : [];
}

// ================================================================ Untuk API SMM (tanpa data pribadi)

/** Menyamarkan data pribadi yang mungkin diketik di komentar/uraian: email, NIK/nomor panjang, no. HP, dan nama peserta batch. */
function samarkan_pribadi(string $t, array $nama = []): string {
  $t = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email disamarkan]', $t);
  $t = preg_replace('/(?<!\d)\d{16}(?!\d)/', '[NIK disamarkan]', $t);
  $t = preg_replace('/(?<![\w])(?:\+?62|0)[\s.\-]?8(?:[\s.\-]?\d){7,12}(?!\d)/', '[no. HP disamarkan]', $t);
  $t = preg_replace('/(?<!\d)\d{10,}(?!\d)/', '[nomor disamarkan]', $t);
  foreach ($nama as $n) {
    $n = trim($n);
    if (mb_strlen($n) >= 4) $t = preg_replace('/' . preg_quote($n, '/') . '/iu', '[nama disamarkan]', $t);
  }
  return $t;
}

/** Nama peserta per batch (untuk disamarkan dari komentar). */
function nama_peserta_batch(string $idBatch): array {
  static $cache = [];
  if ($idBatch === '') return [];
  if (!isset($cache[$idBatch])) {
    $n = array_column(rows("SELECT nama FROM pengguna WHERE role = 'peserta' AND id_batch = ?", [$idBatch]), 'nama');
    usort($n, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a)); // nama panjang lebih dulu
    $cache[$idBatch] = $n;
  }
  return $cache[$idBatch];
}
