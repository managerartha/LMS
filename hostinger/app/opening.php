<?php
/**
 * Data untuk halaman Opening Pelatihan (modul terpisah di /opening/).
 * Halaman opening dibuka dari LMS (tombol "Buka Opening") dan memakai login PIC/Admin yang sama (token di browser).
 */

/** Periode format opening: '1 – 12 Desember 2026', '28 November – 3 Desember 2026'; tanggal pilihan memakai format laporan. */
function periode_opening(array $b): string {
  if ($b['tanggal_custom'] !== '') return tanggal_batch_teks($b);
  [$y1, $m1, $d1] = array_map('intval', explode('-', $b['tgl_mulai']));
  [$y2, $m2, $d2] = array_map('intval', explode('-', $b['tgl_selesai']));
  if ($b['tgl_mulai'] === $b['tgl_selesai']) return tgl_id($b['tgl_mulai']);
  if ($y1 === $y2 && $m1 === $m2) return "$d1 – $d2 " . BULAN_ID[$m1 - 1] . " $y1";
  if ($y1 === $y2) return "$d1 " . BULAN_ID[$m1 - 1] . " – $d2 " . BULAN_ID[$m2 - 1] . " $y1";
  return tgl_id($b['tgl_mulai']) . ' – ' . tgl_id($b['tgl_selesai']);
}

/** Jenis kelas untuk opening: Online/Offline dari skema batch; Blended mengikuti hari pertama (bebas lokasi = online). */
function kelas_opening(array $b): string {
  if ($b['jenis_pelatihan'] === 'Online') return 'online';
  if ($b['jenis_pelatihan'] === 'Offline') return 'offline';
  $h = row('SELECT lokasi_bebas FROM hari WHERE id_batch = ? ORDER BY tanggal LIMIT 1', [$b['id_batch']]);
  $bebas = $h && ($h['lokasi_bebas'] ?? '') !== '' ? $h['lokasi_bebas'] === 'ya' : $b['lokasi_bebas'] === 'ya';
  return $bebas ? 'online' : 'offline';
}

/**
 * Semua PIC & Admin yang login ke LMS boleh membuka opening batch mana pun (data tidak rahasia).
 * id_batch boleh berisi beberapa batch dipisah koma (opening gabungan, mis. Ahli K3 Umum + Pengawas K3 Migas yang kelasnya digabung).
 */
function openingData(array $data, array $user): array {
  $ids = array_values(array_unique(array_filter(array_map('trim', explode(',', (string)($data['id_batch'] ?? ''))))));
  if (!$ids) throw new LmsError('Batch tidak ditemukan. Buka kembali halaman opening dari LMS.');
  if (count($ids) > 6) throw new LmsError('Opening gabungan maksimal 6 batch.');
  $list = [];
  foreach ($ids as $id) {
    $b = row('SELECT * FROM batch WHERE id_batch = ?', [$id]);
    if (!$b) throw new LmsError('Batch tidak ditemukan. Buka kembali halaman opening dari LMS.');
    $list[] = $b;
  }
  if (count($list) === 1) {
    $b = $list[0];
    return ['id_batch' => $b['id_batch'], 'kode_batch' => $b['kode_batch'], 'judul' => $b['nama_pelatihan'], 'periode' => periode_opening($b),
      'kelas' => kelas_opening($b), 'skema' => $b['jenis_pelatihan'], 'inhouse' => trim((string)($b['perusahaan_inhouse'] ?? ''))];
  }
  // gabungan: periode sama → satu periode; berbeda → tanggal mulai paling awal s.d. selesai paling akhir
  $periode = array_values(array_unique(array_map('periode_opening', $list)));
  if (count($periode) > 1) {
    $gab = ['tanggal_custom' => '', 'tgl_mulai' => min(array_column($list, 'tgl_mulai')), 'tgl_selesai' => max(array_column($list, 'tgl_selesai'))];
    $periode = [periode_opening($gab)];
  }
  $kelas = array_values(array_unique(array_map('kelas_opening', $list)));
  $inhouse = array_values(array_unique(array_filter(array_map(fn($b) => trim((string)($b['perusahaan_inhouse'] ?? '')), $list))));
  return ['id_batch' => implode(',', array_column($list, 'id_batch')), 'kode_batch' => implode(' + ', array_column($list, 'kode_batch')),
    'judul' => implode(' & ', array_values(array_unique(array_column($list, 'nama_pelatihan')))), 'periode' => $periode[0],
    'kelas' => count($kelas) === 1 ? $kelas[0] : 'offline', 'kelas_beda' => count($kelas) > 1, 'skema' => '', 'inhouse' => implode(' & ', $inhouse),
    'gabungan' => count($list)];
}
