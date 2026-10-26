<?php
// GET ?id=N = jeden kvíz s otázkami; POST = uložení celého kvízu
require __DIR__ . '/../../src/bootstrap.php';
Http::run(function () {
    Auth::require();
    if (Http::method() === 'GET') {
        $q = Quiz::get((int)($_GET['id'] ?? 0));
        if (!$q) {
            Http::fail(404, 'not_found', 'Kvíz neexistuje.');
        }
        return $q;
    }
    return ['id' => Quiz::save(Http::input())];
});
