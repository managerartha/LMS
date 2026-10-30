<?php
/**
 * Surat Keterangan Lulus (SKL).
 * PIC/Admin menerbitkan SKL per peserta (semua atau sebagian); peserta hanya bisa mengunduh SKL yang sudah terbit.
 * Nomor SKL unik dan tetap (tidak berubah walaupun SKL ditahan lalu diterbitkan lagi).
 * PDF dibuat saat diunduh dari data terbaru peserta & batch, dengan tanggal terbit yang tersimpan.
 */

const JENIS_PELATIHAN = ['Offline', 'Online', 'Blended'];
const ROMAWI = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

function jenis_pelatihan(array $batch): string {
  return in_array($batch['jenis_pelatihan'], JENIS_PELATIHAN, true) ? $batch['jenis_pelatihan'] : 'Offline';
}

/**
 * Tanggal lahir dari berbagai format ketikan/Excel: 1990-01-12, 12-01-1990, 12/1/1990, 12.01.1990,
 * '12 Januari 1990', '12 Jan 1990'. Mengembalikan 'yyyy-mm-dd'; '' jika kosong. Gagal → LmsError.
 */
function parse_tgl_lahir(string $s, string $nama = ''): string {
  $s = trim($s);
  if ($s === '') return '';
  $y = $m = $d = 0;
  if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $s, $x)) [$y, $m, $d] = [(int)$x[1], (int)$x[2], (int)$x[3]];
  elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $s, $x)) [$d, $m, $y] = [(int)$x[1], (int)$x[2], (int)$x[3]];
  elseif (preg_match('/^(\d{1,2})\s+([A-Za-z]+)\.?\s+(\d{4})$/u', $s, $x)) {
    $nb = strtolower(substr($x[2], 0, 3));
    $map = ['jan' => 1, 'feb' => 2, 'peb' => 2, 'mar' => 3, 'apr' => 4, 'mei' => 5, 'may' => 5, 'jun' => 6, 'jul' => 7,
      'agu' => 8, 'agt' => 8, 'aug' => 8, 'sep' => 9, 'okt' => 10, 'oct' => 10, 'nov' => 11, 'nop' => 11, 'des' => 12, 'dec' => 12];
    [$d, $m, $y] = [(int)$x[1], $map[$nb] ?? 0, (int)$x[3]];
  }
  if (!$y || !checkdate($m, $d, $y) || $y < 1900 || $y > (int)now_()['date']) {
    throw new LmsError('Tanggal lahir tidak dikenali' . ($nama !== '' ? " ($nama)" : '') . ': "' . $s . '". Contoh: 12-01-1990 atau 12 Januari 1990.');
  }
  return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

function skl_of(string $idUser): ?array {
  return row('SELECT * FROM skl WHERE id_skl = ?', ['k' . $idUser]);
}

function skl_view(?array $s): array {
  return $s ? ['terbit' => $s['status'] === 'terbit', 'status' => $s['status'], 'nomor' => $s['nomor'], 'tgl_terbit' => $s['tgl_terbit']]
    : ['terbit' => false, 'status' => '', 'nomor' => '', 'tgl_terbit' => ''];
}

/** Nomor SKL berikutnya sesuai format di Data perusahaan, urut per tahun penerbitan. */
function skl_nomor_baru(array $batch, string $tanggal): array {
  [$y, $m] = array_map('intval', explode('-', $tanggal));
  $urut = (int)(row('SELECT MAX(CAST(urut AS UNSIGNED)) AS n FROM skl WHERE tahun = ?', [(string)$y])['n'] ?? 0) + 1;
  $format = org_()['format_skl'] ?: ORG_DEFAULT['format_skl'];
  if (!str_contains($format, '{no}')) $format = '{no}/' . $format;
  $nomor = strtr($format, ['{no}' => sprintf('%03d', $urut), '{bulan}' => ROMAWI[$m - 1], '{bulan_angka}' => sprintf('%02d', $m),
    '{tahun}' => (string)$y, '{batch}' => $batch['kode_batch']]);
  return [$urut, (string)$y, $nomor];
}

// ---------------------------------------------------------------- Peserta

function sklSaya(array $data, array $user): array {
  wajib_peserta($user);
  return skl_view(skl_of($user['id_user']));
}

function sklUnduh(array $data, array $user): array {
  if ($user['role'] === 'peserta') {
    $u = $user;
  } else {
    $u = peserta_for_pic($user, (string)($data['id_user'] ?? ''));
  }
  $s = skl_of($u['id_user']);
  if (!$s || $s['status'] !== 'terbit') {
    throw new LmsError($user['role'] === 'peserta' ? 'SKL Anda belum diterbitkan. Silakan hubungi PIC pelatihan.' : 'SKL peserta ini belum diterbitkan.');
  }
  $batch = get_batch($u['id_batch']);
  $nama = preg_replace('#[\\\\/:*?"<>|]#', '-', 'SKL ' . $u['kode_login'] . ' - ' . $u['nama'] . '.pdf');
  if ($user['role'] === 'peserta') log_($user, 'skl_unduh', $s['nomor']);
  return ['nama' => $nama, 'data' => base64_encode(html_ke_pdf(skl_html($batch, [[$u, $s]])))];
}

// ---------------------------------------------------------------- PIC / Admin

function sklList(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $skl = [];
  foreach (rows('SELECT * FROM skl WHERE id_batch = ?', [$b['id_batch']]) as $s) $skl[$s['id_user']] = $s;
  return [
    'jenis_pelatihan' => jenis_pelatihan($b),
    'periode' => rentang_tgl($b['tgl_mulai'], $b['tgl_selesai']),
    'peserta' => array_map(fn($u) => ['id_user' => $u['id_user'], 'no_absen' => $u['no_absen'], 'nama' => $u['nama'],
      'kode_login' => $u['kode_login'], 'tempat_lahir' => $u['tempat_lahir'], 'tgl_lahir' => $u['tgl_lahir'],
      'skl' => skl_view($skl[$u['id_user']] ?? null)], peserta_batch($b['id_batch'], false)),
  ];
}

/** Menyimpan jenis pelatihan batch dan tempat/tanggal lahir peserta. */
function sklSimpanData(array $data, array $user): bool {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  if (isset($data['jenis_pelatihan'])) {
    if (!in_array($data['jenis_pelatihan'], JENIS_PELATIHAN, true)) throw new LmsError('Jenis pelatihan tidak valid.');
    update('batch', $b, ['jenis_pelatihan' => $data['jenis_pelatihan']]);
  }
  $ubah = [];
  foreach ((array)($data['peserta'] ?? []) as $p) {
    $u = row("SELECT * FROM pengguna WHERE id_user = ? AND role = 'peserta' AND id_batch = ?", [(string)($p['id_user'] ?? ''), $b['id_batch']]);
    if (!$u) continue;
    $ubah[] = [$u, ['tempat_lahir' => mb_substr(preg_replace('/\s+/', ' ', trim((string)($p['tempat_lahir'] ?? ''))), 0, 100),
      'tgl_lahir' => parse_tgl_lahir((string)($p['tgl_lahir'] ?? ''), $u['nama'])]];
  }
  foreach ($ubah as [$u, $c]) update('pengguna', $u, $c); // semua tanggal sudah valid sebelum ada yang disimpan
  log_($user, 'skl_data', $b['kode_batch']);
  return true;
}

/** Menerbitkan SKL peserta terpilih. Peserta tanpa tempat/tanggal lahir dilewati dan dilaporkan. */
function sklTerbitkan(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $ids = array_values(array_unique(array_map('strval', (array)($data['id_users'] ?? []))));
  if (!$ids) throw new LmsError('Pilih minimal satu peserta.');
  $now = now_();
  $terbit = 0;
  $dilewati = [];
  foreach ($ids as $id) {
    $u = row("SELECT * FROM pengguna WHERE id_user = ? AND role = 'peserta' AND id_batch = ? AND aktif <> 'tidak'", [$id, $b['id_batch']]);
    if (!$u) continue;
    if ($u['tempat_lahir'] === '' || $u['tgl_lahir'] === '') { $dilewati[] = $u['nama']; continue; }
    $s = skl_of($id);
    if ($s && $s['status'] === 'terbit') { $terbit++; continue; } // sudah terbit, tanggal tidak diubah
    if ($s) {
      update('skl', $s, ['status' => 'terbit', 'tgl_terbit' => $now['date'], 'diubah_oleh' => $user['nama'], 'diubah_pada' => $now['stamp']]);
    } else {
      for ($coba = 0; ; $coba++) {
        [$urut, $tahun, $nomor] = skl_nomor_baru($b, $now['date']);
        try {
          insert('skl', ['id_skl' => 'k' . $id, 'id_batch' => $b['id_batch'], 'id_user' => $id, 'nomor' => $nomor, 'urut' => $urut,
            'tahun' => $tahun, 'status' => 'terbit', 'tgl_terbit' => $now['date'], 'diubah_oleh' => $user['nama'], 'diubah_pada' => $now['stamp']]);
          break;
        } catch (PDOException $e) {
          if ($coba >= 4) throw $e; // nomor bentrok dengan penerbitan bersamaan: ambil nomor berikutnya
        }
      }
    }
    $terbit++;
  }
  log_($user, 'skl_terbit', $b['kode_batch'] . ': ' . $terbit . ' peserta');
  return ['terbit' => $terbit, 'dilewati' => $dilewati];
}

/** Menahan (membatalkan sementara) SKL: peserta tidak bisa mengunduh. Nomor tetap dicadangkan. */
function sklTahan(array $data, array $user): int {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $n = 0;
  foreach ((array)($data['id_users'] ?? []) as $id) {
    $s = skl_of((string)$id);
    if (!$s || $s['id_batch'] !== $b['id_batch'] || $s['status'] !== 'terbit') continue;
    update('skl', $s, ['status' => 'ditahan', 'diubah_oleh' => $user['nama'], 'diubah_pada' => now_()['stamp']]);
    $n++;
  }
  log_($user, 'skl_tahan', $b['kode_batch'] . ': ' . $n . ' peserta');
  return $n;
}

/** Semua SKL terbit satu batch dalam satu PDF (untuk dicetak). */
function sklUnduhSemua(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $list = [];
  foreach (peserta_batch($b['id_batch'], false) as $u) {
    $s = skl_of($u['id_user']);
    if ($s && $s['status'] === 'terbit') $list[] = [$u, $s];
  }
  if (!$list) throw new LmsError('Belum ada SKL yang diterbitkan di batch ini.');
  $nama = preg_replace('#[\\\\/:*?"<>|]#', '-', 'SKL ' . $b['kode_batch'] . ' - ' . $b['nama_pelatihan'] . '.pdf');
  return ['nama' => $nama, 'data' => base64_encode(html_ke_pdf(skl_html($b, $list)))];
}

// ---------------------------------------------------------------- Format PDF

const SKL_CSS = '
  @page { margin: 15mm 20mm; }
  body { font-family: "Times New Roman", Times, serif; font-size: 12pt; color: #000; line-height: 1.45; }
  table { border-collapse: collapse; }
  .pb { page-break-before: always; }
  .judul { text-align: center; font-weight: bold; font-size: 15pt; text-decoration: underline; margin-top: 18px; letter-spacing: .5px; }
  .nomor { text-align: center; font-size: 12pt; margin-bottom: 22px; }
  table.data td { padding: 3px 6px; vertical-align: top; font-size: 12pt; }
  .kop-web { font-family: Helvetica, Arial, sans-serif; font-size: 8pt; color: #333; text-align: right; vertical-align: middle; }
  .lulus { text-align: center; font-size: 16pt; font-weight: bold; letter-spacing: 3px; margin: 14px 0; }
';

/** $list = [[pengguna, skl], ...]; satu halaman A4 per peserta. */
function skl_html(array $batch, array $list): string {
  $org = org_();
  $ttd = $org['ttd_id'] !== '' ? img_(file_data_url($org['ttd_id']), 'height="100"') : '<br><br><br><br><br>';
  $out = ['<html><head><meta charset="utf-8"><style>' . SKL_CSS . '</style></head><body>'];
  foreach ($list as $i => [$u, $s]) {
    $ttl = trim($u['tempat_lahir'] . ($u['tgl_lahir'] !== '' ? ', ' . tgl_id($u['tgl_lahir']) : ''));
    $out[] = ($i ? '<div class="pb"></div>' : '') . kop($org) .
      '<div class="judul">SURAT KETERANGAN LULUS</div>' .
      '<div class="nomor">Nomor: ' . h_($s['nomor']) . '</div>' .
      '<p>Yang bertanda tangan di bawah ini, ' . h_($org['jabatan']) . ' ' . h_($org['nama']) .
      ' selaku penyelenggara pelatihan (Nomor SKP ' . h_(preg_replace('/^No\.?\s*/i', '', $org['nomor_skp'])) . '), menerangkan bahwa:</p>' .
      '<table class="data" style="margin-left:30px">' .
      '<tr><td style="width:190px">Nama</td><td style="width:10px">:</td><td><b>' . h_(mb_strtoupper($u['nama'])) . '</b></td></tr>' .
      '<tr><td>Tempat, Tanggal Lahir</td><td>:</td><td>' . h_($ttl ?: '-') . '</td></tr></table>' .
      '<p>telah mengikuti seluruh rangkaian pelatihan dan dinyatakan:</p>' .
      '<div class="lulus">LULUS</div>' .
      '<p>pada pelatihan berikut:</p>' .
      '<table class="data" style="margin-left:30px">' .
      '<tr><td style="width:190px">Judul Pelatihan</td><td style="width:10px">:</td><td><b>' . h_($batch['nama_pelatihan']) . '</b></td></tr>' .
      '<tr><td>Periode Pelatihan</td><td>:</td><td>' . h_(rentang_tgl($batch['tgl_mulai'], $batch['tgl_selesai'])) . '</td></tr>' .
      '<tr><td>Jenis Pelatihan</td><td>:</td><td>' . h_(jenis_pelatihan($batch)) . '</td></tr></table>' .
      '<p>Demikian surat keterangan ini dibuat untuk dapat dipergunakan sebagaimana mestinya.</p><br>' .
      '<table style="width:100%"><tr><td style="width:55%"></td><td style="text-align:center">' .
      h_($org['kota']) . ', ' . h_(tgl_id($s['tgl_terbit'])) . '<br>' . h_($org['nama']) . '<br>' . $ttd .
      '<br><b><u>' . h_($org['penandatangan']) . '</u></b><br>' . h_($org['jabatan']) . '</td></tr></table>';
  }
  $out[] = '</body></html>';
  return implode("\n", $out);
}
