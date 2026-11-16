<?php
/** Login, sesi (token), dan akun PIC. */

const MAX_TOKEN_PIC = 3; // PIC boleh login di beberapa perangkat (mis. laptop + HP)

function hash_pin(string $pin): string { return password_hash($pin, PASSWORD_DEFAULT); }

function new_token(): string { return bin2hex(random_bytes(24)); }

function is_pic(array $u): bool { return $u['role'] === 'pic' || $u['role'] === 'admin'; }

function user_from_token($token): array {
  $t = (string)$token;
  $u = $t !== '' ? row('SELECT p.* FROM token t JOIN pengguna p ON p.id_user = t.id_user WHERE t.token = ?', [$t]) : null;
  if (!$u || $u['aktif'] === 'tidak') throw new LmsError('Sesi login habis, silakan login lagi.');
  return $u;
}

function public_user(array $u): array {
  return [
    'id_user' => $u['id_user'], 'id_batch' => $u['id_batch'], 'no_absen' => $u['no_absen'], 'nama' => $u['nama'],
    'role' => $u['role'], 'kode_login' => $u['kode_login'], 'setuju_privasi' => $u['setuju_privasi'] !== '',
    'punya_ttd' => $u['ttd_id'] !== '',
  ] + ($u['role'] === 'peserta' ? [] : ['inventaris' => is_petugas_inventaris($u)]); // data peserta tidak berubah
}

/** Admin, atau PIC yang ditunjuk sebagai petugas inventaris (kelola katalog, stok gudang, tanda tangan bagian inventaris). */
function is_petugas_inventaris(array $u): bool {
  return $u['role'] === 'admin' || ($u['role'] === 'pic' && ($u['hak_inventaris'] ?? '') === 'ya');
}

function batchAktif(): array {
  // Semua batch tampil di halaman login: peserta batch yang sudah selesai/diarsipkan tetap bisa login untuk melihat
  // materi, dokumentasi, riwayat, dan SKL (absen hanya untuk batch aktif). Batch aktif di urutan atas.
  return array_map(fn($b) => ['id_batch' => $b['id_batch'], 'nama_pelatihan' => $b['nama_pelatihan'], 'kode_batch' => $b['kode_batch'],
    'selesai' => $b['status'] !== 'aktif'],
    rows("SELECT * FROM batch ORDER BY CASE WHEN status = 'aktif' THEN 0 ELSE 1 END, tgl_mulai DESC"));
}

/** Kode acak pendek untuk link/QR login batch (dibuat sekali, otomatis). Tanpa huruf/angka yang mirip (0/O, 1/I/L). */
function kode_link_batch(array $b): string {
  if (($b['kode_link'] ?? '') !== '') return $b['kode_link'];
  $huruf = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';
  do {
    $k = '';
    for ($i = 0; $i < 6; $i++) $k .= $huruf[random_int(0, strlen($huruf) - 1)];
  } while (row('SELECT id_batch FROM batch WHERE kode_link = ?', [$k]));
  update('batch', $b, ['kode_link' => $k]);
  return $k;
}

/** Info pelatihan dari link/QR (?b=KODE) untuk halaman login peserta. */
function batchLink(array $data): array {
  $k = strtoupper(trim((string)($data['b'] ?? '')));
  $b = preg_match('/^[A-Z0-9]{4,12}$/', $k) ? row('SELECT * FROM batch WHERE kode_link = ?', [$k]) : null;
  if (!$b) throw new LmsError('Link pelatihan tidak dikenali. Silakan masuk dengan kode peserta saja.');
  return ringkas_batch_login($b);
}

/** Link & QR login batch untuk PIC/Admin. */
function linkBatch(array $data, array $user): array {
  $b = batch_for_pic($user, (string)($data['id_batch'] ?? ''));
  return ['kode' => kode_link_batch($b)] + ringkas_batch_login($b);
}

function buat_token(array $u, int $simpan, string $deviceId = ''): string {
  $token = new_token();
  insert('token', ['token' => $token, 'id_user' => $u['id_user'], 'dibuat_pada' => now_()['stamp'], 'device_id' => $deviceId]);
  // Sisakan token terbaru saja.
  $lama = rows('SELECT token FROM token WHERE id_user = ? ORDER BY dibuat_pada DESC, token', [$u['id_user']]);
  $buang = array_filter($lama, fn($r) => $r['token'] !== $token);
  foreach (array_slice(array_values($buang), max(0, $simpan - 1)) as $r) q('DELETE FROM token WHERE token = ?', [$r['token']]);
  return $token;
}

/** Ringkasan batch untuk halaman login peserta (pilihan pelatihan & link/QR). */
function ringkas_batch_login(array $b): array {
  return ['id_batch' => $b['id_batch'], 'nama_pelatihan' => $b['nama_pelatihan'], 'periode' => tanggal_batch_teks($b),
    'jenis' => jenis_pelatihan($b), 'lokasi' => $b['lokasi_bebas'] === 'ya' ? 'Online' : $b['lokasi_nama'],
    'selesai' => $b['status'] !== 'aktif' || $b['tgl_selesai'] < now_()['date'], 'tgl_mulai' => $b['tgl_mulai']];
}

/**
 * Login peserta. Cukup kode dari kartu: batch dicari otomatis (semua batch, termasuk selesai/arsip).
 * Bila kode yang sama ada di beberapa batch, dikembalikan daftar pilihan ('pilih') dan peserta memilih batchnya.
 * id_batch dikirim bila peserta datang dari link/QR batch atau sudah memilih (juga kompatibel dengan tampilan lama).
 */
function loginPeserta(array $data): array {
  $kode = strtolower(preg_replace('/\s+/', '', (string)($data['kode'] ?? '')));
  $deviceId = (string)($data['device_id'] ?? '');
  if ($kode === '') throw new LmsError('Kode wajib diisi.');
  if ($deviceId === '') throw new LmsError('Perangkat tidak dikenali. Muat ulang halaman.');
  $idBatch = (string)($data['id_batch'] ?? '');
  if ($idBatch !== '') {
    $batch = get_batch($idBatch);
    $u = row("SELECT * FROM pengguna WHERE role = 'peserta' AND id_batch = ? AND LOWER(kode_login) = ?", [$batch['id_batch'], $kode]);
    if (!$u || $u['aktif'] === 'tidak') throw new LmsError('Kode tidak ditemukan di pelatihan ini. Periksa kembali kode di kartu peserta Anda atau hubungi PIC.');
  } else {
    $cocok = rows("SELECT * FROM pengguna WHERE role = 'peserta' AND LOWER(kode_login) = ? AND aktif <> 'tidak'", [$kode]);
    if (!$cocok) throw new LmsError('Kode tidak ditemukan. Periksa kembali kode di kartu peserta Anda atau hubungi PIC.');
    if (count($cocok) > 1) {
      $pilih = array_map(fn($x) => ringkas_batch_login(get_batch($x['id_batch'])), $cocok);
      usort($pilih, fn($a, $b) => $a['selesai'] <=> $b['selesai'] ?: strcmp($b['tgl_mulai'], $a['tgl_mulai']));
      return ['pilih' => $pilih];
    }
    $u = $cocok[0];
  }
  $u = daftarkan_perangkat($u, $deviceId); // maksimal 2 perangkat (mis. HP + laptop)
  $token = buat_token($u, MAKS_PERANGKAT, $deviceId);
  log_($u, 'login', 'peserta');
  return ['token' => $token, 'user' => public_user($u), 'me' => me([], $u)];
}

function loginPic(array $data): array {
  $kode = strtolower(trim((string)($data['kode'] ?? '')));
  $pin = (string)($data['pin'] ?? '');
  $u = row("SELECT * FROM pengguna WHERE role IN ('pic', 'admin') AND LOWER(kode_login) = ?", [$kode]);
  if (!$u || $u['aktif'] === 'tidak' || !password_verify($pin, $u['pin_hash'])) throw new LmsError('Kode atau PIN salah.');
  $token = buat_token($u, MAX_TOKEN_PIC);
  log_($u, 'login', $u['role']);
  return ['token' => $token, 'user' => public_user($u), 'me' => me([], $u)];
}

/** Data awal setelah login / saat aplikasi dibuka, sekaligus isi halaman pertama. */
function me(array $data, array $user): array {
  $out = ['user' => public_user($user)];
  if ($user['role'] === 'peserta') {
    $b = get_batch($user['id_batch']);
    $out['batch'] = ['id_batch' => $b['id_batch'], 'nama_pelatihan' => $b['nama_pelatihan'], 'kode_batch' => $b['kode_batch']];
    $out['berkas_aktif'] = (bool)items_berkas($b); // menu Berkas Saya hanya untuk program yang punya daftar berkas (mis. BNSP)
    $out['sertifikat_aktif'] = (bool)sertifikat_ringkas($user); // menu Sertifikat hanya muncul setelah ada sertifikat terbit
    $out['nilai_aktif'] = nilai_aktif_peserta($user); // menu Nilai Ujian hanya muncul setelah nilai dipublikasikan
    if ($user['setuju_privasi'] !== '') $out['beranda'] = beranda($data, $user);
  } else {
    $out['batches'] = batchList($data, $user);
  }
  return $out;
}

/** Status absen hari ini + konten (rundown, materi, dokumentasi) dalam satu permintaan. */
function beranda(array $data, array $user): array {
  return ['status' => statusHariIni($data, $user), 'konten' => kontenPeserta($data, $user), 'skl' => skl_view(skl_of($user['id_user'])),
    'panduan' => panduan_peserta($user), 'berkas' => berkas_ringkas($user), 'sertifikat' => sertifikat_ringkas($user)];
}

function logout(array $data, array $user): bool {
  q('DELETE FROM token WHERE token = ?', [(string)($data['token'] ?? '')]);
  return true;
}

function setuju(array $data, array $user): array {
  if ($user['setuju_privasi'] === '') $user = update('pengguna', $user, ['setuju_privasi' => now_()['stamp']]);
  return public_user($user);
}

function gantiPin(array $data, array $user): bool {
  if (!password_verify((string)($data['pin_lama'] ?? ''), $user['pin_hash'])) throw new LmsError('PIN lama salah.');
  $baru = (string)($data['pin_baru'] ?? '');
  if (!preg_match('/^\d{4,8}$/', $baru)) throw new LmsError('PIN baru harus 4–8 angka.');
  update('pengguna', $user, ['pin_hash' => hash_pin($baru)]);
  log_($user, 'ganti_pin');
  return true;
}

// ---------------------------------------------------------------- Akun PIC (Admin)

function picList(): array {
  return array_map(fn($u) => ['id_user' => $u['id_user'], 'nama' => $u['nama'], 'kode_login' => $u['kode_login'],
    'role' => $u['role'], 'no_hp' => $u['no_hp'], 'aktif' => $u['aktif'], 'hak_inventaris' => ($u['hak_inventaris'] ?? '') === 'ya'],
    rows("SELECT * FROM pengguna WHERE role IN ('pic', 'admin') ORDER BY role, nama"));
}

function picTambah(array $data, array $user): array {
  $nama = trim((string)($data['nama'] ?? ''));
  $kode = trim((string)($data['kode'] ?? ''));
  $pin = (string)($data['pin'] ?? '');
  if ($nama === '') throw new LmsError('Nama PIC wajib diisi.');
  if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $kode)) throw new LmsError('Kode PIC 3–30 karakter (huruf, angka, titik, strip).');
  if (!preg_match('/^\d{4,8}$/', $pin)) throw new LmsError('PIN harus 4–8 angka.');
  if (row("SELECT id_user FROM pengguna WHERE role IN ('pic', 'admin') AND LOWER(kode_login) = ?", [strtolower($kode)])) {
    throw new LmsError('Kode PIC sudah dipakai.');
  }
  $u = insert('pengguna', ['id_user' => new_id('u'), 'nama' => $nama, 'role' => 'pic', 'kode_login' => $kode,
    'pin_hash' => hash_pin($pin), 'no_hp' => $data['no_hp'] ?? '', 'aktif' => 'ya', 'dibuat_pada' => now_()['stamp']]);
  log_($user, 'pic_tambah', "$nama ($kode)");
  return public_user($u);
}

/** Mengubah akun PIC/Admin: nama, kode, no HP, peran, status aktif, dan batch yang ditangani. */
function picUbah(array $data, array $user): array {
  $u = row("SELECT * FROM pengguna WHERE id_user = ? AND role IN ('pic', 'admin')", [(string)($data['id_user'] ?? '')]);
  if (!$u) throw new LmsError('Akun PIC tidak ditemukan.');
  $nama = preg_replace('/\s+/', ' ', trim((string)($data['nama'] ?? '')));
  $kode = trim((string)($data['kode'] ?? ''));
  $role = ($data['role'] ?? '') === 'admin' ? 'admin' : 'pic';
  $aktif = !empty($data['aktif']);
  if ($nama === '') throw new LmsError('Nama wajib diisi.');
  if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $kode)) throw new LmsError('Kode login 3–30 karakter (huruf, angka, titik, strip).');
  if (row("SELECT id_user FROM pengguna WHERE role IN ('pic', 'admin') AND LOWER(kode_login) = ? AND id_user <> ?", [strtolower($kode), $u['id_user']])) {
    throw new LmsError('Kode login sudah dipakai akun lain.');
  }
  if ($u['id_user'] === $user['id_user'] && ($role !== 'admin' || !$aktif)) {
    throw new LmsError('Anda tidak bisa menurunkan peran atau menonaktifkan akun Anda sendiri.');
  }
  if ($u['role'] === 'admin' && ($role !== 'admin' || !$aktif)) {
    $lain = row("SELECT COUNT(*) AS n FROM pengguna WHERE role = 'admin' AND aktif <> 'tidak' AND id_user <> ?", [$u['id_user']]);
    if (!(int)$lain['n']) throw new LmsError('Harus ada minimal satu Admin aktif.');
  }
  $u = update('pengguna', $u, ['nama' => $nama, 'kode_login' => $kode, 'no_hp' => trim((string)($data['no_hp'] ?? '')),
    'role' => $role, 'aktif' => $aktif ? 'ya' : 'tidak'] + (array_key_exists('hak_inventaris', $data) ? ['hak_inventaris' => $data['hak_inventaris'] ? 'ya' : ''] : []));
  if (!$aktif) q('DELETE FROM token WHERE id_user = ?', [$u['id_user']]); // langsung keluar dari semua perangkat
  if (isset($data['batch_ids']) && is_array($data['batch_ids'])) {
    $pilih = array_map('strval', $data['batch_ids']);
    foreach (rows("SELECT * FROM batch WHERE status <> 'arsip'") as $b) { // batch arsip tidak tampil di formulir, jadi tidak diubah
      $ids = pic_ids($b);
      $ada = in_array($u['id_user'], $ids, true);
      $mau = in_array($b['id_batch'], $pilih, true);
      if ($ada === $mau) continue;
      $ids = $mau ? array_merge($ids, [$u['id_user']]) : array_values(array_diff($ids, [$u['id_user']]));
      update('batch', $b, ['pic_ids' => implode(',', $ids)]);
    }
  }
  log_($user, 'pic_ubah', "$nama ($kode), $role" . ($aktif ? '' : ', nonaktif'));
  return public_user($u);
}

function picResetPin(array $data, array $user): bool {
  $u = row("SELECT * FROM pengguna WHERE id_user = ? AND role IN ('pic', 'admin')", [(string)($data['id_user'] ?? '')]);
  if (!$u) throw new LmsError('PIC tidak ditemukan.');
  $pin = (string)($data['pin'] ?? '');
  if (!preg_match('/^\d{4,8}$/', $pin)) throw new LmsError('PIN harus 4–8 angka.');
  update('pengguna', $u, ['pin_hash' => hash_pin($pin)]);
  q('DELETE FROM token WHERE id_user = ?', [$u['id_user']]);
  log_($user, 'pic_reset_pin', $u['nama']);
  return true;
}
