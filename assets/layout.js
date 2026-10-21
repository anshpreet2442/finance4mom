/* Customize home: draft editor, AI panel with live verified preview, backups. DOM APIs and textContent only. */
(function () {
  'use strict';
  var d = document, w = window;
  function el(tag, cls, text) { var e = d.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function btn(label, cls, fn) { var b = el('button', 'btn ' + (cls || ''), label); b.type = 'button'; if (fn) b.addEventListener('click', fn); return b; }
  function card(title, sub) { var c = el('section', 'card lw-card in'); var h = el('div', 'lw-card__h'); h.appendChild(el('h2', 'text-lg font-semibold tracking-tight', title)); if (sub) h.appendChild(el('p', 'text-sm text-mute', sub)); c.appendChild(h); var b = el('div', 'lw-card__b'); c.appendChild(b); c._body = b; c._head = h; return c; }

  function init(root) {
    if (!root || root._lw) return; root._lw = true; var cfg = JSON.parse(d.getElementById('lw-data').textContent), S = null;
    var detected = (w.matchMedia && w.matchMedia('(max-width: 1023px)').matches) ? 'mobile' : 'desktop', device = null, busy = false, view = 'live';
    try { device = w.localStorage.getItem('mf.lw.device'); } catch (e) { } if (device !== 'mobile' && device !== 'desktop') device = detected;
    function csrf() { return d.body.getAttribute('data-csrf') || cfg.csrf; }
    function api(body) {
      body.device = device; if (S && !body.draft && body.action !== 'state' && body.action !== 'optin') body.draft = S.draft;
      return fetch(cfg.endpoints.api, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() }, body: JSON.stringify(body) }).then(function (r) { return r.json(); }).then(function (j) { if (!j.ok) { notice(j.message || 'Something went wrong.', true); return null; } S = j; if (j.message) notice(j.message); render(); return j; }).catch(function () { notice('Could not reach the server.', true); return null; });
    }
    var noticeEl = el('p', 'lw-notice', ''); noticeEl.setAttribute('role', 'status'); noticeEl.hidden = true; var nt = null;
    function notice(t, bad) { noticeEl.textContent = t; noticeEl.hidden = !t; noticeEl.className = 'lw-notice' + (bad ? ' is-bad' : ''); clearTimeout(nt); if (t) nt = setTimeout(function () { noticeEl.hidden = true; }, 7000); }

    // ---------- layout of the page
    var wrap = el('div', 'lw-ed'), left = el('div', 'lw-ed__l'), right = el('div', 'lw-ed__r'); wrap.appendChild(left); wrap.appendChild(right); root.appendChild(wrap);
    var optinBox = el('div'), devBox = el('div'), aiBox = el('div'), edBox = el('div'), dispBox = el('div'), bkBox = el('div'); [optinBox, devBox, aiBox, edBox, dispBox, bkBox].forEach(function (b) { left.appendChild(b); });
    var pvCard = card('Preview', 'What your home screen will look like. This is the real page, drawn from your draft.'); right.appendChild(noticeEl); right.appendChild(pvCard);

    // ---------- preview
    var pvBar = el('div', 'lw-pv__bar'), frameWrap = el('div', 'lw-pv__frame'), frame = el('iframe', 'lw-pv__if'), wire = el('img', 'lw-pv__wire'), wireTxt = el('ol', 'lw-pv__wiretxt'); wireTxt.hidden = true; frame.setAttribute('title', 'Preview of your home screen'); frame.setAttribute('sandbox', 'allow-same-origin'); wire.alt = 'Wireframe of the layout'; wire.hidden = true; frameWrap.appendChild(frame); frameWrap.appendChild(wire); frameWrap.appendChild(wireTxt);
    // the wireframe is an inline SVG sent with the state (no image request, no GD); if an image still cannot be shown, the plain ordered list below is used instead of a broken-image icon
    function wireText() { wireTxt.textContent = ''; ((S && S.widgets) || []).filter(function (w) { return w.visible; }).forEach(function (w) { wireTxt.appendChild(el('li', '', w.title + ' · ' + w.size)); }); }
    wire.onerror = function () { wire.hidden = true; wireText(); wireTxt.hidden = false; };
    // the live preview is fetched and shown with srcdoc: it works whatever X-Frame-Options / frame-ancestors the host adds to /layout/preview. Falls back to a normal src if fetch fails.
    var pvSeq = 0, pvNote = el('p', 'lw-pv__note', ''); pvNote.hidden = true;
    function loadLive(url) {
      var my = ++pvSeq; pvNote.hidden = true;
      function direct() { if (my !== pvSeq) return; frame.removeAttribute('srcdoc'); frame.src = url; }
      if (!window.fetch || !('srcdoc' in frame)) { direct(); return; }
      fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'mfnc-preview' } }).then(function (r) { if (!r.ok) throw new Error('http ' + r.status); return r.text(); })
        .then(function (html) { if (my !== pvSeq) return; if (html.indexOf('data-preview') < 0) throw new Error('not the preview'); frame.removeAttribute('src'); frame.srcdoc = html; }).catch(direct);
    }
    var viewBtns = el('div', 'lw-seg'); var bLive = btn('Live preview', 'btn-sm', function () { view = 'live'; paintPv(); }), bWire = btn('Wireframe', 'btn-sm', function () { view = 'wire'; paintPv(); }); viewBtns.appendChild(bLive); viewBtns.appendChild(bWire); pvBar.appendChild(viewBtns);
    var chip = el('span', 'lw-chip', ''); pvBar.appendChild(chip); var warnBox = el('ul', 'lw-warn'); pvCard._body.appendChild(pvBar); pvCard._body.appendChild(frameWrap); pvCard._body.appendChild(warnBox);
    function fit() { var W = device === 'mobile' ? 390 : 1280, cw = frameWrap.clientWidth || 600, s = Math.min(1, cw / W), H = 620; frame.style.width = W + 'px'; frame.style.height = (H / s) + 'px'; frame.style.transform = 'scale(' + s + ')'; frameWrap.style.height = H + 'px'; frame.style.transformOrigin = 'top left'; if (device === 'mobile') { frame.style.marginLeft = Math.max(0, (cw - W * s) / 2) + 'px'; } else { frame.style.marginLeft = '0'; } }
    var lastRev = '';
    function paintPv() {
      bLive.classList.toggle('btn-dark', view === 'live'); bWire.classList.toggle('btn-dark', view === 'wire'); frame.hidden = view !== 'live'; wire.hidden = view !== 'wire'; if (!S) return; fit();
      if (lastRev !== S.rev + view) { lastRev = S.rev + view; if (view === 'live') loadLive(S.preview + (d.documentElement.classList.contains('dark') ? '&dark=1' : '')); else { wireTxt.hidden = true; wire.hidden = false; wire.src = S.wire_svg ? 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(S.wire_svg) : S.wire; } }
      chip.textContent = 'Checked · ' + S.snapshot.hash + ' · ' + S.snapshot.visible_count + ' widgets' + (S.dirty ? ' · unsaved draft' : ' · matches your live home screen'); chip.className = 'lw-chip' + (S.dirty ? ' is-draft' : '');
      warnBox.textContent = ''; S.snapshot.warnings.forEach(function (t) { warnBox.appendChild(el('li', '', t)); }); warnBox.hidden = !S.snapshot.warnings.length;
    }
    w.addEventListener('resize', function () { if (S) fit(); });

    // ---------- opt-in + device
    function paintTop() {
      optinBox.textContent = ''; devBox.textContent = ''; if (!S) return;
      if (!S.optin) { var c = card('Turn on home screen customization', 'Beta feature'); c._head.appendChild(el('span', 'badge-beta', 'Beta')); c._body.appendChild(el('p', 'text-sm', 'You can rearrange widgets, change text size, simplify words, and ask the AI to do it for you. Every change is a draft first, a backup is saved before anything is applied, and you can reset to the original in one tap. The AI never writes code or touches your money: it only moves and resizes widgets.')); var ul = el('ul', 'lw-bullets'); ['Draft first: nothing changes until you press Apply', 'Automatic backups you can restore', 'One-tap reset to the default home screen'].forEach(function (t) { ul.appendChild(el('li', '', t)); }); c._body.appendChild(ul);
        c._body.appendChild(btn('Turn on', 'btn-primary', function () { api({ action: 'optin', on: true }); })); optinBox.appendChild(c); }
      var seg = el('div', 'lw-seg lw-seg--lg'); [['desktop', 'Desktop / tablet layout'], ['mobile', 'Phone layout']].forEach(function (p) { var b = btn(p[1], '', function () { if (device === p[0]) return; device = p[0]; try { w.localStorage.setItem('mf.lw.device', device); } catch (e) { } S = null; lastRev = ''; api({ action: 'state' }); }); b.classList.toggle('btn-dark', device === p[0]); b.setAttribute('aria-pressed', device === p[0] ? 'true' : 'false'); seg.appendChild(b); });
      devBox.appendChild(seg); devBox.appendChild(el('p', 'text-xs text-mute lw-devnote', 'You are on a ' + (detected === 'mobile' ? 'phone-sized' : 'larger') + ' screen, so we opened the ' + (detected === 'mobile' ? 'phone' : 'desktop') + ' layout first. The two layouts are saved separately.' + (device !== detected ? ' You are editing the other one.' : '')));
    }

    // ---------- AI panel
    var log = el('div', 'lw-log'), steps = null, composer = el('form', 'lw-composer'), ta = el('textarea', 'input'), send = btn('Send', 'btn-dark'), statusEl = el('p', 'lw-status text-sm text-mute', ''), actions = el('div', 'lw-actions'), sugg = el('div', 'lw-sugg');
    ta.rows = 2; ta.maxLength = 800; ta.placeholder = 'Describe the home screen you want, e.g. "put my bills first and make the text bigger"'; ta.setAttribute('aria-label', 'Describe the home screen you want'); send.type = 'submit'; composer.appendChild(ta); composer.appendChild(send);
    var bApply = btn('Apply to my home screen', 'btn-primary', function () { api({ action: 'apply' }).then(function (j) { if (j) { addMsg('assistant', null, { text: 'Applied. Your home screen now looks like the preview, and the previous version is saved under Backups.', link: true }); } }); }),
      bTweak = btn('Tweak', '', function () { ta.focus(); ta.placeholder = 'Tweak the draft, e.g. "make the bills bigger"'; }), bDiscard = btn('Discard draft', 'btn-ghost', function () { api({ action: 'discard' }); }),
      bUndo = btn('Undo', 'btn-ghost', function () { api({ action: 'undo' }); }), bRedo = btn('Redo', 'btn-ghost', function () { api({ action: 'redo' }); });
    [bApply, bTweak, bDiscard, bUndo, bRedo].forEach(function (b) { actions.appendChild(b); });
    function addMsg(role, text, extra) {
      var m = el('div', 'lw-msg lw-msg--' + role); if (text) m.appendChild(el('p', '', text)); extra = extra || {};
      if (extra.text) m.appendChild(el('p', '', extra.text)); if (extra.changes && extra.changes.length) { var h = el('p', 'lw-msg__h', 'What changed'); m.appendChild(h); var ul = el('ul', 'lw-bullets'); extra.changes.forEach(function (c) { ul.appendChild(el('li', '', c)); }); m.appendChild(ul); }
      if (extra.refused && extra.refused.length) { m.appendChild(el('p', 'lw-msg__h', 'I could not do')); var u2 = el('ul', 'lw-bullets'); extra.refused.forEach(function (c) { u2.appendChild(el('li', '', c)); }); m.appendChild(u2); }
      if (extra.warnings && extra.warnings.length) { m.appendChild(el('p', 'lw-msg__h', 'Heads-up from the check')); var u3 = el('ul', 'lw-bullets'); extra.warnings.forEach(function (c) { u3.appendChild(el('li', '', c)); }); m.appendChild(u3); }
      if (extra.link) { var a = el('a', 'btn btn-sm', 'View my home screen'); a.href = cfg.home; m.appendChild(a); } log.appendChild(m); log.scrollTop = log.scrollHeight; return m;
    }
    function paintAi() {
      aiBox.textContent = ''; if (!S || !S.optin) return; var c = card('Customize with AI', 'Tell me what you want. I only move, hide, resize and restyle widgets, then check the real result before showing it to you.'); c._head.appendChild(el('span', 'badge-beta', 'Beta'));
      if (!cfg.ai) { c._body.appendChild(el('p', 'text-sm text-mute', cfg.ai_why || 'The AI helper is not available right now. You can still arrange the widgets by hand below.')); aiBox.appendChild(c); return; }
      sugg.textContent = ''; cfg.suggestions.forEach(function (t) { var b = el('button', 'chip', t); b.type = 'button'; b.addEventListener('click', function () { ta.value = t; ta.focus(); }); sugg.appendChild(b); });
      c._body.appendChild(sugg); c._body.appendChild(log); c._body.appendChild(statusEl); c._body.appendChild(composer); c._body.appendChild(actions); aiBox.appendChild(c); updateButtons();
    }
    function updateButtons() { bApply.disabled = !S || !S.dirty || busy; bDiscard.disabled = !S || !S.dirty || busy; bUndo.disabled = !S || !S.can_undo || busy; bRedo.disabled = !S || !S.can_redo || busy; bTweak.disabled = busy; send.disabled = busy; ta.disabled = busy; }
    composer.addEventListener('submit', function (e) {
      e.preventDefault(); var text = ta.value.trim(); if (!text || busy || !S) return; addMsg('user', text); ta.value = ''; busy = true; updateButtons(); statusEl.textContent = 'Thinking…'; steps = el('ul', 'lw-steps'); log.appendChild(steps);
      fetch(cfg.endpoints.ai, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() }, body: JSON.stringify({ draft: S.draft, message: text, device: device }) }).then(function (r) {
        var rd = r.body && r.body.getReader ? r.body.getReader() : null, dec = new TextDecoder(), buf = '';
        function handle(line) { if (!line.trim()) return; var ev; try { ev = JSON.parse(line); } catch (x) { return; } onEv(ev); }
        if (!rd) return r.text().then(function (t) { t.split('\n').forEach(handle); });
        function pump() { return rd.read().then(function (x) { if (x.done) { if (buf) handle(buf); return; } buf += dec.decode(x.value, { stream: true }); var i; while ((i = buf.indexOf('\n')) >= 0) { handle(buf.slice(0, i)); buf = buf.slice(i + 1); } return pump(); }); }
        return pump();
      }).catch(function () { addMsg('assistant', 'I could not reach the server. Nothing was changed.'); }).then(function () { busy = false; statusEl.textContent = ''; updateButtons(); });
    });
    var stepEls = {};
    function onEv(ev) {
      if (ev.type === 'status') { statusEl.textContent = ev.text; }
      else if (ev.type === 'step') { var li = stepEls[ev.id]; if (!li) { li = el('li', ''); stepEls[ev.id] = li; steps.appendChild(li); } li.textContent = (ev.status === 'done' ? '✓ ' : (ev.status === 'failed' ? '✗ ' : '… ')) + ev.label; li.className = 'is-' + ev.status; log.scrollTop = log.scrollHeight; }
      else if (ev.type === 'snapshot') { S = ev.state; render(true); }
      else if (ev.type === 'message') { S = ev.state; addMsg('assistant', null, { text: ev.message.text, changes: ev.message.changes, refused: ev.message.refused, warnings: ev.message.warnings }); render(); }
      else if (ev.type === 'error') { addMsg('assistant', ev.message || 'Something went wrong.'); }
    }

    // ---------- manual editor
    function opsApi(ops) { return api({ action: 'ops', ops: ops }).then(function (j) { if (j && j.rejected && j.rejected.length) notice(j.rejected[0].reason, true); return j; }); }
    function paintEditor() {
      edBox.textContent = ''; if (!S || !S.optin) return; var c = card('Widgets', 'Show, hide, reorder and resize by hand. Changes go to the draft and the preview.'), ul = el('ul', 'lw-wl');
      S.widgets.forEach(function (x, i) {
        var li = el('li', 'lw-wl__i' + (x.visible ? '' : ' is-off')), cb = el('input'); cb.type = 'checkbox'; cb.checked = x.visible; cb.disabled = x.critical; cb.id = 'lw-v-' + x.id; cb.setAttribute('aria-label', 'Show ' + x.title); cb.addEventListener('change', function () { opsApi([cb.checked ? { op: 'add', widget: x.id } : { op: 'remove', widget: x.id }]); });
        var t = el('label', 'lw-wl__t'); t.setAttribute('for', cb.id); t.appendChild(el('span', 'font-medium', x.title)); if (x.critical) t.appendChild(el('span', 'lw-wl__crit', 'always on')); t.appendChild(el('span', 'text-xs text-mute', x.desc)); var ctl = el('div', 'lw-wl__c');
        var same = S.widgets.filter(function (y) { return y.zone === x.zone; }), pos = same.indexOf(x);
        var up = btn('↑', 'btn-sm btn-icon', function () { if (pos > 0) opsApi([{ op: 'move', widget: x.id, to: 'before', target: same[pos - 1].id }]); }), dn = btn('↓', 'btn-sm btn-icon', function () { if (pos < same.length - 1) opsApi([{ op: 'move', widget: x.id, to: 'after', target: same[pos + 1].id }]); });
        up.setAttribute('aria-label', 'Move ' + x.title + ' up'); dn.setAttribute('aria-label', 'Move ' + x.title + ' down'); up.disabled = pos === 0; dn.disabled = pos === same.length - 1; ctl.appendChild(up); ctl.appendChild(dn);
        if (device === 'desktop' && x.zone === 'main' && x.sizes.length > 1) { var sel = el('select', 'input lw-sel'); sel.setAttribute('aria-label', x.title + ' size'); x.sizes.forEach(function (s) { var o = el('option', '', s.replace('_', ' ')); o.value = s; o.selected = s === x.size; sel.appendChild(o); }); sel.addEventListener('change', function () { opsApi([{ op: 'resize', widget: x.id, size: sel.value }]); }); ctl.appendChild(sel); }
        if (device === 'desktop' && x.sizes.length > 1) { var zb = btn(x.zone === 'main' ? 'To side' : 'To main', 'btn-sm', function () { opsApi([{ op: 'move', widget: x.id, to: 'zone', zone: x.zone === 'main' ? 'side' : 'main' }]); }); ctl.appendChild(zb); }
        if (x.variants.length > 1) { var vs = el('select', 'input lw-sel'); vs.setAttribute('aria-label', x.title + ' style'); x.variants.forEach(function (v) { var o = el('option', '', v); o.value = v; o.selected = v === x.variant; vs.appendChild(o); }); vs.addEventListener('change', function () { opsApi([{ op: 'variant', widget: x.id, variant: vs.value }]); }); ctl.appendChild(vs); }
        li.appendChild(cb); li.appendChild(t); li.appendChild(ctl); ul.appendChild(li);
      });
      c._body.appendChild(ul); edBox.appendChild(c);
    }
    function paintDisplay() {
      dispBox.textContent = ''; if (!S || !S.optin) return; var th = S.layout.theme, c = card('Text and style', 'Applies to this device\'s home screen.'), g = el('div', 'lw-form');
      function row(label, ctl) { var r = el('label', 'lw-row'); r.appendChild(el('span', '', label)); r.appendChild(ctl); g.appendChild(r); return r; }
      var den = el('select', 'input'); [['comfortable', 'Comfortable'], ['compact', 'Compact']].forEach(function (p) { var o = el('option', '', p[1]); o.value = p[0]; o.selected = th.density === p[0]; den.appendChild(o); }); den.addEventListener('change', function () { opsApi([{ op: 'density', value: den.value }]); }); row('Spacing', den);
      var fs = el('input'); fs.type = 'range'; fs.min = '90'; fs.max = '150'; fs.step = '5'; fs.value = String(Math.round(th.font_scale * 100)); var out = el('output', 'lw-out', fs.value + '%'); fs.addEventListener('input', function () { out.textContent = fs.value + '%'; }); fs.addEventListener('change', function () { opsApi([{ op: 'font_scale', value: Number(fs.value) / 100 }]); }); fs.setAttribute('aria-label', 'Text size'); var fr = row('Text size', fs); fr.appendChild(out);
      [['contrast', 'High contrast', th.contrast === 'high', function (on) { return { op: 'theme', contrast: on ? 'high' : 'normal' }; }], ['simple', 'Simple words', th.simple, function (on) { return { op: 'theme', simple: on }; }], ['big', 'Big buttons', th.big_buttons, function (on) { return { op: 'theme', big_buttons: on }; }]].forEach(function (p) { var cb = el('input'); cb.type = 'checkbox'; cb.checked = p[2]; cb.addEventListener('change', function () { opsApi([p[3](cb.checked)]); }); var r = row(p[1], cb); r.className += ' lw-row--chk'; });
      c._body.appendChild(g); var bar = el('div', 'lw-actions'); bar.appendChild(btn('Easy-to-use profile', '', function () { opsApi([{ op: 'profile', name: 'ease' }]); })); bar.appendChild(btn('Copy from ' + (device === 'mobile' ? 'desktop' : 'phone') + ' layout', '', function () { opsApi([{ op: 'mirror', from: device === 'mobile' ? 'desktop' : 'mobile' }]); }));
      c._body.appendChild(bar); c._body.appendChild(el('p', 'text-xs text-mute', 'Easy-to-use: large text, high contrast, plain words, big buttons and only the widgets you need every day.')); dispBox.appendChild(c);
    }
    function paintBackups() {
      bkBox.textContent = ''; if (!S || !S.optin) return; var c = card('Backups and reset', 'A backup is saved automatically before every change is applied (latest ' + S.limit + ' kept).'), ul = el('ul', 'lw-bk');
      if (!S.backups.length) ul.appendChild(el('li', 'text-sm text-mute', 'No backups yet. They appear here after your first change.'));
      S.backups.forEach(function (b) { var li = el('li', 'lw-bk__i'); var t = el('span', 'min-w-0'); t.appendChild(el('span', 'font-medium', b.label || 'Backup')); t.appendChild(el('span', 'text-xs text-mute', ' ' + b.created_at.slice(0, 16) + ' UTC' + (b.source === 'manual' ? ' · saved by you' : ''))); li.appendChild(t); var a = el('span', 'lw-actions');
        a.appendChild(btn('Restore', 'btn-sm', function () { if (w.confirm('Restore this backup? Your current home screen is saved as a backup first.')) api({ action: 'restore', id: b.id }); })); a.appendChild(btn('Delete', 'btn-sm btn-ghost', function () { api({ action: 'backup_delete', id: b.id }); })); li.appendChild(a); ul.appendChild(li); });
      c._body.appendChild(ul); var bar = el('div', 'lw-actions'); bar.appendChild(btn('Save a backup now', '', function () { api({ action: 'backup_now', label: 'Saved by you' }); }));
      var rs = btn('Reset to default', 'btn-ghost text-neg', function () { if (w.confirm('Reset this device\'s home screen to the original? A backup is saved first.')) api({ action: 'reset_default' }); }); rs.setAttribute('data-lw-reset', '1'); bar.appendChild(rs); c._body.appendChild(bar); bkBox.appendChild(c);
    }
    var firstPaint = true;
    function render(onlyPreview) { if (!S) return; paintPv(); updateButtons(); if (onlyPreview) return; paintTop(); if (firstPaint || !aiBox.firstChild) { paintAi(); } firstPaint = false; paintEditor(); paintDisplay(); paintBackups(); updateButtons(); }
    api({ action: 'state' }).then(function (j) { if (j) { firstPaint = true; render(); } });
  }
  function boot() { init(d.querySelector('[data-lw-editor]')); }
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', boot); else boot(); d.addEventListener('mfnc:page', boot);
})();
