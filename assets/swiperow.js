/* SwipeRow — vanilla port of the React SwipeRow (swipe-to-reveal row actions).
   Markup comes from PHP swipe_row(): <div class="swipe-row" data-swipe> <div class="swipe-row__actions swipe-row__actions--start|end"> …action buttons… </div> <div class="swipe-row__content"> … </div> </div>
   Options (data attributes on .swipe-row; same names as the React props):
     data-threshold="0.4"   fraction of the actions' width past which a release keeps them open
     data-full="0.7"        fraction of the row width past which a release runs the first action ("full swipe"); 0 disables
     data-resistance="0.35" rubber-band factor when dragging past the actions
     data-velocity="0.5"    px/ms flick speed that opens/closes regardless of distance
     data-disabled          turns swiping off (actions stay reachable by keyboard)
     data-mouse             also allow mouse dragging (touch / pen only by default)
   Actions are real <form> buttons (CSRF included), so they work without JS. Buttons marked data-swipe-undo run after a short delay with an Undo toast.
   Accessibility: role=group, ArrowLeft/ArrowRight on the focused row reveal actions, Escape closes, actions are only tabbable while open. prefers-reduced-motion: no animation. */
(function () {
  'use strict';
  var d = document, reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches, openRow = null, pending = [];
  function num(el, k, def) { var v = parseFloat(el.getAttribute('data-' + k)); return isNaN(v) ? def : v; }

  function setTab(row, on) { [].forEach.call(row.querySelectorAll('.swipe-row__actions button, .swipe-row__actions a'), function (b) { b.tabIndex = on ? 0 : -1; }); }
  function close(row) { if (!row) return; setX(row, 0, true); row.removeAttribute('data-open'); setTab(row, false); if (openRow === row) openRow = null; }
  function setX(row, x, animate) {
    var c = row._c; c.style.transition = animate && !reduce ? 'transform .34s cubic-bezier(.2,.9,.25,1)' : 'none'; c.style.transform = x ? 'translate3d(' + x + 'px,0,0)' : ''; row._x = x;
    row.classList.toggle('is-start', x > 0); row.classList.toggle('is-end', x < 0);
  }
  function open(row, side) {
    if (openRow && openRow !== row) close(openRow);
    var w = side === 'start' ? row._sw : row._ew; if (!w) return; setX(row, side === 'start' ? w : -w, true); row.setAttribute('data-open', side); setTab(row, true); openRow = row;
  }
  function run(btn, row) {
    var undo = btn.getAttribute('data-swipe-undo'), form = btn.form;
    if (btn.hasAttribute('data-open') || !form) { close(row); btn.click && btn.dispatchEvent(new MouseEvent('click', { bubbles: true })); return; }
    if (!undo) { if (form.requestSubmit) form.requestSubmit(btn); else form.submit(); return; }
    // destructive: collapse the row now, really send it after 5 s unless the person taps Undo
    var h = row.offsetHeight; row.style.height = h + 'px'; row.style.overflow = 'hidden'; row.offsetHeight; row.style.transition = reduce ? 'none' : 'height .28s ease, opacity .28s ease, margin .28s ease'; row.style.height = '0px'; row.style.opacity = '0'; row.style.marginTop = '0'; row.style.marginBottom = '0';
    row.setAttribute('aria-hidden', 'true'); if (openRow === row) openRow = null;
    var done = false, fd = new FormData(form), url = form.action;
    function commit(keep) {
      if (done) return; done = true; clearTimeout(t); pending = pending.filter(function (p) { return p !== commit; });
      var opts = { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Mfnc-Spa': '1' }, keepalive: !!keep, redirect: 'manual' };
      fetch(url, opts).then(function () { row.remove(); if (window.MfncSpa) window.MfncSpa.refresh(); else if (!keep) location.reload(); }).catch(function () { restore(); if (window.toast) toast({ type: 'err', title: 'Could not delete', text: 'Check your connection and try again.' }); });
    }
    function restore() { row.style.height = h + 'px'; row.style.opacity = ''; row.style.marginTop = ''; row.style.marginBottom = ''; row.removeAttribute('aria-hidden'); setX(row, 0, false); setTimeout(function () { row.style.height = ''; row.style.overflow = ''; row.style.transition = ''; }, 320); }
    var t = setTimeout(function () { commit(false); }, 5000); pending.push(commit);
    if (window.toast) toast({ type: 'info', title: undo, ms: 5000, action: { label: 'Undo', fn: function () { if (done) return; done = true; clearTimeout(t); pending = pending.filter(function (p) { return p !== commit; }); restore(); } } });
    else commit(false);
  }
  addEventListener('pagehide', function () { pending.slice().forEach(function (c) { c(true); }); });
  d.addEventListener('visibilitychange', function () { if (d.visibilityState === 'hidden') pending.slice().forEach(function (c) { c(true); }); });

  function init(row) {
    if (row._swipe) return; row._swipe = true; row._c = row.querySelector('.swipe-row__content'); row._x = 0; if (!row._c) return;
    var A = { start: row.querySelector('.swipe-row__actions--start'), end: row.querySelector('.swipe-row__actions--end') };
    function measure() { row._sw = A.start ? A.start.scrollWidth : 0; row._ew = A.end ? A.end.scrollWidth : 0; row._w = row.clientWidth; }
    measure(); setTab(row, false);
    var thr = num(row, 'threshold', 0.4), full = num(row, 'full', 0.7), res = num(row, 'resistance', 0.35), vel = num(row, 'velocity', 0.5), mouse = row.hasAttribute('data-mouse');
    var sx = 0, sy = 0, x0 = 0, lx = 0, lt = 0, v = 0, mode = 0, id = null; // mode: 0 idle, 1 undecided, 2 dragging, -1 ignore
    row._c.addEventListener('pointerdown', function (e) {
      if (row.hasAttribute('data-disabled') || (e.pointerType === 'mouse' && !mouse) || (e.pointerType === 'mouse' && e.button !== 0)) return;
      if (e.target.closest('input,select,textarea,[data-no-swipe]')) return;
      measure(); id = e.pointerId; sx = lx = e.clientX; sy = e.clientY; x0 = row._x; lt = e.timeStamp; v = 0; mode = 1;
      if (openRow && openRow !== row) close(openRow);
    });
    row._c.addEventListener('pointermove', function (e) {
      if (e.pointerId !== id || mode < 1) return; var dx = e.clientX - sx, dy = e.clientY - sy;
      if (mode === 1) { if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return; if (Math.abs(dy) > Math.abs(dx)) { mode = -1; return; } mode = 2; try { row._c.setPointerCapture(id); } catch (x) {} row.classList.add('is-dragging'); }
      if (mode !== 2) return; var dt = e.timeStamp - lt; if (dt > 0) v = (e.clientX - lx) / dt; lx = e.clientX; lt = e.timeStamp;
      var x = x0 + dx, max = row._sw, min = -row._ew; // rubber band past the ends (and where no actions exist)
      if (x > max) x = max + (x - max) * res; else if (x < min) x = min + (x - min) * res;
      setX(row, x, false); if (e.cancelable) e.preventDefault();
    });
    function end(e) {
      if (e.pointerId !== id) return; var m = mode; mode = 0; id = null; row.classList.remove('is-dragging'); if (m !== 2) return;
      var x = row._x, W = row._w || row.clientWidth, side = x > 0 ? 'start' : 'end', w = side === 'start' ? row._sw : row._ew, a = Math.abs(x);
      var primary = A[side] && A[side].querySelector('button'); row._swallow = true; setTimeout(function () { row._swallow = false; }, 60);
      if (w && primary && full > 0 && a > W * full && !(side === 'start' ? v < -vel : v > vel)) { setX(row, side === 'start' ? W : -W, true); setTimeout(function () { run(primary, row); }, reduce ? 0 : 140); return; }
      var flick = side === 'start' ? v > vel : v < -vel, back = side === 'start' ? v < -vel : v > vel;
      if (w && !back && (flick || a > w * thr)) open(row, side); else close(row);
    }
    row._c.addEventListener('pointerup', end); row._c.addEventListener('pointercancel', end);
    row._c.addEventListener('click', function (e) { if (row._swallow) { e.preventDefault(); e.stopPropagation(); return; } if (row.getAttribute('data-open')) { e.preventDefault(); e.stopPropagation(); close(row); return; }
      // a plain tap reveals the actions too (discoverable, and the only way for people who cannot swipe); taps on links, buttons and fields behave normally
      if (row.hasAttribute('data-notap') || e.target.closest('a,button,input,select,textarea,label,summary,[data-no-swipe]') || !(e.pointerType === 'touch' || e.detail === 1 && matchMedia('(hover: none)').matches)) return;
      var side = A.start ? 'start' : (A.end ? 'end' : ''); if (side) open(row, side); }, true);
    row._c.addEventListener('keydown', function (e) {
      if (e.target !== row._c) return;
      if (e.key === 'ArrowLeft') { e.preventDefault(); if (row.getAttribute('data-open') === 'start') close(row); else open(row, 'end'); }
      else if (e.key === 'ArrowRight') { e.preventDefault(); if (row.getAttribute('data-open') === 'end') close(row); else open(row, 'start'); }
      else if (e.key === 'Escape') close(row);
    });
    row.addEventListener('click', function (e) { var b = e.target.closest('.swipe-row__actions button'); if (!b) return; var undo = b.getAttribute('data-swipe-undo'); if (undo) { e.preventDefault(); run(b, row); } else if (!b.hasAttribute('data-open')) close(row); });
    if (window.ResizeObserver) new ResizeObserver(measure).observe(row);
  }
  function scan(root) { [].forEach.call((root || d).querySelectorAll('[data-swipe]'), init); }
  d.addEventListener('pointerdown', function (e) { if (openRow && !openRow.contains(e.target)) close(openRow); }, true);
  scan(); d.addEventListener('mfnc:page', function () { openRow = null; scan(); });
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', function () { scan(); });
  window.SwipeRow = { scan: scan, close: function () { close(openRow); } };
})();
