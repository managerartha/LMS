<?php
/** Pintu tunggal API: api(action, data). Daftar aksi & hak akses sama dengan versi Apps Script. */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/batch.php';
require_once __DIR__ . '/absensi.php';
require_once __DIR__ . '/konten.php';
require_once __DIR__ . '/laporan.php';
require_once __DIR__ . '/skl.php';
require_once __DIR__ . '/dasbor.php';
require_once __DIR__ . '/narasumber.php';

/** auth: '' = publik, 'user' = sudah login, 'pic' = PIC/Admin, 'admin' = Admin. */
const ROUTES = [
  'waktu' => '', 'batchAktif' => '', 'loginPeserta' => '', 'loginPic' => '',
  'me' => 'user', 'beranda' => 'user', 'logout' => 'user', 'setuju' => 'user',
  'statusHariIni' => 'user', 'absen' => 'user', 'riwayat' => 'user', 'kontenPeserta' => 'user',
  'gantiPin' => 'pic', 'batchList' => 'pic', 'batchUbah' => 'pic', 'hariList' => 'pic', 'hariSetLokasi' => 'pic',
  'hariResetLokasi' => 'pic', 'lokasiDariTeks' => 'pic', 'pesertaList' => 'pic', 'pesertaTambah' => 'pic', 'pesertaUbah' => 'pic',
  'pesertaResetDevice' => 'pic', 'pesertaAktif' => 'pic', 'monitor' => 'pic', 'absenSetStatus' => 'pic', 'absenFoto' => 'pic',
  'rundownGet' => 'pic', 'rundownSimpan' => 'pic', 'rundownSalin' => 'pic', 'materiList' => 'pic', 'materiTambah' => 'pic',
  'materiHapus' => 'pic', 'dokList' => 'pic', 'dokTambah' => 'pic', 'dokUbah' => 'pic', 'dokHapus' => 'pic',
  'ttdSaya' => 'pic', 'ttdSayaSimpan' => 'pic', 'laporanList' => 'pic', 'laporanBuat' => 'pic', 'laporanUnduh' => 'pic',
  'infoLaporanGet' => 'pic', 'infoLaporanSimpan' => 'pic',
  'sklSaya' => 'user', 'sklUnduh' => 'user', 'sklList' => 'pic', 'sklSimpanData' => 'pic', 'sklTerbitkan' => 'pic', 'sklTahan' => 'pic', 'sklUnduhSemua' => 'pic',
  'batchBuat' => 'admin', 'picList' => 'admin', 'picTambah' => 'admin', 'picResetPin' => 'admin', 'picUbah' => 'admin', 'dasborPic' => 'pic',
  'narasumberList' => 'pic', 'narasumberAbsen' => 'pic', 'narasumberHapus' => 'pic', 'orgSimpan' => 'admin', 'orgTtdSimpan' => 'admin',
];

function waktu(): array { return ['now' => lms_ms()]; }

function api($action, $data): array {
  try {
    $action = (string)$action;
    if (!array_key_exists($action, ROUTES)) throw new LmsError('Aksi tidak dikenal: ' . $action);
    $data = is_array($data) ? $data : [];
    if ($action !== 'waktu') pastikan_skema();
    $auth = ROUTES[$action];
    $user = null;
    if ($auth !== '') {
      $user = user_from_token($data['token'] ?? '');
      if ($auth === 'pic' && !is_pic($user)) throw new LmsError('Menu ini khusus PIC/Admin.');
      if ($auth === 'admin' && $user['role'] !== 'admin') throw new LmsError('Menu ini khusus Admin.');
    }
    return ['ok' => true, 'data' => $auth === '' ? $action($data) : $action($data, $user)];
  } catch (LmsError $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  } catch (Throwable $e) {
    error_log('LMS ' . $action . ': ' . $e);
    return ['ok' => false, 'error' => 'Terjadi kesalahan di server. Coba lagi, atau hubungi Admin. (' . $e->getMessage() . ')'];
  }
}

/** Pengecekan pemasangan: dibuka lewat api/?cek=1 */
function cek(): array {
  $out = ['ok' => false, 'api' => 'LMS Artha Safety', 'versi' => API_VERSI, 'php' => PHP_VERSION];
  try {
    config();
    pastikan_skema();
    $tabel = [];
    foreach (array_keys(SCHEMA) as $t) {
      try { q("SELECT 1 FROM `$t` LIMIT 1"); } catch (Throwable $e) { $tabel[] = $t; }
    }
    $out['database'] = $tabel ? 'tabel belum lengkap: ' . implode(', ', $tabel) : 'ok';
    $out['folder_storage_bisa_ditulis'] = is_writable(STORAGE);
    $out['folder_uploads_bisa_ditulis'] = is_writable(UPLOADS);
    $out['pembuat_pdf'] = is_file(ROOT . '/vendor/autoload.php') ? 'ok' : 'folder vendor tidak ada';
    $out['ok'] = !$tabel && $out['folder_storage_bisa_ditulis'] && $out['folder_uploads_bisa_ditulis'] && $out['pembuat_pdf'] === 'ok';
  } catch (Throwable $e) {
    $out['error'] = $e->getMessage();
  }
  return $out;
}
