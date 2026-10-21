/* Moms Finance single-page navigation.
   Links and forms stay ordinary HTML (so everything works without JavaScript); this script fetches the next page and swaps only what changed:
   the main content, top bar, menu, banner and the pill nav. The shell, sidebar, dialogs, toasts, service worker and live badges are never reloaded.
   Server cooperation: requests carry X-Mfnc-Spa: 1, and redirects come back as 204 + X-Mfnc-Redirect so we can follow them (or leave the site for the payment gateway).
   Anything unusual (downloads, other layouts, signing in/out, a different user, network trouble) falls back to a normal browser navigation. */
(function () {
  'use strict';
  var d = document, root = d.documentElement, body = d.body, BASE = body.getAttribute('data-base') || '';
  if (!window.fetch || !window.DOMParser || !history.pushState || !window.URL || !body.hasAttribute('data-user')) return;
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  var NOSPA = /^\/(transactions\/export|settings\/backup|sw\.js|manifest\.webmanifest|webhooks|webauthn|sync|api|credits|offline|login|register|logout|assets)(\/|$)/;
  var NOSPA_POST = /^\/do\/(logout|login|register|forgot|reset)/;
  var SWAPS = ['header.topbar', '#mf-nav', '#mf-banner', '#mf-pill', '#main'];
  var activeVT = null, cur = location.pathname + location.search, ctl = null, seq = 0, cache = {}, bar = null, barTimer = 0;
  try { history.scrollRestoration = 'manual'; } catch (e) {}

  function rel(u) { return u.pathname.indexOf(BASE) === 0 ? u.pathname.slice(BASE.length) || '/' : null; }
  function inScope(u, post) { if (u.origin !== location.origin) return false; var r = rel(u); return r !== null && !(post ? NOSPA_POST : NOSPA).test(r); }
  function abs(href) { try { return new URL(href, location.href); } catch (e) { return null; } }
  function say(o) { if (window.toast) window.toast(o); }

  // ---- progress bar
  function barOn() { clearTimeout(barTimer); barTimer = setTimeout(function () { if (!bar) { bar = d.createElement('div'); bar.id = 'spa-bar'; bar.setAttribute('aria-hidden', 'true'); body.appendChild(bar); } bar.className = ''; void bar.offsetWidth; bar.className = 'is-on'; }, 110); }
  function barOff() { clearTimeout(barTimer); if (bar && bar.className === 'is-on') { bar.className = 'is-done'; var b = bar; setTimeout(function () { if (b.className === 'is-done') b.className = ''; }, 380); } }

  // ---- fetching
  function request(url, opts) {
    var init = { method: opts.post ? 'POST' : 'GET', credentials: 'same-origin', redirect: 'manual', headers: { 'X-Mfnc-Spa': '1', 'X-Requested-With': 'mfnc', 'Accept': 'text/html' }, signal: opts.signal };
    if (opts.post) init.body = opts.post;
    return fetch(url, init);
  }
  /** Resolves to {doc, text, url, cached} or rejects with {hard: true} for "do a normal navigation instead". */
  function load(url, opts) {
    var hit = !opts.post && !opts.fresh && cache[url];
    if (hit) { delete cache[url]; if (Date.now() - hit.t < 10000) return hit.p; }
    var p = request(url, opts).then(function (r) {
      var to = r.headers.get('X-Mfnc-Redirect');
      if (to) { var u = abs(to); if (!u || !inScope(u, false)) throw { hard: true, url: to }; return { redirect: u.pathname + u.search + u.hash }; }
      if (r.type === 'opaqueredirect' || (r.status >= 300 && r.status < 400)) throw { hard: true, url: r.url || url };
      var ct = r.headers.get('content-type') || ''; if (ct.indexOf('text/html') !== 0) throw { hard: true, url: url, download: true };
      return r.text().then(function (text) { return { text: text, status: r.status, saved: r.headers.get('X-Mfnc-Cache') === '1', url: url }; });
    });
    if (!opts.post && !opts.fresh) { cache[url] = { t: Date.now(), p: p }; p.catch(function () { delete cache[url]; }); }
    return p;
  }

  // ---- swapping
  function runScripts(scope) {
    Array.prototype.slice.call(scope.querySelectorAll('script')).forEach(function (old) {
      if (old.type && old.type !== 'text/javascript' && old.type !== 'module') return;
      var s = d.createElement('script'); Array.prototype.slice.call(old.attributes).forEach(function (a) { s.setAttribute(a.name, a.value); }); s.text = old.text; old.replaceWith(s);
    });
  }
  function apply(doc, res, o) {
    Array.prototype.slice.call(d.querySelectorAll('dialog[open]')).forEach(function (x) { try { x.close(); } catch (e) {} });
    d.title = doc.title;
    SWAPS.forEach(function (sel) {
      var a = d.querySelector(sel), b = doc.querySelector(sel);
      if (a && b) a.replaceWith(d.importNode(b, true)); else if (a && !b && sel !== '#main') a.remove();
    });
    var tx = d.getElementById('tx-dialog'), ntx = doc.getElementById('tx-dialog'); if (tx && ntx && !tx.open) tx.replaceWith(d.importNode(ntx, true));
    var b1 = d.querySelector('#confirm-form [name=_back]'), b2 = doc.querySelector('#confirm-form [name=_back]'); if (b1 && b2) b1.value = b2.value;
    ['data-csrf', 'data-passkeys', 'data-locale', 'data-symbol'].forEach(function (a) { if (doc.body.hasAttribute(a)) body.setAttribute(a, doc.body.getAttribute(a)); });
    var sb = d.getElementById('sidebar'), sc = d.getElementById('scrim'); if (sb) sb.classList.remove('is-open'); if (sc) sc.classList.add('hidden');
    var m = d.getElementById('main'); runScripts(m);
    if (!(d.startViewTransition && !reduce) && !reduce && o.animate) { m.classList.add('page-in'); }
    return doc.getElementById('flash-data');
  }
  function show(res, url, o) {
    var doc = new DOMParser().parseFromString(res.text, 'text/html');
    var hasMain = !!doc.getElementById('main') && doc.body.getAttribute('data-user') === body.getAttribute('data-user');
    if (!hasMain) { d.open(); d.write(res.text); d.close(); return; } // an error page or another layout: just show it
    var target = abs(url), path = target.pathname + target.search, same = path === cur, y = window.scrollY, flash = null;
    var work = function () {
      flash = apply(doc, res, { animate: !same || !o.quiet });
      if (o.push && !same) { try { history.replaceState({ y: y }, ''); } catch (e) {} history.pushState({ y: 0 }, '', target.pathname + target.search + target.hash); }
      else if (!o.pop) { try { history.replaceState(history.state || { y: 0 }, '', target.pathname + target.search + target.hash); } catch (e) {} }
      cur = location.pathname + location.search;
      if (o.pop) window.scrollTo(0, o.y || 0); else if (same || o.keepScroll) window.scrollTo(0, y); else window.scrollTo(0, 0);
      d.dispatchEvent(new CustomEvent('mfnc:page', { detail: { url: url } }));
      if (target.hash) { var el = d.getElementById(decodeURIComponent(target.hash.slice(1))); if (el) el.scrollIntoView(); }
    };
    var done = function () {
      if (flash) { try { JSON.parse(flash.textContent).forEach(function (f, i) { setTimeout(function () { say({ type: f.type, title: f.title, text: f.text }); }, i * 160); }); } catch (e) {} }
      if (res.saved) say({ type: 'neutral', icon: 'offline', title: 'Saved copy', text: 'You are offline, so this is the last version saved on your device.' });
      var h = d.getElementById('main'); if (h && !o.quiet) { h.setAttribute('tabindex', '-1'); try { h.focus({ preventScroll: true }); } catch (e) {} }
    };
    if (d.startViewTransition && !reduce && !d.hidden && !o.quiet) {
      var ran = false, once = function () { if (ran) return; ran = true; work(); }; // never let a stalled animation frame hold the page back
      if (activeVT) { try { activeVT.skipTransition(); } catch (e) {} }
      var vt = activeVT = d.startViewTransition(once); setTimeout(once, 450);
      vt.ready.catch(function () {}); vt.updateCallbackDone.catch(function () {}); vt.finished.then(function () { done(); }, function () { done(); }).then(function () { if (activeVT === vt) activeVT = null; });
    }
    else { work(); done(); }
  }

  /** Go to a URL the single-page way. opts: {post: FormData, push: bool, pop: bool, y, quiet, keepScroll, fresh} */
  function go(href, opts) {
    opts = opts || {}; if (window.__mfncWaiting && !opts.post && !opts.pop && !opts.quiet) { try { window.__mfncGoto = href; window.__mfncWaiting.postMessage({ type: 'SKIP_WAITING' }); setTimeout(function () { location.href = href; }, 1500); return Promise.resolve(); } catch (e) {} }
    var u = abs(href); if (!u || !inScope(u, !!opts.post)) { location.href = href; return Promise.resolve(); }
    if (ctl) ctl.abort(); ctl = window.AbortController ? new AbortController() : null; var my = ++seq; barOn();
    var o = { post: opts.post, signal: ctl && ctl.signal, fresh: !!opts.fresh || !!opts.post || !!opts.pop };
    if (opts.post) cache = {};
    return load(u.pathname + u.search, o).then(function (res) {
      if (my !== seq) return;
      if (res.redirect) { barOff(); return go(res.redirect, { fresh: true, push: opts.push !== false, quiet: opts.quiet, keepScroll: !!opts.post && res.redirect.split('#')[0] === cur }); }
      barOff(); show(res, u.pathname + u.search + u.hash, { push: opts.push !== false && !opts.pop, pop: opts.pop, y: opts.y, quiet: opts.quiet, keepScroll: opts.keepScroll });
    }).catch(function (e) {
      if (my !== seq) return; barOff();
      if (e && e.name === 'AbortError') return;
      if (e && e.hard) { location.href = e.url || href; return; }
      if (opts.post) { say({ type: 'err', title: 'Could not reach the server', text: 'Check your connection and try again. Nothing was changed.' }); throw e; }
      location.href = href; // offline etc.: let the browser and the service worker take over
    });
  }
  function refresh() { return go(location.pathname + location.search, { push: false, quiet: true, fresh: true, keepScroll: true }); }
  window.MfncSpa = { go: function (href) { return go(href, { push: true }); }, refresh: refresh };

  // ---- links
  d.addEventListener('click', function (e) {
    if (e.defaultPrevented || e.button || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
    var a = e.target.closest && e.target.closest('a[href]'); if (!a || (a.target && a.target !== '_self') || a.hasAttribute('download') || a.getAttribute('data-spa') === 'off' || a.getAttribute('rel') === 'external') return;
    var u = abs(a.href); if (!u || !inScope(u, false)) return;
    if (u.pathname + u.search === cur && u.hash) return; // in-page anchor
    e.preventDefault(); go(u.pathname + u.search + u.hash, { push: true, quiet: u.pathname + u.search === cur });
  });
  // ---- forms
  d.addEventListener('submit', function (e) {
    if (e.defaultPrevented) return; var f = e.target; if (!f || f.tagName !== 'FORM' || f.getAttribute('data-spa') === 'off' || f.hasAttribute('data-logout') || (f.target && f.target !== '_self')) return;
    var u = abs(f.getAttribute('action') || location.href), method = (f.getAttribute('method') || 'get').toLowerCase(); if (!u || (method !== 'get' && method !== 'post')) return;
    if (!inScope(u, method === 'post')) return;
    var fd; try { fd = new FormData(f, e.submitter || undefined); } catch (x) { fd = new FormData(f); if (e.submitter && e.submitter.name) fd.append(e.submitter.name, e.submitter.value); }
    e.preventDefault();
    var btn = e.submitter || f.querySelector('[type=submit]'); f.setAttribute('aria-busy', 'true'); if (btn) btn.disabled = true;
    var end = function () { f.removeAttribute('aria-busy'); if (btn) btn.disabled = false; };
    if (method === 'post') { go(u.pathname + u.search, { post: fd, push: true }).then(end, end); }
    else { var q = new URLSearchParams(); fd.forEach(function (v, k) { if (typeof v === 'string' && v !== '') q.append(k, v); }); var s = q.toString(); go(u.pathname + (s ? '?' + s : ''), { push: true }).then(end, end); }
  });
  // ---- back / forward
  window.addEventListener('popstate', function (e) {
    var path = location.pathname + location.search; if (path === cur) return; // only the #hash changed
    go(path + location.hash, { pop: true, y: e.state && e.state.y, push: false });
  });
  // ---- warm up the next page when someone is about to click
  function warm(e) { var a = e.target.closest && e.target.closest('a[href]'); if (!a || a.target || a.hasAttribute('download') || navigator.connection && navigator.connection.saveData) return; var u = abs(a.href); if (!u || !inScope(u, false) || u.pathname + u.search === cur) return; var k = u.pathname + u.search; if (cache[k] && Date.now() - cache[k].t < 10000) return; load(k, { fresh: false }).catch(function () {}); }
  d.addEventListener('mouseover', warm, { passive: true }); d.addEventListener('touchstart', warm, { passive: true });
})();
