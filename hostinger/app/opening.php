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

/** Semua PIC & Admin yang login ke LMS boleh membuka opening batch mana pun (data tidak rahasia). */
function openingData(array $data, array $user): array {
  $b = row('SELECT * FROM batch WHERE id_batch = ?', [(string)($data['id_batch'] ?? '')]);
  if (!$b) throw new LmsError('Batch tidak ditemukan. Buka kembali halaman opening dari LMS.');
  return ['id_batch' => $b['id_batch'], 'kode_batch' => $b['kode_batch'], 'judul' => $b['nama_pelatihan'], 'periode' => periode_opening($b),
    'kelas' => kelas_opening($b), 'skema' => $b['jenis_pelatihan'], 'inhouse' => trim((string)($b['perusahaan_inhouse'] ?? ''))];
}
