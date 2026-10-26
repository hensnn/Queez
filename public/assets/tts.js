'use strict';
/**
 * Předčítání na zařízení moderátora.
 *  1) Web Speech API (speechSynthesis) – hlas cs-CZ, výběr hlasu a rychlosti z nastavení,
 *  2) záloha: Gemini TTS na serveru (api/tts_gemini.php), když zařízení nemá český hlas a je to povoleno.
 * Věty se řadí do fronty; cancel() zahodí rozmluvené i čekající věty.
 * Prohlížeče vyžadují před prvním přehráním klepnutí uživatele – když to selže, nastaví se blocked
 * a stránka ukáže tlačítko „Povolit hlas“.
 */
const Speech = {
  cfg: null,          // nastavení ze serveru (state.tts)
  on: false,          // zda se má mluvit (nastavení + přepínač moderátora)
  blocked: false,
  onBlocked: null,
  onError: null,      // zavolá se při chybě / obnovení serverového TTS
  lastError: null,    // poslední hláška z Gemini TTS (zobrazí se moderátorovi)
  token: 0,
  chain: Promise.resolve(),
  audio: null,
  geminiReq: null,    // (text) => Promise<Response>; nastaví host.js
  blobCache: new Map(),

  setConfig(cfg) {
    this.cfg = cfg;
    let stored = null;
    try { stored = localStorage.getItem('kviz_tts'); } catch (e) { /* nic */ }
    this.on = stored === null ? !!cfg.enabled : stored === 'on';
  },
  setOn(on) {
    this.on = on;
    try { localStorage.setItem('kviz_tts', on ? 'on' : 'off'); } catch (e) { /* nic */ }
    if (!on) this.cancel();
  },

  supported() { return 'speechSynthesis' in window && 'SpeechSynthesisUtterance' in window; },
  voices() { try { return this.supported() ? window.speechSynthesis.getVoices() : []; } catch (e) { return []; } },
  czechVoice() {
    const v = this.voices();
    if (this.cfg && this.cfg.voice) {
      const f = v.find(x => x.name === this.cfg.voice);
      if (f) return f;
    }
    return v.find(x => /^cs([-_]|$)/i.test(x.lang)) || null;
  },

  cancel() {
    this.token++;
    try { if (this.supported()) window.speechSynthesis.cancel(); } catch (e) { /* nic */ }
    if (this.audio) { try { this.audio.pause(); } catch (e) { /* nic */ } this.audio = null; }
  },

  /** Zařadí větu do fronty. Vrací {spoken, cancelled} po dokončení. */
  say(text) {
    if (!this.on || !this.cfg || !text) return Promise.resolve({ spoken: false, cancelled: false });
    const tok = this.token;
    const p = this.chain.then(() => (tok !== this.token ? { spoken: false, cancelled: true } : this._speak(text, tok)));
    this.chain = p.catch(() => {});
    return p;
  },

  async _speak(text, tok) {
    const cz = this.czechVoice();
    if (cz || (this.supported() && !(this.cfg.gemini && this.geminiReq))) {
      return this._browser(text, cz, tok);
    }
    if (this.cfg.gemini && this.geminiReq) return this._gemini(text, tok);
    return { spoken: false, cancelled: false };
  },

  _chunks(text) {   // dlouhé věty se v některých prohlížečích utnou → dělíme po ~180 znacích
    const parts = text.split(/(?<=[.!?:])\s+/);
    const out = [];
    let cur = '';
    for (const p of parts) {
      if ((cur + ' ' + p).trim().length > 180 && cur) { out.push(cur); cur = p; } else { cur = (cur + ' ' + p).trim(); }
    }
    if (cur) out.push(cur);
    return out;
  },

  async _browser(text, voice, tok) {
    for (const chunk of this._chunks(text)) {
      if (tok !== this.token) return { spoken: false, cancelled: true };
      const r = await this._utter(chunk, voice);
      if (r === 'blocked') return { spoken: false, cancelled: false, blocked: true };
    }
    return { spoken: true, cancelled: tok !== this.token };
  },

  _utter(text, voice) {
    return new Promise(resolve => {
      const u = new SpeechSynthesisUtterance(text);
      u.lang = 'cs-CZ';
      if (voice) u.voice = voice;
      u.rate = this.cfg.rate || 1;
      let done = false;
      const fin = r => { if (done) return; done = true; clearTimeout(watchdog); resolve(r); };
      u.onend = () => fin('ok');
      u.onerror = e => {
        if (e && e.error === 'not-allowed') { this._setBlocked(); fin('blocked'); } else fin('err');
      };
      const watchdog = setTimeout(() => fin('timeout'), Math.max(4000, (text.length * 130) / (u.rate || 1) + 2000));
      try { window.speechSynthesis.speak(u); } catch (e) { fin('err'); }
    });
  },

  async _gemini(text, tok) {
    try {
      let url = this.blobCache.get(text);
      if (!url) {
        const r = await this.geminiReq(text);
        if (!r.ok) {
          let m = '';
          try { m = (await r.json()).message || ''; } catch (e) { /* bez těla */ }
          this.lastError = m || 'HTTP ' + r.status;
          if (this.onError) this.onError();
          return { spoken: false, cancelled: false };
        }
        url = URL.createObjectURL(await r.blob());
        this.blobCache.set(text, url);
        if (this.lastError) { this.lastError = null; if (this.onError) this.onError(); }
      }
      if (tok !== this.token) return { spoken: false, cancelled: true };
      const a = new Audio(url);
      a.playbackRate = this.cfg.rate || 1;
      this.audio = a;
      await a.play();
      await new Promise(res => { a.onended = res; a.onerror = res; a.onpause = res; });
      return { spoken: true, cancelled: tok !== this.token };
    } catch (e) {
      if (e && e.name === 'NotAllowedError') this._setBlocked();
      return { spoken: false, cancelled: false, blocked: !!(e && e.name === 'NotAllowedError') };
    }
  },

  _setBlocked() {
    if (!this.blocked) { this.blocked = true; if (this.onBlocked) this.onBlocked(); }
  },
};

/* ---------- české věty (skloňování a skládání textů) ---------- */
const CZ = {
  letters: ['A', 'B', 'C', 'D', 'E', 'F'],
  points(n) { return n + (n === 1 ? ' bod' : n >= 2 && n <= 4 ? ' body' : ' bodů'); },
  join(names) { return names.length > 1 ? names.slice(0, -1).join(', ') + ' a ' + names[names.length - 1] : (names[0] || ''); },
  ordinal: ['první', 'druhé', 'třetí'],
};
