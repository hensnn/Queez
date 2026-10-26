<?php
// GET = seznam kvízů; POST {action: delete|duplicate|import, id | data}
require __DIR__ . '/../../src/bootstrap.php';
Http::run(function () {
    Auth::require();
    if (Http::method() === 'GET') {
        return ['quizzes' => Quiz::list()];
    }
    $in = Http::input();
    switch ($in['action'] ?? '') {
        case 'delete':
            Quiz::delete((int)($in['id'] ?? 0));
            return ['ok' => true];
        case 'duplicate':
            return ['id' => Quiz::duplicate((int)($in['id'] ?? 0))];
        case 'import':
            if (!is_array($in['data'] ?? null)) {
                throw new InvalidArgumentException('Chybí data k importu.');
            }
            return ['id' => Quiz::import($in['data'])];
    }
    throw new InvalidArgumentException('Neznámá akce.');
});
