'use strict';
/**
 * Zvukový signál před koncem času (pípání) – bez řeči, tóny se generují přes Web Audio API.
 * Používá obrazovka moderátora (host.js) a ukázka v administraci.
 *
 * Konfigurace (cfg): {seconds, style: 'lengthening'|'rising'|'steady', final: bool, volume: 0.1–1}
 *  - jedno pípnutí za vteřinu od okamžiku „seconds“ před koncem;
 *  - lengthening: tón je pokaždé delší (100 → 600 ms), rising: stoupá výška (600 → 1500 Hz), steady: stejné krátké pípnutí;
 *  - final: po vypršení času jeden dlouhý hluboký tón.
 * Prohlížeč povolí zvuk až po klepnutí uživatele – unlock() se volá při každé interakci.
 */
const Beeper = {
  ctx: null,
  timers: [],
  muted: false,

  /** Vytvoří / probudí AudioContext (volat z obsluhy klepnutí). Vrací true, když zvuk smí hrát. */
  unlock() {
    try {
      if (!this.ctx) {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) return false;
        this.ctx = new AC();
      }
      if (this.ctx.state === 'suspended') this.ctx.resume();
    } catch (e) { return false; }
    return !!this.ctx && this.ctx.state !== 'suspended';
  },
  supported() { return !!(window.AudioContext || window.webkitAudioContext); },

  /** Jeden tón s plynulým náběhem a doznělím (bez lupnutí). */
  tone(freq, durMs, volume) {
    if (!this.ctx || this.muted) return;
    const ctx = this.ctx;
    const t0 = ctx.currentTime, d = durMs / 1000, v = Math.max(0.001, Math.min(1, volume) * 0.5);
    const osc = ctx.createOscillator(), gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.value = freq;
    gain.gain.setValueAtTime(0.0001, t0);
    gain.gain.exponentialRampToValueAtTime(v, t0 + 0.012);
    gain.gain.setValueAtTime(v, t0 + Math.max(0.02, d - 0.04));
    gain.gain.exponentialRampToValueAtTime(0.0001, t0 + d);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start(t0);
    osc.stop(t0 + d + 0.03);
  },

  /** Parametry i-tého pípnutí z n (i = 0 je první, nejméně naléhavé). */
  spec(style, i, n) {
    const x = n > 1 ? i / (n - 1) : 1;
    if (style === 'rising') return { freq: 600 + 900 * x, dur: 150 };
    if (style === 'steady') return { freq: 880, dur: 150 };
    return { freq: 880, dur: 100 + 500 * x };   // lengthening
  },
  finalSpec() { return { freq: 440, dur: 1000 }; },

  /** Zahraje pípnutí číslo i z n podle konfigurace. */
  beep(cfg, i, n) {
    const s = this.spec(cfg.style, i, n);
    this.tone(s.freq, s.dur, cfg.volume);
    return s;
  },
  playFinal(cfg) {
    const s = this.finalSpec();
    this.tone(s.freq, s.dur, cfg.volume);
    return s;
  },

  /** Ukázka v administraci: přehraje celou sekvenci v reálném čase. onDone se zavolá po skončení. */
  preview(cfg, onDone) {
    this.stop();
    if (!this.unlock() && !this.ctx) return false;
    const n = Math.max(1, cfg.seconds);
    for (let i = 0; i < n; i++) this.timers.push(setTimeout(() => this.beep(cfg, i, n), i * 1000));
    let end = n * 1000;
    if (cfg.final) { this.timers.push(setTimeout(() => this.playFinal(cfg), end)); end += 1100; }
    this.timers.push(setTimeout(() => { this.timers = []; if (onDone) onDone(); }, end));
    return true;
  },
  stop() {
    this.timers.forEach(clearTimeout);
    this.timers = [];
  },
};

// Povolení zvuku při jakémkoli klepnutí (prohlížeče vyžadují interakci uživatele)
['pointerdown', 'keydown', 'touchstart'].forEach(ev => window.addEventListener(ev, () => { Beeper.unlock(); }, { passive: true }));
