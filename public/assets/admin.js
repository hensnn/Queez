'use strict';

const $app = document.getElementById('app');
const S = { csrf: '', tabs: [], defs: {}, values: {}, categories: [] };
const TYPES = { choice: 'Výběr z možností', truefalse: 'Pravda / nepravda', poll: 'Anketa (bez správné odpovědi)' };
const SHAPES = ['▲', '◆', '●', '■', '★', '⬣'];
let dirty = false;

/* ---------- pomocné funkce ---------- */
function h(tag, props, ...kids) {
  const e = document.createElement(tag);
  for (const [k, v] of Object.entries(props || {})) {
    if (v === null || v === undefined || v === false) continue;
    if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
    else if (k === 'class') e.className = v;
    else if (['value', 'checked', 'disabled', 'selected'].includes(k)) e[k] = v;
    else e.setAttribute(k, v === true ? '' : v);
  }
  for (const kid of kids.flat(Infinity)) {
    if (kid === null || kid === undefined || kid === false) continue;
    e.append(kid instanceof Node ? kid : document.createTextNode(String(kid)));
  }
  return e;
}

function put(el, ...kids) { // replaceChildren by z null udělalo text „null“
  el.replaceChildren(...kids.flat(Infinity).filter(k => k !== null && k !== undefined && k !== false));
  return el;
}

let toastTimer;
function toast(msg, err) {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'show' + (err ? ' err' : '');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => (t.className = ''), err ? 6000 : 2500);
}

async function api(path, opts = {}) {
  const o = { method: opts.method || 'GET', credentials: 'same-origin', headers: {} };
  if (S.csrf) o.headers['X-CSRF-Token'] = S.csrf;
  if (opts.form) { o.body = opts.form; o.method = 'POST'; }
  else if (opts.body !== undefined) {
    o.method = 'POST';
    o.headers['Content-Type'] = 'application/json';
    o.body = JSON.stringify(opts.body);
  }
  const r = await fetch('../api/' + path, o);
  let d = null;
  try { d = await r.json(); } catch (e) { /* prázdná odpověď */ }
  if (r.status === 401 && path !== 'admin_login.php') { showLogin(); throw new Error('Přihlaste se.'); }
  if (!r.ok) throw new Error((d && d.message) || 'Chyba ' + r.status);
  return d;
}

async function act(fn) { // spustí akci a zobrazí chybu jako toast
  try { return await fn(); } catch (e) { toast(e.message, true); }
}

const fmt = (d, v) => (v === 0 && d.zero_label ? d.zero_label : v + (d.unit || ''));

/* ---------- ovládací prvky podle definice nastavení ---------- */
function control(d, value, onchange) {
  switch (d.type) {
    case 'int':
    case 'float': {
      const out = h('output', {}, fmt(d, value));
      const inp = h('input', {
        type: 'range', min: d.min, max: d.max, step: d.step || 1, value: value,
        oninput: () => {
          const v = d.type === 'int' ? parseInt(inp.value, 10) : parseFloat(inp.value);
          out.textContent = fmt(d, v);
          onchange(v);
        },
      });
      return h('div', { class: 'slider' }, inp, out);
    }
    case 'bool':
      return h('label', { class: 'switch' },
        h('input', { type: 'checkbox', checked: !!value, onchange: e => onchange(e.target.checked) }), h('span'));
    case 'select': {
      const s = h('select', { onchange: () => onchange(s.value) },
        Object.entries(d.options).map(([k, l]) => h('option', { value: k, selected: k === value }, l)));
      return s;
    }
    case 'color':
      return h('input', { type: 'color', value: value, oninput: e => onchange(e.target.value) });
    case 'textarea':
      return h('textarea', { rows: 12, value: value, oninput: e => onchange(e.target.value) });
    case 'image': {
      const box = h('div', { class: 'row' });
      const draw = v => {
        put(box,
          v ? h('img', { class: 'thumb', src: '../api/media.php?f=' + v, alt: '' }) : null,
          h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp', onchange: async e => {
            const f = e.target.files[0];
            if (!f) return;
            await act(async () => {
              const fd = new FormData(); fd.append('file', f);
              const r = await api('admin_upload.php', { form: fd });
              onchange(r.file); draw(r.file);
            });
          } }),
          v ? h('button', { class: 'small', onclick: () => { onchange(''); draw(''); } }, 'Odebrat') : null);
      };
      draw(value);
      return box;
    }
    default:
      return h('input', { type: 'text', id: d.key ? 'set_' + d.key : null, value: value, maxlength: d.max || 200, oninput: e => onchange(e.target.value) });
  }
}

/* ---------- přihlášení ---------- */
function showLogin() {
  const pw = h('input', { type: 'password', autocomplete: 'current-password', autofocus: true });
  const go = async () => act(async () => {
    const r = await api('admin_login.php', { body: { password: pw.value } });
    S.csrf = r.csrf;
    await start();
  });
  pw.addEventListener('keydown', e => { if (e.key === 'Enter') go(); });
  $app.replaceChildren(h('div', { class: 'card login' },
    h('h1', {}, 'Správa kvízu'),
    h('label', { class: 'f' }, 'Heslo'), pw,
    h('p', {}, h('button', { class: 'primary', onclick: go }, 'Přihlásit'))));
  pw.focus();
}

/* ---------- rozvržení a směrování ---------- */
function layout(active, content) {
  const nav = (href, label, key) => h('a', { href, class: active === key ? 'active' : '' }, label);
  $app.replaceChildren(
    h('header', { class: 'top' },
      h('span', { class: 'brand' }, S.values.app_name || 'Kvíz'),
      nav('#/quizzes', 'Kvízy', 'quizzes'),
      nav('#/generate', '✨ Generovat', 'generate'),
      nav('#/games', 'Hry', 'games'),
      nav('#/settings', 'Nastavení', 'settings'),
      h('span', { class: 'spacer' }),
      h('a', { href: '#', onclick: async e => { e.preventDefault(); await act(async () => { await api('admin_logout.php', { body: {} }); S.csrf = ''; showLogin(); }); } }, 'Odhlásit')),
    h('main', {}, content));
  document.documentElement.style.setProperty('--primary', S.values.color_primary || '#4f46e5');
  document.documentElement.style.setProperty('--accent', S.values.color_accent || '#f59e0b');
}

function route() {
  if (!S.csrf) return;
  if (dirty && !confirm('Máte neuložené změny. Opravdu odejít?')) { return; }
  dirty = false;
  const hash = location.hash || '#/quizzes';
  const m = hash.match(/^#\/quiz\/(\d+|new)$/);
  const st = hash.match(/^#\/start\/(\d+)$/);
  act(async () => {
    if (m) await viewEditor(m[1] === 'new' ? 0 : parseInt(m[1], 10));
    else if (st) await viewStart(parseInt(st[1], 10));
    else if (hash === '#/games') await viewGames();
    else if (hash === '#/generate') await viewGenerate();
    else if (hash === '#/settings') await viewSettings('general');
    else await viewQuizzes();
  });
}

async function loadSettings() {
  const r = await api('admin_settings.php');
  S.tabs = r.tabs; S.values = r.values; S.categories = r.categories; S.subs = r.subs || {};
  S.defs = Object.fromEntries(r.defs.map(d => [d.key, d]));
}

/* ---------- seznam kvízů ---------- */
async function viewQuizzes() {
  const { quizzes } = await api('admin_quizzes.php');
  const fileIn = h('input', { type: 'file', accept: '.json,application/json', style: 'display:none', onchange: async e => {
    const f = e.target.files[0];
    if (!f) return;
    await act(async () => {
      let data;
      try { data = JSON.parse(await f.text()); } catch (x) { throw new Error('Soubor není platný JSON.'); }
      const r = await api('admin_quizzes.php', { body: { action: 'import', data } });
      toast('Kvíz naimportován.');
      location.hash = '#/quiz/' + r.id;
    });
  } });
  const rows = quizzes.map(q => h('tr', {},
    h('td', {}, h('a', { href: '#/quiz/' + q.id }, h('strong', {}, q.title)),
      h('div', { class: 'muted' }, [q.category, q.description].filter(Boolean).join(' – '))),
    h('td', {}, q.questions_count + ' otáz.'),
    h('td', {}, h('div', { class: 'actions' },
      h('a', { class: 'btn small primary', href: '#/start/' + q.id }, '▶ Spustit hru'),
      h('a', { class: 'btn small', href: '#/quiz/' + q.id }, 'Upravit'),
      h('button', { class: 'small', onclick: () => act(async () => { await api('admin_quizzes.php', { body: { action: 'duplicate', id: q.id } }); toast('Zduplikováno.'); route(); }) }, 'Duplikovat'),
      h('a', { class: 'btn small', href: '../api/admin_export.php?id=' + q.id }, 'Export'),
      h('button', { class: 'small danger', onclick: () => confirm('Smazat kvíz „' + q.title + '“?') && act(async () => { await api('admin_quizzes.php', { body: { action: 'delete', id: q.id } }); toast('Smazáno.'); route(); }) }, 'Smazat')))));
  layout('quizzes', [
    h('div', { class: 'row between' }, h('h1', {}, 'Kvízy'),
      h('div', { class: 'row' },
        h('button', { onclick: () => fileIn.click() }, 'Import JSON'), fileIn,
        h('a', { class: 'btn primary', href: '#/quiz/new' }, '+ Nový kvíz'))),
    h('div', { class: 'card' }, quizzes.length
      ? h('table', {}, h('tbody', {}, rows))
      : h('p', { class: 'muted' }, 'Zatím tu nic není. Vytvořte první kvíz tlačítkem „Nový kvíz“.'))]);
}

/* ---------- editor kvízu ---------- */
function newQuestion(type) {
  const q = { type, text: '', image: null, time_limit: null, points: null, explanation: '', options: [] };
  setType(q, type);
  return q;
}
function setType(q, type) {
  q.type = type;
  if (type === 'truefalse') {
    q.options = [{ text: 'Pravda', correct: true }, { text: 'Nepravda', correct: false }];
  } else {
    if (!q.options.length || q.options.length < 2 || (q.options[0].text === 'Pravda' && q.options.length === 2)) {
      q.options = [0, 1, 2, 3].map(() => ({ text: '', correct: false }));
      if (type === 'choice') q.options[0].correct = true;
    }
    if (type === 'poll') q.options.forEach(o => (o.correct = false));
    if (type === 'choice' && !q.options.some(o => o.correct)) q.options[0].correct = true;
  }
}

let DRAFT = null;      // návrh otázek z Gemini předaný editoru: {title, category_id, questions}
let lastGen = null;    // poslední parametry generování (pro přegenerování jedné otázky)

async function viewEditor(id) {
  const draft = DRAFT; DRAFT = null;
  const Q = id ? await api('admin_quiz.php?id=' + id)
    : { id: null, title: '', description: '', category_id: null, settings: {}, questions: draft ? [] : [newQuestion('choice')] };
  if (Array.isArray(Q.settings)) Q.settings = {};
  if (draft) {
    if (!id) { Q.title = draft.title; Q.category_id = draft.category_id; }
    Q.questions.push(...draft.questions);
    dirty = true;   // návrh není uložen, dokud ho admin neuloží
  }
  const touch = () => { dirty = true; };
  const eff = () => ({ ...S.values, ...Q.settings });

  // --- nastavení kvízu (přepisy globálních výchozích hodnot)
  const quizDefs = Object.values(S.defs).filter(d => d.quiz);
  const setBox = h('div');
  const drawSettings = () => {
    setBox.replaceChildren(...quizDefs.map(d => {
      const over = d.key in Q.settings;
      const slot = h('div', { class: 'grow' });
      const drawSlot = () => {
        const isOver = d.key in Q.settings;
        slot.replaceChildren(isOver
          ? control(d, Q.settings[d.key], v => { Q.settings[d.key] = v; touch(); })
          : h('span', { class: 'muted' }, 'výchozí: ' + (d.type === 'bool' ? (S.values[d.key] ? 'ano' : 'ne')
            : d.type === 'select' ? d.options[S.values[d.key]] : fmt(d, S.values[d.key]))));
      };
      drawSlot();
      return h('div', { class: 'setting' },
        h('div', {}, h('strong', {}, d.label), h('div', { class: 'muted' }, (d.tab === 'tts' && d.group ? '🔊 Hlasatel – ' + d.group + ': ' : '') + d.help)),
        h('div', { class: 'row' },
          h('label', { class: 'row muted' }, h('input', { type: 'checkbox', checked: over, onchange: e => {
            if (e.target.checked) Q.settings[d.key] = S.values[d.key]; else delete Q.settings[d.key];
            touch(); drawSlot();
          } }), 'přepsat'), slot));
    }));
  };
  drawSettings();

  // --- otázky
  const qBox = h('div');
  const drawQs = () => qBox.replaceChildren(...Q.questions.map((q, i) => qCard(q, i)),
    h('div', { class: 'row' },
      h('button', { onclick: () => { Q.questions.push(newQuestion('choice')); touch(); drawQs(); } }, '+ Otázka'),
      h('button', { onclick: () => { Q.questions.push(newQuestion('truefalse')); touch(); drawQs(); } }, '+ Pravda/nepravda'),
      h('button', { onclick: () => { Q.questions.push(newQuestion('poll')); touch(); drawQs(); } }, '+ Anketa')));

  function optionalNumber(defKey, q, field) {
    const d = S.defs[defKey];
    const slot = h('span');
    const cb = h('input', { type: 'checkbox', checked: q[field] === null });
    const draw = () => slot.replaceChildren(q[field] === null
      ? h('span', { class: 'muted' }, 'výchozí: ' + fmt(d, eff()[defKey]))
      : control(d, q[field], v => { q[field] = v; touch(); }));
    cb.addEventListener('change', () => { q[field] = cb.checked ? null : eff()[defKey]; touch(); draw(); });
    draw();
    return h('div', { class: 'row' }, h('label', { class: 'row muted' }, cb, 'výchozí kvízu'), slot);
  }

  function qCard(q, i) {
    const move = d => () => { const j = i + d; if (j < 0 || j >= Q.questions.length) return; [Q.questions[i], Q.questions[j]] = [Q.questions[j], Q.questions[i]]; touch(); drawQs(); };
    const optsBox = h('div');
    const drawOpts = () => put(optsBox,
      ...q.options.map((o, k) => h('div', { class: 'opt' },
        h('span', { class: 'tile c' + k }, SHAPES[k]),
        q.type === 'truefalse'
          ? h('strong', { class: 'grow' }, o.text)
          : h('input', { type: 'text', class: 'grow', placeholder: 'Odpověď ' + (k + 1), maxlength: 200, value: o.text, oninput: e => { o.text = e.target.value; touch(); } }),
        q.type === 'poll' ? null : h('label', { class: 'ok' },
          h('input', { type: q.type === 'truefalse' ? 'radio' : 'checkbox', name: 'ok' + i, checked: o.correct, onchange: e => {
            if (q.type === 'truefalse') q.options.forEach((x, n) => (x.correct = n === k)); else o.correct = e.target.checked;
            touch();
          } }), 'správná'),
        q.type === 'truefalse' || q.options.length <= 2 ? null
          : h('button', { class: 'small danger', title: 'Odebrat odpověď', onclick: () => { q.options.splice(k, 1); touch(); drawOpts(); } }, '✕'))),
      q.type === 'truefalse' || q.options.length >= 6 ? null
        : h('button', { class: 'small', onclick: () => { q.options.push({ text: '', correct: false }); touch(); drawOpts(); } }, '+ Přidat odpověď'));
    drawOpts();

    return h('div', { class: 'card qcard' },
      h('div', { class: 'qhead' }, h('h3', {}, 'Otázka ' + (i + 1), q.source === 'gemini' ? h('span', { class: 'muted' }, ' · Gemini') : null),
        q.source === 'gemini' && lastGen ? h('button', { class: 'small', title: 'Nahradit novou otázkou od Gemini', onclick: () => act(async () => {
          const avoid = Q.questions.map(x => x.text).filter(Boolean);
          const r = await api('admin_gemini.php', { body: { ...lastGen, action: 'regenerate', type: q.type, avoid_texts: avoid } });
          const n = r.question;
          n.time_limit = q.time_limit; n.points = q.type === 'poll' ? null : q.points;
          Q.questions[i] = n; dirty = true; drawQs(); toast('Otázka přegenerována.');
        }) }, '↻ Přegenerovat') : null,
        h('button', { class: 'small', title: 'Nahoru', onclick: move(-1), disabled: i === 0 }, '↑'),
        h('button', { class: 'small', title: 'Dolů', onclick: move(1), disabled: i === Q.questions.length - 1 }, '↓'),
        h('button', { class: 'small', title: 'Duplikovat', onclick: () => { Q.questions.splice(i + 1, 0, JSON.parse(JSON.stringify(q))); touch(); drawQs(); } }, '⧉'),
        h('button', { class: 'small danger', title: 'Smazat', onclick: () => { if (confirm('Smazat otázku ' + (i + 1) + '?')) { Q.questions.splice(i, 1); touch(); drawQs(); } } }, '✕')),
      h('select', { onchange: e => { setType(q, e.target.value); touch(); drawQs(); } },
        Object.entries(TYPES).map(([k, l]) => h('option', { value: k, selected: k === q.type }, l))),
      h('label', { class: 'f' }, 'Text otázky'),
      h('textarea', { maxlength: 500, value: q.text, oninput: e => { q.text = e.target.value; touch(); } }),
      h('label', { class: 'f' }, 'Obrázek (volitelný)'),
      control({ type: 'image' }, q.image || '', v => { q.image = v || null; touch(); }),
      h('label', { class: 'f' }, 'Odpovědi'), optsBox,
      h('label', { class: 'f' }, 'Časový limit'), optionalNumber('default_time_limit', q, 'time_limit'),
      q.type === 'poll' ? null : [h('label', { class: 'f' }, 'Body'), optionalNumber('default_points', q, 'points')],
      h('label', { class: 'f' }, 'Vysvětlení po odhalení odpovědi (volitelné)'),
      h('input', { type: 'text', maxlength: 500, value: q.explanation || '', oninput: e => { q.explanation = e.target.value; touch(); } }));
  }
  drawQs();

  const title = h('input', { type: 'text', maxlength: 120, value: Q.title, oninput: e => { Q.title = e.target.value; touch(); } });
  const desc = h('input', { type: 'text', maxlength: 500, value: Q.description, oninput: e => { Q.description = e.target.value; touch(); } });
  const cat = h('select', { onchange: e => { Q.category_id = e.target.value ? parseInt(e.target.value, 10) : null; touch(); } },
    h('option', { value: '' }, '— bez kategorie —'),
    S.categories.map(c => h('option', { value: c.id, selected: c.id === Q.category_id }, c.name)));

  const save = () => act(async () => {
    const r = await api('admin_quiz.php', { body: Q });
    dirty = false;
    toast('Uloženo.');
    if (!Q.id) { location.hash = '#/quiz/' + r.id; } else { Q.id = r.id; }
  });

  layout('quizzes', [
    h('div', { class: 'row between' }, h('h1', {}, Q.id ? 'Úprava kvízu' : 'Nový kvíz'),
      h('a', { href: '#/quizzes' }, '← zpět na seznam')),
    h('div', { class: 'card' },
      h('label', { class: 'f' }, 'Název kvízu'), title,
      h('label', { class: 'f' }, 'Popis (volitelný)'), desc,
      h('label', { class: 'f' }, 'Kategorie'), cat),
    h('details', { class: 'card' }, h('summary', {}, 'Nastavení tohoto kvízu (přepisuje globální výchozí hodnoty)'),
      h('p', { class: 'muted' }, 'Zaškrtněte „přepsat“ u hodnoty, která se má pro tento kvízu lišit od globálního nastavení. Při spuštění hry jde ještě upravit.'),
      setBox),
    qBox,
    h('div', { class: 'savebar' }, h('button', { class: 'primary', onclick: save }, 'Uložit kvíz'),
      h('span', { class: 'muted' }, 'Změny se projeví až po uložení.')),
  ]);
}

/* ---------- generování otázek (Gemini) ---------- */
async function viewGenerate() {
  const [{ quizzes }, keySt] = await Promise.all([api('admin_quizzes.php'), api('admin_secrets.php')]);
  const v = S.values;
  const f = { category_id: '', subcategory: '', count: 10, answers: 4, difficulty: 3, time_limit: 20, points: 1000,
    audience: 'dospeli', language: 'čeština', types: ['choice'], avoid_existing: true,
    model: v.gemini_model, temperature: v.gemini_temperature, prompt: v.gemini_prompt };
  let target = 'new';
  const sl = (key, label, min, max, step, unit) => h('div', { class: 'setting' }, h('strong', {}, label),
    control({ type: 'int', min, max, step, unit }, f[key], x => { f[key] = x; }));

  // Podkategorie: nabídka podle vybrané kategorie + možnost „Vlastní…“ (volný text)
  const CUSTOM = '__custom__';
  const subWrap = h('div');
  const subInput = h('input', { type: 'text', maxlength: 200, placeholder: 'Vlastní upřesnění, např. české filmy 90. let, fotbal – MS',
    oninput: e => { f.subcategory = e.target.value; } });
  const drawSub = () => {
    const c = S.categories.find(x => x.id === f.category_id);
    const list = (c && S.subs && S.subs[c.name]) || [];
    f.subcategory = '';
    subInput.value = '';
    if (!list.length) {   // obecné téma nebo vlastní kategorie: jen volný text
      subInput.style.display = '';
      put(subWrap, subInput);
      return;
    }
    const sel = h('select', { onchange: () => {
      if (sel.value === CUSTOM) { subInput.style.display = ''; f.subcategory = subInput.value; subInput.focus(); }
      else { subInput.style.display = 'none'; f.subcategory = sel.value; }
    } },
      h('option', { value: '' }, '— bez upřesnění —'),
      list.map(x => h('option', { value: x }, x)),
      h('option', { value: CUSTOM }, '✏️ Vlastní…'));
    subInput.style.display = 'none';
    put(subWrap, sel, subInput);
  };
  const cat = h('select', { onchange: e => { f.category_id = e.target.value ? parseInt(e.target.value, 10) : ''; drawSub(); } },
    h('option', { value: '' }, '— obecné / různé —'), S.categories.map(c => h('option', { value: c.id }, c.name)));
  drawSub();
  const aud = h('select', { onchange: e => { f.audience = e.target.value; } },
    [['dospeli', 'Dospělí'], ['deti', 'Děti'], ['odbornici', 'Odborníci']].map(([k, l]) => h('option', { value: k }, l)));
  const lang = h('input', { type: 'text', maxlength: 30, value: f.language, oninput: e => { f.language = e.target.value; } });
  const typeBox = h('div', { class: 'row' }, [['choice', 'ABCD (výběr z možností)'], ['truefalse', 'Pravda / nepravda'], ['poll', 'Anketa (bez správné odpovědi)']]
    .map(([k, l]) => h('label', { class: 'row' }, h('input', { type: 'checkbox', checked: f.types.includes(k), onchange: e => {
      f.types = e.target.checked ? [...new Set([...f.types, k])] : f.types.filter(x => x !== k);
    } }), l)));
  const avoid = h('label', { class: 'row' }, h('input', { type: 'checkbox', checked: true, onchange: e => { f.avoid_existing = e.target.checked; } }),
    'Nevracet otázky, které už v databázi jsou (platí pro vybranou kategorii)');
  const tgt = h('select', { onchange: e => { target = e.target.value; } }, h('option', { value: 'new' }, 'Nový kvíz'),
    quizzes.map(q => h('option', { value: q.id }, 'Přidat do: ' + q.title)));
  const promptBox = h('textarea', { rows: 14, value: f.prompt, oninput: e => { f.prompt = e.target.value; } });
  const model = h('input', { type: 'text', maxlength: 80, value: f.model, oninput: e => { f.model = e.target.value; } });
  const status = h('div', { class: 'muted' });
  const go = h('button', { class: 'primary' }, '✨ Vygenerovat návrh');

  go.addEventListener('click', () => act(async () => {
    go.disabled = true; status.textContent = 'Generuji, může to trvat i minutu…';
    try {
      const r = await api('admin_gemini.php', { body: { ...f, action: 'generate' } });
      lastGen = { ...f };
      const cname = S.categories.find(c => c.id === f.category_id);
      DRAFT = { title: 'Gemini: ' + [cname ? cname.name : 'Různé', f.subcategory].filter(Boolean).join(' – ').slice(0, 100),
        category_id: f.category_id === '' ? null : f.category_id, questions: r.questions };
      if (r.warnings.length) toast(r.warnings.join(' '));
      dirty = false;
      location.hash = target === 'new' ? '#/quiz/new' : '#/quiz/' + target;   // hashchange spustí route()
    } finally { go.disabled = false; status.textContent = ''; }
  }));

  layout('generate', [
    h('h1', {}, '✨ Generování otázek (Gemini)'),
    !keySt.gemini_api_key.set ? h('div', { class: 'note' }, 'Není nastaven API klíč. Zadejte ho v ', h('a', { href: '#/settings' }, 'Nastavení → Gemini'), '.') : null,
    h('div', { class: 'card' },
      h('label', { class: 'f' }, 'Kategorie / oblast'), cat,
      h('label', { class: 'f' }, 'Podkategorie / upřesnění'), subWrap,
      h('label', { class: 'f' }, 'Cílová skupina'), aud,
      h('label', { class: 'f' }, 'Jazyk'), lang,
      h('label', { class: 'f' }, 'Typy otázek'), typeBox, avoid,
      sl('count', 'Počet otázek', 1, 50, 1, ''), sl('answers', 'Počet odpovědí', 2, 6, 1, ''),
      sl('difficulty', 'Obtížnost (1 snadné – 5 těžké)', 1, 5, 1, ''),
      sl('time_limit', 'Časový limit otázky', 5, 120, 5, ' s'), sl('points', 'Body za otázku', 0, 5000, 50, ' b.')),
    h('details', { class: 'card' }, h('summary', {}, 'Prompt pro Gemini (lze upravit před odesláním)'),
      h('p', { class: 'muted' }, 'Zástupné proměnné ({kategorie}, {upresneni}, {pocet}, {obtiznost}, {jazyk}, {pocet_odpovedi}, {cilova_skupina}, {typy_otazek}, {existujici_otazky}) se doplní z formuláře. Výchozí šablonu měníte v Nastavení → Gemini.'),
      promptBox,
      h('div', { class: 'row', style: 'margin-top:.5rem' },
        h('button', { onclick: () => { promptBox.value = f.prompt = S.values.gemini_prompt; } }, 'Vrátit šablonu z Nastavení'),
        h('button', { onclick: () => act(async () => {
          const r = await api('admin_gemini.php', { body: { ...f, action: 'preview' } });
          alert(r.prompt);
        }) }, 'Zobrazit hotový prompt'))),
    h('details', { class: 'card' }, h('summary', {}, 'Pokročilé: model a kreativita'),
      h('label', { class: 'f' }, 'Model Gemini'), model,
      h('div', { class: 'setting' }, h('strong', {}, 'Teplota (kreativita)'),
        control(S.defs.gemini_temperature, f.temperature, x => { f.temperature = x; }))),
    h('div', { class: 'card' }, h('label', { class: 'f' }, 'Kam návrh uložit'), tgt,
      h('p', { class: 'muted' }, 'Otázky se nejdřív zobrazí v editoru jako návrh. Můžete je upravit, smazat, přegenerovat a teprve pak uložit.')),
    h('div', { class: 'savebar' }, go, status)]);
}

/* ---------- spuštění hry a přehled her ---------- */
const hostUrl = g => '../host/?game=' + g.pin + '&key=' + g.host_token;

async function viewStart(id) {
  const Q = await api('admin_quiz.php?id=' + id);
  if (!Q.questions.length) { toast('Kvíz nemá žádné otázky.', true); location.hash = '#/quizzes'; return; }
  const base = { ...S.values, ...(Array.isArray(Q.settings) ? {} : Q.settings) };
  const over = {};
  let mode = 'screen';
  const rows = Object.values(S.defs).filter(d => d.quiz).map(d => h('div', { class: 'setting' },
    h('div', {}, h('strong', {}, d.label), h('div', { class: 'muted' }, (d.tab === 'tts' && d.group ? '🔊 Hlasatel – ' + d.group + ': ' : '') + d.help)),
    control(d, base[d.key], v => { over[d.key] = v; })));
  const modeSel = h('select', { onchange: e => { mode = e.target.value; } },
    h('option', { value: 'screen' }, 'Velká obrazovka (projektor / TV)'),
    h('option', { value: 'voice' }, 'Bez obrazovky – hlasový režim (ovládání z mobilu, vše se předčítá)'));
  layout('quizzes', [
    h('div', { class: 'row between' }, h('h1', {}, 'Spustit hru: ' + Q.title), h('a', { href: '#/quizzes' }, '← zpět')),
    h('div', { class: 'card' }, h('label', { class: 'f' }, 'Režim zobrazení'), modeSel),
    h('details', { class: 'card' }, h('summary', {}, 'Nastavení této hry (volitelné)'),
      h('p', { class: 'muted' }, 'Předvyplněno z globálního nastavení a nastavení kvízu. Změny platí jen pro tuto hru.'), rows),
    h('div', { class: 'savebar' }, h('button', { class: 'primary', onclick: () => act(async () => {
      const g = await api('admin_games.php', { body: { action: 'create', quiz_id: id, mode, settings: over } });
      location.href = hostUrl(g);
    }) }, '▶ Vytvořit hru a otevřít obrazovku moderátora'))]);
}

async function viewGames() {
  const { games } = await api('admin_games.php');
  const when = t => new Date(t * 1000).toLocaleString('cs-CZ');
  const PH = { lobby: 'čeká na hráče', question: 'běží otázka', reveal: 'běží', leaderboard: 'běží', finished: 'skončila' };
  layout('games', [
    h('h1', {}, 'Hry'),
    h('div', { class: 'card' }, games.length ? h('table', {}, h('tbody', {}, games.map(g => h('tr', {},
      h('td', {}, h('strong', {}, 'PIN ' + g.pin), h('div', { class: 'muted' }, g.title + ' · ' + when(g.created_at))),
      h('td', {}, PH[g.phase] || g.phase, h('div', { class: 'muted' }, g.players_count + ' hráčů')),
      h('td', {}, h('div', { class: 'actions' },
        h('a', { class: 'btn small', href: hostUrl(g) }, 'Otevřít'),
        h('a', { class: 'btn small', href: '../api/host_export.php?pin=' + g.pin + '&key=' + g.host_token }, 'CSV'),
        h('button', { class: 'small danger', onclick: () => confirm('Smazat hru PIN ' + g.pin + ' včetně výsledků?') && act(async () => {
          await api('admin_games.php', { body: { action: 'delete', id: g.id } }); toast('Smazáno.'); route();
        }) }, 'Smazat')))))))
      : h('p', { class: 'muted' }, 'Zatím žádné hry. Spusťte ji v seznamu kvízů tlačítkem „Spustit hru“.')),
  ]);
}

/* ---------- nastavení ---------- */
const edits = {};
async function viewSettings(tab) {
  await loadSettings();
  const t = S.tabs.find(x => x.key === tab) || S.tabs[0];
  let prevGroup = null;
  const rows = Object.values(S.defs).filter(d => d.tab === t.key).flatMap(d => {
    const cur = d.key in edits ? edits[d.key] : S.values[d.key];
    const head = d.group && d.group !== prevGroup ? h('h3', { class: 'group' }, d.group) : null;
    prevGroup = d.group || prevGroup;
    return [head, h('div', { class: 'setting' },
      h('strong', {}, d.label),
      control(d, cur, v => { edits[d.key] = v; dirty = true; }),
      d.help ? h('div', { class: 'help muted' }, d.help) : null)];
  });
  const extra = [];
  if (t.key === 'gemini') extra.push(await geminiKeyCard(), categoriesCard());
  if (t.key === 'tts') extra.push(voiceTestCard());
  if (t.key === 'maintenance') extra.push(maintenanceCard());

  layout('settings', [
    h('h1', {}, 'Nastavení'),
    h('div', { class: 'tabs' }, S.tabs.map(x => h('button', { class: x.key === t.key ? 'active' : '', onclick: () => {
      dirty = false; viewSettings(x.key);
    } }, x.title))),
    h('div', { class: 'card' }, t.note ? h('div', { class: 'note' }, t.note) : null, rows),
    ...extra,
    h('div', { class: 'savebar' },
      h('button', { class: 'primary', onclick: () => act(async () => {
        await api('admin_settings.php', { body: { values: edits } });
        for (const k of Object.keys(edits)) delete edits[k];
        dirty = false; toast('Nastavení uloženo.'); await viewSettings(t.key);
      }) }, 'Uložit nastavení'),
      h('button', { class: 'danger', onclick: () => confirm('Obnovit výchozí hodnoty záložky „' + t.title + '“?') && act(async () => {
        await api('admin_settings.php', { body: { reset: t.key } });
        Object.values(S.defs).filter(d => d.tab === t.key).forEach(d => delete edits[d.key]);
        dirty = false; toast('Výchozí hodnoty obnoveny.'); await viewSettings(t.key);
      }) }, 'Obnovit výchozí')),
  ]);
}

/** Výběr a vyzkoušení hlasu v tomto prohlížeči (hlasy závisí na zařízení; moderátorův telefon může mít jiné). */
function voiceTestCard() {
  const box = h('div', { class: 'card' });
  const draw = () => {
    const sup = 'speechSynthesis' in window;
    const voices = sup ? speechSynthesis.getVoices() : [];
    const sel = h('select', {}, h('option', { value: '' }, '— automaticky (první český hlas) —'),
      voices.slice().sort((a, b) => (/^cs/i.test(b.lang) - /^cs/i.test(a.lang)) || a.name.localeCompare(b.name))
        .map(v => h('option', { value: v.name }, v.name + ' (' + v.lang + ')' + (/^cs/i.test(v.lang) ? ' ★' : ''))));
    const cur = document.getElementById('set_tts_voice');
    if (cur) sel.value = cur.value;
    sel.addEventListener('change', () => {
      const inp = document.getElementById('set_tts_voice');
      if (inp) inp.value = sel.value;
      edits.tts_voice = sel.value; dirty = true;
    });
    put(box, h('h3', {}, 'Vyzkoušet hlas'),
      sup ? null : h('p', { class: 'muted' }, 'Tento prohlížeč předčítání nepodporuje.'),
      h('p', { class: 'muted' }, 'Seznam ukazuje hlasy tohoto zařízení (★ = český). Hlas se vybírá podle názvu, takže na telefonu moderátora, který má jiné hlasy, se použije první český.'),
      sup ? sel : null,
      sup && !voices.some(v => /^cs/i.test(v.lang)) ? h('p', { class: 'muted' }, 'Tohle zařízení nemá český hlas. V hlasovém režimu pomůže záložní Gemini TTS (viz níže).') : null,
      sup ? h('div', { class: 'row', style: 'margin-top:.6rem' }, h('button', { onclick: () => {
        speechSynthesis.cancel();
        const u = new SpeechSynthesisUtterance('Otázka číslo jedna. Jaké je hlavní město České republiky?');
        u.lang = 'cs-CZ';
        const v = voices.find(x => x.name === sel.value) || voices.find(x => /^cs/i.test(x.lang));
        if (v) u.voice = v;
        u.rate = edits.tts_rate || S.values.tts_rate || 1;
        speechSynthesis.speak(u);
      } }, '▶ Přehrát ukázku')) : null);
  };
  draw();
  if ('speechSynthesis' in window) speechSynthesis.addEventListener('voiceschanged', draw);
  return box;
}

async function geminiKeyCard() {
  const box = h('div', { class: 'card' });
  const draw = async () => {
    const st = (await api('admin_secrets.php')).gemini_api_key;
    const keyIn = h('input', { type: 'password', autocomplete: 'off', placeholder: st.set ? 'Zadejte nový klíč pro nahrazení' : 'Vložte Gemini API klíč', maxlength: 200 });
    const msg = h('div', { class: 'muted' });
    const stText = !st.set ? 'Klíč není nastaven.'
      : st.source === 'config' ? 'Používá se klíč z config.php.'
      : 'Klíč je uložen šifrovaně na serveru (končí na …' + st.hint + ').';
    put(box, h('h3', {}, 'Gemini API klíč'),
      h('p', { class: st.set ? '' : 'muted' }, (st.set ? '✔ ' : '✘ ') + stText),
      h('p', { class: 'muted' }, 'Klíč se ukládá zašifrovaně mimo databázi a do prohlížeče se nikdy nevrací. Získáte ho na aistudio.google.com.'),
      keyIn,
      h('div', { class: 'row', style: 'margin-top:.6rem' },
        h('button', { class: 'primary', onclick: () => act(async () => {
          await api('admin_secrets.php', { body: { name: 'gemini_api_key', value: keyIn.value } });
          toast('Klíč uložen.'); await draw();
        }) }, 'Uložit klíč'),
        h('button', { onclick: () => act(async () => {
          msg.textContent = 'Zkouším spojení…';
          try { const r = await api('admin_gemini.php', { body: { action: 'test' } }); msg.textContent = '✔ Funguje (model ' + r.model + ').'; }
          catch (e) { msg.textContent = '✘ ' + e.message; }
        }) }, 'Otestovat klíč'),
        st.source === 'app' ? h('button', { class: 'danger', onclick: () => confirm('Smazat uložený klíč?') && act(async () => {
          await api('admin_secrets.php', { body: { name: 'gemini_api_key', action: 'delete' } }); toast('Klíč smazán.'); await draw();
        }) }, 'Smazat klíč') : null),
      msg);
  };
  await draw();
  return box;
}

function categoriesCard() {
  const box = h('div', { class: 'card' });
  const draw = () => {
    const nameIn = h('input', { type: 'text', maxlength: 60, placeholder: 'Nová kategorie' });
    const call = body => act(async () => { const r = await api('admin_categories.php', { body }); S.categories = r.categories; draw(); });
    box.replaceChildren(h('h3', {}, 'Kategorie pro generování otázek'),
      h('p', { class: 'muted' }, 'Seznam, ze kterého se vybírá oblast při generování přes Gemini a kategorie kvízu.'),
      ...S.categories.map(c => {
        const inp = h('input', { type: 'text', maxlength: 60, value: c.name });
        return h('div', { class: 'row', style: 'margin:.3rem 0' }, h('div', { class: 'grow' }, inp),
          h('button', { class: 'small', onclick: () => call({ action: 'rename', id: c.id, name: inp.value }) }, 'Přejmenovat'),
          h('button', { class: 'small danger', onclick: () => confirm('Smazat kategorii „' + c.name + '“?') && call({ action: 'delete', id: c.id }) }, 'Smazat'));
      }),
      h('div', { class: 'row', style: 'margin-top:.6rem' }, h('div', { class: 'grow' }, nameIn),
        h('button', { onclick: () => call({ action: 'add', name: nameIn.value }) }, 'Přidat')),
      h('div', { class: 'row', style: 'margin-top:.8rem' },
        h('button', { onclick: () => act(async () => {
          const r = await api('admin_categories.php', { body: { action: 'seed' } });
          S.categories = r.categories; toast('Doplněno kategorií: ' + r.added); draw();
        }) }, '✨ Doplnit doporučené kategorie'),
        h('span', { class: 'muted' }, 'Přidá chybějící kategorie z výchozí sady (existující nemění).')));
  };
  draw();
  return box;
}

function maintenanceCard() {
  const days = h('input', { type: 'number', min: 1, max: 3650, value: 30, style: 'width:6rem' });
  return h('div', { class: 'card' }, h('h3', {}, 'Údržba databáze'),
    h('div', { class: 'row' }, 'Smazat hry starší než', days, 'dní',
      h('button', { class: 'danger', onclick: () => confirm('Smazat hry starší než ' + days.value + ' dní včetně výsledků?') && act(async () => {
        const r = await api('admin_maintenance.php', { body: { action: 'delete_old_games', days: parseInt(days.value, 10) } });
        toast('Smazáno her: ' + r.deleted);
      }) }, 'Smazat staré hry')),
    h('p', {}, h('a', { class: 'btn', href: '../api/admin_maintenance.php?backup=1' }, 'Stáhnout zálohu databáze')),
    h('h3', {}, 'Obnova ze zálohy'),
    h('p', { class: 'muted' }, 'Nahradí celou databázi (kvízy, hry, nastavení) nahraným souborem .sqlite. Dosavadní databáze se předtím uloží do složky data/ jako kviz-pred-obnovou-….sqlite. Gemini API klíč se neobnovuje, ten je uložený zvlášť.'),
    h('div', { class: 'row' }, h('input', { type: 'file', id: 'restore_file', accept: '.sqlite,.db,application/octet-stream' }),
      h('button', { class: 'danger', onclick: () => {
        const f = document.getElementById('restore_file').files[0];
        if (!f) { toast('Vyberte soubor se zálohou.', true); return; }
        if (!confirm('Opravdu nahradit celou databázi souborem „' + f.name + '“? Rozehrané hry se přeruší.')) return;
        act(async () => {
          const fd = new FormData(); fd.append('backup', f);
          const r = await api('admin_maintenance.php', { form: fd });
          toast('Obnoveno. Původní databáze uložena jako ' + r.safety_copy + '.');
          setTimeout(() => location.reload(), 1500);
        });
      } }, 'Obnovit ze zálohy')));
}

/* ---------- start ---------- */
async function start() {
  await loadSettings();
  route();
}

window.addEventListener('hashchange', route);
window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

(async () => {
  try {
    const r = await fetch('../api/admin_session.php', { credentials: 'same-origin' }).then(x => x.json());
    if (r.authenticated) { S.csrf = r.csrf; await start(); } else showLogin();
  } catch (e) {
    $app.replaceChildren(h('div', { class: 'card login' }, 'Server neodpovídá. Zkontrolujte PHP-FPM a config.php.'));
  }
})();
