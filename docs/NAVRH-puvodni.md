> **Historický dokument – počáteční návrh (etapa 1).** Skutečná implementace se v detailech liší (např. neexistují `rejoin.php`, `quiz_reorder.php`, `host_settings.php`; ovládání moderátora je v `host_control.php`, správa kvízů v `admin_quiz.php` / `admin_quizzes.php`). Aktuální popis architektury je v `CLAUDE.md`.

# Návrh – etapa 1: DB schéma a API

## 1. Principy

- Jeden zdroj pravdy = SQLite (WAL, `busy_timeout=5000`, `foreign_keys=ON`).
- Stav hry je řízen **serverem**: `games.phase` + `games.version` (čítač, +1 při každé změně, kterou má klient vidět).
- Fáze: `lobby → question → reveal → leaderboard → (question …) → finished`. Navíc `paused` jako příznak (`games.paused`), ne fáze.
- Přechody fází dělá jen jedna PHP funkce `Game::advance()`; volá ji moderátor (tlačítko) **i** `state.php` (automatický postup, když vypršel čas a hra je v auto režimu) → nepotřebujeme cron ani démon.
- Čas: server ukládá `question_started_at` a `question_ends_at` (unix ms). Klient dostane `server_time` a dorovná posun hodin.
- Nastavení: globální (`settings`) → kvíz (`quizzes.settings_json`) → hra (`games.settings_json`). Při spuštění hry se **efektivní nastavení slije a zmrazí** do `games.settings_json`, takže pozdější změna v adminu běžící hru neovlivní.
- Pořadí otázek/odpovědí po zamíchání se zmrazí při startu hry do `game_questions` (každá hra má vlastní kopii pořadí a snapshot textu).

## 2. DB schéma

```sql
-- Globální nastavení (klíč/hodnota, hodnota JSON)
settings(key TEXT PK, value TEXT NOT NULL)

-- Kategorie pro Gemini generátor (spravované v Nastavení)
categories(id INTEGER PK, name TEXT UNIQUE NOT NULL, sort INTEGER DEFAULT 0)

quizzes(
  id INTEGER PK, title TEXT NOT NULL, description TEXT,
  category_id INTEGER NULL REFERENCES categories(id) ON DELETE SET NULL,
  settings_json TEXT NOT NULL DEFAULT '{}',   -- přepisy globálních výchozích hodnot
  created_at INTEGER, updated_at INTEGER
)

questions(
  id INTEGER PK, quiz_id INTEGER NOT NULL REFERENCES quizzes(id) ON DELETE CASCADE,
  position INTEGER NOT NULL,                  -- pořadí v kvízu
  type TEXT NOT NULL CHECK(type IN ('choice','truefalse','poll')),
  text TEXT NOT NULL,
  image TEXT NULL,                            -- název souboru v data/uploads
  time_limit INTEGER NULL,                    -- NULL = výchozí kvízu; 0 = bez limitu
  points INTEGER NULL,                        -- NULL = výchozí; u 'poll' ignorováno
  explanation TEXT NULL,                      -- volitelné vysvětlení po odhalení
  source TEXT DEFAULT 'manual',               -- 'manual' | 'gemini'
  category_id INTEGER NULL, created_at INTEGER
)
INDEX questions(quiz_id, position)
INDEX questions(category_id)                  -- pro "nevracet existující otázky"

options(
  id INTEGER PK, question_id INTEGER NOT NULL REFERENCES questions(id) ON DELETE CASCADE,
  position INTEGER NOT NULL, text TEXT NOT NULL,
  is_correct INTEGER NOT NULL DEFAULT 0       -- více správných povoleno; u 'poll' vše 0
)
INDEX options(question_id, position)

games(
  id INTEGER PK, pin TEXT UNIQUE NOT NULL,    -- 4–6 číslic, unikátní mezi neukončenými hrami
  quiz_id INTEGER NOT NULL REFERENCES quizzes(id),
  host_token TEXT NOT NULL,                   -- tajný token moderátora (nebo admin session)
  mode TEXT NOT NULL DEFAULT 'screen',        -- 'screen' | 'voice'
  phase TEXT NOT NULL DEFAULT 'lobby',
  paused INTEGER NOT NULL DEFAULT 0,
  version INTEGER NOT NULL DEFAULT 1,
  current_index INTEGER NOT NULL DEFAULT -1,  -- index v game_questions
  question_started_at INTEGER NULL,           -- ms
  question_ends_at INTEGER NULL,              -- ms; NULL = bez limitu
  phase_changed_at INTEGER,                   -- pro auto postup z reveal/leaderboard
  settings_json TEXT NOT NULL,                -- zmrazené efektivní nastavení
  created_at INTEGER, started_at INTEGER, finished_at INTEGER
)
INDEX games(pin), games(phase), games(created_at)

game_questions(                               -- snapshot pro konkrétní hru
  game_id INTEGER REFERENCES games(id) ON DELETE CASCADE,
  idx INTEGER NOT NULL,                       -- 0..n-1 (po případném zamíchání)
  question_id INTEGER NULL,                   -- odkaz na zdroj (informativně)
  type TEXT, text TEXT, image TEXT, time_limit INTEGER, points INTEGER, explanation TEXT,
  options_json TEXT NOT NULL,                 -- [{pos,text,correct}] už zamíchané
  PRIMARY KEY(game_id, idx)
)

players(
  id INTEGER PK, game_id INTEGER NOT NULL REFERENCES games(id) ON DELETE CASCADE,
  token TEXT UNIQUE NOT NULL,                 -- 32 hex, ukládá se do localStorage
  name TEXT NOT NULL,                         -- unikátní v rámci hry (přidá se číslo)
  score INTEGER NOT NULL DEFAULT 0,
  streak INTEGER NOT NULL DEFAULT 0,
  kicked INTEGER NOT NULL DEFAULT 0,
  joined_at INTEGER, last_seen INTEGER
)
INDEX players(game_id), UNIQUE(game_id, name)

responses(                                    -- 1 řádek = 1 hráč × 1 otázka
  game_id INTEGER, q_idx INTEGER, player_id INTEGER REFERENCES players(id) ON DELETE CASCADE,
  choice TEXT NULL,                           -- JSON pole pozic; při tajném hlasování se po uzavření otázky NULLuje
  answered_at INTEGER,                        -- ms
  correct INTEGER NULL, points INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY(game_id, q_idx, player_id)      -- ochrana proti dvojímu hlasu na úrovni DB
)

option_counts(                                -- agregace pro grafy (jediná trvalá stopa hlasů u tajných her)
  game_id INTEGER, q_idx INTEGER, opt_pos INTEGER, cnt INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY(game_id, q_idx, opt_pos)
)

rate_limits(bucket TEXT, window_start INTEGER, cnt INTEGER, PRIMARY KEY(bucket, window_start))
  -- bucket např. 'join:ip:1.2.3.4', 'vote:tok:abc', 'login:ip:…'

tts_cache(hash TEXT PK, file TEXT, created_at INTEGER)   -- Gemini TTS fallback
```

**Tajné hlasování:** během otázky musí server znát volbu (kvůli změně odpovědi a bodování). Při uzavření otázky (`question → reveal`) se spočítají body, naplní `option_counts` a **`responses.choice` se vynuluje**. Pak v DB zůstane jen „hráč X dostal Y bodů“ a agregace. Moderátor/API nikdy nevrací `choice` jednotlivců; v netajném režimu se `choice` zachová a moderátor může vidět, kdo jak hlasoval.

**Bodování** (jedna funkce `Scoring::points($q, $settings, $elapsedMs, $streak)`):
`base = points` · rychlost: `base * (1 - speedWeight * elapsed/limit)` (lineárně klesá, `speedWeight` 0–1, 0 = pevné body) · streak: `+ streakBonus * min(streak, streakMax)` · více správných odpovědí: bere se buď „vše správně a nic navíc“ (výchozí), nebo poměrně (volba v nastavení). Anketa = 0 bodů.

## 3. Formát stavu hry (`state.php`)

Jeden endpoint, jedna podoba pro všechny role; obsah se filtruje podle role a nastavení viditelnosti.

`GET /api/state.php?game=PIN&role=player|host&token=…&since=VERZE`

```jsonc
// pokud se nic nezměnilo (a neběží odpočet, který se nepřepočítává na serveru):
{ "changed": false, "version": 17, "server_time": 1760000000123 }

// změna:
{
  "changed": true,
  "version": 18,
  "server_time": 1760000000123,           // ms; klient dopočítá offset
  "phase": "question",                    // lobby|question|reveal|leaderboard|finished
  "paused": false,
  "mode": "screen",                       // screen|voice
  "poll_interval_ms": 1000,               // server může zpomalit/zrychlit
  "game": { "pin": "4821", "title": "Hospodský kvíz", "players_count": 14, "max_players": 50 },
  "question": {                           // jen ve fázích question/reveal
    "index": 2, "total": 10, "type": "choice",
    "text": null,                         // text posílán jen když to režim dovolí (voice mód / host); jinak null
    "options": [{"pos":0,"text":"…"}],    // texty jen host nebo voice mód; hráč v screen módu dostane jen počet + tvary
    "image": "/media/abc.jpg",
    "started_at": 1760000000000, "ends_at": 1760000020000,   // null = bez limitu
    "points": 1000, "answered_count": 9
  },
  "reveal": {                             // jen ve fázi reveal a podle viditelnosti
    "correct": [1], "counts": [3,8,2,1], "explanation": "…"
  },
  "me": {                                 // jen pro role=player
    "name": "Honza", "score": 2300, "rank": 3, "answered": true,
    "was_correct": true, "points_gained": 870, "streak": 2    // was_correct jen pokud je zapnuto "ukázat hned"
  },
  "leaderboard": [ {"name":"…","score":…,"rank":1} ],      // top N (host všichni), podle viditelnosti
  "players": [ {"id":…,"name":"…"} ],     // jen lobby + host
  "tts": [ "Otázka číslo 3. …" ]          // jen voice mód + host: co přečíst (viz níže)
}
```

- `since` = poslední verze, kterou klient zná. Když `since == games.version` a fáze nevyžaduje přepočet, vrací se jen malý objekt `changed:false` (jedno `SELECT` podle PK/indexu, bez JOINů). `answered_count` a `players_count` se drží jako sloupce v `games`, takže jejich změna jen zvedne `version`, ale neprovádí se `COUNT(*)`.
- **Příprava na SSE:** klient má vrstvu `Transport` s rozhraním `subscribe(onState)`. Dnes `PollTransport` (setTimeout + fetch), později `SseTransport` (EventSource na `stream.php`, který pošle stejné JSON objekty). Formát zpráv se nemění.
- **Auto postup:** `state.php` při každém dotazu zkontroluje `question_ends_at <= now` (nebo „odpověděli všichni“) a v auto režimu zavolá `Game::advance()` pod transakcí (`BEGIN IMMEDIATE`, kontrola verze → jen jeden volající uspěje).

## 4. Seznam endpointů (`public/api/*.php`, vše JSON)

Chyby: `{"error":"kod","message":"česky"}` + HTTP 4xx/5xx. Admin endpointy vyžadují session + hlavičku `X-CSRF-Token`.

**Veřejné / hráč**
| Endpoint | Metoda | Účel |
|---|---|---|
| `state.php` | GET | stav hry (viz výše), role player/host |
| `join.php` | POST `pin, name` | vytvoří hráče, vrací `token` + `player`; rate limit per IP |
| `rejoin.php` | POST `pin, token` | obnova po refreshi/výpadku (ověří token) |
| `answer.php` | POST `pin, token, choice[]` | odeslání/změna odpovědi; server ověří čas, fázi, povolení změny |
| `config_public.php` | GET | název aplikace, barvy, logo, výchozí poll interval |
| `media.php` | GET `f=…` | servírování uploadů z `data/uploads` |

**Moderátor** (autorizace `host_token` nebo admin session)
| Endpoint | Metoda | Účel |
|---|---|---|
| `host_control.php` | POST `pin, action` | `start`, `next`, `skip`, `pause`, `resume`, `repeat`, `show_leaderboard`, `finish` |
| `host_kick.php` | POST `pin, player_id` | vyhodí hráče |
| `host_export.php` | GET `pin` | CSV s výsledky |
| `host_settings.php` | POST | změna za běhu (např. auto/ruční postup, TTS) |
| `qr.php` | – | QR se generuje na klientovi (lokální JS knihovna), URL z `BASE_URL` dodá `config_public` |

**Admin**
| Endpoint | Účel |
|---|---|
| `admin_login.php` / `admin_logout.php` / `admin_csrf.php` | přihlášení (rate limit + zpoždění), odhlášení, získání CSRF |
| `quiz_list.php`, `quiz_get.php`, `quiz_save.php`, `quiz_delete.php`, `quiz_duplicate.php`, `quiz_reorder.php` | CRUD kvízů a otázek, pořadí |
| `quiz_export.php`, `quiz_import.php` | JSON export/import |
| `upload.php` | upload obrázku (kontrola MIME, přejmenování, limit velikosti) |
| `game_create.php` | z kvízu vytvoří hru (PIN, snapshot otázek, zmrazení nastavení, režim) |
| `game_list.php`, `game_delete.php` | přehled a mazání her |
| `settings_get.php`, `settings_save.php`, `settings_reset.php` | globální nastavení po záložkách + „obnovit výchozí“ |
| `category_*.php` | správa kategorií |
| `gemini_generate.php` | vygeneruje **návrh** (neukládá), vrací validované otázky |
| `gemini_regenerate_one.php` | přegeneruje jednu otázku z návrhu |
| `gemini_prompt_preview.php` | vrátí sestavený prompt po doplnění proměnných |
| `tts_gemini.php` | volitelný Gemini TTS s cache (fallback) |
| `maintenance.php` | smazání starých her, záloha/obnova DB (přes `VACUUM INTO`) |

## 5. Hlasový režim a TTS v rámci stavu

- V `voice` módu posílá `state.php` hráčům i texty otázek a odpovědí.
- Host dostává pole `tts` – seznam frází s časovou značkou a ID („otázka“, „možnosti“, „zbývá 5 s“, „správná odpověď“, „top 3“, „vítěz“). Klient je předčítá přes `speechSynthesis`; lokálně si hlídá, co už přečetl (ID + verze).
- Odpočet v hlasovém režimu: server nastaví `question_ends_at` až po tlačítku/události „dočteno“ (`host_control action=tts_done`) nebo po nastaveném zpoždění; do té doby je ve fázi `question` s `ends_at = null` a příznakem `reading: true`.

## 6. Ochrana a robustnost (shrnutí)

- PDO prepared statements; výstup escapovat (`textContent`/`htmlspecialchars`); jména max 20 znaků, bez HTML.
- Rate limit: join (IP), answer (token + IP), login (IP + globální zpomalení po neúspěších).
- CSRF token v adminu; session cookie `HttpOnly; SameSite=Lax; Secure` (dle `X-Forwarded-Proto`). IP klienta z `X-Forwarded-For` jen z důvěryhodné proxy (nastavitelné v configu).
- Auto-úklid: při `game_create` a občasně při `state.php` smazat hry starší než N dní (nastavitelné).
- Chyby do `data/../logs/error.log` mimo webroot.

## 7. Co potřebuji schválit / rozhodnout

1. Souhlasí schéma a rozdělení endpointů (zejména `host_control.php` jako jedna akční brána)?
2. **Autorizace moderátora:** bude host obrazovka vždy otevřena z přihlášeného adminu (session), nebo chcete i odkaz s `host_token` pro otevření na jiném zařízení (např. mobil v hlasovém režimu)? Navrhuji obojí: odkaz s tokenem generuje admin při vytvoření hry.
3. **Více správných odpovědí:** výchozí pravidlo „vše správně a nic navíc“, poměrné bodování jako volba. Souhlasíte?
4. **Anketa** je v kvízu jako typ otázky `poll` (bez bodů, nepřeruší streak). Souhlasí?
5. Maximální velikost uploadu obrázku (navrhuji 3 MB, JPG/PNG/WebP).

Po schválení (nebo úpravách) pokračuji etapou 2 – admin: přihlášení, ruční tvorba kvízů a globální nastavení.
