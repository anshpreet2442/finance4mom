/* Moms Finance: PWA layer. Toasts, offline capture + sync, install prompt, splash / pull-to-refresh, theme, intro. */
(function () {
  'use strict';
  var d = document, root = d.documentElement, body = d.body;
  var BASE = body.getAttribute('data-base') || '';
  var $ = function (s, r) { return (r || d).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); };
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var standalone = (window.matchMedia && matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;

  // ---------------------------------------------------------------- toasts
  var ICONS = {
    err: '<svg viewBox="0 0 24 24" width="26" height="26" fill="currentColor"><path d="M10.3 3.6 1.9 18.2A2 2 0 0 0 3.6 21h16.8a2 2 0 0 0 1.7-2.8L13.7 3.6a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4.5M12 17h.01" stroke="#fff" stroke-width="2.4" stroke-linecap="round" fill="none"/></svg>',
    info: '<svg viewBox="0 0 24 24" width="30" height="30" fill="currentColor"><circle cx="12" cy="12" r="10"/><path d="M12 11v5.5M12 7.6h.01" stroke="#fff" stroke-width="2.6" stroke-linecap="round" fill="none"/></svg>',
    ok: '<svg viewBox="0 0 24 24" width="30" height="30" fill="currentColor"><circle cx="12" cy="12" r="10"/><path d="m7.6 12.4 3 3 5.8-6.4" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" fill="none"/></svg>',
    neutral: '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7 10l5 5 5-5M5 20h14"/></svg>',
    warn: '<svg viewBox="0 0 24 24" width="26" height="26" fill="currentColor"><path d="M10.3 3.6 1.9 18.2A2 2 0 0 0 3.6 21h16.8a2 2 0 0 0 1.7-2.8L13.7 3.6a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4.5M12 17h.01" stroke="#fff" stroke-width="2.4" stroke-linecap="round" fill="none"/></svg>',
    offline: '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M2 2l20 20M8.5 16.4a5 5 0 0 1 7 0M2 8.8a15 15 0 0 1 4.2-2.7M10.7 5.1A15 15 0 0 1 22 8.8M5 12.9a10 10 0 0 1 5.2-2.7M19 12.9a10 10 0 0 0-2.6-1.9M12 20h.01"/></svg>'
  };
  var X = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';
  var wrap = $('#toasts');
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  /** toast({type:'ok|err|info|neutral|warn', title, text, action:{label, fn|href}, ms, icon}) */
  function toast(o) {
    if (!wrap) return null;
    var type = o.type || 'info', ms = o.ms === undefined ? (type === 'err' ? 9000 : 6000) : o.ms;
    var el = d.createElement('div'); el.className = 'toast toast-' + type; el.setAttribute('role', type === 'err' ? 'alert' : 'status');
    el.style.setProperty('--dur', ms + 'ms');
    el.innerHTML = '<div class="toast-ic">' + (ICONS[o.icon || type] || ICONS.info) + '</div><div class="toast-body"><div class="toast-title">' + esc(o.title || '') + '</div>' + (o.text ? '<div class="toast-text">' + esc(o.text) + '</div>' : '') +
      (o.action ? '<button type="button" class="toast-act">' + esc(o.action.label) + '</button>' : '') + '</div><button type="button" class="toast-x" aria-label="Dismiss">' + X + '</button>' + (ms ? '<span class="toast-bar"></span>' : '');
    wrap.appendChild(el);
    var timer = null, left = ms, started = 0;
    function close() { clearTimeout(timer); if (el.classList.contains('is-leaving')) return; el.classList.add('is-leaving'); setTimeout(function () { el.remove(); }, 320); }
    function arm() { if (!ms) return; started = Date.now(); timer = setTimeout(close, left); }
    el.addEventListener('mouseenter', function () { if (ms) { clearTimeout(timer); left -= Date.now() - started; } });
    el.addEventListener('mouseleave', arm);
    $('.toast-x', el).addEventListener('click', close);
    var act = $('.toast-act', el); if (act) act.addEventListener('click', function () { if (o.action.href) location.href = o.action.href; else if (o.action.fn) o.action.fn(); close(); });
    while (wrap.children.length > 4) wrap.firstChild.remove();
    arm(); return { close: close };
  }
  window.toast = toast;
  var fd = $('#flash-data');
  if (fd) { try { JSON.parse(fd.textContent).forEach(function (f, i) { setTimeout(function () { toast({ type: f.type, title: f.title, text: f.text }); }, i * 160); }); } catch (e) {} }

  // ---------------------------------------------------------------- splash + expressive reload
  var splash = $('#splash');
  function hideSplash(minMs) { if (!splash) return; var wait = Math.max(0, minMs - (performance.now() | 0)); setTimeout(function () { splash.classList.add('is-done'); root.classList.remove('has-splash'); setTimeout(function () { if (splash) splash.remove(); }, 700); }, reduce ? 0 : wait); }
  if (splash) { if (root.classList.contains('has-splash')) hideSplash(sessionStorage.getItem('mfnc-reload') ? 650 : 1100); else splash.remove(); sessionStorage.removeItem('mfnc-reload'); try { sessionStorage.setItem('mfnc-seen', '1'); } catch (e) {} }
  function expressiveReload() { try { sessionStorage.setItem('mfnc-reload', '1'); } catch (e) {} location.reload(); }
  window.mfncReload = expressiveReload;
  d.addEventListener('click', function (e) { if (e.target.closest('[data-reload]')) expressiveReload(); });
  var main = $('#main'); if (main && !reduce && !('onpagereveal' in window)) main.classList.add('page-in'); // browsers with view transitions animate pages themselves

  // pull to refresh (touch devices, at the top of the page, no dialog open)
  (function () {
    if (!('ontouchstart' in window)) return;
    var el = d.createElement('div'); el.className = 'ptr'; el.setAttribute('aria-hidden', 'true');
    el.innerHTML = '<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/></svg>'; body.appendChild(el);
    var y0 = null, pull = 0, busy = false;
    function set(v) { pull = v; el.style.setProperty('--pull', v + 'px'); el.style.setProperty('--o', Math.min(1, v / 50)); el.style.setProperty('--s', 0.6 + Math.min(0.4, v / 200)); }
    d.addEventListener('touchstart', function (e) { if (busy || window.scrollY > 0 || d.querySelector('dialog[open]') || e.touches.length !== 1) { y0 = null; return; } y0 = e.touches[0].clientY; }, { passive: true });
    d.addEventListener('touchmove', function (e) { if (y0 === null || busy) return; var dy = e.touches[0].clientY - y0; if (dy > 0 && window.scrollY <= 0) set(Math.min(110, dy * 0.55)); else if (dy < 0) { set(0); y0 = null; } }, { passive: true });
    d.addEventListener('touchend', function () { if (y0 === null || busy) return; y0 = null; if (pull >= 62) { busy = true; el.classList.add('is-loading'); setTimeout(expressiveReload, 550); } else set(0); });
  })();

  // ---------------------------------------------------------------- theme + palette
  function applyTheme(dark) { root.classList.toggle('dark', dark); var m = $('meta[name=theme-color]'); }
  d.addEventListener('click', function (ev) {
    if (ev.target.closest('[data-theme-toggle]')) (function () {
      var dark = !root.classList.contains('dark'); applyTheme(dark); try { localStorage.setItem('mfnc-theme', dark ? 'dark' : 'light'); } catch (e) {}
      var csrf = body.getAttribute('data-csrf'); if (csrf && navigator.onLine) { var f = new FormData(); f.append('_csrf', csrf); f.append('theme', dark ? 'dark' : 'light'); fetch(BASE + '/do/theme-quick', { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-Requested-With': 'mfnc' } }).catch(function () {}); }
    })();
  });
  function syncPalette() { var cur = 'auto'; try { cur = localStorage.getItem('mfnc-palette') || 'auto'; } catch (e) {} $$('[data-palette-set]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-palette-set') === cur ? 'true' : 'false'); }); $$('.palette-jelly').forEach(function (g) { var i = $$('.jelly-radio__chip', g).findIndex(function (c) { return c.getAttribute('data-palette-set') === cur; }); if (i >= 0 && g.__jelly) g.__jelly.select(i, true); }); }
  d.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-palette-set]'); if (!b) return; var v = b.getAttribute('data-palette-set'); try { localStorage.setItem('mfnc-palette', v); } catch (e) {} root.setAttribute('data-palette', v);
    toast({ type: 'ok', title: 'Colours updated', text: v === 'ember' ? 'Ember palette on every screen.' : v === 'lime' ? 'Lime palette on every screen.' : 'Ember on phones, lime on larger screens.' });
  });

  // ---------------------------------------------------------------- connection banner
  var bar = $('#netbar'), wasOffline = !navigator.onLine;
  function showBar(txt, ok) { if (!bar) return; bar.textContent = txt; bar.classList.toggle('is-ok', !!ok); bar.classList.add('is-on'); if (ok) setTimeout(function () { bar.classList.remove('is-on'); }, 3200); }
  function goOffline() { wasOffline = true; showBar('You are offline: saved pages still work', false); toast({ type: 'neutral', icon: 'offline', title: 'You are offline', text: 'You can keep browsing saved pages and add transactions. Everything syncs when you reconnect.' }); }
  function goOnline() { if (!wasOffline) return; wasOffline = false; showBar('Back online', true); flush(true); }
  window.addEventListener('offline', goOffline); window.addEventListener('online', goOnline);
  if (!navigator.onLine) showBar('You are offline: saved pages still work', false);
  else if (window.__mfncCache) { showBar('Showing a saved copy: the server cannot be reached', false); toast({ type: 'neutral', icon: 'offline', title: 'Saved copy', text: 'The server is not reachable right now, so this is the last version saved on your device. Changes you make will wait and sync later.' }); }

  // ---------------------------------------------------------------- offline outbox
  var S = window.MfncSync, pendingBar = $('#pending-bar');
  function uid() { return (window.crypto && crypto.randomUUID) ? crypto.randomUUID() : 'x' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12); }
  function refreshPending() {
    if (!S || !pendingBar) return Promise.resolve();
    return S.all().then(function (ops) {
      pendingBar.classList.toggle('hidden', ops.length === 0);
      var n = ops.length, bad = ops.filter(function (o) { return o.rejected; }).length;
      var t = $('[data-pending-text]', pendingBar); if (t) t.textContent = n + ' change' + (n === 1 ? '' : 's') + (navigator.onLine ? ' waiting to sync' : ' saved offline, will sync when you reconnect') + (bad ? ' (' + bad + ' need attention)' : '');
      var list = $('#pending-list'); if (list) list.innerHTML = ops.map(function (o) { var f = o.data || {}; return '<li class="flex items-start justify-between gap-3 py-3 text-sm"><div class="min-w-0"><p class="font-medium">' + esc(f.description || f.type || 'Transaction') + '</p><p class="text-xs text-mute">' + esc(f.date || '') + ' · ' + esc(f.type || '') + ' · ' + esc(f.amount || '') + (o.rejected ? ' · <span class="text-neg">' + esc(o.error || 'Rejected') + '</span>' : '') + '</p></div><button type="button" class="btn btn-ghost btn-sm text-neg" data-discard="' + esc(o.uid) + '">Discard</button></li>'; }).join('') || '<li class="py-4 text-sm text-mute">Nothing waiting.</li>';
    });
  }
  var flushing = false;
  function flush(announce) {
    if (!S || flushing || !navigator.onLine) return Promise.resolve();
    flushing = true;
    return S.flush(BASE).then(function (r) {
      flushing = false; refreshPending();
      if (r.auth) { toast({ type: 'warn', title: 'Sign in to finish syncing', text: 'Your offline changes are safe on this device and will be sent after you sign in.', action: { label: 'Sign in', href: BASE + '/login' } }); return; }
      if (r.sent) { toast({ type: 'ok', title: 'Synced ' + r.sent + ' change' + (r.sent === 1 ? '' : 's'), text: 'Your offline entries are now saved on the server.', action: { label: 'Refresh', fn: expressiveReload } }); }
      if (r.rejected) toast({ type: 'err', title: r.rejected + ' change' + (r.rejected === 1 ? ' was' : 's were') + ' rejected', text: 'Open the pending list to see why, then discard or re-enter.' });
    }).catch(function () { flushing = false; });
  }
  if (S) {
    var csrf = body.getAttribute('data-csrf'), user = body.getAttribute('data-user');
    if (csrf) { S.setMeta('csrf', csrf); S.setMeta('user', user); }
    refreshPending().then(function () { if (navigator.onLine) flush(false); });
    d.addEventListener('visibilitychange', function () { if (!d.hidden) flush(false); });
    d.addEventListener('submit', function (e) {
      var form = e.target; if (!form || form.id !== 'tx-form' || navigator.onLine) return;
      e.preventDefault();
      var data = {}; new FormData(form).forEach(function (v, k) { data[k] = String(v); });
      if (!data.amount) { form.reportValidity && form.reportValidity(); return; }
      S.add({ uid: uid(), kind: 'transaction', data: data, queuedAt: Date.now(), user: user }).then(function () {
        var dlg = form.closest('dialog'); if (dlg && dlg.open) { window.mfncDialog ? window.mfncDialog.close(dlg) : dlg.close(); }
        toast({ type: 'neutral', icon: 'offline', title: 'Saved offline', text: 'It is stored on this device and will sync automatically when you are back online.' });
        refreshPending();
        if (navigator.serviceWorker && navigator.serviceWorker.ready) navigator.serviceWorker.ready.then(function (r) { if (r.sync) r.sync.register('mfnc-sync').catch(function () {}); });
      });
    });
    if (pendingBar) pendingBar.addEventListener('click', function (e) {
      var dis = e.target.closest('[data-discard]'); if (dis) { S.remove(dis.getAttribute('data-discard')).then(refreshPending); return; }
      if (e.target.closest('[data-open-pending]')) { var dlg = $('#pending-dialog'); if (dlg && !dlg.open) { window.mfncDialog ? window.mfncDialog.open(dlg, e.target) : dlg.showModal(); } }
      if (e.target.closest('[data-sync-now]')) { if (navigator.onLine) flush(true); else toast({ type: 'neutral', title: 'Still offline', text: 'Syncing will start by itself when the connection returns.' }); }
    });
    var pd = $('#pending-dialog'); if (pd) pd.addEventListener('click', function (e) { var dis = e.target.closest('[data-discard]'); if (dis) S.remove(dis.getAttribute('data-discard')).then(refreshPending); });
  }

  // ---------------------------------------------------------------- service worker + install
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register(BASE + '/sw.js', { scope: BASE + '/' }).then(function (reg) {
      function offer(w) { window.__mfncWaiting = w; try { if (sessionStorage.getItem('mfnc-update-told')) return; sessionStorage.setItem('mfnc-update-told', '1'); } catch (e) {} toast({ type: 'info', title: 'A new version is ready', text: 'It installs by itself the next time you open another page, or reload now.', ms: 12000, action: { label: 'Reload', fn: function () { w.postMessage({ type: 'SKIP_WAITING' }); } } }); }
      if (reg.waiting && navigator.serviceWorker.controller) offer(reg.waiting);
      reg.addEventListener('updatefound', function () { var w = reg.installing; if (w) w.addEventListener('statechange', function () { if (w.state === 'installed' && navigator.serviceWorker.controller) offer(w); }); });
      setInterval(function () { reg.update().catch(function () {}); }, 30 * 60 * 1000);
    }).catch(function () {});
    var reloaded = false; navigator.serviceWorker.addEventListener('controllerchange', function () { if (reloaded) return; reloaded = true; if (window.__mfncGoto) location.href = window.__mfncGoto; else expressiveReload(); });
    navigator.serviceWorker.addEventListener('message', function (e) { if (e.data && e.data.type === 'SYNCED') { refreshPending(); toast({ type: 'ok', title: 'Synced ' + e.data.sent + ' change' + (e.data.sent === 1 ? '' : 's'), text: 'Sent in the background.', action: { label: 'Refresh', fn: expressiveReload } }); } });
    d.addEventListener('submit', function (e) { if (e.target.matches && e.target.matches('[data-logout]') && navigator.serviceWorker.controller) navigator.serviceWorker.controller.postMessage({ type: 'CLEAR_PAGES' }); });
  }
  var deferred = null, canInstall = false;
  function showInstall(on) { canInstall = on; $$('[data-install]').forEach(function (b) { b.classList.toggle('hidden', !on); }); }
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault(); deferred = e; showInstall(true);
    if (!sessionStorage.getItem('mfnc-install-toast') && !standalone) { try { sessionStorage.setItem('mfnc-install-toast', '1'); } catch (x) {} setTimeout(function () { toast({ type: 'info', title: 'Install Moms Finance', text: 'Add it to your home screen for a full-screen app that also works offline.', action: { label: 'Install', fn: promptInstall } }); }, 4000); }
  });
  function promptInstall() { if (!deferred) return; deferred.prompt(); deferred.userChoice.then(function () { deferred = null; showInstall(false); }); }
  d.addEventListener('click', function (e) { if (e.target.closest('[data-install]')) promptInstall(); });
  window.addEventListener('appinstalled', function () { showInstall(false); toast({ type: 'ok', title: 'App installed', text: 'Open Moms Finance from your home screen any time, even offline.' }); });
  var ios = /iphone|ipad|ipod/i.test(navigator.userAgent) && !standalone;
  function pageUi() { $$('[data-ios-hint]').forEach(function (el) { el.classList.toggle('hidden', !ios); }); showInstall(canInstall); syncPalette(); }
  pageUi(); d.addEventListener('mfnc:page', pageUi);

  // ---------------------------------------------------------------- passkey nudge (once, only where passkeys work and none exist yet)
  if (body.getAttribute('data-passkeys') === '0' && window.PublicKeyCredential && !localStorage.getItem('mfnc-pk-dismiss') && !sessionStorage.getItem('mfnc-pk-nudged') && !$('#intro')) {
    setTimeout(function () { try { sessionStorage.setItem('mfnc-pk-nudged', '1'); } catch (x) {} toast({ type: 'info', icon: 'info', title: 'Sign in faster with a passkey', text: 'Use your fingerprint, face or screen lock instead of typing a password.', ms: 10000, action: { label: 'Set up', href: BASE + '/settings#passkeys' } }); try { localStorage.setItem('mfnc-pk-dismiss', '1'); } catch (x) {} }, 7000);
  }

  // ---------------------------------------------------------------- intro screen
  var intro = $('#intro');
  if (intro) {
    var slides = $$('.intro-slide', intro), dots = $$('.intro-dots i', intro), idx = -1; // -1 = hero
    var heroEls = $$('[data-intro-hero]', intro);
    function show(i) { idx = i; heroEls.forEach(function (e) { e.classList.toggle('hidden', i >= 0); }); slides.forEach(function (s, k) { s.classList.toggle('is-on', k === i); }); dots.forEach(function (x, k) { x.classList.toggle('is-on', k === i); }); $('.intro-dots', intro).classList.toggle('hidden', i < 0); var go = $('.go', intro); if (go) go.textContent = i < 0 ? 'Learn More' : (i === slides.length - 1 ? 'Get started' : 'Next'); }
    function close() { var f = new FormData(); f.append('_csrf', body.getAttribute('data-csrf') || ''); fetch(BASE + '/do/intro-done', { method: 'POST', body: f, credentials: 'same-origin', headers: { 'X-Requested-With': 'mfnc' } }).catch(function () {}); intro.classList.add('is-leaving'); body.style.overflow = ''; setTimeout(function () { intro.remove(); }, 480); }
    body.style.overflow = 'hidden'; show(-1);
    $('.go', intro).addEventListener('click', function () { if (idx === slides.length - 1) close(); else show(idx + 1); });
    $('.arrow', intro).addEventListener('click', function () { if (idx === slides.length - 1) close(); else show(idx + 1); });
    $('.intro-skip', intro).addEventListener('click', close);
    d.addEventListener('keydown', function (e) { if (e.key === 'Escape' && d.body.contains(intro)) close(); });
  }
})();
