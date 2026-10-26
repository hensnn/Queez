<?php
require __DIR__ . '/../../src/bootstrap.php';
Auth::require();
$id = (int)($_GET['id'] ?? 0);
$data = Quiz::export($id);
if (!$data) {
    Http::fail(404, 'not_found', 'Kvíz neexistuje.');
}
$fn = preg_replace('/[^A-Za-z0-9_-]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $data['title']) ?: 'kviz');
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="kviz-' . trim($fn, '_') . '.json"');
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
