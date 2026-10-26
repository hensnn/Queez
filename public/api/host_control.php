<?php
// POST {pin, key, action: start|next|pause|resume|finish|kick, player_id?}
require __DIR__ . '/../../src/bootstrap.php';
Http::run(function () {
    if (Http::method() !== 'POST') {
        Http::fail(405, 'method', 'Povolen je jen POST.');
    }
    $in = Http::input();
    $g = Game::requireHost(trim((string)($in['pin'] ?? '')), (string)($in['key'] ?? ''));
    $action = (string)($in['action'] ?? '');
    if ($action === 'kick') {
        Game::kick($g, (int)($in['player_id'] ?? 0));
    } else {
        Game::control($g, $action);
    }
    return ['ok' => true];
});
