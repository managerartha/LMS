<?php
/**
 * Dashboard PIC/Admin: ringkasan semua batch yang ditangani (kecuali arsip) dan pengingat tugas,
 * mis. dokumentasi kemarin belum diupload, materi/rundown hari ini belum ada, lokasi belum diatur.
 */

/** Satu pengingat. level: 'bad' (segera), 'warn' (perlu dikerjakan), 'info'. tab = tab tujuan tombol "Buka". */
function pengingat_(string $level, string $teks, string $tab, string $idHari = ''): array {
  return ['level' => $level, 'teks' => $teks, 'tab' => $tab, 'id_hari' => $idHari];
}

function label_hari(array $h): string {
  return 'Hari ke-' . $h['hari_ke'] . ' (' . tgl_id($h['tanggal']) . ')';
}

function dasborPic(array $data, array $user): array {
  $now = now_();
  $out = [];
  $list = array_values(array_filter(rows("SELECT * FROM batch WHERE status <> 'arsip' ORDER BY tgl_mulai"), fn($b) => can_access_batch($user, $b)));
  foreach ($list as $b) $out[] = dasbor_batch($b, $now, $user);
  $urutFase = ['berjalan' => 0, 'akan' => 1, 'selesai' => 2];
  usort($out, fn($a, $b) => $urutFase[$a['fase']] <=> $urutFase[$b['fase']] ?: strcmp($a['tgl_mulai'], $b['tgl_mulai']));
  return ['tanggal' => $now['date'], 'jam' => $now['time'], 'batches' => $out];
}

function dasbor_batch(array $b, array $now, array $user): array {
  $today = $now['date'];
  $hari = hari_batch($b['id_batch']);
  $peserta = (int)row("SELECT COUNT(*) AS n FROM pengguna WHERE role = 'peserta' AND id_batch = ? AND aktif <> 'tidak'", [$b['id_batch']])['n'];
  $fase = $b['status'] !== 'aktif' || $b['tgl_selesai'] < $today ? 'selesai' : ($b['tgl_mulai'] > $today ? 'akan' : 'berjalan');
  $hIni = $kemarin = $besok = null;
  foreach ($hari as $h) {
    if ($h['tanggal'] === $today) $hIni = $h;
    elseif ($h['tanggal'] < $today) $kemarin = $h;          // hari pelatihan terakhir sebelum hari ini
    elseif (!$besok) $besok = $h;                            // hari pelatihan berikutnya
  }
  $p = [];

  // Ringkasan absensi hari ini
  $absenHariIni = null;
  if ($hIni && $b['status'] === 'aktif') {
    $hitung = [];
    foreach (rows('SELECT sesi, status, COUNT(*) AS n FROM absensi WHERE id_hari = ? GROUP BY sesi, status', [$hIni['id_hari']]) as $r) {
      $hitung[(int)$r['sesi']][$r['status']] = (int)$r['n'];
    }
    $absenHariIni = ['hari_ke' => $hIni['hari_ke'], 'total_hari' => count($hari), 'sesi' => array_map(function ($s) use ($b, $hIni, $now, $hitung, $peserta) {
      $c = $hitung[$s] ?? [];
      $hadir = $c['hadir'] ?? 0;
      $izin = ($c['izin'] ?? 0) + ($c['sakit'] ?? 0);
      $tercatat = array_sum($c);
      $state = state_sesi($b, $hIni, $s, $now);
      return array_merge(['sesi' => $s, 'state' => $state, 'hadir' => $hadir, 'izin_sakit' => $izin,
        'belum' => $state === 'belum_dibuka' ? 0 : max(0, $peserta - $tercatat)], jam_sesi($b, $s));
    }, SESI)];
  }

  if ($b['status'] === 'aktif') {
    // Persiapan sebelum mulai
    if ($fase === 'akan' && $peserta === 0 && (strtotime($b['tgl_mulai']) - strtotime($today)) <= 7 * 86400) {
      $p[] = pengingat_('warn', 'Belum ada peserta. Pelatihan mulai ' . tgl_id($b['tgl_mulai']) . '.', 'peserta');
    }
    // Hari ini & hari pelatihan berikutnya: lokasi, rundown, materi
    foreach ([[$hIni, true], [$besok, false]] as [$h, $isToday]) {
      if (!$h) continue;
      if (!$isToday && (strtotime($h['tanggal']) - strtotime($today)) > 3 * 86400) continue; // hanya H-3
      $kapan = $isToday ? 'hari ini' : label_hari($h);
      if (lokasi_hari($b, $h)['lat'] === null) {
        $p[] = pengingat_($isToday ? 'bad' : 'warn', 'Lokasi absen ' . $kapan . ' belum diatur — peserta tidak bisa absen.', 'lokasi', $h['id_hari']);
      }
      if (!row("SELECT id_rundown FROM rundown WHERE id_hari = ? AND status = 'terbit' LIMIT 1", [$h['id_hari']])) {
        $draft = (bool)row('SELECT id_rundown FROM rundown WHERE id_hari = ? LIMIT 1', [$h['id_hari']]);
        $p[] = pengingat_($isToday ? 'warn' : 'info', 'Rundown ' . $kapan . ($draft ? ' masih draft, belum diterbitkan.' : ' belum dibuat.'), 'rundown', $h['id_hari']);
      }
      if (!row('SELECT id_materi FROM materi WHERE id_hari = ? LIMIT 1', [$h['id_hari']])) {
        $p[] = pengingat_($isToday ? 'warn' : 'info', 'Materi ' . $kapan . ' belum diupload.', 'materi', $h['id_hari']);
      }
    }
    // Dokumentasi hari pelatihan sebelumnya (dan hari ini setelah sesi terakhir selesai)
    if ($fase === 'berjalan' && $kemarin && !row('SELECT id_dok FROM dokumentasi WHERE id_hari = ? LIMIT 1', [$kemarin['id_hari']])) {
      $p[] = pengingat_('warn', 'Dokumentasi ' . label_hari($kemarin) . ' belum diupload.', 'dok', $kemarin['id_hari']);
    }
    if ($hIni && $now['time'] >= $b['sesi2_selesai'] && !row('SELECT id_dok FROM dokumentasi WHERE id_hari = ? LIMIT 1', [$hIni['id_hari']])) {
      $p[] = pengingat_('info', 'Dokumentasi hari ini belum diupload.', 'dok', $hIni['id_hari']);
    }
    // Sesi yang sedang berjalan
    foreach ($absenHariIni['sesi'] ?? [] as $s) {
      if ($s['state'] === 'buka' && $s['belum'] > 0) {
        $p[] = pengingat_('info', $s['belum'] . ' peserta belum absen Sesi ' . $s['sesi'] . ' (sampai ' . $s['selesai'] . ').', 'monitor', $hIni['id_hari']);
      }
    }
  }

  // Setelah pelatihan berakhir: laporan, SKL, status/arsip
  if ($fase === 'selesai') {
    $tanpaDok = array_values(array_filter($hari, fn($h) => $h['tanggal'] <= $today
      && !row('SELECT id_dok FROM dokumentasi WHERE id_hari = ? LIMIT 1', [$h['id_hari']])));
    if ($tanpaDok) {
      $p[] = pengingat_('warn', count($tanpaDok) === 1 ? 'Dokumentasi ' . label_hari($tanpaDok[0]) . ' belum diupload.'
        : 'Dokumentasi belum diupload untuk ' . count($tanpaDok) . ' hari pelatihan, mulai Hari ke-' . $tanpaDok[0]['hari_ke'] . '.', 'dok', $tanpaDok[0]['id_hari']);
    }
    if ($peserta && !row("SELECT id_laporan FROM laporan WHERE id_batch = ? AND jenis = 'resmi' LIMIT 1", [$b['id_batch']])) {
      $p[] = pengingat_('warn', 'Laporan resmi belum dibuat.', 'laporan');
    }
    $sklTerbit = (int)row("SELECT COUNT(*) AS n FROM skl s JOIN pengguna u ON u.id_user = s.id_user
      WHERE s.id_batch = ? AND s.status = 'terbit' AND u.aktif <> 'tidak'", [$b['id_batch']])['n'];
    if ($peserta > $sklTerbit) {
      $p[] = pengingat_('info', ($peserta - $sklTerbit) . ' dari ' . $peserta . ' peserta belum menerima SKL.', 'skl');
    }
    if ($b['status'] === 'aktif') {
      $p[] = $user['role'] === 'admin'
        ? pengingat_('info', 'Pelatihan sudah lewat tanggal selesai. Setelah laporan & SKL beres, ubah status menjadi Selesai lalu arsipkan.', 'batch')
        : pengingat_('info', 'Pelatihan sudah lewat tanggal selesai. Setelah laporan & SKL beres, minta Admin menutup & mengarsipkan batch.', 'laporan');
    } elseif ($user['role'] === 'admin') {
      $p[] = pengingat_('info', 'Batch sudah selesai. Arsipkan jika laporan & SKL sudah beres.', 'batch');
    }
  }

  $lv = ['bad' => 0, 'warn' => 1, 'info' => 2];
  usort($p, fn($x, $y) => $lv[$x['level']] <=> $lv[$y['level']]);
  return ['id_batch' => $b['id_batch'], 'kode_batch' => $b['kode_batch'], 'nama_pelatihan' => $b['nama_pelatihan'], 'status' => $b['status'],
    'tgl_mulai' => $b['tgl_mulai'], 'tgl_selesai' => $b['tgl_selesai'], 'fase' => $fase, 'peserta' => $peserta, 'total_hari' => count($hari),
    'hari_ini' => $absenHariIni, 'berikutnya' => $besok ? $besok['tanggal'] : '', 'pengingat' => $p];
}
