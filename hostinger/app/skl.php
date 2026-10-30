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

/** Kode batch pada nomor SKL (mis. A06B087KEM); default kode batch LMS. */
function kode_skl(array $batch): string {
  return trim((string)(info_batch($batch)['kode_skl'] ?? '')) ?: $batch['kode_batch'];
}

/**
 * Nomor SKL berikutnya sesuai format di Data perusahaan, mis. 09/A06B087KEM/SKL/PT-ARSINDO/X/2026.
 * {no} = nomor urut dalam batch (2 digit), {kode} = kode batch untuk SKL, {bulan} = bulan Romawi saat terbit.
 */
function skl_nomor_baru(array $batch, string $tanggal, int $mulai = 0): array {
  [$y, $m] = array_map('intval', explode('-', $tanggal));
  $urut = max($mulai, (int)(row('SELECT MAX(CAST(urut AS UNSIGNED)) AS n FROM skl WHERE id_batch = ?', [$batch['id_batch']])['n'] ?? 0) + 1);
  $format = org_()['format_skl'];
  if ($format === '' || $format === '{no}/SKL/ASI/{bulan}/{tahun}') $format = ORG_DEFAULT['format_skl']; // format awal versi sebelumnya
  if (!str_contains($format, '{no}')) $format = '{no}/' . $format;
  $nomor = strtr($format, ['{no}' => sprintf('%02d', $urut), '{kode}' => kode_skl($batch), '{bulan}' => ROMAWI[$m - 1],
    '{bulan_angka}' => sprintf('%02d', $m), '{tahun}' => (string)$y, '{batch}' => $batch['kode_batch']]);
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
  return ['nama' => $nama, 'data' => base64_encode(html_ke_pdf(skl_html($batch, [[$u, $s]]), SKL_KERTAS))];
}

// ---------------------------------------------------------------- PIC / Admin

function sklList(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $skl = [];
  foreach (rows('SELECT * FROM skl WHERE id_batch = ?', [$b['id_batch']]) as $s) $skl[$s['id_user']] = $s;
  return [
    'jenis_pelatihan' => jenis_pelatihan($b),
    'kode_skl' => kode_skl($b), 'contoh_nomor' => skl_nomor_baru($b, now_()['date'])[2],
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
    $b = update('batch', $b, ['jenis_pelatihan' => $data['jenis_pelatihan']]);
  }
  if (isset($data['kode_skl'])) {
    $kode = mb_substr(trim((string)$data['kode_skl']), 0, 60);
    if (preg_match('#[/\\s]#', $kode)) throw new LmsError('Kode batch SKL tidak boleh berisi spasi atau garis miring.');
    $b = update('batch', $b, ['info_laporan' => json_encode(array_merge(info_batch($b), ['kode_skl' => $kode]), JSON_UNESCAPED_UNICODE)]);
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
      for ($coba = 0, $mulai = 0; ; $coba++) {
        [$urut, $tahun, $nomor] = skl_nomor_baru($b, $now['date'], $mulai);
        try {
          insert('skl', ['id_skl' => 'k' . $id, 'id_batch' => $b['id_batch'], 'id_user' => $id, 'nomor' => $nomor, 'urut' => $urut,
            'tahun' => $tahun, 'status' => 'terbit', 'tgl_terbit' => $now['date'], 'diubah_oleh' => $user['nama'], 'diubah_pada' => $now['stamp']]);
          break;
        } catch (PDOException $e) {
          if ($coba >= 20) throw $e;
          $mulai = $urut + 1; // nomor sudah dipakai (penerbitan bersamaan / kode batch sama): ambil nomor berikutnya
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
  return ['nama' => $nama, 'data' => base64_encode(html_ke_pdf(skl_html($b, $list), SKL_KERTAS))];
}

// ---------------------------------------------------------------- Format PDF (sesuai template Artha Safety)

/** Ukuran kertas template (point) dan jarak puncak huruf ke garis dasar teks 12pt. */
const SKL_KERTAS = [0, 0, 624, 870.5];
const SKL_ASCENT = 13.8;

/** 'yyyy-mm-dd' -> '04 Oktober 2003' */
function tgl_skl(string $s): string {
  [$y, $m, $d] = array_map('intval', explode('-', $s));
  return sprintf('%02d %s %d', $d, BULAN_ID[$m - 1], $y);
}

/** '19 – 30 Oktober 2026' / '28 Oktober – 08 November 2026' / tanggal lengkap jika beda tahun. */
function periode_skl(string $a, string $b): string {
  [$y1, $m1, $d1] = array_map('intval', explode('-', $a));
  [$y2, $m2, $d2] = array_map('intval', explode('-', $b));
  if ($a === $b) return tgl_skl($a);
  if ($y1 === $y2 && $m1 === $m2) return sprintf('%02d – %02d %s %d', $d1, $d2, BULAN_ID[$m1 - 1], $y1);
  if ($y1 === $y2) return sprintf('%02d %s – %02d %s %d', $d1, BULAN_ID[$m1 - 1], $d2, BULAN_ID[$m2 - 1], $y1);
  return tgl_skl($a) . ' – ' . tgl_skl($b);
}

/** Teks di posisi tetap: x = kiri (pt), y = garis dasar dari bawah halaman (pt), seperti di template. */
function skl_teks(float $x, float $y, string $html, string $gaya = ''): string {
  $top = SKL_KERTAS[3] - $y - SKL_ASCENT;
  return '<div style="position:absolute;left:' . $x . 'pt;top:' . round($top, 1) . 'pt;' . $gaya . '">' . $html . '</div>';
}

/** Teks rata tengah pada kolom selebar $w yang berpusat di $cx. */
function skl_tengah(float $cx, float $y, float $w, string $html): string {
  return skl_teks($cx - $w / 2, $y, $html, 'width:' . $w . 'pt;text-align:center;');
}

/** $list = [[pengguna, skl], ...]; satu halaman per peserta. */
function skl_html(array $batch, array $list): string {
  $org = org_();
  $latar = 'data:image/jpeg;base64,' . base64_encode((string)file_get_contents(__DIR__ . '/skl-latar.jpg'));
  $judul = $batch['nama_pelatihan'];
  // "Pembinaan Ahli K3 Umum" -> "Pembinaan <b>Ahli K3 Umum</b>", sertifikat "Ahli K3 Umum"
  $sertifikat = preg_match('/^pembinaan\s+(.+)$/iu', $judul, $x) ? $x[1] : $judul;
  $judulHtml = $sertifikat !== $judul ? h_(mb_substr($judul, 0, mb_strlen($judul) - mb_strlen($sertifikat))) . '<b>' . h_($sertifikat) . '</b>' : '<b>' . h_($judul) . '</b>';
  $periode = periode_skl($batch['tgl_mulai'], $batch['tgl_selesai']);
  $jenis = jenis_pelatihan($batch) . ' Training';
  $W = SKL_KERTAS[2];
  $H = SKL_KERTAS[3];
  $css = '@page { margin: 0; } body { margin: 0; font-family: "Times New Roman", Times, serif; font-size: 12pt; color: #000; }
    .hal { position: relative; width: ' . $W . 'pt; height: ' . ($H - 1) . 'pt; overflow: hidden; }
    .isi { position: absolute; left: 63pt; width: 499pt; line-height: 20.9pt; text-align: justify; }
    .isi p { margin: 0; text-indent: 36pt; }';
  $out = ['<html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>'];
  foreach ($list as $i => [$u, $s]) {
    $ttl = trim($u['tempat_lahir'] . ($u['tgl_lahir'] !== '' ? ', ' . tgl_skl($u['tgl_lahir']) : ''));
    $isiTop = $H - 428.6 - SKL_ASCENT - (20.9 - 12) / 2 - 0.7;
    $out[] = '<div class="hal"' . ($i < count($list) - 1 ? ' style="page-break-after:always"' : '') . '>' .
      '<img src="' . $latar . '" style="position:absolute;left:0;top:0;width:' . $W . 'pt;height:' . $H . 'pt">' .
      skl_tengah(313.5, 606.1, 500, 'NO : ' . h_($s['nomor'])) .
      skl_teks(99, 544.0, 'Yang bertanda tangan di bawah ini, ' . h_($org['jabatan']) . ' PT. Artha Safety Indonesia.', 'width:463pt;word-spacing:2.55pt;') .
      skl_teks(63, 523.2, 'Menerangkan bahwa :') .
      skl_teks(99, 481.9, 'Nama') . skl_teks(238, 481.9, ':') . skl_teks(244.3, 481.9, '<b>' . h_($u['nama']) . '</b>', 'width:318pt;') .
      skl_teks(99, 461.2, 'Tempat, tanggal lahir') . skl_teks(238, 461.2, ':') . skl_teks(244.3, 461.2, '<b>' . h_($ttl ?: '-') . '</b>', 'width:318pt;') .
      '<div class="isi" style="top:' . round($isiTop, 1) . 'pt">' .
      '<p style="margin-left:-5pt;margin-right:-1pt">Telah mengikuti Kegiatan ' . $judulHtml . ' Sertifikasi <b>KEMNAKER RI</b> yang diselenggarakan pada tanggal ' .
      h_($periode) . ' secara <i>' . h_($jenis) . '</i>.</p>' .
      '<p>Surat keterangan ini kami terbitkan dan menjadi bukti bahwa peserta sudah menyelesaikan pelatihan dengan <b>Predikat BAIK</b> dan dinyatakan <b>LULUS</b>. ' .
      'Adapun surat ini untuk menerangkan bahwa Sertifikat <b>' . h_($sertifikat) . '</b> sedang dalam proses penerbitan oleh <b>KEMNAKER RI</b>.</p>' .
      '<p style="word-spacing:-0.6pt">Demikian surat keterangan ini kami sampaikan untuk dapat dipergunakan sebagaimana mestinya. Atas perhatiannya kami ucapkan terimakasih.</p></div>' .
      skl_tengah(453.8, 221.5, 220, h_($org['kota']) . ', ' . h_(tgl_skl($s['tgl_terbit']))) .
      skl_tengah(457.2, 200.8, 220, 'PT. Artha Safety Indonesia') .
      skl_tengah(459.5, 118.0, 220, h_($org['penandatangan'])) .
      skl_tengah(455.9, 97.3, 220, h_($org['jabatan'])) .
      '</div>';
  }
  $out[] = '</body></html>';
  return implode("\n", $out);
}
