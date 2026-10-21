/* Account menu: a vanilla port of the KokonutUI "Account Menu" (React + motion). Same structure and behaviour:
   - the panel grows out of the trigger's own rectangle (two clip-paths: border + fill; spring 0.3 s, no bounce) and shrinks back into it;
   - avatar, name and e-mail are shared between the trigger and the panel header and travel between the two (FLIP, the same spring);
   - two pages, "main" and "accounts" (the accounts page only exists when the server renders it; this app has one account per sign-in, so it does not);
   - Status radios, Theme radios (sliding indicator, spring 0.25 s), link items, hold-to-confirm Sign out (inverted copy revealed left to right);
   - keyboard: ArrowUp/Down move between rows, ArrowLeft/Right between radios, Home/End, Escape and Tab close (focus goes back to the trigger),
     Backspace / ArrowLeft leave the accounts page, ArrowDown/ArrowUp on the trigger open it, hovering an item focuses it, a keyboard open/close skips the animation;
   - prefers-reduced-motion: no morph, no travelling, no sliding indicator.
   Wired to the app: Status -> POST /do/presence-set (do not disturb has real effects, see app/acctmenu.php), Theme -> the same storage and /do/theme-quick as the top-bar
   toggle (System follows prefers-color-scheme), Sign out -> the normal POST /do/logout with the CSRF token.
   Pure helpers (arrow navigation, clip strings) are exposed as MfAccountMenu.helpers for tests. */
(function (root, factory) {
  var api = factory(root);
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else { root.MfAccountMenu = api; if (root.document) api.boot(); }
})(typeof window !== 'undefined' ? window : globalThis, function (w) {
  'use strict';
  var RADIUS = 16, PANEL_W = 288, NAV = { ArrowDown: 1, ArrowUp: 1, ArrowLeft: 1, ArrowRight: 1, Home: 1, End: 1 };
  var SPRING = { type: 'spring', duration: 0.3 }, ISPRING = { type: 'spring', duration: 0.25 }, EASE_OUT = [0.23, 1, 0.32, 1];

  // ---------------------------------------------------------------- pure helpers (the keyboard model of the original)
  function sideTarget(items, current, step) {
    var row = items.filter(function (i) { return i.dataset.row === current.dataset.row; }); if (row.length < 2) return;
    var idx = row.indexOf(current); return row[(idx + step + row.length) % row.length];
  }
  function rowTarget(items, current, step) {
    var rows = []; items.forEach(function (i) { if (rows.indexOf(i.dataset.row) < 0) rows.push(i.dataset.row); });
    var idx = rows.indexOf(current.dataset.row), next = rows[(idx + step + rows.length) % rows.length];
    var cands = items.filter(function (i) { return i.dataset.row === next; });
    return cands.filter(function (i) { return i.getAttribute('aria-checked') === 'true'; })[0] || cands[0];
  }
  /** Where arrow keys move focus: rows go up and down, radios left and right. */
  function nextTarget(items, active, key) {
    if (key === 'Home') return items[0]; if (key === 'End') return items[items.length - 1];
    var current = items.filter(function (i) { return i === active; })[0];
    if (!current) return key === 'ArrowUp' ? items[items.length - 1] : items[0];
    if (key === 'ArrowLeft' || key === 'ArrowRight') return sideTarget(items, current, key === 'ArrowRight' ? 1 : -1);
    return rowTarget(items, current, key === 'ArrowDown' ? 1 : -1);
  }
  /** The two clip-path strings: outer = 1px border layer, inner = fill + content. align 'end' pins the top-right corner, 'start' the top-left. */
  function clips(w_, h_, r_, align) {
    if (align === 'start') return {
      outer: 'inset(0px calc(100% - ' + w_ + 'px) calc(100% - ' + h_ + 'px) 0px round ' + r_ + 'px)',
      inner: 'inset(1px calc(100% - ' + w_ + 'px + 1px) calc(100% - ' + h_ + 'px + 1px) 1px round ' + Math.max(0, r_ - 1) + 'px)' };
    return {
      outer: 'inset(0px 0px calc(100% - ' + h_ + 'px) calc(100% - ' + w_ + 'px) round ' + r_ + 'px)',
      inner: 'inset(1px 1px calc(100% - ' + h_ + 'px + 1px) calc(100% - ' + w_ + 'px + 1px) round ' + Math.max(0, r_ - 1) + 'px)' };
  }
  var helpers = { nextTarget: nextTarget, clips: clips, sideTarget: sideTarget, rowTarget: rowTarget };
  if (!w.document) return { helpers: helpers, boot: function () {} };

  var d = w.document, root = d.documentElement, M = w.MfMotion;
  var uidSeq = 0, instances = [];
  var mq = w.matchMedia ? w.matchMedia('(prefers-color-scheme: dark)') : null;
  function reduced() { return !!(M && M.reduced()) || !M; }
  function ls(k, v) { try { if (v === undefined) return w.localStorage.getItem(k); w.localStorage.setItem(k, v); } catch (e) { } return null; }
  function viaOf(e) { return e && e.detail === 0 ? 'keyboard' : 'pointer'; }
  function items(el) { return el ? [].slice.call(el.querySelectorAll('[data-menu-item]')) : []; }
  function toast(o) { if (w.toast) w.toast(o); }
  function postForm(url, data) {
    var f = new FormData(); f.append('_csrf', d.body.getAttribute('data-csrf') || ''); Object.keys(data).forEach(function (k) { f.append(k, data[k]); });
    return fetch(url, { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-Requested-With': 'mfnc' } });
  }

  // ---------------------------------------------------------------- theme + status (shared by every instance on the page)
  function themeNow() { return ls('mfnc-theme') || root.getAttribute('data-theme') || 'system'; }
  function applyTheme(t) { root.classList.toggle('dark', t === 'dark' || (t === 'system' && !!(mq && mq.matches))); root.setAttribute('data-theme', t); }
  function syncAll() { instances.forEach(function (i) { i.syncRows(); }); }
  function setTheme(t, url) {
    ls('mfnc-theme', t); applyTheme(t); syncAll();
    if (url && navigator.onLine) { postForm(url, { theme: t }).catch(function () { }); }
  }
  if (mq && mq.addEventListener) mq.addEventListener('change', function () { if (themeNow() === 'system') applyTheme('system'); });
  function setStatus(st, url, prev) {
    d.body.setAttribute('data-presence', st); syncAll();
    if (url) postForm(url, { status: st }).then(function (r) { if (!r.ok && r.status >= 400) throw new Error('status'); }).catch(function () {
      d.body.setAttribute('data-presence', prev); syncAll(); toast({ type: 'err', title: 'Could not save your status', text: 'Check your connection and try again.' });
    });
    if (st === 'busy') toast({ type: 'info', title: 'Do not disturb is on', text: 'No pop-ups, and no email, SMS or Telegram reminders except overdue payments.' });
    else if (prev === 'busy') toast({ type: 'ok', title: st === 'away' ? 'You are away' : 'You are online', text: 'Reminders are back on.' });
  }

  // ---------------------------------------------------------------- one menu
  function AccountMenu(el) {
    var s = this; s.el = el; s.trigger = el.querySelector('.am__trigger'); s.layer = el.querySelector('.am__layer'); s.clip = el.querySelector('.am__clip'); s.pages = el.querySelector('.am__pages');
    s.outer = el.querySelector('.am__outer'); s.inner = el.querySelector('.am__inner'); s.main = el.querySelector('[data-page=main]'); s.acct = el.querySelector('[data-page=accounts]'); s.mainMenu = s.main.querySelector('[role=menu]');
    s.align = el.getAttribute('data-align') === 'start' ? 'start' : 'end'; s.themeUrl = el.getAttribute('data-theme-url'); s.statusUrl = el.getAttribute('data-status-url');
    s.phase = 'closed'; s.page = 'main'; s.instant = false; s.closeSeq = 0; s.targetH = 0; s.panelW = PANEL_W; s.intent = { focus: 'menu', resume: false }; s.pageFocus = null; s.thumbMv = M ? M.value(0) : null;
    s.w = M.value(0); s.h = M.value(0); s.r = M.value(RADIUS); s.px = M.value(0); s.op = M.value(1);
    var uid = 'am' + (++uidSeq); s.mainMenu.id = uid + '-menu'; s.trigger.setAttribute('aria-controls', s.mainMenu.id);
    s.fades = [].slice.call(el.querySelectorAll('[data-am-fade]')); s.hold = null;
    var draw = function () { var c = clips(Math.round(s.w.get() * 100) / 100, Math.round(s.h.get() * 100) / 100, s.r.get(), s.align); s.outer.style.clipPath = c.outer; s.inner.style.clipPath = c.inner; s.clip.style.clipPath = c.inner; };
    s.w.on(draw); s.h.on(draw); s.r.on(draw); s.px.on(function (v) { s.pages.style.transform = 'translate3d(' + v + 'px,0,0)'; }); s.op.on(function (v) { s.fades.forEach(function (f) { f.style.opacity = v; }); });
    s.trigger.setAttribute('role', 'button');
    s.trigger.addEventListener('click', function (e) { e.preventDefault(); var via = viaOf(e); s.open(via, via === 'keyboard' ? 'first' : 'menu'); });
    s.trigger.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); s.open('keyboard', e.key === 'ArrowDown' ? 'first' : 'last'); }
      else if (e.key === ' ') { e.preventDefault(); s.open('keyboard', 'first'); }
    });
    s.clip.addEventListener('keydown', function (e) { s.onKey(e); });
    s.clip.addEventListener('pointermove', function (e) { if (e.pointerType !== 'mouse') return; var it = e.target.closest && e.target.closest('[data-menu-item]'); if (it && d.activeElement !== it) it.focus({ preventScroll: true }); });
    s.clip.addEventListener('click', function (e) { s.onClick(e); });
    s.onDown = function (e) { if (!s.el.contains(e.target) && !s.layer.contains(e.target)) s.close('pointer', false); };
    s.onResize = function () { if (s.phase === 'open') { s.place(); s.fit(true); } };
    s.onScroll = function () { if (s.phase === 'open') s.close('keyboard', false); };
    if (s.acct) s.acct.inert = true;
    var hold = s.mainMenu.querySelector('[data-hold]'); if (hold) s.setupHold(hold);
    s.syncRows(); s.setThumb(false);
  }
  AccountMenu.prototype.syncRows = function () {
    var s = this, st = d.body.getAttribute('data-presence') || 'online', th = themeNow();
    s.el.setAttribute('data-status', st); [].forEach.call(s.layer.querySelectorAll('[data-status-set]'), function (b) { b.setAttribute('aria-checked', b.getAttribute('data-status-set') === st ? 'true' : 'false'); });
    [].forEach.call(s.layer.querySelectorAll('[data-theme-set]'), function (b) { b.setAttribute('aria-checked', b.getAttribute('data-theme-set') === th ? 'true' : 'false'); });
    s.setThumb(s.phase === 'open' && !reduced());
  };
  AccountMenu.prototype.setThumb = function (animate) {
    var s = this, seg = s.layer.querySelector('[data-am-seg]'); if (!seg) return; var thumb = seg.querySelector('.am__thumb'), on = seg.querySelector('[aria-checked=true]'); if (!thumb || !on || !seg.offsetWidth) return;
    if (!s.thumbBound) { s.thumbBound = true; s.thumbMv.on(function (v) { thumb.style.transform = 'translateX(' + v + 'px)'; }); }
    thumb.style.width = on.offsetWidth + 'px'; var to = on.offsetLeft;
    if (animate && !reduced()) M.animate(s.thumbMv, to, ISPRING); else { s.thumbMv.jump(to); thumb.style.transform = 'translateX(' + to + 'px)'; }
  };
  /** Size and place the (fixed) panel next to the trigger, inside the viewport. */
  AccountMenu.prototype.place = function () {
    var s = this, tr = s.trigger.getBoundingClientRect(), vw = root.clientWidth, vh = w.innerHeight, pw = Math.min(PANEL_W, vw - 16), left;
    s.panelW = pw; s.layer.style.setProperty('--am-w', pw + 'px');
    left = s.align === 'start' ? tr.left : tr.right - pw; left = Math.max(8, Math.min(vw - 8 - pw, left));
    var top = Math.max(0, tr.top); s.layer.style.left = left + 'px'; s.layer.style.top = top + 'px'; s.layer.style.setProperty('--am-maxh', Math.max(180, vh - top - 8) + 'px');
  };
  AccountMenu.prototype.pageEl = function (p) { return p === 'main' ? this.main : this.acct; };
  AccountMenu.prototype.fit = function (animateIt) {
    var s = this, el = s.pageEl(s.page); if (!el) return; var maxh = parseFloat(getComputedStyle(s.layer).getPropertyValue('--am-maxh')) || 9999, next = Math.min(el.offsetHeight, maxh);
    if (Math.abs(next - s.targetH) < 0.5) return; s.targetH = next; if (animateIt && !reduced()) M.animate(s.h, next, SPRING); else s.h.jump(next);
  };

  // identity pieces travel between the trigger and the header (FLIP: measure both places, start from the old one, spring to the new one)
  AccountMenu.prototype.pieces = function (scope) {
    return { av: scope.querySelector('[data-am-avwrap]'), name: scope.querySelector('[data-am-name]'), email: scope.querySelector('[data-am-email]') };
  };
  AccountMenu.prototype.rects = function (p) { var o = {}; Object.keys(p).forEach(function (k) { o[k] = p[k] ? p[k].getBoundingClientRect() : null; }); return o; };
  AccountMenu.prototype.travel = function (toEls, fromRects, instant) {
    var s = this; if (s.travelRun) { s.travelRun.forEach(function (r) { r.mv.stop(); r.el.style.transform = ''; r.el.style.opacity = ''; }); } s.travelRun = [];
    Object.keys(toEls).forEach(function (k) {
      var el = toEls[k], f = fromRects[k]; if (!el) return; var l = el.getBoundingClientRect();
      if (instant || reduced()) return;
      if (!f || !f.width || !f.height) { if (l.width) { el.style.opacity = '0'; var mo = M.value(0); mo.on(function (v) { el.style.opacity = v; }); setTimeout(function () { M.animate(mo, 1, { duration: 0.2, ease: EASE_OUT }).finished.then(function () { el.style.opacity = ''; }); }, 60); } return; }
      var dx = f.left - l.left, dy = f.top - l.top, sx = k === 'av' && l.width ? f.width / l.width : 1, sy = k === 'av' && l.height ? f.height / l.height : 1;
      var mv = M.value(1); el.style.transformOrigin = '0 0'; var run = { el: el, mv: mv }; s.travelRun.push(run);
      var paint = function (p) { el.style.transform = 'translate(' + (dx * p).toFixed(2) + 'px,' + (dy * p).toFixed(2) + 'px) scale(' + (1 + (sx - 1) * p).toFixed(4) + ',' + (1 + (sy - 1) * p).toFixed(4) + ')'; };
      paint(1); mv.on(paint); M.animate(mv, 0, SPRING).finished.then(function () { if (mv.get() === 0) { el.style.transform = ''; el.style.transformOrigin = ''; } });
    });
  };

  AccountMenu.prototype.open = function (via, focus) {
    var s = this; if (s.phase === 'open') return;
    var resume = s.phase === 'closing'; s.intent = { focus: focus, resume: resume }; s.closeSeq++;
    s.instant = via === 'keyboard' || reduced(); s.page = 'main'; s.syncRows(); s.setPages();
    var firstRects = s.rects(s.pieces(s.trigger)), tr = s.trigger.getBoundingClientRect(), r0 = parseFloat(getComputedStyle(s.trigger).borderTopLeftRadius) || RADIUS;
    s.phase = 'open'; s.el.classList.add('is-open'); s.el.classList.remove('is-closing'); s.layer.classList.add('is-open'); s.layer.classList.remove('is-closing'); s.trigger.setAttribute('aria-expanded', 'true');
    d.body.appendChild(s.layer); s.layer.hidden = false;   // the panel lives in <body> while it is open: no ancestor (sticky top bar, transformed drawer) can clip it or sit above it
    d.addEventListener('pointerdown', s.onDown, true); w.addEventListener('resize', s.onResize); w.addEventListener('scroll', s.onScroll, { passive: true });
    s.place(); s.targetH = 0; s.px.jump(0); s.fitOpen(tr, r0, resume);
    s.setThumb(false);
    s.travel(s.pieces(s.main.querySelector('.am__head')), firstRects, s.instant);
    var list = items(s.main), t = { first: list[0], last: list[list.length - 1], menu: s.mainMenu }[s.intent.focus]; if (t) t.focus({ preventScroll: true });
    if (!s.ro && w.ResizeObserver) { s.ro = new w.ResizeObserver(function () { if (s.phase === 'open') s.fit(true); }); [s.main, s.acct].forEach(function (p) { if (p) s.ro.observe(p); }); }
  };
  AccountMenu.prototype.fitOpen = function (tr, r0, resume) {
    var s = this, maxh = parseFloat(getComputedStyle(s.layer).getPropertyValue('--am-maxh')) || 9999; s.targetH = Math.min(s.main.offsetHeight, maxh);
    if (s.instant) { s.w.jump(s.panelW); s.h.jump(s.targetH); s.r.jump(RADIUS); s.op.jump(1); return; }
    if (!s.intent.resume) { s.w.jump(tr.width); s.h.jump(tr.height); s.r.jump(Math.min(r0, RADIUS * 2)); s.op.jump(0); }
    M.animate(s.w, s.panelW, SPRING); M.animate(s.h, s.targetH, SPRING); M.animate(s.r, RADIUS, SPRING);
    var seq = s.closeSeq; setTimeout(function () { if (s.closeSeq === seq && s.phase === 'open') M.animate(s.op, 1, { duration: 0.2, ease: EASE_OUT }); }, 50);
  };
  AccountMenu.prototype.close = function (via, returnFocus) {
    var s = this; if (s.phase !== 'open') return; var trigger = s.trigger;
    d.removeEventListener('pointerdown', s.onDown, true); w.removeEventListener('resize', s.onResize); w.removeEventListener('scroll', s.onScroll);
    if (returnFocus) trigger.focus({ preventScroll: true });
    s.trigger.setAttribute('aria-expanded', 'false');
    function done() { s.phase = 'closed'; s.layer.hidden = true; s.layer.classList.remove('is-open', 'is-closing'); s.el.classList.remove('is-open', 'is-closing'); s.el.appendChild(s.layer); s.page = 'main'; s.setPages(); }
    if (via === 'keyboard' || reduced()) { s.travel({}, {}, true); done(); return; }
    var headRects = s.rects(s.pieces(s.main.querySelector('.am__head'))); s.phase = 'closing'; s.closeSeq++; var seq = s.closeSeq;
    s.el.classList.remove('is-open'); s.el.classList.add('is-closing'); s.layer.classList.remove('is-open'); s.layer.classList.add('is-closing');
    s.travel(s.pieces(trigger), headRects, false);
    M.animate(s.op, 0, { duration: 0.12, ease: EASE_OUT }); M.animate(s.r, Math.min(parseFloat(getComputedStyle(trigger).borderTopLeftRadius) || RADIUS, RADIUS * 2), SPRING); M.animate(s.w, trigger.offsetWidth, SPRING);
    M.animate(s.h, trigger.offsetHeight, SPRING).finished.then(function () { if (s.closeSeq === seq && s.phase === 'closing') done(); });
  };
  AccountMenu.prototype.setPages = function () {
    var s = this; [['main', s.main], ['accounts', s.acct]].forEach(function (x) { var el = x[1]; if (!el) return; var off = s.page !== x[0]; el.classList.toggle('is-off', off); el.inert = off; if (off) el.setAttribute('aria-hidden', 'true'); else el.removeAttribute('aria-hidden'); });
  };
  AccountMenu.prototype.goTo = function (target) {
    var s = this; if (!s.pageEl(target) || s.page === target) return; s.pageFocus = target === 'accounts' ? 'first' : 'switch'; s.page = target; s.setPages();
    var el = s.pageEl(target), x = target === 'main' ? 0 : -s.panelW, maxh = parseFloat(getComputedStyle(s.layer).getPropertyValue('--am-maxh')) || 9999; s.targetH = Math.min(el.offsetHeight, maxh);
    if (reduced()) { s.px.jump(x); s.h.jump(s.targetH); } else { M.animate(s.px, x, SPRING); M.animate(s.h, s.targetH, SPRING); }
    var intent = s.pageFocus; s.pageFocus = null;
    if (intent === 'first') { var list = items(el), checked = list.filter(function (i) { return i.getAttribute('aria-checked') === 'true'; })[0]; (checked || list[0]) && (checked || list[0]).focus({ preventScroll: true }); }
    else if (intent === 'switch') { var sw = el.querySelector('[data-switch]'); if (sw) sw.focus({ preventScroll: true }); }
  };
  AccountMenu.prototype.onKey = function (e) {
    var s = this;
    if (e.key === 'Escape' || e.key === 'Tab') { e.preventDefault(); s.close('keyboard', true); return; }
    if (s.page === 'accounts' && e.key === 'Backspace') { e.preventDefault(); s.goTo('main'); return; }
    if (!NAV[e.key]) return; e.preventDefault();
    var active = d.activeElement, target = nextTarget(items(s.pageEl(s.page)), active, e.key);
    if (target) target.focus({ preventScroll: true });
    else if (s.page === 'accounts' && e.key === 'ArrowLeft') s.goTo('main');
    else if (e.key === 'ArrowRight' && active && active.hasAttribute && active.hasAttribute('data-switch')) s.goTo('accounts');
  };
  AccountMenu.prototype.onClick = function (e) {
    var s = this, t = e.target.closest ? e.target.closest('[data-menu-item]') : null; if (!t) return;
    if (t.hasAttribute('data-status-set')) { var prev = d.body.getAttribute('data-presence') || 'online', st = t.getAttribute('data-status-set'); if (st !== prev) setStatus(st, s.statusUrl, prev); return; }
    if (t.hasAttribute('data-theme-set')) { setTheme(t.getAttribute('data-theme-set'), s.themeUrl); return; }
    if (t.hasAttribute('data-hold')) { e.preventDefault(); return; }
    if (t.hasAttribute('data-switch')) { s.goTo('accounts'); return; }
    if (t.hasAttribute('data-back')) { s.goTo('main'); return; }
    if (t.hasAttribute('data-account')) { var url = t.getAttribute('data-account-url'); if (url) { w.location.href = url; } else s.goTo('main'); return; }
    s.close(viaOf(e), true); // a link: close with the morph and let the navigation carry on
  };

  // hold-to-confirm Sign out: press and hold (mouse, touch, Enter or Space) until the inverted copy has filled the row
  AccountMenu.prototype.setupHold = function (btn) {
    var s = this, duration = parseInt(btn.getAttribute('data-hold'), 10) || 1200, timer = 0, nudgeT = 0, done = false, hints = btn.querySelectorAll('[data-hold-hint]');
    btn.style.setProperty('--hold', duration + 'ms');
    function hint(t) { [].forEach.call(hints, function (h) { h.textContent = t; }); }
    function start(via) {
      if (timer || done) return; clearTimeout(nudgeT); btn.classList.remove('is-nudge'); hint('Hold'); btn.classList.add('is-holding');
      timer = setTimeout(function () {
        timer = 0; btn.classList.remove('is-holding'); btn.classList.add('is-done'); done = true; if (w.navigator.vibrate) { try { w.navigator.vibrate(14); } catch (e) { } }
        var f = s.el.querySelector('form[data-logout]'); s.close(via, true); if (f) { setTimeout(function () { f.submit(); }, via === 'pointer' && !reduced() ? 180 : 0); }
      }, duration);
    }
    function cancel() {
      if (!timer) return; clearTimeout(timer); timer = 0; btn.classList.remove('is-holding'); btn.classList.add('is-nudge'); hint('Keep holding');
      nudgeT = setTimeout(function () { btn.classList.remove('is-nudge'); hint('Hold'); }, 1400);
    }
    btn.addEventListener('pointerdown', function (e) { if (e.button !== 0) return; try { btn.setPointerCapture(e.pointerId); } catch (x) { } start('pointer'); });
    btn.addEventListener('pointerup', cancel); btn.addEventListener('pointercancel', cancel); btn.addEventListener('blur', cancel);
    btn.addEventListener('contextmenu', function (e) { e.preventDefault(); });
    btn.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); if (!e.repeat) start('keyboard'); } });
    btn.addEventListener('keyup', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); cancel(); } });
    s.hold = { reset: function () { clearTimeout(timer); timer = 0; done = false; btn.classList.remove('is-holding', 'is-done', 'is-nudge'); hint('Hold'); } };
  };

  function scan() {
    instances = instances.filter(function (i) { if (i.el.isConnected) return true; try { i.close('keyboard', false); } catch (e) { } if (i.layer.parentNode) i.layer.parentNode.removeChild(i.layer); return false; });
    [].forEach.call(d.querySelectorAll('[data-acctmenu]'), function (el) { if (el._am) return; if (!M) return; el._am = new AccountMenu(el); instances.push(el._am); });
    syncAll();
  }
  function boot() {
    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', scan); else scan();
    d.addEventListener('mfnc:page', scan);
    // the top-bar toggle (pwa.js) flips light / dark: keep the Theme row in step with it
    d.addEventListener('click', function (e) { if (e.target.closest && e.target.closest('[data-theme-toggle]')) setTimeout(syncAll, 30); });
    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', function () { applyThemeIfSystem(); }); else applyThemeIfSystem();
  }
  function applyThemeIfSystem() { if (themeNow() === 'system') applyTheme('system'); }
  return { helpers: helpers, boot: boot, scan: scan, _instances: function () { return instances; } };
});
