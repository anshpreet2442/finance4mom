/* Circular progress ring: a vanilla port of MagicUI's AnimatedCircularProgressBar.
   Same geometry as the original: viewBox 0 0 100 100, r = 45, stroke-width 10, round caps, the arc starts at 12 o'clock and runs clockwise,
   the arc length is animated with a CSS transition on stroke-dasharray (1s ease), the value sits in the middle (semibold).
   Extras for this app: tones that follow limits (primary -> amber near the limit -> red when over), animate from 0 the first time it scrolls into view,
   updates when the value changes, reduced motion = no animation, role="progressbar", colours from the app tokens (any CSS colour accepted).

   Declarative:   <div data-ring data-value="72"></div>          (see the data-* list below)
   Imperative:    mfRing(el, { value, max, min, primary, secondary, format, size, text, sub, label, limit, warn, bad, variant, duration })
                  returns { update(opts), destroy(), el }
   data-*: value max min primary secondary format(pct|raw|none) size(px) text sub label limit(1) warn(85) bad(100) tone(ok|warn|bad|done) variant(track|split) duration(ms)
   The server (ring() in app/ring.php) prints the same markup with the final state already in place, so nothing moves when this script starts.
   Pure helpers (also used by the node tests): MfRing.math.percent / tone / dash / split / text. */
(function (root, factory) {
  var api = factory(root);
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else { root.MfRing = api; root.mfRing = api.create; if (root.document) api.boot(); }
})(typeof window !== 'undefined' ? window : globalThis, function (w) {
  'use strict';
  var d = w.document, C = 2 * Math.PI * 45, NS = 'http://www.w3.org/2000/svg';

  // ---------------------------------------------------------------- pure maths
  function num(v, def) { var n = typeof v === 'number' ? v : parseFloat(v); return isFinite(n) ? n : def; }
  /** { real, visual, whole }: real = unclamped percent of the range (112 for 112 % used), visual = what the arc draws (0..100). */
  function percent(value, min, max) {
    value = num(value, 0); min = num(min, 0); max = num(max, 100);
    var span = max - min, real = span > 0 ? (value - min) / span * 100 : 0;
    return { real: real, visual: Math.max(0, Math.min(100, real)), whole: Math.round(real) };
  }
  /** Tone for a ring. limit = true for budgets and caps (amber near the limit, red when over); goals stay neutral until they are complete. */
  function tone(real, o) {
    o = o || {}; var warn = num(o.warn, 85), bad = num(o.bad, 100);
    if (o.limit) return real > bad ? 'bad' : (real >= warn ? 'warn' : 'ok');
    return real >= 100 ? 'done' : 'ok';
  }
  /** Length of the arc in user units for a visual percent (stroke-dasharray first value). */
  function dash(visual) { return Math.round(visual / 100 * C * 1000) / 1000; }
  /** Geometry of the MagicUI "split" look: a gap of 5 % on each side of the primary arc; no second arc from 90 % up. */
  function split(visual) {
    var gap = 5, whole = Math.round(visual), rest = whole <= 90 && whole >= 0 ? 90 - whole : 0;
    return { gap: gap, secondary: rest, secondaryDash: dash(rest), secondaryRotate: 360 - 90 - gap * 3.6, primaryRotate: -90 };
  }
  /** Text in the middle: the whole percent by default. */
  function text(real, o) {
    o = o || {}; if (o.text != null && o.text !== '') return String(o.text);
    var f = o.format || 'pct'; if (f === 'none') return '';
    var n = Math.round(real); if (f === 'raw') return String(n); return n + '%';
  }
  function ease(x) { return bezier(0.25, 0.1, 0.25, 1)(x); } // CSS "ease"
  var _b = null;
  function bezier(x1, y1, x2, y2) {
    if (_b) return _b;
    var cx = 3 * x1, bx = 3 * (x2 - x1) - cx, ax = 1 - cx - bx, cy = 3 * y1, by = 3 * (y2 - y1) - cy, ay = 1 - cy - by;
    function sx(t) { return ((ax * t + bx) * t + cx) * t; } function sy(t) { return ((ay * t + by) * t + cy) * t; } function dx(t) { return (3 * ax * t + 2 * bx) * t + cx; }
    _b = function (x) {
      if (x <= 0) return 0; if (x >= 1) return 1; var t = x, i, e;
      for (i = 0; i < 8; i++) { e = sx(t) - x; if (Math.abs(e) < 1e-5) return sy(t); var s = dx(t); if (Math.abs(s) < 1e-6) break; t -= e / s; }
      var lo = 0, hi = 1; t = x; while (lo < hi) { e = sx(t); if (Math.abs(e - x) < 1e-5) break; if (x > e) lo = t; else hi = t; t = (hi - lo) / 2 + lo; if (hi - lo < 1e-7) break; }
      return sy(t);
    };
    return _b;
  }
  var math = { percent: percent, tone: tone, dash: dash, split: split, text: text, ease: ease, CIRC: C };
  if (!d) return { math: math, create: function () { return null; }, scan: function () {}, boot: function () {} };

  // ---------------------------------------------------------------- component
  var reduce = w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)');
  function reduced() { return !!(reduce && reduce.matches); }
  function svgEl(tag, cls) { var e = d.createElementNS(NS, tag); if (cls) e.setAttribute('class', cls); return e; }
  function readAttrs(el) {
    var g = function (k) { return el.getAttribute('data-' + k); }, o = {};
    ['value', 'max', 'min', 'warn', 'bad', 'size', 'duration'].forEach(function (k) { if (g(k) !== null) o[k] = g(k); });
    ['primary', 'secondary', 'format', 'text', 'sub', 'label', 'variant', 'tone'].forEach(function (k) { if (g(k) !== null) o[k] = g(k); });
    if (g('limit') !== null) o.limit = g('limit') !== '0' && g('limit') !== 'false';
    return o;
  }

  function create(el, opts) {
    if (!el) return null; if (el._ring) { if (opts) el._ring.update(opts); return el._ring; }
    var o = {}, k, attrs = readAttrs(el); for (k in attrs) o[k] = attrs[k]; for (k in (opts || {})) o[k] = opts[k];
    var svg = el.querySelector('svg.mf-ring__svg'), track, arc, valEl = el.querySelector('.mf-ring__n'), subEl = el.querySelector('.mf-ring__s');
    el.classList.add('mf-ring');
    if (!svg) { // built on the client (no server markup)
      svg = svgEl('svg', 'mf-ring__svg'); svg.setAttribute('viewBox', '0 0 100 100'); svg.setAttribute('fill', 'none'); svg.setAttribute('aria-hidden', 'true');
      track = svgEl('circle', 'mf-ring__track'); arc = svgEl('circle', 'mf-ring__arc');
      [track, arc].forEach(function (c) { c.setAttribute('cx', '50'); c.setAttribute('cy', '50'); c.setAttribute('r', '45'); svg.appendChild(c); });
      el.insertBefore(svg, el.firstChild);
    }
    track = svg.querySelector('.mf-ring__track'); arc = svg.querySelector('.mf-ring__arc');
    if (!valEl) { var wrap = d.createElement('span'); wrap.className = 'mf-ring__v'; valEl = d.createElement('span'); valEl.className = 'mf-ring__n'; wrap.appendChild(valEl); subEl = d.createElement('small'); subEl.className = 'mf-ring__s'; wrap.appendChild(subEl); el.appendChild(wrap); }
    if (!el.hasAttribute('role')) el.setAttribute('role', 'progressbar');
    var state = { shown: null, raf: 0, ready: false, io: null, mo: null, dead: false };

    function compute() {
      var p = percent(o.value, o.min, o.max), t = o.tone && o.tone !== 'auto' ? o.tone : tone(p.real, o);
      return { p: p, tone: t, txt: text(p.real, o), sp: split(p.visual) };
    }
    function paint(c, animateText) {
      var cs = el.style;
      el.setAttribute('data-state', c.tone); el.setAttribute('data-variant', o.variant === 'split' ? 'split' : 'track');
      if (o.size) cs.setProperty('--ring-size', (parseFloat(o.size) || 96) + 'px');
      if (o.duration) cs.setProperty('--ring-ms', (parseFloat(o.duration) || 1000) + 'ms');
      if (o.primary) cs.setProperty('--ring-primary', o.primary); else cs.removeProperty('--ring-primary');
      if (o.secondary) cs.setProperty('--ring-secondary', o.secondary); else cs.removeProperty('--ring-secondary');
      cs.setProperty('--p', String(Math.round(c.p.visual * 100) / 100)); cs.setProperty('--s', String(c.sp.secondary));
      var min = num(o.min, 0), max = num(o.max, 100), now = Math.max(min, Math.min(max, num(o.value, 0)));
      el.setAttribute('aria-valuemin', String(min)); el.setAttribute('aria-valuemax', String(max)); el.setAttribute('aria-valuenow', String(Math.round(now * 100) / 100));
      var vt = o.valueText || (c.p.real > 100 ? c.p.whole + '% (over the limit by ' + (c.p.whole - 100) + '%)' : c.p.whole + '%'); el.setAttribute('aria-valuetext', vt);
      if (o.label) el.setAttribute('aria-label', o.label);
      if (subEl && o.sub != null) subEl.textContent = o.sub;
      if (!animateText) valEl.textContent = c.txt;
      if (state.mo) state.mo.takeRecords(); // our own attribute writes are not changes made by the page
    }
    function countTo(c, from, ms) {
      cancelAnimationFrame(state.raf);
      var numeric = (o.text == null || o.text === '') && o.format !== 'none';
      if (!numeric || reduced() || ms <= 0 || from === c.p.whole) { valEl.textContent = c.txt; return; }
      var t0 = null, suffix = o.format === 'raw' ? '' : '%', ez = bezier(0.25, 0.1, 0.25, 1);
      function step(t) {
        if (state.dead) return; if (t0 === null) t0 = t; var k = Math.min(1, (t - t0) / ms), v = Math.round(from + (c.p.whole - from) * ez(k));
        valEl.textContent = v + suffix; if (k < 1) state.raf = requestAnimationFrame(step); else valEl.textContent = c.txt;
      }
      valEl.textContent = from + suffix; state.raf = requestAnimationFrame(step);
      setTimeout(function () { if (!state.dead && valEl.textContent !== c.txt && !state.raf) valEl.textContent = c.txt; }, ms + 400); // background tabs pause frames: always land on the real number
    }
    function ms() { return parseFloat(o.duration) || 1000; }
    function reveal() {
      if (state.ready) return; state.ready = true; var c = compute();
      if (reduced()) { el.classList.add('is-in', 'is-static'); paint(c); return; }
      el.classList.add('is-in'); paint(c, true); countTo(c, 0, ms()); state.shown = c.p.whole;
    }
    function update(next) {
      for (var k in (next || {})) o[k] = next[k]; var c = compute();
      if (!state.ready) { paint(c); state.shown = c.p.whole; return; }
      var from = state.shown == null ? 0 : state.shown; paint(c, true); countTo(c, from, reduced() ? 0 : ms()); state.shown = c.p.whole;
    }

    // initial state: nothing moves until the ring has been seen; reduced motion shows the final state at once
    var first = compute(); paint(first); state.shown = first.p.whole; el.classList.add('is-armed');
    if (reduced() || !('IntersectionObserver' in w)) { state.ready = true; el.classList.add('is-in', 'is-static'); }
    else {
      state.io = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting && !state.ready) { state.io.disconnect(); reveal(); } }); }, { threshold: 0.25 });
      state.io.observe(el);
    }
    if (w.MutationObserver) {
      state.mo = new w.MutationObserver(function (ml) {
        var changed = false; ml.forEach(function (m) { if (m.attributeName && m.attributeName.indexOf('data-') === 0) changed = true; });
        if (changed) { var a = readAttrs(el), n = {}; for (var k in a) n[k] = a[k]; update(n); }
      });
      state.mo.observe(el, { attributes: true, attributeFilter: ['data-value', 'data-max', 'data-min', 'data-primary', 'data-secondary', 'data-text', 'data-tone', 'data-sub', 'data-limit'] });
    }
    var api = { el: el, update: update, destroy: function () { state.dead = true; cancelAnimationFrame(state.raf); if (state.io) state.io.disconnect(); if (state.mo) state.mo.disconnect(); delete el._ring; } };
    el._ring = api; return api;
  }

  function scan(rootEl) { [].forEach.call((rootEl || d).querySelectorAll('[data-ring]'), function (el) { create(el); }); }
  function boot() {
    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', function () { scan(d); }); else scan(d);
    d.addEventListener('mfnc:page', function () { scan(d); });
  }
  return { math: math, create: create, scan: scan, boot: boot };
});
