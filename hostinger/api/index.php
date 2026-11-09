<?php
/** API LMS: POST JSON {action, data} → JSON {ok, data|error}. GET ?cek=1 untuk pengecekan pemasangan. */
require __DIR__ . '/../app/api.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(isset($_GET['cek']) ? cek() : ['ok' => true, 'api' => 'LMS Artha Safety'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
  exit;
}
$req = json_decode((string)file_get_contents('php://input'), true);
$req = is_array($req) ? $req : [];
$out = json_encode(api($req['action'] ?? null, $req['data'] ?? []), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
$tugas = setelah_respon();
if (!$tugas) { echo $out; exit; }
// Ada tugas latar belakang: kirim jawaban dulu dan tutup koneksi, baru kerjakan tugasnya.
ignore_user_abort(true);
@set_time_limit(300);
header('Content-Length: ' . strlen($out));
header('Connection: close');
echo $out;
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
else { while (ob_get_level()) ob_end_flush(); flush(); }
foreach ($tugas as $t) { try { $t(); } catch (Throwable $e) { error_log('LMS tugas latar: ' . $e); } }
