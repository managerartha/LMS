<?php
/** Mengunduh file materi dengan nama aslinya: api/unduh.php?id=<id_materi> */
require __DIR__ . '/../app/api.php';

try {
  $m = row('SELECT * FROM materi WHERE id_materi = ?', [(string)($_GET['id'] ?? '')]);
  $f = $m && $m['file_id'] !== '' && !str_contains($m['file_id'], '..') ? ROOT . '/' . $m['file_id'] : '';
  if (!$f || !is_file($f)) throw new LmsError('File tidak ditemukan.');
  $nama = preg_replace('/[^A-Za-z0-9 ._()-]+/', '-', $m['judul']) . '.' . $m['tipe_file'];
  header('Content-Type: application/octet-stream');
  header('Content-Disposition: attachment; filename="' . $nama . '"');
  header('Content-Length: ' . filesize($f));
  readfile($f);
} catch (Throwable $e) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=utf-8');
  echo 'File tidak ditemukan.';
}
