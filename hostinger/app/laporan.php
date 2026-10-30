<?php
/**
 * Laporan PDF (dompdf). Isi & format sama dengan versi Apps Script:
 * laporan harian (internal), laporan resmi format Artha Safety, dan laporan absensi per peserta.
 */

use Dompdf\Dompdf;
use Dompdf\Options;

const LAPORAN_KOLOM_ABSEN = 4;
const STATUS_TEKS = ['hadir' => 'Hadir', 'izin' => 'Izin', 'sakit' => 'Sakit', 'alpa' => 'Alpa', 'belum' => 'Belum absen', '' => '-'];
const BULAN_ID = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
const HARI_ID = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
const CENTANG = '<span style="font-family: DejaVu Sans">&#8730;</span>';

const LAPORAN_CSS = '
  @page { margin: 16mm 15mm; }
  body { font-family: Helvetica, Arial, sans-serif; font-size: 10pt; color: #1d2433; }
  h1 { font-size: 20pt; color: #1b1e4f; margin: 0 0 6px; }
  h2 { font-size: 14pt; color: #1b1e4f; border-bottom: 2px solid #22d89b; padding-bottom: 4px; margin: 18px 0 8px; }
  h3 { font-size: 11pt; color: #1b1e4f; margin: 14px 0 6px; }
  table { border-collapse: collapse; width: 100%; }
  table.data th { background: #e8edff; color: #1b1e4f; font-size: 9pt; padding: 5px; border: 1px solid #9fb0c8; text-align: left; }
  table.data td { border: 1px solid #c9d0db; padding: 4px 5px; font-size: 9pt; vertical-align: top; }
  table.info td { padding: 4px 6px; font-size: 11pt; vertical-align: top; }
  table.grid td { border: 1px solid #c9d0db; padding: 4px; text-align: center; vertical-align: top; font-size: 8pt; }
  .muted { color: #667085; } .small { font-size: 8pt; } .center { text-align: center; }
  .pb { page-break-before: always; }
  .hadir { color: #12805c; font-weight: bold; } .izin, .sakit { color: #1f5fbf; font-weight: bold; } .alpa { color: #b42318; font-weight: bold; }
';

function h_($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** 'yyyy-mm-dd' -> 'Selasa, 3 November 2026' */
function tgl_panjang(string $s): string {
  [$y, $m, $d] = array_map('intval', explode('-', $s));
  $w = (int)(new DateTime($s))->format('w');
  return HARI_ID[$w] . ', ' . $d . ' ' . BULAN_ID[$m - 1] . ' ' . $y;
}

/** 'yyyy-mm-dd' -> '24 April 2024' */
function tgl_id(string $s): string {
  [$y, $m, $d] = array_map('intval', explode('-', $s));
  return $d . ' ' . BULAN_ID[$m - 1] . ' ' . $y;
}

/** Rentang tanggal seperti di format: '08–10 November 2023' atau '22 Mei – 20 Juni 2024'. */
function rentang_tgl(string $a, string $b): string {
  [$y1, $m1, $d1] = array_map('intval', explode('-', $a));
  [$y2, $m2, $d2] = array_map('intval', explode('-', $b));
  if ($a === $b) return tgl_id($a);
  if ($y1 === $y2 && $m1 === $m2) return sprintf('%02d–%02d %s %d', $d1, $d2, BULAN_ID[$m1 - 1], $y1);
  if ($y1 === $y2) return "$d1 " . BULAN_ID[$m1 - 1] . " – $d2 " . BULAN_ID[$m2 - 1] . " $y1";
  return tgl_id($a) . ' – ' . tgl_id($b);
}

/** 20 -> 'dua puluh' */
function terbilang($n): string {
  $s = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
  $n = (int)floor((float)$n);
  if ($n < 12) return $n === 0 ? 'nol' : $s[$n];
  if ($n < 20) return terbilang($n - 10) . ' belas';
  if ($n < 100) return terbilang(intdiv($n, 10)) . ' puluh' . ($n % 10 ? ' ' . terbilang($n % 10) : '');
  if ($n < 200) return 'seratus' . ($n % 100 ? ' ' . terbilang($n % 100) : '');
  if ($n < 1000) return terbilang(intdiv($n, 100)) . ' ratus' . ($n % 100 ? ' ' . terbilang($n % 100) : '');
  if ($n < 2000) return 'seribu' . ($n % 1000 ? ' ' . terbilang($n % 1000) : '');
  return terbilang(intdiv($n, 1000)) . ' ribu' . ($n % 1000 ? ' ' . terbilang($n % 1000) : '');
}

function orang_($v): string {
  return $v === '' || $v === null ? '-' : $v . ' (' . terbilang($v) . ') orang';
}

function img_(string $src, string $attrs): string {
  return $src !== '' ? '<img src="' . $src . '" ' . $attrs . '>' : '<span class="muted small">(gambar tidak tersedia)</span>';
}

function aset_laporan(): array {
  static $a = null;
  if ($a === null) $a = json_decode((string)file_get_contents(__DIR__ . '/aset-laporan.json'), true);
  return $a;
}

// ---------------------------------------------------------------- Tanda tangan PIC

function ttdSaya(array $data, array $user): array {
  return ['ttd' => file_data_url($user['ttd_id'])];
}

function ttdSayaSimpan(array $data, array $user): bool {
  $rel = simpan_data_url('storage/tanda-tangan', (string)($data['ttd'] ?? ''), 'TTD_' . $user['kode_login'] . '.png');
  hapus_file($user['ttd_id']);
  update('pengguna', $user, ['ttd_id' => $rel]);
  log_($user, 'ttd_pic');
  return true;
}

// ---------------------------------------------------------------- Daftar & unduh laporan

function laporan_view(array $r): array {
  return ['id_laporan' => $r['id_laporan'], 'jenis' => $r['jenis'], 'id_hari' => $r['id_hari'], 'nama' => $r['nama_file'] ?: 'Laporan.pdf',
    'dibuat_oleh' => $r['dibuat_oleh'], 'dibuat_pada' => $r['dibuat_pada'], 'drive' => ''];
}

function laporanList(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  return array_map('laporan_view', rows('SELECT * FROM laporan WHERE id_batch = ? ORDER BY dibuat_pada DESC', [$b['id_batch']]));
}

function laporanUnduh(array $data, array $user): array {
  $r = row('SELECT * FROM laporan WHERE id_laporan = ?', [(string)($data['id_laporan'] ?? '')]);
  if (!$r) throw new LmsError('Laporan tidak ditemukan.');
  batch_for_pic($user, $r['id_batch']);
  $f = ROOT . '/' . $r['file_id'];
  if (!is_file($f)) throw new LmsError('File laporan tidak ditemukan di server.');
  return ['nama' => laporan_view($r)['nama'], 'data' => base64_encode((string)file_get_contents($f))];
}

function html_ke_pdf(string $html): string {
  require_once ROOT . '/vendor/autoload.php';
  @set_time_limit(600);
  @ini_set('memory_limit', '1024M');
  $opt = new Options();
  $opt->set('isRemoteEnabled', false);
  $opt->set('defaultFont', 'Helvetica');
  $opt->set('tempDir', STORAGE . '/tmp');
  $opt->set('fontCache', STORAGE . '/tmp');
  pastikan_folder(STORAGE . '/tmp');
  $pdf = new Dompdf($opt);
  $pdf->loadHtml($html, 'UTF-8');
  $pdf->setPaper('A4', 'portrait');
  $pdf->render();
  return (string)$pdf->output();
}

/**
 * Membuat laporan PDF, menyimpannya di server, dan mengembalikan isinya (base64) untuk diunduh.
 * jenis: 'resmi' (format Artha Safety lengkap) | 'absensi' (opsional dari/sampai no absen) | 'harian' (butuh id_hari).
 */
function laporanBuat(array $data, array $user): array {
  $batch = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $jenis = in_array($data['jenis'] ?? '', ['harian', 'absensi'], true) ? $data['jenis'] : 'resmi';
  $hariList = hari_batch($batch['id_batch']);
  if (!$hariList) throw new LmsError('Batch ini belum memiliki hari pelatihan.');
  if ($jenis === 'harian') {
    $hari = get_hari((string)($data['id_hari'] ?? ''));
    if ($hari['id_batch'] !== $batch['id_batch']) throw new LmsError('Hari tidak termasuk batch ini.');
    $hariList = [$hari];
    $nama = "Laporan Harian {$batch['kode_batch']} Hari-{$hari['hari_ke']} ({$hari['tanggal']}).pdf";
    $html = laporan_harian_html($batch, $hariList);
  } else {
    $dari = (int)($data['dari'] ?? 0);
    $sampai = (int)($data['sampai'] ?? 0);
    $rentang = $jenis === 'absensi' && ($dari || $sampai) ? ' (No ' . ($dari ?: 1) . '-' . ($sampai ?: 'akhir') . ')' : '';
    $nama = ($jenis === 'resmi' ? 'Laporan Resmi ' : 'Laporan Absensi ') . $batch['kode_batch'] . ' - ' . $batch['nama_pelatihan'] . $rentang . '.pdf';
    $html = laporan_resmi_html($batch, $hariList, $jenis, $dari, $sampai);
  }
  $nama = preg_replace('#[\\\\/:*?"<>|]#', '-', $nama);
  $pdf = html_ke_pdf($html);
  if (!empty(config()['uji'])) file_put_contents(STORAGE . '/laporan-terakhir.html', $html);
  $rel = simpan_bytes('storage/' . $batch['folder'] . '/laporan', $pdf, $nama);
  $rec = insert('laporan', ['id_laporan' => new_id('l'), 'id_batch' => $batch['id_batch'], 'jenis' => $jenis,
    'id_hari' => $jenis === 'harian' ? $hariList[0]['id_hari'] : '', 'file_id' => $rel, 'dibuat_oleh' => $user['nama'],
    'dibuat_pada' => now_()['stamp'], 'nama_file' => $nama]);
  log_($user, 'laporan_' . $jenis, $nama);
  return ['laporan' => laporan_view($rec), 'nama' => $nama, 'data' => base64_encode($pdf)];
}

// ================================================================ Laporan harian (internal)

function laporan_harian_html(array $batch, array $hariList): string {
  $now = now_();
  $peserta = peserta_batch($batch['id_batch'], false);
  $ids = pic_ids($batch);
  $pics = $ids ? rows('SELECT * FROM pengguna WHERE id_user IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) : [];
  $rekap = array_map(function ($h) use ($batch, $peserta, $now) {
    $per = array_map(fn($u) => array_map(function ($s) use ($h, $u, $batch, $now) {
      $rec = absen_of($h['id_hari'], $u['id_user'], $s);
      return ['rec' => $rec, 'status' => status_sesi($rec, state_sesi($batch, $h, $s, $now))];
    }, SESI), $peserta);
    $hitung = array_map(function ($i) use ($per) {
      $c = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'belum' => 0];
      foreach ($per as $p) if (isset($c[$p[$i]['status']])) $c[$p[$i]['status']]++;
      return $c;
    }, array_keys(SESI));
    return ['hari' => $h, 'per' => $per, 'hitung' => $hitung];
  }, $hariList);
  $out = ['<html><head><meta charset="utf-8"><style>' . LAPORAN_CSS . '</style></head><body>'];
  $out[] = sampul_harian($batch, $hariList, $pics, count($peserta));
  $out[] = ringkasan_harian($rekap, count($peserta));
  foreach ($rekap as $r) $out[] = bagian_hari($batch, $r, $peserta);
  $out[] = pengesahan_harian($batch, $pics, $now);
  $out[] = '</body></html>';
  return implode("\n", $out);
}

function sampul_harian(array $batch, array $hariList, array $pics, int $jumlah): string {
  $lokasi = [];
  foreach ($hariList as $h) { $n = lokasi_hari($batch, $h)['nama']; if (!in_array($n, $lokasi, true)) $lokasi[] = $n; }
  $periode = count($hariList) === 1 ? tgl_panjang($hariList[0]['tanggal'])
    : tgl_panjang($hariList[0]['tanggal']) . ' s.d. ' . tgl_panjang(end($hariList)['tanggal']);
  return '<img src="' . trim((string)file_get_contents(__DIR__ . '/aset-logo.txt')) . '" width="300">' .
    '<div class="muted" style="margin-top:4px">Learning Management System</div><br><br><br><br>' .
    '<h1>LAPORAN HARIAN KEGIATAN PELATIHAN</h1><div style="font-size:16pt;margin-bottom:24px">' . h_($batch['nama_pelatihan']) . '</div>' .
    '<table class="info"><tr><td style="width:150px">Batch</td><td>: ' . h_($batch['kode_batch']) . '</td></tr>' .
    '<tr><td>Hari ke</td><td>: ' . h_($hariList[0]['hari_ke']) . '</td></tr>' .
    '<tr><td>Tanggal</td><td>: ' . h_($periode) . '</td></tr><tr><td>Lokasi</td><td>: ' . h_(implode('; ', $lokasi)) . '</td></tr>' .
    '<tr><td>Jumlah peserta</td><td>: ' . $jumlah . ' orang</td></tr>' .
    '<tr><td>PIC</td><td>: ' . h_(implode(', ', array_column($pics, 'nama')) ?: '-') . '</td></tr></table>';
}

function ringkasan_harian(array $rekap, int $total): string {
  $rows = '';
  foreach ($rekap as $r) foreach (SESI as $i => $s) {
    $c = $r['hitung'][$i];
    $belumDibuka = !array_filter($r['per'], fn($p) => $p[$i]['status'] !== '');
    $pct = $total && !$belumDibuka ? round($c['hadir'] / $total * 100) . '%' : '-';
    $rows .= '<tr><td>' . ($i === 0 ? 'Hari ke-' . h_($r['hari']['hari_ke']) . '<br><span class="small muted">' . h_(tgl_panjang($r['hari']['tanggal'])) . '</span>' : '') .
      "</td><td>Sesi $s</td><td class=\"center\">{$c['hadir']}</td><td class=\"center\">{$c['izin']}</td><td class=\"center\">{$c['sakit']}</td>" .
      "<td class=\"center\">{$c['alpa']}</td><td class=\"center\">" . ($c['belum'] ?: '-') . "</td><td class=\"center\"><b>$pct</b></td></tr>";
  }
  return '<div class="pb"></div><h2>Ringkasan Kehadiran</h2><p>Jumlah peserta: <b>' . $total . '</b> orang. Persentase dihitung dari jumlah peserta yang hadir dibanding seluruh peserta.</p>' .
    '<table class="data"><tr><th>Hari</th><th>Sesi</th><th>Hadir</th><th>Izin</th><th>Sakit</th><th>Alpa</th><th>Belum</th><th>% Hadir</th></tr>' . $rows . '</table>';
}

function grid_table(array $cells, int $cols): string {
  $rows = '';
  for ($i = 0; $i < count($cells); $i += $cols) {
    $row = array_slice($cells, $i, $cols);
    while (count($row) < $cols) $row[] = '<td style="border:0"></td>';
    $rows .= '<tr>' . implode('', $row) . '</tr>';
  }
  return '<table class="grid">' . $rows . '</table>';
}

function bagian_hari(array $batch, array $r, array $peserta): string {
  $h = $r['hari'];
  $out = ['<div class="pb"></div><h2>Hari ke-' . h_($h['hari_ke']) . ' — ' . h_(tgl_panjang($h['tanggal'])) . '</h2>',
    '<p class="muted">Lokasi: ' . h_(lokasi_hari($batch, $h)['nama']) . '</p>'];
  $rd = rundown_hari($h['id_hari']);
  $out[] = '<h3>A. Rundown Kegiatan</h3>';
  $out[] = $rd ? '<table class="data"><tr><th style="width:85px">Waktu</th><th>Kegiatan</th><th>Pemateri</th><th>Keterangan</th></tr>' .
    implode('', array_map(fn($x) => '<tr><td>' . h_($x['jam_mulai']) . '–' . h_($x['jam_selesai']) . '</td><td>' . h_($x['kegiatan']) . '</td><td>' .
      h_($x['pemateri']) . '</td><td>' . h_($x['keterangan']) . '</td></tr>', $rd)) . '</table>' : '<p class="muted">Rundown belum diisi.</p>';
  $out[] = '<h3>B. Daftar Hadir</h3>';
  $head = implode('', array_map(fn($s) => '<th>Sesi ' . $s . ' (' . h_(jam_sesi($batch, $s)['mulai']) . '–' . h_(jam_sesi($batch, $s)['selesai']) . ')</th>', SESI));
  $body = '';
  foreach ($peserta as $i => $u) {
    $body .= '<tr><td class="center">' . h_($u['no_absen']) . '</td><td>' . h_($u['nama']) . '</td><td>' . h_($u['instansi']) . '</td>';
    foreach ($r['per'][$i] as $x) {
      $body .= '<td><span class="' . $x['status'] . '">' . h_(STATUS_TEKS[$x['status']] ?? $x['status']) . '</span>' .
        ($x['rec'] && $x['rec']['waktu_server'] !== '' && $x['status'] === 'hadir' ? ' ' . h_(substr($x['rec']['waktu_server'], 11, 5)) : '') .
        ($x['rec'] && $x['rec']['keterangan'] !== '' ? '<br><span class="small muted">' . h_($x['rec']['keterangan']) . '</span>' : '') . '</td>';
    }
    $body .= '</tr>';
  }
  $out[] = '<table class="data"><tr><th style="width:30px">No</th><th>Nama</th><th>Instansi</th>' . $head . '</tr>' . $body . '</table>';
  foreach (SESI as $i => $s) {
    $hadir = [];
    foreach ($peserta as $j => $u) {
      $x = $r['per'][$j][$i];
      if ($x['status'] === 'hadir' && $x['rec'] && $x['rec']['foto_id'] !== '') $hadir[] = ['u' => $u, 'rec' => $x['rec']];
    }
    $judul = "<h3>C.$s Lampiran Absensi Sesi $s — Hari ke-" . h_($h['hari_ke']) . '</h3>';
    if (!$hadir) { $out[] = $judul . '<p class="muted">Tidak ada peserta yang absen hadir pada sesi ini.</p>'; continue; }
    $out[] = '<div class="pb"></div>' . $judul;
    $cells = array_map(fn($x) => '<td style="width:' . intdiv(100, LAPORAN_KOLOM_ABSEN) . '%">' . img_(file_data_url($x['rec']['foto_id']), 'width="150"') .
      '<br><b>' . h_($x['u']['nama']) . '</b><br>' . h_($x['u']['kode_login']) . ' · ' . h_(substr($x['rec']['waktu_server'], 11, 8)) . ' WIB' .
      '<br><span class="muted">± ' . h_($x['rec']['jarak_meter']) . ' m dari lokasi</span><br>' . img_(file_data_url($x['rec']['ttd_id']), 'height="45"') . '</td>', $hadir);
    $out[] = grid_table($cells, LAPORAN_KOLOM_ABSEN);
  }
  $dok = array_values(array_filter(dok_hari($h['id_hari']), fn($d) => $d['masuk_laporan'] === 'ya'));
  $out[] = '<div class="pb"></div><h3>D. Dokumentasi Kegiatan</h3>';
  if ($dok) {
    $cells = array_map(function ($d) use ($rd) {
      $keg = null;
      foreach ($rd as $x) if ($d['id_rundown'] !== '' && $x['id_rundown'] === $d['id_rundown']) $keg = $x;
      return '<td style="width:50%">' . img_(file_data_url($d['file_kecil_id'] ?: $d['file_id']), 'width="320"') .
        ($keg ? '<br><b>' . h_($keg['jam_mulai']) . ' · ' . h_($keg['kegiatan']) . '</b>' : '') . ($d['keterangan'] !== '' ? '<br>' . h_($d['keterangan']) : '') . '</td>';
    }, $dok);
    $out[] = grid_table($cells, 2);
  } else {
    $out[] = '<p class="muted">Tidak ada dokumentasi.</p>';
  }
  $materi = materi_hari($h['id_hari']);
  $out[] = '<h3>E. Materi</h3>';
  $out[] = $materi ? '<table class="data"><tr><th style="width:30px">No</th><th>Judul</th><th>Kategori</th><th>Jenis</th></tr>' .
    implode('', array_map(fn($m, $i) => '<tr><td class="center">' . ($i + 1) . '</td><td>' . h_($m['judul']) . '</td><td>' .
      ($m['kategori'] === 'latihan' ? 'Lembar latihan' : 'Materi') . '</td><td>' . h_(strtoupper($m['tipe_file'])) . '</td></tr>', $materi, array_keys($materi))) . '</table>'
    : '<p class="muted">Tidak ada materi.</p>';
  return implode("\n", $out);
}

function pengesahan_harian(array $batch, array $pics, array $now): string {
  $cols = $pics ?: [['nama' => '', 'ttd_id' => '']];
  $n = count($cols);
  $tds = '';
  foreach ($cols as $i => $p) {
    $tds .= '<td class="center" style="width:' . intdiv(100, $n) . '%;vertical-align:top">PIC ' . ($n > 1 ? $i + 1 : '') . '<br><br>' .
      ($p['ttd_id'] !== '' ? img_(file_data_url($p['ttd_id']), 'height="70"') : '<br><br><br><br>') .
      '<br><b><u>' . h_($p['nama'] ?: '(..............................)') . '</u></b></td>';
  }
  return '<div class="pb"></div><h2>Pengesahan</h2><p>Laporan ini disusun otomatis oleh LMS Artha Safety Indonesia berdasarkan data absensi, rundown, materi, dan dokumentasi kegiatan ' .
    h_($batch['nama_pelatihan']) . ' batch ' . h_($batch['kode_batch']) . '.</p><p>Dibuat pada: ' . h_(tgl_panjang($now['date'])) . ', pukul ' . h_($now['time']) .
    ' WIB</p><br><table><tr>' . $tds . '</tr></table>';
}

// ================================================================ Laporan resmi (format Artha Safety)

/** Data perusahaan untuk kop, cover, dan halaman penyelenggaraan (tabel pengaturan, kunci 'org'). */
const ORG_DEFAULT = [
  'nama' => 'PT Artha Safety Indonesia',
  'nomor_skp' => 'No. 5/000666/AS.01.02.4103.6.15/V/2026',
  'masa_skp' => '20 Mei 2028',
  'alamat' => 'Ruko De Mercy Square No R8. Jl. Kapten Haryadi Komp. Merapi Regency, Sardonoharjo, Ngaglik, Sleman Yogyakarta',
  'telepon' => '0812-6363-6376',
  'telepon_kantor' => '(0274) 5063025',
  'website' => 'www.arthasafetyindonesia.co.id',
  'email' => 'info@arthasafetyindonesia.co.id',
  'penandatangan' => 'Saut Reynald Ricardo Simbolon',
  'ttd_id' => '',
];

/** Data laporan per batch, disimpan sebagai JSON di kolom batch.info_laporan. */
const INFO_DEFAULT = [
  'nomor_permohonan' => '', 'judul_cover' => 'AHLI K3 UMUM', 'sub_cover' => 'Sertifikasi Kementrian Ketenagakerjaan Republik Indonesia',
  'bidang_pembinaan' => 'Calon Ahli K3 Umum', 'bidang_jasa' => 'Keahlian K3 Umum', 'jenis_personil' => 'Ahli K3', 'klasifikasi' => 'Ahli K3 Umum',
  'kelas' => 'Non Kelas', 'tempat_kelas' => 'PT Artha Safety Indonesia', 'tempat_observasi' => '', 'metode' => 'Offline Training',
  'jumlah_pendaftar' => '', 'jumlah_lulus' => '', 'surat_permohonan' => true, 'bap' => true, 'daftar_hadir_pemateri' => true,
];

function org_(): array {
  $o = json_decode(pengaturan('org') ?: '{}', true);
  return array_merge(ORG_DEFAULT, is_array($o) ? $o : []);
}

function info_batch(array $batch): array {
  $o = json_decode($batch['info_laporan'] ?: '{}', true);
  return array_merge(INFO_DEFAULT, is_array($o) ? $o : []);
}

function infoLaporanGet(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $org = org_();
  return ['info' => info_batch($b), 'org' => $org, 'org_ttd' => file_data_url($org['ttd_id']),
    'jumlah_peserta' => count(peserta_batch($b['id_batch'], false)), 'bisa_ubah_org' => $user['role'] === 'admin'];
}

function infoLaporanSimpan(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $masuk = $data['info'] ?? [];
  $info = [];
  foreach (INFO_DEFAULT as $k => $def) {
    if (!array_key_exists($k, $masuk)) continue;
    $info[$k] = is_bool($def) ? (bool)$masuk[$k] : mb_substr(trim((string)$masuk[$k]), 0, 300);
  }
  foreach (['jumlah_pendaftar', 'jumlah_lulus'] as $k) {
    if (($info[$k] ?? '') !== '' && !preg_match('/^\d{1,4}$/', $info[$k])) throw new LmsError('Jumlah pendaftar/lulus harus berupa angka.');
  }
  $b = update('batch', $b, ['info_laporan' => json_encode(array_merge(info_batch($b), $info), JSON_UNESCAPED_UNICODE)]);
  log_($user, 'info_laporan', $b['kode_batch']);
  return info_batch($b);
}

function orgSimpan(array $data, array $user): array {
  $masuk = $data['org'] ?? [];
  $o = org_();
  foreach (ORG_DEFAULT as $k => $v) if ($k !== 'ttd_id' && array_key_exists($k, $masuk)) $o[$k] = mb_substr(trim((string)$masuk[$k]), 0, 300);
  pengaturan_simpan('org', json_encode($o, JSON_UNESCAPED_UNICODE));
  log_($user, 'org_ubah');
  return $o;
}

/** Gambar tanda tangan + cap penandatangan (PNG/JPG) untuk halaman penyelenggaraan. */
function orgTtdSimpan(array $data, array $user): bool {
  $o = org_();
  $rel = simpan_data_url('storage/tanda-tangan', (string)($data['gambar'] ?? ''), 'TTD_CAP_PENYELENGGARA.png');
  hapus_file($o['ttd_id']);
  $o['ttd_id'] = $rel;
  pengaturan_simpan('org', json_encode($o, JSON_UNESCAPED_UNICODE));
  log_($user, 'org_ttd');
  return true;
}

const RESMI_CSS = '
  @page { margin: 15mm 18mm; }
  body { font-family: "Times New Roman", Times, serif; font-size: 11pt; color: #000; }
  table { border-collapse: collapse; }
  .pb { page-break-before: always; }
  .judul { text-align: center; font-weight: bold; font-size: 12pt; margin: 16px 0 18px; }
  .bag { font-weight: bold; margin: 14px 0 6px; }
  table.isian td { padding: 3px 6px; vertical-align: top; font-size: 11pt; }
  table.garis { width: 100%; }
  table.garis th { border: 1px solid #000; padding: 4px; font-size: 10.5pt; background: #c6d4ef; }
  table.garis td { border: 1px solid #000; padding: 4px 5px; font-size: 10.5pt; vertical-align: top; }
  table.abs { width: 100%; page-break-inside: auto; }
  table.abs tr { page-break-inside: avoid; }
  table.abs td { border: 1px solid #555; padding: 4px 5px; font-size: 9.5pt; vertical-align: middle; }
  table.abs td.foto { text-align: center; width: 150px; padding: 2px; }
  table.dok td { border: 1px solid #999; padding: 4px; text-align: center; vertical-align: top; font-size: 9pt; width: 50%; }
  .kop-web { font-family: Helvetica, Arial, sans-serif; font-size: 8pt; color: #333; text-align: right; vertical-align: middle; }
  .cover { font-family: Helvetica, Arial, sans-serif; text-align: center; color: #000; }
';

function kop(array $org): string {
  return '<table style="width:100%"><tr><td><img src="' . aset_laporan()['kop'] . '" width="230"></td>' .
    '<td class="kop-web">' . h_($org['website']) . '</td></tr></table><div style="border-bottom:1px solid #1b1e4f;margin:4px 0 8px"></div>';
}

/** Halaman resmi: kop + isi, dimulai di halaman baru. */
function halaman(array $org, string $isi): string {
  return '<div class="pb"></div>' . kop($org) . $isi;
}

function laporan_resmi_html(array $batch, array $hariList, string $jenis, int $dari, int $sampai): string {
  $org = org_();
  $info = info_batch($batch);
  $now = now_();
  $peserta = peserta_batch($batch['id_batch'], false);
  if ($dari || $sampai) $peserta = array_values(array_filter($peserta, fn($u) => (int)$u['no_absen'] >= ($dari ?: 1) && (int)$u['no_absen'] <= ($sampai ?: 99999)));
  $out = ['<html><head><meta charset="utf-8"><style>' . RESMI_CSS . '</style></head><body>'];
  if ($jenis === 'resmi') {
    $out[] = cover_resmi($batch, $info, $org);
    $out[] = halaman($org, penyelenggaraan($batch, $hariList, $info, $org));
    $out[] = halaman($org, jadwal_kegiatan($hariList));
    $out[] = halaman($org, daftar_materi($hariList));
  }
  $out[] = absensi_peserta($batch, $hariList, $info, $peserta, $now, $jenis !== 'resmi');
  if ($jenis === 'resmi') $out[] = halaman($org, dokumentasi_resmi($hariList));
  $out[] = '</body></html>';
  return implode("\n", $out);
}

function cover_resmi(array $batch, array $info, array $org): string {
  return '<div class="cover"><br><br><div style="font-size:18pt;font-weight:bold">PEMBINAAN</div><br>' .
    '<div style="font-size:30pt;font-weight:bold">' . h_($info['judul_cover']) . '</div><br>' .
    '<div style="font-size:11pt;font-weight:bold">' . h_($info['sub_cover']) . '</div><br>' .
    '<div style="font-size:11pt;font-weight:bold">Tanggal ' . h_(rentang_tgl($batch['tgl_mulai'], $batch['tgl_selesai'])) . '</div>' .
    '<br><br><br><img src="' . aset_laporan()['cover'] . '" width="300"><br><br><br><br>' .
    '<div style="font-size:10pt;font-weight:bold">Diselenggarakan oleh:</div><br>' .
    '<div style="font-size:16pt;font-weight:bold">' . h_(mb_strtoupper($org['nama'])) . '</div><br><br>' .
    '<div style="font-size:11pt;font-weight:bold">Alamat Kantor :</div><br>' .
    '<div style="font-size:9.5pt;font-weight:bold">' . h_($org['alamat']) . ($org['telepon_kantor'] !== '' ? ' ' . h_($org['telepon_kantor']) : '') . '</div></div>';
}

function isian(array $rows): string {
  return '<table class="isian" style="margin-left:28px">' . implode('', array_map(fn($r) =>
    '<tr><td style="width:230px">' . $r[0] . '</td><td>' . ($r[1] === null ? '' : ': ' . h_($r[1])) . '</td></tr>', $rows)) . '</table>';
}

function penyelenggaraan(array $batch, array $hariList, array $info, array $org): string {
  $adaRundown = (bool)row('SELECT id_rundown FROM rundown WHERE id_batch = ? LIMIT 1', [$batch['id_batch']]);
  $adaAbsen = (bool)row("SELECT id_absen FROM absensi WHERE id_batch = ? AND status = 'hadir' LIMIT 1", [$batch['id_batch']]);
  $adaDok = (bool)row('SELECT id_dok FROM dokumentasi WHERE id_batch = ? LIMIT 1', [$batch['id_batch']]);
  $syarat = [['Surat Permohonan', $info['surat_permohonan']], ['BAP', $info['bap']], ['Jadwal Kegiatan', $adaRundown],
    ['Daftar Hadir Pemateri', $info['daftar_hadir_pemateri']], ['Daftar Hadir Peserta', $adaAbsen], ['Foto Kegiatan', $adaDok]];
  $jumlah = count(peserta_batch($batch['id_batch'], false));
  $pendaftar = $info['jumlah_pendaftar'] === '' ? $jumlah : $info['jumlah_pendaftar'];
  $sp = '&nbsp;&nbsp;';
  $ind = '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;';
  $tabelSyarat = '';
  foreach ($syarat as $i => $x) {
    $tabelSyarat .= '<tr><td style="text-align:center">' . ($i + 1) . '</td><td>' . $x[0] . '</td><td style="text-align:center">' .
      ($x[1] ? CENTANG : '') . '</td><td style="text-align:center">' . ($x[1] ? '' : CENTANG) . '</td></tr>';
  }
  return implode("\n", [
    '<div class="judul">LAPORAN PENYELENGGARAAN KEGIATAN</div>',
    "<div class=\"bag\">A.{$sp}PENYELENGGARA</div>",
    isian([["a.{$sp}Nama Penyelenggara", $org['nama']], ["b.{$sp}Nomor SKP", $org['nomor_skp']], ["c.{$sp}Masa Berlaku SKP", $org['masa_skp']]]),
    "<div class=\"bag\">B.{$sp}PELAKSANAAN KEGIATAN</div>",
    isian([["a.{$sp}Nomor Permohonan Temank3", $info['nomor_permohonan'] ?: '-'], ["b.{$sp}Tempat Pelaksanaan", null],
      ["{$ind}Kelas", $info['tempat_kelas'] ?: '-'], ["{$ind}Observasi/PKL", $info['tempat_observasi'] ?: '-'],
      ["c.{$sp}Metode", $info['metode'] ?: '-'], ["d.{$sp}Waktu Pelaksanaan", rentang_tgl($batch['tgl_mulai'], $batch['tgl_selesai'])],
      ["e.{$sp}Bidang Pembinaan", $info['bidang_pembinaan'] ?: '-']]),
    "<div class=\"bag\">C.{$sp}PERSYARATAN ADMINISTRASI</div>",
    '<table class="garis" style="margin-left:28px;width:90%"><tr><th style="width:35px">No</th><th>Persyaratan Administrasi</th><th style="width:50px">Ada</th><th style="width:50px">Tidak</th></tr>' . $tabelSyarat . '</table>',
    "<div class=\"bag\">D.{$sp}KEPESERTAAN</div>",
    isian([["a.{$sp}Jumlah Pendaftar", orang_($pendaftar)], ["b.{$sp}Jumlah Peserta", orang_($jumlah)], ["c.{$sp}Jumlah Peserta Lulus", orang_($info['jumlah_lulus'])]]),
    "<div class=\"bag\">E.{$sp}PENUTUP</div>",
    '<div style="margin-left:28px">Demikian laporan ini kami sampaikan dengan data yang sebenar-benarnya.</div><br>',
    '<table style="width:100%"><tr><td style="width:55%"></td><td style="text-align:center">Hormat kami,<br>' . h_($org['nama']) . '<br>' .
      ($org['ttd_id'] !== '' ? img_(file_data_url($org['ttd_id']), 'height="95"') : '<br><br><br><br><br>') .
      '<br><b>' . h_($org['penandatangan']) . '</b></td></tr></table>',
  ]);
}

function jadwal_kegiatan(array $hariList): string {
  $out = ['<div class="judul">JADWAL KEGIATAN</div>'];
  foreach ($hariList as $h) {
    $rd = rundown_hari($h['id_hari']);
    if (!$rd) continue;
    $out[] = '<div class="bag">Hari ke-' . h_($h['hari_ke']) . ' — ' . h_(tgl_panjang($h['tanggal'])) . '</div>';
    $out[] = '<table class="garis"><tr><th style="width:95px">Waktu</th><th>Kegiatan</th><th>Pemateri</th><th>Keterangan</th></tr>' .
      implode('', array_map(fn($x) => '<tr><td>' . h_($x['jam_mulai']) . '–' . h_($x['jam_selesai']) . '</td><td>' . h_($x['kegiatan']) . '</td><td>' .
        h_($x['pemateri']) . '</td><td>' . h_($x['keterangan']) . '</td></tr>', $rd)) . '</table>';
  }
  if (count($out) === 1) $out[] = '<p>Rundown belum diisi.</p>';
  return implode("\n", $out);
}

/** Materi dan pemateri unik dari rundown semua hari batch (kegiatan yang punya nama pemateri). */
function daftar_materi(array $hariList): string {
  $list = [];
  foreach ($hariList as $h) foreach (rundown_hari($h['id_hari']) as $x) {
    if ($x['pemateri'] === '') continue;
    $ada = false;
    foreach ($list as $m) if ($m['kegiatan'] === $x['kegiatan'] && $m['pemateri'] === $x['pemateri']) $ada = true;
    if (!$ada) $list[] = $x;
  }
  return '<div class="judul">DAFTAR MATERI DAN PEMATERI</div>' . ($list
    ? '<table class="garis"><tr><th style="width:35px">No</th><th>Materi</th><th>Nama Trainer</th><th>Instansi</th></tr>' .
      implode('', array_map(fn($x, $i) => '<tr><td style="text-align:center">' . ($i + 1) . '</td><td>' . h_($x['kegiatan']) . '</td><td>' . h_($x['pemateri']) .
        '</td><td>' . h_($x['instansi']) . '</td></tr>', $list, array_keys($list))) . '</table>'
    : '<p>Belum ada kegiatan rundown yang mencantumkan nama pemateri.</p>');
}

/** Laporan absensi per peserta: identitas di atas, lalu setiap sesi dengan foto & tanda tangan. */
function absensi_peserta(array $batch, array $hariList, array $info, array $peserta, array $now, bool $halamanPertama): string {
  $head = '<div style="font-size:13pt;line-height:1.35;margin:0 0 14px 20px">LAPORAN ABSENSI<br>NO PERMOHONAN : ' . h_($info['nomor_permohonan'] ?: '-') .
    '<br>BIDANG JASA : ' . h_($info['bidang_jasa']) . '<br>JENIS PERSONIL : ' . h_($info['jenis_personil']) . '<br>KLASIFIKASI : ' . h_($info['klasifikasi']) .
    '<br>KELAS : ' . h_($info['kelas']) . '</div>';
  if (!$peserta) return ($halamanPertama ? '' : '<div class="pb"></div>') . $head . '<p>Tidak ada peserta.</p>';
  $out = [];
  foreach ($peserta as $i => $u) {
    $out[] = ($i === 0 && $halamanPertama ? '' : '<div class="pb"></div>') . $head;
    $rows = '<tr><td style="width:32%"><b>Nama</b></td><td><b>' . h_($u['nama']) . '</b></td><td class="foto"></td></tr>';
    foreach ($hariList as $h) foreach (SESI as $s) {
      $state = state_sesi($batch, $h, $s, $now);
      if ($state === 'belum_dibuka') continue;
      $rec = absen_of($h['id_hari'], $u['id_user'], $s);
      $status = status_sesi($rec, $state);
      $lokasi = '-';
      $foto = '';
      if ($status === 'hadir' && $rec && $rec['foto_id'] !== '') {
        $jam = tgl_id(substr($rec['waktu_server'], 0, 10)) . ', Jam : ' . substr($rec['waktu_server'], 11, 8);
        $lokasi = alamat_absen($rec);
        $foto = img_(file_data_url($rec['foto_id']), 'width="140"') . '<br>' . img_(file_data_url($rec['ttd_id']), 'height="42"');
      } else {
        $jam = 'Tidak hadir (' . (STATUS_TEKS[$status] ?? $status) . ($rec && $rec['keterangan'] !== '' ? ' – ' . $rec['keterangan'] : '') . ')';
      }
      $rows .= '<tr><td><b>Sesi Absen</b></td><td><b>' . ($s === 1 ? 'Pagi' : 'Siang') . '</b></td><td class="foto" rowspan="4">' . $foto . '</td></tr>' .
        '<tr><td><b>Tanggal Pelatihan</b></td><td><b>' . h_(tgl_id($h['tanggal'])) . '</b></td></tr>' .
        '<tr><td>Tanggal &amp; Jam Absensi</td><td>' . h_($jam) . '</td></tr>' .
        '<tr><td>Lokasi Absen</td><td>' . h_($lokasi) . '</td></tr>';
    }
    $out[] = '<table class="abs">' . $rows . '</table>';
  }
  return implode("\n", $out);
}

/** Alamat lokasi absen; untuk data tanpa alamat, dicari dari koordinat lalu disimpan. */
function alamat_absen(array $rec): string {
  if ($rec['alamat'] !== '') return $rec['alamat'];
  $a = alamat_($rec['lat'], $rec['lng']);
  if ($a !== '') {
    update('absensi', $rec, ['alamat' => $a]);
    return $a;
  }
  return $rec['lat'] !== '' ? $rec['lat'] . ', ' . $rec['lng'] : '-';
}

function dokumentasi_resmi(array $hariList): string {
  $out = ['<div class="judul">DOKUMENTASI KEGIATAN</div>'];
  foreach ($hariList as $h) {
    $dok = array_values(array_filter(dok_hari($h['id_hari']), fn($d) => $d['masuk_laporan'] === 'ya'));
    if (!$dok) continue;
    $rd = rundown_hari($h['id_hari']);
    $out[] = '<div class="bag">Hari ke-' . h_($h['hari_ke']) . ' — ' . h_(tgl_panjang($h['tanggal'])) . '</div>';
    $cells = array_map(function ($d) use ($rd) {
      $keg = null;
      foreach ($rd as $x) if ($d['id_rundown'] !== '' && $x['id_rundown'] === $d['id_rundown']) $keg = $x;
      return '<td>' . img_(file_data_url($d['file_kecil_id'] ?: $d['file_id']), 'width="300"') . ($keg ? '<br><b>' . h_($keg['kegiatan']) . '</b>' : '') .
        ($d['keterangan'] !== '' ? '<br>' . h_($d['keterangan']) : '') . '</td>';
    }, $dok);
    $rows = '';
    for ($i = 0; $i < count($cells); $i += 2) $rows .= '<tr>' . $cells[$i] . ($cells[$i + 1] ?? '<td style="border:0"></td>') . '</tr>';
    $out[] = '<table class="dok" style="width:100%">' . $rows . '</table>';
  }
  if (count($out) === 1) $out[] = '<p>Belum ada foto kegiatan yang ditandai masuk laporan.</p>';
  return implode("\n", $out);
}
