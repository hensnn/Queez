<?php
// Zkopírujte jako config.php (mimo webroot, tj. vedle složky public/) a upravte.
// Soubor config.php NIKDY nenahrávejte do veřejného repozitáře.
return [
    // Veřejná adresa aplikace (bez lomítka na konci). Z ní se tvoří odkazy v QR kódech.
    'base_url' => 'https://example.org',

    // Hash admin hesla. Vygenerujte příkazem:
    //   php -r "echo password_hash('VASE_HESLO', PASSWORD_DEFAULT), PHP_EOL;"
    'admin_password_hash' => '',

    // Cesty (výchozí hodnoty jsou vedle složky public/)
    'db_path'  => __DIR__ . '/data/kviz.sqlite',
    'data_dir' => __DIR__ . '/data',          // uploady, session, TTS cache
    'log_file' => __DIR__ . '/logs/error.log',

    // Reverzní proxy, kterým věříme hlavičky X-Forwarded-* (IP adresy nebo CIDR)
    'trusted_proxies' => ['127.0.0.1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],

    // Gemini: API klíč se zadává v aplikaci (Nastavení → Gemini) a ukládá se šifrovaně do data/secrets/.
    // Hodnota zde je jen záložní (použije se, když v aplikaci není klíč uložen).
    'gemini_api_key'     => '',
    'gemini_tts_enabled' => false,   // hlavní vypínač serverového TTS
    // 'gemini_base_url' => 'https://generativelanguage.googleapis.com',   // jen pro testy
    // 'curl_cainfo'     => 'C:/php/cacert.pem',   // jen na Windows bez CA balíčku

    // Ostatní
    'upload_max_mb'      => 3,
    'login_max_attempts' => 5,       // neúspěšných pokusů za 15 minut z jedné IP
    'session_hours'      => 12,
    'timezone'           => 'Europe/Prague',
];
