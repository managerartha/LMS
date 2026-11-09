<?php
/**
 * Perangkat peserta: maksimal 2 perangkat per akun (mis. HP + laptop) untuk mencegah titip absen.
 * Jenis perangkat (HP/Laptop/Tablet), sistem & browser dibaca dari User-Agent browser saat login dan absen.
 * Akun lama yang sudah terkunci ke 1 perangkat (pengguna.device_id) tetap dihormati sebagai perangkat pertama.
 */

const MAKS_PERANGKAT = 2;

/** 'Mozilla/5.0 (Linux; Android 13; ...) ... Chrome/120 Mobile' → ['jenis' => 'HP', 'os' => 'Android', 'browser' => 'Chrome'] */
function info_perangkat(string $ua): array {
  $os = preg_match('/iPhone|iPod/i', $ua) ? 'iPhone' : (preg_match('/iPad/i', $ua) ? 'iPad'
    : (preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/CrOS/i', $ua) ? 'ChromeOS'
    : (preg_match('/Windows/i', $ua) ? 'Windows' : (preg_match('/Mac OS X|Macintosh/i', $ua) ? 'macOS'
    : (preg_match('/Linux/i', $ua) ? 'Linux' : ''))))));
  $jenis = preg_match('/iPad|Tablet/i', $ua) || ($os === 'Android' && !preg_match('/Mobile/i', $ua)) ? 'Tablet'
    : (preg_match('/Mobi|iPhone|iPod|Android/i', $ua) ? 'HP' : ($ua === '' ? '' : 'Laptop/PC'));
  $browser = preg_match('/Edg(e|A|iOS)?\//', $ua) ? 'Edge' : (preg_match('/OPR\/|Opera/', $ua) ? 'Opera'
    : (preg_match('/SamsungBrowser/', $ua) ? 'Samsung Internet' : (preg_match('/FxiOS|Firefox\//', $ua) ? 'Firefox'
    : (preg_match('/CriOS|Chrome\//', $ua) ? 'Chrome' : (preg_match('/Safari\//', $ua) ? 'Safari' : '')))));
  return ['jenis' => $jenis, 'os' => $os, 'browser' => $browser];
}

function ua_(): string {
  return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400);
}

/** 'Laptop/PC · Windows · Chrome' (kosong jika tidak diketahui). */
function label_perangkat(array $p): string {
  return implode(' · ', array_filter([$p['jenis'] ?? '', $p['os'] ?? '', $p['browser'] ?? '']));
}

/** Perangkat terdaftar milik peserta; perangkat lama dari sebelum fitur ini ditampilkan sebagai "belum diketahui". */
function perangkat_user(array $u): array {
  $list = rows('SELECT * FROM perangkat WHERE id_user = ? ORDER BY pertama', [$u['id_user']]);
  if ($u['device_id'] !== '' && !in_array($u['device_id'], array_column($list, 'device_id'), true)) {
    array_unshift($list, ['id_perangkat' => '', 'id_user' => $u['id_user'], 'device_id' => $u['device_id'], 'jenis' => '', 'os' => '',
      'browser' => '', 'pertama' => '', 'terakhir' => '']);
  }
  return $list;
}

function perangkat_view(array $p): array {
  return ['id_perangkat' => $p['id_perangkat'], 'jenis' => $p['jenis'], 'label' => label_perangkat($p) ?: 'Perangkat (jenis belum tercatat)',
    'pertama' => $p['pertama'], 'terakhir' => $p['terakhir']];
}

/**
 * Dipanggil saat login peserta: perangkat dikenal → catat waktu & jenis; perangkat baru → didaftarkan jika
 * belum 2 perangkat; jika sudah penuh → ditolak.
 */
function daftarkan_perangkat(array $u, string $deviceId): array {
  $now = now_()['stamp'];
  $info = info_perangkat(ua_());
  $list = perangkat_user($u);
  foreach ($list as $p) {
    if ($p['device_id'] !== $deviceId) continue;
    if ($p['id_perangkat'] === '') { // perangkat lama: sekarang jenisnya ikut dicatat
      insert('perangkat', array_merge($info, ['id_perangkat' => new_id('d'), 'id_user' => $u['id_user'], 'device_id' => $deviceId,
        'pertama' => $now, 'terakhir' => $now]));
    } else {
      update('perangkat', $p, array_merge($info, ['terakhir' => $now]));
    }
    return $u;
  }
  if (count($list) >= MAKS_PERANGKAT) {
    throw new LmsError('Akun ini sudah terhubung ke ' . MAKS_PERANGKAT . ' perangkat (' .
      implode(' & ', array_map(fn($p) => perangkat_view($p)['label'], $list)) . '). Jika Anda ganti perangkat, minta PIC melepas perangkat lama.');
  }
  foreach ($list as $p) {
    // perangkat lama yang belum tercatat di daftar disimpan dulu agar tetap terhitung
    if ($p['id_perangkat'] === '') insert('perangkat', ['id_perangkat' => new_id('d'), 'id_user' => $u['id_user'], 'device_id' => $p['device_id'],
      'jenis' => '', 'os' => '', 'browser' => '', 'pertama' => '', 'terakhir' => '']);
  }
  insert('perangkat', array_merge($info, ['id_perangkat' => new_id('d'), 'id_user' => $u['id_user'], 'device_id' => $deviceId,
    'pertama' => $now, 'terakhir' => $now]));
  if ($u['device_id'] === '') $u = update('pengguna', $u, ['device_id' => $deviceId]);
  return $u;
}

/** PIC melepas satu perangkat peserta (mis. laptop diganti); sesi login di perangkat itu ikut berakhir. */
function perangkatLepas(array $data, array $user): bool {
  $u = peserta_for_pic($user, (string)($data['id_user'] ?? ''));
  $p = row('SELECT * FROM perangkat WHERE id_perangkat = ? AND id_user = ?', [(string)($data['id_perangkat'] ?? ''), $u['id_user']]);
  $deviceId = $p ? $p['device_id'] : (($data['id_perangkat'] ?? '') === '' ? $u['device_id'] : '');
  if ($deviceId === '') throw new LmsError('Perangkat tidak ditemukan.');
  if ($p) hapus('perangkat', $p);
  $pertama = $u['device_id'] === $deviceId;
  if ($pertama) {
    $sisa = row('SELECT device_id FROM perangkat WHERE id_user = ? ORDER BY pertama LIMIT 1', [$u['id_user']]);
    update('pengguna', $u, ['device_id' => $sisa ? $sisa['device_id'] : '']);
  }
  q('DELETE FROM token WHERE id_user = ? AND device_id = ?', [$u['id_user'], $deviceId]);
  // sesi login dari sebelum fitur ini (tanpa catatan perangkat) milik perangkat pertama
  if ($pertama) q("DELETE FROM token WHERE id_user = ? AND (device_id = '' OR device_id IS NULL)", [$u['id_user']]);
  log_($user, 'peserta_lepas_perangkat', $u['kode_login'] . ' ' . ($p ? label_perangkat($p) : 'perangkat lama'));
  return true;
}
