<?php
// POST {pin, key, text} → audio/wav (Gemini TTS, s cache). Jen pro moderátora hry.
require __DIR__ . '/../../src/bootstrap.php';
try {
    if (Http::method() !== 'POST') {
        Http::fail(405, 'method', 'Povolen je jen POST.');
    }
    $in = Http::input();
    $g = Game::requireHost(trim((string)($in['pin'] ?? '')), (string)($in['key'] ?? ''));
    if (!Tts::available($g['s'])) {
        Http::fail(403, 'tts_off', 'Serverové TTS není zapnuté (config.php + Nastavení → TTS).');
    }
    if (RateLimit::hit('tts:' . $g['pin'], 60) > 90) {
        Http::fail(429, 'rate_limit', 'Příliš mnoho požadavků na TTS.');
    }
    $file = Tts::synth((string)($in['text'] ?? ''), $g['s']);
    header('Content-Type: audio/wav');
    header('Content-Length: ' . filesize($file));
    header('Cache-Control: private, max-age=86400');
    readfile($file);
} catch (InvalidArgumentException $e) {
    Http::fail(422, 'invalid', $e->getMessage());
} catch (Throwable $e) {
    error_log((string)$e);
    Http::fail(500, 'server', 'Chyba serveru. Podrobnosti jsou v logu.');
}
