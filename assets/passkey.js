/* Passkey (WebAuthn) buttons: sign in on the login page, add one in Settings. Server checks everything; this only talks to the browser API. */
(function () {
  'use strict';
  var d = document, body = d.body, BASE = body.getAttribute('data-base') || '';
  var $ = function (s) { return d.querySelector(s); };
  var supported = !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.create);
  function ui() { d.querySelectorAll('[data-passkey-ui]').forEach(function (el) { el.classList.toggle('hidden', !supported); }); var u = $('#pk-unsupported'); if (u) u.classList.toggle('hidden', supported); }
  ui(); d.addEventListener('mfnc:page', ui);
  if (!supported) return;
  function enc(buf) { var b = new Uint8Array(buf), s = ''; for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]); return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
  function dec(str) { str = str.replace(/-/g, '+').replace(/_/g, '/'); while (str.length % 4) str += '='; var s = atob(str), b = new Uint8Array(s.length); for (var i = 0; i < s.length; i++) b[i] = s.charCodeAt(i); return b.buffer; }
  function post(path, data) {
    return fetch(BASE + path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': body.getAttribute('data-csrf') || '', 'X-Requested-With': 'mfnc' }, body: JSON.stringify(data || {}) })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'Something went wrong.'); return j; }); });
  }
  function say(msg, bad) { var m = $('#pk-msg'); if (m) { m.textContent = msg; m.className = 'mt-3 rounded-2xl px-4 py-3 text-sm ' + (bad ? 'bg-neg/10 text-neg' : 'bg-lime/20 text-lime-dk'); m.hidden = !msg; } else if (window.toast) toast({ type: bad ? 'err' : 'ok', title: msg }); }
  function friendly(e) { if (e && (e.name === 'NotAllowedError' || e.name === 'AbortError')) return 'Cancelled. Nothing was changed.'; if (e && e.name === 'InvalidStateError') return 'This device already has a passkey for your account.'; return (e && e.message) || 'Something went wrong.'; }
  function busy(btn, on) { if (!btn) return; btn.disabled = on; btn.classList.toggle('opacity-60', on); }

  d.addEventListener('click', function (ev) {
    var login = ev.target.closest('[data-passkey-login]'); if (!login) return;
    var email = ($('input[name=email]') || {}).value || ''; busy(login, true); say('', false);
    post('/webauthn/login/options', email ? { email: email } : {}).then(function (o) {
      o.challenge = dec(o.challenge); (o.allowCredentials || []).forEach(function (c) { c.id = dec(c.id); });
      return navigator.credentials.get({ publicKey: o });
    }).then(function (c) {
      var r = c.response; return post('/webauthn/login/verify', { id: enc(c.rawId), clientDataJSON: enc(r.clientDataJSON), authenticatorData: enc(r.authenticatorData), signature: enc(r.signature), userHandle: r.userHandle ? enc(r.userHandle) : '' });
    }).then(function (j) { say('Signed in. Taking you to your dashboard…', false); location.href = j.redirect || BASE + '/'; })
      .catch(function (e) { say(friendly(e), true); busy(login, false); });
  });

  d.addEventListener('click', function (ev) {
    var add = ev.target.closest('[data-passkey-add]'); if (!add) return;
    var name = ($('#pk-name') || {}).value || ''; busy(add, true); say('', false);
    post('/webauthn/register/options', {}).then(function (o) {
      o.challenge = dec(o.challenge); o.user.id = dec(o.user.id); (o.excludeCredentials || []).forEach(function (c) { c.id = dec(c.id); });
      return navigator.credentials.create({ publicKey: o });
    }).then(function (c) {
      var r = c.response; return post('/webauthn/register/verify', { name: name, id: enc(c.rawId), clientDataJSON: enc(r.clientDataJSON), attestationObject: enc(r.attestationObject), transports: r.getTransports ? r.getTransports() : [] });
    }).then(function () { try { localStorage.setItem('mfnc-pk-dismiss', '1'); } catch (x) {} say('Passkey added. You can use it to sign in next time.', false); setTimeout(function () { (window.MfncSpa ? window.MfncSpa.refresh() : location.reload()); }, 900); })
      .catch(function (e) { say(friendly(e), true); busy(add, false); });
  });
})();
