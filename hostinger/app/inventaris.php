<?php
/**
 * Inventaris pelatihan.
 * - Gudang (Admin & petugas inventaris): katalog barang (diberikan / dipinjamkan, ukuran opsional mis. S–XXL),
 *   stok dicatat sebagai buku besar mutasi (inv_mutasi): tambah stok (+), dialokasikan ke batch (−),
 *   kembali dari batch saat inventaris batch ditutup (+). Stok = jumlah semua mutasi. Stok ≤ batas minimum = menipis.
 * - Per batch (PIC & Admin): barang yang disiapkan (jumlah manual, per ukuran), status per peserta
 *   (diambil / dikembalikan + kondisi + catatan) atau transaksi alat bersama (keluar / kembali).
 * - Tutup batch: sisa barang & pinjaman yang kembali baik masuk lagi ke gudang. Berita acara PDF ditandatangani
 *   digital oleh PIC dan bagian inventaris.
 * Tidak mengubah akses maupun tampilan peserta.
 */

const JENIS_BARANG = ['diberikan' => 'Diberikan', 'dipinjamkan' => 'Dipinjamkan'];
const MODE_INV = ['peserta' => 'Per peserta', 'jumlah' => 'Alat bersama (per jumlah)'];
const KONDISI_INV = ['baik' => 'Baik', 'rusak' => 'Rusak', 'hilang' => 'Hilang'];

// ---------------------------------------------------------------- Bantuan umum

function inv_batch_akses(array $user, string $idBatch): array {
  return is_petugas_inventaris($user) ? get_batch($idBatch) : batch_for_pic($user, $idBatch);
}

function ukuran_barang(array $b): array {
  return array_values(array_filter(array_map('trim', explode(',', $b['ukuran']))));
}

function barang_semua(): array {
  return array_column(rows('SELECT * FROM inv_barang ORDER BY nama'), null, 'id_barang');
}

/** Stok gudang per barang per ukuran: [id_barang][ukuran] => jumlah. */
function stok_gudang(): array {
  $out = [];
  foreach (rows('SELECT id_barang, ukuran, SUM(CAST(jumlah AS SIGNED)) AS n FROM inv_mutasi GROUP BY id_barang, ukuran') as $r) {
    $out[$r['id_barang']][$r['ukuran']] = (int)$r['n'];
  }
  return $out;
}

function mutasi_(string $idBarang, string $ukuran, int $jumlah, string $jenis, string $idBatch, string $catatan, array $user): void {
  if ($jumlah === 0) return;
  insert('inv_mutasi', ['id_mutasi' => new_id('x'), 'id_barang' => $idBarang, 'ukuran' => $ukuran, 'jumlah' => $jumlah, 'jenis' => $jenis,
    'id_batch' => $idBatch, 'catatan' => mb_substr($catatan, 0, 180), 'oleh' => $user['nama'], 'waktu' => now_()['stamp']]);
}

function ba_batch(string $idBatch): array {
  return row('SELECT * FROM inv_ba WHERE id_batch = ?', [$idBatch])
    ?? ['id_batch' => $idBatch, 'ditutup' => '', 'ditutup_oleh' => '', 'ditutup_waktu' => '', 'ttd_pic' => '', 'ttd_pic_waktu' => '', 'ttd_gudang' => '', 'ttd_gudang_waktu' => ''];
}

function ba_simpan(array $ba, array $ubah): array {
  if (row('SELECT id_batch FROM inv_ba WHERE id_batch = ?', [$ba['id_batch']])) return update('inv_ba', $ba, $ubah);
  return insert('inv_ba', array_merge($ba, $ubah));
}

function inv_wajib_terbuka(string $idBatch): void {
  if (ba_batch($idBatch)['ditutup'] === 'ya') throw new LmsError('Inventaris batch ini sudah ditutup. Klik "Buka kembali" untuk mengubah.');
}

/** Alokasi barang batch per ukuran: ['' => 30] atau ['S' => 2, 'M' => 10, ...]. */
function alokasi_ib(array $ib): array {
  $r = json_decode($ib['rincian'] ?: '[]', true);
  return is_array($r) && $r ? array_map('intval', $r) : ['' => (int)$ib['jumlah']];
}

/**
 * Rekap satu barang batch per ukuran: disiapkan, diambil, kembali baik/rusak/hilang, belum kembali, sisa,
 * dan jumlah yang kembali ke gudang saat ditutup.
 */
function rekap_ib(array $ib, array $barang): array {
  $alok = alokasi_ib($ib);
  $pinjam = $barang['jenis'] === 'dipinjamkan';
  $rek = [];
  $tambah = function (string $u) use (&$rek, $alok) {
    if (!isset($rek[$u])) $rek[$u] = ['ukuran' => $u, 'disiapkan' => $alok[$u] ?? 0, 'diambil' => 0, 'baik' => 0, 'rusak' => 0, 'hilang' => 0, 'belum' => 0, 'permintaan' => 0];
  };
  foreach (array_keys($alok) as $u) $tambah((string)$u);
  if ($ib['mode'] === 'peserta') {
    foreach (rows('SELECT * FROM inv_peserta WHERE id_ib = ?', [$ib['id_ib']]) as $p) {
      $u = isset($alok[$p['ukuran']]) || $p['ukuran'] !== '' ? $p['ukuran'] : (string)array_key_first($alok);
      $tambah($u);
      if ($p['ukuran'] !== '') $rek[$u]['permintaan']++;
      if ($p['status'] === 'diambil' || $p['status'] === 'dikembalikan') $rek[$u]['diambil']++;
      if ($p['status'] === 'diambil' && $pinjam) $rek[$u]['belum']++;
      if ($p['status'] === 'dikembalikan') $rek[$u][$p['kondisi'] ?: 'baik']++;
    }
  } else {
    $u = (string)array_key_first($alok);
    $tambah($u);
    foreach (rows('SELECT * FROM inv_transaksi WHERE id_ib = ?', [$ib['id_ib']]) as $t) {
      if ($t['jenis'] === 'keluar') $rek[$u]['diambil'] += (int)$t['jumlah'];
      else foreach (['baik', 'rusak', 'hilang'] as $k) $rek[$u][$k] += (int)$t[$k];
    }
    $rek[$u]['belum'] = max(0, $rek[$u]['diambil'] - $rek[$u]['baik'] - $rek[$u]['rusak'] - $rek[$u]['hilang']);
  }
  foreach ($rek as &$r) {
    if ($ib['mode'] === 'jumlah') {
      // alat bersama: semua unit kembali ke gudang kecuali rusak, hilang, dan yang masih di luar
      $r['sisa'] = max(0, $r['disiapkan'] - $r['belum']);
      $r['ke_gudang'] = max(0, $r['disiapkan'] - $r['rusak'] - $r['hilang'] - $r['belum']);
    } else {
      $r['sisa'] = $r['disiapkan'] - $r['diambil'];
      $r['ke_gudang'] = max(0, $r['sisa']) + ($pinjam ? $r['baik'] : 0);
    }
  }
  unset($r);
  ksort($rek);
  if (ukuran_barang($barang) && isset($rek['']) && !array_filter(array_diff_key($rek[''], ['ukuran' => 1]))) unset($rek['']); // barang berukuran: baris tanpa ukuran yang kosong tidak ditampilkan
  if ($ib['mode'] === 'peserta' && ukuran_barang($barang)) {
    $urut = array_flip(ukuran_barang($barang));
    uksort($rek, fn($a, $b) => ($urut[$a] ?? 99) <=> ($urut[$b] ?? 99) ?: strcmp($a, $b));
  }
  return array_values($rek);
}

function total_rekap(array $rek): array {
  $t = ['disiapkan' => 0, 'diambil' => 0, 'baik' => 0, 'rusak' => 0, 'hilang' => 0, 'belum' => 0, 'sisa' => 0, 'ke_gudang' => 0, 'permintaan' => 0];
  foreach ($rek as $r) foreach ($t as $k => $_) $t[$k] += $r[$k];
  return $t;
}

// ---------------------------------------------------------------- Gudang: katalog & stok

function barang_view(array $b, array $stok): array {
  $uk = ukuran_barang($b);
  $per = [];
  foreach ($uk ?: [''] as $u) $per[$u] = $stok[$b['id_barang']][$u] ?? 0;
  foreach ($stok[$b['id_barang']] ?? [] as $u => $n) if (!array_key_exists($u, $per)) $per[$u] = $n; // ukuran lama yang masih punya stok
  $min = (int)$b['stok_min'];
  return ['id_barang' => $b['id_barang'], 'nama' => $b['nama'], 'jenis' => $b['jenis'], 'jenis_label' => JENIS_BARANG[$b['jenis']] ?? $b['jenis'],
    'satuan' => $b['satuan'], 'ukuran' => $uk, 'stok_min' => $min, 'keterangan' => $b['keterangan'], 'aktif' => $b['aktif'] !== 'tidak',
    'stok' => array_map(fn($u, $n) => ['ukuran' => (string)$u, 'jumlah' => $n, 'menipis' => $min > 0 && $n <= $min], array_keys($per), $per),
    'stok_total' => array_sum($per)];
}

function stok_menipis(): array {
  $stok = stok_gudang();
  $out = [];
  foreach (barang_semua() as $b) {
    if ($b['aktif'] === 'tidak') continue;
    foreach (barang_view($b, $stok)['stok'] as $s) if ($s['menipis']) $out[] = $b['nama'] . ($s['ukuran'] !== '' ? ' ' . $s['ukuran'] : '') . ' (' . $s['jumlah'] . ')';
  }
  return $out;
}

/** Katalog + stok (semua PIC bisa melihat untuk memilih barang; kelola hanya petugas inventaris). */
function invGudang(array $data, array $user): array {
  $stok = stok_gudang();
  $out = ['boleh_kelola' => is_petugas_inventaris($user), 'barang' => array_map(fn($b) => barang_view($b, $stok), array_values(barang_semua()))];
  if (is_petugas_inventaris($user)) {
    $ba = [];
    foreach (rows("SELECT a.*, b.kode_batch, b.nama_pelatihan FROM inv_ba a JOIN batch b ON b.id_batch = a.id_batch WHERE a.ditutup = 'ya' ORDER BY a.ditutup_waktu DESC") as $r) {
      $ba[] = ['id_batch' => $r['id_batch'], 'kode_batch' => $r['kode_batch'], 'nama_pelatihan' => $r['nama_pelatihan'], 'ditutup_waktu' => $r['ditutup_waktu'],
        'ttd_pic' => $r['ttd_pic'] !== '' ? nama_user($r['ttd_pic']) : '', 'ttd_gudang' => $r['ttd_gudang'] !== '' ? nama_user($r['ttd_gudang']) : ''];
    }
    $out['berita_acara'] = $ba;
  }
  return $out;
}

function nama_user(string $id): string {
  $u = row('SELECT nama FROM pengguna WHERE id_user = ?', [$id]);
  return $u ? $u['nama'] : '-';
}

function invBarangSimpan(array $data, array $user): array {
  $nama = preg_replace('/\s+/', ' ', trim((string)($data['nama'] ?? '')));
  $jenis = (string)($data['jenis'] ?? '');
  if ($nama === '') throw new LmsError('Nama barang wajib diisi.');
  if (!array_key_exists($jenis, JENIS_BARANG)) throw new LmsError('Pilih jenis barang: diberikan atau dipinjamkan.');
  $uk = array_values(array_unique(array_filter(array_map(fn($x) => mb_strtoupper(trim($x)), explode(',', (string)($data['ukuran'] ?? ''))))));
  $id = (string)($data['id_barang'] ?? '');
  if (row('SELECT id_barang FROM inv_barang WHERE LOWER(nama) = ? AND id_barang <> ?', [mb_strtolower($nama), $id])) throw new LmsError('Barang "' . $nama . '" sudah ada di katalog.');
  $c = ['nama' => mb_substr($nama, 0, 150), 'jenis' => $jenis, 'satuan' => mb_substr(trim((string)($data['satuan'] ?? '')) ?: 'pcs', 0, 30),
    'ukuran' => implode(',', $uk), 'stok_min' => max(0, (int)($data['stok_min'] ?? 0)), 'keterangan' => mb_substr(trim((string)($data['keterangan'] ?? '')), 0, 500),
    'aktif' => array_key_exists('aktif', $data) && !$data['aktif'] ? 'tidak' : 'ya'];
  if ($id === '') {
    $b = insert('inv_barang', array_merge($c, ['id_barang' => new_id('i'), 'dibuat_pada' => now_()['stamp']]));
  } else {
    $b = row('SELECT * FROM inv_barang WHERE id_barang = ?', [$id]);
    if (!$b) throw new LmsError('Barang tidak ditemukan.');
    $b = update('inv_barang', $b, $c);
  }
  log_($user, 'inv_barang', $b['nama']);
  return barang_view($b, stok_gudang());
}

/** Tambah stok (masuk) atau koreksi stok (+/−, mis. hasil stock opname). */
function invStokTambah(array $data, array $user): array {
  $b = row('SELECT * FROM inv_barang WHERE id_barang = ?', [(string)($data['id_barang'] ?? '')]);
  if (!$b) throw new LmsError('Barang tidak ditemukan.');
  $koreksi = ($data['jenis'] ?? '') === 'koreksi';
  $baris = is_array($data['jumlah'] ?? null) ? $data['jumlah'] : [(string)($data['ukuran'] ?? '') => $data['jumlah'] ?? 0];
  $uk = ukuran_barang($b);
  $n = 0;
  foreach ($baris as $u => $j) {
    $j = (int)$j;
    if ($j === 0) continue;
    if ($uk && !in_array((string)$u, $uk, true)) throw new LmsError('Ukuran "' . $u . '" tidak ada pada barang ini.');
    if (!$uk && (string)$u !== '') throw new LmsError('Barang ini tidak memakai ukuran.');
    if (!$koreksi && $j < 0) throw new LmsError('Jumlah tambah stok harus lebih dari 0. Gunakan Koreksi untuk mengurangi.');
    mutasi_($b['id_barang'], (string)$u, $j, $koreksi ? 'koreksi' : 'masuk', '', (string)($data['catatan'] ?? ''), $user);
    $n += $j;
  }
  if ($n === 0 && !$koreksi) throw new LmsError('Isi jumlah stok yang ditambahkan.');
  log_($user, $koreksi ? 'inv_koreksi' : 'inv_stok_masuk', $b['nama'] . ' ' . $n);
  return barang_view($b, stok_gudang());
}

function invMutasiList(array $data, array $user): array {
  $barang = barang_semua();
  $batch = array_column(rows('SELECT id_batch, kode_batch FROM batch'), 'kode_batch', 'id_batch');
  $where = ($data['id_barang'] ?? '') !== '' ? ' WHERE id_barang = ?' : '';
  return array_map(fn($m) => ['waktu' => $m['waktu'], 'barang' => $barang[$m['id_barang']]['nama'] ?? '(dihapus)', 'ukuran' => $m['ukuran'],
    'jumlah' => (int)$m['jumlah'], 'jenis' => $m['jenis'], 'batch' => $batch[$m['id_batch']] ?? '', 'catatan' => $m['catatan'], 'oleh' => $m['oleh']],
    rows('SELECT * FROM inv_mutasi' . $where . ' ORDER BY waktu DESC, id_mutasi DESC LIMIT 300', $where ? [$data['id_barang']] : []));
}

// ---------------------------------------------------------------- Per batch

function invBatch(array $data, array $user): array {
  $batch = inv_batch_akses($user, (string)($data['id_batch'] ?? ''));
  $barang = barang_semua();
  $stok = stok_gudang();
  $peserta = peserta_batch($batch['id_batch'], false);
  $items = [];
  foreach (rows('SELECT * FROM inv_batch WHERE id_batch = ? ORDER BY diubah_pada', [$batch['id_batch']]) as $ib) {
    $b = $barang[$ib['id_barang']] ?? null;
    if (!$b) continue;
    $rek = rekap_ib($ib, $b);
    $status = [];
    if ($ib['mode'] === 'peserta') {
      foreach (rows('SELECT * FROM inv_peserta WHERE id_ib = ?', [$ib['id_ib']]) as $p) $status[$p['id_user']] = $p;
    }
    $items[] = ['id_ib' => $ib['id_ib'], 'id_barang' => $b['id_barang'], 'nama' => $b['nama'], 'jenis' => $b['jenis'], 'jenis_label' => JENIS_BARANG[$b['jenis']],
      'satuan' => $b['satuan'], 'ukuran' => ukuran_barang($b), 'mode' => $ib['mode'], 'jumlah' => (int)$ib['jumlah'], 'rincian' => alokasi_ib($ib),
      'saran' => $ib['saran'], 'catatan' => $ib['catatan'], 'rekap' => $rek, 'total' => total_rekap($rek),
      'stok_gudang' => barang_view($b, $stok)['stok'],
      'peserta' => $ib['mode'] === 'peserta' ? array_map(fn($u) => ['id_user' => $u['id_user'], 'ukuran' => $status[$u['id_user']]['ukuran'] ?? '',
        'status' => $status[$u['id_user']]['status'] ?? '', 'kondisi' => $status[$u['id_user']]['kondisi'] ?? '', 'catatan' => $status[$u['id_user']]['catatan'] ?? '',
        'waktu_ambil' => $status[$u['id_user']]['waktu_ambil'] ?? '', 'waktu_kembali' => $status[$u['id_user']]['waktu_kembali'] ?? ''], $peserta) : [],
      'transaksi' => $ib['mode'] === 'jumlah' ? rows('SELECT * FROM inv_transaksi WHERE id_ib = ? ORDER BY waktu', [$ib['id_ib']]) : []];
  }
  $ba = ba_batch($batch['id_batch']);
  $saya = row('SELECT ttd_id FROM pengguna WHERE id_user = ?', [$user['id_user']]);
  return ['id_batch' => $batch['id_batch'], 'items' => $items,
    'peserta' => array_map(fn($u) => ['id_user' => $u['id_user'], 'no_absen' => $u['no_absen'], 'nama' => $u['nama'], 'kode_login' => $u['kode_login']], $peserta),
    'ba' => ['ditutup' => $ba['ditutup'] === 'ya', 'ditutup_oleh' => $ba['ditutup_oleh'], 'ditutup_waktu' => $ba['ditutup_waktu'],
      'ttd_pic' => $ba['ttd_pic'] !== '' ? nama_user($ba['ttd_pic']) : '', 'ttd_pic_waktu' => $ba['ttd_pic_waktu'],
      'ttd_gudang' => $ba['ttd_gudang'] !== '' ? nama_user($ba['ttd_gudang']) : '', 'ttd_gudang_waktu' => $ba['ttd_gudang_waktu']],
    'saya_petugas' => is_petugas_inventaris($user), 'saya_punya_ttd' => $saya && $saya['ttd_id'] !== ''];
}

/**
 * Tambah/ubah barang di batch. Jumlah disiapkan (per ukuran) diambil dari stok gudang; selisihnya dicatat sebagai mutasi.
 * Jika stok gudang kurang, ditolak kecuali paksa = true (stok boleh minus, ditandai di gudang).
 */
function invBatchSimpan(array $data, array $user): array {
  $batch = inv_batch_akses($user, (string)($data['id_batch'] ?? ''));
  inv_wajib_terbuka($batch['id_batch']);
  $id = (string)($data['id_ib'] ?? '');
  if ($id === '') {
    $b = row("SELECT * FROM inv_barang WHERE id_barang = ? AND aktif <> 'tidak'", [(string)($data['id_barang'] ?? '')]);
    if (!$b) throw new LmsError('Pilih barang dari katalog.');
    if (row('SELECT id_ib FROM inv_batch WHERE id_batch = ? AND id_barang = ?', [$batch['id_batch'], $b['id_barang']])) throw new LmsError('Barang ini sudah ada di batch.');
    $mode = ukuran_barang($b) ? 'peserta' : (($data['mode'] ?? '') === 'jumlah' ? 'jumlah' : 'peserta');
    $ib = ['id_ib' => new_id('j'), 'id_batch' => $batch['id_batch'], 'id_barang' => $b['id_barang'], 'mode' => $mode, 'jumlah' => 0, 'rincian' => '', 'saran' => '',
      'catatan' => '', 'diubah_oleh' => $user['nama'], 'diubah_pada' => now_()['stamp']];
    $lama = [];
    $baru = true;
  } else {
    $ib = row('SELECT * FROM inv_batch WHERE id_ib = ? AND id_batch = ?', [$id, $batch['id_batch']]);
    if (!$ib) throw new LmsError('Barang batch tidak ditemukan.');
    $b = row('SELECT * FROM inv_barang WHERE id_barang = ?', [$ib['id_barang']]);
    $lama = alokasi_ib($ib);
    $baru = false;
  }
  $uk = ukuran_barang($b);
  $minta = $data['jumlah'] ?? 0;
  $alok = [];
  if ($uk) {
    foreach ((is_array($minta) ? $minta : []) as $u => $j) {
      if (!in_array((string)$u, $uk, true)) throw new LmsError('Ukuran "' . $u . '" tidak ada pada barang ini.');
      if ((int)$j < 0) throw new LmsError('Jumlah tidak boleh minus.');
      if ((int)$j > 0) $alok[(string)$u] = (int)$j;
    }
  } else {
    if ((int)(is_array($minta) ? array_sum($minta) : $minta) < 0) throw new LmsError('Jumlah tidak boleh minus.');
    $alok[''] = (int)(is_array($minta) ? array_sum($minta) : $minta);
  }
  $stok = stok_gudang()[$b['id_barang']] ?? [];
  $kurang = [];
  foreach (array_unique(array_merge(array_keys($alok), array_keys($lama))) as $u) {
    $selisih = ($alok[$u] ?? 0) - ($lama[$u] ?? 0);
    if ($selisih > 0 && ($stok[$u] ?? 0) < $selisih) $kurang[] = $b['nama'] . ($u !== '' ? ' ' . $u : '') . ': butuh ' . $selisih . ', stok ' . ($stok[$u] ?? 0);
  }
  if ($kurang && empty($data['paksa'])) throw new LmsError('STOK_KURANG|Stok gudang tidak cukup — ' . implode('; ', $kurang) . '.');
  foreach (array_unique(array_merge(array_keys($alok), array_keys($lama))) as $u) {
    mutasi_($b['id_barang'], (string)$u, -(($alok[$u] ?? 0) - ($lama[$u] ?? 0)), 'keluar_batch', $batch['id_batch'], 'Disiapkan untuk batch ' . $batch['kode_batch'], $user);
  }
  $c = ['jumlah' => array_sum($alok), 'rincian' => $uk ? json_encode((object)$alok) : '', 'catatan' => mb_substr(trim((string)($data['catatan'] ?? $ib['catatan'])), 0, 180),
    'diubah_oleh' => $user['nama'], 'diubah_pada' => now_()['stamp']];
  $baru ? insert('inv_batch', array_merge($ib, $c)) : update('inv_batch', $ib, $c);
  log_($user, 'inv_batch', $batch['kode_batch'] . ': ' . $b['nama'] . ' = ' . array_sum($alok));
  return invBatch(['id_batch' => $batch['id_batch']], $user);
}

/** Menghapus barang dari batch (hanya jika belum ada catatan pengambilan); alokasinya kembali ke gudang. */
function invBatchHapus(array $data, array $user): array {
  $ib = row('SELECT * FROM inv_batch WHERE id_ib = ?', [(string)($data['id_ib'] ?? '')]);
  if (!$ib) throw new LmsError('Barang batch tidak ditemukan.');
  $batch = inv_batch_akses($user, $ib['id_batch']);
  inv_wajib_terbuka($batch['id_batch']);
  if (row("SELECT id_ip FROM inv_peserta WHERE id_ib = ? AND status <> ''", [$ib['id_ib']]) || row('SELECT id_tr FROM inv_transaksi WHERE id_ib = ?', [$ib['id_ib']])) {
    throw new LmsError('Barang ini sudah punya catatan pengambilan. Kosongkan statusnya dulu jika ingin menghapus.');
  }
  foreach (alokasi_ib($ib) as $u => $j) mutasi_($ib['id_barang'], (string)$u, $j, 'batal_batch', $batch['id_batch'], 'Dibatalkan dari batch ' . $batch['kode_batch'], $user);
  q('DELETE FROM inv_peserta WHERE id_ib = ?', [$ib['id_ib']]);
  hapus('inv_batch', $ib);
  return invBatch(['id_batch' => $batch['id_batch']], $user);
}

function ib_untuk(array $user, string $idIb): array {
  $ib = row('SELECT * FROM inv_batch WHERE id_ib = ?', [$idIb]);
  if (!$ib) throw new LmsError('Barang batch tidak ditemukan.');
  inv_batch_akses($user, $ib['id_batch']);
  inv_wajib_terbuka($ib['id_batch']);
  return $ib;
}

/** Status satu peserta untuk satu barang: ukuran, status ('' / diambil / dikembalikan), kondisi, catatan. */
function invPesertaSet(array $data, array $user): array {
  $ib = ib_untuk($user, (string)($data['id_ib'] ?? ''));
  if ($ib['mode'] !== 'peserta') throw new LmsError('Barang ini dicatat per jumlah, bukan per peserta.');
  $b = row('SELECT * FROM inv_barang WHERE id_barang = ?', [$ib['id_barang']]);
  $u = row("SELECT * FROM pengguna WHERE id_user = ? AND role = 'peserta' AND id_batch = ?", [(string)($data['id_user'] ?? ''), $ib['id_batch']]);
  if (!$u) throw new LmsError('Peserta tidak ditemukan di batch ini.');
  $p = row('SELECT * FROM inv_peserta WHERE id_ib = ? AND id_user = ?', [$ib['id_ib'], $u['id_user']]);
  $status = array_key_exists('status', $data) ? (string)$data['status'] : ($p['status'] ?? '');
  $ukuran = array_key_exists('ukuran', $data) ? (string)$data['ukuran'] : ($p['ukuran'] ?? '');
  $kondisi = '';
  if ($status === 'dikembalikan') {
    $kondisi = array_key_exists((string)($data['kondisi'] ?? ''), KONDISI_INV) ? (string)$data['kondisi'] : (($p['kondisi'] ?? '') ?: 'baik');
  }
  if (!in_array($status, ['', 'diambil', 'dikembalikan'], true)) throw new LmsError('Status tidak valid.');
  if ($status === 'dikembalikan' && $b['jenis'] !== 'dipinjamkan') throw new LmsError('Barang yang diberikan tidak perlu dikembalikan.');
  $uk = ukuran_barang($b);
  if ($ukuran !== '' && !in_array($ukuran, $uk, true)) throw new LmsError('Ukuran tidak valid untuk barang ini.');
  if ($uk && $status !== '' && $ukuran === '') throw new LmsError('Pilih ukuran ' . $b['nama'] . ' untuk ' . $u['nama'] . ' terlebih dahulu.');
  $now = now_()['stamp'];
  $c = ['ukuran' => $ukuran, 'status' => $status, 'kondisi' => $kondisi, 'oleh' => $user['nama'],
    'catatan' => mb_substr(trim((string)($data['catatan'] ?? ($p['catatan'] ?? ''))), 0, 180),
    'waktu_ambil' => $status === '' ? '' : (($p['waktu_ambil'] ?? '') ?: $now),
    'waktu_kembali' => $status === 'dikembalikan' ? (($p['status'] ?? '') === 'dikembalikan' && ($p['waktu_kembali'] ?? '') !== '' ? $p['waktu_kembali'] : $now) : ''];
  if ($p) update('inv_peserta', $p, $c);
  else insert('inv_peserta', array_merge($c, ['id_ip' => new_id('k'), 'id_batch' => $ib['id_batch'], 'id_ib' => $ib['id_ib'], 'id_user' => $u['id_user']]));
  return ['ok' => true];
}

/** Tandai semua peserta (yang belum) sudah mengambil barang ini. Barang berukuran: hanya peserta yang ukurannya sudah dipilih. */
function invPesertaMassal(array $data, array $user): array {
  $ib = ib_untuk($user, (string)($data['id_ib'] ?? ''));
  $b = row('SELECT * FROM inv_barang WHERE id_barang = ?', [$ib['id_barang']]);
  $berukuran = (bool)ukuran_barang($b);
  $n = $lewat = 0;
  foreach (peserta_batch($ib['id_batch'], false) as $u) {
    $p = row('SELECT * FROM inv_peserta WHERE id_ib = ? AND id_user = ?', [$ib['id_ib'], $u['id_user']]);
    if ($p && $p['status'] !== '') continue;
    if ($berukuran && (!$p || $p['ukuran'] === '')) { $lewat++; continue; }
    invPesertaSet(['id_ib' => $ib['id_ib'], 'id_user' => $u['id_user'], 'status' => 'diambil'], $user);
    $n++;
  }
  return ['ditandai' => $n, 'dilewati' => $lewat];
}

/** Alat bersama: catat keluar (jumlah) atau kembali (baik/rusak/hilang). */
function invTransaksi(array $data, array $user): array {
  $ib = ib_untuk($user, (string)($data['id_ib'] ?? ''));
  if ($ib['mode'] !== 'jumlah') throw new LmsError('Barang ini dicatat per peserta.');
  $b = row('SELECT * FROM inv_barang WHERE id_barang = ?', [$ib['id_barang']]);
  $rek = rekap_ib($ib, $b)[0];
  $jenis = ($data['jenis'] ?? '') === 'kembali' ? 'kembali' : 'keluar';
  $c = ['jumlah' => 0, 'baik' => 0, 'rusak' => 0, 'hilang' => 0];
  if ($jenis === 'keluar') {
    $c['jumlah'] = (int)($data['jumlah'] ?? 0);
    if ($c['jumlah'] <= 0) throw new LmsError('Isi jumlah yang keluar.');
    if ($rek['belum'] + $c['jumlah'] > $rek['disiapkan']) throw new LmsError('Melebihi jumlah yang disiapkan (' . $rek['disiapkan'] . ', sedang di luar ' . $rek['belum'] . ').');
  } else {
    foreach (['baik', 'rusak', 'hilang'] as $k) $c[$k] = max(0, (int)($data[$k] ?? 0));
    $tot = $c['baik'] + $c['rusak'] + $c['hilang'];
    if ($tot <= 0) throw new LmsError('Isi jumlah yang kembali.');
    if ($tot > $rek['belum']) throw new LmsError('Yang kembali (' . $tot . ') lebih banyak dari yang sedang di luar (' . $rek['belum'] . ').');
  }
  insert('inv_transaksi', array_merge($c, ['id_tr' => new_id('t'), 'id_batch' => $ib['id_batch'], 'id_ib' => $ib['id_ib'], 'jenis' => $jenis,
    'catatan' => mb_substr(trim((string)($data['catatan'] ?? '')), 0, 180), 'oleh' => $user['nama'], 'waktu' => now_()['stamp']]));
  return invBatch(['id_batch' => $ib['id_batch']], $user);
}

function invTransaksiHapus(array $data, array $user): array {
  $t = row('SELECT * FROM inv_transaksi WHERE id_tr = ?', [(string)($data['id_tr'] ?? '')]);
  if (!$t) throw new LmsError('Transaksi tidak ditemukan.');
  ib_untuk($user, $t['id_ib']);
  hapus('inv_transaksi', $t);
  return invBatch(['id_batch' => $t['id_batch']], $user);
}

/**
 * Tutup inventaris batch: sisa barang dan pinjaman yang kembali dalam kondisi baik masuk lagi ke stok gudang.
 * Jika masih ada pinjaman yang belum kembali, perlu paksa = true (dicatat di berita acara sebagai belum kembali).
 */
function invTutup(array $data, array $user): array {
  $batch = inv_batch_akses($user, (string)($data['id_batch'] ?? ''));
  inv_wajib_terbuka($batch['id_batch']);
  $barang = barang_semua();
  $ibs = rows('SELECT * FROM inv_batch WHERE id_batch = ?', [$batch['id_batch']]);
  if (!$ibs) throw new LmsError('Belum ada barang inventaris di batch ini.');
  $belum = [];
  foreach ($ibs as $ib) {
    $t = total_rekap(rekap_ib($ib, $barang[$ib['id_barang']]));
    if ($t['belum']) $belum[] = $barang[$ib['id_barang']]['nama'] . ' (' . $t['belum'] . ')';
  }
  if ($belum && empty($data['paksa'])) throw new LmsError('BELUM_KEMBALI|Masih ada pinjaman yang belum dikembalikan: ' . implode(', ', $belum) . '.');
  foreach ($ibs as $ib) {
    foreach (rekap_ib($ib, $barang[$ib['id_barang']]) as $r) {
      mutasi_($ib['id_barang'], $r['ukuran'], $r['ke_gudang'], 'kembali_batch', $batch['id_batch'], 'Kembali dari batch ' . $batch['kode_batch'], $user);
    }
  }
  $now = now_()['stamp'];
  ba_simpan(ba_batch($batch['id_batch']), ['ditutup' => 'ya', 'ditutup_oleh' => $user['nama'], 'ditutup_waktu' => $now,
    'ttd_pic' => '', 'ttd_pic_waktu' => '', 'ttd_gudang' => '', 'ttd_gudang_waktu' => '']);
  log_($user, 'inv_tutup', $batch['kode_batch']);
  return invBatch(['id_batch' => $batch['id_batch']], $user);
}

/** Buka kembali: pengembalian ke gudang dibatalkan dan tanda tangan dikosongkan, agar data bisa diperbaiki. */
function invBuka(array $data, array $user): array {
  $batch = inv_batch_akses($user, (string)($data['id_batch'] ?? ''));
  $ba = ba_batch($batch['id_batch']);
  if ($ba['ditutup'] !== 'ya') throw new LmsError('Inventaris batch ini belum ditutup.');
  q("DELETE FROM inv_mutasi WHERE id_batch = ? AND jenis = 'kembali_batch'", [$batch['id_batch']]);
  ba_simpan($ba, ['ditutup' => '', 'ditutup_oleh' => '', 'ditutup_waktu' => '', 'ttd_pic' => '', 'ttd_pic_waktu' => '', 'ttd_gudang' => '', 'ttd_gudang_waktu' => '']);
  log_($user, 'inv_buka', $batch['kode_batch']);
  return invBatch(['id_batch' => $batch['id_batch']], $user);
}

function wajib_ttd(array $user): void {
  $u = row('SELECT ttd_id FROM pengguna WHERE id_user = ?', [$user['id_user']]);
  if (!$u || $u['ttd_id'] === '') throw new LmsError('Anda belum punya tanda tangan digital. Buat dulu di menu Laporan → "Tanda tangan saya".');
}

function invTtdPic(array $data, array $user): array {
  $batch = inv_batch_akses($user, (string)($data['id_batch'] ?? ''));
  $ba = ba_batch($batch['id_batch']);
  if ($ba['ditutup'] !== 'ya') throw new LmsError('Tutup inventaris batch terlebih dahulu.');
  wajib_ttd($user);
  ba_simpan($ba, ['ttd_pic' => $user['id_user'], 'ttd_pic_waktu' => now_()['stamp']]);
  log_($user, 'inv_ttd_pic', $batch['kode_batch']);
  return invBatch(['id_batch' => $batch['id_batch']], $user);
}

/** Bagian inventaris memverifikasi & menandatangani berita acara batch. */
function invVerifikasi(array $data, array $user): array {
  $batch = get_batch((string)($data['id_batch'] ?? ''));
  $ba = ba_batch($batch['id_batch']);
  if ($ba['ditutup'] !== 'ya') throw new LmsError('Inventaris batch ini belum ditutup oleh PIC.');
  if ($ba['ttd_pic'] === '') throw new LmsError('Berita acara belum ditandatangani PIC batch.');
  wajib_ttd($user);
  ba_simpan($ba, ['ttd_gudang' => $user['id_user'], 'ttd_gudang_waktu' => now_()['stamp']]);
  log_($user, 'inv_verifikasi', $batch['kode_batch']);
  return ['ok' => true];
}

// ---------------------------------------------------------------- Berita acara PDF

function invPdf(array $data, array $user): array {
  $batch = inv_batch_akses($user, (string)($data['id_batch'] ?? ''));
  $d = invBatch(['id_batch' => $batch['id_batch']], $user);
  if (!$d['items']) throw new LmsError('Belum ada barang inventaris di batch ini.');
  $html = ba_html($batch, $d);
  $nama = preg_replace('#[\\\\/:*?"<>|]#', '-', 'Berita Acara Inventaris ' . $batch['kode_batch'] . ' - ' . $batch['nama_pelatihan'] . '.pdf');
  $pdf = html_ke_pdf($html);
  $rel = simpan_bytes('storage/' . $batch['folder'] . '/laporan', $pdf, $nama);
  insert('laporan', ['id_laporan' => new_id('l'), 'id_batch' => $batch['id_batch'], 'jenis' => 'inventaris', 'id_hari' => '', 'file_id' => $rel,
    'dibuat_oleh' => $user['nama'], 'dibuat_pada' => now_()['stamp'], 'nama_file' => $nama]);
  log_($user, 'inv_pdf', $nama);
  return ['nama' => $nama, 'data' => base64_encode($pdf)];
}

function ba_html(array $batch, array $d): string {
  $org = org_();
  $pesertaNama = array_column($d['peserta'], null, 'id_user');
  $css = 'body { font-family: Helvetica, Arial, sans-serif; font-size: 9pt; color: #111; } @page { margin: 14mm 13mm; }
    h1 { font-size: 13pt; text-align: center; margin: 6px 0 2px; } .sub { text-align: center; font-size: 9pt; margin-bottom: 10px; }
    h2 { font-size: 10.5pt; margin: 14px 0 5px; color: #1b1e4f; }
    table { border-collapse: collapse; width: 100%; } table.g th, table.g td { border: 1px solid #888; padding: 3px 4px; vertical-align: top; }
    table.g th { background: #e8edff; font-size: 8.5pt; } td.c, th.c { text-align: center; } .merah { color: #b42318; font-weight: bold; }
    table.id td { border: 0; padding: 2px 4px; } .muted { color: #666; } .pb { page-break-inside: avoid; }';
  $id = '<table class="id"><tr><td style="width:120px">Kode batch</td><td>: ' . h_($batch['kode_batch']) . '</td><td style="width:110px">Skema</td><td>: ' . h_($batch['jenis_pelatihan'] ?: '-') . '</td></tr>' .
    '<tr><td>Nama pelatihan</td><td>: ' . h_($batch['nama_pelatihan']) . '</td><td>Jumlah peserta</td><td>: ' . count($d['peserta']) . ' orang</td></tr>' .
    '<tr><td>Tanggal</td><td>: ' . h_(tanggal_batch_teks($batch)) . '</td><td>Ditutup</td><td>: ' . h_($d['ba']['ditutup_waktu'] ?: 'belum ditutup') . '</td></tr></table>';
  // A. Ringkasan
  $rows = '';
  $no = 0;
  $tindak = [];
  foreach ($d['items'] as $it) {
    foreach ($it['rekap'] as $r) {
      $no++;
      $pinjam = $it['jenis'] === 'dipinjamkan';
      $rows .= '<tr><td class="c">' . $no . '</td><td>' . h_($it['nama']) . '</td><td>' . h_($it['jenis_label']) . '</td><td class="c">' . h_($r['ukuran'] ?: '-') . '</td>' .
        '<td class="c">' . $r['disiapkan'] . '</td><td class="c">' . $r['diambil'] . '</td><td class="c">' . ($pinjam ? $r['baik'] : '-') . '</td>' .
        '<td class="c' . ($r['rusak'] ? ' merah' : '') . '">' . ($pinjam ? $r['rusak'] : '-') . '</td><td class="c' . ($r['hilang'] ? ' merah' : '') . '">' . ($pinjam ? $r['hilang'] : '-') . '</td>' .
        '<td class="c' . ($r['belum'] ? ' merah' : '') . '">' . ($pinjam ? $r['belum'] : '-') . '</td><td class="c">' . $r['ke_gudang'] . '</td></tr>';
    }
  }
  $ringkas = '<h2>A. Ringkasan barang</h2><table class="g"><tr><th class="c" style="width:22px">No</th><th>Barang</th><th>Jenis</th><th class="c">Ukuran</th><th class="c">Disiapkan</th>' .
    '<th class="c">Diambil / dipinjam</th><th class="c">Kembali baik</th><th class="c">Rusak</th><th class="c">Hilang</th><th class="c">Belum kembali</th><th class="c">Kembali ke gudang</th></tr>' . $rows . '</table>';
  // B. Rincian per peserta
  $rinci = '';
  foreach ($d['items'] as $it) {
    if ($it['mode'] !== 'peserta') continue;
    $tr = '';
    foreach ($it['peserta'] as $p) {
      $u = $pesertaNama[$p['id_user']];
      $st = $p['status'] === 'dikembalikan' ? 'Dikembalikan (' . (KONDISI_INV[$p['kondisi']] ?? 'Baik') . ')' : ($p['status'] === 'diambil' ? ($it['jenis'] === 'dipinjamkan' ? 'Dipinjam' : 'Diambil') : 'Belum diambil');
      $merah = ($p['status'] === 'diambil' && $it['jenis'] === 'dipinjamkan') || in_array($p['kondisi'], ['rusak', 'hilang'], true);
      if ($merah) $tindak[] = $u['nama'] . ' — ' . $it['nama'] . ($p['ukuran'] ? ' ' . $p['ukuran'] : '') . ': ' . ($p['status'] === 'diambil' ? 'belum dikembalikan' : strtolower(KONDISI_INV[$p['kondisi']])) . ($p['catatan'] ? ' (' . $p['catatan'] . ')' : '');
      $tr .= '<tr><td class="c">' . h_($u['no_absen']) . '</td><td>' . h_($u['nama']) . '</td><td class="c">' . h_($p['ukuran'] ?: '-') . '</td><td class="' . ($merah ? 'merah' : '') . '">' . h_($st) . '</td>' .
        '<td>' . h_(substr($p['waktu_ambil'], 0, 16)) . '</td><td>' . h_(substr($p['waktu_kembali'], 0, 16)) . '</td><td>' . h_($p['catatan']) . '</td></tr>';
    }
    $rinci .= '<div class="pb"><p style="margin:8px 0 3px"><b>' . h_($it['nama']) . '</b> <span class="muted">(' . h_($it['jenis_label']) . ')</span></p><table class="g"><tr><th class="c" style="width:22px">No</th><th>Nama peserta</th>' .
      '<th class="c">Ukuran</th><th>Status</th><th>Waktu ambil</th><th>Waktu kembali</th><th>Catatan</th></tr>' . $tr . '</table></div>';
  }
  if ($rinci !== '') $rinci = '<h2>B. Rincian per peserta</h2>' . $rinci;
  // C. Alat bersama
  $alat = '';
  foreach ($d['items'] as $it) {
    if ($it['mode'] !== 'jumlah') continue;
    $tr = implode('', array_map(fn($t) => '<tr><td>' . h_(substr($t['waktu'], 0, 16)) . '</td><td>' . ($t['jenis'] === 'keluar' ? 'Keluar ' . $t['jumlah'] : 'Kembali: baik ' . $t['baik'] . ', rusak ' . $t['rusak'] . ', hilang ' . $t['hilang']) .
      '</td><td>' . h_($t['oleh']) . '</td><td>' . h_($t['catatan']) . '</td></tr>', $it['transaksi']));
    $r = $it['total'];
    if ($r['rusak'] || $r['hilang'] || $r['belum']) $tindak[] = $it['nama'] . ': rusak ' . $r['rusak'] . ', hilang ' . $r['hilang'] . ', belum kembali ' . $r['belum'];
    $alat .= '<div class="pb"><p style="margin:8px 0 3px"><b>' . h_($it['nama']) . '</b> — disiapkan ' . $r['disiapkan'] . ' ' . h_($it['satuan']) . '</p><table class="g"><tr><th>Waktu</th><th>Transaksi</th><th>Dicatat oleh</th><th>Catatan</th></tr>' .
      ($tr ?: '<tr><td colspan="4" class="muted">Belum ada transaksi.</td></tr>') . '</table></div>';
  }
  if ($alat !== '') $alat = '<h2>' . ($rinci !== '' ? 'C' : 'B') . '. Riwayat alat bersama</h2>' . $alat;
  $hTindak = '<h2>Perlu tindak lanjut</h2>' . ($tindak ? '<ul>' . implode('', array_map(fn($x) => '<li class="merah">' . h_($x) . '</li>', $tindak)) . '</ul>' : '<p class="muted">Tidak ada barang belum kembali, rusak, atau hilang.</p>');
  // Pengesahan
  $ttd = function (string $judul, string $idUser, string $waktu) {
    $u = $idUser !== '' ? row('SELECT nama, ttd_id FROM pengguna WHERE id_user = ?', [$idUser]) : null;
    return '<td style="width:50%;text-align:center;border:0">' . $judul . '<br>' . ($u && $u['ttd_id'] !== '' ? img_(file_data_url($u['ttd_id']), 'height="60"') : '<br><br><br>') .
      '<br><b><u>' . h_($u ? $u['nama'] : '(belum ditandatangani)') . '</u></b>' . ($waktu ? '<br><span class="muted">' . h_($waktu) . '</span>' : '') . '</td>';
  };
  $ba = row('SELECT * FROM inv_ba WHERE id_batch = ?', [$batch['id_batch']]) ?? ['ttd_pic' => '', 'ttd_pic_waktu' => '', 'ttd_gudang' => '', 'ttd_gudang_waktu' => ''];
  $sah = '<div class="pb"><h2>Pengesahan</h2><p>Demikian berita acara serah terima dan pengembalian inventaris ini dibuat dengan sebenar-benarnya.</p>' .
    '<table><tr>' . $ttd('PIC Batch', $ba['ttd_pic'], $ba['ttd_pic_waktu']) . $ttd('Bagian Inventaris', $ba['ttd_gudang'], $ba['ttd_gudang_waktu']) . '</tr></table></div>';
  return '<html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>' . kop($org) .
    '<h1>BERITA ACARA SERAH TERIMA &amp; PENGEMBALIAN INVENTARIS</h1><div class="sub">' . h_($org['nama']) . '</div>' .
    $id . $ringkas . $rinci . $alat . $hTindak . $sah .
    '<p class="muted" style="margin-top:12px;font-size:7.5pt">Dibuat otomatis oleh LMS Artha Safety Indonesia pada ' . h_(now_()['stamp']) . ' WIB.</p></body></html>';
}

// ---------------------------------------------------------------- Dashboard PIC

/** Pengingat inventaris untuk satu batch (dipanggil dari dasbor). */
function pengingat_inventaris(array $b, string $fase, string $today): array {
  $ibs = rows('SELECT * FROM inv_batch WHERE id_batch = ?', [$b['id_batch']]);
  if (!$ibs) return [];
  $barang = barang_semua();
  $ba = ba_batch($b['id_batch']);
  $p = [];
  $belum = [];
  foreach ($ibs as $ib) {
    if (!isset($barang[$ib['id_barang']])) continue;
    $t = total_rekap(rekap_ib($ib, $barang[$ib['id_barang']]));
    if ($t['belum']) $belum[] = $t['belum'] . ' ' . $barang[$ib['id_barang']]['nama'];
  }
  $hariTerakhir = $b['tgl_selesai'] <= $today;
  if ($belum && $hariTerakhir && $ba['ditutup'] !== 'ya') $p[] = pengingat_('warn', 'Pinjaman inventaris belum dikembalikan: ' . implode(', ', $belum) . '.', 'inventaris');
  if ($fase === 'selesai' && $ba['ditutup'] !== 'ya') $p[] = pengingat_('warn', 'Inventaris batch belum ditutup & dikembalikan ke gudang.', 'inventaris');
  if ($ba['ditutup'] === 'ya' && $ba['ttd_pic'] === '') $p[] = pengingat_('info', 'Berita acara inventaris belum ditandatangani PIC.', 'inventaris');
  return $p;
}
