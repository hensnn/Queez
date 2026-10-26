<?php
// POST {action: generate|regenerate|preview|test, …parametry z formuláře}
// generate → {questions:[…], warnings:[…]} (jen NÁVRH, nic se neukládá)
require __DIR__ . '/../../src/bootstrap.php';
Http::run(function () {
    Auth::require();
    if (Http::method() !== 'POST') {
        Http::fail(405, 'method', 'Povolen je jen POST.');
    }
    $in = Http::input();
    switch ($in['action'] ?? '') {
        case 'test':
            return Gemini::test();
        case 'preview':
            return ['prompt' => Gemini::buildPrompt(Gemini::cleanParams($in))];
        case 'generate':
            return Gemini::generate(Gemini::cleanParams($in));
        case 'regenerate':   // jedna nová otázka na místo zamítnuté
            $in['count'] = 1;
            if (!empty($in['type']) && in_array($in['type'], ['choice', 'truefalse', 'poll'], true)) {
                $in['types'] = [$in['type']];
            }
            $r = Gemini::generate(Gemini::cleanParams($in));
            return ['question' => $r['questions'][0], 'warnings' => $r['warnings']];
    }
    throw new InvalidArgumentException('Neznámá akce.');
});
