<?php
// Veřejné údaje pro hráčský klient a obrazovku moderátora.
require __DIR__ . '/../../src/bootstrap.php';
Http::run(function () {
    $s = Settings::all();
    $base = rtrim((string)cfg('base_url', ''), '/');
    return [
        'app_name'      => $s['app_name'],
        'logo'          => $s['logo'] ? 'api/media.php?f=' . $s['logo'] : null,
        'color_primary' => $s['color_primary'],
        'color_accent'  => $s['color_accent'],
        'base_url'      => $base,   // prázdné → klient použije adresu, na které stránka běží
    ];
});
