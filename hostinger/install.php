<?php
/**
 * Installer LMS Artha Safety Indonesia (sekali jalan).
 * Mengecek kesiapan hosting, menyimpan koneksi database ke app/config.php, membuat tabel, dan akun Admin.
 * Setelah berhasil, hapus file ini dari File Manager.
 */
declare(strict_types=1);
define('ROOT', __DIR__);
require __DIR__ . '/app/schema.php';

const ADMIN_PIN_AWAL = '123456';
$configFile = __DIR__ . '/app/config.php';

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$cek = [
  ['PHP versi 8.1 atau lebih baru', version_compare(PHP_VERSION, '8.1.0', '>='), 'Versi Anda: ' . PHP_VERSION . '. Ubah di hPanel → Advanced → PHP Configuration.'],
  ['Ekstensi pdo_mysql', extension_loaded('pdo_mysql'), 'Aktifkan di hPanel → PHP Configuration → PHP Extensions.'],
  ['Ekstensi mbstring', extension_loaded('mbstring'), 'Aktifkan di hPanel → PHP Configuration → PHP Extensions.'],
  ['Ekstensi gd (gambar di PDF)', extension_loaded('gd'), 'Aktifkan di hPanel → PHP Configuration → PHP Extensions.'],
  ['Ekstensi dom (pembuat PDF)', extension_loaded('dom'), 'Aktifkan di hPanel → PHP Configuration → PHP Extensions.'],
  ['Ekstensi curl (alamat lokasi)', extension_loaded('curl'), 'Aktifkan di hPanel → PHP Configuration → PHP Extensions.'],
  ['Folder vendor (pembuat PDF) ada', is_file(__DIR__ . '/vendor/autoload.php'), 'Upload ulang ZIP lalu Extract semua isinya.'],
  ['Folder storage bisa ditulis', is_writable(__DIR__ . '/storage'), 'File Manager → klik kanan folder storage → Permissions → 755.'],
  ['Folder uploads bisa ditulis', is_writable(__DIR__ . '/uploads'), 'File Manager → klik kanan folder uploads → Permissions → 755.'],
  ['Folder app bisa ditulis (untuk config.php)', is_writable(__DIR__ . '/app'), 'File Manager → klik kanan folder app → Permissions → 755.'],
];
$siap = !in_array(false, array_column($cek, 1), true);
$sudah = is_file($configFile);
$pesan = '';
$sukses = false;

if (!$sudah && $siap && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $c = ['driver' => 'mysql', 'host' => trim($_POST['host'] ?? 'localhost'), 'name' => trim($_POST['name'] ?? ''),
    'user' => trim($_POST['user'] ?? ''), 'pass' => (string)($_POST['pass'] ?? '')];
  try {
    if ($c['name'] === '' || $c['user'] === '') throw new Exception('Nama database dan username wajib diisi.');
    $pdo = new PDO('mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=utf8mb4', $c['user'], $c['pass'],
      [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    foreach (schema_sql('mysql') as $sql) {
      try { $pdo->exec($sql); } catch (PDOException $ex) {
        if (stripos($sql, 'CREATE INDEX') !== 0) throw $ex; // index yang sudah ada boleh dilewati
      }
    }
    $admin = $pdo->query("SELECT COUNT(*) FROM pengguna WHERE role = 'admin'")->fetchColumn();
    if (!(int)$admin) {
      $st = $pdo->prepare('INSERT INTO pengguna (id_user, nama, role, kode_login, pin_hash, aktif, dibuat_pada) VALUES (?, ?, ?, ?, ?, ?, ?)');
      $st->execute(['u' . bin2hex(random_bytes(6)), 'Admin', 'admin', 'ADMIN', password_hash(ADMIN_PIN_AWAL, PASSWORD_DEFAULT), 'ya',
        (new DateTime('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s')]);
    }
    if (file_put_contents($configFile, "<?php\n// Koneksi database LMS (dibuat oleh install.php). Jangan dibagikan.\nreturn " . var_export($c, true) . ";\n") === false) {
      throw new Exception('app/config.php tidak bisa ditulis. Ubah permission folder app menjadi 755.');
    }
    $sukses = true;
    $sudah = true;
  } catch (Throwable $ex) {
    $msg = $ex->getMessage();
    if (str_contains($msg, 'Access denied')) $msg = 'Username atau password database salah, atau user belum diberi akses ke database ini.';
    elseif (str_contains($msg, 'Unknown database')) $msg = 'Nama database tidak ditemukan. Periksa lagi nama lengkapnya (biasanya diawali u123456789_).';
    elseif (str_contains($msg, 'getaddrinfo') || str_contains($msg, 'Connection refused')) $msg = 'Host database tidak bisa dihubungi. Untuk Hostinger, isi: localhost.';
    $pesan = $msg;
  }
}
?><!DOCTYPE html>
<html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pasang LMS Artha Safety</title>
<style>
  body { margin: 0; background: #f5f6fb; color: #1b1e4f; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
  .wrap { max-width: 640px; margin: 0 auto; padding: 24px 16px 48px; }
  img { height: 34px; }
  .card { background: #fff; border: 1px solid #e2e4ef; border-radius: 16px; padding: 20px; margin-top: 16px; }
  h1 { font-size: 22px; margin: 16px 0 4px; } h2 { font-size: 17px; margin: 0 0 10px; }
  .muted { color: #5d6280; font-size: 14px; }
  li { margin: 6px 0; } .ok { color: #12805c; } .no { color: #b42318; }
  label { display: block; font-weight: 600; font-size: 14px; margin: 12px 0 4px; }
  input { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #cdd3dd; border-radius: 9px; font: inherit; }
  button, .btn { display: inline-block; margin-top: 16px; background: #1b1e4f; color: #fff; border: 0; border-radius: 10px; padding: 12px 18px; font: 600 15px inherit; cursor: pointer; text-decoration: none; }
  .alert { padding: 12px 14px; border-radius: 10px; margin-top: 12px; }
  .bad { background: #fde9e7; color: #b42318; } .good { background: #e3fbf2; color: #0d6b4b; } .warn { background: #fff2df; color: #8a4b00; }
  code { background: #f1f2f8; padding: 1px 6px; border-radius: 6px; }
</style></head>
<body><div class="wrap">
  <img src="img/logo.png" alt="Artha Safety Indonesia">
  <h1>Pasang LMS</h1>
  <p class="muted">Halaman ini hanya dipakai sekali saat pemasangan pertama.</p>

  <?php if ($sukses || $sudah): ?>
    <div class="card">
      <h2><?= $sukses ? '✅ Pemasangan berhasil' : 'LMS sudah terpasang' ?></h2>
      <p>Login pertama sebagai Admin:</p>
      <ul><li>Buka LMS → pilih tab <b>PIC / Admin</b></li><li>Kode: <code>ADMIN</code> · PIN: <code><?= ADMIN_PIN_AWAL ?></code></li><li>Segera ganti PIN lewat tombol <b>PIN</b> di kanan atas.</li></ul>
      <div class="alert warn"><b>Penting:</b> hapus file <code>install.php</code> dari File Manager sekarang (klik kanan → Delete), agar halaman ini tidak bisa dibuka orang lain.</div>
      <a class="btn" href="./">Buka LMS →</a>
      <a class="btn" style="background:#5d6280" href="api/?cek=1" target="_blank">Cek pemasangan</a>
    </div>
  <?php else: ?>
    <div class="card">
      <h2>1. Kesiapan hosting</h2>
      <ul>
        <?php foreach ($cek as $c): ?>
          <li class="<?= $c[1] ? 'ok' : 'no' ?>"><?= $c[1] ? '✅' : '❌' ?> <?= e($c[0]) ?><?= $c[1] ? '' : '<br><span class="muted">' . e($c[2]) . '</span>' ?></li>
        <?php endforeach; ?>
      </ul>
      <?php if (!$siap): ?><div class="alert bad">Perbaiki dulu yang bertanda ❌, lalu muat ulang halaman ini.</div><?php endif; ?>
    </div>
    <?php if ($siap): ?>
    <form class="card" method="post">
      <h2>2. Data database MySQL</h2>
      <p class="muted">Ambil dari hPanel → <b>Databases</b> → <b>Management</b>. Nama database dan username biasanya diawali <code>u123456789_</code>.</p>
      <?php if ($pesan): ?><div class="alert bad"><?= e($pesan) ?></div><?php endif; ?>
      <label for="host">Host database</label><input id="host" name="host" value="<?= e($_POST['host'] ?? 'localhost') ?>">
      <label for="name">Nama database</label><input id="name" name="name" value="<?= e($_POST['name'] ?? '') ?>" placeholder="u123456789_lms" required>
      <label for="user">Username database</label><input id="user" name="user" value="<?= e($_POST['user'] ?? '') ?>" placeholder="u123456789_lmsadmin" required>
      <label for="pass">Password database</label><input id="pass" name="pass" type="password" required>
      <button type="submit">Pasang LMS</button>
    </form>
    <?php endif; ?>
  <?php endif; ?>
</div></body></html>
