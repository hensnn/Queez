'use strict';
/* Hráčský klient (mobil). */

const $app = document.getElementById('app');
const LS_KEY = 'kviz_player';
let cfg = { app_name: 'Kvíz' };
let transport = null;
let sess = null;          // {pin, token, name}
let stopTimer = null;
let connOk = true;
let local = { qIndex: -1, sel: [] };   // lokální výběr u otázky s více správnými odpověďmi
let last = null;          // poslední stav ze serveru
let myLastChoice = {};    // index otázky → moje volba (u tajného hlasování ji server po uzavření smaže)

const store = {
  load() { try { return JSON.parse(localStorage.getItem(LS_KEY)); } catch (e) { return null; } },
  save(s) { try { localStorage.setItem(LS_KEY, JSON.stringify(s)); } catch (e) { /* soukromý režim */ } },
  clear() { try { localStorage.removeItem(LS_KEY); } catch (e) { /* nic */ } },
};

/** Zastaví běžící odpočet. Musí se volat PŘED vytvořením nové obrazovky (ta si spouští vlastní odpočet). */
function stopTick() {
  if (stopTimer) { stopTimer(); stopTimer = null; }
}

function shell(...content) {
  put($app, !connOk ? h('div', { class: 'banner conn' }, 'Spojení ztraceno, zkouším znovu…') : null, content);
}

/* ---------- připojení ---------- */
function showJoin(pin, message) {
  stopTick();
  if (transport) { transport.stop(); transport = null; }
  const pinIn = h('input', { type: 'text', inputmode: 'numeric', pattern: '[0-9]*', maxlength: 8, autocomplete: 'off', value: pin || '' });
  const nameIn = h('input', { type: 'text', maxlength: 20, autocomplete: 'nickname', placeholder: 'Např. Honza' });
  const err = h('div', { class: 'err' }, message || '');
  const btn = h('button', { class: 'btn' }, 'Připojit se');
  const go = async () => {
    err.textContent = '';
    btn.disabled = true;
    const r = await postJson('../api/join.php', { pin: pinIn.value.trim(), name: nameIn.value });
    btn.disabled = false;
    if (!r.ok) { err.textContent = r.message; return; }
    sess = { pin: r.data.pin, token: r.data.token, name: r.data.name };
    store.save(sess);
    myLastChoice = {};
    connect();
  };
  btn.addEventListener('click', go);
  nameIn.addEventListener('keydown', e => { if (e.key === 'Enter') go(); });
  shell(h('div', { class: 'p-wrap' }, h('div', { class: 'p-center' },
    cfg.logo ? h('img', { class: 'logo-join', src: '../' + cfg.logo, alt: cfg.app_name || '' }) : null,
    cfg.logo ? null : h('div', { class: 'title-bubble' }, '🎲 ' + cfg.app_name),   // s logem se název neopakuje
    h('div', { class: 'card' },
      h('h1', {}, 'Připoj se ke hře! 🎉'),
      h('label', {}, 'PIN hry'), pinIn,
      h('label', {}, 'Vaše jméno'), nameIn,
      err, btn))));
  (pin ? nameIn : pinIn).focus();
}

function connect() {
  last = null;
  local = { qIndex: -1, sel: [] };
  transport = new PollTransport({
    url: '../api/state.php?game=' + encodeURIComponent(sess.pin) + '&role=player&token=' + encodeURIComponent(sess.token),
    onState: st => { last = st; connOk = true; render(st); },
    onStatus: ok => {
      if (ok !== connOk) { connOk = ok; if (last) render(last); }
    },
    onFatal: (status, d) => {
      const pin = sess ? sess.pin : '';
      store.clear(); sess = null;
      showJoin(status === 404 ? '' : pin, (d && d.message) || 'Připojení se nezdařilo.');
    },
  });
  transport.start();
}

/* ---------- vykreslení ---------- */
function head(st, extra) {
  return h('div', { class: 'p-top' },
    h('span', { class: 'pill' }, st.me ? avatar(st.me.name, 'sm') : null, st.me ? st.me.name : ''),
    extra || null,
    h('span', { class: 'pill gold' }, '⭐ ' + (st.me ? st.me.score : 0)));
}

let lastScreen = null;
/** Třída rozložení odpovědních tlačítek podle nastavení (auto = bez třídy). */
function layoutClass(st) {
  return st.game.layout === 'grid' ? ' lay-grid' : st.game.layout === 'list' ? ' lay-list' : '';
}

function render(st) {
  const screen = st.phase + ':' + (st.question ? st.question.index : '-');
  $app.classList.toggle('still', screen === lastScreen);   // bez animací při pouhé aktualizaci stejné obrazovky
  lastScreen = screen;
  stopTick();
  const body = ({ lobby: viewLobby, question: viewQuestion, reveal: viewReveal, leaderboard: viewBoard, finished: viewFinished }[st.phase] || viewLobby)(st);
  shell(st.paused ? h('div', { class: 'banner' }, 'Hra je pozastavená') : null, body);
}

function viewLobby(st) {
  return h('div', { class: 'p-wrap' }, h('div', { class: 'p-center' },
    avatar(st.me.name, 'xl'),
    h('div', { class: 'big' }, 'Jsi ve hře, ' + st.me.name + '! 🎉'),
    h('div', {}, 'Čekáme na start od moderátora…'),
    h('div', { class: 'pill' }, '👥 Hráčů: ' + st.game.players_count)));
}

function viewQuestion(st) {
  const q = st.question;
  if (local.qIndex !== q.index) { local = { qIndex: q.index, sel: [] }; }
  const me = st.me;
  if (me.choice) myLastChoice[q.index] = me.choice;
  const selected = local.sel.length ? local.sel : (me.choice || []);
  const timeEl = h('div', { class: 'timer' }, '');
  const hasLimit = q.time_limit > 0;
  const status = h('div', { class: 'p-center', style: 'flex:0;margin:.8rem 0' });

  const sendChoice = async choice => {
    const r = await postJson('../api/answer.php', { pin: sess.pin, token: sess.token, choice });
    if (!r.ok) {
      put(status, h('div', { class: 'pill' }, r.message));
      if (r.status === 401) { transport.poke(); }
      return;
    }
    myLastChoice[q.index] = choice;
    transport.poke();
  };

  const reading = !!q.reading;   // hlasový režim: otázka se ještě předčítá, odpočet nezačal
  const closed = () => { const r = remainingMs(q, st.paused); return reading || st.paused || (r !== null && r <= 0); };
  const locked = reading || (me.answered && !st.game.allow_change_answer);

  const hasText = q.options.some(o => o.text !== null);
  const tiles = h('div', { class: 'tiles n' + q.options.length + (hasText ? ' txt' : '') + layoutClass(st) },
    q.options.map(o => {
      const isSel = selected.includes(o.pos);
      const cnt = q.counts ? q.counts[o.pos] : null;
      return h('button', {
        class: 'tile c' + o.pos + (isSel ? ' sel' : ''),
        disabled: locked,
        'aria-label': 'Odpověď ' + (o.pos + 1),
        onclick: () => {
          if (closed()) return;
          if (q.multi) {
            local.sel = (local.sel.length ? local.sel : (me.choice || [])).slice();
            const i = local.sel.indexOf(o.pos);
            if (i >= 0) local.sel.splice(i, 1); else local.sel.push(o.pos);
            render(st);
          } else {
            local.sel = [o.pos];
            sendChoice([o.pos]);
            render(st);
          }
        },
      }, h('span', { class: 'shape' }, SHAPES[o.pos]),
      st.game.letters && o.text !== null ? h('span', { class: 'letter' }, 'ABCDEF'[o.pos]) : null,
      o.text !== null ? h('span', { class: 'tx' }, o.text) : null,
      cnt !== null ? h('span', { class: 'cnt' }, cnt) : null);
    }));

  const submit = q.multi && !locked
    ? h('button', { class: 'btn', disabled: !selected.length, onclick: () => sendChoice(selected.slice().sort()) },
        me.answered ? 'Změnit odpověď' : 'Odeslat odpověď')
    : null;

  if (me.answered) {
    const fb = me.was_correct === true ? 'Správně! 🎉' : me.was_correct === false ? 'Tentokrát ne 😅' : null;
    put(status, h('div', { class: 'big' }, fb || 'Odpověď odeslána ✔'),
      st.game.allow_change_answer ? h('div', { class: 'pill' }, 'Do konce limitu ji můžete změnit') : null);
  }

  if (reading) put(status, h('div', { class: 'pill' }, '🔊 Čte se otázka, odpověď půjde za chvilku'));

  stopTimer = every(200, () => {
    if (reading) { timeEl.textContent = '🔊'; return; }
    const r = remainingMs(q, st.paused);
    if (!hasLimit || r === null) { timeEl.textContent = '∞'; return; }
    const s = Math.ceil(r / 1000);
    timeEl.textContent = st.paused ? '⏸' : s;
    timeEl.className = 'timer' + (s <= (st.game.warn_seconds || 5) && !st.paused ? ' low' : '');
    setRing(timeEl, r, q.time_limit * 1000);
  });

  return h('div', { class: 'p-wrap' },
    h('div', { class: 'p-top' }, h('span', { class: 'pill' }, '❓ ' + (q.index + 1) + ' / ' + q.total), timeEl,
      h('span', { class: 'pill gold' }, '⭐ ' + me.score)),
    q.text || q.image ? h('div', { class: 'qbox' },
      q.text ? h('div', { class: 'qtext' }, q.text) : null,
      q.image ? h('img', { class: 'qimg', src: '../api/media.php?f=' + encodeURIComponent(q.image), alt: '' }) : null) : null,
    tiles, submit, status);
}

function viewReveal(st) {
  const q = st.question, r = st.reveal, me = st.me;
  let box;
  if (me.was_correct === true) box = h('div', { class: 'result good' }, '🎉 Správně!', h('div', { class: 'big' }, '+' + me.points_gained + ' bodů'));
  else if (me.was_correct === false) box = h('div', { class: 'result bad' }, me.answered ? '😅 Tentokrát ne' : '😴 Bez odpovědi', me.answered && me.points_gained ? h('div', { class: 'big' }, '+' + me.points_gained + ' bodů') : null);
  else box = h('div', { class: 'result neutral' }, me.answered ? '🗳️ Díky za hlas!' : '😴 Bez odpovědi');
  const mine = me.choice || myLastChoice[q.index] || [];
  const isPoll = q.type === 'poll';
  const tiles = h('div', { class: 'tiles n' + q.options.length + (q.options.some(o => o.text !== null) ? ' txt' : '') + layoutClass(st), style: 'flex:0' },
    q.options.map(o => h('div', {
      class: 'tile c' + o.pos + (!isPoll && r.correct.includes(o.pos) ? ' ok' : (!isPoll ? ' dim' : '')) + (mine.includes(o.pos) ? ' sel' : ''),
      style: 'min-height:4rem',
    }, h('span', { class: 'shape' }, SHAPES[o.pos]), o.text !== null ? h('span', { class: 'tx' }, o.text) : null,
    r.counts ? h('span', { class: 'cnt' }, r.counts[o.pos]) : null)));
  return h('div', { class: 'p-wrap' }, head(st),
    q.text ? h('div', { class: 'qbox', style: 'margin-bottom:.8rem' }, h('div', { class: 'qtext', style: 'font-size:1.15rem' }, q.text)) : null,
    box,
    h('div', { class: 'p-center', style: 'flex:0;margin:.8rem 0' },
      h('div', { class: 'big' }, me.rank + '. místo'), h('div', {}, me.score + ' bodů' + (me.streak > 1 ? ' · série ' + me.streak + '🔥' : ''))),
    tiles,
    r.explanation ? h('div', { class: 'expl', style: 'margin-top:.8rem' }, r.explanation) : null);
}

function lbList(st) {
  return h('ul', { class: 'lb' }, (st.leaderboard || []).map(r =>
    h('li', { class: r.name === st.me.name ? 'me' : '' },
      h('span', { class: 'rk' }, r.rank === 1 ? '🥇' : r.rank === 2 ? '🥈' : r.rank === 3 ? '🥉' : r.rank + '.'),
      avatar(r.name, 'sm'), h('span', { class: 'nm' }, r.name),
      r.gained ? h('span', { class: 'gn' }, '+' + r.gained) : null, h('span', {}, r.score))));
}

function viewBoard(st) {
  return h('div', { class: 'p-wrap' }, head(st),
    h('div', { class: 'big', style: 'text-align:center;margin:.5rem 0' }, st.game.is_last ? '🏁 Konečné pořadí' : '📊 Průběžné pořadí'),
    lbList(st),
    h('div', { class: 'p-center', style: 'flex:0;margin-top:1rem' }, h('div', { class: 'big' }, 'Vy: ' + st.me.rank + '. místo'), h('div', {}, st.me.score + ' bodů')),
    h('div', { class: 'p-center', style: 'flex:0;margin-top:1rem' }, st.game.is_last ? 'Za chvíli se vyhlásí vítěz…' : 'Čekejte na další otázku…'));
}

function viewFinished(st) {
  const medal = st.me.rank === 1 ? '🥇' : st.me.rank === 2 ? '🥈' : st.me.rank === 3 ? '🥉' : '🏁';
  return h('div', { class: 'p-wrap' }, st.me.rank <= 3 ? confetti(46) : null, h('div', { class: 'p-center', style: 'flex:0;margin:1rem 0' },
    h('div', { class: 'huge' }, medal), h('div', { class: 'big' }, 'Konec hry!'),
    h('div', { class: 'big' }, st.me.rank + '. místo'), h('div', {}, st.me.score + ' bodů')),
    lbList(st),
    h('button', { class: 'btn sec', onclick: () => { store.clear(); sess = null; if (transport) transport.stop(); showJoin(''); } }, 'Připojit se k nové hře'));
}

/* ---------- start ---------- */
(async () => {
  cfg = await loadConfig('../');
  const urlPin = new URLSearchParams(location.search).get('pin') || '';
  const saved = store.load();
  if (saved && saved.pin && saved.token && (!urlPin || urlPin === saved.pin)) {
    sess = saved;
    connect();
  } else {
    showJoin(urlPin);
  }
  document.addEventListener('visibilitychange', () => { if (!document.hidden && transport) transport.poke(); });
})();
