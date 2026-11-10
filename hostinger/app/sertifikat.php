<?php
/**
 * Sertifikat pelatihan: BNSP (1 lembar), Basic Safety Training/BST (3 lembar), HSE Training (topik terpilih, bawaan 40).
 * - Pengaturan per batch (PIC & Admin): jenis yang diberikan, kode batch pada nomor, judul/periode BNSP (manual),
 *   tanggal & tempat BST, periode & topik HSE.
 * - Hanya Admin yang menerbitkan / menahan / membatalkan. PIC mendapat pemberitahuan di Dashboard dan bisa mengunduh.
 * - Peserta hanya melihat & mengunduh sertifikat yang sudah terbit.
 * - Nomor: {no} = nomor absen 3 digit, {kode} = kode batch per jenis, {bulan}/{tahun} = tanggal sertifikat.
 *   Nomor dibuat saat terbit dan tetap (ditahan lalu diterbitkan lagi = nomor sama; dibatalkan = nomor dibuat ulang).
 * - PDF dibuat saat diunduh di atas latar asli desain Artha Safety (app/sert-*.jpg).
 */

const JENIS_SERT = ['bnsp' => 'Sertifikat BNSP', 'bst' => 'Basic Safety Training (BST)', 'hse' => 'HSE Training'];
const LEMBAR_BST = ['BFA' => 'Basic First Aid', 'BFF' => 'Basic Fire Fighting', 'SCBA' => 'Self Contained Breathing Apparatus'];
const FORMAT_SERT = [
  'bnsp' => '{no}/{kode}/BNSP/ARSINDO/{bulan}/{tahun}',
  'bst' => '{no}/{kode}/{lembar}-ARSINDO/{bulan}/{tahun}',
  'hse' => '{no}/{topik}/{kode}/HSE-ARSINDO/{bulan}/{tahun}',
];
const BULAN_EN = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const A4_TEGAK = [0, 0, 595.28, 841.89];
const A4_DATAR = [0, 0, 841.89, 595.28];

// ---------------------------------------------------------------- Data dasar

function format_sert(): array {
  $f = json_decode(pengaturan('format_sertifikat') ?: '{}', true);
  return array_merge(FORMAT_SERT, is_array($f) ? array_filter($f, fn($x) => is_string($x) && $x !== '') : []);
}

/** Hari pertama & terakhir pelatihan (tanggal custom = tanggal paling awal & paling akhir). */
function batch_awal_akhir(array $b): array {
  if (($b['tanggal_custom'] ?? '') !== '') {
    $t = array_values(array_filter(explode(',', $b['tanggal_custom'])));
    sort($t);
    if ($t) return [$t[0], $t[count($t) - 1]];
  }
  return [$b['tgl_mulai'], $b['tgl_selesai']];
}

function batch_tgl_akhir(array $b): string { return batch_awal_akhir($b)[1]; }

/** Daftar topik HSE; diisi otomatis dari app/sertifikat-hse.json saat pertama kali dipakai. */
function topik_hse(bool $hanyaAktif = false): array {
  if (pengaturan('hse_topik_terisi') === '') {
    if (!row('SELECT id_topik FROM sertifikat_topik LIMIT 1')) {
      foreach (json_decode((string)file_get_contents(__DIR__ . '/sertifikat-hse.json'), true) as $t) {
        insert('sertifikat_topik', ['id_topik' => new_id('h'), 'kode' => $t['kode'], 'judul' => $t['judul'], 'bahasa' => $t['bahasa'],
          'isi_materi' => implode("\n", $t['materi']), 'urutan' => $t['urutan'], 'aktif' => 'ya']);
      }
    }
    pengaturan_simpan('hse_topik_terisi', now_()['stamp']);
  }
  $r = rows('SELECT * FROM sertifikat_topik ORDER BY CAST(urutan AS SIGNED), judul');
  return $hanyaAktif ? array_values(array_filter($r, fn($t) => $t['aktif'] !== 'tidak')) : $r;
}

/** Pengaturan sertifikat batch + nilai bawaan. */
function setelan_sert(array $b): array {
  $r = row('SELECT * FROM sertifikat_batch WHERE id_batch = ?', [$b['id_batch']]);
  $s = $r ? (json_decode($r['setelan'], true) ?: []) : [];
  [$awal, $akhir] = batch_awal_akhir($b);
  $hseBawaan = in_array(lembaga_skl($b), ['KEMNAKER RI', 'BNSP'], true); // inhouse/webinar (PT. Artha) tidak otomatis
  $def = [
    'bnsp' => ['aktif' => false, 'kode' => '', 'judul' => '', 'mulai' => '', 'selesai' => ''],
    'bst' => ['aktif' => false, 'kode' => '', 'tanggal' => $akhir, 'tempat_en' => '', 'tempat_id' => ''],
    'hse' => ['aktif' => !$r && $hseBawaan, 'kode' => '', 'mulai' => $awal, 'selesai' => $akhir, 'topik' => null],
  ];
  foreach ($def as $j => $d) $def[$j] = array_merge($d, is_array($s[$j] ?? null) ? $s[$j] : []);
  $def['tersimpan'] = (bool)$r;
  $def['diubah_oleh'] = $r['diubah_oleh'] ?? '';
  $def['diubah_pada'] = $r['diubah_pada'] ?? '';
  return $def;
}

/** Topik HSE yang dipakai batch (null = semua topik aktif). */
function topik_batch(array $set): array {
  $semua = topik_hse(true);
  if (!is_array($set['hse']['topik'])) return $semua;
  $pilih = array_flip($set['hse']['topik']);
  return array_values(array_filter($semua, fn($t) => isset($pilih[$t['id_topik']])));
}

/** Tanggal sertifikat (tanggal tanda tangan & bulan/tahun nomor). */
function tgl_sert(array $set, string $jenis): string {
  return $jenis === 'bst' ? $set['bst']['tanggal'] : $set[$jenis]['selesai'];
}

/** Hal yang belum lengkap sebelum sertifikat jenis ini bisa diterbitkan. */
function kurang_sert(array $set, string $jenis): array {
  $s = $set[$jenis];
  $k = [];
  if (trim($s['kode']) === '') $k[] = 'kode batch pada nomor';
  if ($jenis === 'bnsp') {
    if (trim($s['judul']) === '') $k[] = 'judul pelatihan';
    if ($s['mulai'] === '' || $s['selesai'] === '') $k[] = 'periode pelatihan';
  } elseif ($jenis === 'bst') {
    if ($s['tanggal'] === '') $k[] = 'tanggal pelaksanaan';
    if (trim($s['tempat_en']) === '' || trim($s['tempat_id']) === '') $k[] = 'tempat (Inggris & Indonesia)';
  } else {
    if ($s['mulai'] === '' || $s['selesai'] === '') $k[] = 'periode pelatihan';
    if (!topik_batch($set)) $k[] = 'topik';
  }
  return $k;
}

/** Nomor-nomor sertifikat seorang peserta: [kunci lembar => nomor]. */
function nomor_sert(array $b, array $set, string $jenis, array $u): array {
  $tgl = tgl_sert($set, $jenis);
  [$y, $m] = array_map('intval', explode('-', $tgl ?: now_()['date']));
  $f = format_sert()[$jenis];
  $dasar = ['{no}' => sprintf('%03d', (int)$u['no_absen']), '{kode}' => trim($set[$jenis]['kode']), '{bulan}' => ROMAWI[$m - 1],
    '{bulan_angka}' => sprintf('%02d', $m), '{tahun}' => (string)$y, '{batch}' => $b['kode_batch']];
  if ($jenis === 'bnsp') return ['BNSP' => strtr($f, $dasar)];
  $out = [];
  if ($jenis === 'bst') {
    foreach (LEMBAR_BST as $k => $_) $out[$k] = strtr($f, $dasar + ['{lembar}' => $k]);
  } else {
    foreach (topik_batch($set) as $t) $out[$t['id_topik']] = strtr($f, $dasar + ['{topik}' => $t['kode']]);
  }
  return $out;
}

function sert_of(string $idUser, string $jenis): ?array {
  return row('SELECT * FROM sertifikat WHERE id_user = ? AND jenis = ?', [$idUser, $jenis]);
}

function sert_view(?array $s): array {
  if (!$s) return ['status' => '', 'terbit' => false, 'nomor' => '', 'lembar' => 0, 'tgl_terbit' => ''];
  $n = json_decode($s['daftar_nomor'], true) ?: [];
  return ['status' => $s['status'], 'terbit' => $s['status'] === 'terbit', 'nomor' => (string)(reset($n) ?: ''), 'lembar' => count($n),
    'tgl_terbit' => $s['tgl_terbit']];
}

function cek_jenis(string $j): string {
  if (!isset(JENIS_SERT[$j])) throw new LmsError('Jenis sertifikat tidak dikenal.');
  return $j;
}

// ---------------------------------------------------------------- PIC & Admin

function sertifikatData(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $set = setelan_sert($b);
  $punya = [];
  foreach (rows('SELECT * FROM sertifikat WHERE id_batch = ?', [$b['id_batch']]) as $s) $punya[$s['id_user']][$s['jenis']] = sert_view($s);
  $contoh = [];
  $peserta = peserta_batch($b['id_batch'], false);
  $u0 = $peserta[0] ?? ['no_absen' => 1];
  foreach (JENIS_SERT as $j => $_) $contoh[$j] = array_slice(array_values(nomor_sert($b, $set, $j, $u0)), 0, 1)[0] ?? '';
  [$awal, $akhir] = batch_awal_akhir($b);
  return [
    'setelan' => $set, 'format' => format_sert(), 'jenis' => JENIS_SERT, 'lembar_bst' => LEMBAR_BST,
    'topik' => array_map(fn($t) => ['id_topik' => $t['id_topik'], 'kode' => $t['kode'], 'judul' => $t['judul'], 'bahasa' => $t['bahasa'],
      'jumlah_materi' => count(array_filter(explode("\n", $t['isi_materi'])))], topik_hse(true)),
    'kurang' => array_map(fn($j) => kurang_sert($set, $j), array_combine(array_keys(JENIS_SERT), array_keys(JENIS_SERT))),
    'contoh_nomor' => $contoh, 'periode_batch' => tanggal_batch_teks($b), 'awal' => $awal, 'akhir' => $akhir,
    'lembaga_skl' => lembaga_skl($b), 'admin' => $user['role'] === 'admin',
    'peserta' => array_map(fn($u) => ['id_user' => $u['id_user'], 'no_absen' => $u['no_absen'], 'nama' => $u['nama'], 'kode_login' => $u['kode_login'],
      'tempat_lahir' => $u['tempat_lahir'], 'tgl_lahir' => $u['tgl_lahir'],
      'sertifikat' => array_map(fn($j) => $punya[$u['id_user']][$j] ?? sert_view(null), array_combine(array_keys(JENIS_SERT), array_keys(JENIS_SERT)))], $peserta),
  ];
}

/** Simpan pengaturan sertifikat batch (PIC & Admin). Tempat/tanggal lahir peserta disimpan lewat data SKL. */
function sertifikatSimpan(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $lama = setelan_sert($b);
  $in = (array)($data['setelan'] ?? []);
  $tgl = function ($v, $label) {
    $v = trim((string)$v);
    if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) throw new LmsError("Tanggal $label tidak valid.");
    return $v;
  };
  $kode = function ($v) {
    $v = mb_substr(trim((string)$v), 0, 40);
    if (preg_match('#[/\s]#', $v)) throw new LmsError('Kode batch pada nomor sertifikat tidak boleh berisi spasi atau garis miring.');
    return $v;
  };
  $teks = fn($v, $n) => mb_substr(preg_replace('/\s+/', ' ', trim((string)$v)), 0, $n);
  $s = [];
  $x = (array)($in['bnsp'] ?? []);
  $s['bnsp'] = ['aktif' => !empty($x['aktif']), 'kode' => $kode($x['kode'] ?? ''), 'judul' => $teks($x['judul'] ?? '', 150),
    'mulai' => $tgl($x['mulai'] ?? '', 'mulai BNSP'), 'selesai' => $tgl($x['selesai'] ?? '', 'selesai BNSP')];
  $x = (array)($in['bst'] ?? []);
  $s['bst'] = ['aktif' => !empty($x['aktif']), 'kode' => $kode($x['kode'] ?? ''), 'tanggal' => $tgl($x['tanggal'] ?? '', 'BST'),
    'tempat_en' => $teks($x['tempat_en'] ?? '', 120), 'tempat_id' => $teks($x['tempat_id'] ?? '', 120)];
  $x = (array)($in['hse'] ?? []);
  $ids = array_column(topik_hse(), 'id_topik');
  $topik = isset($x['topik']) && is_array($x['topik']) ? array_values(array_intersect(array_map('strval', $x['topik']), $ids)) : null;
  if (is_array($topik) && count($topik) === count(topik_hse(true)) && !array_diff(array_column(topik_hse(true), 'id_topik'), $topik)) $topik = null; // semua = ikut daftar
  $s['hse'] = ['aktif' => !empty($x['aktif']), 'kode' => $kode($x['kode'] ?? ''), 'mulai' => $tgl($x['mulai'] ?? '', 'mulai HSE'),
    'selesai' => $tgl($x['selesai'] ?? '', 'selesai HSE'), 'topik' => $topik];
  foreach (['bnsp', 'hse'] as $j) {
    if ($s[$j]['mulai'] !== '' && $s[$j]['selesai'] !== '' && $s[$j]['mulai'] > $s[$j]['selesai']) throw new LmsError('Periode ' . strtoupper($j) . ': tanggal mulai melewati tanggal selesai.');
  }
  // Jenis yang sudah ada sertifikat terbit tidak bisa dimatikan (batalkan dulu).
  foreach (JENIS_SERT as $j => $label) {
    if ($lama[$j]['aktif'] && !$s[$j]['aktif'] && row("SELECT id_sertifikat FROM sertifikat WHERE id_batch = ? AND jenis = ? LIMIT 1", [$b['id_batch'], $j])) {
      throw new LmsError("$label sudah ada yang diterbitkan, jadi tidak bisa dimatikan. Admin perlu membatalkannya dulu.");
    }
  }
  $now = now_();
  $rec = ['setelan' => json_encode($s, JSON_UNESCAPED_UNICODE), 'diubah_oleh' => $user['nama'], 'diubah_pada' => $now['stamp']];
  $r = row('SELECT * FROM sertifikat_batch WHERE id_batch = ?', [$b['id_batch']]);
  if ($r) update('sertifikat_batch', $r, $rec); else insert('sertifikat_batch', ['id_batch' => $b['id_batch']] + $rec);
  if (isset($data['peserta'])) sklSimpanData(['id_batch' => $b['id_batch'], 'peserta' => $data['peserta']], $user);
  log_($user, 'sertifikat_setelan', $b['kode_batch']);
  return sertifikatData(['id_batch' => $b['id_batch']], $user);
}

/** Format nomor sertifikat (berlaku semua batch) — Admin. */
function sertifikatFormat(array $data, array $user): array {
  $f = [];
  foreach (FORMAT_SERT as $j => $def) {
    $v = trim((string)($data['format'][$j] ?? ''));
    if ($v === '') $v = $def;
    if (!str_contains($v, '{no}')) throw new LmsError('Format nomor ' . strtoupper($j) . ' harus memuat {no}.');
    if ($j === 'bst' && !str_contains($v, '{lembar}')) throw new LmsError('Format nomor BST harus memuat {lembar} (BFA/BFF/SCBA).');
    if ($j === 'hse' && !str_contains($v, '{topik}')) throw new LmsError('Format nomor HSE harus memuat {topik}.');
    $f[$j] = mb_substr($v, 0, 120);
  }
  pengaturan_simpan('format_sertifikat', json_encode($f, JSON_UNESCAPED_UNICODE));
  log_($user, 'sertifikat_format', implode(' | ', $f));
  return format_sert();
}

/** Admin menerbitkan sertifikat satu jenis untuk peserta terpilih. */
function sertifikatTerbitkan(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $jenis = cek_jenis((string)($data['jenis'] ?? ''));
  $set = setelan_sert($b);
  if (!$set[$jenis]['aktif']) throw new LmsError(JENIS_SERT[$jenis] . ' belum diaktifkan untuk batch ini.');
  if ($k = kurang_sert($set, $jenis)) throw new LmsError('Lengkapi dulu pengaturan ' . JENIS_SERT[$jenis] . ': ' . implode(', ', $k) . '.');
  $ids = array_values(array_unique(array_map('strval', (array)($data['id_users'] ?? []))));
  if (!$ids) throw new LmsError('Pilih minimal satu peserta.');
  $now = now_();
  $terbit = 0;
  $dilewati = [];
  foreach ($ids as $id) {
    $u = row("SELECT * FROM pengguna WHERE id_user = ? AND role = 'peserta' AND id_batch = ? AND aktif <> 'tidak'", [$id, $b['id_batch']]);
    if (!$u) continue;
    if ($jenis === 'bnsp' && ($u['tempat_lahir'] === '' || $u['tgl_lahir'] === '')) { $dilewati[] = $u['nama'] . ' (tempat/tanggal lahir kosong)'; continue; }
    $s = sert_of($id, $jenis);
    if ($s && $s['status'] === 'terbit') { $terbit++; continue; }
    if ($s) {
      update('sertifikat', $s, ['status' => 'terbit', 'diubah_oleh' => $user['nama'], 'diubah_pada' => $now['stamp']]);
      $terbit++;
      continue;
    }
    $nomor = nomor_sert($b, $set, $jenis, $u);
    $kembar = '';
    foreach ($nomor as $n) {
      $x = row('SELECT s.id_user FROM sertifikat s WHERE s.jenis = ? AND s.daftar_nomor LIKE ? LIMIT 1', [$jenis, '%' . json_encode($n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '%']);
      if ($x) { $kembar = $n; break; }
    }
    if ($kembar !== '') { $dilewati[] = $u['nama'] . " (nomor $kembar sudah dipakai — periksa kode batch)"; continue; }
    insert('sertifikat', ['id_sertifikat' => new_id('c'), 'id_batch' => $b['id_batch'], 'id_user' => $id, 'jenis' => $jenis,
      'daftar_nomor' => json_encode($nomor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'status' => 'terbit', 'tgl_terbit' => $now['date'],
      'diterbitkan_oleh' => $user['nama'], 'diubah_oleh' => $user['nama'], 'diubah_pada' => $now['stamp']]);
    $terbit++;
  }
  log_($user, 'sertifikat_terbit', $b['kode_batch'] . ' ' . $jenis . ': ' . $terbit . ' peserta');
  return ['terbit' => $terbit, 'dilewati' => $dilewati];
}

/** Admin: tahan (peserta tidak bisa unduh, nomor tetap) atau batalkan (nomor dihapus; terbit ulang = nomor dibuat ulang). */
function sertifikatTahan(array $data, array $user): int {
  return ubah_status_sert($data, $user, 'tahan');
}

function sertifikatBatal(array $data, array $user): int {
  return ubah_status_sert($data, $user, 'batal');
}

function ubah_status_sert(array $data, array $user, string $aksi): int {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $jenis = cek_jenis((string)($data['jenis'] ?? ''));
  $n = 0;
  foreach ((array)($data['id_users'] ?? []) as $id) {
    $s = sert_of((string)$id, $jenis);
    if (!$s || $s['id_batch'] !== $b['id_batch']) continue;
    if ($aksi === 'batal') hapus('sertifikat', $s);
    elseif ($s['status'] === 'terbit') update('sertifikat', $s, ['status' => 'ditahan', 'diubah_oleh' => $user['nama'], 'diubah_pada' => now_()['stamp']]);
    else continue;
    $n++;
  }
  log_($user, 'sertifikat_' . $aksi, $b['kode_batch'] . ' ' . $jenis . ': ' . $n . ' peserta');
  return $n;
}

/**
 * Unduh PDF sertifikat. Peserta: miliknya yang sudah terbit. PIC/Admin: peserta batchnya; 'pratinjau' = contoh
 * sebelum terbit (bertanda PRATINJAU).
 */
function sertifikatUnduh(array $data, array $user): array {
  $jenis = cek_jenis((string)($data['jenis'] ?? ''));
  $u = $user['role'] === 'peserta' ? $user : peserta_for_pic($user, (string)($data['id_user'] ?? ''));
  $b = get_batch($u['id_batch']);
  $set = setelan_sert($b);
  $s = sert_of($u['id_user'], $jenis);
  $pratinjau = false;
  if (!$s || $s['status'] !== 'terbit') {
    if ($user['role'] === 'peserta') throw new LmsError('Sertifikat ini belum diterbitkan. Silakan hubungi PIC pelatihan.');
    if (empty($data['pratinjau'])) throw new LmsError('Sertifikat peserta ini belum diterbitkan.');
    $pratinjau = true;
    $s = ['daftar_nomor' => json_encode(nomor_sert($b, $set, $jenis, $u), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
  }
  if ($user['role'] === 'peserta') log_($user, 'sertifikat_unduh', $jenis);
  $nama = preg_replace('#[\\\\/:*?"<>|]#', '-', 'Sertifikat ' . strtoupper($jenis) . ' - ' . $u['nama'] . ($pratinjau ? ' (PRATINJAU)' : '') . '.pdf');
  return ['nama' => $nama, 'data' => base64_encode(html_ke_pdf(sert_html($b, $set, $jenis, [[$u, $s]], $pratinjau), $jenis === 'bst' ? A4_DATAR : A4_TEGAK))];
}

/**
 * Semua sertifikat terbit satu jenis. BNSP & BST: satu PDF. HSE (banyak halaman): ZIP berisi satu PDF per peserta,
 * dibuat bertahap (beberapa peserta per permintaan) agar tidak melewati batas waktu server.
 */
function sertifikatUnduhSemua(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $jenis = cek_jenis((string)($data['jenis'] ?? ''));
  $set = setelan_sert($b);
  $list = [];
  foreach (peserta_batch($b['id_batch'], false) as $u) {
    $s = sert_of($u['id_user'], $jenis);
    if ($s && $s['status'] === 'terbit') $list[] = [$u, $s];
  }
  if (!$list) throw new LmsError('Belum ada ' . JENIS_SERT[$jenis] . ' yang terbit di batch ini.');
  $judul = preg_replace('#[\\\\/:*?"<>|]#', '-', 'Sertifikat ' . strtoupper($jenis) . ' ' . $b['kode_batch'] . ' - ' . $b['nama_pelatihan']);
  if ($jenis !== 'hse') {
    return ['selesai' => true, 'nama' => $judul . '.pdf', 'tipe' => 'application/pdf',
      'data' => base64_encode(html_ke_pdf(sert_html($b, $set, $jenis, $list), $jenis === 'bst' ? A4_DATAR : A4_TEGAK))];
  }
  if (!class_exists('ZipArchive')) throw new LmsError('Server tidak mendukung ZIP. Unduh per peserta.');
  $dir = STORAGE . '/tmp/sert-' . $b['id_batch'];
  pastikan_folder($dir);
  $mulai = max(0, (int)($data['mulai'] ?? 0));
  if ($mulai === 0) foreach (glob($dir . '/*.pdf') ?: [] as $f) @unlink($f);
  $batas = microtime(true) + 20;
  $i = $mulai;
  for (; $i < count($list) && microtime(true) < $batas; $i++) {
    [$u, $s] = $list[$i];
    $f = sprintf('%s/%03d %s.pdf', $dir, (int)$u['no_absen'], preg_replace('#[\\\\/:*?"<>|]#', '-', $u['nama']));
    file_put_contents($f, html_ke_pdf(sert_html($b, $set, $jenis, [[$u, $s]]), A4_TEGAK));
  }
  if ($i < count($list)) return ['selesai' => false, 'mulai' => $i, 'total' => count($list)];
  $zipFile = $dir . '/semua.zip';
  @unlink($zipFile);
  $z = new ZipArchive();
  if ($z->open($zipFile, ZipArchive::CREATE) !== true) throw new LmsError('Gagal membuat ZIP.');
  foreach (glob($dir . '/*.pdf') ?: [] as $f) $z->addFile($f, basename($f));
  $z->close();
  $isi = (string)file_get_contents($zipFile);
  foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
  @rmdir($dir);
  log_($user, 'sertifikat_unduh_semua', $b['kode_batch'] . ' ' . $jenis);
  return ['selesai' => true, 'nama' => $judul . '.zip', 'tipe' => 'application/zip', 'data' => base64_encode($isi)];
}

/** Daftar topik HSE (semua, termasuk nonaktif) beserta materinya — Admin. */
function topikHseList(array $data, array $user): array {
  return array_map(fn($t) => $t + ['materi' => array_values(array_filter(explode("\n", $t['isi_materi']), fn($x) => trim($x) !== ''))], topik_hse());
}

function topikHseSimpan(array $data, array $user): array {
  $kode = strtoupper(trim((string)($data['kode'] ?? '')));
  $judul = preg_replace('/\s+/', ' ', trim((string)($data['judul'] ?? '')));
  if ($judul === '') throw new LmsError('Judul topik wajib diisi.');
  if (!preg_match('/^[A-Z0-9-]{1,12}$/', $kode)) throw new LmsError('Kode topik: huruf besar/angka, tanpa spasi (mis. LOTO).');
  $materi = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)($data['materi'] ?? ''))), fn($x) => $x !== ''));
  $id = (string)($data['id_topik'] ?? '');
  $sama = row('SELECT id_topik FROM sertifikat_topik WHERE kode = ? AND id_topik <> ?', [$kode, $id]);
  if ($sama) throw new LmsError("Kode $kode sudah dipakai topik lain.");
  $c = ['kode' => $kode, 'judul' => mb_substr($judul, 0, 200), 'bahasa' => ($data['bahasa'] ?? '') === 'en' ? 'en' : 'id',
    'isi_materi' => implode("\n", array_slice($materi, 0, 15)), 'urutan' => (int)($data['urutan'] ?? 99),
    'aktif' => array_key_exists('aktif', $data) && !$data['aktif'] ? 'tidak' : 'ya'];
  topik_hse();
  if ($id === '') insert('sertifikat_topik', ['id_topik' => new_id('h')] + $c);
  else {
    $t = row('SELECT * FROM sertifikat_topik WHERE id_topik = ?', [$id]);
    if (!$t) throw new LmsError('Topik tidak ditemukan.');
    update('sertifikat_topik', $t, $c);
  }
  log_($user, 'topik_hse', $kode . ' ' . $judul);
  return topikHseList([], $user);
}

// ---------------------------------------------------------------- Peserta

function sertifikat_terbit_peserta(string $idUser): array {
  return rows("SELECT * FROM sertifikat WHERE id_user = ? AND status = 'terbit'", [$idUser]);
}

/** Ringkasan untuk Dashboard peserta. Aman bila tabel belum ada (update belum lengkap). */
function sertifikat_ringkas(array $user): array {
  try {
    return array_map(fn($s) => ['jenis' => $s['jenis'], 'label' => JENIS_SERT[$s['jenis']] ?? $s['jenis']], sertifikat_terbit_peserta($user['id_user']));
  } catch (Throwable $e) {
    return [];
  }
}

function sertifikatSaya(array $data, array $user): array {
  wajib_peserta($user);
  return array_map(fn($s) => ['jenis' => $s['jenis'], 'label' => JENIS_SERT[$s['jenis']] ?? $s['jenis']] + sert_view($s), sertifikat_terbit_peserta($user['id_user']));
}

// ---------------------------------------------------------------- Dashboard PIC/Admin

function pengingat_sertifikat(array $b, string $fase, array $user): array {
  try {
    $set = setelan_sert($b);
    if (!$set['tersimpan']) return [];
    $p = [];
    $n = (int)row("SELECT COUNT(*) AS n FROM pengguna WHERE role = 'peserta' AND id_batch = ? AND aktif <> 'tidak'", [$b['id_batch']])['n'];
    $hitung = [];
    foreach (rows("SELECT jenis, MAX(diubah_pada) AS t, COUNT(*) AS n FROM sertifikat WHERE id_batch = ? AND status = 'terbit' GROUP BY jenis", [$b['id_batch']]) as $r) $hitung[$r['jenis']] = $r;
    $lama = date('Y-m-d H:i:s', strtotime(now_()['stamp']) - 14 * 86400);
    foreach (JENIS_SERT as $j => $label) {
      if (!$set[$j]['aktif']) continue;
      $t = (int)($hitung[$j]['n'] ?? 0);
      if ($t && ($hitung[$j]['t'] ?? '') >= $lama) {
        $p[] = pengingat_('info', "$label sudah diterbitkan Admin untuk $t dari $n peserta — bisa diunduh untuk laporan ke klien.", 'sertifikat');
      }
      if ($fase === 'selesai' && $t < $n) {
        $k = kurang_sert($set, $j);
        if ($k) $p[] = pengingat_('warn', "Lengkapi data $label: " . implode(', ', $k) . '.', 'sertifikat');
        elseif ($user['role'] === 'admin') $p[] = pengingat_('warn', "$label belum diterbitkan untuk " . ($n - $t) . ' peserta.', 'sertifikat');
      }
    }
    return $p;
  } catch (Throwable $e) {
    return [];
  }
}

// ---------------------------------------------------------------- Format PDF (latar asli desain Artha Safety)

/** 5 -> '05<sup>th</sup>' */
function hari_en(int $d): string {
  $suf = in_array($d % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$d % 10] ?? 'th');
  return sprintf('%02d', $d) . '<sup>' . $suf . '</sup>';
}

/** 'November 05<sup>th</sup>, 2026' (tanpa koma sebelum tahun bila $koma = false) */
function tgl_en(string $s, bool $koma = true): string {
  [$y, $m, $d] = array_map('intval', explode('-', $s));
  return BULAN_EN[$m - 1] . ' ' . hari_en($d) . ($koma ? ', ' : ' ') . $y;
}

/** 'November 02nd – 05th, 2026' / 'October 30th – November 02nd, 2026' */
function periode_en(string $a, string $b): string {
  if ($a === $b) return tgl_en($a);
  [$y1, $m1, $d1] = array_map('intval', explode('-', $a));
  [$y2, $m2, $d2] = array_map('intval', explode('-', $b));
  if ($y1 === $y2 && $m1 === $m2) return BULAN_EN[$m1 - 1] . ' ' . hari_en($d1) . ' – ' . hari_en($d2) . ', ' . $y1;
  if ($y1 === $y2) return BULAN_EN[$m1 - 1] . ' ' . hari_en($d1) . ' – ' . BULAN_EN[$m2 - 1] . ' ' . hari_en($d2) . ', ' . $y1;
  return tgl_en($a) . ' – ' . tgl_en($b);
}

/**
 * Teks pada posisi tetap. $y = garis dasar dari atas halaman (pt), $cx = titik tengah (rata tengah) atau
 * kiri bila $kiri. Ukuran huruf mengecil otomatis bila teks terlalu panjang untuk $w.
 */
function sert_teks(float $cx, float $y, float $size, string $html, string $gaya = '', float $w = 480, bool $kiri = false, float $muat = 0): string {
  if ($muat > 0) { // perkiraan lebar huruf Times tebal ≈ 0,5 × ukuran
    $polos = html_entity_decode(strip_tags($html));
    $size = min($size, max($size * 0.55, $muat / max(1, mb_strlen($polos) * 0.5)));
  }
  $top = $y - $size * 1.11;
  $left = $kiri ? $cx : $cx - $w / 2;
  return '<div style="position:absolute;left:' . round($left, 1) . 'pt;top:' . round($top, 1) . 'pt;width:' . $w . 'pt;font-size:' . round($size, 1) . 'pt;line-height:' . round($size * 1.15, 1) . 'pt;' .
    ($kiri ? '' : 'text-align:center;') . $gaya . '">' . $html . '</div>';
}

function sert_latar(string $file): string {
  return 'data:image/jpeg;base64,' . base64_encode((string)file_get_contents(__DIR__ . '/' . $file));
}

/** $list = [[pengguna, sertifikat], ...] — satu peserta = 1 / 3 / N halaman. */
function sert_html(array $b, array $set, string $jenis, array $list, bool $pratinjau = false): string {
  $org = org_();
  $kertas = $jenis === 'bst' ? A4_DATAR : A4_TEGAK;
  [$W, $H] = [$kertas[2], $kertas[3]];
  $css = '@page { margin: 0; } body { margin: 0; font-family: "Times New Roman", Times, serif; color: #000; }
    .hal { position: relative; width: ' . $W . 'pt; height: ' . ($H - 1) . 'pt; overflow: hidden; page-break-after: always; }
    .hal.akhir { page-break-after: auto; }
    sup { font-size: 66%; line-height: 0; vertical-align: 0.5em; }
    .tanda { position: absolute; left: 0; width: ' . $W . 'pt; text-align: center; font: bold 64pt Helvetica; color: #e0262b; opacity: .18; }';
  $hal = [];
  $tanda = $pratinjau ? '<div class="tanda" style="top:' . round($H / 2 - 40) . 'pt">PRATINJAU</div>' : '';
  $latarCache = [];
  $latar = function (string $f) use (&$latarCache) { return $latarCache[$f] ??= sert_latar($f); };
  $img = fn($f) => '<img src="' . $latar($f) . '" style="position:absolute;left:0;top:0;width:' . $W . 'pt;height:' . $H . 'pt">';
  $ttd = function (float $cx, float $yKota, float $yNama, string $tgl, bool $tebal, float $jarak = 16) use ($org) {
    return sert_teks($cx, $yKota, 12, h_($org['kota']) . ', ' . $tgl, '', 240) .
      sert_teks($cx, $yKota + $jarak, 12, 'PT. Artha Safety Indonesia', '', 240) .
      sert_teks($cx, $yNama, 12, h_($org['penandatangan']), $tebal ? 'font-weight:bold;' : '', 240) .
      sert_teks($cx, $yNama + 15, 12, h_($org['jabatan']), $tebal ? 'font-weight:bold;' : '', 240);
  };
  foreach ($list as [$u, $s]) {
    $nomor = json_decode($s['daftar_nomor'], true) ?: [];
    if ($jenis === 'bnsp') {
      $c = $set['bnsp'];
      $ttl = trim($u['tempat_lahir'] . ($u['tgl_lahir'] !== '' ? ', ' . tgl_skl($u['tgl_lahir']) : ''));
      $biru = 'color:#1f3863;';
      $hal[] = $img('sert-bnsp.jpg') .
        sert_teks(297.6, 268, 12, 'This is to certify that:') .
        sert_teks(297.6, 296, 12, 'Name:') .
        sert_teks(297.6, 354, 26, h_($u['nama']), 'font-weight:bold;' . $biru, 500, false, 470) .
        sert_teks(297.6, 384, 12, 'Place, date of birth:') .
        sert_teks(297.6, 411, 11.5, h_($ttl ?: '-'), 'font-weight:bold;') .
        sert_teks(297.6, 425, 12, 'Has successfully attended') .
        sert_teks(297.6, 453, 12, 'Training') .
        sert_teks(297.6, 480, 12, h_($c['judul']), 'font-weight:bold;' . $biru, 460) .
        sert_teks(297.6, 503, 14, 'Certification BNSP', 'font-weight:bold;' . $biru) .
        sert_teks(297.6, 544, 12, 'Held by', 'font-style:italic;') .
        sert_teks(297.6, 560, 12, 'PT. Artha Safety Indonesia') .
        sert_teks(297.6, 576, 12, 'on ' . periode_en($c['mulai'], $c['selesai'])) .
        $ttd(436.5, 607, 734, tgl_en($c['selesai']), true) .
        sert_teks(52.8, 734, 12, 'NO. ' . h_($nomor['BNSP'] ?? ''), '', 300, true) . $tanda;
    } elseif ($jenis === 'bst') {
      $c = $set['bst'];
      [$y, $m, $d] = array_map('intval', explode('-', $c['tanggal']));
      $sans = 'font-family:Helvetica, Arial, sans-serif;';
      foreach (LEMBAR_BST as $k => $_) {
        $hal[] = $img('sert-' . strtolower($k) . '.jpg') .
          sert_teks(421.5, 162, 10.5, h_($nomor[$k] ?? ''), 'font-weight:bold;font-family:Helvetica, Arial, sans-serif;', 400, false) .
          sert_teks(425.5, 280, 36, h_($u['nama']), 'font-weight:bold;color:#002060;', 700, false, 640) .
          sert_teks(422, 395, 10.5, 'Hold in ' . h_($c['tempat_en']) . ', ' . tgl_en($c['tanggal'], false), $sans, 560) .
          sert_teks(422, 417, 10.5, 'Dilaksanakan di ' . h_($c['tempat_id']) . ', ' . sprintf('%02d', $d) . ' ' . BULAN_ID[$m - 1] . ' ' . $y, $sans, 560) .
          $tanda;
      }
    } else {
      $c = $set['hse'];
      $topik = array_column(topik_hse(), null, 'id_topik');
      foreach ($nomor as $idt => $no) {
        $t = $topik[$idt] ?? null;
        if (!$t) continue;
        $en = $t['bahasa'] === 'en';
        $materi = array_values(array_filter(explode("\n", $t['isi_materi']), fn($x) => trim($x) !== ''));
        $n = count($materi);
        // Tata letak menyesuaikan jumlah materi & panjang judul agar tidak menabrak tanda tangan (tetap di latar):
        // judul "Materi" naik sedikit, lalu jeda dikurangi, lalu jarak baris materi, terakhir ukuran huruf materi.
        $tglTeks = $en ? periode_en($c['mulai'], $c['selesai']) : periode_skl($c['mulai'], $c['selesai']);
        $awal = ($en ? 'Has successfully completed a Training ' : 'Telah Mengikuti Training ') . $t['judul'] . ($en ? '' : ' pada tanggal ' . strip_tags($tglTeks));
        $baris = (int)ceil(mb_strlen($awal) * 5.5 / 425) + ($en ? 2 : 1);
        $kalimat = ($en ? 'Has successfully completed a Training ' : 'Telah Mengikuti Training ') . '<b><i>' . h_($t['judul']) . '</i></b>' .
          ($en ? '<br>On ' . $tglTeks : ' <span style="white-space:nowrap">pada tanggal ' . $tglTeks . '</span>') .
          '<br>' . ($en ? 'by' : 'Oleh') . ' PT Artha Safety Indonesia';
        $mulai = 276; $p = 21; $g = 69; $fs = 12; $lh = 21;
        $lebih = function () use (&$mulai, &$p, &$g, &$lh, $n, $baris) { return $mulai + 27 + ($n - 1) * $p + $g + 38 + 24 + ($baris - 1) * $lh - 596; };
        if ($lebih() > 0) $mulai = max(258, $mulai - $lebih());
        if ($lebih() > 0) $g = max(32, $g - $lebih());
        if ($lebih() > 0) $p = max(16.5, $p - $lebih() / max(1, $n - 1));
        if ($lebih() > 0) $lh = max(17, $lh - $lebih() / max(1, $baris - 1));
        if ($lebih() > 0) { $fs = 11; $p = max(14, $p - $lebih() / max(1, $n - 1)); }
        $y = $mulai;
        $isi = sert_teks(72.3, $y, 12, $en ? 'Contents :' : 'Materi :', '', 300, true);
        $y += 27;
        foreach ($materi as $i => $mt) {
          $isi .= sert_teks(96.9, $y, $fs, ($i + 1) . '.', '', 20, true) . sert_teks(114.9, $y, $fs, h_($mt), '', 420, true);
          $y += $p;
        }
        $y += $g - $p;
        $isi .= sert_teks(297.7, $y, 12, $en ? 'It is hereby certified that :' : 'Diberikan Kepada :');
        $y += 38;
        $isi .= sert_teks(297.7, $y, 18, h_($u['nama']), 'font-weight:bold;color:#44546a;', 460, false, 440);
        $y += 24;
        $isi .= '<div style="position:absolute;left:82pt;top:' . round($y - 12 * 1.11 - ($lh - 12) / 2 - 1, 1) . 'pt;width:432pt;text-align:center;font-size:12pt;line-height:' . round($lh, 1) . 'pt">' . $kalimat . '</div>';
        $hal[] = $img('sert-hse.jpg') . $isi .
          $ttd(447, 623, 737, $en ? tgl_en($c['selesai']) : tgl_skl($c['selesai']), false, 23) .
          sert_teks(72.3, 738, $en ? 12 : 11.5, 'No. ' . h_($no), '', 300, true) . $tanda;
      }
    }
  }
  $out = ['<html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>'];
  foreach ($hal as $i => $h) $out[] = '<div class="hal' . ($i === count($hal) - 1 ? ' akhir' : '') . '">' . $h . '</div>';
  $out[] = '</body></html>';
  return implode("\n", $out);
}
