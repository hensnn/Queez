<?php
// GET ?game=PIN → pravidla hry jen ke čtení pro hráče (bez klíče moderátora; nastavení nejsou tajná)
require __DIR__ . '/../../src/bootstrap.php';
Http::run(function () {
    $pin = (string)($_GET['game'] ?? '');
    if (!preg_match('/^\d{4,8}$/', $pin)) {
        Http::fail(404, 'no_game', 'Hra neexistuje.');
    }
    $g = Game::byPin($pin);
    if (!$g) {
        Http::fail(404, 'no_game', 'Hra neexistuje.');
    }
    return Game::rules($g, true);
});
