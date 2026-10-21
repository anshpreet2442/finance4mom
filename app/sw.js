/* Moms Finance service worker. Served by PHP at /sw.js so BUILD changes whenever an asset changes and caches refresh themselves.
   - app shell + assets: cached so the app opens offline
   - pages you have visited: network first, falling back to the last saved copy, then to /offline
   - nothing from /do/, /sync, /webhooks, /login, /register, /admin or payment returns is ever cached
   - offline changes wait in IndexedDB and are sent on reconnect (Background Sync where available, otherwise when the page is open) */
const BUILD = '__BUILD__';
const BASE = '__BASE__';
const SHELL = 'mfnc-shell-' + BUILD;
const PAGES = 'mfnc-pages-' + BUILD;
const PRECACHE = ['/assets/app.css', '/assets/polish.css', '/assets/ext.css', '/assets/swiperow.js', '/assets/dock.js', '/assets/starborder.js', '/assets/ai.js', '/assets/motion.js', '/assets/statusmark.js', '/assets/statusmark.css', '/assets/promptbar.js', '/assets/promptbar.css', '/assets/pillsmark.js', '/assets/shop.js', '/assets/pills.svg', '/assets/landing.css', '/assets/app.js', '/assets/pwa.js', '/assets/sync.js', '/assets/spa.js', '/assets/jelly.js', '/assets/slosh.js', '/assets/passkey.js', '/assets/icons/icon-192.png', '/assets/icons/icon-512.png', '/offline', '/manifest.webmanifest'].map(function (p) { return BASE + p; });
const NEVER = /^\/(do|sync|api|webhooks|login|register|admin|dues\/return|sw\.js)(\/|$)/;
importScripts(BASE + '/assets/sync.js');

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(SHELL).then(function (c) { return Promise.all(PRECACHE.map(function (u) { return c.add(new Request(u, { cache: 'reload' })).catch(function () {}); })); }));
  // no skipWaiting here: the page offers "Reload" when a new version is ready, so nobody loses a half-typed form
});
self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) { return Promise.all(keys.filter(function (k) { return k.indexOf('mfnc-') === 0 && k !== SHELL && k !== PAGES; }).map(function (k) { return caches.delete(k); })); }).then(function () { return self.clients.claim(); }));
});
self.addEventListener('message', function (e) {
  var d = e.data || {};
  if (d.type === 'SKIP_WAITING') self.skipWaiting();
  if (d.type === 'CLEAR_PAGES') e.waitUntil(caches.delete(PAGES));
});
function rel(url) { var p = new URL(url).pathname; return BASE && p.indexOf(BASE) === 0 ? p.slice(BASE.length) || '/' : p; }
self.addEventListener('fetch', function (e) {
  var req = e.request, url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== self.location.origin) return;
  var path = rel(req.url);
  if (path.indexOf('/assets/') === 0 || path === '/manifest.webmanifest') {
    // assets carry ?v=<file time>, so an exact match is always the right version; a changed file is fetched fresh, and the old copy is only a fallback when offline
    e.respondWith(caches.open(SHELL).then(function (c) { return c.match(req).then(function (hit) {
      if (hit) return hit;
      return fetch(req).then(function (r) { if (r.ok) c.put(req, r.clone()); return r; }).catch(function () { return c.match(req, { ignoreSearch: true }); });
    }); }));
    return;
  }
  if (req.mode === 'navigate' || req.headers.get('X-Mfnc-Spa') === '1') {
    e.respondWith(fetch(req).then(function (r) {
      var cacheable = r.ok && !r.redirected && !NEVER.test(path) && (r.headers.get('content-type') || '').indexOf('text/html') === 0;
      if (cacheable) { var copy = r.clone(); caches.open(PAGES).then(function (c) { c.put(req, copy); }); }
      return r;
    }).catch(function () {
      return caches.open(PAGES).then(function (c) { return c.match(req).then(function (hit) { return hit || c.match(url.pathname) || null; }); })
        .then(function (hit) { // tell the page it is a saved copy so it can say so, even when the device itself still reports "online"
          if (!hit) return null;
          return hit.text().then(function (t) { return new Response(t.replace('</body>', '<script>window.__mfncCache=1</script></body>'), { status: 200, statusText: 'OK', headers: { 'Content-Type': 'text/html; charset=utf-8', 'X-Mfnc-Cache': '1' } }); });
        })
        .then(function (hit) { return hit || caches.open(SHELL).then(function (c) { return c.match(BASE + '/offline'); }); })
        .then(function (hit) { return hit || new Response('You are offline.', { status: 503, headers: { 'Content-Type': 'text/plain' } }); });
    }));
  }
});
self.addEventListener('sync', function (e) {
  if (e.tag === 'mfnc-sync') {
    e.waitUntil(MfncSync.flush(BASE).then(function (r) {
      if (r && r.sent) return self.clients.matchAll({ type: 'window' }).then(function (cs) { cs.forEach(function (c) { c.postMessage({ type: 'SYNCED', sent: r.sent, left: r.left }); }); });
    }));
  }
});
