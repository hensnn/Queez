<?php
require __DIR__ . '/../../src/bootstrap.php';
Http::run(function () {
    Auth::require();
    if (Http::method() !== 'POST' || empty($_FILES['file'])) {
        throw new InvalidArgumentException('Chybí soubor.');
    }
    return ['file' => Media::saveUpload($_FILES['file'])];
});
