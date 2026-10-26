<?php
require __DIR__ . '/../../src/bootstrap.php';
Http::run(function () {
    if (Http::method() !== 'POST') {
        Http::fail(405, 'method', 'Povolen je jen POST.');
    }
    $in = Http::input();
    return Auth::login((string)($in['password'] ?? ''));
});
