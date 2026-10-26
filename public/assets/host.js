'use strict';
/* Obrazovka moderátora / projektor. Adresa: /host/?game=PIN&key=KLÍČ */

const $app = document.getElementById('app');
const params = new URLSearchParams(location.search);
const PIN = params.get('game') || '';
const KEY = params.get('key') || '';
let cfg = { app_name: 'Kvíz', base_url: '' };
let transport = null;
let last = null;
let stopTimer = null;
let connOk = true;
let busy = false;

function joinUrl() {
  const base = cfg.base_url || new URL('..', location.href).href.replace(/\/$/, '');
  return base + '/play/?pin=' + PIN;
}

async function control(action, extra) {
  if (busy) return;
  busy = true;
  const r = await postJson('../api/host_control.php', { pin: PIN, key: KEY, action, ...(extra || {}) });
  busy = false;
  if (!r.ok) alert(r.message);
  if (transport) transport.poke();
}

/* ---------- hlasatel ---------- */
let spokenKey = null;        // fáze + číslo otázky, pro které už se četlo
let firstState = true;
let lastQuestionState = null; // poslední stav fáze „question“ (pro „čas vypršel“)
let prevPaused = null;
const cuesSaid = new Map();   // číslo otázky → množina už vyslovených časových upozornění
const sleep = ms => new Promise(r => setTimeout(r, ms));

const NUM = { 1: 'jedna', 2: 'dvě', 3: 'tři', 4: 'čtyři', 5: 'pět', 6: 'šest', 7: 'sedm', 8: 'osm', 9: 'devět', 10: 'deset',
  11: 'jedenáct', 12: 'dvanáct', 13: 'třináct', 14: 'čtrnáct', 15: 'patnáct', 16: 'šestnáct', 17: 'sedmnáct', 18: 'osmnáct',
  19: 'devatenáct', 20: 'dvacet', 30: 'třicet', 40: 'čtyřicet', 45: 'čtyřicet pět', 50: 'padesát', 60: 'šedesát', 90: 'devadesát', 120: 'sto dvacet' };
const ORD = { 1: 'První', 2: 'Druhé', 3: 'Třetí', 4: 'Čtvrté', 5: 'Páté', 6: 'Šesté', 7: 'Sedmé', 8: 'Osmé', 9: 'Deváté', 10: 'Desáté' };

/** „dvacet sekund“, „tři sekundy“ (správné skloňování) */
function secondsText(n) {
  return (NUM[n] || String(n)) + (n === 1 ? ' sekunda' : n >= 2 && n <= 4 ? ' sekundy' : ' sekund');
}
/** „Zbývá pět sekund.“ / „Zbývají tři sekundy.“ */
function remainingPhrase(n) {
  return (n >= 2 && n <= 4 ? 'Zbývají ' : 'Zbývá ') + secondsText(n) + '.';
}

/**
 * Časové upozornění pro otázku s běžícím odpočtem (nebo null). Každé zazní nejvýše jednou za otázku;
 * když se čas „přeskočí“ (např. po pauze), řekne se jen nejnovější.
 */
function timeCue(st, r) {
  const t = st.tts, q = st.question;
  const limit = q.time_limit * 1000;
  const warn = (st.game.warn_seconds || 5) * 1000;
  if (r === null || r <= 0 || limit <= 0) return null;
  let said = cuesSaid.get(q.index);
  if (!said) { said = new Set(); cuesSaid.set(q.index, said); }
  const cues = [
    { id: 'half', cond: t.warn_half && limit >= 20000 && r <= limit / 2 && r > warn + 1500, text: 'Zbývá polovina času.' },
    { id: 'warn', cond: t.read_countdown && limit >= warn + 2000 && r <= warn, text: remainingPhrase(Math.round(warn / 1000)) },
    ...[3, 2, 1].map(n => ({ id: 'f' + n, cond: t.warn_final && limit >= warn + 2000 && n * 1000 < warn && r <= n * 1000, text: ({ 3: 'Tři.', 2: 'Dva.', 1: 'Jedna.' })[n] })),
  ];
  let text = null;
  for (const c of cues) {
    if (c.cond && !said.has(c.id)) { said.add(c.id); text = c.text; }
  }
  return text;
}

/** Věty, které hlasatel řekne v dané fázi (podle nastavení). ctx: {first, timeUp} */
function phrasesFor(st, ctx) {
  ctx = ctx || {};
  const t = st.tts, q = st.question, g = st.game;
  if (!t) return [];
  const lbl = o => (g.letters ? CZ.letters[o.pos] + ': ' : '');
  const out = [];
  if (st.phase === 'question') {
    if (ctx.first && t.say_start) out.push('Hra začíná, hodně štěstí!');
    out.push((t.say_number ? 'Otázka číslo ' + (q.index + 1) + '. ' : '') + (t.say_type && q.type === 'poll' ? 'Anketa. ' : '') + q.text);
    const multiNote = t.say_type && q.multi ? 'Může být více správných odpovědí.' : '';
    if (t.read_options) {
      if (q.type === 'truefalse') out.push('Pravda, nebo nepravda?');
      else out.push((multiNote ? multiNote + ' ' : '') + 'Možnosti: ' + q.options.map(o => lbl(o) + o.text).join('. ') + '.');
    } else if (multiNote) out.push(multiNote);
    if (t.say_points && q.type !== 'poll' && q.points > 0) out.push('Za správnou odpověď je ' + CZ.points(q.points) + '.');
    if (t.say_limit && q.time_limit > 0) out.push('Na odpověď máte ' + secondsText(q.time_limit) + '.');
  } else if (st.phase === 'reveal') {
    const r = st.reveal;
    if (ctx.timeUp && t.say_time_up) out.push('Čas vypršel.');
    if (t.read_results) {
      if (q.type === 'poll') out.push('Hlasování skončilo.');
      else {
        const cor = q.options.filter(o => r.correct.includes(o.pos));
        out.push((cor.length > 1 ? 'Správné odpovědi: ' : 'Správná odpověď: ') + cor.map(o => lbl(o) + o.text).join('. ') + '.');
      }
    }
    if (t.say_stats && r.counts && q.type !== 'poll' && !q.multi) {   // jen když smí moderátor rozložení vidět
      const total = r.counts.reduce((a, b) => a + b, 0);
      const ok = r.correct.reduce((a, p) => a + (r.counts[p] || 0), 0);
      if (total > 0) out.push('Správných odpovědí: ' + ok + ' z ' + total + '.');
    }
    if (t.say_explanation && r.explanation) out.push(r.explanation);
  } else if (st.phase === 'leaderboard' && t.read_leaderboard) {
    const top = (st.leaderboard || []).slice(0, t.lb_top || 3);
    out.push((g.is_last ? 'Konečné pořadí. ' : 'Průběžné pořadí. ') + top.map(r =>
      (ORD[r.rank] || r.rank + '.') + ' místo: ' + r.name + (t.say_scores ? ', ' + CZ.points(r.score) : '') + '.').join(' '));
  } else if (st.phase === 'finished' && t.say_winner) {
    const lb = st.leaderboard || [];
    const win = lb.filter(r => r.rank === 1);
    if (win.length) {
      out.push('Konec hry. ' + (win.length > 1 ? 'Vítězi jsou ' : 'Vítězem je ') + CZ.join(win.map(r => r.name)) + (t.say_scores ? ', ' + CZ.points(win[0].score) : '') + '.');
      const sec = lb.filter(r => r.rank === 2), thr = lb.filter(r => r.rank === 3);
      if (sec.length) out.push('Druhé místo: ' + CZ.join(sec.map(r => r.name)) + '.');
      if (thr.length) out.push('Třetí místo: ' + CZ.join(thr.map(r => r.name)) + '.');
    }
  }
  return out;
}

async function speakPhase(st, startCountdown, ctx) {
  const phrases = phrasesFor(st, ctx);
  const key = spokenKey;
  const t0 = Date.now();
  const res = await Promise.all(phrases.map(p => Speech.say(p)));
  if (res.some(r => r.cancelled) || key !== spokenKey) return;
  if (!startCountdown) return;
  // Hlasový režim: odpočet začne až po dočtení (+ nastavená prodleva); bez hlasu podle odhadu doby čtení.
  const spoken = phrases.length > 0 && res.every(r => r.spoken);
  if (!spoken) {
    const est = Math.max(2500, phrases.join(' ').length * 65);
    if (Date.now() - t0 < est) await sleep(est - (Date.now() - t0));
  }
  await sleep(((st.tts && st.tts.read_delay) || 0) * 1000);
  const cur = last;
  if (key === spokenKey && cur && cur.phase === 'question' && cur.question.reading && cur.question.index === st.question.index) {
    control('tts_done');
  }
}

function handleSpeech(st) {
  const key = st.phase + ':' + (st.question ? st.question.index : '-');
  if (key === spokenKey) return;
  spokenKey = key;
  const wasFirst = firstState;
  firstState = false;
  Speech.cancel();
  const reading = st.phase === 'question' && st.question.reading;
  if (wasFirst && !reading) return;   // po obnovení stránky už rozehranou fázi znovu nečteme
  const ctx = { first: st.phase === 'question' && st.question.index === 0 };
  if (st.phase === 'reveal') {
    const lq = lastQuestionState;   // otázka skončila vypršením limitu, ne dřívějším uzavřením
    ctx.timeUp = !!(lq && lq.question && lq.question.index === st.question.index && lq.question.ends_at
      && Clock.now() >= lq.question.ends_at - 1500);
  }
  speakPhase(st, reading, ctx);
}

/** Hlášení pauzy a pokračování. */
function handlePause(st) {
  if (prevPaused !== null && st.paused !== prevPaused && st.tts && st.tts.say_pause) {
    Speech.say(st.paused ? 'Hra je pozastavena.' : 'Pokračujeme.');
  }
  prevPaused = st.paused;
}

function repeatSpeech() {
  if (!last) return;
  Speech.blocked = false;
  Speech.cancel();
  speakPhase(last, false);
}
function ttsButtons(st) {
  if (!st.tts) return [];
  const out = [];
  if (Speech.on || st.mode === 'voice') out.push(btn('🔁 Zopakovat', repeatSpeech, 'sec'));
  out.push(btn(Speech.on ? '🔊 Hlas zapnut' : '🔇 Hlas vypnut', () => { Speech.setOn(!Speech.on); if (last) render(last); }, 'sec'));
  if (st.phase === 'question' && st.question.reading) out.push(btn('Přeskočit čtení ▶', () => control('tts_done'), 'sec'));
  return out;
}

function shell(st, content, controls) {
  if (stopTimer) { stopTimer(); stopTimer = null; }
  const g = st.game;
  document.body.classList.toggle('voice', st.mode === 'voice');
  put($app,
    !connOk ? h('div', { class: 'banner conn' }, 'Spojení se serverem ztraceno, zkouším znovu…') : null,
    st.paused ? h('div', { class: 'banner' }, '⏸ Hra je pozastavená') : null,
    Speech.on && Speech.blocked
      ? h('div', { class: 'banner' }, 'Prohlížeč zablokoval hlas. ', h('button', { class: 'pill', style: 'border:0;color:#fff;cursor:pointer', onclick: () => { render(last); repeatSpeech(); } }, '🔊 Povolit hlas'))
      : null,
    Speech.lastError
      ? h('div', { class: 'banner conn' }, 'Gemini TTS nefunguje: ' + Speech.lastError)
      : null,
    Speech.on && st.tts && Speech.supported() && !Speech.czechVoice() && !st.tts.gemini
      ? h('div', { class: 'banner' }, 'Toto zařízení nemá český hlas, čtení bude znít špatně. Nainstalujte český hlas v systému, nebo zapněte záložní Gemini TTS v nastavení.')
      : null,
    h('div', { class: 'h-wrap' },
      h('div', { class: 'h-top' },
        h('div', { class: 'title' }, cfg.logo ? h('img', { class: 'logo-sm', src: '../' + cfg.logo, alt: '' }) : null, cfg.logo ? (g.title || '') : (cfg.app_name || 'Kvíz') + (g.title ? ' – ' + g.title : '')),
        h('div', { class: 'row' },
          h('span', { class: 'pill' }, 'PIN ' + g.pin), ' ',
          h('span', { class: 'pill' }, '👥 ' + g.players_count),
          ' ', h('button', { class: 'pill', style: 'border:0;color:#fff', title: 'Celá obrazovka', onclick: () => {
            if (document.fullscreenElement) document.exitFullscreen(); else document.documentElement.requestFullscreen().catch(() => {});
          } }, '⛶'))),
      h('div', { class: 'h-main' }, content)),
    h('div', { class: 'h-ctl' }, controls || [], ttsButtons(st)));
}

const btn = (label, fn, cls, disabled) => h('button', { class: cls || '', disabled: !!disabled, onclick: fn }, label);

function csvButton(primary) {
  return btn('⬇ Výsledky (CSV)', () => {
    location.href = '../api/host_export.php?pin=' + encodeURIComponent(PIN) + '&key=' + encodeURIComponent(KEY);
  }, primary ? '' : 'sec');
}

function endButton() {
  return btn('Ukončit hru', () => { if (confirm('Opravdu ukončit hru?')) control('finish'); }, 'sec');
}
function pauseButton(st) {
  return btn(st.paused ? '▶ Pokračovat' : '⏸ Pauza', () => control(st.paused ? 'resume' : 'pause'), 'sec');
}

/* ---------- fáze ---------- */
function viewLobby(st) {
  const url = joinUrl();
  let qrSvg = null;
  try {
    const qr = qrcode(0, 'M');
    qr.addData(url);
    qr.make();
    qrSvg = qr.createSvgTag({ cellSize: 6, margin: 0, scalable: true });
  } catch (e) { qrSvg = null; }
  const qrBox = h('div', { class: 'qrbox' });
  if (qrSvg) qrBox.insertAdjacentHTML('beforeend', qrSvg);   // SVG vygenerované lokální knihovnou, bez vstupu od uživatele
  qrBox.append(h('div', { class: 'pin' }, PIN), h('div', { class: 'joinurl' }, url));

  const players = st.players || [];
  shell(st,
    h('div', { class: 'h-lobby' }, qrBox,
      h('div', {},
        h('div', { class: 'big', style: 'margin-bottom:.8rem' }, '📱 Naskenuj QR kód nebo zadej PIN'),
        players.length
          ? h('div', { class: 'chips' }, players.map(p => h('span', { class: 'chip' }, avatar(p.name, 'sm'), p.name,
              h('button', { title: 'Vyhodit hráče', onclick: () => confirm('Vyhodit hráče „' + p.name + '“?') && control('kick', { player_id: p.id }) }, '✕'))))
          : h('div', { style: 'opacity:.95' }, '⏳ Zatím se nikdo nepřipojil…'))),
    [btn('▶ Start hry', () => control('start'), '', players.length < 1), endButton()]);
}

function tilesFor(q, r, counts) {
  const total = counts ? counts.reduce((a, b) => a + b, 0) : 0;
  const votes = r && r.votes ? r.votes : null;   // jen v netajné hře
  const lay = last && last.game ? (last.game.layout === 'grid' ? ' lay-grid' : last.game.layout === 'list' ? ' lay-list' : '') : '';
  return h('div', { class: 'h-tiles' + lay }, q.options.map(o => {
    const ok = r && q.type !== 'poll' && r.correct.includes(o.pos);
    const dim = r && q.type !== 'poll' && !ok;
    const c = counts ? counts[o.pos] : null;
    const who = votes ? votes.filter(v => v.choice.includes(o.pos)).map(v => v.name).join(', ') : '';
    return h('div', { class: 'tile c' + o.pos + (ok ? ' ok' : '') + (dim ? ' dim' : '') },
      h('span', { class: 'shape' }, SHAPES[o.pos]),
      last && last.game && last.game.letters ? h('span', { class: 'letter' }, CZ.letters[o.pos]) : null,
      h('span', { class: 'tx' }, o.text, who ? h('span', { class: 'who' }, who) : null),
      c !== null ? h('span', { class: 'cnt' }, c) : null,
      c !== null && total > 0 ? h('span', { class: 'bar', style: 'width:' + Math.round(100 * c / total) + '%' }) : null);
  }));
}

function viewQuestion(st) {
  const q = st.question, g = st.game;
  const timer = h('div', { class: 'bigtimer' }, '');
  const hasLimit = q.time_limit > 0;
  shell(st,
    h('div', {},
      h('div', { class: 'row', style: 'display:flex;justify-content:space-between;align-items:center' },
        h('span', { class: 'pill' }, '❓ ' + (q.index + 1) + ' / ' + q.total + (q.type === 'poll' ? ' · anketa' : ' · ⭐ ' + q.points)),
        hasLimit ? timer : h('span', { class: 'pill' }, '∞ bez limitu'),
        h('span', { class: 'pill gold' }, '✍ ' + q.answered_count + ' / ' + g.players_count)),
      h('div', { class: 'h-q' }, q.text),
      q.image ? h('img', { class: 'qimg', style: 'max-height:32vh', src: '../api/media.php?f=' + encodeURIComponent(q.image), alt: '' }) : null,
      tilesFor(q, null, q.counts)),
    [pauseButton(st), btn('Ukončit otázku ▶', () => control('next')), endButton()]);
  stopTimer = every(200, () => {
    if (q.reading) { timer.textContent = '🔊'; return; }
    const r = remainingMs(q, st.paused);
    if (r === null) return;
    const s = Math.ceil(r / 1000);
    timer.textContent = st.paused ? '⏸' : s;
    timer.className = 'bigtimer' + (s <= (g.warn_seconds || 5) && !st.paused ? ' low' : '');
    setRing(timer, r, q.time_limit * 1000);
  });
}

function viewReveal(st) {
  const q = st.question, r = st.reveal;
  shell(st,
    h('div', {},
      h('div', { class: 'h-q' }, q.text),
      tilesFor(q, r, r.counts),
      r.explanation ? h('div', { class: 'expl', style: 'margin:1rem auto;max-width:50rem' }, r.explanation) : null,
      st.game.auto_advance ? h('p', { style: 'text-align:center;opacity:.8' }, 'Automatický postup je zapnutý.') : null),
    [pauseButton(st), btn(st.game.lb_next ? 'Žebříček ▶' : (st.game.is_last ? 'Konec hry ▶' : 'Další otázka ▶'), () => control('next')), endButton()]);
}

function podium(lb) {
  const by = n => lb.find(r => r.rank === n);
  const col = (n, cls) => {
    const r = by(n);
    return r ? h('div', { class: 'col ' + cls },
      n === 1 ? h('div', { class: 'crown' }, '👑') : null, avatar(r.name, 'lg'),
      h('div', { class: 'nm' }, r.name), h('div', { class: 'sc' }, '⭐ ' + r.score), h('div', { class: 'blk' }, n)) : null;
  };
  return h('div', { class: 'podium' }, col(2, 'p2'), col(1, 'p1'), col(3, 'p3'));
}

function restList(lb, from) {
  const rows = lb.filter(r => r.rank > 3).slice(0, from);
  if (!rows.length) return null;
  return h('ul', { class: 'lb h-rest' }, rows.map(r => h('li', {},
    h('span', { class: 'rk' }, r.rank + '.'), avatar(r.name, 'sm'), h('span', { class: 'nm' }, r.name),
    r.gained ? h('span', { class: 'gn' }, '+' + r.gained) : null, h('span', {}, r.score))));
}

function viewBoard(st) {
  const isLast = st.game.is_last;
  shell(st,
    h('div', {}, h('div', { class: 'h-q' }, isLast ? '🏁 Konečné pořadí' : '📊 Průběžné pořadí'), podium(st.leaderboard), restList(st.leaderboard, 12)),
    [pauseButton(st), btn(isLast ? 'Ukončit hru ▶' : 'Další otázka ▶', () => control('next')), csvButton(), isLast ? null : endButton()]);
}

function viewFinished(st) {
  const res = st.question_results || [];
  shell(st,
    h('div', {}, confetti(70), h('div', { class: 'h-q' }, '🏆 Konec hry!'), podium(st.leaderboard), restList(st.leaderboard, 40),
      res.length ? h('details', { class: 'summary' }, h('summary', {}, 'Rozložení odpovědí po otázkách'),
        res.map(x => h('div', { class: 'qs' }, h('strong', {}, (x.index + 1) + '. ' + x.text),
          x.options.map((o, i) => h('div', { class: 'o' + (o.correct ? ' ok' : '') }, SHAPES[i] + ' ' + o.text + (o.correct ? ' ✔' : ''), o.count !== null ? ' — ' + o.count : ''))))) : null),
    [csvButton(true), btn('Zpět do administrace', () => { location.href = '../admin/'; }, 'sec')]);
}

let lastScreen = null;
function render(st) {
  const screen = st.phase + ':' + (st.question ? st.question.index : '-') + ':' + ((st.players || []).length);
  $app.classList.toggle('still', screen === lastScreen && st.phase !== 'lobby');
  lastScreen = screen;
  ({ lobby: viewLobby, question: viewQuestion, reveal: viewReveal, leaderboard: viewBoard, finished: viewFinished }[st.phase] || viewLobby)(st);
}

function onState(st) {
  last = st;
  connOk = true;
  if (st.tts) Speech.setConfig(st.tts);
  render(st);
  handlePause(st);
  handleSpeech(st);
  if (st.phase === 'question') lastQuestionState = st;
}

// Časová upozornění (polovina času, před koncem, odpočet 3-2-1) – jen při běžícím odpočtu
setInterval(() => {
  const st = last;
  if (!st || st.phase !== 'question' || st.paused || st.question.reading || !st.tts || !Speech.on) return;
  const cue = timeCue(st, remainingMs(st.question, false));
  if (cue) Speech.say(cue);
}, 250);
/* ---------- start ---------- */
(async () => {
  if (!/^\d{4,8}$/.test(PIN) || !KEY) {
    $app.replaceChildren(h('div', { class: 'p-wrap' }, h('div', { class: 'card', style: 'margin:3rem auto;max-width:30rem' },
      h('h1', {}, 'Chybí údaje o hře'), h('p', {}, 'Otevřete tuto stránku z administrace tlačítkem u spuštěné hry.'))));
    return;
  }
  cfg = await loadConfig('../');
  Speech.geminiReq = text => fetch('../api/tts_gemini.php', {
    method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ pin: PIN, key: KEY, text }),
  });
  Speech.onBlocked = () => { if (last) render(last); };
  Speech.onError = () => { if (last) render(last); };
  try { if (Speech.supported()) window.speechSynthesis.getVoices(); } catch (e) { /* načtení seznamu hlasů */ }
  transport = new PollTransport({
    url: '../api/state.php?game=' + encodeURIComponent(PIN) + '&role=host&token=' + encodeURIComponent(KEY),
    onState,
    onStatus: ok => { if (ok !== connOk) { connOk = ok; if (last) render(last); } },
    onFatal: (status, d) => {
      $app.replaceChildren(h('div', { class: 'p-wrap' }, h('div', { class: 'card', style: 'margin:3rem auto;max-width:30rem' },
        h('h1', {}, 'Hru se nepodařilo otevřít'), h('p', {}, (d && d.message) || 'Chyba ' + status))));
    },
  });
  transport.start();
  document.addEventListener('keydown', e => {
    if (!last || e.target.tagName === 'INPUT') return;
    if (e.key === 'Enter' || e.key === ' ') {
      e.preventDefault();
      const p = last.phase;
      if (p === 'lobby') { if ((last.players || []).length) control('start'); }
      else if (p === 'question' && last.question.reading) control('tts_done');
      else if (p !== 'finished') control('next');
    }
  });
  document.addEventListener('visibilitychange', () => { if (!document.hidden && transport) transport.poke(); });
})();
