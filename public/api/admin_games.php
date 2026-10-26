<?php
// GET = seznam her; POST {action: create, quiz_id, mode, settings:{…}} | {action: delete, id}
require __DIR__ . '/../../src/bootstrap.php';
Http::run(function () {
    Auth::require();
    if (Http::method() === 'POST') {
        $in = Http::input();
        switch ($in['action'] ?? '') {
            case 'create':
                return Game::create((int)($in['quiz_id'] ?? 0), (string)($in['mode'] ?? 'screen'),
                    is_array($in['settings'] ?? null) ? $in['settings'] : []);
            case 'delete':
                Db::exec('DELETE FROM games WHERE id=?', [(int)($in['id'] ?? 0)]);
                break;
            default:
                throw new InvalidArgumentException('Neznámá akce.');
        }
    }
    $rows = Db::all('SELECT id, pin, host_token, mode, phase, players_count, created_at, settings_json
                     FROM games ORDER BY created_at DESC, id DESC LIMIT 100');
    foreach ($rows as &$r) {
        $r['title'] = (json_decode($r['settings_json'], true)['_title'] ?? '');
        unset($r['settings_json']);
    }
    return ['games' => $rows];
});
