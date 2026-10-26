<?php
declare(strict_types=1);

/**
 * Generování otázek přes Gemini API. Klíč zůstává na serveru (odesílá se v hlavičce, ne v URL);
 * prohlížeč vidí jen hotový návrh otázek.
 */
final class Gemini
{
    private const AUDIENCE = [
        'deti'      => 'děti (jednoduché otázky, srozumitelný jazyk)',
        'dospeli'   => 'dospělí (běžná všeobecná znalost)',
        'odbornici' => 'odborníci a zájemci o obor (náročnější, odborné otázky)',
    ];
    private const TYPES = [
        'choice'    => 'výběr z možností (type=choice, jedna nebo více správných odpovědí)',
        'truefalse' => 'pravda/nepravda (type=truefalse, tvrzení a dvě možnosti)',
        'poll'      => 'anketa bez správné odpovědi (type=poll, otázka na názor, žádná správná odpověď)',
    ];

    public static function apiKey(): string
    {
        $k = Secrets::get('gemini_api_key');
        return $k !== '' ? $k : (string)cfg('gemini_api_key', '');
    }

    public static function keyStatus(): array
    {
        $s = Secrets::status('gemini_api_key');
        $source = $s['set'] ? 'app' : ((string)cfg('gemini_api_key', '') !== '' ? 'config' : null);
        return ['set' => $source !== null, 'source' => $source, 'hint' => $s['hint'], 'saved_at' => $s['saved_at']];
    }

    /* ------------------------------------------------------------------ parametry a prompt */

    public static function cleanParams(array $in): array
    {
        $s = Settings::all();
        $clamp = fn($v, int $min, int $max, int $def) => is_numeric($v) ? max($min, min($max, (int)$v)) : $def;

        $catName = '';
        $catId = (isset($in['category_id']) && $in['category_id'] !== '' && $in['category_id'] !== null) ? (int)$in['category_id'] : null;
        if ($catId !== null) {
            $catName = (string)Db::val('SELECT name FROM categories WHERE id=?', [$catId]);
            if ($catName === '') {
                $catId = null;
            }
        }
        $types = array_values(array_intersect(array_keys(self::TYPES), (array)($in['types'] ?? ['choice'])));
        if (!$types) {
            $types = ['choice'];
        }
        $model = (string)($in['model'] ?? $s['gemini_model']);
        if (!preg_match('/^[A-Za-z0-9._-]{1,80}$/', $model)) {
            throw new InvalidArgumentException('Neplatný název modelu.');
        }
        $template = isset($in['prompt']) ? mb_substr((string)$in['prompt'], 0, 6000) : (string)$s['gemini_prompt'];
        if (trim($template) === '') {
            $template = (string)$s['gemini_prompt'];
        }
        $avoid = [];
        foreach ((array)($in['avoid_texts'] ?? []) as $t) {
            if (is_string($t) && trim($t) !== '') {
                $avoid[] = mb_substr(trim($t), 0, 200);
            }
        }
        return [
            'category_id'    => $catId,
            'category'       => $catName !== '' ? $catName : 'obecné / různé',
            'subcategory'    => mb_substr(trim((string)($in['subcategory'] ?? '')), 0, 200),
            'count'          => $clamp($in['count'] ?? 10, 1, 50, 10),
            'answers'        => $clamp($in['answers'] ?? 4, 2, 6, 4),
            'difficulty'     => $clamp($in['difficulty'] ?? 3, 1, 5, 3),
            'time_limit'     => $clamp($in['time_limit'] ?? 20, 5, 120, 20),
            'points'         => $clamp($in['points'] ?? 1000, 0, 5000, 1000),
            'audience'       => isset(self::AUDIENCE[$in['audience'] ?? '']) ? $in['audience'] : 'dospeli',
            'language'       => mb_substr(trim((string)($in['language'] ?? '')) ?: 'čeština', 0, 30),
            'types'          => $types,
            'avoid_existing' => !empty($in['avoid_existing']),
            'avoid_texts'    => array_slice($avoid, 0, 60),
            'model'          => $model,
            'temperature'    => is_numeric($in['temperature'] ?? null) ? max(0.0, min(2.0, (float)$in['temperature'])) : (float)$s['gemini_temperature'],
            'prompt'         => $template,
        ];
    }

    /** Doplní proměnné do šablony; připojí pevný technický dovětek (formát řeší response schema). */
    public static function buildPrompt(array $p): string
    {
        $existing = [];
        if ($p['avoid_existing'] && $p['category_id'] !== null) {
            foreach (Db::all('SELECT text FROM questions WHERE category_id=? ORDER BY id DESC LIMIT 150', [$p['category_id']]) as $r) {
                $existing[] = mb_substr($r['text'], 0, 120);
            }
        }
        $existing = array_merge($existing, $p['avoid_texts']);
        $existingTxt = $existing
            ? "Tyto otázky už existují – nepoužívej je ani jejich parafráze:\n- " . implode("\n- ", $existing)
            : '';
        $vars = [
            '{kategorie}'         => $p['category'],
            '{upresneni}'         => $p['subcategory'] !== '' ? $p['subcategory'] : '(bez upřesnění)',
            '{pocet}'             => (string)$p['count'],
            '{obtiznost}'         => (string)$p['difficulty'],
            '{jazyk}'             => $p['language'],
            '{pocet_odpovedi}'    => (string)$p['answers'],
            '{cilova_skupina}'    => self::AUDIENCE[$p['audience']],
            '{typy_otazek}'       => implode('; ', array_map(fn($t) => self::TYPES[$t], $p['types'])),
            '{existujici_otazky}' => $existingTxt,
        ];
        $prompt = strtr($p['prompt'], $vars);
        $prompt .= "\n\nTechnické požadavky: odpověz pouze strukturovaným JSON podle zadaného schématu. "
            . "U type=truefalse uveď přesně dvě možnosti „Pravda“ a „Nepravda“ a označ správnou. "
            . "U type=poll nesmí být žádná možnost označena jako správná. "
            . "Text otázky nejvýše 300 znaků, každá možnost nejvýše 120 znaků.";
        return $prompt;
    }

    private static function schema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'questions' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'type'        => ['type' => 'STRING', 'enum' => ['choice', 'truefalse', 'poll']],
                            'text'        => ['type' => 'STRING'],
                            'explanation' => ['type' => 'STRING'],
                            'options'     => [
                                'type' => 'ARRAY',
                                'items' => [
                                    'type' => 'OBJECT',
                                    'properties' => [
                                        'text'    => ['type' => 'STRING'],
                                        'correct' => ['type' => 'BOOLEAN'],
                                    ],
                                    'required' => ['text', 'correct'],
                                ],
                            ],
                        ],
                        'required' => ['type', 'text', 'options'],
                    ],
                ],
            ],
            'required' => ['questions'],
        ];
    }

    /* ------------------------------------------------------------------ volání API */

    public static function request(string $model, array $payload): array
    {
        $key = self::apiKey();
        if ($key === '') {
            throw new InvalidArgumentException('Není nastaven Gemini API klíč (Nastavení → Gemini).');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Chybí PHP rozšíření curl (balíček php-curl).');
        }
        $base = rtrim((string)cfg('gemini_base_url', 'https://generativelanguage.googleapis.com'), '/');
        $ch = curl_init($base . '/v1beta/models/' . rawurlencode($model) . ':generateContent');
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 100,
        ];
        if ((string)cfg('curl_cainfo', '') !== '') {
            $opts[CURLOPT_CAINFO] = (string)cfg('curl_cainfo');
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);

        if ($body === false) {
            error_log('Gemini curl: ' . $err);
            throw new InvalidArgumentException('Nepodařilo se spojit s Gemini API: ' . $err);
        }
        $data = json_decode((string)$body, true);
        if ($code >= 400) {
            $msg = is_array($data) ? (string)($data['error']['message'] ?? '') : '';
            error_log("Gemini HTTP $code: " . mb_substr($msg !== '' ? $msg : (string)$body, 0, 500));
            throw new InvalidArgumentException(self::httpError($code, $msg));
        }
        if (!is_array($data)) {
            throw new InvalidArgumentException('Gemini vrátil neplatnou odpověď.');
        }
        return $data;
    }

    private static function httpError(int $code, string $msg): string
    {
        if ($code === 400 && stripos($msg, 'API key') !== false) {
            return 'Gemini odmítl API klíč jako neplatný. Zkontrolujte ho v Nastavení → Gemini.';
        }
        return match (true) {
            $code === 401 || $code === 403 => 'Gemini odmítl přístup (klíč nemá oprávnění nebo je omezený). ' . $msg,
            $code === 404 => 'Model nebyl nalezen. Zkontrolujte název modelu v Nastavení → Gemini. ' . $msg,
            $code === 429 => 'Překročen limit požadavků nebo kvóta Gemini. Zkuste to za chvíli.',
            $code >= 500  => 'Služba Gemini je dočasně nedostupná (HTTP ' . $code . '). Zkuste to znovu.',
            default       => 'Gemini vrátil chybu (HTTP ' . $code . '): ' . $msg,
        };
    }

    /** Jednoduchý test klíče a modelu. */
    public static function test(): array
    {
        $model = (string)Settings::get('gemini_model');
        $r = self::request($model, [
            'contents' => [['role' => 'user', 'parts' => [['text' => 'Odpověz jedním slovem: OK']]]],
            'generationConfig' => ['maxOutputTokens' => 64, 'temperature' => 0],
        ]);
        return ['ok' => isset($r['candidates']), 'model' => $model];
    }

    /* ------------------------------------------------------------------ generování */

    /** @return array{questions: array, warnings: string[]} */
    public static function generate(array $p): array
    {
        @set_time_limit(130);
        $resp = self::request($p['model'], [
            'contents' => [['role' => 'user', 'parts' => [['text' => self::buildPrompt($p)]]]],
            'generationConfig' => [
                'temperature'      => $p['temperature'],
                'responseMimeType' => 'application/json',
                'responseSchema'   => self::schema(),
                'maxOutputTokens'  => 16384,
            ],
        ]);
        $json = self::extractJson($resp);
        return self::normalize($json['questions'] ?? [], $p);
    }

    private static function extractJson(array $resp): array
    {
        if (!empty($resp['promptFeedback']['blockReason'])) {
            throw new InvalidArgumentException('Gemini zadání zablokoval (' . $resp['promptFeedback']['blockReason'] . '). Upravte téma nebo prompt.');
        }
        $cand = $resp['candidates'][0] ?? null;
        $text = '';
        foreach ($cand['content']['parts'] ?? [] as $part) {
            $text .= (string)($part['text'] ?? '');
        }
        $finish = (string)($cand['finishReason'] ?? '');
        if (trim($text) === '') {
            throw new InvalidArgumentException('Gemini nevrátil žádný obsah' . ($finish ? " (důvod: $finish)" : '') . '. Zkuste jiné téma.');
        }
        $j = json_decode($text, true);
        if (!is_array($j)) {   // záchrana: ohraničení ``` nebo text okolo JSONu
            $a = strpos($text, '{');
            $b = strrpos($text, '}');
            if ($a !== false && $b !== false && $b > $a) {
                $j = json_decode(substr($text, $a, $b - $a + 1), true);
            }
        }
        if (!is_array($j)) {
            throw new InvalidArgumentException(
                $finish === 'MAX_TOKENS'
                    ? 'Odpověď Gemini byla příliš dlouhá a useknutá. Snižte počet otázek.'
                    : 'Gemini vrátil neplatný JSON. Zkuste to znovu nebo snižte počet otázek.'
            );
        }
        return $j;
    }

    /** Ověří otázky od Gemini; neplatné vyřadí a zapíše varování. */
    private static function normalize(array $raw, array $p): array
    {
        $warnings = [];
        $out = [];
        $seen = [];
        foreach ($p['avoid_texts'] as $t) {
            $seen[mb_strtolower($t)] = true;
        }
        if ($p['avoid_existing'] && $p['category_id'] !== null) {
            foreach (Db::all('SELECT text FROM questions WHERE category_id=?', [$p['category_id']]) as $r) {
                $seen[mb_strtolower(trim($r['text']))] = true;
            }
        }
        $dropped = 0;
        $dups = 0;
        foreach ($raw as $i => $q) {
            if (!is_array($q)) {
                $dropped++;
                continue;
            }
            $type = (string)($q['type'] ?? 'choice');
            if (!in_array($type, $p['types'], true)) {
                $dropped++;
                continue;
            }
            $opts = [];
            foreach ((array)($q['options'] ?? []) as $o) {
                $opts[] = ['text' => trim((string)($o['text'] ?? '')), 'correct' => !empty($o['correct'])];
            }
            if ($type === 'truefalse') {
                $idx = 0;
                foreach ($opts as $k => $o) {
                    if ($o['correct']) {
                        $idx = $k;
                        break;
                    }
                }
                $first = mb_strtolower($opts[$idx]['text'] ?? '');
                $isTrue = $first !== '' ? !preg_match('/^(ne|nepravda|false|nesprávn)/u', $first) : $idx === 0;
                $opts = [['text' => 'Pravda', 'correct' => $isTrue], ['text' => 'Nepravda', 'correct' => !$isTrue]];
            } elseif ($type === 'choice') {
                // víc možností, než se chtělo: odeber špatné od konce
                while (count($opts) > $p['answers']) {
                    $drop = null;
                    for ($k = count($opts) - 1; $k >= 0; $k--) {
                        if (!$opts[$k]['correct']) {
                            $drop = $k;
                            break;
                        }
                    }
                    if ($drop === null) {
                        break;
                    }
                    array_splice($opts, $drop, 1);
                }
            } else { // poll
                $opts = array_slice($opts, 0, max(2, $p['answers']));
            }
            $cand = [
                'type'        => $type,
                'text'        => (string)($q['text'] ?? ''),
                'options'     => $opts,
                'explanation' => (string)($q['explanation'] ?? ''),
                'time_limit'  => $p['time_limit'],
                'points'      => $type === 'poll' ? null : $p['points'],
                'source'      => 'gemini',
            ];
            try {
                $clean = Quiz::cleanQuestion($cand, $i + 1);
            } catch (InvalidArgumentException $e) {
                $dropped++;
                continue;
            }
            $key = mb_strtolower(trim($clean['text']));
            if (isset($seen[$key])) {
                $dups++;
                continue;
            }
            $seen[$key] = true;
            $out[] = [
                'type'        => $clean['type'],
                'text'        => $clean['text'],
                'image'       => null,
                'time_limit'  => $clean['time_limit'],
                'points'      => $clean['points'],
                'explanation' => (string)$clean['explanation'],
                'source'      => 'gemini',
                'options'     => $clean['options'],
            ];
        }
        if ($dropped) {
            $warnings[] = "Vyřazeno neplatných otázek: $dropped.";
        }
        if ($dups) {
            $warnings[] = "Vyřazeno duplicitních otázek: $dups.";
        }
        if (!$out) {
            throw new InvalidArgumentException('Gemini nevrátil žádnou použitelnou otázku. Zkuste upravit téma nebo prompt.');
        }
        if (count($out) < $p['count']) {
            $warnings[] = 'Vráceno ' . count($out) . ' z požadovaných ' . $p['count'] . ' otázek.';
        }
        return ['questions' => $out, 'warnings' => $warnings];
    }
}
