/* AI advisor chat. Renders structured blocks (text, stats, table, chart, goal progress, bill list, comparison, calculator steps, action previews)
   with DOM APIs and textContent only, so nothing the model says can inject markup. Talks to /ai/chat (NDJSON stream) and /ai/act (apply / dismiss / undo). */
(function () {
  'use strict';
  var d = document;
  function el(tag, cls, text) { var e = d.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  var inr = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 });
  function money(v) { var n = Number(v); if (!isFinite(n)) return String(v); return inr.format(n).replace(/\.00$/, ''); }
  function fmtCell(v, type) { if (typeof v === 'number') { if (type === 'money') return money(v); if (type === 'pct') return (Math.round(v * 10) / 10) + '%'; return String(Math.round(v * 100) / 100); } return String(v == null ? '' : v); }
  function md(text) { // tiny, safe formatting: **bold**, line breaks, "- " bullets
    var wrap = el('div', 'ai-text'), lines = String(text).split('\n'), ul = null;
    lines.forEach(function (ln) {
      var m = /^\s*[-•]\s+(.*)$/.exec(ln);
      if (m) { if (!ul) { ul = el('ul'); wrap.appendChild(ul); } var li = el('li'); inline(li, m[1]); ul.appendChild(li); }
      else { ul = null; if (ln.trim() === '') return; var p = el('p'); inline(p, ln); wrap.appendChild(p); }
    });
    return wrap;
  }
  function inline(node, s) { s.split(/(\*\*[^*]+\*\*)/).forEach(function (part) { if (/^\*\*[^*]+\*\*$/.test(part)) node.appendChild(el('strong', '', part.slice(2, -2))); else if (part) node.appendChild(d.createTextNode(part)); }); }

  var R = {};
  R.text = function (b) { return md(b.text); };
  R.callout = function (b) { var c = el('div', 'ai-callout ai-callout--' + (b.tone || 'warn')); c.setAttribute('role', 'note'); c.appendChild(md(b.text)); return c; };
  R.stats = function (b) { var g = el('div', 'ai-stats'); b.items.forEach(function (i) { var c = el('div', 'ai-stat' + (i.tone ? ' ai-stat--' + i.tone : '')); c.appendChild(el('div', 'ai-stat__l', i.label)); c.appendChild(el('div', 'ai-stat__v', fmtCell(i.value, i.type))); if (i.sub) c.appendChild(el('div', 'ai-stat__s', i.sub)); g.appendChild(c); }); return g; };
  R.table = function (b) {
    var w = el('div', 'ai-card'); if (b.title) w.appendChild(el('div', 'ai-card__t', b.title)); var sc = el('div', 'ai-scroll'), t = el('table', 'ai-table'), h = el('tr');
    b.columns.forEach(function (c) { var th = el('th', c.type === 'text' ? '' : 'r', c.label); th.scope = 'col'; h.appendChild(th); }); var th = el('thead'); th.appendChild(h); t.appendChild(th); var tb = el('tbody');
    b.rows.forEach(function (r) { var tr = el('tr'); b.columns.forEach(function (c, i) { tr.appendChild(el('td', c.type === 'text' ? '' : 'r', fmtCell(r[i], c.type))); }); tb.appendChild(tr); }); t.appendChild(tb); sc.appendChild(t); w.appendChild(sc); return w;
  };
  var PAL = ['#8fc93a', '#f6a85e', '#4aa7a0', '#a06aa8', '#f1e94b', '#6b6b66'];
  R.chart = function (b) {
    var w = el('div', 'ai-card'); if (b.title) w.appendChild(el('div', 'ai-card__t', b.title)); var NS = 'http://www.w3.org/2000/svg', W = 320, H = 170, svg = d.createElementNS(NS, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H); svg.setAttribute('role', 'img'); svg.setAttribute('class', 'ai-chart'); svg.setAttribute('aria-label', (b.title || 'Chart') + ': ' + b.labels.map(function (l, i) { return l + ' ' + fmtCell(b.series[0].values[i], b.money ? 'money' : 'number'); }).join(', '));
    function s(tag, a, txt) { var e = d.createElementNS(NS, tag); for (var k in a) e.setAttribute(k, a[k]); if (txt != null) e.textContent = txt; svg.appendChild(e); return e; }
    var all = [].concat.apply([], b.series.map(function (x) { return x.values; })), n = b.labels.length;
    if (b.kind === 'donut') {
      var vals = b.series[0].values, tot = vals.reduce(function (a, c) { return a + Math.max(0, c); }, 0) || 1, a0 = -Math.PI / 2, cx = 85, cy = 85, r = 70, r2 = 42;
      vals.forEach(function (v, i) { v = Math.max(0, v); var a1 = a0 + v / tot * Math.PI * 2, big = a1 - a0 > Math.PI ? 1 : 0; if (v <= 0) return; if (v / tot > 0.9999) { s('circle', { cx: cx, cy: cy, r: (r + r2) / 2, fill: 'none', stroke: PAL[i % PAL.length], 'stroke-width': r - r2 }); return; }
        var p = 'M' + (cx + r * Math.cos(a0)) + ' ' + (cy + r * Math.sin(a0)) + ' A' + r + ' ' + r + ' 0 ' + big + ' 1 ' + (cx + r * Math.cos(a1)) + ' ' + (cy + r * Math.sin(a1)) + ' L' + (cx + r2 * Math.cos(a1)) + ' ' + (cy + r2 * Math.sin(a1)) + ' A' + r2 + ' ' + r2 + ' 0 ' + big + ' 0 ' + (cx + r2 * Math.cos(a0)) + ' ' + (cy + r2 * Math.sin(a0)) + ' Z';
        var e = s('path', { d: p, fill: PAL[i % PAL.length] }); var t = d.createElementNS(NS, 'title'); t.textContent = b.labels[i] + ': ' + fmtCell(v, b.money ? 'money' : 'number') + ' (' + Math.round(v / tot * 100) + '%)'; e.appendChild(t); a0 = a1; });
      b.labels.slice(0, 7).forEach(function (l, i) { s('rect', { x: 180, y: 14 + i * 20, width: 10, height: 10, rx: 3, fill: PAL[i % PAL.length] }); s('text', { x: 196, y: 23 + i * 20, 'font-size': 11, fill: 'currentColor' }, (l.length > 16 ? l.slice(0, 15) + '…' : l)); });
    } else {
      var max = Math.max.apply(null, all.concat([1])), min = Math.min.apply(null, all.concat([0])), L = 8, B = 22, T = 8, iw = W - L - 6, ih = H - B - T, y = function (v) { return T + ih - (v - min) / (max - min || 1) * ih; };
      s('line', { x1: L, x2: W - 6, y1: y(0), y2: y(0), stroke: 'currentColor', 'stroke-opacity': .25 });
      var step = Math.ceil(n / 8), gw = iw / n;
      b.labels.forEach(function (l, i) { if (i % step === 0) s('text', { x: L + gw * i + gw / 2, y: H - 6, 'font-size': 9, 'text-anchor': 'middle', fill: 'currentColor', 'fill-opacity': .7 }, l.length > 7 ? l.slice(0, 6) + '…' : l); });
      if (b.kind === 'line') { b.series.forEach(function (se, si) { var pts = se.values.map(function (v, i) { return (L + gw * i + gw / 2) + ',' + y(v); }).join(' '); s('polyline', { points: pts, fill: 'none', stroke: PAL[si], 'stroke-width': 2.2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' }); se.values.forEach(function (v, i) { var c = s('circle', { cx: L + gw * i + gw / 2, cy: y(v), r: 2.6, fill: PAL[si] }); var t = d.createElementNS(NS, 'title'); t.textContent = b.labels[i] + ': ' + fmtCell(v, b.money ? 'money' : 'number'); c.appendChild(t); }); }); }
      else { var k = b.series.length, bw = Math.max(2, gw * 0.7 / k); b.series.forEach(function (se, si) { se.values.forEach(function (v, i) { var x = L + gw * i + gw * 0.15 + si * bw, y0 = y(0), y1 = y(v); var r = s('rect', { x: x, y: Math.min(y0, y1), width: bw - 1, height: Math.max(1, Math.abs(y0 - y1)), rx: 2, fill: PAL[si] }); var t = d.createElementNS(NS, 'title'); t.textContent = (k > 1 ? se.name + ' · ' : '') + b.labels[i] + ': ' + fmtCell(v, b.money ? 'money' : 'number'); r.appendChild(t); }); }); }
      s('text', { x: L, y: T + 8, 'font-size': 9, fill: 'currentColor', 'fill-opacity': .7 }, fmtCell(max, b.money ? 'money' : 'number'));
    }
    w.appendChild(svg); if (b.series.length > 1 && b.kind !== 'donut') { var lg = el('div', 'ai-legend'); b.series.forEach(function (se, i) { var x = el('span'); var sw = el('i'); sw.style.background = PAL[i]; x.appendChild(sw); x.appendChild(d.createTextNode(se.name)); lg.appendChild(x); }); w.appendChild(lg); } return w;
  };
  R.goal_progress = function (b) { var w = el('div', 'ai-card'); b.items.forEach(function (g) { var r = el('div', 'ai-goal'); var h = el('div', 'ai-goal__h'); h.appendChild(el('span', '', g.name)); h.appendChild(el('span', 'num', Math.round(g.pct) + '%')); r.appendChild(h); var bar = el('div', 'ai-bar'); bar.setAttribute('role', 'progressbar'); bar.setAttribute('aria-valuenow', Math.round(g.pct)); bar.setAttribute('aria-valuemin', 0); bar.setAttribute('aria-valuemax', 100); bar.setAttribute('aria-label', g.name + ' progress'); var f = el('i'); f.style.width = Math.min(100, g.pct) + '%'; bar.appendChild(f); r.appendChild(bar); r.appendChild(el('div', 'ai-goal__s', money(g.saved) + ' of ' + money(g.target) + (g.note ? ' · ' + g.note : ''))); w.appendChild(r); }); return w; };
  R.bill_list = function (b) { var w = el('div', 'ai-card'); b.items.forEach(function (i) { var r = el('div', 'ai-bill'); var l = el('div'); l.appendChild(el('div', 'ai-bill__n', i.name)); l.appendChild(el('div', 'ai-bill__d', i.due)); r.appendChild(l); var rt = el('div', 'ai-bill__r'); rt.appendChild(el('div', 'num', money(i.amount))); rt.appendChild(el('span', 'ai-pill ai-pill--' + i.status, i.status)); r.appendChild(rt); w.appendChild(r); }); return w; };
  R.comparison = function (b) {
    var w = el('div', 'ai-card'); if (b.title) w.appendChild(el('div', 'ai-card__t', b.title)); var sc = el('div', 'ai-scroll'), t = el('table', 'ai-table'), h = el('tr'), th0 = el('th', '', ''); th0.scope = 'col'; h.appendChild(th0);
    b.labels.forEach(function (l) { var th = el('th', 'r', l); th.scope = 'col'; h.appendChild(th); }); var hd = el('thead'); hd.appendChild(h); t.appendChild(hd); var tb = el('tbody');
    b.rows.forEach(function (r) { var tr = el('tr'), th = el('th', 'row', r.label); th.scope = 'row'; tr.appendChild(th); r.values.forEach(function (v, i) { var td = el('td', 'r' + (r.better === i ? ' best' : ''), fmtCell(v, r.money ? 'money' : 'number')); if (r.better === i) td.title = 'Better'; tr.appendChild(td); }); tb.appendChild(tr); });
    t.appendChild(tb); sc.appendChild(t); w.appendChild(sc); if (b.verdict) w.appendChild(el('div', 'ai-verdict', b.verdict)); return w;
  };
  R.calc = function (b) {
    var w = el('details', 'ai-card ai-calc'); w.open = true; var sm = el('summary'); sm.appendChild(el('span', 'ai-card__t', 'How this was calculated: ' + b.title)); w.appendChild(sm); var ol = el('ol');
    b.steps.forEach(function (s) { var li = el('li'); if (!s.value && !s.formula) li.className = 'sep'; li.appendChild(el('span', 'ai-step__l', s.label)); if (s.formula) li.appendChild(el('code', 'ai-step__f', s.formula)); if (s.value) li.appendChild(el('b', 'ai-step__v', s.value)); ol.appendChild(li); });
    w.appendChild(ol); if (b.result) { var r = el('div', 'ai-result'); r.appendChild(el('span', '', b.result.label)); r.appendChild(el('b', '', b.result.value)); w.appendChild(r); } return w;
  };
  function kv(o) { var keys = Object.keys(o || {}); return keys.length ? keys.map(function (k) { var v = o[k]; return k.replace(/_/g, ' ') + ': ' + (v == null ? '—' : (typeof v === 'number' ? money(v) : v)); }).join(' · ') : ''; }
  R.preview = function (b, ctx) {
    var w = el('div', 'ai-card ai-preview'); w.setAttribute('data-status', b.status); var h = el('div', 'ai-preview__h'); h.appendChild(el('span', 'ai-pill ai-pill--upcoming', 'Preview')); h.appendChild(el('b', '', b.title)); w.appendChild(h); if (b.reason) w.appendChild(el('p', 'ai-preview__r', b.reason));
    var g = el('div', 'ai-diff'); g.appendChild(el('div', '', 'Before')); g.appendChild(el('div', '', kv(b.before) || '—')); g.appendChild(el('div', '', 'After')); g.appendChild(el('div', 'ai-diff__after', kv(b.after) || '—')); w.appendChild(g);
    var bar = el('div', 'ai-preview__a'), msg = el('span', 'ai-preview__m');
    function paint(st) { w.setAttribute('data-status', st); bar.textContent = ''; if (st === 'proposed') { var a = el('button', 'btn btn-dark btn-sm', 'Apply'), x = el('button', 'btn btn-ghost btn-sm', 'Dismiss'); a.type = x.type = 'button'; a.onclick = function () { act('apply'); }; x.onclick = function () { act('dismiss'); }; bar.appendChild(a); bar.appendChild(x); }
      else if (st === 'applied') { bar.appendChild(el('span', 'ai-pill ai-pill--paid', 'Applied')); var u = el('button', 'btn btn-sm', 'Undo'); u.type = 'button'; u.onclick = function () { act('undo'); }; bar.appendChild(u); var lk = el('a', 'ai-link', 'All AI actions'); lk.href = ctx.base + '/ai/actions'; bar.appendChild(lk); }
      else bar.appendChild(el('span', 'ai-pill', st === 'undone' ? 'Undone' : (st === 'dismissed' ? 'Dismissed' : 'Failed'))); bar.appendChild(msg); }
    function act(op) { bar.querySelectorAll('button').forEach(function (x) { x.disabled = true; }); ctx.post(ctx.act, { op: op, id: b.action_id }).then(function (ev) { msg.textContent = ev.message || ''; paint(ev.status || b.status); if (window.toast) toast({ type: ev.type === 'ok' ? 'ok' : 'err', title: ev.message || 'Done' }); if (ev.type === 'ok' && window.MfncSpa && (op === 'apply' || op === 'undo')) { try { MfncSpa.refresh && 0; } catch (e) {} } }); }
    paint(b.status); w.appendChild(bar); return w;
  };
  R.name_prompt = function (b, ctx) {
    var w = el('div', 'ai-card ai-name'); w.appendChild(md(b.text)); var row = el('div', 'ai-name__chips'); [b.suggested].concat(b.alternatives || []).forEach(function (n) { var c = el('button', 'chip', n); c.type = 'button'; c.onclick = function () { ctx.send(n); }; row.appendChild(c); }); w.appendChild(row);
    var f = el('form', 'ai-name__f'), i = el('input', 'input'); i.placeholder = 'Or type your own name'; i.maxLength = 24; i.setAttribute('aria-label', 'Name for your advisor'); var s = el('button', 'btn btn-dark btn-sm', 'Use this name'); f.appendChild(i); f.appendChild(s); f.onsubmit = function (e) { e.preventDefault(); if (i.value.trim()) ctx.send(i.value.trim()); }; w.appendChild(f); return w;
  };

  function init(root) {
    if (!root || root._ai) return; root._ai = true; var data = JSON.parse(d.getElementById('ai-data').textContent), log = root.querySelector('[data-ai-log]'), mount = root.querySelector('[data-ai-composer]'), sug = root.querySelector('[data-ai-suggest]'), bar = null, ctrl = null;
    var base = d.body.getAttribute('data-base') || '', busy = false, stage = data.stage, w = window;
    function csrf() { return d.body.getAttribute('data-csrf') || data.csrf; }
    function post(url, body, onEv, signal) {
      return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() }, body: JSON.stringify(body), signal: signal }).then(function (r) {
        if (!r.body || !r.body.getReader) return r.text().then(function (t) { var last = null; t.split('\n').forEach(function (l) { if (l.trim()) { last = JSON.parse(l); if (onEv) onEv(last); } }); return last; });
        var rd = r.body.getReader(), dec = new TextDecoder(), buf = '', last = null;
        function pump() { return rd.read().then(function (x) { if (x.done) { if (buf.trim()) { last = JSON.parse(buf); if (onEv) onEv(last); } return last; } buf += dec.decode(x.value, { stream: true }); var i; while ((i = buf.indexOf('\n')) >= 0) { var ln = buf.slice(0, i); buf = buf.slice(i + 1); if (ln.trim()) { last = JSON.parse(ln); if (onEv) onEv(last); } } return pump(); }); }
        return pump();
      }).catch(function (err) { if (err && err.name === 'AbortError') { var a = { type: 'aborted' }; if (onEv) onEv(a); return a; } var e = { type: 'error', message: 'Could not reach the server. Check your connection.' }; if (onEv) onEv(e); return e; });
    }
    var ctx = { act: data.endpoints.act, base: base, post: post, send: function (t) { send(t); } };
    function scroll() { log.scrollTop = log.scrollHeight; }
    function bubble(role) { var m = el('div', 'ai-msg ai-msg--' + role); if (role === 'assistant') { var av = el('img', 'ai-avatar'); av.src = data.agent.avatar; av.alt = ''; av.width = av.height = 32; m.appendChild(av); } var body = el('div', 'ai-msg__b'); m.appendChild(body); log.appendChild(m); return body; }
    function renderBlock(body, b) { var f = R[b.type]; if (!f) return; try { body.appendChild(f(b, ctx)); } catch (e) { /* a malformed block must never break the chat */ } }
    data.messages.forEach(function (m) { if (m.role === 'user') bubble('user').appendChild(el('p', '', m.text)); else { var b = bubble('assistant'); (m.blocks || []).forEach(function (x) { renderBlock(b, x); }); } });
    // name prompts and interview chips only make sense on the latest message
    function trimOldPrompts() { var all = log.querySelectorAll('.ai-name'); for (var i = 0; i < all.length - 1; i++) all[i].remove(); }
    trimOldPrompts(); scroll();
    function suggestions() { sug.textContent = ''; if (stage !== 'ready' || log.querySelectorAll('.ai-msg--user').length > 2) return; data.suggestions.slice(0, 5).forEach(function (t) { var c = el('button', 'chip', t); c.type = 'button'; c.onclick = function () { send(t); }; sug.appendChild(c); }); }
    suggestions();
    var SOURCE_NAMES = { web: 'Web search', accounts: 'Accounts', transactions: 'Transactions', bills: 'Bills', goals: 'Goals', budgets: 'Budgets' };
    function ls(k, v) { try { if (v === undefined) return w.localStorage.getItem('mf.ai.' + k); w.localStorage.setItem('mf.ai.' + k, v); } catch (e) { } return null; }
    function nav(href) { var a = d.createElement('a'); a.href = href; if (w.MfncSpa && MfncSpa.go) MfncSpa.go(href); else w.location.href = href; }
    function pickFiles() {
      var fi = d.createElement('input'); fi.type = 'file'; fi.multiple = true; fi.accept = data.upload.accept;
      fi.onchange = function () {
        var files = Array.prototype.slice.call(fi.files || []), have = bar.getState().attachments.length;
        files.forEach(function (f) {
          if (have >= data.upload.max_files) { bar.setNotice('You can attach up to ' + data.upload.max_files + ' files per message.'); return; }
          if (f.size > data.upload.max_bytes) { bar.setNotice(f.name + ' is too big (limit ' + Math.round(data.upload.max_bytes / 1048576) + ' MB).'); return; } have++;
          var h = bar.addAttachment({ name: f.name, status: 'uploading' }), fd = new FormData(); fd.append('file', f);
          fetch(data.endpoints.upload, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf() }, body: fd }).then(function (r) { return r.json().catch(function () { return { type: 'error', message: 'Upload failed.' }; }); })
            .then(function (j) { if (j.type === 'ok') { h.update({ id: j.id, name: j.name, status: 'ready' }); bar.setNotice(''); } else { bar.setNotice(j.message || 'Upload failed.'); h.remove(); } })
            .catch(function () { bar.setNotice('Upload failed. Check your connection.'); h.remove(); });
        });
      };
      fi.click();
    }
    var SR = w.SpeechRecognition || w.webkitSpeechRecognition, rec = null;
    function dictate() {
      return new Promise(function (res, rej) {
        try { rec = new SR(); rec.lang = d.documentElement.lang === 'en' ? (navigator.language || 'en-IN') : d.documentElement.lang; rec.interimResults = false; rec.maxAlternatives = 1; rec.continuous = false; var got = ''; rec.onresult = function (e) { got = e.results[0][0].transcript; }; rec.onerror = function () { rej(); }; rec.onend = function () { res(got); }; rec.start(); } catch (e) { rej(e); }
      });
    }
    function buildBar() {
      if (!mount || !w.PromptBar) return; var src = [{ key: 'files', name: 'Photos & files', description: 'Upload from this device', icon: 'clip', attach: true }];
      if (data.web) src.push({ key: 'web', name: SOURCE_NAMES.web, description: 'Live results', icon: 'globe' });
      data.scopes.forEach(function (k) { src.push({ key: k, name: SOURCE_NAMES[k], description: 'Focus on my ' + SOURCE_NAMES[k].toLowerCase(), icon: 'data' }); });
      var models = data.models, def = ls('model') && models.some(function (m) { return m.key === ls('model') && !m.locked; }) ? ls('model') : data.defaultModel, eff = ls('effort') && data.efforts.indexOf(ls('effort')) >= 0 ? ls('effort') : data.defaultEffort;
      bar = PromptBar.create({ placeholder: stage === 'ready' ? 'Ask anything about your money' : 'Type your answer', sources: src, commands: data.commands, models: models, defaultModel: def, efforts: data.efforts, defaultEffort: eff, className: 'prompt-bar--app',
        onEffortChange: function (e) { ls('effort', e); }, onModelChange: function (k) { ls('model', k); }, onNavigate: nav, onLocked: function () { nav(data.endpoints.shop); }, onSend: function (t, o) { send(t, o); }, onStop: stop, onAttach: pickFiles,
        onDictate: SR ? dictate : null, onDictateCancel: function () { try { rec && rec.abort(); } catch (e) { } }, maxRows: 5 });
      bar.setModels(models, { name: 'Get in Plugins', href: data.endpoints.shop, description: 'Premium models' });
      mount.appendChild(bar.el);
    }
    function stop() { if (ctrl) ctrl.abort(); }
    function parseSources(text) {
      var low = text.toLowerCase(), scopes = [], web = low.indexOf('@web search') >= 0; data.scopes.forEach(function (k) { if (low.indexOf('@' + SOURCE_NAMES[k].toLowerCase()) >= 0) scopes.push(k); }); return { web: web, sources: scopes };
    }
    function send(text, o) {
      text = (text || '').trim(); o = o || (bar ? { attachments: bar.getState().attachments, model: bar.getState().model, effort: bar.getState().effort } : {});
      var files = (o.attachments || []).filter(function (a) { return a.id; }); if ((!text && !files.length) || busy) return; busy = true; sug.textContent = ''; if (bar) bar.setBusy(true);
      var shown = text || 'Please look at the attached file.'; var u = bubble('user'); u.appendChild(el('p', '', shown)); if (files.length) u.appendChild(el('p', 'ai-attached', 'Attached: ' + files.map(function (f) { return f.name; }).join(', '))); scroll();
      var body = bubble('assistant'), box = el('div', 'ai-status'), steps = el('ul', 'status-steps status-steps--chat'), list = w.StatusMark ? StatusMark.list(steps) : null; box.setAttribute('role', 'status'); box.appendChild(steps); var st = el('span', 'ai-status__t sr-only', 'Thinking…'); box.appendChild(st); body.appendChild(box); scroll();
      ctrl = w.AbortController ? new AbortController() : null; var ps = parseSources(shown), stopped = false;
      post(data.endpoints.chat, { message: text, model: o.model && o.model.key, effort: o.effort, files: files.map(function (f) { return f.id; }), sources: ps.sources, web: ps.web }, function (ev) {
        if (ev.type === 'status') st.textContent = ev.text;
        else if (ev.type === 'step' && list) { list.set(ev); scroll(); }
        else if (ev.type === 'block') { renderBlock(body, ev.block); body.appendChild(box); scroll(); }
        else if (ev.type === 'stage') { stage = ev.stage; if (ev.name) { root.querySelector('[data-ai-name]').textContent = ev.name; data.agent.name = ev.name; if (ev.avatar) { data.agent.avatar = ev.avatar; root.querySelectorAll('.ai-chat__head .ai-avatar').forEach(function (a) { a.src = ev.avatar; }); } } if (stage === 'ready') { root.querySelector('[data-ai-sub]').textContent = 'Ready. Ask anything about your money.'; if (bar) bar.configure({ placeholder: 'Ask anything about your money' }); } }
        else if (ev.type === 'aborted' || ev.type === 'cancelled') { stopped = true; }
        else if (ev.type === 'error') { var c = el('div', 'ai-callout ai-callout--bad'); c.appendChild(el('p', '', ev.message)); if (ev.code === 'wallet' || ev.code === 'plan' || ev.code === 'budget') { var a = el('a', 'btn btn-sm', 'Plans & wallet'); a.href = base + '/plans'; c.appendChild(a); } else if (ev.code === 'model_locked' || ev.code === 'model') { var a2 = el('a', 'btn btn-sm', 'Get in Plugins'); a2.href = base + '/plugins'; c.appendChild(a2); } body.appendChild(c); }
      }, ctrl && ctrl.signal).then(function () {
        if (list) list.cancelRunning(); if (stopped) { var n = el('p', 'ai-stopped', 'Stopped.'); body.appendChild(n); }
        var n2 = steps.children.length; if (n2) { var dt = el('details', 'ai-steps-done'); dt.appendChild(el('summary', '', n2 + (n2 === 1 ? ' step' : ' steps'))); dt.appendChild(steps); box.replaceWith(dt); body.insertBefore(dt, body.firstChild); } else box.remove();
        if (!body.childNodes.length) body.closest('.ai-msg').remove(); busy = false; ctrl = null; if (bar) { bar.setBusy(false); bar.focus(); } trimOldPrompts(); suggestions(); scroll();
      });
    }
    buildBar();
  }
  function boot() { init(d.querySelector('[data-ai]')); }
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', boot); else boot(); d.addEventListener('mfnc:page', boot);
})();
