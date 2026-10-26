<?php
// POST {pin, token, choice:[pozice,…]}
require __DIR__ . '/../../src/bootstrap.php';
Http::run(function () {
    if (Http::method() !== 'POST') {
        Http::fail(405, 'method', 'Povolen je jen POST.');
    }
    $in = Http::input();
    $g = Game::byPin(trim((string)($in['pin'] ?? '')));
    $token = (string)($in['token'] ?? '');
    if (!$g) {
        Http::fail(404, 'no_game', 'Hra neexistuje.');
    }
    $p = Db::one('SELECT id, kicked FROM players WHERE token=? AND game_id=?', [$token, $g['id']]);
    if (!$p || $p['kicked']) {
        Http::fail(401, 'bad_token', 'Hráč nebyl nalezen.');
    }
    if (RateLimit::hit('ans:' . $token, 10) > 20) {
        Http::fail(429, 'rate_limit', 'Příliš rychle, zpomalte.');
    }
    Game::answer($g, $p, $in['choice'] ?? null);
    return ['ok' => true];
});
