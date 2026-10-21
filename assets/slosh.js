/* SloshGauge: a liquid level gauge with a damped spring, ported to vanilla JS from the React component.
   Markup:  <div class="slosh-gauge" data-slosh data-value="62" data-w="88" data-h="150"></div>
   Options (data-*): value 0-100, unit, text (replaces the number), w, h, r, ticks, viscosity, tilt, splash, liquid, glass,
   interactive, idle (gentle breathing). The simulation sleeps when the liquid is at rest, so it costs nothing while idle. */
(function () {
  'use strict';
  var clamp = function (v, a, b) { return Math.min(b, Math.max(a, v)); };
  var H = 1 / 120, DT_MAX = 0.05, TILT_GAIN = 0.07, TILT_MAX = 30, REST_POS = 0.05, REST_VEL = 2, STEP = 2, BIG = 10, BAND = 10;
  var mq = window.matchMedia ? matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };
  function onColor(hex) {
    var raw = String(hex || '').replace('#', ''); if (!/^[0-9a-f]{3}([0-9a-f]{3})?$/i.test(raw)) return '#ffffff';
    var full = raw.length === 3 ? raw.split('').map(function (c) { return c + c; }).join('') : raw, n = parseInt(full, 16);
    return (((n >> 16) & 255) * 299 + ((n >> 8) & 255) * 587 + (n & 255) * 114) / 1000 >= 150 ? '#111111' : '#ffffff';
  }
  function num(el, k, d) { var v = parseFloat(el.getAttribute('data-' + k)); return isNaN(v) ? d : v; }

  function Slosh(root) {
    var o = {
      value: clamp(num(root, 'value', 0), 0, 100), unit: root.getAttribute('data-unit') || '%', text: root.getAttribute('data-text'), w: num(root, 'w', 88), h: num(root, 'h', 180), r: num(root, 'r', 20),
      ticks: num(root, 'ticks', 4), viscosity: num(root, 'viscosity', 0.15), tilt: num(root, 'tilt', 0.45), splash: num(root, 'splash', 0.42),
      interactive: root.getAttribute('data-interactive') === 'true', idle: root.getAttribute('data-idle') !== 'false', showValue: root.getAttribute('data-show-value') !== 'false'
    };
    var liquidColor = root.getAttribute('data-liquid'), glassColor = root.getAttribute('data-glass');
    root.classList.add('slosh-gauge'); root.setAttribute('role', o.interactive ? 'slider' : 'meter'); root.setAttribute('aria-valuemin', '0'); root.setAttribute('aria-valuemax', '100');
    if (!root.getAttribute('aria-label')) root.setAttribute('aria-label', 'Level');
    root.setAttribute('data-interactive', o.interactive ? 'true' : 'false'); if (o.interactive) root.tabIndex = 0;
    root.style.setProperty('--sg-w', o.w + 'px'); root.style.setProperty('--sg-h', o.h + 'px'); root.style.setProperty('--sg-r', Math.min(o.r, o.w / 2, o.h / 2) + 'px'); root.style.setProperty('--sg-ticks', o.ticks);
    root.style.setProperty('--sg-font', clamp(Math.round(o.w * 0.16), 12, 20) + 'px');
    if (glassColor) root.style.setProperty('--sg-glass', glassColor);
    if (liquidColor) { root.style.setProperty('--sg-liquid', liquidColor); root.style.setProperty('--sg-on-liquid', onColor(liquidColor)); }
    root.innerHTML = '';
    var textA = o.showValue ? mk('span', 'slosh-gauge__value') : null, liquid = mk('div', 'slosh-gauge__liquid'), textB = o.showValue ? mk('span', 'slosh-gauge__value') : null;
    if (textA) { textA.setAttribute('aria-hidden', 'true'); root.appendChild(textA); }
    liquid.setAttribute('aria-hidden', 'true'); if (textB) liquid.appendChild(textB); root.appendChild(liquid);
    if (o.ticks > 0) { var t = mk('div', 'slosh-gauge__ticks'); t.setAttribute('aria-hidden', 'true'); root.appendChild(t); }
    var marker = null; if (o.interactive) { marker = mk('div', 'slosh-gauge__marker'); marker.setAttribute('aria-hidden', 'true'); root.appendChild(marker); }
    function mk(tag, cls) { var e = document.createElement(tag); e.className = cls; return e; }

    var reduce = mq.matches; var sim = { x: reduce ? o.value : 0, v: 0, L: reduce ? o.value : 0, raf: 0, last: 0 }, grip = null, started = false, onChange = null;
    function paint() {
      var th = reduce ? 0 : clamp(o.tilt * sim.v * TILT_GAIN, -TILT_MAX, TILT_MAX), lean = (Math.tan((th * Math.PI) / 180) * o.w) / 2, top = 100 - sim.x;
      liquid.style.clipPath = 'polygon(0 calc(' + top + '% + ' + lean + 'px), 100% calc(' + top + '% - ' + lean + 'px), 100% 100%, 0 100%)';
      if (marker) marker.style.transform = 'translateY(' + ((100 - sim.L) * o.h) / 100 + 'px)';
    }
    function say() {
      var n = Math.round(sim.L), s = o.text !== null ? o.text : n + o.unit; root.setAttribute('aria-valuenow', String(Math.round(o.interactive ? n : o.value)));
      if (root.getAttribute('aria-valuetext') === null && o.text !== null) root.setAttribute('aria-valuetext', o.text);
      if (textA) textA.textContent = s; if (textB) textB.textContent = s;
    }
    function tick(now) {
      var dt = sim.last ? Math.min((now - sim.last) / 1000, DT_MAX) : H; sim.last = now;
      if (o.viscosity <= 0) { sim.x = sim.L; sim.v = 0; }
      else {
        var k = 1224 - 1044 * o.viscosity, zeta = reduce ? 1 : 0.26 - 0.17 * o.viscosity, c = 2 * zeta * Math.sqrt(k), rest = reduce ? 0 : o.splash;
        for (var n = Math.ceil(dt / H), h = dt / n; n > 0; n -= 1) {
          sim.v = sim.v * Math.exp(-c * h) + k * (sim.L - sim.x) * h; sim.x += sim.v * h;
          if (sim.x > 100) { sim.x = 100; sim.v = -sim.v * rest; } else if (sim.x < 0) { sim.x = 0; sim.v = -sim.v * rest; }
        }
        if (!grip && Math.abs(sim.L - sim.x) < REST_POS && Math.abs(sim.v) < REST_VEL) { sim.x = sim.L; sim.v = 0; }
      }
      paint();
      if (!grip && sim.x === sim.L && sim.v === 0) { sim.raf = 0; sim.last = 0; } else sim.raf = requestAnimationFrame(tick);
    }
    function wake() { if (!sim.raf) sim.raf = requestAnimationFrame(tick); }
    function setLevel(L, instant) { sim.L = clamp(L, 0, 100); if (instant) { sim.x = sim.L; sim.v = 0; } say(); wake(); }
    function nudge(amount) { if (reduce) return; sim.v += amount; wake(); }

    say(); paint();
    // fill up when it first scrolls into view, so the liquid sloshes in
    function begin() { if (started) return; started = true; setLevel(o.value, reduce); }
    if (reduce || !('IntersectionObserver' in window)) begin();
    else { var io = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { setTimeout(begin, 120 + (root.__delay || 0)); io.disconnect(); } }); }, { threshold: 0.2 }); io.observe(root); }
    if (!o.interactive) root.addEventListener('pointerenter', function () { nudge((Math.random() < 0.5 ? -1 : 1) * 24); });
    if (o.idle && !reduce) { (function breathe() { setTimeout(function () { if (!document.hidden && started && !grip && root.isConnected) nudge((Math.random() - 0.5) * 10); if (root.isConnected) breathe(); }, 6000 + Math.random() * 6000); })(); }

    // interactive mode (drag / keyboard), same behaviour as the React version
    function levelAt(y, g) { return clamp(((g.rect.bottom - y) / g.scale / (root.offsetHeight || g.rect.height)) * 100, 0, 100); }
    function report() { var g = grip, n = Math.round(sim.L); if (g && n !== g.sent) { g.sent = n; if (onChange) onChange(n); } }
    function up(e, reason) {
      var g = grip; if (!g || g.id !== e.pointerId) return; grip = null; try { root.releasePointerCapture(e.pointerId); } catch (x) {}
      root.setAttribute('data-held', 'false'); if (reason === 'escape') { setLevel(g.at); if (onChange) onChange(Math.round(g.at)); } wake();
    }
    if (o.interactive) {
      root.addEventListener('pointerdown', function (e) {
        if (grip || e.button !== 0) return; var rect = root.getBoundingClientRect(), scale = rect.height / (root.offsetHeight || rect.height) || 1, markerY = rect.top + ((100 - sim.L) / 100) * rect.height;
        grip = { id: e.pointerId, rect: rect, scale: scale, band: Math.abs(e.clientY - markerY) <= BAND * scale, grab: null, at: sim.L, sent: NaN }; try { root.setPointerCapture(e.pointerId); } catch (x) {}
        root.setAttribute('data-held', 'true'); if (grip.band) wake(); else { setLevel(levelAt(e.clientY, grip)); report(); }
      });
      root.addEventListener('pointermove', function (e) { var g = grip; if (!g || g.id !== e.pointerId) return; var at = levelAt(e.clientY, g); if (g.band && g.grab === null) { g.grab = sim.L - at; return; } setLevel(at + (g.grab || 0)); report(); });
      root.addEventListener('pointerup', function (e) { up(e); }); root.addEventListener('pointercancel', function (e) { up(e, 'cancel'); });
      root.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { if (grip) up({ pointerId: grip.id }, 'escape'); return; }
        var L = sim.L, d = e.shiftKey ? BIG : STEP, map = { ArrowUp: L + d, ArrowRight: L + d, ArrowDown: L - d, ArrowLeft: L - d, PageUp: L + BIG, PageDown: L - BIG, Home: 0, End: 100 }, next = map[e.key];
        if (next === undefined) return; e.preventDefault(); setLevel(next, true); if (onChange) onChange(Math.round(sim.L));
      });
    }
    root.__slosh = { set: function (v, instant) { o.value = clamp(v, 0, 100); started = true; setLevel(o.value, instant); }, nudge: nudge, onChange: function (fn) { onChange = fn; } };
    return root.__slosh;
  }
  function init(scope) { Array.prototype.forEach.call((scope || document).querySelectorAll('[data-slosh]'), function (el, i) { if (!el.__slosh) { el.__delay = i * 90; Slosh(el); } }); }
  window.SloshGauge = { init: init, create: Slosh };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(); }); else init();
})();
