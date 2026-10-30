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
echo json_encode(api($req['action'] ?? null, $req['data'] ?? []), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
