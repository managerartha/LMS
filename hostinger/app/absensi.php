<?php
/** Absensi: absen peserta, riwayat, monitor PIC, ubah status izin/sakit/alpa. */

const SESI = [1, 2];

function jam_sesi(array $batch, int $sesi): array {
  return ['mulai' => $batch["sesi{$sesi}_mulai"], 'selesai' => $batch["sesi{$sesi}_selesai"]];
}

/** 'belum_dibuka' | 'buka' | 'tutup' menurut jam server. */
function state_sesi(array $batch, array $hari, int $sesi, array $now): string {
  $j = jam_sesi($batch, $sesi);
  if ($hari['tanggal'] > $now['date']) return 'belum_dibuka';
  if ($hari['tanggal'] < $now['date']) return 'tutup';
  if ($now['time'] < $j['mulai']) return 'belum_dibuka';
  if ($now['time'] >= $j['selesai']) return 'tutup';
  return 'buka';
}

function absen_of(string $idHari, string $idUser, int $sesi): ?array {
  return row('SELECT * FROM absensi WHERE id_hari = ? AND id_user = ? AND sesi = ?', [$idHari, $idUser, (string)$sesi]);
}

/** Tanpa catatan: 'belum' selama sesi buka, 'alpa' otomatis setelah sesi tutup, '' jika belum dibuka. */
function status_sesi(?array $rec, string $state): string {
  if ($rec) return $rec['status'];
  if ($state === 'tutup') return 'alpa';
  if ($state === 'buka') return 'belum';
  return '';
}

function absen_view(?array $rec): ?array {
  return $rec ? ['id_absen' => $rec['id_absen'], 'waktu' => $rec['waktu_server'], 'status' => $rec['status'],
    'keterangan' => $rec['keterangan'], 'jarak_meter' => $rec['jarak_meter'], 'ada_foto' => $rec['foto_id'] !== ''] : null;
}

function hari_ini(array $batch, array $now): ?array {
  return row('SELECT * FROM hari WHERE id_batch = ? AND tanggal = ?', [$batch['id_batch'], $now['date']]);
}

function wajib_peserta(array $user): void {
  if ($user['role'] !== 'peserta') throw new LmsError('Menu ini khusus peserta.');
}

function statusHariIni(array $data, array $user): array {
  wajib_peserta($user);
  $batch = get_batch($user['id_batch']);
  $now = now_();
  $hari = hari_ini($batch, $now);
  $semua = hari_batch($batch['id_batch']);
  $berikutnya = null;
  foreach ($semua as $h) if ($h['tanggal'] > $now['date']) { $berikutnya = $h['tanggal']; break; }
  $out = [
    'server_now' => lms_ms(), 'tanggal' => $now['date'],
    'batch' => ['nama_pelatihan' => $batch['nama_pelatihan'], 'kode_batch' => $batch['kode_batch'], 'status' => $batch['status'],
      'tgl_mulai' => $batch['tgl_mulai'], 'tgl_selesai' => $batch['tgl_selesai'], 'lokasi_nama' => $batch['lokasi_nama']],
    'hari' => null, 'total_hari' => count($semua),
    'hari_selesai' => count(array_filter($semua, fn($h) => $h['tanggal'] < $now['date'])),
    'berikutnya' => $berikutnya, 'rekap' => rekap_peserta($batch, $semua, $user, $now),
  ];
  if (!$hari) return $out;
  $out['hari'] = [
    'id_hari' => $hari['id_hari'], 'hari_ke' => $hari['hari_ke'], 'total_hari' => count($semua), 'lokasi' => lokasi_hari($batch, $hari),
    'sesi' => array_map(function ($s) use ($batch, $hari, $now, $user) {
      $state = state_sesi($batch, $hari, $s, $now);
      $rec = absen_of($hari['id_hari'], $user['id_user'], $s);
      return array_merge(['sesi' => $s, 'state' => $state, 'status' => status_sesi($rec, $state), 'absen' => absen_view($rec)], jam_sesi($batch, $s));
    }, SESI),
  ];
  return $out;
}

/** Jumlah sesi hadir dibanding sesi yang sudah berjalan (sesi yang masih dibuka & belum absen tidak dihitung). */
function rekap_peserta(array $batch, array $hariList, array $user, array $now): array {
  $r = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'total' => 0];
  foreach ($hariList as $h) {
    if ($h['tanggal'] > $now['date']) continue;
    foreach (SESI as $s) {
      $st = status_sesi(absen_of($h['id_hari'], $user['id_user'], $s), state_sesi($batch, $h, $s, $now));
      if (!isset($r[$st])) continue;
      $r[$st]++;
      $r['total']++;
    }
  }
  return $r;
}

function absen(array $data, array $user): array {
  wajib_peserta($user);
  if ($user['setuju_privasi'] === '') throw new LmsError('Setujui pernyataan privasi terlebih dahulu.');
  $sesi = (int)($data['sesi'] ?? 0);
  if (!in_array($sesi, SESI, true)) throw new LmsError('Sesi tidak valid.');
  $batch = get_batch($user['id_batch']);
  if ($batch['status'] !== 'aktif') throw new LmsError('Batch sudah tidak aktif.');

  // 1. Cek jadwal & jam (pakai jam server).
  $now = now_();
  $hari = hari_ini($batch, $now);
  if (!$hari) throw new LmsError('Hari ini tidak ada jadwal pelatihan.');
  $j = jam_sesi($batch, $sesi);
  if (state_sesi($batch, $hari, $sesi, $now) !== 'buka') {
    throw new LmsError("Absen Sesi $sesi hanya bisa dilakukan pukul {$j['mulai']}–{$j['selesai']} WIB.");
  }

  // 2. Cek lokasi.
  $lok = lokasi_hari($batch, $hari);
  if ($lok['lat'] === null) throw new LmsError('Lokasi pelatihan hari ini belum diatur PIC. Hubungi PIC.');
  if (!isset($data['lat']) || !is_numeric($data['lat']) || !is_numeric($data['lng'] ?? null)) {
    throw new LmsError('Lokasi GPS tidak terbaca. Aktifkan GPS/lokasi lalu coba lagi.');
  }
  $lat = (float)$data['lat'];
  $lng = (float)$data['lng'];
  $jarak = distance_m($lat, $lng, $lok['lat'], $lok['lng']);
  if ($jarak > $lok['radius']) {
    throw new LmsError("Anda berada ± $jarak m dari {$lok['nama']}. Absen hanya bisa dalam radius " . (int)$lok['radius'] . ' m.');
  }
  $ada = absen_of($hari['id_hari'], $user['id_user'], $sesi);
  if ($ada && $ada['status'] === 'hadir') throw new LmsError("Anda sudah absen Sesi $sesi hari ini.");

  // 3. Simpan foto & tanda tangan (folder privat).
  $dir = 'storage/' . $batch['folder'] . '/absensi/hari-' . $hari['hari_ke'];
  $base = 'H' . $hari['hari_ke'] . '_S' . $sesi . '_' . $user['kode_login'];
  $foto = simpan_data_url($dir, (string)($data['foto'] ?? ''), $base . '_foto.jpg');
  $ttd = simpan_data_url($dir, (string)($data['ttd'] ?? ''), $base . '_ttd.png');
  $alamat = alamat_($lat, $lng);

  // 4. Catat. Kunci unik (hari, peserta, sesi) di database mencegah absen dobel.
  $waktu = now_()['stamp'];
  $rec = ['waktu_server' => $waktu, 'foto_id' => $foto, 'ttd_id' => $ttd, 'lat' => $lat, 'lng' => $lng,
    'akurasi_meter' => (int)round((float)($data['akurasi'] ?? 0)), 'jarak_meter' => $jarak, 'status' => 'hadir',
    'keterangan' => '', 'diubah_oleh' => '', 'diubah_pada' => '', 'alamat' => $alamat];
  try {
    if ($ada) {
      update('absensi', $ada, $rec); // sebelumnya ditandai izin/sakit/alpa oleh PIC, peserta ternyata hadir
    } else {
      insert('absensi', array_merge(['id_absen' => new_id('a'), 'id_batch' => $batch['id_batch'], 'id_hari' => $hari['id_hari'],
        'id_user' => $user['id_user'], 'sesi' => $sesi], $rec));
    }
  } catch (PDOException $e) {
    hapus_file($foto);
    hapus_file($ttd);
    throw new LmsError("Anda sudah absen Sesi $sesi hari ini.");
  }
  return ['waktu' => $waktu, 'jarak_meter' => $jarak];
}

function riwayat(array $data, array $user): array {
  wajib_peserta($user);
  $batch = get_batch($user['id_batch']);
  $now = now_();
  return array_map(fn($h) => ['hari_ke' => $h['hari_ke'], 'tanggal' => $h['tanggal'], 'sesi' => array_map(function ($s) use ($h, $user, $batch, $now) {
    $rec = absen_of($h['id_hari'], $user['id_user'], $s);
    return ['sesi' => $s, 'status' => status_sesi($rec, state_sesi($batch, $h, $s, $now)),
      'waktu' => $rec['waktu_server'] ?? '', 'keterangan' => $rec['keterangan'] ?? ''];
  }, SESI)], hari_batch($batch['id_batch']));
}

// ---------------------------------------------------------------- PIC

/**
 * Rekap absensi satu hari untuk PIC. Bisa dipanggil dengan id_batch saja: hari dipilih otomatis
 * (hari ini, atau hari terdekat) dan daftar hari ikut dikirim, agar halaman PIC cukup satu permintaan.
 */
function monitor(array $data, array $user): array {
  $daftarHari = null;
  if (!empty($data['id_batch'])) {
    $b = batch_for_pic($user, (string)$data['id_batch']);
    $semua = hari_batch($b['id_batch']);
    $today = now_()['date'];
    $hari = null;
    foreach ($semua as $h) if ($h['id_hari'] === ($data['id_hari'] ?? null)) $hari = $h;
    if (!$hari) foreach ($semua as $h) if ($h['tanggal'] === $today) $hari = $h;
    if (!$hari) foreach ($semua as $h) if ($h['tanggal'] > $today) { $hari = $h; break; }
    if (!$hari && $semua) $hari = end($semua);
    if (!$hari) return ['hariList' => [], 'hari' => null];
    $daftarHari = hariList(['id_batch' => $b['id_batch']], $user);
  } else {
    $hari = get_hari((string)($data['id_hari'] ?? ''));
  }
  $batch = batch_for_pic($user, $hari['id_batch']);
  $now = now_();
  $states = array_map(fn($s) => state_sesi($batch, $hari, $s, $now), SESI);
  $rekap = array_map(fn() => ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'belum' => 0], SESI);
  $peserta = [];
  foreach (peserta_batch($batch['id_batch'], false) as $u) {
    $sesiList = [];
    foreach (SESI as $i => $s) {
      $rec = absen_of($hari['id_hari'], $u['id_user'], $s);
      $status = status_sesi($rec, $states[$i]);
      if (isset($rekap[$i][$status])) $rekap[$i][$status]++;
      $sesiList[] = array_merge(['sesi' => $s, 'status' => $status], absen_view($rec) ?? []);
    }
    $peserta[] = ['id_user' => $u['id_user'], 'no_absen' => $u['no_absen'], 'nama' => $u['nama'], 'kode_login' => $u['kode_login'], 'sesi' => $sesiList];
  }
  return [
    'server_now' => lms_ms(), 'hariList' => $daftarHari,
    'hari' => ['id_hari' => $hari['id_hari'], 'hari_ke' => $hari['hari_ke'], 'tanggal' => $hari['tanggal'], 'lokasi' => lokasi_hari($batch, $hari)],
    'sesi' => array_map(fn($i) => array_merge(['sesi' => SESI[$i], 'state' => $states[$i], 'rekap' => $rekap[$i]], jam_sesi($batch, SESI[$i])), array_keys(SESI)),
    'peserta' => $peserta,
  ];
}

/** PIC menandai Izin / Sakit / Alpa. Foto & tanda tangan (jika ada) tetap disimpan sebagai arsip. */
function absenSetStatus(array $data, array $user): bool {
  $hari = get_hari((string)($data['id_hari'] ?? ''));
  $batch = batch_for_pic($user, $hari['id_batch']);
  $u = peserta_for_pic($user, (string)($data['id_user'] ?? ''));
  if ($u['id_batch'] !== $batch['id_batch']) throw new LmsError('Peserta bukan anggota batch ini.');
  $sesi = (int)($data['sesi'] ?? 0);
  if (!in_array($sesi, SESI, true)) throw new LmsError('Sesi tidak valid.');
  $status = (string)($data['status'] ?? '');
  if (!in_array($status, ['izin', 'sakit', 'alpa', 'hadir'], true)) throw new LmsError('Status tidak valid.');
  $ada = absen_of($hari['id_hari'], $u['id_user'], $sesi);
  if ($status === 'hadir' && !($ada && $ada['foto_id'] !== '')) throw new LmsError('Status Hadir hanya dari absen peserta (foto + tanda tangan).');
  $changes = ['status' => $status, 'keterangan' => (string)($data['keterangan'] ?? ''), 'diubah_oleh' => $user['nama'], 'diubah_pada' => now_()['stamp']];
  $lama = $ada ? $ada['status'] : '(kosong)';
  if ($ada) {
    update('absensi', $ada, $changes);
  } else {
    insert('absensi', array_merge(['id_absen' => new_id('a'), 'id_batch' => $batch['id_batch'], 'id_hari' => $hari['id_hari'],
      'id_user' => $u['id_user'], 'sesi' => $sesi], $changes));
  }
  log_($user, 'absen_status', "{$u['nama']} hari {$hari['hari_ke']} sesi $sesi: $lama → $status" . (!empty($data['keterangan']) ? " ({$data['keterangan']})" : ''));
  return true;
}

function absenFoto(array $data, array $user): array {
  $rec = row('SELECT * FROM absensi WHERE id_absen = ?', [(string)($data['id_absen'] ?? '')]);
  if (!$rec) throw new LmsError('Data absen tidak ditemukan.');
  batch_for_pic($user, $rec['id_batch']);
  return ['foto' => file_data_url($rec['foto_id']), 'ttd' => file_data_url($rec['ttd_id']), 'waktu' => $rec['waktu_server'],
    'lat' => $rec['lat'], 'lng' => $rec['lng'], 'jarak_meter' => $rec['jarak_meter'], 'akurasi_meter' => $rec['akurasi_meter']];
}
