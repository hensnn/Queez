<?php
// GET = stav tajných hodnot (bez hodnot); POST {name, value} = uložit; POST {name, action:"delete"} = smazat
require __DIR__ . '/../../src/bootstrap.php';
Http::run(function () {
    Auth::require();
    if (Http::method() === 'POST') {
        $in = Http::input();
        $name = (string)($in['name'] ?? '');
        if (($in['action'] ?? '') === 'delete') {
            Secrets::delete($name);
        } else {
            $value = trim((string)($in['value'] ?? ''));
            // Povoleny jsou běžné znaky klíčů (písmena, číslice, _ - . ~ + / =); mezery a konce řádků ne.
            if ($name === 'gemini_api_key' && !preg_match('/^[A-Za-z0-9_\-.~+\/=]{16,400}$/D', $value)) {
                throw new InvalidArgumentException(
                    'Klíč má neplatný tvar (' . strlen($value) . ' znaků). Zkontrolujte, že je zkopírovaný celý, '
                    . 'bez mezer, uvozovek a konců řádků.'
                );
            }
            Secrets::set($name, $value);
        }
    }
    return ['gemini_api_key' => Gemini::keyStatus()];
});
