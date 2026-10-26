<?php
/**
 * Integrační test celé hry přes HTTP API (bez prohlížeče).
 *
 *   php tests/run.php --url=http://127.0.0.1:8000 --password=HESLO_ADMINA [--db=/cesta/k/kviz.sqlite]
 *
 * Vytvoří dočasné kvízy a hry a na konci je smaže. Spouštějte jen proti VÝVOJOVÉ instalaci.
 * Volitelné --db umožní navíc zkontrolovat, že se u tajného hlasování nic neukládá.
 * Test trvá asi 15 s (jedna kontrola čeká na vypršení limitu otázky).
 */
if (PHP_SAPI !== 'cli') {
    exit("Spouští se z příkazové řádky.\n");
}
$o = getopt('', ['url:', 'password:', 'db::']);
$URL = rtrim($o['url'] ?? '', '/');
$PASS = $o['password'] ?? '';
$DB = $o['db'] ?? null;
if ($URL === '' || $PASS === '') {
    exit("Chybí --url a --password.\n");
}

/* ---------------------------------------------------------------- pomocné funkce */
$cookie = tempnam(sys_get_temp_dir(), 'kvztest');
$csrf = '';
$pass = 0;
$fail = 0;
$cleanup = ['games' => [], 'quizzes' => []];

/** Vrací [HTTP kód, dekódovaný JSON nebo surové tělo]. */
function http(string $method, string $path, ?array $body = null, bool $admin = false, array $extraHeaders = []): array
{
    global $URL, $cookie, $csrf;
    $ch = curl_init($URL . $path);
    $h = array_merge(['Accept: application/json'], $extraHeaders);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => false]);
    if ($admin) {
        curl_setopt_array($ch, [CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie]);
        if ($csrf !== '') {
            $h[] = 'X-CSRF-Token: ' . $csrf;
        }
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        $h[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    $raw = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $j = json_decode($raw, true);
    return [$code, is_array($j) ? $j : $raw];
}
function ok(string $name, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  OK    $name\n";
    } else {
        $fail++;
        echo "  CHYBA $name" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}
function admin(string $path, ?array $body = null): array
{
    [$c, $j] = http($body === null ? 'GET' : 'POST', $path, $body, true);
    if ($c >= 400 && is_array($j) && isset($j['message'])) {
        return ['_error' => $j['message'], '_code' => $c];
    }
    return is_array($j) ? $j : [];
}
function newQuiz(array $questions, string $title = '__test__'): int
{
    global $cleanup;
    $r = admin('/api/admin_quiz.php', ['title' => $title, 'questions' => $questions]);
    $cleanup['quizzes'][] = $r['id'];
    return (int)$r['id'];
}
function newGame(int $quiz, array $settings, string $mode = 'screen'): array
{
    global $cleanup;
    $g = admin('/api/admin_games.php', ['action' => 'create', 'quiz_id' => $quiz, 'mode' => $mode, 'settings' => $settings]);
    $cleanup['games'][] = $g['id'];
    return $g;
}
function join_(array $g, string $name): array
{
    [, $r] = http('POST', '/api/join.php', ['pin' => $g['pin'], 'name' => $name]);
    return is_array($r) ? $r : [];
}
function stateOf(array $g, string $role, string $token): array
{
    [$c, $j] = http('GET', '/api/state.php?game=' . $g['pin'] . '&role=' . $role . '&token=' . $token);
    return is_array($j) ? $j + ['_code' => $c] : ['_code' => $c];
}
function host(array $g, string $action, array $extra = []): array
{
    [, $j] = http('POST', '/api/host_control.php', ['pin' => $g['pin'], 'key' => $g['host_token'], 'action' => $action] + $extra);
    return is_array($j) ? $j : [];
}
function hostState(array $g): array
{
    return stateOf($g, 'host', $g['host_token']);
}
function answer(array $g, array $player, array $choice): array
{
    [$c, $j] = http('POST', '/api/answer.php', ['pin' => $g['pin'], 'token' => $player['token'], 'choice' => $choice]);
    return ['_code' => $c] + (is_array($j) ? $j : []);
}
$opt = fn(string $t, bool $c) => ['text' => $t, 'correct' => $c];

/** Hodnota na cestě „a.b.0.c“; chybějící klíč vrátí MISSING (na rozdíl od ?? rozliší null od „neexistuje“). */
const MISSING = '__missing__';
function v(array $a, string $path)
{
    foreach (explode('.', $path) as $k) {
        if (!is_array($a) || !array_key_exists($k, $a)) {
            return MISSING;
        }
        $a = $a[$k];
    }
    return $a;
}

/* ---------------------------------------------------------------- 1) přihlášení */
echo "Přihlášení a ochrana adminu\n";
[$c] = http('POST', '/api/admin_login.php', ['password' => $PASS . '-spatne']);
ok('špatné heslo → 401', $c === 401, "HTTP $c");
[, $login] = http('POST', '/api/admin_login.php', ['password' => $PASS], true);
$csrf = $login['csrf'] ?? '';
ok('správné heslo → CSRF token', $csrf !== '');
[$c] = http('POST', '/api/admin_categories.php', ['action' => 'add', 'name' => 'x'], true, ['X-CSRF-Token: spatny']);
ok('špatný CSRF token → 403', $c === 403, "HTTP $c");
[$c] = http('GET', '/api/admin_quizzes.php');
ok('admin API bez přihlášení → 401', $c === 401, "HTTP $c");

/* ---------------------------------------------------------------- 2) kvíz */
echo "Kvíz: validace a uložení\n";
$bad = admin('/api/admin_quiz.php', ['title' => 'x', 'questions' => [['type' => 'choice', 'text' => 'a', 'options' => [$opt('1', false), $opt('2', false)]]]]);
ok('otázka bez správné odpovědi je odmítnuta (422)', ($bad['_code'] ?? 0) === 422);
$questions = [
    ['type' => 'choice', 'text' => 'Hlavní město ČR?', 'options' => [$opt('Praha', true), $opt('Brno', false), $opt('Plzeň', false)]],
    ['type' => 'truefalse', 'text' => 'Země je plochá', 'points' => 500, 'options' => [$opt('Pravda', false), $opt('Nepravda', true)]],
    ['type' => 'poll', 'text' => 'Oblíbená barva?', 'options' => [$opt('Červená', false), $opt('Modrá', false)]],
];
$qid = newQuiz($questions);
$saved = admin('/api/admin_quiz.php?id=' . $qid);
ok('kvíz se uložil se 3 otázkami', count($saved['questions'] ?? []) === 3);
[$c, $ej] = http('GET', '/api/admin_export.php?id=' . $qid, null, true);
ok('export vrací formát kviz-v1', is_array($ej) && ($ej['format'] ?? '') === 'kviz-v1');
$imp = admin('/api/admin_quizzes.php', ['action' => 'import', 'data' => $ej]);
if (isset($imp['id'])) {
    $cleanup['quizzes'][] = $imp['id'];
}
ok('import exportovaného kvízu', isset($imp['id']) && count(admin('/api/admin_quiz.php?id=' . $imp['id'])['questions'] ?? []) === 3);

/* ---------------------------------------------------------------- 3) hra od začátku do konce */
echo "Hra: připojení, odpovědi, bodování, pořadí\n";
$base = ['default_time_limit' => 0, 'allow_change_answer' => false, 'secret_voting' => false, 'streak_bonus' => 100,
         'speed_weight' => 50, 'visibility_host' => 'after_question', 'visibility_players' => 'after_question'];
$g = newGame($qid, $base);
ok('PIN má 6 číslic', (bool)preg_match('/^\d{6}$/', $g['pin'] ?? ''));
$bk = http('POST', '/api/host_control.php', ['pin' => $g['pin'], 'key' => 'spatny', 'action' => 'start']);
ok('špatný klíč moderátora → 403', $bk[0] === 403, 'HTTP ' . $bk[0]);
$pa = join_($g, 'Anna');
$pb = join_($g, 'Bob');
$pc = join_($g, 'anna');
ok('duplicitní jméno dostane číslo', ($pc['name'] ?? '') === 'anna 2');
[, $xss] = http('POST', '/api/join.php', ['pin' => $g['pin'], 'name' => '<b>x</b>']);
ok('HTML ve jméně je odmítnuto', is_array($xss) && isset($xss['error']));
$hs = hostState($g);
ok('lobby: moderátor vidí 3 hráče', ($hs['phase'] ?? '') === 'lobby' && count($hs['players'] ?? []) === 3);
[$c, $nc] = http('GET', '/api/state.php?game=' . $g['pin'] . '&role=host&token=' . $g['host_token'] . '&since=' . $hs['version']);
ok('since = aktuální verze → changed:false', ($nc['changed'] ?? true) === false);
ok('špatný token hráče → 401', stateOf($g, 'player', 'neexistuje')['_code'] === 401);

host($g, 'start');
$ps = stateOf($g, 'player', $pa['token']);
$hq = hostState($g);
ok('hráč vidí text otázky (výchozí phone_content), ale ne texty odpovědí', v($ps, 'question.text') === 'Hlavní město ČR?' && v($ps, 'question.options.0.text') === null);
ok('stav hráče neobsahuje žádné správné odpovědi', !preg_match('/"correct"\s*:\s*\[/', json_encode($ps)) && !isset($ps['reveal']));
ok('moderátor vidí text i odpovědi', ($hq['question']['options'][0]['text'] ?? '') === 'Praha');
answer($g, $pa, [0]);
$again = answer($g, $pa, [1]);
ok('druhý hlas (změna) je při vypnuté změně odmítnut', $again['_code'] === 422);
ok('neplatná pozice odpovědi je odmítnuta', answer($g, $pb, [9])['_code'] === 422);
answer($g, $pb, [1]);
$mid = hostState($g);
ok('počet odpovědí 2, výsledky zatím skryté', v($mid, 'question.answered_count') === 2 && v($mid, 'question.counts') === null);
answer($g, $pc, [0]);
$rv = hostState($g);
ok('všichni odpověděli → odhalení', ($rv['phase'] ?? '') === 'reveal');
ok('správná odpověď [0], počty 2/1/0', ($rv['reveal']['correct'] ?? []) === [0] && ($rv['reveal']['counts'] ?? []) === [2, 1, 0]);
ok('netajné hlasování: moderátor vidí, kdo jak hlasoval', count($rv['reveal']['votes'] ?? []) === 3);
$ra = stateOf($g, 'player', $pa['token']);
$rb = stateOf($g, 'player', $pb['token']);
ok('Anna +1000 (bez limitu plný základ)', ($ra['me']['points_gained'] ?? 0) === 1000 && ($ra['me']['was_correct'] ?? null) === true);
ok('Bob 0 bodů', ($rb['me']['score'] ?? -1) === 0 && ($rb['me']['was_correct'] ?? null) === false);
ok('hráč nedostane cizí hlasy (votes)', !isset($ra['reveal']['votes']));
host($g, 'next');
$lb = hostState($g);
ok('žebříček: Anna i anna 2 sdílí 1. místo, Bob 3.', ($lb['phase'] ?? '') === 'leaderboard' && ($lb['leaderboard'][0]['rank'] ?? 0) === 1 && ($lb['leaderboard'][1]['rank'] ?? 0) === 1 && ($lb['leaderboard'][2]['rank'] ?? 0) === 3);
host($g, 'next');
answer($g, $pa, [1]);
answer($g, $pb, [1]);
answer($g, $pc, [0]);
$sa = stateOf($g, 'player', $pa['token']);
ok('série: 500 + 100 = 600, celkem 1600', ($sa['me']['points_gained'] ?? 0) === 600 && ($sa['me']['score'] ?? 0) === 1600 && ($sa['me']['streak'] ?? 0) === 2);
host($g, 'next');
host($g, 'next');
answer($g, $pa, [0]);
answer($g, $pb, [1]);
answer($g, $pc, [0]);
$sp = stateOf($g, 'player', $pa['token']);
ok('anketa: 0 bodů, série se nepřeruší, score 1600', v($sp, 'phase') === 'reveal' && v($sp, 'me.points_gained') === 0 && v($sp, 'me.was_correct') === null && v($sp, 'me.score') === 1600);
host($g, 'next');
ok('poslední otázka: is_last a konečné pořadí', (hostState($g)['game']['is_last'] ?? false) === true);
host($g, 'next');
$fin = hostState($g);
ok('hra skončila, souhrn otázek', ($fin['phase'] ?? '') === 'finished' && count($fin['question_results'] ?? []) === 3);
ok('do skončené hry se nejde připojit', isset(join_($g, 'Pozdě')['error']));
$csvRaw = file_get_contents($URL . '/api/host_export.php?pin=' . $g['pin'] . '&key=' . $g['host_token']);
ok('CSV export: BOM, hlavička a řádky hráčů', str_starts_with((string)$csvRaw, "\xEF\xBB\xBF") && str_contains((string)$csvRaw, 'Anna'));

/* ---------------------------------------------------------------- 4) režimy průběžného pořadí */
echo "Průběžné pořadí: režimy\n";
foreach ([['every', 3, ['leaderboard', 'leaderboard', 'leaderboard']],
          ['interval', 2, ['question', 'leaderboard', 'finished']],
          ['end', 3, ['question', 'question', 'leaderboard']],
          ['never', 3, ['question', 'question', 'finished']]] as [$mode, $n, $expect]) {
    $gm = newGame($qid, ['default_time_limit' => 0, 'allow_change_answer' => false, 'leaderboard_mode' => $mode, 'leaderboard_every' => $n]);
    $pm = join_($gm, 'T');
    host($gm, 'start');
    $trace = [];
    for ($i = 0; $i < 3; $i++) {
        answer($gm, $pm, [0]);                       // jediný hráč → okamžité odhalení
        host($gm, 'next');                           // reveal → ?
        $ph = hostState($gm)['phase'];
        $trace[] = $ph;
        if ($ph === 'leaderboard') {
            host($gm, 'next');
        }
        if ($ph === 'finished') {
            break;
        }
    }
    ok("režim $mode (N=$n): " . implode(' → ', $expect), $trace === $expect, implode(' → ', $trace));
}

/* ---------------------------------------------------------------- 5) tajné hlasování a viditelnost */
echo "Tajné hlasování a viditelnost výsledků\n";
$gs = newGame($qid, ['default_time_limit' => 0, 'allow_change_answer' => false, 'secret_voting' => true,
                     'visibility_host' => 'live', 'visibility_players' => 'never']);
$e = join_($gs, 'Ema');
$f = join_($gs, 'Filip');
host($gs, 'start');
answer($gs, $e, [0]);
$h1 = hostState($gs);
$p1 = stateOf($gs, 'player', $f['token']);
ok('moderátor vidí živé počty [1,0,0]', ($h1['question']['counts'] ?? []) === [1, 0, 0]);
ok('hráč počty nevidí (visibility_players=never)', v($p1, 'question.counts') === null);
answer($gs, $f, [1]);
$h2 = hostState($gs);
ok('tajné: po otázce nejsou k dispozici hlasy jednotlivců (votes)', !isset($h2['reveal']['votes']) && ($h2['reveal']['counts'] ?? []) === [1, 1, 0]);
if ($DB && is_file($DB)) {
    $pdo = new PDO('sqlite:' . $DB);
    $n = (int)$pdo->query('SELECT COUNT(*) FROM responses WHERE game_id=' . (int)$gs['id'] . ' AND choice IS NOT NULL')->fetchColumn();
    $tot = (int)$pdo->query('SELECT COUNT(*) FROM responses WHERE game_id=' . (int)$gs['id'])->fetchColumn();
    ok('tajné: v databázi nezůstala žádná volba (jen body)', $n === 0 && $tot === 2, "s volbou: $n, celkem: $tot");
} else {
    echo "  přeskočeno: kontrola databáze (zadejte --db=cesta/k/kviz.sqlite)\n";
}

/* ---------------------------------------------------------------- 6) hlasový režim */
echo "Hlasový režim: čtení otázky před odpočtem\n";
$gv = newGame($qid, ['default_time_limit' => 10, 'allow_change_answer' => true], 'voice');
$v = join_($gv, 'Pepa');
host($gv, 'start');
$hv = hostState($gv);
$pv = stateOf($gv, 'player', $v['token']);
ok('otázka se čte (reading) a odpočet neběží', v($hv, 'question.reading') === true && v($hv, 'question.ends_at') === null);
ok('hráči dostanou text otázky i odpovědí', ($pv['question']['text'] ?? '') !== '' && ($pv['question']['options'][0]['text'] ?? '') === 'Praha');
ok('během čtení nelze odpovědět', answer($gv, $v, [0])['_code'] === 422);
host($gv, 'tts_done');
$hv2 = hostState($gv);
$left = ($hv2['question']['ends_at'] ?? 0) - ($hv2['server_time'] ?? 0);
ok('po tts_done běží odpočet ~10 s', ($hv2['question']['reading'] ?? true) === false && $left > 8000 && $left <= 10000, "zbývá $left ms");
ok('po začátku odpočtu jde odpovědět', answer($gv, $v, [0])['_code'] === 200);
$gp = newGame($qid, ['default_time_limit' => 10], 'voice');
join_($gp, 'Pepa');
host($gp, 'start');
host($gp, 'pause');
host($gp, 'tts_done');
$hp = hostState($gp);
ok('pauza při čtení: čtení skončilo, odpočet čeká', ($hp['paused'] ?? false) === true && ($hp['question']['paused_remaining'] ?? 0) === 10000);
host($gp, 'resume');
ok('po Pokračovat odpočet běží', ($h = hostState($gp)) && ($h['question']['ends_at'] ?? 0) > ($h['server_time'] ?? PHP_INT_MAX));

/* ---------------------------------------------------------------- 7) časovač a pauza */
echo "Časovač: vypršení limitu a pauza\n";
$gt = newGame($qid, ['default_time_limit' => 5, 'allow_change_answer' => true, 'secret_voting' => false]);
$t = join_($gt, 'Cyril');
host($gt, 'start');
answer($gt, $t, [1]);
answer($gt, $t, [0]);
$tl = hostState($gt);
ok('změna odpovědi je povolena (počet odpovědí 1)', ($tl['question']['answered_count'] ?? 0) === 1);
host($gt, 'pause');
sleep(6);
$tp = hostState($gt);
ok('pauza zastaví odpočet (stále otázka)', ($tp['phase'] ?? '') === 'question' && ($tp['paused'] ?? false) === true);
ok('při pauze nelze odpovídat', answer($gt, $t, [2])['_code'] === 422);
host($gt, 'resume');
sleep(6);
ok('po vypršení limitu server sám přejde na odhalení', (hostState($gt)['phase'] ?? '') === 'reveal');
ok('odpověď po limitu je odmítnuta', answer($gt, $t, [1])['_code'] === 422);

/* ---------------------------------------------------------------- 8) CSV a vyhození hráče */
echo "CSV a vyhození hráče\n";
$gc = newGame($qid, ['default_time_limit' => 0, 'allow_change_answer' => false]);
$c1 = join_($gc, '=SUM(1)');
$c2 = join_($gc, 'Jana');
host($gc, 'start');
answer($gc, $c1, [0]);
answer($gc, $c2, [1]);
$csv = (string)file_get_contents($URL . '/api/host_export.php?pin=' . $gc['pin'] . '&key=' . $gc['host_token']);
ok('CSV: vzorec ve jménu je zneškodněn apostrofem', str_contains($csv, "\"'=SUM(1)\""));
$bk = http('GET', '/api/host_export.php?pin=' . $gc['pin'] . '&key=spatny');
ok('CSV se špatným klíčem → 403', $bk[0] === 403);
$gk = newGame($qid, ['default_time_limit' => 0]);
$k1 = join_($gk, 'Karel');
$lobby = hostState($gk);
$kid = $lobby['players'][0]['id'] ?? 0;
host($gk, 'kick', ['player_id' => $kid]);
ok('vyhozený hráč dostane 403', stateOf($gk, 'player', $k1['token'])['_code'] === 403);

/* ---------------------------------------------------------------- úklid a shrnutí */
foreach ($cleanup['games'] as $id) {
    admin('/api/admin_games.php', ['action' => 'delete', 'id' => $id]);
}
foreach (array_unique($cleanup['quizzes']) as $id) {
    admin('/api/admin_quizzes.php', ['action' => 'delete', 'id' => $id]);
}
@unlink($cookie);
echo "\nVýsledek: $pass v pořádku, $fail chyb.\n";
exit($fail ? 1 : 0);
