/* Offline outbox shared by the page and the service worker (IndexedDB).
   Changes made while offline are stored here with a client-generated id, then POSTed to /sync as soon as a connection
   exists. The server de-duplicates on that id, so a retry can never create a second record. */
(function (root) {
  'use strict';
  var DB = 'mfnc', V = 1;
  function open() {
    return new Promise(function (res, rej) {
      var r = indexedDB.open(DB, V);
      r.onupgradeneeded = function () { var d = r.result; if (!d.objectStoreNames.contains('outbox')) d.createObjectStore('outbox', { keyPath: 'uid' }); if (!d.objectStoreNames.contains('meta')) d.createObjectStore('meta', { keyPath: 'k' }); };
      r.onsuccess = function () { res(r.result); }; r.onerror = function () { rej(r.error); };
    });
  }
  function run(store, mode, fn) {
    return open().then(function (db) { return new Promise(function (res, rej) { var t = db.transaction(store, mode), s = t.objectStore(store), out = fn(s); t.oncomplete = function () { res(out && out.result !== undefined ? out.result : out); db.close(); }; t.onerror = function () { rej(t.error); db.close(); }; }); });
  }
  var S = {
    add: function (op) { return run('outbox', 'readwrite', function (s) { return s.put(op); }); },
    all: function () { return run('outbox', 'readonly', function (s) { return s.getAll(); }).then(function (r) { return (r || []).sort(function (a, b) { return a.queuedAt - b.queuedAt; }); }); },
    remove: function (uid) { return run('outbox', 'readwrite', function (s) { return s.delete(uid); }); },
    clear: function () { return run('outbox', 'readwrite', function (s) { return s.clear(); }); },
    setMeta: function (k, v) { return run('meta', 'readwrite', function (s) { return s.put({ k: k, v: v }); }); },
    getMeta: function (k) { return run('meta', 'readonly', function (s) { return s.get(k); }).then(function (r) { return r ? r.v : null; }); },
    /** Sends everything waiting. Returns {sent, left, rejected, auth}. */
    flush: function (base) {
      return S.all().then(function (ops) {
        var pending = ops.filter(function (o) { return !o.rejected; });
        if (!pending.length) return { sent: 0, left: ops.length, rejected: ops.length - pending.length };
        return Promise.all([S.getMeta('csrf'), S.getMeta('user')]).then(function (m) {
          return fetch(base + '/sync', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': m[0] || '', 'X-Requested-With': 'mfnc' }, body: JSON.stringify({ user: m[1], ops: pending }) });
        }).then(function (r) {
          if (r.status === 401 || r.status === 403) return { auth: true, sent: 0, left: ops.length };
          if (!r.ok) throw new Error('sync ' + r.status);
          return r.json().then(function (j) {
            var sent = 0, rejected = 0, chain = Promise.resolve();
            (j.results || []).forEach(function (x) {
              if (x.ok) { sent++; chain = chain.then(function () { return S.remove(x.uid); }); }
              else if (x.final) { rejected++; var op = pending.filter(function (o) { return o.uid === x.uid; })[0]; if (op) { op.rejected = true; op.error = x.error; chain = chain.then(function () { return S.add(op); }); } }
            });
            return chain.then(function () { return S.all(); }).then(function (rest) { return { sent: sent, rejected: rejected, left: rest.length }; });
          });
        });
      });
    }
  };
  root.MfncSync = S;
})(typeof self !== 'undefined' ? self : window);
