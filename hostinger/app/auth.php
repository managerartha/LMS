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
  ];
}

function batchAktif(): array {
  // Batch yang sudah selesai tetap muncul jika ada SKL terbit, agar peserta bisa login untuk mengunduh SKL.
  return array_map(fn($b) => ['id_batch' => $b['id_batch'], 'nama_pelatihan' => $b['nama_pelatihan'], 'kode_batch' => $b['kode_batch'],
    'selesai' => $b['status'] !== 'aktif'],
    rows("SELECT * FROM batch WHERE status = 'aktif' OR id_batch IN (SELECT id_batch FROM skl WHERE status = 'terbit')
      ORDER BY CASE WHEN status = 'aktif' THEN 0 ELSE 1 END, tgl_mulai DESC"));
}

function buat_token(array $u, int $simpan): string {
  $token = new_token();
  insert('token', ['token' => $token, 'id_user' => $u['id_user'], 'dibuat_pada' => now_()['stamp']]);
  // Sisakan token terbaru saja.
  $lama = rows('SELECT token FROM token WHERE id_user = ? ORDER BY dibuat_pada DESC, token', [$u['id_user']]);
  $buang = array_filter($lama, fn($r) => $r['token'] !== $token);
  foreach (array_slice(array_values($buang), max(0, $simpan - 1)) as $r) q('DELETE FROM token WHERE token = ?', [$r['token']]);
  return $token;
}

function loginPeserta(array $data): array {
  $kode = strtolower(trim((string)($data['kode'] ?? '')));
  $deviceId = (string)($data['device_id'] ?? '');
  if ($kode === '') throw new LmsError('Kode wajib diisi.');
  if ($deviceId === '') throw new LmsError('Perangkat tidak dikenali. Muat ulang halaman.');
  $batch = get_batch((string)($data['id_batch'] ?? ''));
  $u = row("SELECT * FROM pengguna WHERE role = 'peserta' AND id_batch = ? AND LOWER(kode_login) = ?", [$batch['id_batch'], $kode]);
  if ($batch['status'] !== 'aktif' && !($u && (skl_of($u['id_user'])['status'] ?? '') === 'terbit')) {
    throw new LmsError('Batch ini sudah tidak aktif.');
  }
  if (!$u || $u['aktif'] === 'tidak') throw new LmsError('Kode tidak ditemukan di batch ini. Periksa kembali kode Anda.');
  if ($u['device_id'] !== '' && $u['device_id'] !== $deviceId) {
    throw new LmsError('Akun ini sudah terhubung ke HP lain. Jika Anda ganti HP, minta PIC menekan "Reset HP".');
  }
  $u = update('pengguna', $u, ['device_id' => $deviceId]);
  $token = buat_token($u, 1);
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
    if ($user['setuju_privasi'] !== '') $out['beranda'] = beranda($data, $user);
  } else {
    $out['batches'] = batchList($data, $user);
  }
  return $out;
}

/** Status absen hari ini + konten (rundown, materi, dokumentasi) dalam satu permintaan. */
function beranda(array $data, array $user): array {
  return ['status' => statusHariIni($data, $user), 'konten' => kontenPeserta($data, $user), 'skl' => skl_view(skl_of($user['id_user']))];
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
    'role' => $u['role'], 'no_hp' => $u['no_hp'], 'aktif' => $u['aktif']],
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
