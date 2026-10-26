<?php
declare(strict_types=1);

/**
 * Herní logika. Stav hry je v tabulce games (phase + version); všechny změny stavu běží
 * v transakci BEGIN IMMEDIATE, takže se souběžné požadavky řadí za sebe.
 *
 * Fáze: lobby → question → reveal → leaderboard → (question …) → finished
 */
final class Game
{
    private const NAME_MAX = 20;
    private const READING_TIMEOUT_MS = 45000;   // pojistka: nejdéle tak dlouho čeká hlasový režim na dočtení otázky

    /* ------------------------------------------------------------------ načítání */

    public static function byPin(string $pin): ?array
    {
        $g = Db::one('SELECT * FROM games WHERE pin=?', [$pin]);
        return $g ? self::hydrate($g) : null;
    }

    public static function byId(int $id): ?array
    {
        $g = Db::one('SELECT * FROM games WHERE id=?', [$id]);
        return $g ? self::hydrate($g) : null;
    }

    private static function hydrate(array $g): array
    {
        $g['s'] = json_decode($g['settings_json'], true) ?: [];
        return $g;
    }

    private static function question(int $gameId, int $idx): ?array
    {
        $q = Db::one('SELECT * FROM game_questions WHERE game_id=? AND idx=?', [$gameId, $idx]);
        if ($q) {
            $q['options'] = json_decode($q['options_json'], true);
        }
        return $q;
    }

    private static function correctPositions(array $q): array
    {
        $c = [];
        foreach ($q['options'] as $o) {
            if (!empty($o['correct'])) {
                $c[] = (int)$o['pos'];
            }
        }
        return $c;
    }

    public static function requireHost(string $pin, string $token): array
    {
        $g = self::byPin($pin);
        if (!$g) {
            Http::fail(404, 'no_game', 'Hra neexistuje.');
        }
        if ($token === '' || !hash_equals($g['host_token'], $token)) {
            Http::fail(403, 'bad_key', 'Neplatný klíč moderátora.');
        }
        return $g;
    }

    /* ------------------------------------------------------------------ vytvoření hry */

    public static function create(int $quizId, string $mode, array $overrides): array
    {
        $quiz = Quiz::get($quizId);
        if (!$quiz || !$quiz['questions']) {
            throw new InvalidArgumentException('Kvíz neexistuje nebo nemá žádné otázky.');
        }
        if (!in_array($mode, ['screen', 'voice'], true)) {
            $mode = 'screen';
        }
        $s = array_merge(Settings::all(), (array)$quiz['settings'], Settings::cleanQuiz($overrides));
        $s['_title'] = $quiz['title'];
        $s['_total'] = count($quiz['questions']);

        $days = (int)$s['cleanup_days'];
        if ($days > 0) {
            Db::exec('DELETE FROM games WHERE created_at < ?', [time() - $days * 86400]);
        }

        $questions = $quiz['questions'];
        if (!empty($s['shuffle_questions'])) {
            shuffle($questions);
        }

        return Db::tx(function () use ($quiz, $questions, $s, $mode) {
            $pin = null;
            for ($i = 0; $i < 30; $i++) {
                $try = (string)random_int(100000, 999999);
                if (!Db::one('SELECT 1 FROM games WHERE pin=?', [$try])) {
                    $pin = $try;
                    break;
                }
            }
            if ($pin === null) {
                throw new RuntimeException('Nepodařilo se vygenerovat PIN.');
            }
            $token = bin2hex(random_bytes(12));
            Db::exec(
                'INSERT INTO games(pin,quiz_id,host_token,mode,settings_json,phase_changed_at,created_at)
                 VALUES(?,?,?,?,?,?,?)',
                [$pin, $quiz['id'], $token, $mode, json_encode($s, JSON_UNESCAPED_UNICODE), now_ms(), time()]
            );
            $gid = Db::lastId();
            foreach (array_values($questions) as $idx => $q) {
                $opts = $q['options'];
                if (!empty($s['shuffle_answers']) && $q['type'] === 'choice') {
                    shuffle($opts);
                }
                $o = [];
                foreach (array_values($opts) as $pos => $x) {
                    $o[] = ['pos' => $pos, 'text' => $x['text'], 'correct' => (bool)$x['correct']];
                }
                $limit = $q['time_limit'] ?? (int)$s['default_time_limit'];
                $points = $q['type'] === 'poll' ? 0 : ($q['points'] ?? (int)$s['default_points']);
                Db::exec(
                    'INSERT INTO game_questions(game_id,idx,question_id,type,text,image,time_limit,points,explanation,options_json)
                     VALUES(?,?,?,?,?,?,?,?,?,?)',
                    [$gid, $idx, $q['id'], $q['type'], $q['text'], $q['image'], $limit, $points,
                     $q['explanation'] !== '' ? $q['explanation'] : null, json_encode($o, JSON_UNESCAPED_UNICODE)]
                );
            }
            return ['id' => $gid, 'pin' => $pin, 'host_token' => $token];
        });
    }

    /* ------------------------------------------------------------------ hráči */

    public static function join(string $pin, string $rawName): array
    {
        $name = preg_replace('/\s+/u', ' ', trim($rawName)) ?? '';
        $len = mb_strlen($name);
        if ($len < 1 || $len > self::NAME_MAX) {
            throw new InvalidArgumentException('Jméno musí mít 1 až ' . self::NAME_MAX . ' znaků.');
        }
        if (preg_match('/[<>\x00-\x1f\x7f]/u', $name)) {
            throw new InvalidArgumentException('Jméno obsahuje nepovolené znaky.');
        }

        return Db::tx(function () use ($pin, $name) {
            $g = self::byPin($pin);
            if (!$g || $g['phase'] === 'finished') {
                throw new InvalidArgumentException('Hra s tímto PINem neexistuje nebo už skončila.');
            }
            $s = $g['s'];
            if ($g['phase'] !== 'lobby' && empty($s['allow_join_after_start'])) {
                throw new InvalidArgumentException('Hra už začala, nové hráče nepřijímá.');
            }
            if ((int)$g['players_count'] >= (int)$s['max_players']) {
                throw new InvalidArgumentException('Hra je plná.');
            }
            $ci = !empty($s['unique_names']);
            $norm = fn(string $n) => $ci ? mb_strtolower($n) : $n;
            $taken = [];
            foreach (Db::all('SELECT name FROM players WHERE game_id=?', [$g['id']]) as $r) {
                $taken[$norm($r['name'])] = true;
            }
            $final = $name;
            for ($n = 2; isset($taken[$norm($final)]); $n++) {
                $final = $name . ' ' . $n;
            }
            $token = bin2hex(random_bytes(16));
            Db::exec(
                'INSERT INTO players(game_id,token,name,joined_at,last_seen) VALUES(?,?,?,?,?)',
                [$g['id'], $token, $final, time(), time()]
            );
            Db::exec('UPDATE games SET players_count=players_count+1, version=version+1 WHERE id=?', [$g['id']]);
            return ['token' => $token, 'name' => $final, 'pin' => $pin];
        });
    }

    public static function kick(array $g, int $playerId): void
    {
        Db::tx(function () use ($g, $playerId) {
            $p = Db::one('SELECT id FROM players WHERE id=? AND game_id=? AND kicked=0', [$playerId, $g['id']]);
            if (!$p) {
                return;
            }
            Db::exec('UPDATE players SET kicked=1 WHERE id=?', [$playerId]);
            Db::exec('DELETE FROM responses WHERE player_id=? AND game_id=?', [$playerId, $g['id']]);
            Db::exec(
                'UPDATE games SET version=version+1,
                   players_count=(SELECT COUNT(*) FROM players WHERE game_id=? AND kicked=0),
                   answered_count=(SELECT COUNT(*) FROM responses WHERE game_id=? AND q_idx=games.current_index)
                 WHERE id=?',
                [$g['id'], $g['id'], $g['id']]
            );
        });
    }

    /* ------------------------------------------------------------------ odpovědi */

    public static function answer(array $g, array $player, $choice): void
    {
        if (!is_array($choice)) {
            throw new InvalidArgumentException('Chybí odpověď.');
        }
        Db::tx(function () use ($g, $player, $choice) {
            $g = self::byId((int)$g['id']);
            $s = $g['s'];
            if ($g['phase'] !== 'question' || $g['paused']) {
                throw new InvalidArgumentException('Otázka teď neběží.');
            }
            if ($g['reading']) {
                throw new InvalidArgumentException('Otázka se ještě čte, počkejte na začátek odpočtu.');
            }
            $now = now_ms();
            if ($g['question_ends_at'] !== null && $now > (int)$g['question_ends_at']) {
                throw new InvalidArgumentException('Čas vypršel.');
            }
            $idx = (int)$g['current_index'];
            $q = self::question((int)$g['id'], $idx);
            $n = count($q['options']);
            $multi = $q['type'] === 'choice' && count(self::correctPositions($q)) > 1;

            $c = [];
            foreach ($choice as $v) {
                if (!is_int($v) && !(is_string($v) && ctype_digit($v))) {
                    throw new InvalidArgumentException('Neplatná odpověď.');
                }
                $v = (int)$v;
                if ($v < 0 || $v >= $n) {
                    throw new InvalidArgumentException('Neplatná odpověď.');
                }
                $c[$v] = $v;
            }
            $c = array_values($c);
            sort($c);
            if (!$c || (!$multi && count($c) !== 1)) {
                throw new InvalidArgumentException($multi ? 'Vyberte aspoň jednu možnost.' : 'Vyberte jednu možnost.');
            }

            $ex = Db::one('SELECT 1 FROM responses WHERE game_id=? AND q_idx=? AND player_id=?', [$g['id'], $idx, $player['id']]);
            if ($ex && empty($s['allow_change_answer'])) {
                throw new InvalidArgumentException('Odpověď už jste odeslali.');
            }
            if ($ex) {
                Db::exec(
                    'UPDATE responses SET choice=?, answered_at=? WHERE game_id=? AND q_idx=? AND player_id=?',
                    [json_encode($c), $now, $g['id'], $idx, $player['id']]
                );
                Db::exec('UPDATE games SET version=version+1 WHERE id=?', [$g['id']]);
            } else {
                Db::exec(
                    'INSERT INTO responses(game_id,q_idx,player_id,choice,answered_at) VALUES(?,?,?,?,?)',
                    [$g['id'], $idx, $player['id'], json_encode($c), $now]
                );
                Db::exec('UPDATE games SET answered_count=answered_count+1, version=version+1 WHERE id=?', [$g['id']]);
                // Všichni odpověděli → hned uzavřít (jen když nejde odpověď měnit)
                if (empty($s['allow_change_answer'])) {
                    $g = self::byId((int)$g['id']);
                    if ((int)$g['players_count'] > 0 && (int)$g['answered_count'] >= (int)$g['players_count']) {
                        self::closeQuestion($g);
                    }
                }
            }
        });
    }

    /* ------------------------------------------------------------------ přechody fází (volat uvnitř transakce) */

    private static function openQuestion(array $g, int $idx): void
    {
        $q = self::question((int)$g['id'], $idx);
        if (!$q) {
            self::finish($g);
            return;
        }
        $now = now_ms();
        $limit = (int)$q['time_limit'];
        // Hlasový režim: nejdřív se otázka předčítá (reading=1), odpočet začne až po signálu „dočteno“.
        $reading = $g['mode'] === 'voice' ? 1 : 0;
        Db::exec(
            "UPDATE games SET phase='question', current_index=?, question_started_at=?, question_ends_at=?,
               phase_changed_at=?, answered_count=0, paused_remaining=NULL, reading=?,
               started_at=COALESCE(started_at,?), version=version+1 WHERE id=?",
            [$idx, $now, ($limit > 0 && !$reading) ? $now + $limit * 1000 : null, $now, $reading, time(), $g['id']]
        );
    }

    /** Konec předčítání: spustí odpočet otázky (volá moderátor po dočtení, nebo server po vypršení pojistky). */
    private static function startCountdown(array $g): void
    {
        if ($g['phase'] !== 'question' || !$g['reading']) {
            return;
        }
        $q = self::question((int)$g['id'], (int)$g['current_index']);
        $limitMs = (int)$q['time_limit'] * 1000;
        $now = now_ms();
        if ($g['paused']) {
            // při pauze se odpočet jen připraví; poběží po „Pokračovat“
            Db::exec(
                'UPDATE games SET reading=0, question_started_at=?, paused_remaining=?, version=version+1 WHERE id=?',
                [$now, $limitMs > 0 ? $limitMs : null, $g['id']]
            );
            return;
        }
        Db::exec(
            'UPDATE games SET reading=0, question_started_at=?, question_ends_at=?, version=version+1 WHERE id=?',
            [$now, $limitMs > 0 ? $now + $limitMs : null, $g['id']]
        );
    }

    private static function closeQuestion(array $g): void
    {
        $gid = (int)$g['id'];
        $idx = (int)$g['current_index'];
        $s = $g['s'];
        $q = self::question($gid, $idx);
        $correct = self::correctPositions($q);
        $limitMs = (int)$q['time_limit'] * 1000;
        $start = (int)$g['question_started_at'];
        $secret = !empty($s['secret_voting']);

        $resp = [];
        foreach (Db::all('SELECT player_id, choice, answered_at FROM responses WHERE game_id=? AND q_idx=?', [$gid, $idx]) as $r) {
            $resp[(int)$r['player_id']] = $r;
        }
        $counts = array_fill(0, count($q['options']), 0);

        foreach (Db::all('SELECT id, streak FROM players WHERE game_id=? AND kicked=0', [$gid]) as $p) {
            $pid = (int)$p['id'];
            $streak = (int)$p['streak'];
            if (isset($resp[$pid])) {
                $chosen = json_decode((string)$resp[$pid]['choice'], true) ?: [];
                foreach ($chosen as $pos) {
                    if (isset($counts[$pos])) {
                        $counts[$pos]++;
                    }
                }
                [$ok, $pts] = Scoring::evaluate(
                    $q['type'], $chosen, $correct, (int)$q['points'], $s,
                    max(0, (int)$resp[$pid]['answered_at'] - $start), $limitMs, $streak
                );
                $newStreak = $ok === null ? $streak : ($ok ? $streak + 1 : 0);
                Db::exec(
                    'UPDATE responses SET correct=?, points=?' . ($secret ? ', choice=NULL' : '') .
                    ' WHERE game_id=? AND q_idx=? AND player_id=?',
                    [$ok === null ? null : ($ok ? 1 : 0), $pts, $gid, $idx, $pid]
                );
                Db::exec('UPDATE players SET score=score+?, streak=? WHERE id=?', [$pts, $newStreak, $pid]);
            } elseif ($q['type'] !== 'poll' && $streak !== 0) {
                Db::exec('UPDATE players SET streak=0 WHERE id=?', [$pid]);
            }
        }
        Db::exec('DELETE FROM option_counts WHERE game_id=? AND q_idx=?', [$gid, $idx]);
        foreach ($counts as $pos => $cnt) {
            Db::exec('INSERT INTO option_counts(game_id,q_idx,opt_pos,cnt) VALUES(?,?,?,?)', [$gid, $idx, $pos, $cnt]);
        }
        Db::exec(
            "UPDATE games SET phase='reveal', phase_changed_at=?, question_ends_at=NULL, paused_remaining=NULL,
               reading=0, version=version+1 WHERE id=?",
            [now_ms(), $gid]
        );
    }

    private static function finish(array $g): void
    {
        Db::exec(
            "UPDATE games SET phase='finished', finished_at=?, phase_changed_at=?, question_ends_at=NULL,
               paused=0, paused_remaining=NULL, reading=0, version=version+1 WHERE id=?",
            [time(), now_ms(), $g['id']]
        );
    }

    /** Posun o jednu fázi dál (tlačítko „Další“ i automatický postup). */
    private static function advance(array $g): void
    {
        switch ($g['phase']) {
            case 'question':
                self::closeQuestion($g);
                break;
            case 'reveal':
                Db::exec("UPDATE games SET phase='leaderboard', phase_changed_at=?, version=version+1 WHERE id=?", [now_ms(), $g['id']]);
                break;
            case 'leaderboard':
                $next = (int)$g['current_index'] + 1;
                if ($next < (int)$g['s']['_total']) {
                    self::openQuestion($g, $next);
                } else {
                    self::finish($g);
                }
                break;
        }
    }

    private static function due(array $g): bool
    {
        if ($g['paused'] || in_array($g['phase'], ['lobby', 'finished'], true)) {
            return false;
        }
        $now = now_ms();
        if ($g['phase'] === 'question') {
            return $g['question_ends_at'] !== null && $now >= (int)$g['question_ends_at'];
        }
        return !empty($g['s']['auto_advance'])
            && $now - (int)$g['phase_changed_at'] >= (int)$g['s']['auto_advance_delay'] * 1000;
    }

    /** Provede případný čekající přechod (vypršel čas / automatický postup) a vrátí aktuální hru. */
    public static function tick(array $g): array
    {
        $readingStuck = $g['reading'] && !$g['paused'] && now_ms() - (int)$g['phase_changed_at'] > self::READING_TIMEOUT_MS;
        if (!self::due($g) && !$readingStuck) {
            return $g;
        }
        Db::tx(function () use ($g) {
            $f = self::byId((int)$g['id']);
            if (!$f) {
                return;
            }
            if ($f['reading'] && !$f['paused'] && now_ms() - (int)$f['phase_changed_at'] > self::READING_TIMEOUT_MS) {
                self::startCountdown($f);   // moderátor se neozval (vypadl telefon) → odpočet spustí server
            } elseif (self::due($f)) {
                self::advance($f);
            }
        });
        return self::byId((int)$g['id']) ?? $g;
    }

    /* ------------------------------------------------------------------ ovládání moderátorem */

    public static function control(array $g, string $action): void
    {
        Db::tx(function () use ($g, $action) {
            $g = self::byId((int)$g['id']);
            $now = now_ms();
            switch ($action) {
                case 'start':
                    if ($g['phase'] !== 'lobby') {
                        throw new InvalidArgumentException('Hra už běží.');
                    }
                    if ((int)$g['players_count'] < 1) {
                        throw new InvalidArgumentException('Ve hře zatím nejsou žádní hráči.');
                    }
                    self::openQuestion($g, 0);
                    break;
                case 'next':
                    if ($g['phase'] === 'lobby') {
                        throw new InvalidArgumentException('Hru spustíte tlačítkem Start.');
                    }
                    if ($g['paused']) {
                        throw new InvalidArgumentException('Hra je pozastavená.');
                    }
                    self::advance($g);
                    break;
                case 'tts_done':
                    self::startCountdown($g);
                    break;
                case 'pause':
                    if ($g['paused'] || in_array($g['phase'], ['lobby', 'finished'], true)) {
                        break;
                    }
                    $rem = $g['question_ends_at'] !== null ? max(0, (int)$g['question_ends_at'] - $now) : null;
                    Db::exec(
                        'UPDATE games SET paused=1, paused_remaining=?, question_ends_at=NULL, version=version+1 WHERE id=?',
                        [$rem, $g['id']]
                    );
                    break;
                case 'resume':
                    if (!$g['paused']) {
                        break;
                    }
                    $ends = ($g['phase'] === 'question' && $g['paused_remaining'] !== null)
                        ? $now + (int)$g['paused_remaining'] : null;
                    Db::exec(
                        'UPDATE games SET paused=0, paused_remaining=NULL, question_ends_at=?, phase_changed_at=?,
                           version=version+1 WHERE id=?',
                        [$ends, $now, $g['id']]
                    );
                    break;
                case 'finish':
                    if ($g['phase'] !== 'finished') {
                        self::finish($g);
                    }
                    break;
                default:
                    throw new InvalidArgumentException('Neznámá akce.');
            }
        });
    }

    /* ------------------------------------------------------------------ stav pro klienty */

    /** Které rozložení hlasů smí daná role teď vidět: none | live | final. */
    private static function countsMode(array $s, string $role, string $phase): string
    {
        $v = $role === 'host' ? ($s['visibility_host'] ?? 'after_question') : ($s['visibility_players'] ?? 'after_question');
        if ($v === 'never' || $v === 'end') {
            return 'none';
        }
        if ($v === 'live') {
            return $phase === 'question' ? 'live' : 'final';
        }
        return $phase === 'reveal' ? 'final' : 'none';
    }

    private static function liveCounts(int $gid, int $idx, int $n): array
    {
        $counts = array_fill(0, $n, 0);
        foreach (Db::all('SELECT choice FROM responses WHERE game_id=? AND q_idx=?', [$gid, $idx]) as $r) {
            foreach (json_decode((string)$r['choice'], true) ?: [] as $pos) {
                if (isset($counts[$pos])) {
                    $counts[$pos]++;
                }
            }
        }
        return $counts;
    }

    private static function finalCounts(int $gid, int $idx, int $n): array
    {
        $counts = array_fill(0, $n, 0);
        foreach (Db::all('SELECT opt_pos, cnt FROM option_counts WHERE game_id=? AND q_idx=?', [$gid, $idx]) as $r) {
            if (isset($counts[(int)$r['opt_pos']])) {
                $counts[(int)$r['opt_pos']] = (int)$r['cnt'];
            }
        }
        return $counts;
    }

    public static function state(array $g, string $role, ?int $playerId): array
    {
        $s = $g['s'];
        $gid = (int)$g['id'];
        $phase = $g['phase'];
        $isHost = $role === 'host';
        $idx = (int)$g['current_index'];

        $out = [
            'changed'          => true,
            'version'          => (int)$g['version'],
            'server_time'      => now_ms(),
            'phase'            => $phase,
            'paused'           => (bool)$g['paused'],
            'mode'             => $g['mode'],
            'poll_interval_ms' => (int)($s['poll_interval_ms'] ?? 1000),
            'game'             => [
                'pin'                 => $g['pin'],
                'title'               => $s['_title'] ?? '',
                'players_count'       => (int)$g['players_count'],
                'max_players'         => (int)($s['max_players'] ?? 0),
                'total'               => (int)($s['_total'] ?? 0),
                'is_last'             => $idx + 1 >= (int)($s['_total'] ?? 0),
                'warn_seconds'        => (int)($s['warn_seconds'] ?? 5),
                'allow_change_answer' => !empty($s['allow_change_answer']),
                'auto_advance'        => !empty($s['auto_advance']),
            ],
        ];

        $q = null;
        if ($phase === 'question' || $phase === 'reveal') {
            $q = self::question($gid, $idx);
            $phone = (string)($s['phone_content'] ?? 'question');
            $voice = $g['mode'] === 'voice';
            $showQ = $isHost || $voice || in_array($phone, ['question', 'full'], true);   // text a obrázek otázky
            $showText = $isHost || $voice || $phone === 'full';                              // texty odpovědí
            $correct = self::correctPositions($q);
            $out['question'] = [
                'index'            => $idx,
                'total'            => (int)($s['_total'] ?? 0),
                'type'             => $q['type'],
                'multi'            => $q['type'] === 'choice' && count($correct) > 1,
                'text'             => $showQ ? $q['text'] : null,
                'image'            => ($showQ && $q['image']) ? $q['image'] : null,
                'options'          => array_map(fn($o) => ['pos' => (int)$o['pos'], 'text' => $showText ? $o['text'] : null], $q['options']),
                'started_at'       => (int)$g['question_started_at'],
                'reading'          => (bool)$g['reading'],
                'ends_at'          => $g['question_ends_at'] === null ? null : (int)$g['question_ends_at'],
                'time_limit'       => (int)$q['time_limit'],
                'points'           => (int)$q['points'],
                'answered_count'   => (int)$g['answered_count'],
                'paused_remaining' => $g['paused_remaining'] === null ? null : (int)$g['paused_remaining'],
            ];
            $mode = self::countsMode($s, $role, $phase);
            $n = count($q['options']);
            $counts = $mode === 'live' ? self::liveCounts($gid, $idx, $n)
                : ($mode === 'final' ? self::finalCounts($gid, $idx, $n) : null);
            if ($phase === 'question') {
                $out['question']['counts'] = $counts;
            } else {
                $out['reveal'] = [
                    'correct'     => $correct,
                    'counts'      => $counts,
                    'explanation' => $q['explanation'],
                ];
                // Kdo jak hlasoval: jen moderátor, jen v netajné hře a jen pokud smí vidět výsledky
                if ($isHost && empty($s['secret_voting']) && $mode !== 'none') {
                    $votes = [];
                    foreach (Db::all(
                        'SELECT p.name, r.choice, r.points FROM responses r JOIN players p ON p.id=r.player_id
                         WHERE r.game_id=? AND r.q_idx=? ORDER BY r.answered_at',
                        [$gid, $idx]
                    ) as $v) {
                        $votes[] = ['name' => $v['name'], 'choice' => json_decode((string)$v['choice'], true) ?: [], 'points' => (int)$v['points']];
                    }
                    $out['reveal']['votes'] = $votes;
                }
            }
        }

        if ($isHost) {
            // nastavení předčítání pro prohlížeč moderátora
            $out['tts'] = [
                'enabled'          => !empty($s['tts_enabled']) || $g['mode'] === 'voice',
                'voice'            => (string)($s['tts_voice'] ?? ''),
                'rate'             => (float)($s['tts_rate'] ?? 1),
                'read_options'     => !empty($s['tts_read_options']),
                'read_countdown'   => !empty($s['tts_read_countdown']),
                'read_results'     => !empty($s['tts_read_results']),
                'read_leaderboard' => !empty($s['tts_read_leaderboard']),
                'read_delay'       => (int)($s['tts_read_delay'] ?? 2),
                'gemini'           => Tts::available($s),
            ];
        }

        if ($phase === 'lobby' && $isHost) {
            $out['players'] = Db::all('SELECT id, name FROM players WHERE game_id=? AND kicked=0 ORDER BY id LIMIT 300', [$gid]);
        }

        if ($phase === 'leaderboard' || $phase === 'finished') {
            $limit = $isHost ? ($phase === 'finished' ? 100 : 10) : ($phase === 'finished' ? 10 : 5);
            $rows = Db::all(
                'SELECT p.id, p.name, p.score, COALESCE(r.points,0) AS gained
                 FROM players p LEFT JOIN responses r ON r.player_id=p.id AND r.game_id=p.game_id AND r.q_idx=?
                 WHERE p.game_id=? AND p.kicked=0 ORDER BY p.score DESC, p.joined_at, p.id LIMIT ' . $limit,
                [$idx, $gid]
            );
            $lb = [];
            $rank = 0;
            $prev = null;
            foreach ($rows as $i => $r) {
                if ($prev === null || (int)$r['score'] !== $prev) {
                    $rank = $i + 1;
                }
                $prev = (int)$r['score'];
                $lb[] = ['rank' => $rank, 'name' => $r['name'], 'score' => (int)$r['score'], 'gained' => (int)$r['gained']];
            }
            $out['leaderboard'] = $lb;
        }

        if ($phase === 'finished') {
            $out['question_results'] = self::questionResults($g, $role);
        }

        if (!$isHost && $playerId !== null) {
            $out['me'] = self::me($g, $playerId, $q);
        }
        return $out;
    }

    private static function me(array $g, int $playerId, ?array $q): array
    {
        $s = $g['s'];
        $gid = (int)$g['id'];
        $phase = $g['phase'];
        $p = Db::one('SELECT name, score, streak FROM players WHERE id=?', [$playerId]);
        $me = ['name' => $p['name'], 'score' => (int)$p['score'], 'streak' => (int)$p['streak'],
               'answered' => false, 'choice' => null, 'was_correct' => null, 'points_gained' => 0, 'rank' => null];

        if ($q !== null) {
            $r = Db::one(
                'SELECT choice, correct, points FROM responses WHERE game_id=? AND q_idx=? AND player_id=?',
                [$gid, (int)$g['current_index'], $playerId]
            );
            if ($r) {
                $me['answered'] = true;
                $me['choice'] = $r['choice'] === null ? null : json_decode($r['choice'], true);
                if ($phase === 'reveal') {
                    $me['was_correct'] = $r['correct'] === null ? null : (bool)$r['correct'];
                    $me['points_gained'] = (int)$r['points'];
                } elseif ($phase === 'question' && !empty($s['show_correct_to_player']) && empty($s['allow_change_answer'])
                    && $q['type'] !== 'poll' && $me['choice'] !== null) {
                    // okamžitá zpětná vazba jen když nejde odpověď měnit (jinak by šlo hádat)
                    [$ok] = Scoring::evaluate($q['type'], $me['choice'], self::correctPositions($q), 1, [], 0, 0, 0);
                    $me['was_correct'] = $ok;
                }
            } elseif ($phase === 'reveal') {
                $me['was_correct'] = $q['type'] === 'poll' ? null : false;
            }
        }
        if (in_array($phase, ['reveal', 'leaderboard', 'finished'], true)) {
            $me['rank'] = 1 + (int)Db::val(
                'SELECT COUNT(*) FROM players WHERE game_id=? AND kicked=0 AND score > ?',
                [$gid, $p['score']]
            );
        }
        return $me;
    }

    /** Souhrn všech otázek na konci hry (rozložení hlasů dle viditelnosti). */
    private static function questionResults(array $g, string $role): array
    {
        $s = $g['s'];
        $v = $role === 'host' ? ($s['visibility_host'] ?? 'after_question') : ($s['visibility_players'] ?? 'after_question');
        $gid = (int)$g['id'];
        $out = [];
        foreach (Db::all('SELECT idx, text, options_json FROM game_questions WHERE game_id=? ORDER BY idx', [$gid]) as $r) {
            $opts = json_decode($r['options_json'], true);
            $counts = $v === 'never' ? null : self::finalCounts($gid, (int)$r['idx'], count($opts));
            $o = [];
            foreach ($opts as $i => $x) {
                $o[] = ['text' => $x['text'], 'correct' => (bool)$x['correct'], 'count' => $counts[$i] ?? null];
            }
            $out[] = ['index' => (int)$r['idx'], 'text' => $r['text'], 'options' => $o];
        }
        return $out;
    }
}
