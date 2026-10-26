<?php
// POST {pin, name} → {token, name, pin}
require __DIR__ . '/../../src/bootstrap.php';
Http::run(function () {
    if (Http::method() !== 'POST') {
        Http::fail(405, 'method', 'Povolen je jen POST.');
    }
    // V hospodě sedí všichni na jedné wifi (jedna veřejná IP), proto štědrý limit.
    if (RateLimit::hit('join:' . Net::clientIp(), 300) > 150) {
        Http::fail(429, 'rate_limit', 'Příliš mnoho pokusů, zkuste to za chvíli.');
    }
    $in = Http::input();
    return Game::join(trim((string)($in['pin'] ?? '')), (string)($in['name'] ?? ''));
});
