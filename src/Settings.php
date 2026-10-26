<?php
declare(strict_types=1);

/**
 * Globální nastavení. Definice (typ, rozsah, nápověda, výchozí hodnota) jsou na jednom místě
 * a admin UI se z nich generuje. Hodnoty jsou v tabulce `settings`.
 * Příznak 'quiz' => true = hodnotu lze přepsat v nastavení kvízu (a při spuštění hry).
 */
final class Settings
{
    private static ?array $cache = null;

    public const DEFAULT_PROMPT = <<<'TXT'
Jsi tvůrce kvízových otázek. Vytvoř {pocet} otázek v jazyce: {jazyk}.
Kategorie: {kategorie}
Upřesnění tématu: {upresneni}
Cílová skupina: {cilova_skupina}
Obtížnost: {obtiznost} z 5 (1 = velmi snadné, 5 = velmi těžké).
Povolené typy otázek: {typy_otazek}.
U otázek s výběrem uveď přesně {pocet_odpovedi} možností a označ správnou odpověď (nebo odpovědi).
Otázky musí být fakticky správné, jednoznačné a zajímavé; špatné možnosti musí být věrohodné.
Nepoužívej otázky, které se opírají o zastaralé nebo sporné informace.
{existujici_otazky}
TXT;

    public static function tabs(): array
    {
        return [
            ['key' => 'general', 'title' => 'Obecné', 'note' => ''],
            ['key' => 'game', 'title' => 'Hra', 'note' =>
                'Jak se počítají body: správná odpověď dostane základ × (1 − R × čas ÷ limit), kde R je „Bonus za rychlost“ '
                . '(0 % = pevné body, 50 % = odpověď na poslední vteřině má poloviční základ). K tomu se přičte '
                . 'série: „Bonus za sérii“ × počet správných odpovědí hned před touto otázkou (nejvýše „Max. délka série“). '
                . 'Špatná nebo chybějící odpověď dá 0 bodů a sérii přeruší. Anketa body nedává a sérii nepřeruší. '
                . 'Při otázce bez časového limitu se bonus za rychlost nepoužije.'],
            ['key' => 'gemini', 'title' => 'Gemini', 'note' => ''],
            ['key' => 'tts', 'title' => 'Hlasatel (TTS)', 'note' => 'Co všechno hlasatel říká a kdy. Hlasatel mluví na zařízení moderátora; v hlasovém režimu (bez obrazovky) vždy. Většinu voleb jde přepsat i u kvízu a při spuštění hry.'],
            ['key' => 'maintenance', 'title' => 'Údržba', 'note' => ''],
        ];
    }

    private static function vis(): array
    {
        return [
            'live'           => 'Průběžně během hlasování',
            'after_question' => 'Po skončení otázky',
            'end'            => 'Až na konci hry',
            'never'          => 'Nikdy',
        ];
    }

    public static function defs(): array
    {
        $d = [
            // ---- Obecné
            'app_name' => ['tab' => 'general', 'type' => 'text', 'max' => 60, 'default' => 'Kvíz',
                'label' => 'Název aplikace', 'help' => 'Zobrazuje se hráčům i na projektoru.'],
            'logo' => ['tab' => 'general', 'type' => 'image', 'default' => '',
                'label' => 'Logo', 'help' => 'Volitelné. JPG, PNG nebo WebP.'],
            'color_primary' => ['tab' => 'general', 'type' => 'color', 'default' => '#ff6b4a',
                'label' => 'Hlavní barva', 'help' => 'Pozadí her a záhlaví. Vytvoří se z ní teplý přechod.'],
            'color_accent' => ['tab' => 'general', 'type' => 'color', 'default' => '#ffc233',
                'label' => 'Doplňková barva', 'help' => 'Tlačítka, zvýraznění, stupně vítězů.'],

            // ---- Hra
            'default_time_limit' => ['tab' => 'game', 'type' => 'int', 'min' => 0, 'max' => 120, 'step' => 5, 'default' => 20,
                'unit' => ' s', 'zero_label' => 'bez limitu', 'quiz' => true,
                'label' => 'Výchozí časový limit otázky', 'help' => 'Platí, pokud otázka nemá vlastní limit.'],
            'default_points' => ['tab' => 'game', 'type' => 'int', 'min' => 0, 'max' => 5000, 'step' => 50, 'default' => 1000,
                'unit' => ' b.', 'quiz' => true,
                'label' => 'Výchozí body za otázku', 'help' => 'Základ bodování správné odpovědi.'],
            'speed_weight' => ['tab' => 'game', 'type' => 'int', 'min' => 0, 'max' => 100, 'step' => 5, 'default' => 50,
                'unit' => ' %', 'quiz' => true,
                'label' => 'Bonus za rychlost (R)', 'help' => '0 % = pevné body. Čím vyšší, tím víc záleží na rychlosti.'],
            'streak_bonus' => ['tab' => 'game', 'type' => 'int', 'min' => 0, 'max' => 500, 'step' => 10, 'default' => 100,
                'unit' => ' b.', 'quiz' => true,
                'label' => 'Bonus za sérii', 'help' => 'Body navíc za každou správnou odpověď v řadě. 0 = vypnuto.'],
            'streak_max' => ['tab' => 'game', 'type' => 'int', 'min' => 1, 'max' => 10, 'step' => 1, 'default' => 5,
                'quiz' => true,
                'label' => 'Max. délka série', 'help' => 'Bonus za sérii dál neroste.'],
            'multi_correct_mode' => ['tab' => 'game', 'type' => 'select', 'default' => 'all_or_nothing', 'quiz' => true,
                'options' => ['all_or_nothing' => 'Vše správně a nic navíc', 'proportional' => 'Poměrně podle správných voleb'],
                'label' => 'Více správných odpovědí', 'help' => 'Jak se bodují otázky s více správnými možnostmi.'],
            'allow_change_answer' => ['tab' => 'game', 'type' => 'bool', 'default' => true, 'quiz' => true,
                'label' => 'Povolit změnu odpovědi', 'help' => 'Hráč může odpověď změnit do konce limitu.'],
            'show_correct_to_player' => ['tab' => 'game', 'type' => 'bool', 'default' => true, 'quiz' => true,
                'label' => 'Hned po odpovědi ukázat, zda byla správná', 'help' => 'Jinak se to dozví až po skončení otázky.'],
            'secret_voting' => ['tab' => 'game', 'type' => 'bool', 'default' => false, 'quiz' => true,
                'label' => 'Tajné hlasování', 'help' => 'Nikdo (ani moderátor) neuvidí, kdo jak hlasoval; ukládá se jen součet hlasů a body.'],
            'visibility_host' => ['tab' => 'game', 'type' => 'select', 'default' => 'after_question', 'quiz' => true,
                'options' => self::vis(),
                'label' => 'Výsledky hlasování na projektoru', 'help' => 'Kdy moderátor uvidí rozložení odpovědí.'],
            'visibility_players' => ['tab' => 'game', 'type' => 'select', 'default' => 'after_question', 'quiz' => true,
                'options' => self::vis(),
                'label' => 'Výsledky hlasování na telefonech', 'help' => 'Kdy uvidí rozložení odpovědí hráči.'],
            'leaderboard_mode' => ['tab' => 'game', 'type' => 'select', 'default' => 'every', 'quiz' => true,
                'options' => [
                    'every'    => 'Po každé otázce',
                    'interval' => 'Po každých N otázkách',
                    'end'      => 'Jen po poslední otázce',
                    'never'    => 'Nikdy (rovnou konečné výsledky)',
                ],
                'label' => 'Kdy ukazovat průběžné pořadí', 'help' => 'Pořadí hráčů mezi otázkami. Konečné výsledky na konci hry se ukazují vždy.'],
            'leaderboard_every' => ['tab' => 'game', 'type' => 'int', 'min' => 2, 'max' => 20, 'step' => 1, 'default' => 3,
                'unit' => ' otázky', 'quiz' => true,
                'label' => 'N pro „po každých N otázkách“', 'help' => 'Používá se jen u volby „Po každých N otázkách“.'],
            'warn_seconds' => ['tab' => 'game', 'type' => 'int', 'min' => 3, 'max' => 30, 'step' => 1, 'default' => 5,
                'unit' => ' s', 'quiz' => true,
                'label' => 'Upozornit na konec limitu', 'help' => 'Kolik vteřin před koncem otázky se odpočet zabarví červeně a (je-li zapnuté předčítání) zazní upozornění. Platí u otázek s limitem delším než tato hodnota + 2 s.'],
            'phone_content' => ['tab' => 'game', 'type' => 'select', 'default' => 'question', 'quiz' => true,
                'options' => [
                    'buttons'  => 'Jen barevná tlačítka',
                    'question' => 'Otázka + barevná tlačítka',
                    'full'     => 'Otázka i texty odpovědí',
                ],
                'label' => 'Co vidí hráč na telefonu', 'help' => 'Při hře s velkou obrazovkou. V hlasovém režimu (bez obrazovky) vidí hráči vždy otázku i odpovědi.'],
            'shuffle_questions' => ['tab' => 'game', 'type' => 'bool', 'default' => false, 'quiz' => true,
                'label' => 'Zamíchat pořadí otázek', 'help' => 'Při každém spuštění hry jiné pořadí.'],
            'shuffle_answers' => ['tab' => 'game', 'type' => 'bool', 'default' => false, 'quiz' => true,
                'label' => 'Zamíchat pořadí odpovědí', 'help' => 'U otázek s výběrem (pravda/nepravda a anketa se nemíchají).'],
            'auto_advance' => ['tab' => 'game', 'type' => 'bool', 'default' => false, 'quiz' => true,
                'label' => 'Automatický postup na další otázku', 'help' => 'Jinak postupuje moderátor ručně.'],
            'auto_advance_delay' => ['tab' => 'game', 'type' => 'int', 'min' => 3, 'max' => 30, 'step' => 1, 'default' => 8,
                'unit' => ' s', 'quiz' => true,
                'label' => 'Prodleva před další otázkou', 'help' => 'Jak dlouho se při automatickém postupu drží výsledky.'],
            'max_players' => ['tab' => 'game', 'type' => 'int', 'min' => 2, 'max' => 200, 'step' => 1, 'default' => 50,
                'quiz' => true,
                'label' => 'Max. počet hráčů', 'help' => 'Další hráči se nepřipojí.'],
            'allow_join_after_start' => ['tab' => 'game', 'type' => 'bool', 'default' => true, 'quiz' => true,
                'label' => 'Povolit připojení po startu hry', 'help' => 'Opozdilci se mohou přidat do rozehrané hry.'],
            'unique_names' => ['tab' => 'game', 'type' => 'bool', 'default' => true, 'quiz' => true,
                'label' => 'Zajistit unikátní jména', 'help' => 'Duplicitní jméno dostane automaticky číslo (Honza 2).'],
            'poll_interval_ms' => ['tab' => 'game', 'type' => 'int', 'min' => 500, 'max' => 5000, 'step' => 100, 'default' => 1000,
                'unit' => ' ms',
                'label' => 'Interval dotazování klientů', 'help' => 'Jak často se telefony ptají serveru. Nižší = svižnější, ale víc zátěže.'],

            // ---- Gemini
            'gemini_model' => ['tab' => 'gemini', 'type' => 'text', 'max' => 80, 'default' => 'gemini-2.5-flash',
                'label' => 'Model Gemini', 'help' => 'Název modelu pro generování otázek.'],
            'gemini_temperature' => ['tab' => 'gemini', 'type' => 'float', 'min' => 0, 'max' => 2, 'step' => 0.1, 'default' => 0.8,
                'label' => 'Teplota (kreativita)', 'help' => 'Nižší = konzervativnější, vyšší = originálnější, ale riskantnější.'],
            'gemini_prompt' => ['tab' => 'gemini', 'type' => 'textarea', 'max' => 6000, 'default' => self::DEFAULT_PROMPT,
                'label' => 'Šablona promptu',
                'help' => 'Zástupné proměnné: {kategorie} {upresneni} {pocet} {obtiznost} {jazyk} {pocet_odpovedi} {cilova_skupina} {typy_otazek} {existujici_otazky}'],

            // ---- Hlasatel (TTS). Klíče zůstávají tts_*; 'group' jen seskupuje řádky v administraci.
            'tts_enabled' => ['tab' => 'tts', 'group' => 'Hlasatel obecně', 'type' => 'bool', 'default' => false, 'quiz' => true,
                'label' => 'Hlasatel zapnut jako výchozí', 'help' => 'Moderátor ho může zapnout/vypnout i během hry. V hlasovém režimu mluví vždy.'],
            'tts_voice' => ['tab' => 'tts', 'group' => 'Hlasatel obecně', 'type' => 'text', 'max' => 120, 'default' => '',
                'label' => 'Název hlasu', 'help' => 'Prázdné = první dostupný český hlas. Dostupné hlasy závisí na zařízení moderátora (viz „Vyzkoušet hlas“ níže).'],
            'tts_rate' => ['tab' => 'tts', 'group' => 'Hlasatel obecně', 'type' => 'float', 'min' => 0.5, 'max' => 2, 'step' => 0.1, 'default' => 1.0,
                'unit' => '×', 'label' => 'Rychlost řeči', 'help' => '1 = normální.'],
            'tts_pitch' => ['tab' => 'tts', 'group' => 'Hlasatel obecně', 'type' => 'float', 'min' => 0.5, 'max' => 1.5, 'step' => 0.1, 'default' => 1.0,
                'unit' => '×', 'label' => 'Výška hlasu', 'help' => '1 = běžná. Platí u hlasů prohlížeče; záložní Gemini hlas výšku nemění.'],
            'tts_volume' => ['tab' => 'tts', 'group' => 'Hlasatel obecně', 'type' => 'int', 'min' => 10, 'max' => 100, 'step' => 5, 'default' => 100,
                'unit' => ' %', 'label' => 'Hlasitost hlasatele', 'help' => 'Vedle hlasitosti telefonu nebo reproduktoru.'],

            'tts_say_number' => ['tab' => 'tts', 'group' => 'Otázka', 'type' => 'bool', 'default' => true, 'quiz' => true,
                'label' => 'Říkat číslo otázky', 'help' => '„Otázka číslo 3.“ Vypnuto: hlasatel rovnou čte text otázky.'],
            'tts_say_type' => ['tab' => 'tts', 'group' => 'Otázka', 'type' => 'bool', 'default' => true, 'quiz' => true,
                'label' => 'Upozornit na typ otázky', 'help' => '„Anketa.“ a „Může být více správných odpovědí.“'],
            'tts_read_options' => ['tab' => 'tts', 'group' => 'Otázka', 'type' => 'bool', 'default' => true, 'quiz' => true,
                'label' => 'Předčítat možnosti odpovědí', 'help' => 'U pravda/nepravda hlasatel řekne „Pravda, nebo nepravda?“.'],
            'tts_option_letters' => ['tab' => 'tts', 'group' => 'Otázka', 'type' => 'select', 'default' => 'auto', 'quiz' => true,
                'options' => ['auto' => 'Jen v hlasovém režimu (bez obrazovky)', 'always' => 'Vždy (písmena A–F u odpovědí)', 'never' => 'Nikdy'],
                'label' => 'Označovat odpovědi písmeny', 'help' => 'Písmena A, B, C… se přečtou a zároveň zobrazí u odpovědí na telefonech i na projektoru.'],
            'tts_say_points' => ['tab' => 'tts', 'group' => 'Otázka', 'type' => 'bool', 'default' => false, 'quiz' => true,
                'label' => 'Říkat, kolik je za otázku bodů', 'help' => '„Za správnou odpověď je tisíc bodů.“ (u ankety se neříká)'],
            'tts_say_limit' => ['tab' => 'tts', 'group' => 'Otázka', 'type' => 'bool', 'default' => false, 'quiz' => true,
                'label' => 'Říkat časový limit', 'help' => '„Na odpověď máte dvacet sekund.“ (u otázky bez limitu se neříká)'],
            'tts_read_delay' => ['tab' => 'tts', 'group' => 'Otázka', 'type' => 'int', 'min' => 0, 'max' => 15, 'step' => 1, 'default' => 2,
                'unit' => ' s', 'quiz' => true, 'label' => 'Prodleva po dočtení otázky', 'help' => 'Jen hlasový režim: odpočet začne až tolik vteřin po dočtení otázky.'],

            'tts_read_countdown' => ['tab' => 'tts', 'group' => 'Čas', 'type' => 'bool', 'default' => true, 'quiz' => true,
                'label' => 'Upozornit před koncem limitu', 'help' => 'Řekne třeba „Zbývá pět sekund.“ Kolik vteřin před koncem se nastavuje v záložce Hra („Upozornit na konec limitu“).'],
            'tts_warn_half' => ['tab' => 'tts', 'group' => 'Čas', 'type' => 'bool', 'default' => false, 'quiz' => true,
                'label' => 'Hlásit polovinu času', 'help' => '„Zbývá polovina času.“ Jen u otázek s limitem aspoň 20 s.'],
            'tts_warn_final' => ['tab' => 'tts', 'group' => 'Čas', 'type' => 'bool', 'default' => false, 'quiz' => true,
                'label' => 'Odpočítat poslední tři vteřiny', 'help' => '„Tři… dva… jedna.“ Hodí se pro napětí; vypněte, pokud hlas příliš ruší.'],
            'tts_say_time_up' => ['tab' => 'tts', 'group' => 'Čas', 'type' => 'bool', 'default' => true, 'quiz' => true,
                'label' => 'Oznámit konec času', 'help' => '„Čas vypršel.“ Jen když otázka skončila vypršením limitu (ne když všichni odpověděli dřív).'],

            'tts_read_results' => ['tab' => 'tts', 'group' => 'Odhalení odpovědi', 'type' => 'bool', 'default' => true, 'quiz' => true,
                'label' => 'Říct správnou odpověď', 'help' => '„Správná odpověď: Praha.“ U ankety „Hlasování skončilo.“'],
            'tts_say_stats' => ['tab' => 'tts', 'group' => 'Odhalení odpovědi', 'type' => 'bool', 'default' => false, 'quiz' => true,
                'label' => 'Říct, kolik hráčů odpovědělo správně', 'help' => '„Správně odpovědělo 5 z 8 hráčů.“ Neřekne se, pokud je skryté rozložení odpovědí (tajné hlasování, viditelnost výsledků).'],
            'tts_say_explanation' => ['tab' => 'tts', 'group' => 'Odhalení odpovědi', 'type' => 'bool', 'default' => true, 'quiz' => true,
                'label' => 'Přečíst vysvětlení', 'help' => 'Text vysvětlení, pokud ho otázka má.'],

            'tts_read_leaderboard' => ['tab' => 'tts', 'group' => 'Pořadí a konec hry', 'type' => 'bool', 'default' => true, 'quiz' => true,
                'label' => 'Číst pořadí', 'help' => '„Průběžné pořadí…“ mezi otázkami a „Konečné pořadí…“ po poslední.'],
            'tts_lb_top' => ['tab' => 'tts', 'group' => 'Pořadí a konec hry', 'type' => 'int', 'min' => 1, 'max' => 10, 'step' => 1, 'default' => 3,
                'unit' => ' míst', 'quiz' => true, 'label' => 'Kolik míst v pořadí číst', 'help' => 'Kolik prvních hráčů hlasatel jmenuje.'],
            'tts_say_scores' => ['tab' => 'tts', 'group' => 'Pořadí a konec hry', 'type' => 'bool', 'default' => true, 'quiz' => true,
                'label' => 'Číst u pořadí i body', 'help' => 'Vypnuto: jen jména („První: Anna. Druhý: Bob.“).'],
            'tts_say_winner' => ['tab' => 'tts', 'group' => 'Pořadí a konec hry', 'type' => 'bool', 'default' => true, 'quiz' => true,
                'label' => 'Vyhlásit vítěze na konci hry', 'help' => '„Konec hry. Vítězem je Anna.“ Včetně druhého a třetího místa.'],

            'tts_say_start' => ['tab' => 'tts', 'group' => 'Průběh hry', 'type' => 'bool', 'default' => false, 'quiz' => true,
                'label' => 'Oznámit začátek hry', 'help' => '„Hra začíná, hodně štěstí!“ před první otázkou.'],
            'tts_say_pause' => ['tab' => 'tts', 'group' => 'Průběh hry', 'type' => 'bool', 'default' => false, 'quiz' => true,
                'label' => 'Hlásit pauzu a pokračování', 'help' => '„Hra je pozastavena.“ a „Pokračujeme.“'],

            'tts_gemini_fallback' => ['tab' => 'tts', 'group' => 'Záložní hlas přes Gemini', 'type' => 'bool', 'default' => false,
                'label' => 'Použít Gemini TTS, když zařízení nemá český hlas', 'help' => 'Funguje jen při zapnutí v config.php (gemini_tts_enabled) a s nastaveným Gemini API klíčem. Hotové věty se ukládají do cache na serveru.'],
            'tts_gemini_model' => ['tab' => 'tts', 'group' => 'Záložní hlas přes Gemini', 'type' => 'text', 'max' => 80, 'default' => 'gemini-2.5-flash-preview-tts',
                'label' => 'Model Gemini TTS', 'help' => 'Název modelu pro převod textu na řeč.'],
            'tts_gemini_voice' => ['tab' => 'tts', 'group' => 'Záložní hlas přes Gemini', 'type' => 'text', 'max' => 40, 'default' => 'Kore',
                'label' => 'Hlas Gemini TTS', 'help' => 'Název předdefinovaného hlasu (např. Kore, Puck, Charon, Aoede).'],
            // ---- Údržba
            'cleanup_days' => ['tab' => 'maintenance', 'type' => 'int', 'min' => 0, 'max' => 365, 'step' => 1, 'default' => 30,
                'unit' => ' dní', 'zero_label' => 'nikdy nemazat',
                'label' => 'Automaticky mazat hry starší než', 'help' => 'Smaže se hra i její hráči a odpovědi.'],
        ];
        foreach ($d as $k => &$def) {
            $def['key'] = $k;
        }
        return $d;
    }

    /** Všechny hodnoty = výchozí + uložené. */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $defs = self::defs();
        $out = [];
        foreach ($defs as $k => $d) {
            $out[$k] = $d['default'];
        }
        foreach (Db::all('SELECT key,value FROM settings') as $r) {
            if (!isset($defs[$r['key']])) {
                continue;
            }
            try {
                $out[$r['key']] = self::clean($defs[$r['key']], json_decode($r['value'], true));
            } catch (InvalidArgumentException $e) {
                // poškozená hodnota → zůstane výchozí
            }
        }
        return self::$cache = $out;
    }

    public static function get(string $key)
    {
        return self::all()[$key] ?? null;
    }

    public static function saveMany(array $values): void
    {
        $defs = self::defs();
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            foreach ($values as $k => $v) {
                if (!isset($defs[$k])) {
                    continue;
                }
                $c = self::clean($defs[$k], $v);
                Db::exec('INSERT INTO settings(key,value) VALUES(?,?)
                          ON CONFLICT(key) DO UPDATE SET value=excluded.value', [$k, json_encode($c, JSON_UNESCAPED_UNICODE)]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        self::$cache = null;
    }

    public static function resetTab(string $tab): void
    {
        foreach (self::defs() as $k => $d) {
            if ($d['tab'] === $tab) {
                Db::exec('DELETE FROM settings WHERE key=?', [$k]);
            }
        }
        self::$cache = null;
    }

    /** Přepisy na úrovni kvízu: jen klíče označené 'quiz'. */
    public static function cleanQuiz($in): array
    {
        $out = [];
        if (!is_array($in)) {
            return $out;
        }
        $defs = self::defs();
        foreach ($in as $k => $v) {
            if (isset($defs[$k]) && !empty($defs[$k]['quiz'])) {
                $out[$k] = self::clean($defs[$k], $v);
            }
        }
        return $out;
    }

    public static function clean(array $d, $v)
    {
        $label = $d['label'] ?? $d['key'];
        switch ($d['type']) {
            case 'int':
            case 'float':
                if (!is_numeric($v)) {
                    throw new InvalidArgumentException("Hodnota „{$label}“ musí být číslo.");
                }
                $n = $d['type'] === 'int' ? (int)round((float)$v) : round((float)$v, 2);
                return max($d['min'], min($d['max'], $n));
            case 'bool':
                return filter_var($v, FILTER_VALIDATE_BOOLEAN);
            case 'select':
                if (!is_string($v) || !isset($d['options'][$v])) {
                    throw new InvalidArgumentException("Neplatná volba u „{$label}“.");
                }
                return $v;
            case 'color':
                if (!is_string($v) || !preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
                    throw new InvalidArgumentException("Neplatná barva u „{$label}“.");
                }
                return strtolower($v);
            case 'image':
                $v = (string)$v;
                if ($v !== '' && !Media::validName($v)) {
                    throw new InvalidArgumentException("Neplatný obrázek u „{$label}“.");
                }
                return $v;
            case 'textarea':
                return mb_substr(trim((string)$v), 0, $d['max'] ?? 8000);
            default: // text
                return mb_substr(trim((string)$v), 0, $d['max'] ?? 200);
        }
    }

    /** Data pro admin UI. */
    public static function forAdmin(): array
    {
        return [
            'tabs'   => self::tabs(),
            'defs'   => array_values(self::defs()),
            'values' => self::all(),
        ];
    }
}
