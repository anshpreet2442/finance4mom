/* StatusMark: vanilla port of the React component (same DOM, class names, CSS, springs and easing).
   var m = StatusMark.create({ status: 'running', progress: 0.62, label: 'Draft supplier emails', size: 20, ... }); parent.appendChild(m.el); m.update({ status: 'done' }); m.destroy();
   statuses: pending | running | done | failed | cancelled. Needs motion.js. StatusMark.list(container) manages a keyed list of steps (used for live agent steps). */
(function (w) {
  'use strict';
  var NS = 'http://www.w3.org/2000/svg', CHECK = 'M7.5 12.25 10.5 15.25 16.75 8.75', CROSS = 'M8.5 8.5 15.5 15.5M15.5 8.5 8.5 15.5';
  var TEXT = { pending: 'Pending', running: 'In progress', done: 'Completed', failed: 'Failed', cancelled: 'Cancelled' }, IDLE_DASH = 0.3, UI = { type: 'spring', duration: 0.3, bounce: 0 }, MORPH = { duration: 0.3, ease: [0.77, 0, 0.175, 1] };
  function clamp01(v) { return Math.min(1, Math.max(0, v)); }
  function svg(tag, attrs) { var e = document.createElementNS(NS, tag); for (var k in attrs) e.setAttribute(k, attrs[k]); return e; }
  function create(opts) {
    var o = Object.assign({ status: 'pending', progress: undefined, label: undefined, color: 'currentColor', doneColor: '#22c55e', errorColor: '#ef4444', size: 20, strokeWidth: 2, dashes: 8, fontSize: 14, spinDuration: 1100, arcLength: 0.68, drawDuration: 240, fillOpacity: 0.06, strike: true, strikeDelay: 60, className: '' }, opts || {});
    var M = w.MfMotion, r = 10 - o.strokeWidth / 2, C = 2 * Math.PI * r, P = C / Math.max(1, o.dashes);
    var el = document.createElement('span'), s = el.style;
    el.className = 'status-mark' + (o.className ? ' ' + o.className : '');
    s.setProperty('--sm-size', o.size + 'px'); s.setProperty('--sm-stroke', String(o.strokeWidth)); s.setProperty('--sm-color', o.color); s.setProperty('--sm-done', o.doneColor); s.setProperty('--sm-error', o.errorColor);
    s.setProperty('--sm-fill', String(o.fillOpacity)); s.setProperty('--sm-font', o.fontSize + 'px'); s.setProperty('--sm-draw', o.drawDuration + 'ms'); s.setProperty('--sm-strike-delay', (120 + o.strikeDelay) + 'ms');
    var g = svg('svg', { 'class': 'status-mark__glyph', viewBox: '0 0 24 24', width: o.size, height: o.size });
    var track = svg('circle', { 'class': 'status-mark__track', cx: 12, cy: 12, r: r, transform: 'rotate(-90 12 12)' }), ring = svg('circle', { 'class': 'status-mark__ring', cx: 12, cy: 12, r: r, transform: 'rotate(-90 12 12)' });
    g.appendChild(track); g.appendChild(ring); g.appendChild(svg('path', { 'class': 'status-mark__check', d: CHECK, pathLength: 1 })); g.appendChild(svg('path', { 'class': 'status-mark__cross', d: CROSS, pathLength: 1 }));
    el.appendChild(g);
    var sr = null, labelEl = null, labelText = null;
    var mode = M.value(0), arc = M.value(1), travel = M.value(0), gen = 0, first = true, st = {};
    function writeDash() {
      var m = mode.get(), a = arc.get(), dash = IDLE_DASH * P + (a * C - IDLE_DASH * P) * m, gap = (1 - IDLE_DASH) * P + ((1 - a) * C - (1 - IDLE_DASH) * P) * m;
      ring.setAttribute('stroke-dasharray', Math.max(0, dash) + ' ' + Math.max(0, gap));
    }
    mode.on(writeDash); arc.on(writeDash); travel.on(function (v) { ring.setAttribute('stroke-dashoffset', String(v)); });
    function label(l) {
      if (l === undefined || l === null) return;
      if (!labelEl) {
        g.setAttribute('aria-hidden', 'true'); g.removeAttribute('role'); g.removeAttribute('aria-label');
        sr = document.createElement('span'); sr.className = 'status-mark__sr'; labelEl = document.createElement('span'); labelEl.className = 'status-mark__label'; labelText = document.createTextNode('');
        var strike = document.createElement('span'); strike.className = 'status-mark__strike'; strike.setAttribute('aria-hidden', 'true'); labelEl.appendChild(labelText); labelEl.appendChild(strike); el.appendChild(sr); el.appendChild(labelEl);
      }
      labelText.nodeValue = l;
    }
    function apply(p) {
      var status = p.status, progress = p.progress, determinate = status === 'running' && isFinite(progress) && progress !== null && progress !== undefined, indeterminate = status === 'running' && !determinate;
      var solid = status === 'running' || status === 'done' || status === 'failed', targetArc = indeterminate ? o.arcLength : determinate ? clamp01(progress) : 1;
      var spoken = TEXT[status] + (determinate ? ', ' + Math.round(clamp01(progress) * 100) + '%' : '');
      el.setAttribute('data-status', status); if (indeterminate) el.setAttribute('data-indeterminate', ''); else el.removeAttribute('data-indeterminate'); if (o.strike) el.setAttribute('data-strike', ''); else el.removeAttribute('data-strike');
      if (p.label !== undefined) label(p.label);
      if (labelEl) sr.textContent = spoken + ': '; else { g.setAttribute('role', 'img'); g.setAttribute('aria-label', spoken); }
      var changed = first || st.status !== status || st.determinate !== determinate || st.targetArc !== targetArc; st = { status: status, determinate: determinate, targetArc: targetArc };
      if (!changed) return;
      var gg = ++gen;
      if (first) { mode.jump(solid ? 1 : 0); arc.jump(targetArc); first = false; if (w.MfMotion.reduced()) { travel.jump(0); return; } if (!indeterminate) { return; } }
      if (M.reduced()) { mode.jump(solid ? 1 : 0); arc.jump(targetArc); travel.jump(0); return; }
      if (mode.get() === 0) arc.jump(targetArc);
      M.animate(mode, solid ? 1 : 0, MORPH); M.animate(arc, targetArc, UI);
      if (indeterminate) { var t0 = travel.get(); M.animate(travel, [t0, t0 - C], { duration: o.spinDuration / 1000, ease: 'linear', repeat: Infinity }); return; }
      var unit = determinate ? C : P, to = Math.floor(travel.get() / unit) * unit;
      M.animate(travel, to, UI).finished.then(function () { if (gen === gg && !travel.run) travel.jump(0); });
    }
    var cur = { status: o.status, progress: o.progress, label: o.label };
    apply(cur);
    return {
      el: el,
      update: function (p) { cur = Object.assign({}, cur, p); if (!('progress' in (p || {})) && p && p.status && p.status !== 'running') cur.progress = undefined; apply(cur); },
      destroy: function () { gen++; mode.stop(); arc.stop(); travel.stop(); if (el.parentNode) el.parentNode.removeChild(el); },
    };
  }
  /** A keyed list of steps: list.set({ id, label, status, progress }) adds or updates a row; list.cancelRunning() marks unfinished rows cancelled. */
  function list(container, opts) {
    var rows = {}, o = opts || {};
    return {
      set: function (s) {
        var r = rows[s.id];
        if (!r) { var li = document.createElement('li'), m = create(Object.assign({ size: 18, fontSize: 14, doneColor: '', errorColor: '' }, o, { status: s.status, progress: s.progress, label: s.label })); li.appendChild(m.el); container.appendChild(li); r = rows[s.id] = { li: li, m: m }; }
        else r.m.update({ status: s.status, progress: s.progress, label: s.label });
      },
      cancelRunning: function () { for (var k in rows) { var el = rows[k].m.el; var st = el.getAttribute('data-status'); if (st === 'running' || st === 'pending') rows[k].m.update({ status: 'cancelled' }); } },
      clear: function () { for (var k in rows) rows[k].m.destroy(); rows = {}; container.textContent = ''; },
      count: function () { return Object.keys(rows).length; },
    };
  }
  w.StatusMark = { create: create, list: list };
})(window);
