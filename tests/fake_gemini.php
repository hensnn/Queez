<?php
/**
 * Falešný Gemini server pro testy bez skutečného API klíče.
 *
 *   php -S 127.0.0.1:8001 tests/fake_gemini.php
 *
 * V config.php vývojové instalace přidejte:
 *   'gemini_base_url' => 'http://127.0.0.1:8001',
 * a v adminu (Nastavení → Gemini) uložte libovolný klíč (20+ znaků). Chování podle začátku klíče:
 *   badkey…     → HTTP 400 „API key not valid“
 *   ratelimit…  → HTTP 429
 *   garbage…    → odpověď, která není JSON
 *   jiný        → generování otázek / test klíče / audio (TTS) s vymyšlenými daty
 * Poslední požadavek se ukládá do tests/last_request.json (kontrola promptu a hlavičky x-goog-api-key),
 * počet volání TTS do tests/tts_calls.txt (ověření cache).
 */
header('Content-Type: application/json');
$key = $_SERVER['HTTP_X_GOOG_API_KEY'] ?? '';
$body = file_get_contents('php://input');
file_put_contents(__DIR__ . '/last_request.json', json_encode([
    'uri' => $_SERVER['REQUEST_URI'], 'key_header' => $key, 'body' => json_decode($body, true),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

if (str_starts_with($key, 'badkey')) {
    http_response_code(400);
    echo json_encode(['error' => ['code' => 400, 'message' => 'API key not valid. Please pass a valid API key.']]);
    exit;
}
if (str_starts_with($key, 'ratelimit')) {
    http_response_code(429);
    echo json_encode(['error' => ['message' => 'Resource exhausted']]);
    exit;
}
if (str_starts_with($key, 'garbage')) {
    echo json_encode(['candidates' => [['content' => ['parts' => [['text' => 'tohle neni json {{{']]], 'finishReason' => 'STOP']]]);
    exit;
}

$req = json_decode($body, true);

// TTS: žádost o audio → krátké „PCM“ (0,2 s při 24 kHz)
if (isset($req['generationConfig']['responseModalities'])) {
    $n = (int)@file_get_contents(__DIR__ . '/tts_calls.txt');
    file_put_contents(__DIR__ . '/tts_calls.txt', (string)($n + 1));
    $pcm = str_repeat(pack('v', 1000) . pack('v', 64536), 2400);
    echo json_encode(['candidates' => [['content' => ['parts' => [['inlineData' => [
        'mimeType' => 'audio/L16;codec=pcm;rate=24000', 'data' => base64_encode($pcm)]]]]]]]);
    exit;
}

$prompt = $req['contents'][0]['parts'][0]['text'] ?? '';

// Test klíče
if (str_contains($prompt, 'Odpověz jedním slovem')) {
    echo json_encode(['candidates' => [['content' => ['parts' => [['text' => 'OK']]], 'finishReason' => 'STOP']]]);
    exit;
}

// Generování otázek: záměrně i s vadami (víc možností, špatný typ, duplicita, otázka bez správné odpovědi)
$qs = [
    ['type' => 'choice', 'text' => 'Kolik je 2+2?', 'explanation' => 'Základní sčítání.',
     'options' => [['text' => '3', 'correct' => false], ['text' => '4', 'correct' => true], ['text' => '5', 'correct' => false], ['text' => '6', 'correct' => false], ['text' => '7', 'correct' => false]]],
    ['type' => 'truefalse', 'text' => 'Země je kulatá.', 'options' => [['text' => 'Ano', 'correct' => true], ['text' => 'Ne', 'correct' => false]]],
    ['type' => 'poll', 'text' => 'Oblíbené jídlo?', 'options' => [['text' => 'Svíčková', 'correct' => true], ['text' => 'Pizza', 'correct' => false]]],
    ['type' => 'choice', 'text' => 'Otázka bez správné odpovědi', 'options' => [['text' => 'a', 'correct' => false], ['text' => 'b', 'correct' => false]]],
    ['type' => 'choice', 'text' => 'kolik je 2+2?', 'options' => [['text' => '4', 'correct' => true], ['text' => '5', 'correct' => false]]],
];
// přegenerování jedné otázky (prompt obsahuje „Vytvoř 1 otázek“)
if (str_contains($prompt, 'Vytvoř 1 otázek')) {
    $qs = [['type' => 'choice', 'text' => 'Nová otázka po přegenerování?', 'options' => [['text' => 'Ano', 'correct' => true], ['text' => 'Ne', 'correct' => false]]]];
}
echo json_encode(['candidates' => [['content' => ['parts' => [['text' => json_encode(['questions' => $qs], JSON_UNESCAPED_UNICODE)]]], 'finishReason' => 'STOP']]]);
