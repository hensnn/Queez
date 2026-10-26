# CLAUDE.md – průvodce projektem pro vývoj

Kvízová webová aplikace ve stylu Kahoot (hospoda / přátelé / firemní školení). Celé UI je **česky**.
Server–klient, **PHP 8.2+ (php-fpm) + SQLite**, vanilla JS, žádný framework, žádný build, žádné CDN.
Tento soubor je určen pro každého (člověka i Claude), kdo na projektu pokračuje. Uživatel je **laik**: odpovídej česky,
postupy na serveru piš po jednotlivých krocích a příkazy dávej do samostatných bloků.

- Původní zadání (zdroj požadavků): `docs/ZADANI.md`
- Instalace a provoz pro uživatele: `README.md`
- Počáteční návrh etapy 1 (historický, v detailech zastaralý): `docs/NAVRH-puvodni.md`

## Rychlý start pro vývoj (lokálně)

```bash
cp config.example.php config.php
php -r "echo password_hash('testovaci-heslo', PASSWORD_DEFAULT);"   # hash vložit do config.php (admin_password_hash)
php -S 127.0.0.1:8000 -t public
```

- Admin: `http://127.0.0.1:8000/admin/`, hráči `/play/`, moderátor `/host/?game=PIN&key=KLÍČ` (odkaz vzniká v adminu: Kvízy → ▶ Spustit hru).
- `config.php` ukládejte jako **UTF-8 bez BOM** (BOM rozbije JSON odpovědi).
- Na Windows potřebuje PHP v `php.ini` rozšíření `curl, fileinfo, mbstring, openssl, pdo_sqlite, sqlite3`.
- Vestavěný server `php -S` je **jednovláknový** – hodí se na vývoj, ne na posuzování výkonu.
- `base_url` v `config.php` určuje adresu v QR kódu (lokálně `http://127.0.0.1:8000`).

## Testy

```bash
php tests/run.php --url=http://127.0.0.1:8000 --password=testovaci-heslo      # integrační test celé hry (API)
php tools/loadtest.php --url=http://127.0.0.1:8000 --password=... --players=24  # zátěž: všichni hráči pollují naráz
```

- `tests/run.php` si vytvoří dočasný kvíz a hry a na konci je smaže. Spouštějte jen proti vývojové instalaci.
- Gemini jde testovat bez klíče: `php -S 127.0.0.1:8001 tests/fake_gemini.php` a do `config.php` přidejte `'gemini_base_url' => 'http://127.0.0.1:8001'`
  (v adminu uložte libovolný klíč; chování podle začátku klíče je popsané v záhlaví `tests/fake_gemini.php`, vrací i záměrně vadné otázky a audio pro TTS).
  Skutečné API zatím vyzkoušeno nebylo (viz „Neověřené“).
- Po změně JS/CSS ověřte v prohlížeči na mobilní šířce (360 px) i desktopu; měřit lze `document.documentElement.scrollWidth > innerWidth`.

## Architektura

### Struktura

```
public/              ← webroot (jediná složka dostupná z webu)
  index.php          → přesměruje na /play/
  play/ host/ admin/ ← HTML stránky (načítají skripty z assets/)
  api/*.php          ← JSON API (viz níže); každý soubor je tenký, logika je v src/
  assets/            ← common.js play.js host.js admin.js tts.js, game.css admin.css, vendor/qrcode.js, fonts/ (Nunito), icon.svg (favicon/logo)
src/                 ← třídy (autoload podle názvu souboru, bez namespace)
data/                ← SQLite, uploady, TTS cache, secrets/ (šifrovaný klíč) – zapisovatelné, MIMO web, není v Gitu
logs/                ← error.log, není v Gitu
config.php           ← tajné/startovací věci, není v Gitu (vzor config.example.php)
deploy/ tools/ tests/ docs/ (docs/img/ = logo aplikace (logo.png) a snímky obrazovek do README)
```

### Třídy v `src/`

| Soubor | Úloha |
|---|---|
| `bootstrap.php` | načte `config.php`, autoload, logování chyb do souboru, `cfg()`, `now_ms()` |
| `Http` | `json()`, `fail()`, `input()` (JSON tělo / $_POST), `run(callable)` (výjimky → JSON) |
| `Net` | `clientIp()` (X-Forwarded-For jen od důvěryhodné proxy z `trusted_proxies`), `isHttps()` |
| `Db` | PDO SQLite (WAL, `busy_timeout`, FK), migrace (`PRAGMA user_version`), `tx()` = `BEGIN IMMEDIATE`, `restore()` |
| `RateLimit` | pevné okno v tabulce `rate_limits` |
| `Auth` | admin session, CSRF (`X-CSRF-Token`), limit pokusů o přihlášení |
| `Settings` | **definice všech nastavení na jednom místě** (`DEFS`), uložení, validace, kvízové přepisy |
| `Defaults` | výchozí kategorie a nabídka podkategorií pro generování |
| `Media` | upload obrázků (jpg/png/webp, kontrola MIME) |
| `Quiz` | CRUD kvízu, validace otázek (`cleanQuestion`), export/import JSON (`kviz-v1`) |
| `Game` | **jádro hry** (stavový automat, hráči, odpovědi, filtrace stavu podle role) |
| `Scoring` | výpočet bodů (jediné místo) |
| `Gemini` | generování otázek (response schema, validace) |
| `Tts` | serverové TTS přes Gemini s cache souborů |
| `Secrets` | šifrované úložiště tajných hodnot (AES-256-GCM) mimo DB |

### Stavový automat hry (`Game`)

Fáze (`games.phase`): `lobby → question → reveal → leaderboard → question … → finished`. Změna stavu zvedne `games.version`.
- Všechny změny běží v `Db::tx()` (**BEGIN IMMEDIATE**), takže souběžné požadavky se řadí za sebe.
- Žádný cron/démon: `state.php` volá `Game::tick()`, které provede čekající přechod (vypršel limit, automatický postup, pojistka čtení v hlasovém režimu).
- Čas počítá **server**: `question_started_at`, `question_ends_at` (ms). Klient jen zobrazuje odpočet; `Clock` v `common.js` dorovná posun hodin podle `server_time`.
- `closeQuestion()` spočítá body, naplní `option_counts`, při **tajném hlasování nuluje `responses.choice`** (zůstanou jen body + agregace).
- Průběžné pořadí řídí `leaderboard_mode` (`every|interval|end|never`, `Game::showLeaderboardAfter()`); po poslední otázce se pořadí jmenuje „Konečné“ (`game.is_last`).
- Hlasový režim (`games.mode='voice'`): po otevření otázky je `reading=1`, odpočet začne po `host_control action=tts_done` (nebo po 45 s pojistce).
- Při vytvoření hry se **efektivní nastavení zmrazí** do `games.settings_json` (globální → kvíz → přepisy při spuštění; plus `_title`, `_total`) a otázky do `game_questions`. Pozdější změna nastavení běžící hru neovlivní.
- Pozice odpovědí (`pos`) jsou pozice **po případném zamíchání** v dané hře, ne v kvízu.

### `state.php` (jediný endpoint pro polling)

`GET /api/state.php?game=PIN&role=player|host&token=…&since=VERZE` → `{changed:false}` když se nic nezměnilo, jinak celý stav.
Obsah se **filtruje podle role a nastavení** (`Game::state()`): hráč nikdy nedostane správné odpovědi dopředu ani cizí hlasy;
texty otázky/odpovědí na telefonu řídí `phone_content` (`buttons|question|full`), v hlasovém režimu vždy plné.
Klient má vrstvu `PollTransport` (`common.js`) – kvůli případné výměně za SSE bez přepisu zbytku.

### API (`public/api/`)

- Hráč/veřejné: `join.php`, `state.php`, `answer.php`, `config_public.php`, `media.php`
- Moderátor (klíč `host_token` v URL, ne session): `host_control.php` (`start|next|pause|resume|finish|kick|tts_done`), `host_export.php` (CSV), `tts_gemini.php`
- Admin (session + CSRF): `admin_login/logout/session`, `admin_quizzes` (seznam, smazání, duplikace, import), `admin_quiz` (čtení/uložení), `admin_export`,
  `admin_upload`, `admin_games`, `admin_settings`, `admin_categories`, `admin_secrets`, `admin_gemini`, `admin_maintenance` (záloha/obnova/mazání her)
- Chyby: `{"error":"kod","message":"česky"}`; validační chyby `InvalidArgumentException` → HTTP 422 s českou hláškou pro uživatele.

### Nastavení (`Settings`)

- Jediné místo definice: `Settings::defs()` (typ, rozsah, popisek, nápověda, výchozí). **Admin UI se z toho generuje automaticky** (posuvníky, přepínače, výběry).
- Hodnoty jsou v tabulce `settings` (JSON). Příznak `'quiz' => true` = lze přepsat v kvízu (`quizzes.settings_json`) i při spuštění hry.
- **Nové nastavení:** přidat do `defs()`, použít v kódu přes `$g['s']['klic']` (hra) nebo `Settings::get()`; UI nic dalšího nepotřebuje.

### Hlasatel (TTS)

- Mluví **prohlížeč moderátora** (`host.js`), server posílá jen nastavení v `state.tts` (hodnoty `tts_*` ze zmrazeného nastavení hry; skládá je `Game::state()`) a `game.letters` (písmena A–F u odpovědí podle `tts_option_letters`).
- **Co a kdy říct** určují `phrasesFor(st, ctx)` (věty pro fázi: otázka, odhalení, pořadí, konec) a `timeCue(st, r)` (časová upozornění: polovina času, před koncem, odpočet 3-2-1; každé jednou za otázku, při skoku času jen nejnovější). Obě jsou čisté funkce, dají se zkoušet v konzoli.
- Další spouštěče: začátek hry (`ctx.first`), „čas vypršel“ (`ctx.timeUp` – otázka skončila vypršením `ends_at`), pauza/pokračování (`handlePause`).
- Fronta vět a výběr hlasu je v `tts.js` (`Speech`); u zařízení bez českého hlasu záložní Gemini TTS (`api/tts_gemini.php`).
- Nastavení hlasatele (záložka *Hlasatel (TTS)*) se v adminu seskupuje podle klíče `group` v `Settings::defs()`. Obsahové volby mají `'quiz' => true` (jdou přepsat u kvízu i při spuštění hry); hlas, rychlost, výška a hlasitost jsou globální (závisí na zařízení).
### Bodování (`Scoring::evaluate`)

`základ × (1 − R × čas/limit)` + `streak_bonus × min(série_před_otázkou, streak_max)`; více správných: `all_or_nothing` / `proportional`; anketa = 0 bodů a série se nepřeruší.
Vzorec je popsán uživateli v Nastavení → Hra (`Settings::tabs()`), při změně aktualizujte oba.

### Frontend

- `common.js`: `h()` (tvorba DOM, vše přes `textContent`), **`put(el, …)`** (nahrazení obsahu; `replaceChildren(null)` by vypsalo text „null“),
  `Clock`, `PollTransport`, `postJson`, `avatar()`, `confetti()`, `setRing()`.
- `play.js` (hráč), `host.js` (moderátor/projektor + hlasový režim), `tts.js` (Web Speech API + záloha Gemini TTS, fronta vět), `admin.js` (SPA s hash routingem).
- Vzhled: `game.css` (hráč+moderátor) a `admin.css`; design tokeny v `:root`, teplý přechod odvozený z `color_primary` v nastavení, písmo Nunito lokálně.
- Rozložení odpovědních tlačítek řídí nastavení `answer_layout` (`auto|grid|list`) → `state.game.layout` → třída `lay-grid` / `lay-list` na `.tiles` (hráč) a `.h-tiles` (moderátor); `auto` = bez třídy, o rozložení rozhodují media queries v `game.css` (odpovědi s textem se na úzkém displeji řadí pod sebe).
- Animace „vyskočení“ se spouští jen při změně obrazovky (třída `still` na `#app`), jinak by blikaly při každém pollu.
- **Pozor na pořadí:** odpočet hráče se zastavuje funkcí `stopTick()` v `render()` **před** vytvořením obrazovky (obrazovka si spouští vlastní interval).

## Konvence

- PHP: `declare(strict_types=1)`, prepared statements všude, žádné závislosti (bez Composeru). Komentáře a hlášky česky.
- Výstup do DOM jen přes `textContent`/`h()`; CSRF na všech nečtecích admin požadavcích.
- Soubory **UTF-8 bez BOM, koncovky LF**. V Gitu není a nesmí být `config.php`, `data/`, `logs/`, databáze, klíče.
- Žádné CDN za běhu; knihovny jsou v `public/assets/vendor/` a písmo v `public/assets/fonts/` (licence: MIT, SIL OFL). Nové stahování souborů vždy odsouhlasit s uživatelem.
- **Do repozitáře nepatří osobní údaje** (domény, IP, e-maily, hesla, cesty z počítače). Před každým pushem je prohledat. Commity jako `hensnn <hensnn@users.noreply.github.com>`.
- Změna schématu DB = nová verze v `Db::migrate()` (`SCHEMA_VERSION`), migrace musí být zpětně kompatibilní (ALTER TABLE / INSERT OR IGNORE).

## Nasazení a provoz (jak to běží u uživatele)

- Debian LXC s Caddy (`:87`) + PHP-FPM 8.4, před tím Nginx Proxy Manager (HTTPS, veřejná doména). Konfigurace: `Caddyfile.example`, `deploy/php-fpm-kviz.conf`, `deploy/npm-advanced.conf`.
- PHP-FPM má **dva pooly**: `kviz` (hra, `request_terminate_timeout=20s`) a `kviz-slow` (Gemini/TTS/záloha, 130 s). Výchozí pool `www` se **nesmí vypínat** (jiné weby v témže PHP).
- Administrace je z internetu zablokovaná v NPM; admin se otevírá na LAN adrese nebo na druhé doméně chráněné NPM Access Listem.
- **Aktualizace na serveru** (složka `/var/www/kviz` je klon tohoto repozitáře; `config.php`, `data/`, `logs/` jsou ignorované):
  `git -C /var/www/kviz pull --ff-only && systemctl reload php8.4-fpm` (databáze se při potřebě migruje sama).
  Na serveru neupravovat soubory aplikace ručně (kromě `config.php`), nikdy `git clean`.
- Gemini API klíč se zadává v adminu (Nastavení → Gemini), ukládá se zašifrovaně do `data/secrets/`, do prohlížeče se nevrací.

## Rozhodnutí a odchylky od původního návrhu

- Moderátor se autorizuje **tajným klíčem v URL** (`host_token`), ne admin session; odkaz je v adminu v části Hry.
- Samostatný `rejoin.php` neexistuje – obnovu hráče řeší `state.php` (token v `localStorage`).
- Věty ke čtení skládá **prohlížeč moderátora** ze stavu (`host.js: phrasesFor`), server je neposílá.
- Odpověď se vyhodnotí a ukáže hned jen při vypnuté změně odpovědi (jinak by šlo hádat); předčasné uzavření otázky „všichni odpověděli“ jen při vypnuté změně odpovědi.
- Okno pro odpověď je přesné: po `question_ends_at` server odpověď odmítne (žádná tolerance).
- Název hráče: 1–20 znaků, bez `<>` a řídicích znaků; duplicity dostanou číslo.

## Neověřené / omezení

- **Gemini TTS** (název modelu `gemini-2.5-flash-preview-tts`, hlas `Kore`) a **generování otázek** byly testovány proti simulaci, ne proti živému API – názvy modelů v Nastavení mohou být potřeba upravit.
- **Předčítání v prohlížeči** závisí na hlasech zařízení (český hlas musí být nainstalovaný, na Windows nejlépe Edge s hlasy Vlasta/Antonín).
- Zátěž byla ověřena na produkčním PHP-FPM (24 hráčů naráz, p95 ≈ 30 ms), ale ne dlouhodobě ani nad ~50 hráčů.
- Obnova ze zálohy DB (`Db::restore`) nemigruje nic navíc kromě standardního `migrate()` při dalším otevření.

## Nápady do budoucna

- Úprava nabídky podkategorií v adminu (dnes pevně v `Defaults::SUBCATEGORIES`).
- SSE místo pollingu (`PollTransport` je na to připravený).
- Týmový režim, časový bonus za sérii, export statistik po otázkách.
