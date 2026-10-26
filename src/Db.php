<?php
declare(strict_types=1);

final class Db
{
    private static ?PDO $pdo = null;
    private const SCHEMA_VERSION = 4;

    public static function pdo(): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        $path = (string)cfg('db_path', ROOT . '/data/kviz.sqlite');
        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0775, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA synchronous=NORMAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec('PRAGMA foreign_keys=ON');
        self::$pdo = $pdo;
        self::migrate();
        return $pdo;
    }

    /**
     * Obnova databáze ze zálohy (soubor .sqlite). Nejdřív se ověří, že jde o databázi této aplikace,
     * aktuální databáze se uloží vedle jako kviz-pred-obnovou-*.sqlite a teprve pak se vymění.
     * Vrací cestu k pojistné kopii.
     */
    public static function restore(string $uploaded): string
    {
        $head = (string)file_get_contents($uploaded, false, null, 0, 16);
        if ($head !== "SQLite format 3\0") {
            throw new InvalidArgumentException('Soubor není SQLite databáze.');
        }
        $chk = new PDO('sqlite:' . $uploaded, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ($chk->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new InvalidArgumentException('Záloha je poškozená (kontrola integrity selhala).');
        }
        $tables = $chk->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['settings', 'quizzes', 'questions', 'games', 'players'] as $t) {
            if (!in_array($t, $tables, true)) {
                throw new InvalidArgumentException('Soubor nevypadá jako záloha této aplikace (chybí tabulka ' . $t . ').');
            }
        }
        $chk = null;

        $path = (string)cfg('db_path', ROOT . '/data/kviz.sqlite');
        $backup = dirname($path) . '/kviz-pred-obnovou-' . date('Ymd-His') . '.sqlite';
        self::pdo()->exec("VACUUM INTO '" . str_replace("'", "''", $backup) . "'");
        self::$pdo = null;   // zavřít spojení, ať jde soubor vyměnit
        foreach (['-wal', '-shm'] as $ext) {
            if (is_file($path . $ext)) {
                @unlink($path . $ext);
            }
        }
        if (!copy($uploaded, $path . '.new') || !rename($path . '.new', $path)) {
            throw new RuntimeException('Nepodařilo se vyměnit soubor databáze (práva ke složce data/?).');
        }
        self::pdo();   // otevře a případně doplní schéma starší zálohy
        return $backup;
    }

    public static function all(string $sql, array $p = []): array
    {
        $s = self::pdo()->prepare($sql);
        $s->execute($p);
        return $s->fetchAll();
    }

    public static function one(string $sql, array $p = []): ?array
    {
        $s = self::pdo()->prepare($sql);
        $s->execute($p);
        $r = $s->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $p = [])
    {
        $r = self::one($sql, $p);
        return $r ? array_values($r)[0] : null;
    }

    public static function exec(string $sql, array $p = []): int
    {
        $s = self::pdo()->prepare($sql);
        $s->execute($p);
        return $s->rowCount();
    }

    /** Transakce se zámkem pro zápis hned od začátku (BEGIN IMMEDIATE), ať se souběžné změny stavu hry řadí za sebou. */
    public static function tx(callable $fn)
    {
        $pdo = self::pdo();
        $pdo->exec('BEGIN IMMEDIATE');
        try {
            $r = $fn();
            $pdo->exec('COMMIT');
            return $r;
        } catch (Throwable $e) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable $x) {
                // transakce už mohla skončit
            }
            throw $e;
        }
    }

    public static function lastId(): int
    {
        return (int)self::pdo()->lastInsertId();
    }

    private static function migrate(): void
    {
        $pdo = self::$pdo;
        $v = (int)$pdo->query('PRAGMA user_version')->fetchColumn();
        if ($v >= self::SCHEMA_VERSION) {
            return;
        }
        if ($v < 1) {
            $pdo->exec(self::SCHEMA_V1);
        }
        if ($v < 2) {
            // zbývající čas otázky při pozastavení hry
            $pdo->exec('ALTER TABLE games ADD COLUMN paused_remaining INTEGER NULL');
        }
        if ($v < 3) {
            // hlasový režim: 1 = otázka se právě předčítá, odpočet ještě neběží
            $pdo->exec('ALTER TABLE games ADD COLUMN reading INTEGER NOT NULL DEFAULT 0');
        }
        $pdo->exec('PRAGMA user_version=' . self::SCHEMA_VERSION);
        if ($v < 4) {
            // jednorázově naplnit seznam kategorií pro generování otázek
            Defaults::seedCategories();
        }
    }

    private const SCHEMA_V1 = <<<'SQL'
CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY, value TEXT NOT NULL);

CREATE TABLE IF NOT EXISTS categories(
  id INTEGER PRIMARY KEY, name TEXT UNIQUE NOT NULL, sort INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS quizzes(
  id INTEGER PRIMARY KEY, title TEXT NOT NULL, description TEXT NOT NULL DEFAULT '',
  category_id INTEGER NULL REFERENCES categories(id) ON DELETE SET NULL,
  settings_json TEXT NOT NULL DEFAULT '{}',
  created_at INTEGER NOT NULL, updated_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS questions(
  id INTEGER PRIMARY KEY,
  quiz_id INTEGER NOT NULL REFERENCES quizzes(id) ON DELETE CASCADE,
  position INTEGER NOT NULL,
  type TEXT NOT NULL CHECK(type IN ('choice','truefalse','poll')),
  text TEXT NOT NULL, image TEXT NULL,
  time_limit INTEGER NULL, points INTEGER NULL, explanation TEXT NULL,
  source TEXT NOT NULL DEFAULT 'manual',
  category_id INTEGER NULL, created_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_questions_quiz ON questions(quiz_id, position);
CREATE INDEX IF NOT EXISTS idx_questions_cat ON questions(category_id);

CREATE TABLE IF NOT EXISTS options(
  id INTEGER PRIMARY KEY,
  question_id INTEGER NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
  position INTEGER NOT NULL, text TEXT NOT NULL,
  is_correct INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_options_q ON options(question_id, position);

CREATE TABLE IF NOT EXISTS games(
  id INTEGER PRIMARY KEY, pin TEXT UNIQUE NOT NULL,
  quiz_id INTEGER NULL REFERENCES quizzes(id) ON DELETE SET NULL,
  host_token TEXT NOT NULL,
  mode TEXT NOT NULL DEFAULT 'screen',
  phase TEXT NOT NULL DEFAULT 'lobby',
  paused INTEGER NOT NULL DEFAULT 0,
  version INTEGER NOT NULL DEFAULT 1,
  current_index INTEGER NOT NULL DEFAULT -1,
  question_started_at INTEGER NULL, question_ends_at INTEGER NULL,
  phase_changed_at INTEGER NULL,
  players_count INTEGER NOT NULL DEFAULT 0,
  answered_count INTEGER NOT NULL DEFAULT 0,
  settings_json TEXT NOT NULL,
  created_at INTEGER NOT NULL, started_at INTEGER NULL, finished_at INTEGER NULL
);
CREATE INDEX IF NOT EXISTS idx_games_phase ON games(phase);
CREATE INDEX IF NOT EXISTS idx_games_created ON games(created_at);

CREATE TABLE IF NOT EXISTS game_questions(
  game_id INTEGER NOT NULL REFERENCES games(id) ON DELETE CASCADE,
  idx INTEGER NOT NULL, question_id INTEGER NULL,
  type TEXT NOT NULL, text TEXT NOT NULL, image TEXT NULL,
  time_limit INTEGER NULL, points INTEGER NULL, explanation TEXT NULL,
  options_json TEXT NOT NULL,
  PRIMARY KEY(game_id, idx)
);

CREATE TABLE IF NOT EXISTS players(
  id INTEGER PRIMARY KEY,
  game_id INTEGER NOT NULL REFERENCES games(id) ON DELETE CASCADE,
  token TEXT UNIQUE NOT NULL, name TEXT NOT NULL,
  score INTEGER NOT NULL DEFAULT 0, streak INTEGER NOT NULL DEFAULT 0,
  kicked INTEGER NOT NULL DEFAULT 0,
  joined_at INTEGER NOT NULL, last_seen INTEGER NULL,
  UNIQUE(game_id, name)
);
CREATE INDEX IF NOT EXISTS idx_players_game ON players(game_id);

CREATE TABLE IF NOT EXISTS responses(
  game_id INTEGER NOT NULL REFERENCES games(id) ON DELETE CASCADE,
  q_idx INTEGER NOT NULL,
  player_id INTEGER NOT NULL REFERENCES players(id) ON DELETE CASCADE,
  choice TEXT NULL, answered_at INTEGER NOT NULL,
  correct INTEGER NULL, points INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY(game_id, q_idx, player_id)
);

CREATE TABLE IF NOT EXISTS option_counts(
  game_id INTEGER NOT NULL REFERENCES games(id) ON DELETE CASCADE,
  q_idx INTEGER NOT NULL, opt_pos INTEGER NOT NULL,
  cnt INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY(game_id, q_idx, opt_pos)
);

CREATE TABLE IF NOT EXISTS rate_limits(
  bucket TEXT NOT NULL, window_start INTEGER NOT NULL, cnt INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY(bucket, window_start)
);

CREATE TABLE IF NOT EXISTS tts_cache(
  hash TEXT PRIMARY KEY, file TEXT NOT NULL, created_at INTEGER NOT NULL
);
SQL;
}
