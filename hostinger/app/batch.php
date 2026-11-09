<?php
/** Batch, hari pelatihan, lokasi, dan data peserta. */

const DEFAULT_RADIUS = 150;

function get_batch(string $idBatch): array {
  $b = row('SELECT * FROM batch WHERE id_batch = ?', [$idBatch]);
  if (!$b) throw new LmsError('Batch tidak ditemukan.');
  return $b;
}

function pic_ids(array $b): array {
  return $b['pic_ids'] === '' ? [] : explode(',', $b['pic_ids']);
}

function can_access_batch(array $user, array $batch): bool {
  if ($user['role'] === 'admin') return true;
  if ($user['role'] === 'pic') return in_array($user['id_user'], pic_ids($batch), true);
  return $user['id_batch'] === $batch['id_batch'];
}

/** Mengambil batch dan memastikan PIC berhak mengelolanya. */
function batch_for_pic(array $user, string $idBatch): array {
  $b = get_batch($idBatch);
  if (!can_access_batch($user, $b)) throw new LmsError('Anda bukan PIC batch ini.');
  return $b;
}

function batch_view(array $b): array {
  $out = [];
  foreach (SCHEMA['batch'] as $k) $out[$k] = $b[$k];
  $out['jumlah_peserta'] = (int)row("SELECT COUNT(*) AS n FROM pengguna WHERE role = 'peserta' AND id_batch = ? AND aktif <> 'tidak'", [$b['id_batch']])['n'];
  $ids = pic_ids($b);
  $out['pic_nama'] = $ids ? array_column(rows('SELECT nama FROM pengguna WHERE id_user IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids), 'nama') : [];
  $out['folder_url'] = '';
  return $out;
}

function batchList(array $data, array $user): array {
  $list = array_values(array_filter(rows('SELECT * FROM batch'), fn($b) => can_access_batch($user, $b)));
  $urut = ['aktif' => 0, 'selesai' => 1, 'arsip' => 2];
  usort($list, fn($a, $b) => ($urut[$a['status']] ?? 1) <=> ($urut[$b['status']] ?? 1) ?: strcmp($b['tgl_mulai'], $a['tgl_mulai']));
  return array_map('batch_view', $list);
}

function validasi_batch(array $d): void {
  if (trim((string)($d['nama_pelatihan'] ?? '')) === '') throw new LmsError('Nama pelatihan wajib diisi.');
  if (!is_date($d['tgl_mulai'] ?? '') || !is_date($d['tgl_selesai'] ?? '')) throw new LmsError('Tanggal mulai/selesai tidak valid.');
  if ($d['tgl_selesai'] < $d['tgl_mulai']) throw new LmsError('Tanggal selesai tidak boleh sebelum tanggal mulai.');
  foreach (['sesi1_mulai', 'sesi1_selesai', 'sesi2_mulai', 'sesi2_selesai'] as $k) {
    if (!is_time($d[$k] ?? '')) throw new LmsError('Jam sesi tidak valid (format JJ:MM).');
  }
  if ($d['sesi1_selesai'] <= $d['sesi1_mulai'] || $d['sesi2_selesai'] <= $d['sesi2_mulai']) throw new LmsError('Jam selesai sesi harus setelah jam mulai.');
  if ($d['sesi2_mulai'] < $d['sesi1_selesai']) throw new LmsError('Sesi 2 harus dimulai setelah Sesi 1 selesai.');
  validasi_lokasi($d, true);
}

function validasi_lokasi(array $d, bool $optional): void {
  $empty = !isset($d['lat']) || $d['lat'] === '' || $d['lat'] === null;
  if ($empty && $optional) return;
  $r = (float)(($d['radius_meter'] ?? '') !== '' ? $d['radius_meter'] : DEFAULT_RADIUS);
  if ($empty || !is_numeric($d['lat']) || !is_numeric($d['lng'] ?? '') || abs((float)$d['lat']) > 90 || abs((float)$d['lng']) > 180) {
    throw new LmsError('Koordinat lokasi tidak valid.');
  }
  if (!($r >= 20 && $r <= 5000)) throw new LmsError('Radius harus antara 20 dan 5000 meter.');
}

/**
 * Tanggal pilihan (mode custom): array 'yyyy-mm-dd' -> 'yyyy-mm-dd,yyyy-mm-dd,...' terurut, unik.
 * Mengisi juga tgl_mulai/tgl_selesai dengan tanggal pertama/terakhir. Array kosong = mode rentang biasa.
 */
function tanggal_custom_(array &$d): void {
  if (!array_key_exists('tanggal_custom', $d)) return;
  $list = is_array($d['tanggal_custom']) ? $d['tanggal_custom'] : array_filter(explode(',', (string)$d['tanggal_custom']));
  $list = array_values(array_unique(array_map(fn($t) => trim((string)$t), $list)));
  foreach ($list as $t) {
    $x = is_date($t) ? array_map('intval', explode('-', $t)) : [0, 0, 0];
    if (!checkdate($x[1], $x[2], $x[0])) throw new LmsError('Tanggal pilihan tidak valid: ' . $t);
  }
  sort($list);
  if (count($list) > 90) throw new LmsError('Maksimal 90 tanggal pelatihan.');
  $d['tanggal_custom'] = implode(',', $list);
  if ($list) [$d['tgl_mulai'], $d['tgl_selesai']] = [$list[0], end($list)];
}

/** Program & skema batch (opsional). Program harus terdaftar; skema Offline/Online/Blended. */
function validasi_program(array $d): void {
  if (($d['id_program'] ?? '') !== '' && !row('SELECT id_program FROM program WHERE id_program = ?', [$d['id_program']])) throw new LmsError('Program pelatihan tidak ditemukan.');
  if (($d['jenis_pelatihan'] ?? '') !== '' && !in_array($d['jenis_pelatihan'], JENIS_PELATIHAN, true)) throw new LmsError('Skema pelatihan tidak valid.');
}

function batchBuat(array $data, array $user): array {
  $d = array_merge(['sesi1_mulai' => '08:00', 'sesi1_selesai' => '12:00', 'sesi2_mulai' => '13:00', 'sesi2_selesai' => '17:00',
    'radius_meter' => DEFAULT_RADIUS], $data['batch'] ?? []);
  tanggal_custom_($d);
  validasi_program($d);
  validasi_batch($d);
  $kode = trim((string)($d['kode_batch'] ?? ''));
  if ($kode === '') throw new LmsError('Kode batch wajib diisi (mis. B07).');
  if (row('SELECT id_batch FROM batch WHERE LOWER(kode_batch) = ?', [strtolower($kode)])) throw new LmsError('Kode batch sudah dipakai.');
  $b = insert('batch', [
    'id_batch' => new_id('b'), 'nama_pelatihan' => trim((string)$d['nama_pelatihan']), 'kode_batch' => $kode,
    'lokasi_nama' => $d['lokasi_nama'] ?? '', 'tgl_mulai' => $d['tgl_mulai'], 'tgl_selesai' => $d['tgl_selesai'],
    'sesi1_mulai' => $d['sesi1_mulai'], 'sesi1_selesai' => $d['sesi1_selesai'], 'sesi2_mulai' => $d['sesi2_mulai'], 'sesi2_selesai' => $d['sesi2_selesai'],
    'lat' => $d['lat'] ?? '', 'lng' => $d['lng'] ?? '', 'radius_meter' => $d['radius_meter'],
    'lewati_weekend' => !empty($d['lewati_weekend']) ? 'ya' : '', 'pic_ids' => implode(',', $d['pic_ids'] ?? []),
    'folder' => folder_aman($kode . '-' . trim((string)$d['nama_pelatihan'])), 'status' => 'aktif', 'dibuat_pada' => now_()['stamp'],
    'id_program' => $d['id_program'] ?? '', 'jenis_pelatihan' => $d['jenis_pelatihan'] ?? '', 'tanggal_custom' => $d['tanggal_custom'] ?? '',
    // Batch baru berskema Online otomatis bebas lokasi (bisa diubah di Jadwal & Lokasi).
    'lokasi_bebas' => ($d['jenis_pelatihan'] ?? '') === 'Online' ? 'ya' : '',
  ]);
  sync_hari($b);
  log_($user, 'batch_buat', $kode);
  return batch_view($b);
}

/** PIC boleh mengubah jam sesi & lokasi default; Admin juga nama, tanggal, PIC, dan status. */
function batchUbah(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $d = $data['batch'] ?? [];
  if ($user['role'] === 'admin') {
    tanggal_custom_($d);
    validasi_program($d);
  }
  $editable = ['sesi1_mulai', 'sesi1_selesai', 'sesi2_mulai', 'sesi2_selesai', 'lokasi_nama', 'lat', 'lng', 'radius_meter', 'lokasi_bebas'];
  if (array_key_exists('lokasi_bebas', $d)) $d['lokasi_bebas'] = $d['lokasi_bebas'] === 'ya' || $d['lokasi_bebas'] === true ? 'ya' : '';
  if ($user['role'] === 'admin') array_push($editable, 'nama_pelatihan', 'tgl_mulai', 'tgl_selesai', 'status', 'id_program', 'jenis_pelatihan', 'tanggal_custom');
  $changes = [];
  foreach ($editable as $k) if (array_key_exists($k, $d)) $changes[$k] = str_val($d[$k]);
  if ($user['role'] === 'admin' && isset($d['pic_ids']) && is_array($d['pic_ids'])) $changes['pic_ids'] = implode(',', $d['pic_ids']);
  if ($user['role'] === 'admin' && array_key_exists('lewati_weekend', $d)) $changes['lewati_weekend'] = $d['lewati_weekend'] ? 'ya' : '';
  if (isset($changes['status']) && !in_array($changes['status'], ['aktif', 'selesai', 'arsip'], true)) throw new LmsError('Status tidak valid.');
  validasi_batch(array_merge($b, $changes));
  $hariBerubah = false;
  foreach (['tgl_mulai', 'tgl_selesai', 'lewati_weekend', 'tanggal_custom'] as $k) if (isset($changes[$k]) && $changes[$k] !== $b[$k]) $hariBerubah = true;
  $b = update('batch', $b, $changes);
  if ($hariBerubah) sync_hari($b);
  log_($user, 'batch_ubah', $b['kode_batch'] . ': ' . implode(', ', array_keys($changes)));
  return batch_view($b);
}

/**
 * Menghapus batch beserta SEMUA datanya (hari, peserta, absensi, rundown, materi, dokumentasi, laporan, SKL,
 * absensi narasumber) dan file-filenya. Tidak bisa dibatalkan. Konfirmasi = kode batch diketik ulang.
 */
function batchHapus(array $data, array $user): bool {
  $b = get_batch((string)($data['id_batch'] ?? ''));
  if (trim((string)($data['konfirmasi'] ?? '')) !== $b['kode_batch']) throw new LmsError('Ketik kode batch "' . $b['kode_batch'] . '" dengan benar untuk menghapus.');
  $pdo = db();
  $pdo->beginTransaction();
  try {
    q("DELETE FROM token WHERE id_user IN (SELECT id_user FROM pengguna WHERE role = 'peserta' AND id_batch = ?)", [$b['id_batch']]);
    q("DELETE FROM pengguna WHERE role = 'peserta' AND id_batch = ?", [$b['id_batch']]);
    // inventaris: alokasi barang batch ikut terhapus sehingga stok gudang kembali seperti sebelum batch disiapkan
    foreach (['absensi', 'rundown', 'materi', 'dokumentasi', 'laporan', 'skl', 'absen_narasumber', 'hari',
      'inv_peserta', 'inv_transaksi', 'inv_batch', 'inv_mutasi', 'inv_ba'] as $t) {
      q("DELETE FROM `$t` WHERE id_batch = ?", [$b['id_batch']]);
    }
    q('DELETE FROM batch WHERE id_batch = ?', [$b['id_batch']]);
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
  // File batch: storage/<folder> (foto absen, tanda tangan, laporan) dan uploads/<folder> (materi, dokumentasi).
  if ($b['folder'] !== '' && !row('SELECT id_batch FROM batch WHERE folder = ?', [$b['folder']])) {
    foreach ([STORAGE, UPLOADS] as $root) hapus_folder($root . '/' . folder_aman($b['folder']));
  }
  log_($user, 'batch_hapus', $b['kode_batch'] . ' — ' . $b['nama_pelatihan']);
  return true;
}

/** Ringkasan isi batch sumber untuk dialog duplikat. */
function duplikatInfo(array $data, array $user): array {
  $b = get_batch((string)($data['id_batch'] ?? ''));
  $file = rows("SELECT file_id FROM materi WHERE id_batch = ? AND file_id <> ''", [$b['id_batch']]);
  $byte = array_sum(array_map(fn($m) => is_file(ROOT . '/' . $m['file_id']) ? filesize(ROOT . '/' . $m['file_id']) : 0, $file));
  return ['hari' => count(hari_batch($b['id_batch'])),
    'rundown' => (int)row('SELECT COUNT(*) AS n FROM rundown WHERE id_batch = ?', [$b['id_batch']])['n'],
    'pemateri' => (int)row("SELECT COUNT(*) AS n FROM rundown WHERE id_batch = ? AND pemateri <> ''", [$b['id_batch']])['n'],
    'materi' => (int)row('SELECT COUNT(*) AS n FROM materi WHERE id_batch = ?', [$b['id_batch']])['n'],
    'materi_mb' => round($byte / 1048576, 1)];
}

/**
 * Duplikat batch: membuat batch baru (kode, tanggal, skema, program, PIC baru) lalu menyalin jam sesi,
 * data laporan resmi (tanpa nomor permohonan, jumlah pendaftar/lulus & kode SKL), rundown (sebagai draft,
 * nama narasumber bisa dikosongkan) dan materi (file ikut disalin ke folder batch baru) per Hari ke-N.
 * TIDAK disalin: peserta, absensi, dokumentasi, laporan, SKL, absensi narasumber, lokasi.
 */
function batchDuplikat(array $data, array $user): array {
  $src = get_batch((string)($data['id_batch'] ?? ''));
  $opsi = $data['opsi'] ?? [];
  $d = $data['batch'] ?? [];
  foreach (['sesi1_mulai', 'sesi1_selesai', 'sesi2_mulai', 'sesi2_selesai'] as $k) $d[$k] = $src[$k];
  $baru = batchBuat(['batch' => $d], $user);
  try {
    $b = get_batch($baru['id_batch']);
    $info = info_batch($src);
    foreach (['nomor_permohonan', 'jumlah_pendaftar', 'jumlah_lulus', 'kode_skl'] as $k) $info[$k] = '';
    $b = update('batch', $b, ['info_laporan' => json_encode($info, JSON_UNESCAPED_UNICODE)]);
    $hariSrc = hari_batch($src['id_batch']);
    $hariBaru = hari_batch($b['id_batch']);
    $n = ['rundown' => 0, 'materi' => 0, 'hari_dilewati' => max(0, count($hariSrc) - count($hariBaru))];
    foreach ($hariSrc as $i => $hs) {
      $hb = $hariBaru[$i] ?? null;
      if (!$hb) break; // batch baru lebih sedikit harinya
      if (!empty($opsi['rundown'])) {
        foreach (rundown_hari($hs['id_hari']) as $r) {
          $kosong = !empty($opsi['kosongkan_pemateri']);
          insert('rundown', ['id_rundown' => new_id('r'), 'id_batch' => $b['id_batch'], 'id_hari' => $hb['id_hari'], 'urutan' => $r['urutan'],
            'jam_mulai' => $r['jam_mulai'], 'jam_selesai' => $r['jam_selesai'], 'kegiatan' => $r['kegiatan'],
            'pemateri' => $kosong ? '' : $r['pemateri'], 'instansi' => $kosong ? '' : $r['instansi'], 'keterangan' => $r['keterangan'], 'status' => 'draft']);
          $n['rundown']++;
        }
      }
      if (!empty($opsi['materi'])) {
        foreach (materi_hari($hs['id_hari']) as $m) {
          $fileId = '';
          if ($m['file_id'] !== '') {
            $f = ROOT . '/' . $m['file_id'];
            if (str_contains($m['file_id'], '..') || !is_file($f)) continue; // file sumber sudah tidak ada
            $fileId = simpan_bytes(folder_konten($b, 'materi', $hb), (string)file_get_contents($f), $m['judul'] . '.' . $m['tipe_file']);
          }
          insert('materi', ['id_materi' => new_id('m'), 'id_batch' => $b['id_batch'], 'id_hari' => $hb['id_hari'], 'judul' => $m['judul'],
            'kategori' => $m['kategori'], 'file_id' => $fileId, 'tipe_file' => $m['tipe_file'], 'link' => $m['link'],
            'tampil_mulai' => $m['tampil_mulai'], 'diupload_oleh' => $user['nama'], 'diupload_pada' => now_()['stamp']]);
          $n['materi']++;
        }
      }
    }
    // Daftar barang inventaris ikut disalin; jumlah disiapkan 0 (stok gudang baru terpotong saat PIC mengisi jumlah),
    // jumlah batch sumber disimpan sebagai saran.
    foreach (rows('SELECT * FROM inv_batch WHERE id_batch = ?', [$src['id_batch']]) as $ib) {
      $saran = $ib['rincian'] !== '' ? implode(', ', array_map(fn($u, $j) => "$u: $j", array_keys((array)json_decode($ib['rincian'], true)), (array)json_decode($ib['rincian'], true))) : $ib['jumlah'];
      insert('inv_batch', ['id_ib' => new_id('j'), 'id_batch' => $b['id_batch'], 'id_barang' => $ib['id_barang'], 'mode' => $ib['mode'], 'jumlah' => 0,
        'rincian' => '', 'saran' => (string)$saran, 'catatan' => $ib['catatan'], 'diubah_oleh' => $user['nama'], 'diubah_pada' => now_()['stamp']]);
      $n['inventaris'] = ($n['inventaris'] ?? 0) + 1;
    }
  } catch (Throwable $e) {
    // Gagal di tengah jalan: batch baru dihapus lagi supaya tidak setengah jadi.
    batchHapus(['id_batch' => $baru['id_batch'], 'konfirmasi' => $baru['kode_batch']], $user);
    throw $e;
  }
  log_($user, 'batch_duplikat', $src['kode_batch'] . ' → ' . $b['kode_batch'] . " ({$n['rundown']} rundown, {$n['materi']} materi)");
  return ['batch' => batch_view(get_batch($b['id_batch'])), 'hasil' => $n];
}

function hapus_folder(string $dir): void {
  if (!is_dir($dir) || is_link($dir)) return;
  foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
    $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
  }
  @rmdir($dir);
}

/** Menyamakan daftar hari dengan rentang tanggal batch. Hari yang sudah ada absensinya tidak dihapus. */
function sync_hari(array $batch): void {
  $want = $batch['tanggal_custom'] !== '' ? explode(',', $batch['tanggal_custom']) : []; // tanggal pilihan (tidak harus berurutan)
  $d = new DateTime($batch['tgl_mulai']);
  $end = new DateTime($batch['tanggal_custom'] !== '' ? '1970-01-01' : $batch['tgl_selesai']);
  for (; $d <= $end; $d->modify('+1 day')) {
    $dow = (int)$d->format('w');
    if ($batch['lewati_weekend'] === 'ya' && ($dow === 0 || $dow === 6)) continue;
    $want[] = $d->format('Y-m-d');
  }
  if (count($want) > 90) throw new LmsError('Rentang tanggal terlalu panjang (maks. 90 hari).');
  foreach (hari_batch($batch['id_batch']) as $h) {
    if (!in_array($h['tanggal'], $want, true) && !row('SELECT id_absen FROM absensi WHERE id_hari = ? LIMIT 1', [$h['id_hari']])) hapus('hari', $h);
  }
  $ada = array_column(hari_batch($batch['id_batch']), 'tanggal');
  foreach ($want as $t) if (!in_array($t, $ada, true)) insert('hari', ['id_hari' => new_id('h'), 'id_batch' => $batch['id_batch'], 'tanggal' => $t]);
  foreach (hari_batch($batch['id_batch']) as $i => $h) {
    if ($h['hari_ke'] !== (string)($i + 1)) update('hari', $h, ['hari_ke' => $i + 1]);
  }
}

function hari_batch(string $idBatch): array {
  return rows('SELECT * FROM hari WHERE id_batch = ? ORDER BY tanggal', [$idBatch]);
}

function get_hari(string $idHari): array {
  $h = row('SELECT * FROM hari WHERE id_hari = ?', [$idHari]);
  if (!$h) throw new LmsError('Hari pelatihan tidak ditemukan.');
  return $h;
}

/** Lokasi yang berlaku untuk suatu hari: lokasi khusus hari itu, atau lokasi default batch. */
/**
 * Aturan lokasi absen: batch.lokasi_bebas 'ya' = bebas dari mana saja (online), selain itu wajib di lokasi.
 * hari.lokasi_bebas: '' = ikut batch, 'ya' = hari itu bebas (mis. hari online pada batch blended), 'tidak' = wajib di lokasi.
 */
function lokasi_bebas(array $batch, ?array $hari): bool {
  $aturan = $hari['lokasi_bebas'] ?? '';
  return $aturan === 'ya' || ($aturan !== 'tidak' && ($batch['lokasi_bebas'] ?? '') === 'ya');
}

function lokasi_hari(array $batch, ?array $hari): array {
  $khusus = $hari && $hari['lat'] !== '';
  $src = $khusus ? $hari : $batch;
  return [
    'bebas' => lokasi_bebas($batch, $hari), 'aturan_hari' => $hari['lokasi_bebas'] ?? '',
    'khusus' => $khusus,
    'nama' => $src['lokasi_nama'] !== '' ? $src['lokasi_nama'] : ($khusus ? 'Lokasi khusus' : 'Lokasi pelatihan'),
    'lat' => $src['lat'] === '' ? null : (float)$src['lat'],
    'lng' => $src['lng'] === '' ? null : (float)$src['lng'],
    'radius' => (float)($src['radius_meter'] !== '' ? $src['radius_meter'] : DEFAULT_RADIUS),
  ];
}

function hariList(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  return array_map(fn($h) => ['id_hari' => $h['id_hari'], 'tanggal' => $h['tanggal'], 'hari_ke' => $h['hari_ke'],
    'lokasi' => lokasi_hari($b, $h)], hari_batch($b['id_batch']));
}

function hariSetLokasi(array $data, array $user): array {
  $h = get_hari((string)($data['id_hari'] ?? ''));
  $b = batch_for_pic($user, $h['id_batch']);
  validasi_lokasi($data, false);
  $h = update('hari', $h, ['lokasi_nama' => trim((string)($data['lokasi_nama'] ?? '')), 'lat' => (float)$data['lat'], 'lng' => (float)$data['lng'],
    'radius_meter' => (float)(($data['radius_meter'] ?? '') !== '' ? $data['radius_meter'] : DEFAULT_RADIUS), 'diubah_oleh' => $user['nama']]);
  log_($user, 'lokasi_hari', "{$b['kode_batch']} hari {$h['hari_ke']} → {$h['lokasi_nama']} ({$h['lat']}, {$h['lng']}, r={$h['radius_meter']})");
  return lokasi_hari($b, $h);
}

/** Aturan lokasi satu hari: '' ikut batch, 'ya' bebas (online), 'tidak' wajib di lokasi. */
function hariSetAturan(array $data, array $user): array {
  $h = get_hari((string)($data['id_hari'] ?? ''));
  $b = batch_for_pic($user, $h['id_batch']);
  $aturan = (string)($data['aturan'] ?? '');
  if (!in_array($aturan, ['', 'ya', 'tidak'], true)) throw new LmsError('Aturan lokasi tidak valid.');
  $h = update('hari', $h, ['lokasi_bebas' => $aturan, 'diubah_oleh' => $user['nama']]);
  log_($user, 'aturan_lokasi_hari', "{$b['kode_batch']} hari {$h['hari_ke']} → " . ($aturan === '' ? 'ikut batch' : $aturan));
  return lokasi_hari($b, $h);
}

function hariResetLokasi(array $data, array $user): array {
  $h = get_hari((string)($data['id_hari'] ?? ''));
  $b = batch_for_pic($user, $h['id_batch']);
  $h = update('hari', $h, ['lokasi_nama' => '', 'lat' => '', 'lng' => '', 'radius_meter' => '', 'diubah_oleh' => $user['nama']]);
  log_($user, 'lokasi_hari_reset', "{$b['kode_batch']} hari {$h['hari_ke']}");
  return lokasi_hari($b, $h);
}

/** Koordinat dari teks "lat, lng" atau link Google Maps (termasuk link pendek maps.app.goo.gl). */
function lokasiDariTeks(array $data): array {
  $teks = trim((string)($data['teks'] ?? ''));
  if (preg_match('#^https?://(maps\.app\.goo\.gl|goo\.gl)/#', $teks)) {
    for ($i = 0; $i < 5; $i++) {
      $res = http_get($teks, false);
      if (!preg_match('/^location:\s*(\S+)/mi', $res['body'], $m)) break;
      $teks = urldecode($m[1]);
    }
  }
  $pola = ['/@(-?\d+\.\d+),(-?\d+\.\d+)/', '/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/',
    '/[?&](?:q|query|ll|center|destination)=(-?\d+\.\d+),\s*(-?\d+\.\d+)/', '/^(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)$/',
    '/(-?\d{1,2}\.\d{3,})\s*,\s*(-?\d{1,3}\.\d{3,})/'];
  foreach ($pola as $p) if (preg_match($p, $teks, $m)) return ['lat' => (float)$m[1], 'lng' => (float)$m[2]];
  throw new LmsError('Koordinat tidak ditemukan. Tempel link Google Maps atau koordinat seperti -6.2088, 106.8456');
}

// ---------------------------------------------------------------- Peserta

function peserta_batch(string $idBatch, bool $termasukNonaktif): array {
  $list = rows("SELECT * FROM pengguna WHERE role = 'peserta' AND id_batch = ?" . ($termasukNonaktif ? '' : " AND aktif <> 'tidak'"), [$idBatch]);
  usort($list, fn($a, $b) => (int)$a['no_absen'] <=> (int)$b['no_absen']);
  return $list;
}

function pesertaList(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  return array_map(fn($u) => ['id_user' => $u['id_user'], 'no_absen' => $u['no_absen'], 'nama' => $u['nama'], 'kode_login' => $u['kode_login'],
    'instansi' => $u['instansi'], 'no_hp' => $u['no_hp'], 'tempat_lahir' => $u['tempat_lahir'], 'tgl_lahir' => $u['tgl_lahir'], 'hp_terhubung' => $u['device_id'] !== '',
    'perangkat' => array_map('perangkat_view', perangkat_user($u)), 'aktif' => $u['aktif'] !== 'tidak'],
    peserta_batch($b['id_batch'], true));
}

function nama_depan(string $nama): string {
  $w = preg_replace('/[^A-Za-z0-9]/', '', preg_split('/\s+/', trim($nama))[0] ?? '');
  return $w !== '' ? ucfirst(strtolower($w)) : 'Peserta';
}

/**
 * Menambah peserta dari teks: satu peserta per baris. Kolom dipisah Tab (copy dari Excel) atau titik koma:
 * Nama ; Instansi ; No HP. Kode login otomatis: nomor absen 3 digit + nama depan, mis. 001-Kikis.
 */
function pesertaTambah(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string)($data['teks'] ?? '')))));
  if (!$lines) throw new LmsError('Isi nama peserta, satu nama per baris.');
  $pdo = db();
  $pdo->beginTransaction();
  try {
    $no = (int)(row("SELECT MAX(CAST(no_absen AS UNSIGNED)) AS n FROM pengguna WHERE role = 'peserta' AND id_batch = ?", [$b['id_batch']])['n'] ?? 0);
    $added = [];
    foreach ($lines as $line) {
      $cols = array_map('trim', preg_split('/\t|;/', $line));
      $nama = preg_replace('/\s+/', ' ', $cols[0]);
      if ($nama === '' || preg_match('/^(no\.?|nama( lengkap)?)$/i', $nama)) continue; // lewati baris judul
      // Kolom 4–5 (opsional): tempat lahir · tanggal lahir, atau satu kolom "Sleman, 12-01-1990".
      $tempat = $cols[3] ?? '';
      $tgl = $cols[4] ?? '';
      if ($tgl === '' && preg_match('/^(.+?),\s*(\d.*)$/', $tempat, $x)) [$tempat, $tgl] = [trim($x[1]), $x[2]];
      $tgl = parse_tgl_lahir($tgl, $nama);
      $no++;
      $u = insert('pengguna', ['id_user' => new_id('u'), 'id_batch' => $b['id_batch'], 'no_absen' => $no, 'nama' => $nama, 'role' => 'peserta',
        'kode_login' => sprintf('%03d', $no) . '-' . nama_depan($nama), 'instansi' => $cols[1] ?? '', 'no_hp' => $cols[2] ?? '',
        'tempat_lahir' => $tempat, 'tgl_lahir' => $tgl, 'aktif' => 'ya', 'dibuat_pada' => now_()['stamp']]);
      $added[] = ['no_absen' => $u['no_absen'], 'nama' => $u['nama'], 'kode_login' => $u['kode_login']];
    }
    $pdo->commit();
  } catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
  }
  log_($user, 'peserta_tambah', $b['kode_batch'] . ': ' . count($added) . ' peserta');
  return $added;
}

function peserta_for_pic(array $user, string $idUser): array {
  $u = row("SELECT * FROM pengguna WHERE id_user = ? AND role = 'peserta'", [$idUser]);
  if (!$u) throw new LmsError('Peserta tidak ditemukan.');
  batch_for_pic($user, $u['id_batch']);
  return $u;
}

/** Mengubah data peserta. Kode login tetap, agar kartu kode yang sudah dibagikan tetap berlaku. */
function pesertaUbah(array $data, array $user): bool {
  $u = peserta_for_pic($user, (string)($data['id_user'] ?? ''));
  $nama = preg_replace('/\s+/', ' ', trim((string)($data['nama'] ?? '')));
  if ($nama === '') throw new LmsError('Nama wajib diisi.');
  $c = ['nama' => $nama, 'instansi' => $data['instansi'] ?? '', 'no_hp' => $data['no_hp'] ?? ''];
  if (array_key_exists('tempat_lahir', $data)) $c['tempat_lahir'] = mb_substr(trim((string)$data['tempat_lahir']), 0, 100);
  if (array_key_exists('tgl_lahir', $data)) $c['tgl_lahir'] = parse_tgl_lahir((string)$data['tgl_lahir'], $nama);
  update('pengguna', $u, $c);
  log_($user, 'peserta_ubah', $u['kode_login']);
  return true;
}

function pesertaResetDevice(array $data, array $user): bool {
  $u = peserta_for_pic($user, (string)($data['id_user'] ?? ''));
  update('pengguna', $u, ['device_id' => '']);
  q('DELETE FROM perangkat WHERE id_user = ?', [$u['id_user']]);
  q('DELETE FROM token WHERE id_user = ?', [$u['id_user']]);
  log_($user, 'peserta_reset_hp', $u['kode_login'] . ' ' . $u['nama']);
  return true;
}

function pesertaAktif(array $data, array $user): bool {
  $u = peserta_for_pic($user, (string)($data['id_user'] ?? ''));
  update('pengguna', $u, ['aktif' => !empty($data['aktif']) ? 'ya' : 'tidak']);
  if (empty($data['aktif'])) q('DELETE FROM token WHERE id_user = ?', [$u['id_user']]);
  log_($user, !empty($data['aktif']) ? 'peserta_aktifkan' : 'peserta_nonaktifkan', $u['kode_login'] . ' ' . $u['nama']);
  return true;
}
