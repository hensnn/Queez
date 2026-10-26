<?php
// GET = definice + hodnoty + kategorie; POST {values:{...}} = uložit; POST {reset:"tab"} = výchozí hodnoty záložky
require __DIR__ . '/../../src/bootstrap.php';
Http::run(function () {
    Auth::require();
    if (Http::method() === 'POST') {
        $in = Http::input();
        if (!empty($in['reset'])) {
            Settings::resetTab((string)$in['reset']);
        } else {
            Settings::saveMany(is_array($in['values'] ?? null) ? $in['values'] : []);
        }
    }
    return Settings::forAdmin() + [
        'categories' => Db::all('SELECT id,name FROM categories ORDER BY sort,name'),
        'subs'       => Defaults::SUBCATEGORIES,   // nabídka podkategorií pro formulář generování
    ];
});
