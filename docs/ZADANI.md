# Původní zadání (zdroj požadavků)

> Toto je původní zadání, podle kterého byla aplikace postavena (osobní údaje a adresy jsou nahrazeny zástupnými).
> Aktuální stav a rozhodnutí jsou v `CLAUDE.md`. Zadání bylo realizováno ve 7 etapách; pozdější rozšíření (vzhled, mobil, podkategorie, režimy pořadí) zadání doplňují.

# Úkol: Kvízová aplikace (server–klient, PHP + Caddy na Proxmoxu)

Postav kompletní webovou kvízovou aplikaci ve stylu Kahoot pro hraní v hospodě / s přáteli. Celé UI v češtině. Pracuj po etapách (viz konec), po každé etapě se zastav, shrň co je hotové a jak to otestovat.

## Prostředí a technické mantinely

- Nasazení: existující Caddy LXC na Proxmoxu (Debian). Na jiných portech už běží jiné weby — aplikace dostane **vlastní Caddy site na vlastním portu** (výchozí 87; port dej do konfigurace, ať jde snadno změnit).
- Před Caddy stojí **Nginx Proxy Manager**, který dělá HTTPS a veřejnou doménu `<veřejná-doména>` (tedy `BASE_URL=https://<veřejná-doména>`). Caddy tedy servíruje jen HTTP v LAN. Aplikace musí správně fungovat za reverzní proxy (respektuj `X-Forwarded-Proto` / `X-Forwarded-For`, absolutní URL pro QR kódy ber z konfigurace `BASE_URL`, ne z requestu).
- **Výkon PHP-FPM (doplněno):** protože klienti každou vteřinu pollují, dodej upravený pool config (`/etc/php/*/fpm/pool.d/kviz.conf` nebo úpravu `www.conf`) s `pm = dynamic`, `pm.max_children` cca 20–30, `pm.start_servers`/`min/max_spare_servers` rozumně k tomu, a vysvětli, jak hodnotu odvodit podle RAM kontejneru. Doplň i `request_terminate_timeout`, ať zaseknutý request neblokuje pracovníka. V testu se 20+ simulovanými hráči ověř, že se požadavky neřadí do fronty.
- Backend: **PHP 8.2+ přes php-fpm**, úložiště **SQLite (PDO)**. Žádný framework, žádný Node build, ideálně bez Composeru — soubory se na server nahrávají přes WinSCP (SFTP), takže výsledek musí být „zkopíruj složku a funguje“.
- Frontend: čisté HTML + CSS + vanilla JS. Žádné CDN — potřebné knihovny (např. generátor QR kódu) přibal lokálně do `/assets/vendor/`.
- Gemini API klíč a admin heslo jen v `config.php` mimo webroot (nebo v `.env` mimo webroot). Do repozitáře dej jen `config.example.php`. Klíč se **nikdy** nesmí dostat do prohlížeče — všechna volání Gemini jdou přes PHP.
- Dodej: ukázkový `Caddyfile` blok pro tuto site, instalační kroky pro Debian LXC (balíčky `php-fpm php-sqlite3 php-curl php-mbstring`), práva k adresáři s databází, a krátký README. README drž obecné, bez konkrétních IP a domén.

## Architektura

Tři pohledy, jedna PHP API vrstva (`/api/*.php`, JSON):

1. **Admin panel** (`/admin/`) — chráněný heslem (session, hash v configu, ochrana proti brute-force = jednoduchý rate limit). Tvorba a správa kvízů, spouštění her.
2. **Obrazovka moderátora / projektor** (`/host/?game=…`) — velký displej v místnosti: QR kód a PIN pro připojení, seznam připojených hráčů, otázky, odpočet, výsledky, žebříček. Ovládá průběh hry (Start, Další otázka, Ukončit).
3. **Hráčský klient** (`/` nebo `/play/`) — mobilní stránka: zadání PINu (nebo přímý vstup přes QR odkaz s PINem v URL), zadání jména/přezdívky, pak hlasování.

### Real-time
PHP-FPM nedrží dlouhá spojení dobře, proto:
- Stav hry je v DB (fáze: `lobby` → `question` → `reveal` → `leaderboard` → … → `finished`) s čítačem verze.
- Klienti dělají **krátký polling (cca 1 s)** na lehký endpoint `state.php?game=…&since=verze`, který vrací jen změny. Endpoint musí být rychlý (žádné těžké dotazy, indexy v SQLite, WAL režim).
- Časovače počítej **na serveru** (čas startu otázky uložený v DB + délka limitu); klient jen zobrazuje odpočet podle serverového času (pošli serverový timestamp, klient si dorovná posun hodin). Odpověď po limitu server odmítne.
- Navrhni rozhraní tak, aby šlo polling později nahradit SSE bez přepisu frontendu.

## Funkce

### Tvorba kvízů (admin)
- **Ruční vkládání:** otázka, 2–6 odpovědí, označení správné (nebo více správných), volitelně obrázek (upload), časový limit a bodová hodnota per otázka (s výchozími hodnotami kvízu).
- Aplikace je **univerzální, ne jen na jedno téma** (filmy, sport, historie, zeměpis, hudba, firemní školení, hospodský kvíz…). Nic tematického nesmí být natvrdo v kódu.
- **Generování přes Gemini (doplněno):** formulář pro generování obsahuje:
  - **Kategorie / oblast** (výběr ze seznamu spravovaného v Nastavení, lze přidávat vlastní) + **podkategorie / upřesnění** (volný text, např. „české filmy 90. let“, „fotbal – MS“).
  - **Posuvníky:** počet otázek (např. 1–50), počet odpovědí (2–6), obtížnost (1–5), časový limit (5–120 s), body za otázku; u každého posuvníku zobrazená aktuální hodnota.
  - Cílová skupina (děti / dospělí / odborníci), jazyk (výchozí čeština), typy otázek (zaškrtávátka: ABCD, pravda/nepravda, anketa).
  - Volba „nevracet otázky, které už v databázi jsou“ (pošli Gemini seznam existujících otázek dané kategorie, ať se neopakují).
  - **Okno s promptem:** rozbalitelné textové pole se šablonou promptu pro Gemini, kterou jde přímo upravit před odesláním. Šablona používá zástupné proměnné (`{kategorie}`, `{upresneni}`, `{pocet}`, `{obtiznost}`, `{jazyk}`, `{pocet_odpovedi}`, `{cilova_skupina}`…), které se doplní z formuláře. Výchozí šablona se edituje v Nastavení, tlačítko „obnovit výchozí“.
  - Pokročilé: model Gemini a teplota (kreativita) — posuvník.
- PHP zavolá Gemini API (curl, model nastavitelný v configu), vyžádá si **strukturovaný JSON výstup** (response schema), výsledek zvaliduje a ukáže jako **návrh k úpravě** — admin může otázky editovat, mazat, přegenerovat jednotlivou otázku a teprve pak uložit. Ošetři chyby API a neplatný JSON.
- Typy otázek: výběr z možností (A/B/C/D), pravda/nepravda; a **anketa bez správné odpovědi** (jen hlasování, bez bodů).
- Kvízy: seznam, duplikace, mazání, změna pořadí otázek, export/import JSON.

### Nastavení kvízu / hry (komplexní volby)
- **Tajné hlasování** — moderátor ani ostatní nevidí, kdo jak hlasoval; ukládá se jen agregace (u bodovaných otázek se jméno spojí jen s body, ne s konkrétní volbou ve veřejném výstupu).
- **Viditelnost výsledků** — kdy a komu: průběžně během hlasování / až po skončení otázky / až na konci hry / nikdy; zvlášť pro projektor a pro telefony hráčů.
- Zobrazit hráči hned po odpovědi, zda měl pravdu (ano/ne).
- Výchozí časový limit (např. 10/20/30/60 s, nebo bez limitu) a bodování.
- **Bodování:** pevné body, nebo body s bonusem za rychlost (lineárně klesající s časem), volitelně série správných odpovědí (streak bonus). Vzorec dej do jedné funkce a popiš ho v adminu.
- Možnost změnit odpověď do konce limitu (ano/ne).
- Zamíchat pořadí otázek / odpovědí.
- Automatický postup na další otázku, nebo ruční ovládání moderátorem.
- Max. počet hráčů, povolit/zakázat připojení po startu, unikátnost jmen (duplicitní jméno → automaticky přidat číslo).

### Globální nastavení aplikace (doplněno)
- **Všechny proměnné nastavitelné v adminu**, ne jen v kódu: uložené v DB (tabulka `settings`), v `config.php` zůstanou jen tajné a startovací věci (API klíč, hash hesla, cesta k DB, `BASE_URL`).
- Stránka Nastavení rozdělená do záložek: Obecné (název aplikace, logo, barvy), Hra (výchozí limity, bodování, počty hráčů, interval pollingu), Gemini (model, teplota, šablona promptu, kategorie), TTS (hlas, rychlost, co předčítat), Údržba (mazání starých her, záloha/obnova DB).
- Číselné hodnoty jako **posuvníky s viditelnou hodnotou**, přepínače jako switche, u každé volby krátká nápověda.
- Nastavení kvízu přebírá globální výchozí hodnoty a jde je přepsat per kvíz; nastavení hry jde přepsat ještě při spuštění.
- Tlačítko „obnovit výchozí“ pro každou záložku.

### Režim bez velké obrazovky — jen hlas (doplněno)
Pro situace, kdy není k dispozici projektor/TV:
- Při spuštění hry volba **„Režim zobrazení“: velká obrazovka / bez obrazovky (hlasový režim)**.
- V hlasovém režimu moderátor ovládá hru **z mobilu** (kompaktní ovládací panel: QR + PIN k ukázání, seznam hráčů, Start / Další / Pauza / Zopakovat otázku).
- **Text otázky i odpovědi se zobrazí přímo na telefonech hráčů** (v normálním režimu jen barevná tlačítka).
- Vše důležité se **předčítá nahlas** z moderátorova telefonu (ideálně připojeného k Bluetooth reproduktoru): otázka, možnosti, „zbývá 5 sekund“, správná odpověď, průběžné pořadí (top 3) a vítěz.
- Odpočet začne až po dočtení otázky (nebo nastavitelné zpoždění), ať hráči neztrácí čas.
- Tlačítko „zopakovat“ pro znovupřečtení otázky.

### Hráčský klient
- Mobile-first, velká tlačítka odpovědí v barevných dlaždicích (barva + tvar, ať je to čitelné i pro barvoslepé).
- Zadání jména, uložení identity hráče do `localStorage` (token), aby **obnovení stránky nebo výpadek wifi neztratily hráče** — po návratu se znovu připojí ke stejné hře.
- Stavové obrazovky: čekání v lobby, otázka s odpočtem, „odpověď odeslána“, výsledek otázky, průběžné pořadí, konečné umístění.
- Ochrana proti dvojímu hlasování (server-side, token hráče + otázka).

### Obrazovka moderátora
- Velký **QR kód** s odkazem `BASE_URL/play/?pin=XXXX` + čitelný PIN.
- Živý seznam hráčů v lobby, možnost hráče vyhodit.
- Během otázky: text, odpovědi, odpočet, počet hráčů, kteří už odpověděli.
- Po otázce: graf rozložení odpovědí (respektuj nastavení viditelnosti a tajného hlasování), správná odpověď.
- **Průběžný a závěrečný žebříček** (top 3 výrazně, „stupně vítězů“), možnost exportu výsledků do CSV.

### Převod textu na řeč (TTS)
- Na obrazovce moderátora volitelné předčítání otázky (a případně odpovědí a vítěze).
- Primárně **Web Speech API** v prohlížeči (`speechSynthesis`, hlas `cs-CZ`, výběr hlasu a rychlosti v nastavení), ošetři, že prohlížeč vyžaduje interakci uživatele před prvním přehráním.
- Volitelně (přepínač v configu) generování audia přes **Gemini TTS** na serveru s cache souborů, jako fallback pro zařízení bez českého hlasu.

## Bezpečnost a robustnost
- Prepared statements všude, escapování výstupu, CSRF token v adminu, validace vstupů (délka jména, zakázané HTML).
- Rate limit na připojování a hlasování (per IP + per token).
- Staré hry automaticky archivovat/mazat (nastavitelné), ať DB neroste.
- Logování chyb do souboru mimo webroot.

## Struktura (návrh, můžeš upravit)
```
kviz/
  public/            ← webroot pro Caddy
    index.php, play/, host/, admin/, api/, assets/
  src/               ← PHP třídy (DB, Game, Scoring, Gemini, Tts, Auth)
  data/              ← SQLite + uploady + TTS cache (zapisovatelné, mimo webroot)
  config.example.php
  Caddyfile.example
  README.md
```

## Postup (etapy)
1. Návrh DB schématu a API (seznam endpointů + formát stavu hry). Ukaž mi ho ke schválení dřív, než začneš psát zbytek.
2. Admin: přihlášení, ruční tvorba kvízů, globální nastavení (záložky, posuvníky).
3. Herní smyčka: host obrazovka + hráčský klient + polling + časovač + bodování.
4. Žebříček, výsledky, tajné hlasování a viditelnost výsledků.
5. Gemini generování otázek (kategorie, posuvníky, editovatelný prompt).
6. TTS a hlasový režim bez velké obrazovky.
7. Nasazovací balíček: Caddyfile, PHP-FPM pool config, instalační kroky, README, krátký test se simulací ~20 hráčů (skript, který paralelně posílá odpovědi), aby se ověřilo, že polling server zvládne.

Píšu jako laik — u nasazení na server mi popiš kroky jednoduše a postupně.
