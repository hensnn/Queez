<?php
// Veřejné servírování nahraných obrázků: media.php?f=<název>
require __DIR__ . '/../../src/bootstrap.php';
$p = Media::path((string)($_GET['f'] ?? ''));
if (!$p) {
    http_response_code(404);
    exit;
}
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . $types[pathinfo($p, PATHINFO_EXTENSION)]);
header('Cache-Control: public, max-age=86400, immutable');
header('Content-Length: ' . filesize($p));
readfile($p);
