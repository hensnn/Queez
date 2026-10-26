'use strict';
/* Společné části hráčského klienta a obrazovky moderátora. */

const SHAPES = ['▲', '◆', '●', '■', '★', '⬣'];

function h(tag, props, ...kids) {
  const e = document.createElement(tag);
  for (const [k, v] of Object.entries(props || {})) {
    if (v === null || v === undefined || v === false) continue;
    if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
    else if (k === 'class') e.className = v;
    else if (['value', 'checked', 'disabled'].includes(k)) e[k] = v;
    else e.setAttribute(k, v === true ? '' : v);
  }
  for (const kid of kids.flat(Infinity)) {
    if (kid === null || kid === undefined || kid === false) continue;
    e.append(kid instanceof Node ? kid : document.createTextNode(String(kid)));
  }
  return e;
}

/* ---------- vzhled: avatar s iniciálami, konfety, kruh odpočtu ---------- */
const AV_COLORS = ['#ff4d6d', '#3d8bff', '#e89a00', '#23c26b', '#9b5de5', '#14b8b8', '#ff7a2f', '#e0409a'];

/** Barevný kruh s iniciálami jména (barva se odvodí z jména, takže je pro hráče stálá). */
function avatar(name, size) {
  const n = String(name || '?').trim() || '?';
  let hash = 0;
  for (const ch of n) hash = (hash * 31 + ch.codePointAt(0)) >>> 0;
  const words = n.split(/\s+/).filter(Boolean);
  const chars = [...n];
  const ini = (words.length > 1 ? [...words[0]][0] + [...words[1]][0] : chars.slice(0, 2).join('')).toUpperCase();
  const e = document.createElement('span');
  e.className = 'av' + (size ? ' ' + size : '');
  e.style.background = AV_COLORS[hash % AV_COLORS.length];
  e.textContent = ini;
  return e;
}

/** Konfety přes celou obrazovku (na konci hry). */
function confetti(count) {
  const wrap = document.createElement('div');
  wrap.className = 'confetti';
  const cols = ['#ff4d6d', '#ffc233', '#3d8bff', '#23c26b', '#9b5de5', '#14c8c8', '#ff7a2f'];
  for (let i = 0; i < (count || 40); i++) {
    const p = document.createElement('i');
    p.style.setProperty('--x', Math.random() * 100 + 'vw');
    p.style.setProperty('--c', cols[i % cols.length]);
    p.style.setProperty('--d', 3 + Math.random() * 4 + 's');
    p.style.setProperty('--w', -Math.random() * 6 + 's');
    wrap.append(p);
  }
  return wrap;
}

/** Nastaví výplň kruhu odpočtu (0–100 %). */
function setRing(el, remainingMs, totalMs) {
  const pct = totalMs > 0 && remainingMs !== null ? Math.max(0, Math.min(100, (remainingMs / totalMs) * 100)) : 100;
  el.style.setProperty('--p', pct.toFixed(1));
}

/** Nahradí obsah prvku; prázdné hodnoty (null/false) přeskočí (replaceChildren by z nich udělalo text „null“). */
function put(el, ...kids) {
  el.replaceChildren(...kids.flat(Infinity).filter(k => k !== null && k !== undefined && k !== false));
  return el;
}

/* Posun hodin klienta vůči serveru. Z posledních vzorků se bere ten s nejkratší odezvou. */
const Clock = {
  samples: [],
  offset: 0,
  sample(t0, t1, serverTime) {
    const rtt = t1 - t0;
    this.samples.push({ rtt, off: serverTime - (t0 + t1) / 2 });
    if (this.samples.length > 8) this.samples.shift();
    this.offset = this.samples.reduce((a, b) => (b.rtt < a.rtt ? b : a)).off;
  },
  now() { return Date.now() + this.offset; },
};

/**
 * Přenos stavu ze serveru. Dnes krátký polling; rozhraní (start/stop/poke + onState) je navržené tak,
 * aby šlo později vyměnit za SseTransport (EventSource) bez zásahu do zbytku frontendu.
 */
class PollTransport {
  constructor({ url, onState, onStatus, onFatal }) {
    this.url = url; this.onState = onState; this.onStatus = onStatus || (() => {}); this.onFatal = onFatal || (() => {});
    this.version = -1; this.interval = 1000; this.running = false; this.wake = null;
  }
  start() { if (this.running) return; this.running = true; this.loop(); }
  stop() { this.running = false; if (this.wake) this.wake(); }
  poke() { if (this.wake) this.wake(); }
  sleep(ms) { return new Promise(res => { const t = setTimeout(res, ms); this.wake = () => { clearTimeout(t); res(); }; }); }
  async loop() {
    let fails = 0;
    while (this.running) {
      const t0 = Date.now();
      let delay = this.interval;
      try {
        const r = await fetch(this.url + '&since=' + this.version, { cache: 'no-store', credentials: 'same-origin' });
        const t1 = Date.now();
        const d = await r.json().catch(() => null);
        if ([401, 403, 404].includes(r.status)) { this.running = false; this.onFatal(r.status, d); return; }
        if (!r.ok || !d) throw new Error('http ' + r.status);
        Clock.sample(t0, t1, d.server_time);
        fails = 0;
        this.onStatus(true);
        if (d.changed) {
          this.version = d.version;
          if (d.poll_interval_ms) this.interval = Math.max(500, d.poll_interval_ms);
          this.onState(d);
        }
        delay = this.interval;
      } catch (e) {
        fails++;
        this.onStatus(false);
        delay = Math.min(4000, 1000 + fails * 500);
      }
      if (this.running) await this.sleep(delay);
    }
  }
}

async function postJson(url, body) {
  try {
    const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body), credentials: 'same-origin' });
    const d = await r.json().catch(() => ({}));
    return { ok: r.ok, status: r.status, data: d, message: d.message || (r.ok ? '' : 'Chyba ' + r.status) };
  } catch (e) {
    return { ok: false, status: 0, data: {}, message: 'Server neodpovídá.' };
  }
}

/** Zbývající čas otázky v ms (null = bez limitu). */
function remainingMs(q, paused) {
  if (paused && q.paused_remaining !== null && q.paused_remaining !== undefined) return q.paused_remaining;
  if (q.ends_at === null || q.ends_at === undefined) return null;
  return Math.max(0, q.ends_at - Clock.now());
}

/** Každých 200 ms zavolá fn; vrací funkci pro zrušení. */
function every(ms, fn) { fn(); const id = setInterval(fn, ms); return () => clearInterval(id); }

async function loadConfig(prefix) {
  try {
    const c = await fetch(prefix + 'api/config_public.php', { cache: 'no-store' }).then(r => r.json());
    document.documentElement.style.setProperty('--primary', c.color_primary);
    document.documentElement.style.setProperty('--accent', c.color_accent);
    if (c.app_name) document.title = c.app_name;
    return c;
  } catch (e) { return { app_name: 'Kvíz', base_url: '' }; }
}
