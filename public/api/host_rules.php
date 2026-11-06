<?php
// GET ?pin=PIN&key=KLÍČ → pravidla hry (definice, platné hodnoty, zda jdou upravit)
// POST {pin, key, values:{klíč:hodnota,…}} → uloží změněná pravidla (jen v čekárně nebo při pauze)
require __DIR__ . '/../../src/bootstrap.php';
Http::run(function () {
    if (Http::method() === 'POST') {
        $in = Http::input();
        $g = Game::requireHost(trim((string)($in['pin'] ?? '')), (string)($in['key'] ?? ''));
        $changed = Game::updateRules($g, $in['values'] ?? null);
        return ['ok' => true, 'changed' => $changed, 'rules' => Game::rules(Game::byId((int)$g['id']))];
    }
    $g = Game::requireHost(trim((string)($_GET['pin'] ?? '')), (string)($_GET['key'] ?? ''));
    return Game::rules($g);
});
