/* JellyRadio: a radio / tab group whose chips swell, barge their neighbours aside and wobble like jelly. Vanilla port of the React component.
   Markup (see jelly() in app/ui.php): <div class="jelly-radio" data-jelly role="radiogroup"> <a|button class="jelly-radio__chip" role="radio" data-on="true"> ...
   Chips with an href act as navigation tabs: the jelly plays, then the single-page router takes over. Options via data-*: swell barge shrink jelly bounce stagger stiffness gap radius size. */
(function () {
  'use strict';
  var SIZES = { sm: [28, 12, 12], md: [36, 13, 16], lg: [44, 14, 20] };
  var mq = window.matchMedia ? matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };
  function num(el, k, d) { var v = parseFloat(el.getAttribute('data-' + k)); return isNaN(v) ? d : v; }
  function Spring(x) { this.x = x; this.v = 0; this.t = x; this.k = 1; this.c = 1; this.m = 1; this.start = 0; this.on = false; }
  Spring.prototype.to = function (t, k, m, bounce, delay, now) { this.t = t; this.k = k; this.m = m; this.c = 2 * Math.sqrt(k * m) * (1 - bounce); this.start = now + delay; this.on = true; };
  Spring.prototype.jump = function (x) { this.x = this.t = x; this.v = 0; this.on = false; };
  Spring.prototype.step = function (h, now) {
    if (!this.on || now < this.start) return; var a = (-this.k * (this.x - this.t) - this.c * this.v) / this.m; this.v += a * h; this.x += this.v * h;
    if (Math.abs(this.x - this.t) < 0.0008 && Math.abs(this.v) < 0.01) { this.x = this.t; this.v = 0; this.on = false; }
  };

  function Jelly(group) {
    var o = { swell: num(group, 'swell', 0.2), barge: num(group, 'barge', 6), shrink: num(group, 'shrink', 0.05), jelly: num(group, 'jelly', 1), bounce: num(group, 'bounce', 0.25), stagger: num(group, 'stagger', 22), stiffness: num(group, 'stiffness', 580),
      gap: num(group, 'gap', 8), radius: num(group, 'radius', 18), size: group.getAttribute('data-size') || 'md' };
    var sz = SIZES[o.size] || SIZES.md, chips = Array.prototype.slice.call(group.querySelectorAll('.jelly-radio__chip'));
    if (!chips.length) return;
    group.style.setProperty('--jr-gap', o.gap + 'px'); group.style.setProperty('--jr-radius', o.radius + 'px'); group.style.setProperty('--jr-h', sz[0] + 'px'); group.style.setProperty('--jr-font', sz[1] + 'px'); group.style.setProperty('--jr-px', sz[2] + 'px');
    var sp = chips.map(function () { return { x: new Spring(0), sx: new Spring(1), sy: new Spring(1) }; }), widths = [], raf = 0, last = 0;
    var at = Math.max(0, chips.findIndex(function (c) { return c.getAttribute('data-on') === 'true'; }));
    function paint() { chips.forEach(function (c, i) { c.style.transform = 'translateX(' + sp[i].x.x + 'px) scale(' + sp[i].sx.x + ',' + sp[i].sy.x + ')'; }); }
    function loop(now) {
      var dt = last ? Math.min((now - last) / 1000, 0.05) : 1 / 120; last = now; var n = Math.max(1, Math.ceil(dt * 240)), h = dt / n, busy = false;
      for (var s = 0; s < n; s++) sp.forEach(function (m) { m.x.step(h, now); m.sx.step(h, now); m.sy.step(h, now); });
      sp.forEach(function (m) { if (m.x.on || m.sx.on || m.sy.on) busy = true; }); paint(); if (busy) raf = requestAnimationFrame(loop); else { raf = 0; last = 0; }
    }
    function wake() { if (!raf) raf = requestAnimationFrame(loop); }
    function apply(sel, instant) {
      var reduce = mq.matches, rtl = getComputedStyle(group).direction === 'rtl', push = ((widths[sel] || 0) * o.swell) / 2 + o.barge, now = performance.now();
      chips.forEach(function (c, i) {
        var on = i === sel, far = Math.abs(i - sel), x = Math.sign(i - sel) * (rtl ? -1 : 1) * push, s = on ? 1 + o.swell : 1 - o.shrink, m = sp[i];
        if (instant || reduce) { m.x.jump(x); m.sx.jump(s); m.sy.jump(s); return; }
        var k = o.stiffness * (1 - 0.12 * Math.min(far, 3)), flying = m.x.on || m.sx.on || m.sy.on, delay = flying ? 0 : far * o.stagger, j = o.jelly;
        m.x.to(x, k, 0.9, o.bounce, delay, now);
        m.sx.to(s, k * (1 + 0.24 * j), 0.9 - 0.1 * j, Math.min(0.85, o.bounce + 0.3 * j), delay, now);
        m.sy.to(s, k * (1 - 0.14 * j), 0.9 + 0.05 * j, o.bounce, delay + 50 * j, now);
      });
      if (instant || reduce) paint(); else wake();
    }
    function measure() {
      widths = chips.map(function (c) { return c.offsetWidth || 0; }); var h = chips[0].offsetHeight || 0, maxW = Math.max.apply(null, widths.concat([0]));
      group.style.setProperty('--jr-pad-x', Math.ceil((maxW * o.swell * 1.3) / 2 + o.barge) + 2 + 'px'); group.style.setProperty('--jr-pad-y', Math.ceil((h * o.swell) / 2) + 2 + 'px');
    }
    function settle() { measure(); apply(at, true); }
    function mark(i) { chips.forEach(function (c, k) { c.setAttribute('aria-checked', k === i ? 'true' : 'false'); c.setAttribute('data-on', k === i ? 'true' : 'false'); c.tabIndex = k === i ? 0 : -1; }); }
    function commit(i, instant) {
      var c = chips[i]; if (!c || i === at || c.hasAttribute('disabled') || c.getAttribute('aria-disabled') === 'true') return false;
      at = i; mark(i); apply(i, instant);
      group.dispatchEvent(new CustomEvent('jelly:change', { bubbles: true, detail: { value: c.getAttribute('data-value'), index: i, chip: c } })); return true;
    }
    function go(c) { var href = c.getAttribute('href'); if (!href) return; var run = function () { if (window.MfncSpa) window.MfncSpa.go(href); else location.href = href; }; if (mq.matches) run(); else setTimeout(run, 230); }
    group.addEventListener('click', function (e) {
      var c = e.target.closest('.jelly-radio__chip'); if (!c || !group.contains(c)) return; var i = chips.indexOf(c), nav = c.hasAttribute('href');
      if (nav) { if (e.metaKey || e.ctrlKey || e.shiftKey || e.button) return; e.preventDefault(); e.stopImmediatePropagation(); if (i === at) { go(c); return; } commit(i, e.detail === 0); go(c); }
      else commit(i, e.detail === 0);
    });
    group.addEventListener('keydown', function (e) {
      var c = e.target.closest('.jelly-radio__chip'); if (!c) return; var i = chips.indexOf(c), n = chips.length, nav = group.hasAttribute('data-nav') || c.hasAttribute('href'), next = null;
      function step(from, d) { for (var t = 0, j = from; t < n; t++) { j = (j + d + n) % n; if (!chips[j].hasAttribute('disabled')) return j; } return from; }
      if (e.key === 'ArrowRight' || e.key === 'ArrowDown') next = step(i, 1); else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') next = step(i, -1); else if (e.key === 'Home') next = step(-1, 1); else if (e.key === 'End') next = step(n, -1);
      if (next === null) return; e.preventDefault(); chips[next].focus(); if (!nav) commit(next, true);
    });
    if (window.ResizeObserver) new ResizeObserver(settle).observe(group); if (document.fonts && document.fonts.ready) document.fonts.ready.then(settle);
    mark(at); settle(); group.__jelly = { select: function (i, instant) { commit(i, instant); }, value: function () { return chips[at].getAttribute('data-value'); } };
  }
  function init(scope) { Array.prototype.forEach.call((scope || document).querySelectorAll('[data-jelly]'), function (g) { if (!g.__jelly) Jelly(g); }); }
  window.JellyRadio = { init: init };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { init(); }); else init();
})();
