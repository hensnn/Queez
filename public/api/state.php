<?php
// GET ?game=PIN&role=player|host&token=…&since=VERZE
// Lehký endpoint pro polling. Když se nic nezměnilo, vrací jen {"changed":false,...}.
require __DIR__ . '/../../src/bootstrap.php';
Http::run(function () {
    $pin = (string)($_GET['game'] ?? '');
    $role = (($_GET['role'] ?? '') === 'host') ? 'host' : 'player';
    $token = (string)($_GET['token'] ?? '');
    $since = isset($_GET['since']) ? (int)$_GET['since'] : -1;

    if (!preg_match('/^\d{4,8}$/', $pin)) {
        Http::fail(404, 'no_game', 'Hra neexistuje.');
    }
    $g = ($role === 'host') ? Game::requireHost($pin, $token) : Game::byPin($pin);
    if (!$g) {
        Http::fail(404, 'no_game', 'Hra neexistuje.');
    }

    $player = null;
    if ($role === 'player') {
        $player = Db::one('SELECT id, kicked, last_seen FROM players WHERE token=? AND game_id=?', [$token, $g['id']]);
        if (!$player) {
            Http::fail(401, 'bad_token', 'Hráč nebyl nalezen.');
        }
        if ($player['kicked']) {
            Http::fail(403, 'kicked', 'Byli jste odebráni ze hry.');
        }
        if (time() - (int)$player['last_seen'] > 20) {
            Db::exec('UPDATE players SET last_seen=? WHERE id=?', [time(), $player['id']]);
        }
    }

    $g = Game::tick($g);
    if ($since === (int)$g['version']) {
        return ['changed' => false, 'version' => (int)$g['version'], 'server_time' => now_ms()];
    }
    return Game::state($g, $role, $player ? (int)$player['id'] : null);
});
