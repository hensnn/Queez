<?php
/**
 * Zátěžový test: simuluje hru s N hráči, kteří SOUČASNĚ (v jedné vteřině) pollují stav a odpovídají.
 * To je nejhorší případ – všichni klienti se ozvou ve stejný okamžik. Když má PHP-FPM málo pracovníků,
 * požadavky se řadí do fronty a odezvy rostou.
 *
 * Použití (z libovolného počítače s PHP, např. přímo na serveru):
 *   php tools/loadtest.php --url=http://adresa-serveru:87 --password=ADMIN_HESLO [--players=24] [--questions=4] [--ticks=1000]
 *
 * Test si sám vytvoří dočasný kvíz a hru a na konci je smaže.
 * Spouštějte jen proti vlastní instalaci.
 */
if (PHP_SAPI !== 'cli') {
    exit("Spouští se z příkazové řádky.\n");
}
$o = getopt('', ['url:', 'password:', 'players::', 'questions::', 'tick::']);
$url = rtrim($o['url'] ?? '', '/');
$pass = $o['password'] ?? '';
$N = max(2, (int)($o['players'] ?? 24));
$Q = max(1, min(20, (int)($o['questions'] ?? 4)));
$tickMs = max(200, (int)($o['tick'] ?? 1000));
if ($url === '' || $pass === '') {
    exit("Chybí --url a --password.\n");
}

/* ---------------- pomocné funkce ---------------- */
$cookie = tempnam(sys_get_temp_dir(), 'kvzlt');
$csrf = '';
function req(string $method, string $path, ?array $body = null, array $hdr = [], bool $admin = false)
{
    global $url, $cookie;
    $ch = curl_init($url . $path);
    $h = array_merge(['Accept: application/json'], $hdr);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method]);
    if ($admin) {
        curl_setopt_array($ch, [CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie]);
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $h[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $h);
    return $ch;
}
function call(string $method, string $path, ?array $body = null, bool $admin = false): array
{
    global $csrf;
    $ch = req($method, $path, $body, $csrf && $admin ? ['X-CSRF-Token: ' . $csrf] : [], $admin);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $j = json_decode((string)$raw, true);
    if ($code >= 400 || !is_array($j)) {
        throw new RuntimeException("$method $path → HTTP $code " . substr((string)$raw, 0, 200));
    }
    return $j;
}
/** Pošle všechny požadavky najednou a vrátí [odezvy v ms, počet chyb, odpovědi]. */
function burst(array $chs): array
{
    $mh = curl_multi_init();
    foreach ($chs as $ch) {
        curl_multi_add_handle($mh, $ch);
    }
    do {
        $st = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 0.05);
        }
    } while ($running && $st === CURLM_OK);
    $times = [];
    $errs = 0;
    $bodies = [];
    foreach ($chs as $k => $ch) {
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $times[] = curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000;
        $b = json_decode((string)curl_multi_getcontent($ch), true);
        $bodies[$k] = $b;
        if ($code >= 500 || $code === 0) {
            $errs++;
        }
        curl_multi_remove_handle($mh, $ch);
    }
    return [$times, $errs, $bodies];
}
function pct(array $a, float $p): float
{
    if (!$a) {
        return 0;
    }
    sort($a);
    return $a[(int)min(count($a) - 1, floor($p * count($a)))];
}
function line(string $label, array $t, int $errs): void
{
    printf("  %-22s n=%-5d  p50=%6.0f ms  p95=%6.0f ms  max=%6.0f ms  chyb=%d\n", $label, count($t), pct($t, .5), pct($t, .95), $t ? max($t) : 0, $errs);
}

/* ---------------- příprava ---------------- */
echo "Přihlašuji do adminu a připravuji dočasný kvíz…\n";
$l = call('POST', '/api/admin_login.php', ['password' => $pass], true);
$csrf = $l['csrf'];
$questions = [];
for ($i = 1; $i <= $Q; $i++) {
    $questions[] = ['type' => 'choice', 'text' => "Zátěžová otázka $i", 'options' => [
        ['text' => 'A', 'correct' => true], ['text' => 'B', 'correct' => false],
        ['text' => 'C', 'correct' => false], ['text' => 'D', 'correct' => false]]];
}
$quiz = call('POST', '/api/admin_quiz.php', ['title' => '__zatezovy_test__', 'questions' => $questions], true);
$game = call('POST', '/api/admin_games.php', ['action' => 'create', 'quiz_id' => $quiz['id'], 'mode' => 'screen',
    'settings' => ['default_time_limit' => 0, 'allow_change_answer' => false, 'max_players' => 200]], true);
$pin = $game['pin'];
$key = $game['host_token'];
$cleanup = function () use ($game, $quiz, $cookie) {
    try {
        call('POST', '/api/admin_games.php', ['action' => 'delete', 'id' => $game['id']], true);
        call('POST', '/api/admin_quizzes.php', ['action' => 'delete', 'id' => $quiz['id']], true);
    } catch (Throwable $e) {
        echo "Úklid se nepovedl: " . $e->getMessage() . "\n";
    }
    @unlink($cookie);
};

try {
    // základní odezva jednoho klienta (bez souběhu)
    $base = [];
    for ($i = 0; $i < 10; $i++) {
        $ch = req('GET', "/api/state.php?game=$pin&role=host&token=$key");
        [$t] = burst([$ch]);
        $base[] = $t[0];
        usleep(50000);
    }
    printf("Základní odezva jednoho požadavku: p50 = %.0f ms\n\n", pct($base, .5));

    echo "Připojuji $N hráčů (všichni najednou)…\n";
    $chs = [];
    for ($i = 1; $i <= $N; $i++) {
        $chs[$i] = req('POST', '/api/join.php', ['pin' => $pin, 'name' => "Hráč $i"]);
    }
    [$tj, $ej, $bj] = burst($chs);
    $tokens = [];
    foreach ($bj as $b) {
        if (isset($b['token'])) {
            $tokens[] = $b['token'];
        }
    }
    line('join', $tj, $ej);
    if (count($tokens) !== $N) {
        throw new RuntimeException('Připojilo se jen ' . count($tokens) . ' z ' . $N . ' hráčů.');
    }
    call('POST', '/api/host_control.php', ['pin' => $pin, 'key' => $key, 'action' => 'start']);

    $stateT = [];
    $stateE = 0;
    $ansT = [];
    $ansE = 0;
    $slowTicks = 0;
    $ticks = 0;
    $phase = 'question';
    $answered = [];                       // [otázka => [hráč => true]]
    $qIdx = 0;
    $deadline = time() + 120;
    $ticksInQuestion = 0;

    while ($phase !== 'finished' && time() < $deadline) {
        $t0 = microtime(true);
        $ticks++;
        // 1) všichni hráči + moderátor pollují ve stejný okamžik
        $chs = [];
        foreach ($tokens as $i => $tok) {
            $chs[$i] = req('GET', "/api/state.php?game=$pin&role=player&token=$tok");
        }
        $chs['host'] = req('GET', "/api/state.php?game=$pin&role=host&token=$key");
        [$t, $e, $bodies] = burst($chs);
        $stateT = array_merge($stateT, $t);
        $stateE += $e;
        if (pct($t, .95) > 500) {
            $slowTicks++;
        }
        $h = $bodies['host'] ?? [];
        $phase = $h['phase'] ?? $phase;

        // 2) ve fázi otázky odpovídají hráči postupně (3 vlny během prvních vteřin)
        if ($phase === 'question') {
            $qIdx = $h['question']['index'] ?? $qIdx;
            $ticksInQuestion++;
            $wave = [];
            foreach ($tokens as $i => $tok) {
                if (empty($answered[$qIdx][$i]) && ($i % 3) === ($ticksInQuestion - 1) % 3) {
                    $wave[$i] = req('POST', '/api/answer.php', ['pin' => $pin, 'token' => $tok, 'choice' => [random_int(0, 3)]]);
                    $answered[$qIdx][$i] = true;
                }
            }
            if ($wave) {
                [$ta, $ea] = burst($wave);
                $ansT = array_merge($ansT, $ta);
                $ansE += $ea;
            }
        } elseif ($phase === 'reveal' || $phase === 'leaderboard') {
            call('POST', '/api/host_control.php', ['pin' => $pin, 'key' => $key, 'action' => 'next']);
            $ticksInQuestion = 0;
        }
        $spent = (microtime(true) - $t0) * 1000;
        if ($spent < $tickMs) {
            usleep((int)(($tickMs - $spent) * 1000));
        }
    }

    $fin = call('GET', "/api/state.php?game=$pin&role=host&token=$key");
    echo "\nVýsledky ($ticks vteřinových kol, $N hráčů, $Q otázek):\n";
    line('state (polling)', $stateT, $stateE);
    line('answer', $ansT, $ansE);
    printf("  kol, kdy p95 pollingu přesáhlo 500 ms: %d z %d\n", $slowTicks, $ticks);

    $ok = true;
    $report = [];
    if ($fin['phase'] !== 'finished') {
        $ok = false;
        $report[] = 'Hra do 2 minut neskončila.';
    }
    if (($fin['game']['players_count'] ?? 0) !== $N) {
        $ok = false;
        $report[] = 'Počet hráčů nesouhlasí.';
    }
    if ($stateE + $ansE > 0) {
        $ok = false;
        $report[] = 'Server vracel chyby (HTTP 5xx / bez odpovědi): ' . ($stateE + $ansE);
    }
    $p95 = pct($stateT, .95);
    $limit = max(250, $base ? pct($base, .5) * 8 : 250);
    if ($p95 > $limit) {
        $ok = false;
        $report[] = sprintf('Odezva pollingu je vysoká (p95 %.0f ms při základu %.0f ms) – požadavky se pravděpodobně řadí do fronty. Zvyšte pm.max_children.', $p95, pct($base, .5));
    }
    echo "\n" . ($ok ? sprintf("VÝSLEDEK: V POŘÁDKU – odezvy při %d souběžných hráčích jsou v normě (p95 %.0f ms, limit %.0f ms), žádné chyby.\n", $N, $p95, $limit)
                     : "VÝSLEDEK: POZOR\n  - " . implode("\n  - ", $report) . "\n");
    $cleanup();
    exit($ok ? 0 : 1);
} catch (Throwable $e) {
    echo "CHYBA TESTU: " . $e->getMessage() . "\n";
    $cleanup();
    exit(2);
}
