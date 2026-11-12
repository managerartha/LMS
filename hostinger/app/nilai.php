<?php
/**
 * Nilai ujian peserta.
 * - Kemnaker: nilai diketik PIC/Admin per komponen (kategori & komponen mengikuti template program, bisa disesuaikan per batch).
 *   Nilai rata-rata = rata-rata SEMUA komponen (dibulatkan), lulus bila ≥ batas lulus. Rekap PDF format Artha (ditandatangani PIC)
 *   bisa diunduh dan ikut masuk laporan resmi.
 * - BNSP: PIC/Admin upload satu PDF form nilai dari asesor (berisi semua peserta).
 * - Peserta melihat nilai setelah dipublikasikan: Kemnaker hanya nilainya sendiri; BNSP mengunduh PDF asesor.
 */

const MODE_NILAI = ['kemnaker' => 'Kemnaker — nilai diketik', 'bnsp' => 'BNSP — upload PDF dari asesor'];
const KOMPONEN_BAWAAN = [
  ['kategori' => 'Ujian Seminar', 'nama' => 'Praktek'], ['kategori' => 'Ujian Seminar', 'nama' => 'Laporan'],
  ['kategori' => 'Ujian Seminar', 'nama' => 'Paparan'], ['kategori' => 'Ujian Seminar', 'nama' => 'Wawancara'],
  ['kategori' => 'Ujian Teori', 'nama' => 'Teori'],
];
const BATAS_LULUS_BAWAAN = 65;
const NILAI_PDF_MAX_MB = 20;

// ---------------------------------------------------------------- Data dasar

/** Rapikan daftar komponen: [{id, kategori, nama}], id tetap untuk komponen lama. */
function rapikan_komponen($list): array {
  $bersih = [];
  foreach (is_array($list) ? $list : [] as $k) {
    $nama = mb_substr(preg_replace('/\s+/', ' ', trim((string)($k['nama'] ?? ''))), 0, 40);
    if ($nama === '') continue;
    $bersih[] = ['id' => (string)($k['id'] ?? ''), 'kategori' => mb_substr(preg_replace('/\s+/', ' ', trim((string)($k['kategori'] ?? ''))), 0, 40), 'nama' => $nama];
  }
  $bersih = array_slice($bersih, 0, 15);
  $pakai = [];
  foreach ($bersih as $i => $k) { // id lama dipertahankan (nilai yang sudah diisi tetap terhubung)
    if (preg_match('/^k\d{1,4}$/', $k['id']) && !isset($pakai[$k['id']])) $pakai[$k['id']] = true; else $bersih[$i]['id'] = '';
  }
  foreach ($bersih as $i => $k) {
    if ($k['id'] !== '') continue;
    $n = 1;
    while (isset($pakai['k' . $n])) $n++;
    $bersih[$i]['id'] = 'k' . $n;
    $pakai['k' . $n] = true;
  }
  return $bersih;
}

function batas_lulus($v): float {
  $n = is_numeric($v) ? (float)$v : BATAS_LULUS_BAWAAN;
  return max(0, min(100, $n));
}

/** Template nilai program (Admin); bawaan Ujian Seminar + Ujian Teori, batas 65. */
function template_nilai(string $idProgram): array {
  $r = $idProgram !== '' ? row('SELECT * FROM nilai_program WHERE id_program = ?', [$idProgram]) : null;
  return ['komponen' => $r ? rapikan_komponen(json_decode($r['komponen'], true)) : rapikan_komponen(KOMPONEN_BAWAAN),
    'batas_lulus' => $r ? batas_lulus($r['batas_lulus']) : (float)BATAS_LULUS_BAWAAN, 'tersimpan' => (bool)$r];
}

/** Pengaturan nilai batch (+ bawaan dari template program & lembaga batch). */
function setelan_nilai(array $b): array {
  $r = row('SELECT * FROM nilai_batch WHERE id_batch = ?', [$b['id_batch']]);
  if ($r) {
    return ['mode' => $r['mode'], 'komponen' => rapikan_komponen(json_decode($r['komponen'], true)), 'batas_lulus' => batas_lulus($r['batas_lulus']),
      'judul_rekap' => $r['judul_rekap'], 'catatan' => $r['catatan_rekap'], 'terbit' => $r['terbit'] === 'ya', 'terbit_oleh' => $r['terbit_oleh'],
      'terbit_pada' => $r['terbit_pada'], 'file_bnsp' => $r['file_bnsp'], 'nama_file_bnsp' => $r['nama_file_bnsp'], 'tersimpan' => true, 'rec' => $r];
  }
  $t = template_nilai($b['id_program']);
  $lembaga = lembaga_skl($b);
  $info = info_batch($b);
  return ['mode' => $lembaga === 'BNSP' ? 'bnsp' : ($lembaga === 'KEMNAKER RI' ? 'kemnaker' : ''), 'komponen' => $t['komponen'], 'batas_lulus' => $t['batas_lulus'],
    'judul_rekap' => trim(($info['bidang_pembinaan'] ?: $b['nama_pelatihan']) . ' Sertifikasi Kemnaker RI'), 'catatan' => '', 'terbit' => false,
    'terbit_oleh' => '', 'terbit_pada' => '', 'file_bnsp' => '', 'nama_file_bnsp' => '', 'tersimpan' => false, 'rec' => null];
}

function simpan_setelan_nilai(array $b, array $c, array $user): void {
  $r = row('SELECT * FROM nilai_batch WHERE id_batch = ?', [$b['id_batch']]);
  $c += ['diubah_oleh' => $user['nama'], 'diubah_pada' => now_()['stamp']];
  if ($r) { update('nilai_batch', $r, $c); return; }
  $s = setelan_nilai($b);
  insert('nilai_batch', array_merge(['id_batch' => $b['id_batch'], 'mode' => $s['mode'], 'komponen' => json_encode($s['komponen'], JSON_UNESCAPED_UNICODE),
    'batas_lulus' => (string)$s['batas_lulus'], 'judul_rekap' => $s['judul_rekap'], 'catatan_rekap' => '', 'terbit' => '', 'terbit_oleh' => '', 'terbit_pada' => '',
    'file_bnsp' => '', 'nama_file_bnsp' => ''], $c));
}

/** Angka nilai: '' = belum diisi; selain itu 0–100 (boleh desimal). */
function angka_nilai($v, string $nama = ''): string {
  $v = str_replace(',', '.', trim((string)$v));
  if ($v === '') return '';
  if (!is_numeric($v) || (float)$v < 0 || (float)$v > 100) throw new LmsError('Nilai harus angka 0–100' . ($nama !== '' ? " ($nama)" : '') . '.');
  return (string)(round((float)$v, 2) + 0);
}

/** Hitung satu peserta: nilai per komponen, rata-rata (dibulatkan), lulus, keterangan. */
function hitung_nilai(array $set, ?array $rec): array {
  $isi = $rec ? (json_decode($rec['isi_nilai'], true) ?: []) : [];
  $tidakIkut = $rec && $rec['tidak_ikut'] === 'ya';
  $nilai = [];
  $lengkap = true;
  $jumlah = 0.0;
  foreach ($set['komponen'] as $k) {
    $v = $tidakIkut ? '0' : (string)($isi[$k['id']] ?? '');
    $nilai[$k['id']] = $v;
    if ($v === '') $lengkap = false; else $jumlah += (float)$v;
  }
  $rata = $set['komponen'] && ($lengkap || $tidakIkut) ? (int)round($jumlah / count($set['komponen'])) : null;
  $lulus = !$tidakIkut && $rata !== null && $rata >= $set['batas_lulus'];
  $auto = $rata === null ? '' : ($lulus ? 'LULUS' : 'TIDAK LULUS');
  $ket = $rec && trim($rec['keterangan']) !== '' ? trim($rec['keterangan']) : $auto;
  return ['nilai' => $nilai, 'rata' => $rata, 'lulus' => $lulus, 'lengkap' => $rata !== null, 'tidak_ikut' => $tidakIkut,
    'keterangan' => $ket, 'keterangan_auto' => $auto, 'keterangan_manual' => $rec ? $rec['keterangan'] : ''];
}

function nilai_of(string $idUser): ?array {
  return row('SELECT * FROM nilai WHERE id_nilai = ?', ['n' . $idUser]);
}

/** Semua peserta aktif batch + hasil perhitungan. */
function daftar_nilai(array $b, array $set): array {
  $rec = [];
  foreach (rows('SELECT * FROM nilai WHERE id_batch = ?', [$b['id_batch']]) as $r) $rec[$r['id_user']] = $r;
  return array_map(fn($u) => ['u' => $u] + hitung_nilai($set, $rec[$u['id_user']] ?? null), peserta_batch($b['id_batch'], false));
}

/** Jumlah peserta lulus (untuk laporan); null bila nilai Kemnaker belum diisi. */
function jumlah_lulus_nilai(array $b): ?int {
  try {
    $set = setelan_nilai($b);
    if ($set['mode'] !== 'kemnaker' || !row('SELECT id_nilai FROM nilai WHERE id_batch = ? LIMIT 1', [$b['id_batch']])) return null;
    return count(array_filter(daftar_nilai($b, $set), fn($x) => $x['lulus']));
  } catch (Throwable $e) {
    return null;
  }
}

// ---------------------------------------------------------------- PIC & Admin

function nilaiData(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $set = setelan_nilai($b);
  $list = daftar_nilai($b, $set);
  $t = template_nilai($b['id_program']);
  return [
    'mode' => $set['mode'], 'mode_pilihan' => MODE_NILAI, 'komponen' => $set['komponen'], 'batas_lulus' => $set['batas_lulus'],
    'judul_rekap' => $set['judul_rekap'], 'catatan' => $set['catatan'], 'catatan_otomatis' => catatan_otomatis($list),
    'terbit' => $set['terbit'], 'terbit_oleh' => $set['terbit_oleh'], 'terbit_pada' => $set['terbit_pada'], 'tersimpan' => $set['tersimpan'],
    'file_bnsp' => $set['file_bnsp'] !== '', 'nama_file_bnsp' => $set['nama_file_bnsp'],
    'template' => $t + ['program' => $b['id_program'] !== '' ? (row('SELECT nama FROM program WHERE id_program = ?', [$b['id_program']])['nama'] ?? '') : ''],
    'periode' => tanggal_batch_teks($b),
    'peserta' => array_map(fn($x) => ['id_user' => $x['u']['id_user'], 'no_absen' => $x['u']['no_absen'], 'nama' => $x['u']['nama'],
      'instansi' => $x['u']['instansi']] + array_diff_key($x, ['u' => 1]), $list),
    'ringkas' => ringkas_nilai($list),
  ];
}

function ringkas_nilai(array $list): array {
  return ['lulus' => count(array_filter($list, fn($x) => $x['lulus'])), 'tidak_lulus' => count(array_filter($list, fn($x) => $x['lengkap'] && !$x['lulus'])),
    'belum' => count(array_filter($list, fn($x) => !$x['lengkap'])), 'total' => count($list)];
}

/** Kalimat keterangan bawaan di bawah tabel rekap bila ada peserta tidak lulus. */
function catatan_otomatis(array $list): string {
  $tl = array_values(array_filter($list, fn($x) => $x['lengkap'] && !$x['lulus']));
  if (!$tl) return '';
  $nama = array_map(fn($x) => $x['u']['nama'], $tl);
  $gabung = count($nama) > 1 ? implode(', ', array_slice($nama, 0, -1)) . ' dan ' . end($nama) : $nama[0];
  return 'Peserta dengan keterangan Tidak Lulus. Peserta ' . $gabung . ' tidak lulus pada batch ini, mohon dihapus pada nomor permohonan ini.';
}

/** Simpan jenis penilaian, komponen, batas lulus, judul & keterangan rekap. */
function nilaiAtur(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $mode = (string)($data['mode'] ?? '');
  if ($mode !== '' && !isset(MODE_NILAI[$mode])) throw new LmsError('Jenis penilaian tidak dikenal.');
  $c = ['mode' => $mode];
  if (isset($data['komponen'])) {
    $k = rapikan_komponen($data['komponen']);
    if (!$k && $mode === 'kemnaker') throw new LmsError('Isi minimal satu komponen nilai.');
    $c['komponen'] = json_encode($k, JSON_UNESCAPED_UNICODE);
  }
  if (isset($data['batas_lulus'])) {
    if (!is_numeric(str_replace(',', '.', (string)$data['batas_lulus']))) throw new LmsError('Batas lulus harus angka.');
    $c['batas_lulus'] = (string)batas_lulus(str_replace(',', '.', (string)$data['batas_lulus']));
  }
  if (isset($data['judul_rekap'])) $c['judul_rekap'] = mb_substr(preg_replace('/\s+/', ' ', trim((string)$data['judul_rekap'])), 0, 150);
  if (isset($data['catatan'])) $c['catatan_rekap'] = mb_substr(trim((string)$data['catatan']), 0, 2000);
  simpan_setelan_nilai($b, $c, $user);
  log_($user, 'nilai_atur', $b['kode_batch'] . ' ' . $mode);
  return nilaiData(['id_batch' => $b['id_batch']], $user);
}

/** Simpan nilai peserta (Kemnaker). Semua angka diperiksa dulu sebelum ada yang disimpan. */
function nilaiSimpan(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $set = setelan_nilai($b);
  if (!$set['tersimpan']) simpan_setelan_nilai($b, [], $user);
  $ids = array_column($set['komponen'], 'id');
  $ubah = [];
  foreach ((array)($data['peserta'] ?? []) as $p) {
    $u = row("SELECT * FROM pengguna WHERE id_user = ? AND role = 'peserta' AND id_batch = ?", [(string)($p['id_user'] ?? ''), $b['id_batch']]);
    if (!$u) continue;
    $isi = [];
    foreach ($ids as $id) $isi[$id] = angka_nilai($p['nilai'][$id] ?? '', $u['nama']);
    $ubah[] = [$u, ['isi_nilai' => json_encode(array_filter($isi, fn($v) => $v !== ''), JSON_UNESCAPED_UNICODE), 'tidak_ikut' => !empty($p['tidak_ikut']) ? 'ya' : '',
      'keterangan' => mb_substr(preg_replace('/\s+/', ' ', trim((string)($p['keterangan'] ?? ''))), 0, 80)]];
  }
  $now = now_();
  foreach ($ubah as [$u, $c]) {
    $r = nilai_of($u['id_user']);
    $c += ['diubah_oleh' => $user['nama'], 'diubah_pada' => $now['stamp']];
    if ($r) update('nilai', $r, $c); else insert('nilai', ['id_nilai' => 'n' . $u['id_user'], 'id_batch' => $b['id_batch'], 'id_user' => $u['id_user']] + $c);
  }
  log_($user, 'nilai_simpan', $b['kode_batch'] . ': ' . count($ubah) . ' peserta');
  return nilaiData(['id_batch' => $b['id_batch']], $user);
}

/** Upload PDF form nilai BNSP (satu file berisi semua peserta); menggantikan file sebelumnya. */
function nilaiBnspUpload(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $nama = (string)($data['file']['nama'] ?? 'nilai.pdf');
  if (strtolower(pathinfo($nama, PATHINFO_EXTENSION)) !== 'pdf') throw new LmsError('File harus PDF.');
  if (!preg_match('#^data:([^;]*);base64,(.+)$#s', (string)($data['file']['data'] ?? ''), $m)) throw new LmsError('File tidak terbaca. Coba pilih ulang file.');
  $bytes = base64_decode($m[2], true);
  if ($bytes === false || !str_starts_with($bytes, '%PDF')) throw new LmsError('File bukan PDF yang valid.');
  if (strlen($bytes) > NILAI_PDF_MAX_MB * 1024 * 1024) throw new LmsError('File lebih dari ' . NILAI_PDF_MAX_MB . ' MB.');
  $set = setelan_nilai($b);
  $rel = simpan_bytes('storage/' . $b['folder'] . '/nilai', $bytes, 'Nilai BNSP ' . $b['kode_batch'] . '.pdf');
  simpan_setelan_nilai($b, ['mode' => 'bnsp', 'file_bnsp' => $rel, 'nama_file_bnsp' => mb_substr($nama, 0, 150)], $user);
  if ($set['file_bnsp'] !== '' && $set['file_bnsp'] !== $rel) hapus_file($set['file_bnsp']);
  log_($user, 'nilai_bnsp_upload', $b['kode_batch'] . ': ' . $nama);
  return nilaiData(['id_batch' => $b['id_batch']], $user);
}

/** Publikasikan / tarik publikasi nilai ke peserta. */
function nilaiTerbit(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $set = setelan_nilai($b);
  $terbit = !empty($data['terbit']);
  if ($terbit) {
    if ($set['mode'] === '') throw new LmsError('Pilih jenis penilaian terlebih dahulu.');
    if ($set['mode'] === 'bnsp' && $set['file_bnsp'] === '') throw new LmsError('Upload PDF nilai dari asesor terlebih dahulu.');
    if ($set['mode'] === 'kemnaker') {
      $belum = array_filter(daftar_nilai($b, $set), fn($x) => !$x['lengkap']);
      if ($belum) throw new LmsError(count($belum) . ' peserta belum lengkap nilainya (isi semua komponen atau centang "Tidak ikut ujian"): ' .
        implode(', ', array_slice(array_map(fn($x) => $x['u']['nama'], $belum), 0, 5)) . (count($belum) > 5 ? ', …' : '') . '.');
    }
  }
  $now = now_();
  simpan_setelan_nilai($b, ['terbit' => $terbit ? 'ya' : '', 'terbit_oleh' => $terbit ? $user['nama'] : '', 'terbit_pada' => $terbit ? $now['stamp'] : ''], $user);
  log_($user, $terbit ? 'nilai_publikasi' : 'nilai_tarik', $b['kode_batch']);
  return nilaiData(['id_batch' => $b['id_batch']], $user);
}

/** PDF rekap nilai Kemnaker (format Artha, mendatar) — PIC & Admin. */
function nilaiPdf(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $set = setelan_nilai($b);
  if ($set['mode'] !== 'kemnaker') throw new LmsError('Rekap PDF hanya untuk penilaian Kemnaker.');
  $nama = preg_replace('#[\\\\/:*?"<>|]#', '-', 'Nilai Ujian ' . $b['kode_batch'] . ' - ' . $b['nama_pelatihan'] . '.pdf');
  return ['nama' => $nama, 'data' => base64_encode(html_ke_pdf(rekap_nilai_html($b, $set, $user), [0, 0, 841.89, 595.28]))];
}

/** Unduh PDF nilai BNSP: PIC/Admin kapan saja; peserta setelah dipublikasikan. */
function nilaiUnduh(array $data, array $user): array {
  $b = $user['role'] === 'peserta' ? get_batch($user['id_batch']) : batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $set = setelan_nilai($b);
  if ($set['mode'] !== 'bnsp' || $set['file_bnsp'] === '') throw new LmsError('PDF nilai belum tersedia.');
  if ($user['role'] === 'peserta' && !$set['terbit']) throw new LmsError('Nilai belum dipublikasikan. Silakan hubungi PIC pelatihan.');
  $f = ROOT . '/' . $set['file_bnsp'];
  if (!is_file($f)) throw new LmsError('File nilai tidak ditemukan di server. Minta PIC mengupload ulang.');
  if ($user['role'] === 'peserta') log_($user, 'nilai_unduh', $b['kode_batch']);
  return ['nama' => preg_replace('#[\\\\/:*?"<>|]#', '-', 'Nilai BNSP ' . $b['kode_batch'] . '.pdf'), 'data' => base64_encode((string)file_get_contents($f))];
}

// ---------------------------------------------------------------- Template per program (Admin)

function nilaiTemplateList(array $data, array $user): array {
  return array_map(fn($p) => ['id_program' => $p['id_program'], 'nama' => $p['nama']] + template_nilai($p['id_program']),
    rows("SELECT * FROM program WHERE aktif <> 'tidak' ORDER BY nama"));
}

function nilaiTemplateSimpan(array $data, array $user): array {
  $p = row('SELECT * FROM program WHERE id_program = ?', [(string)($data['id_program'] ?? '')]);
  if (!$p) throw new LmsError('Pilih program.');
  $k = rapikan_komponen($data['komponen'] ?? []);
  if (!$k) throw new LmsError('Isi minimal satu komponen nilai.');
  if (!is_numeric(str_replace(',', '.', (string)($data['batas_lulus'] ?? '')))) throw new LmsError('Batas lulus harus angka.');
  $c = ['komponen' => json_encode($k, JSON_UNESCAPED_UNICODE), 'batas_lulus' => (string)batas_lulus(str_replace(',', '.', (string)$data['batas_lulus'])),
    'diubah_oleh' => $user['nama'], 'diubah_pada' => now_()['stamp']];
  $r = row('SELECT * FROM nilai_program WHERE id_program = ?', [$p['id_program']]);
  if ($r) update('nilai_program', $r, $c); else insert('nilai_program', ['id_program' => $p['id_program']] + $c);
  log_($user, 'nilai_template', $p['nama']);
  return nilaiTemplateList([], $user);
}

// ---------------------------------------------------------------- Peserta

/** Apakah peserta punya nilai yang sudah dipublikasikan (aman bila tabel belum ada). */
function nilai_aktif_peserta(array $user): bool {
  try {
    $r = row('SELECT mode, terbit FROM nilai_batch WHERE id_batch = ?', [$user['id_batch']]);
    return $r && $r['terbit'] === 'ya' && $r['mode'] !== '';
  } catch (Throwable $e) {
    return false;
  }
}

function nilaiSaya(array $data, array $user): array {
  wajib_peserta($user);
  $b = get_batch($user['id_batch']);
  $set = setelan_nilai($b);
  if (!$set['terbit'] || $set['mode'] === '') return ['terbit' => false];
  if ($set['mode'] === 'bnsp') return ['terbit' => true, 'mode' => 'bnsp', 'ada_file' => $set['file_bnsp'] !== ''];
  $h = hitung_nilai($set, nilai_of($user['id_user']));
  return ['terbit' => true, 'mode' => 'kemnaker', 'batas_lulus' => $set['batas_lulus'],
    'komponen' => array_map(fn($k) => $k + ['nilai' => $h['nilai'][$k['id']]], $set['komponen']),
    'rata' => $h['rata'], 'lulus' => $h['lulus'], 'keterangan' => $h['keterangan']];
}

// ---------------------------------------------------------------- Dashboard

function pengingat_nilai(array $b, string $fase): array {
  try {
    if ($fase !== 'selesai') return [];
    $set = setelan_nilai($b);
    if ($set['mode'] === '' || $set['terbit']) return [];
    if ($set['mode'] === 'bnsp') {
      return [pengingat_('info', $set['file_bnsp'] === '' ? 'PDF nilai BNSP dari asesor belum diupload.' : 'Nilai BNSP belum dipublikasikan ke peserta.', 'nilai')];
    }
    $r = ringkas_nilai(daftar_nilai($b, $set));
    return [pengingat_('info', $r['belum'] ? $r['belum'] . ' peserta belum diisi nilai ujiannya.' : 'Nilai ujian sudah lengkap, belum dipublikasikan ke peserta.', 'nilai')];
  } catch (Throwable $e) {
    return [];
  }
}

// ---------------------------------------------------------------- PDF rekap (format Artha Safety)

/** PIC yang menandatangani: PIC yang membuat (bila PIC batch), selain itu PIC batch pertama yang punya tanda tangan. */
function pic_penandatangan(array $b, array $user): ?array {
  $ids = pic_ids($b);
  if ($user['role'] === 'pic' && in_array($user['id_user'], $ids, true)) return $user;
  $pics = array_values(array_filter(array_map(fn($id) => row('SELECT * FROM pengguna WHERE id_user = ?', [$id]), $ids)));
  foreach ($pics as $p) if ($p['ttd_id'] !== '') return $p;
  return $pics[0] ?? null;
}

/** Judul + tabel nilai + keterangan + tanda tangan PIC. $kecil = versi di dalam laporan resmi (A4 tegak). */
function isi_rekap_nilai(array $b, array $set, array $list, ?array $pic, bool $kecil): string {
  $fs = $kecil ? 9.5 : (count($set['komponen']) > 6 ? 10.5 : 12);
  $nk = count($set['komponen']);
  $W = $kecil ? 100 : 100; // persen
  $wTetap = $kecil ? [6, 22, 17, 15] : [4.5, 20, 15.5, 15];
  $wNilai = ($W - array_sum($wTetap)) / ($nk + 1);
  $th = 'border:1px solid #000;background:#9cc2e4;font-weight:bold;text-align:center;vertical-align:middle;padding:2px 3px;';
  $td = 'border:1px solid #000;text-align:center;vertical-align:middle;padding:' . ($kecil ? '3pt 3pt' : '6pt 3pt') . ';';
  $html = '<div style="text-align:center;font-weight:bold;font-size:12pt;line-height:16.5pt;margin:' . ($kecil ? '4pt' : '6pt') . ' 0 12pt">Daftar Peserta<br>' .
    h_(jenis_pelatihan($b)) . ' Training<br>' . h_($set['judul_rekap']) . '<br>' . h_(tanggal_batch_teks($b, true)) . '</div>';
  $html .= '<table style="width:100%;border-collapse:collapse;font-size:' . $fs . 'pt;page-break-inside:auto"><thead><tr>' .
    '<th rowspan="2" style="' . $th . 'width:' . $wTetap[0] . '%">No.</th><th rowspan="2" style="' . $th . 'width:' . $wTetap[1] . '%">Nama</th>' .
    '<th rowspan="2" style="' . $th . 'width:' . $wTetap[2] . '%">Perusahaan</th><th rowspan="2" style="' . $th . 'width:' . $wTetap[3] . '%">Keterangan</th>' .
    '<th colspan="' . $nk . '" style="' . $th . '">Nilai</th><th rowspan="2" style="' . $th . 'width:' . round($wNilai, 2) . '%">Nilai<br>Rata-rata</th></tr><tr>';
  foreach ($set['komponen'] as $k) $html .= '<th style="' . $th . 'width:' . round($wNilai, 2) . '%">' . h_($k['nama']) . '</th>';
  $html .= '</tr></thead><tbody>';
  $fmt = fn($v) => $v === '' ? '' : (fmod((float)$v, 1.0) == 0.0 ? (string)(int)$v : str_replace('.', ',', (string)$v));
  foreach ($list as $i => $x) {
    $merah = $x['lengkap'] && !$x['lulus'] ? 'background:#ff0000;' : '';
    $html .= '<tr style="page-break-inside:avoid">' .
      '<td style="' . $td . $merah . '">' . ($i + 1) . '</td>' .
      '<td style="' . $td . $merah . '">' . h_(mb_strtoupper($x['u']['nama'])) . '</td>' .
      '<td style="' . $td . $merah . '">' . h_(mb_strtoupper(trim($x['u']['instansi']) ?: 'UMUM')) . '</td>' .
      '<td style="' . $td . $merah . '">' . h_(mb_strtoupper($x['keterangan'])) . '</td>';
    foreach ($set['komponen'] as $k) $html .= '<td style="' . $td . $merah . '">' . h_($fmt($x['nilai'][$k['id']])) . '</td>';
    $html .= '<td style="' . $td . $merah . '">' . ($x['rata'] === null ? '' : $x['rata']) . '</td></tr>';
  }
  $html .= '</tbody></table>';
  $catatan = trim($set['catatan']) !== '' ? trim($set['catatan']) : catatan_otomatis($list);
  if ($catatan !== '') {
    $c = nl2br(h_($catatan));
    $c = preg_replace('/(Tidak Lulus(?: Ujian[^.,<]*)?)/i', '<span style="background:#ff0000">$1</span>', $c, 1);
    $html .= '<div style="margin-top:16pt;font-size:12pt;line-height:16pt;page-break-inside:avoid">Keterangan :<br><br>' . $c . '</div>';
  }
  $org = org_();
  $ttd = $pic && $pic['ttd_id'] !== '' ? img_(file_data_url($pic['ttd_id']), 'height="60"') : '<div style="height:60pt"></div>';
  $html .= '<table style="width:100%;margin-top:18pt;page-break-inside:avoid;font-size:12pt"><tr><td style="width:62%"></td><td style="text-align:center">' .
    h_($org['kota']) . ', ' . h_(tgl_skl(batch_tgl_akhir($b))) . '<br>PIC Pelatihan<br>' . $ttd . '<br><b><u>' .
    h_($pic['nama'] ?? '(..............................)') . '</u></b></td></tr></table>';
  return $html;
}

/** Rekap nilai mandiri: A4 mendatar dengan kop surat Artha (logo, motif, alamat) di setiap halaman. */
function rekap_nilai_html(array $b, array $set, array $user): string {
  $org = org_();
  $list = daftar_nilai($b, $set);
  $latar = 'data:image/jpeg;base64,' . base64_encode((string)file_get_contents(__DIR__ . '/nilai-latar.jpg'));
  $foot = 'font-family:Helvetica,Arial,sans-serif;font-size:7.5pt;color:#222;text-align:right;line-height:10pt;';
  $css = '@page { margin: 80pt 64pt 100pt 58pt; } body { font-family: "Times New Roman", Times, serif; color: #000; }
    .tetap { position: fixed; } thead { display: table-header-group; }';
  return '<html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>' .
    '<div class="tetap" style="left:-58pt;top:-80pt;width:841.89pt;height:595.28pt;z-index:-1"><img src="' . $latar . '" style="width:841.89pt;height:595.28pt"></div>' .
    '<div class="tetap" style="left:500pt;top:-42pt;width:220pt;' . $foot . 'font-size:9pt">' . h_($org['website']) . '</div>' .
    '<div class="tetap" style="left:3.7pt;top:-16.4pt;width:717pt;border-top:0.6pt solid #000"></div>' .
    '<div class="tetap" style="left:430pt;top:428pt;width:262pt;' . $foot . '">' . h_($org['telepon']) . '<br><br>' . h_($org['alamat']) . '<br>' .
    h_($org['website']) . '<br>' . h_($org['email']) . '</div>' .
    isi_rekap_nilai($b, $set, $list, pic_penandatangan($b, $user), false) . '</body></html>';
}

/** Bagian "Rekap Nilai Ujian" di laporan resmi (hanya Kemnaker yang sudah ada nilainya). */
function nilai_laporan_resmi(array $b, array $org): string {
  try {
    $set = setelan_nilai($b);
    if ($set['mode'] !== 'kemnaker' || !row('SELECT id_nilai FROM nilai WHERE id_batch = ? LIMIT 1', [$b['id_batch']])) return '';
    $pic = pic_penandatangan($b, ['role' => '', 'id_user' => '']);
    return halaman($org, isi_rekap_nilai($b, $set, daftar_nilai($b, $set), $pic, true));
  } catch (Throwable $e) {
    return '';
  }
}
