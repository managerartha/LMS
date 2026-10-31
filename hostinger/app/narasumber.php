<?php
/**
 * Absensi narasumber/pemateri: cukup tanda tangan digital (tanpa foto), diisi di perangkat PIC/Admin.
 * Daftar narasumber per hari diambil dari rundown (kegiatan yang mencantumkan pemateri), ditambah
 * narasumber lain yang dicatat manual. Rekap masuk otomatis ke laporan resmi dan laporan harian.
 */

function absen_narasumber_hari(string $idHari): array {
  return rows('SELECT * FROM absen_narasumber WHERE id_hari = ? ORDER BY jam_mulai, waktu_server', [$idHari]);
}

/**
 * Baris daftar hadir narasumber satu hari: setiap kegiatan rundown berpemateri (sudah/belum tanda tangan),
 * lalu tanda tangan yang tidak terkait kegiatan rundown (dicatat manual, atau kegiatannya sudah dihapus).
 */
function daftar_narasumber(array $hari): array {
  $absen = absen_narasumber_hari($hari['id_hari']);
  $perRundown = [];
  foreach ($absen as $a) if ($a['id_rundown'] !== '') $perRundown[$a['id_rundown']] = $a;
  $out = [];
  foreach (rundown_hari($hari['id_hari']) as $r) {
    if ($r['pemateri'] === '') continue;
    $a = $perRundown[$r['id_rundown']] ?? null;
    unset($perRundown[$r['id_rundown']]);
    $out[] = ['id_rundown' => $r['id_rundown'], 'jam_mulai' => $r['jam_mulai'], 'jam_selesai' => $r['jam_selesai'],
      'materi' => $r['kegiatan'], 'nama' => $a ? $a['nama'] : $r['pemateri'], 'instansi' => $a ? $a['instansi'] : $r['instansi'], 'absen' => $a];
  }
  foreach ($absen as $a) {
    if ($a['id_rundown'] !== '' && !isset($perRundown[$a['id_rundown']])) continue; // sudah tampil di baris rundownnya
    $out[] = ['id_rundown' => $a['id_rundown'], 'jam_mulai' => $a['jam_mulai'], 'jam_selesai' => $a['jam_selesai'],
      'materi' => $a['materi'], 'nama' => $a['nama'], 'instansi' => $a['instansi'], 'absen' => $a];
  }
  usort($out, fn($x, $y) => strcmp($x['jam_mulai'], $y['jam_mulai']));
  return $out;
}

function narasumberList(array $data, array $user): array {
  $hari = hari_for_user($user, (string)($data['id_hari'] ?? ''));
  return ['bisa_isi' => $hari['tanggal'] <= now_()['date'], // jam server, bukan jam HP
    'items' => array_map(fn($x) => array_merge($x, ['absen' => $x['absen'] ? ['id_an' => $x['absen']['id_an'],
      'waktu' => $x['absen']['waktu_server'], 'oleh' => $x['absen']['dibuat_oleh'], 'ttd' => file_data_url($x['absen']['ttd_id'])] : null]),
      daftar_narasumber($hari))];
}

/** Menyimpan tanda tangan narasumber untuk satu kegiatan rundown (id_rundown) atau narasumber lain (manual). */
function narasumberAbsen(array $data, array $user): array {
  $hari = hari_for_user($user, (string)($data['id_hari'] ?? ''));
  $batch = get_batch($hari['id_batch']);
  $now = now_();
  if ($hari['tanggal'] > $now['date']) throw new LmsError('Absensi narasumber hanya bisa diisi pada hari pelatihan tersebut atau sesudahnya.');
  $nama = preg_replace('/\s+/', ' ', trim((string)($data['nama'] ?? '')));
  $instansi = mb_substr(trim((string)($data['instansi'] ?? '')), 0, 150);
  if ($nama === '') throw new LmsError('Nama narasumber wajib diisi.');
  $idRundown = (string)($data['id_rundown'] ?? '');
  if ($idRundown !== '') {
    $r = row('SELECT * FROM rundown WHERE id_rundown = ? AND id_hari = ?', [$idRundown, $hari['id_hari']]);
    if (!$r) throw new LmsError('Kegiatan rundown tidak ditemukan. Muat ulang halaman.');
    if (row('SELECT id_an FROM absen_narasumber WHERE id_rundown = ?', [$idRundown])) throw new LmsError('Narasumber kegiatan ini sudah tanda tangan.');
    [$materi, $mulai, $selesai] = [$r['kegiatan'], $r['jam_mulai'], $r['jam_selesai']];
  } else {
    $materi = trim((string)($data['materi'] ?? ''));
    [$mulai, $selesai] = [(string)($data['jam_mulai'] ?? ''), (string)($data['jam_selesai'] ?? '')];
    if ($materi === '') throw new LmsError('Materi / kegiatan wajib diisi.');
    if (!is_time($mulai) || !is_time($selesai) || $selesai <= $mulai) throw new LmsError('Jam mulai/selesai tidak valid.');
  }
  $rel = simpan_data_url('storage/' . $batch['folder'] . '/narasumber/hari-' . $hari['hari_ke'], (string)($data['ttd'] ?? ''),
    'TTD_' . folder_aman($nama) . '_' . str_replace(':', '', $mulai) . '.png', 1024 * 1024);
  $a = insert('absen_narasumber', ['id_an' => new_id('n'), 'id_batch' => $batch['id_batch'], 'id_hari' => $hari['id_hari'], 'id_rundown' => $idRundown,
    'nama' => $nama, 'instansi' => $instansi, 'materi' => $materi, 'jam_mulai' => $mulai, 'jam_selesai' => $selesai,
    'ttd_id' => $rel, 'waktu_server' => $now['stamp'], 'dibuat_oleh' => $user['nama']]);
  log_($user, 'absen_narasumber', $batch['kode_batch'] . ' hari ' . $hari['hari_ke'] . ': ' . $nama);
  return ['id_an' => $a['id_an'], 'waktu' => $a['waktu_server']];
}

/** Menghapus tanda tangan narasumber (mis. salah orang) agar bisa diulang. */
function narasumberHapus(array $data, array $user): bool {
  $a = row('SELECT * FROM absen_narasumber WHERE id_an = ?', [(string)($data['id_an'] ?? '')]);
  if (!$a) throw new LmsError('Data absensi narasumber tidak ditemukan.');
  batch_for_pic($user, $a['id_batch']);
  hapus_file($a['ttd_id']);
  hapus('absen_narasumber', $a);
  log_($user, 'absen_narasumber_hapus', $a['nama']);
  return true;
}

// ---------------------------------------------------------------- Rekap di laporan

/** Laporan resmi: halaman "Daftar Hadir Narasumber" (kosong jika tidak ada narasumber sama sekali). */
function daftar_hadir_narasumber(array $hariList): string {
  $baris = [];
  foreach ($hariList as $h) foreach (daftar_narasumber($h) as $x) $baris[] = [$h, $x];
  if (!$baris) return '';
  return '<div class="judul">DAFTAR HADIR NARASUMBER</div>' .
    '<table class="garis"><tr><th style="width:28px">No</th><th style="width:105px">Hari, Tanggal</th><th style="width:62px">Waktu</th><th>Materi</th>' .
    '<th>Nama Narasumber</th><th>Instansi</th><th style="width:110px">Tanda Tangan</th></tr>' .
    implode('', array_map(fn($b, $i) => '<tr style="page-break-inside:avoid"><td style="text-align:center">' . ($i + 1) . '</td><td>' .
      h_(tgl_panjang($b[0]['tanggal'])) . '</td><td>' . h_($b[1]['jam_mulai']) . '–' . h_($b[1]['jam_selesai']) . '</td><td>' . h_($b[1]['materi']) .
      '</td><td>' . h_($b[1]['nama']) . '</td><td>' . h_($b[1]['instansi']) . '</td><td style="text-align:center">' . ttd_narasumber($b[1]['absen']) .
      '</td></tr>', $baris, array_keys($baris))) . '</table>';
}

function ttd_narasumber(?array $a): string {
  if (!$a) return '<span style="font-size:8pt;color:#888">(belum tanda tangan)</span>';
  return img_(file_data_url($a['ttd_id']), 'height="38"') . '<br><span style="font-size:7.5pt">' . h_(substr($a['waktu_server'], 11, 5)) . ' WIB</span>';
}

/** Laporan harian: tabel daftar hadir narasumber hari itu ('' jika tidak ada). */
function narasumber_harian(array $hari): string {
  $list = daftar_narasumber($hari);
  if (!$list) return '';
  return '<h3>Daftar Hadir Narasumber</h3><table class="data"><tr><th style="width:85px">Waktu</th><th>Materi</th><th>Narasumber</th><th>Instansi</th>' .
    '<th style="width:110px">Tanda Tangan</th></tr>' . implode('', array_map(fn($x) => '<tr><td>' . h_($x['jam_mulai']) . '–' . h_($x['jam_selesai']) .
    '</td><td>' . h_($x['materi']) . '</td><td>' . h_($x['nama']) . '</td><td>' . h_($x['instansi']) . '</td><td class="center">' .
    ttd_narasumber($x['absen']) . '</td></tr>', $list)) . '</table>';
}
