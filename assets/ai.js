/* AI advisor chat (round 4 redesign). Renders structured blocks (text, stats, table, chart, goal progress, bill list, comparison, calculator steps, action previews, document cards)
   with DOM APIs and textContent only, so nothing the model says can inject markup. Talks to /ai/chat (NDJSON stream) and /ai/act (apply / dismiss / undo / cancel a document). */
(function () {
  'use strict';
  var d = document, w = window;
  function el(tag, cls, text) { var e = d.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  var inr = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 });
  function money(v) { var n = Number(v); if (!isFinite(n)) return String(v); return inr.format(n).replace(/\.00$/, ''); }
  function micro(v) { var n = Number(v) / 1e6; return n >= 100 ? money(Math.round(n)) : '₹' + n.toFixed(2); }
  function fmtCell(v, type) { if (typeof v === 'number') { if (type === 'money') return money(v); if (type === 'pct') return (Math.round(v * 10) / 10) + '%'; return String(Math.round(v * 100) / 100); } return String(v == null ? '' : v); }
  // small line icons (same set as the rest of the app: 24px grid, 1.8 stroke)
  var ICON = { mic: ['M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z', 'M19 10v2a7 7 0 0 1-14 0v-2', 'M12 19v3'], copy: ['M9 9h11v11H9z', 'M5 15V5a1 1 0 0 1 1-1h10'], speaker: ['M11 5 6 9H3v6h3l5 4z', 'M15.5 8.5a5 5 0 0 1 0 7', 'M18.5 5.5a9 9 0 0 1 0 13'], redo: ['M21 12a9 9 0 1 1-3-6.7L21 8', 'M21 3v5h-5'], down: ['M12 5v14', 'm19 12-7 7-7-7'], check: ['M20 6 9 17l-5-5'], file: ['M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z', 'M14 2v6h6', 'M8 13h8', 'M8 17h5'], image: ['M3 5h18v14H3z', 'm3 16 5-5 4 4 3-3 6 6', 'M9 9.5h.01'], clip: ['m21.4 11.1-9.2 9.2a6 6 0 0 1-8.5-8.5l8.6-8.6A4 4 0 1 1 18 8.8l-8.6 8.6a2 2 0 0 1-2.8-2.8l8.5-8.5'], plus: ['M12 5v14', 'M5 12h14'], bill: ['M6 3h12v18l-3-2-3 2-3-2-3 2z', 'M9 8h6', 'M9 12h6'], chart: ['M3 3v18h18', 'm7 15 4-5 3 3 5-7'], target: ['M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z', 'M12 17a5 5 0 1 0 0-10 5 5 0 0 0 0 10z', 'M12 13a1 1 0 1 0 0-2 1 1 0 0 0 0 2z'], percent: ['m19 5-14 14', 'M7.5 9a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z', 'M16.5 18a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z'], upload: ['M12 3v12', 'm7 8 5-5 5 5', 'M5 21h14'] };
  function svg(name, size) { var NS = 'http://www.w3.org/2000/svg', s = d.createElementNS(NS, 'svg'); s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('width', size || 16); s.setAttribute('height', size || 16); s.setAttribute('fill', 'none'); s.setAttribute('stroke', 'currentColor'); s.setAttribute('stroke-width', '1.8'); s.setAttribute('stroke-linecap', 'round'); s.setAttribute('stroke-linejoin', 'round'); s.setAttribute('aria-hidden', 'true'); (ICON[name] || []).forEach(function (p) { var e = d.createElementNS(NS, 'path'); e.setAttribute('d', p); s.appendChild(e); }); return s; }

  // ---------- safe markdown: headings, lists, tables, code, quotes, bold / italic / code / links. Everything is built with DOM nodes and textContent. ----------
  var INLINE = /(`[^`\n]+`)|(\*\*[^*\n]+?\*\*)|(__[^_\n]+?__)|((?:^|[\s(])\*[^*\s][^*\n]*?\*(?=$|[\s).,;:!?]))|(\[[^\]\n]+\]\(https?:\/\/[^\s)]+\))|(https?:\/\/[^\s<>()]+[^\s<>().,;:!?'"])/g;
  function inline(node, s) {
    var last = 0, m; INLINE.lastIndex = 0;
    while ((m = INLINE.exec(s))) {
      if (m.index > last) node.appendChild(d.createTextNode(s.slice(last, m.index)));
      var t = m[0];
      if (m[1]) node.appendChild(el('code', '', t.slice(1, -1)));
      else if (m[2] || m[3]) node.appendChild(el('strong', '', t.slice(2, -2)));
      else if (m[4]) { var lead = /^[\s(]/.test(t) ? t.charAt(0) : ''; if (lead) node.appendChild(d.createTextNode(lead)); node.appendChild(el('em', '', t.slice(lead.length + 1, -1))); }
      else if (m[5]) { var mm = /^\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)$/.exec(t); var a = el('a', '', mm[1]); a.href = mm[2]; a.target = '_blank'; a.rel = 'noopener noreferrer'; node.appendChild(a); }
      else if (m[6]) { var a2 = el('a', '', t); a2.href = t; a2.target = '_blank'; a2.rel = 'noopener noreferrer'; node.appendChild(a2); }
      last = m.index + t.length;
    }
    if (last < s.length) node.appendChild(d.createTextNode(s.slice(last)));
  }
  function md(text) {
    var wrap = el('div', 'ai-text'), lines = String(text).replace(/\r\n?/g, '\n').split('\n'), i = 0;
    function para(buf) { if (!buf.length) return; var p = el('p'); buf.forEach(function (ln, k) { if (k) p.appendChild(el('br')); inline(p, ln.trim()); }); wrap.appendChild(p); }
    var buf = [];
    while (i < lines.length) {
      var ln = lines[i], m;
      if (/^```/.test(ln)) { para(buf); buf = []; var code = []; i++; while (i < lines.length && !/^```/.test(lines[i])) { code.push(lines[i]); i++; } i++; var pre = el('pre'); pre.appendChild(el('code', '', code.join('\n'))); wrap.appendChild(pre); continue; }
      if ((m = /^(#{1,4})\s+(.*)$/.exec(ln))) { para(buf); buf = []; var h = el(m[1].length <= 2 ? 'h3' : 'h4'); inline(h, m[2]); wrap.appendChild(h); i++; continue; }
      if (/^\s*\|.*\|\s*$/.test(ln) && i + 1 < lines.length && /^\s*\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$/.test(lines[i + 1])) {
        para(buf); buf = []; var cells = function (s) { return s.trim().replace(/^\||\|$/g, '').split('|').map(function (c) { return c.trim(); }); }, head = cells(ln); i += 2; var rows = [];
        while (i < lines.length && /^\s*\|.*\|\s*$/.test(lines[i])) { rows.push(cells(lines[i])); i++; }
        var sc = el('div', 'ai-mdtable'), tb = el('table'), thd = el('thead'), tr = el('tr'); head.forEach(function (c) { var th = el('th'); th.scope = 'col'; inline(th, c); tr.appendChild(th); }); thd.appendChild(tr); tb.appendChild(thd); var tbd = el('tbody');
        rows.slice(0, 60).forEach(function (r) { var tr2 = el('tr'); head.forEach(function (_, k) { var td = el('td'); inline(td, r[k] || ''); tr2.appendChild(td); }); tbd.appendChild(tr2); }); tb.appendChild(tbd); sc.appendChild(tb); wrap.appendChild(sc); continue;
      }
      if (/^\s*([-*•]|\d+[.)])\s+/.test(ln)) {
        para(buf); buf = []; var ord = /^\s*\d+[.)]\s+/.test(ln), list = el(ord ? 'ol' : 'ul');
        while (i < lines.length && /^\s*([-*•]|\d+[.)])\s+/.test(lines[i])) { var li = el('li'); inline(li, lines[i].replace(/^\s*([-*•]|\d+[.)])\s+/, '')); list.appendChild(li); i++; }
        wrap.appendChild(list); continue;
      }
      if (/^>\s?/.test(ln)) { para(buf); buf = []; var q = []; while (i < lines.length && /^>\s?/.test(lines[i])) { q.push(lines[i].replace(/^>\s?/, '')); i++; } var bq = el('blockquote'); inline(bq, q.join(' ')); wrap.appendChild(bq); continue; }
      if (ln.trim() === '') { para(buf); buf = []; i++; continue; }
      buf.push(ln); i++;
    }
    para(buf); return wrap;
  }

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
    b.steps.forEach(function (s, idx) { var li = el('li'); if (!s.value && !s.formula) li.className = 'sep'; else if (window.StatusMark) { var mk = StatusMark.create({ status: 'running', size: 14, strike: false, doneColor: '', errorColor: '', className: 'ai-step__mark' }); li.appendChild(mk.el); setTimeout(function () { mk.update({ status: 'done' }); }, 120 + idx * 110); } li.appendChild(el('span', 'ai-step__l', s.label)); if (s.formula) li.appendChild(el('code', 'ai-step__f', s.formula)); if (s.value) li.appendChild(el('b', 'ai-step__v', s.value)); ol.appendChild(li); });
    w.appendChild(ol); if (b.result) { var r = el('div', 'ai-result'); r.appendChild(el('span', '', b.result.label)); r.appendChild(el('b', '', b.result.value)); w.appendChild(r); } return w;
  };

  function kv(o) { var keys = Object.keys(o || {}); return keys.length ? keys.map(function (k) { var v = o[k]; return k.replace(/_/g, ' ') + ': ' + (v == null ? '—' : (typeof v === 'number' ? money(v) : v)); }).join(' · ') : ''; }
  R.preview = function (b, ctx) {
    var w = el('div', 'ai-card ai-preview'); w.setAttribute('data-status', b.status); var h = el('div', 'ai-preview__h'); h.appendChild(el('span', 'ai-pill ai-pill--upcoming', 'Preview')); h.appendChild(el('b', '', b.title)); w.appendChild(h); if (b.reason) w.appendChild(el('p', 'ai-preview__r', b.reason));
    if (b.rows && b.rows.length) {   // many lines read from a document: the first few, with their dates and amounts
      var rows = el('div', 'ai-preview__rows'); b.rows.forEach(function (r) { var row = el('div', 'ai-prow'); row.appendChild(el('span', '', r[0])); row.appendChild(el('span', '', r[1])); row.appendChild(el('span', 'num' + (r[3] === 'income' ? ' in' : ''), (r[3] === 'income' ? '+' : '') + money(r[2] / 100))); rows.appendChild(row); });
      if ((b.rows_total || 0) > b.rows.length) rows.appendChild(el('div', 'ai-more', '…and ' + (b.rows_total - b.rows.length) + ' more'));
      w.appendChild(rows);
    }
    var g = el('div', 'ai-diff'); g.appendChild(el('div', '', 'Before')); g.appendChild(el('div', '', kv(b.before) || '—')); g.appendChild(el('div', '', 'After')); g.appendChild(el('div', 'ai-diff__after', kv(b.after) || '—')); w.appendChild(g);
    var bar = el('div', 'ai-preview__a'), msg = el('span', 'ai-preview__m');
    function paint(st) { w.setAttribute('data-status', st); bar.textContent = ''; if (st === 'proposed') { var a = el('button', 'btn btn-dark btn-sm', 'Apply'), x = el('button', 'btn btn-ghost btn-sm', 'Dismiss'); a.type = x.type = 'button'; a.onclick = function () { act('apply'); }; x.onclick = function () { act('dismiss'); }; bar.appendChild(a); bar.appendChild(x); }
      else if (st === 'applied') { bar.appendChild(el('span', 'ai-pill ai-pill--paid', 'Applied')); var u = el('button', 'btn btn-sm', 'Undo'); u.type = 'button'; u.onclick = function () { act('undo'); }; bar.appendChild(u); var lk = el('a', 'ai-link', 'All AI actions'); lk.href = ctx.base + '/ai/actions'; bar.appendChild(lk); }
      else bar.appendChild(el('span', 'ai-pill', st === 'undone' ? 'Undone' : (st === 'dismissed' ? 'Dismissed' : 'Failed'))); bar.appendChild(msg); }
    function act(op) { bar.querySelectorAll('button').forEach(function (x) { x.disabled = true; }); ctx.post(ctx.act, { op: op, id: b.action_id }).then(function (ev) { msg.textContent = ev.message || ''; paint(ev.status || b.status); if (window.toast) toast({ type: ev.type === 'ok' ? 'ok' : 'err', title: ev.message || 'Done' }); }); }
    paint(b.status); w.appendChild(bar); return w;
  };
  // ---- documents: the price card (before anything is read) and the result card (what was read and what it cost) ----
  R.doc_estimate = function (b, ctx) {
    var w = el('div', 'ai-card ai-doc'); w.setAttribute('data-status', b.status || 'open'); var h = el('div', 'ai-doc__h'), ic = el('span', 'ai-doc__ic'); ic.appendChild(svg(b.docs && b.docs[0] && b.docs[0].kind === 'image' ? 'image' : 'file', 20)); h.appendChild(ic);
    var t = el('div'); t.appendChild(el('div', 'ai-doc__t', 'Ready to read ' + b.pages + (b.pages === 1 ? ' page' : ' pages'))); t.appendChild(el('div', 'ai-doc__s', 'Nothing is read, saved or charged until you continue')); h.appendChild(t); w.appendChild(h);
    var ul = el('ul', 'ai-doc__list'); (b.docs || []).forEach(function (x) { var li = el('li'); li.appendChild(el('span', '', x.name + ' · ' + x.pages + (x.pages === 1 ? ' page' : ' pages'))); li.appendChild(el('b', 'num', '≈ ' + micro(x.total))); ul.appendChild(li); }); w.appendChild(ul);
    var pr = el('div', 'ai-doc__price'), l = el('div'); l.appendChild(el('small', '', 'Estimated cost')); l.appendChild(el('div', '', ''));
    var pays = b.pays === 'free' ? 'Free for you' : (b.pays === 'wallet' ? 'From your wallet' : (b.pays === 'budget+wallet' ? 'AI budget + wallet fee' : 'From your AI budget'));
    pr.appendChild(el('b', '', '≈ ' + micro(b.total_micro))); var r = el('small', '', pays + (b.fee_micro > 0 ? ' · AI ' + micro(b.token_micro) + ' + service fee ' + micro(b.fee_micro) : '')); pr.appendChild(r); w.appendChild(pr);
    if (b.note) w.appendChild(el('p', 'ai-doc__note', b.note));
    var bar = el('div', 'ai-doc__actions'); function closed(t2) { bar.textContent = ''; bar.appendChild(el('span', 'ai-pill', t2)); w.setAttribute('data-status', 'closed'); }
    if ((b.status || 'open') === 'open') {
      var go = el('button', 'btn btn-dark', 'Read it · ≈ ' + micro(b.total_micro)), no = el('button', 'btn btn-ghost', 'Cancel'); go.type = no.type = 'button';
      go.onclick = function () { closed('Reading…'); ctx.read(b); }; no.onclick = function () { closed('Cancelled'); ctx.post(ctx.act, { op: 'docs_cancel', files: b.files }).then(function (ev) { if (window.toast) toast({ type: 'ok', title: ev.message || 'Cancelled' }); }); };
      bar.appendChild(go); bar.appendChild(no);
    } else bar.appendChild(el('span', 'ai-pill', 'Done or expired'));
    w.appendChild(bar); return w;
  };
  R.doc_result = function (b) {
    var w = el('div', 'ai-card ai-doc'), h = el('div', 'ai-doc__h'), ic = el('span', 'ai-doc__ic'); ic.appendChild(svg(b.kind === 'image' ? 'image' : 'file', 20)); h.appendChild(ic);
    var t = el('div'); t.appendChild(el('div', 'ai-doc__t', 'I read ' + b.name)); t.appendChild(el('div', 'ai-doc__s', (b.pages_read === b.pages ? b.pages : b.pages_read + ' of ' + b.pages) + (b.pages === 1 ? ' page' : ' pages') + ' · ' + b.model + (b.calls > 1 ? ' · ' + b.calls + ' reads' : ''))); h.appendChild(t); w.appendChild(h);
    var dl = el('dl', 'ai-kv'); function cell(k, v) { var x = el('div'); x.appendChild(el('dt', '', k)); x.appendChild(el('dd', '', v)); dl.appendChild(x); }
    cell('Transactions found', String(b.rows)); if (b.dups) cell('Already in your ledger', String(b.dups)); if (b.bills) cell('Bills', String(b.bills)); if (b.account) cell('Into account', b.account); cell('Cost', b.total_micro > 0 ? micro(b.total_micro) : 'Free for you');
    if (b.total_micro > 0 && b.fee_micro > 0) cell('of which fee', micro(b.fee_micro)); w.appendChild(dl);
    if (b.cut) w.appendChild(el('p', 'ai-doc__note', b.cut + ' more lines were left out (limit per document).'));
    if (b.notes && b.notes.length) { var ul = el('ul', 'ai-doc__notes'); b.notes.forEach(function (n) { ul.appendChild(el('li', '', n)); }); w.appendChild(ul); }
    return w;
  };

  R.name_prompt = function (b, ctx) {
    var w = el('div', 'ai-card ai-name'); w.appendChild(md(b.text)); var row = el('div', 'ai-name__chips'); [b.suggested].concat(b.alternatives || []).forEach(function (n) { var c = el('button', 'chip', n); c.type = 'button'; c.onclick = function () { ctx.send(n); }; row.appendChild(c); }); w.appendChild(row);
    var f = el('form', 'ai-name__f'), i = el('input', 'input'); i.placeholder = 'Or type your own name'; i.maxLength = 24; i.setAttribute('aria-label', 'Name for your advisor'); var s = el('button', 'btn btn-dark btn-sm', 'Use this name'); f.appendChild(i); f.appendChild(s); f.onsubmit = function (e) { e.preventDefault(); if (i.value.trim()) ctx.send(i.value.trim()); }; w.appendChild(f); return w;
  };


  w.MfAiBlocks = { R: R, md: md, money: money };   // the voice assistant (voicedock.js) renders the same cards in its panel

  function init(root) {
    if (!root || root._ai) return; root._ai = true;
    var data = JSON.parse(d.getElementById('ai-data').textContent), log = root.querySelector('[data-ai-log]'), mount = root.querySelector('[data-ai-composer]'), sug = root.querySelector('[data-ai-suggest]'), down = root.querySelector('[data-ai-down]'), subEl = root.querySelector('[data-ai-sub]'), bar = null, ctrl = null;
    var base = d.body.getAttribute('data-base') || '', busy = false, stage = data.stage, lastUser = '', lastDay = '', stick = true, reduce = w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches, subIdle = subEl ? subEl.textContent : '';
    function csrf() { return d.body.getAttribute('data-csrf') || data.csrf; }
    function post(url, body, onEv, signal) {
      return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() }, body: JSON.stringify(body), signal: signal }).then(function (r) {
        if (!r.body || !r.body.getReader) return r.text().then(function (t) { var last = null; t.split('\n').forEach(function (l) { if (l.trim()) { last = JSON.parse(l); if (onEv) onEv(last); } }); return last; });
        var rd = r.body.getReader(), dec = new TextDecoder(), buf = '', last = null;
        function pump() { return rd.read().then(function (x) { if (x.done) { if (buf.trim()) { last = JSON.parse(buf); if (onEv) onEv(last); } return last; } buf += dec.decode(x.value, { stream: true }); var i; while ((i = buf.indexOf('\n')) >= 0) { var ln = buf.slice(0, i); buf = buf.slice(i + 1); if (ln.trim()) { last = JSON.parse(ln); if (onEv) onEv(last); } } return pump(); }); }
        return pump();
      }).catch(function (err) { if (err && err.name === 'AbortError') { var a = { type: 'aborted' }; if (onEv) onEv(a); return a; } var e = { type: 'error', message: 'Could not reach the server. Check your connection.' }; if (onEv) onEv(e); return e; });
    }
    var ctx = { act: data.endpoints.act, base: base, post: post, send: function (t) { send(t); }, read: function (b) { send(b.text, { resume: true, docs_ok: true, fileIds: b.files, model: { key: b.model }, effort: b.effort }); } };

    // ---------- layout: the card fills the viewport; the composer is pinned; keyboard aware ----------
    function fit() {
      var vv = w.visualViewport, vh = vv ? vv.height : w.innerHeight, kb = vv && (w.innerHeight - vv.height) > 140, top = root.getBoundingClientRect().top + w.scrollY, reserve = 14;
      function shown(x) { return x && x.getClientRects().length > 0 && w.getComputedStyle(x).visibility !== 'hidden'; } var nav = d.querySelector('.m-pill'), dock = d.querySelector('.mf-dock'), el2 = shown(nav) ? nav : (shown(dock) ? dock : null);
      if (el2 && !kb) { var r = el2.getBoundingClientRect(); reserve = Math.max(14, w.innerHeight - r.top + 10); }
      var h = Math.max(vh < 600 ? 220 : 400, Math.round(vh - (top - w.scrollY) - reserve));
      root.style.setProperty('--ai-h', h + 'px'); root.style.setProperty('--ai-mb', Math.round(reserve) + 'px');
    }
    fit(); w.addEventListener('resize', fit); w.addEventListener('orientationchange', fit); if (w.visualViewport) { w.visualViewport.addEventListener('resize', fit); }
    root.addEventListener('focusin', function () { setTimeout(fit, 80); setTimeout(fit, 400); }); root.addEventListener('focusout', function () { setTimeout(fit, 120); });   // the pill nav hides while typing (ai.css)
    d.addEventListener('mfnc:page', function () { setTimeout(fit, 60); }); setTimeout(fit, 250); setTimeout(fit, 900);
    // ---------- scrolling: stay at the bottom while it streams, unless the person scrolled up ----------
    function atBottom() { return log.scrollHeight - log.scrollTop - log.clientHeight < 140; }
    log.addEventListener('scroll', function () { stick = atBottom(); down.classList.toggle('is-on', !stick); if (stick) down.classList.remove('has-new'); }, { passive: true });
    function scroll(force) { if (force || stick) { log.scrollTop = log.scrollHeight; stick = true; down.classList.remove('is-on', 'has-new'); } else { down.classList.add('is-on', 'has-new'); } }
    down.onclick = function () { if (log.scrollTo && !reduce) log.scrollTo({ top: log.scrollHeight, behavior: 'smooth' }); else log.scrollTop = log.scrollHeight; };
    // ---------- messages ----------
    function fmtTime(at) { var t = at ? new Date(at) : new Date(); if (isNaN(t)) t = new Date(); try { return new Intl.DateTimeFormat(undefined, { hour: 'numeric', minute: '2-digit' }).format(t); } catch (e) { return ''; } }
    function dayLabel(at) { var t = at ? new Date(at) : new Date(); if (isNaN(t)) t = new Date(); var k = t.getFullYear() + '-' + t.getMonth() + '-' + t.getDate(), n = new Date(), y = new Date(Date.now() - 864e5); if (k === n.getFullYear() + '-' + n.getMonth() + '-' + n.getDate()) return ['t', 'Today']; if (k === y.getFullYear() + '-' + y.getMonth() + '-' + y.getDate()) return ['y', 'Yesterday']; try { return [k, new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short', year: 'numeric' }).format(t)]; } catch (e) { return [k, k]; } }
    function bubble(role, at) {
      hideEmpty(); var dl = dayLabel(at); if (dl[0] !== lastDay) { lastDay = dl[0]; log.appendChild(el('div', 'ai-day', dl[1])); }
      var row = el('div', 'ai-msg ai-msg--' + role), col = el('div', 'ai-msg__col'), body = el('div', 'ai-msg__b');
      if (role === 'assistant') { var av = el('img', 'ai-avatar'); av.src = data.agent.avatar; av.alt = ''; av.width = av.height = 34; row.appendChild(av); var meta = el('div', 'ai-msg__meta'); var nm = el('b', '', data.agent.name || 'Advisor'); nm.setAttribute('data-ai-who', ''); meta.appendChild(nm); var tm = el('time', '', fmtTime(at)); meta.appendChild(tm); col.appendChild(meta); col.appendChild(body); }
      else { col.appendChild(body); var m2 = el('div', 'ai-msg__meta'); m2.appendChild(el('time', '', fmtTime(at))); col.appendChild(m2); }
      row.appendChild(col); log.appendChild(row); return body;
    }
    function voiceBadge(body) { var row = body.closest('.ai-msg'), meta = row && row.querySelector('.ai-msg__meta'); if (!meta) return; var bd = el('span', 'ai-voicebadge'); bd.appendChild(svg('mic', 12)); bd.appendChild(el('span', 'sr-only', 'Spoken with voice')); bd.title = 'Spoken with voice'; meta.insertBefore(bd, meta.firstChild); }
    function renderBlock(body, b) { var f = R[b.type]; if (!f) return; try { body.appendChild(f(b, ctx)); } catch (e) { /* a malformed block must never break the chat */ } }
    function plain(body) { var out = []; Array.prototype.forEach.call(body.children, function (c) { if (c.classList.contains('ai-status') || c.classList.contains('ai-steps-done')) return; var t = (c.innerText || c.textContent || '').trim(); if (t) out.push(t); }); return out.join('\n\n'); }
    function copyText(t, btn) {
      function fb() { var x = d.createElement('textarea'); x.value = t; x.style.cssText = 'position:fixed;opacity:0'; d.body.appendChild(x); x.select(); try { d.execCommand('copy'); } catch (e) { } x.remove(); }
      if (w.navigator.clipboard && w.navigator.clipboard.writeText) w.navigator.clipboard.writeText(t).catch(fb); else fb();
      if (btn) { btn.setAttribute('aria-pressed', 'true'); var lab = btn.querySelector('span'); if (lab) lab.textContent = 'Copied'; setTimeout(function () { btn.setAttribute('aria-pressed', 'false'); if (lab) lab.textContent = 'Copy'; }, 1800); }
    }
    function tool(label, icon, attrs) { var b = el('button', 'ai-tool'); b.type = 'button'; b.title = label; b.setAttribute('aria-label', label); b.appendChild(svg(icon, 16)); b.appendChild(el('span', 'sr-only', label)); if (attrs) for (var k in attrs) b.setAttribute(k, attrs[k]); return b; }
    function addTools(body, last) {
      var col = body.parentNode; if (!col || col.querySelector('.ai-msg__tools') || !body.childNodes.length) return; var bar2 = el('div', 'ai-msg__tools'), cp = tool('Copy', 'copy'); cp.querySelector('span').className = ''; cp.querySelector('span').textContent = 'Copy'; cp.onclick = function () { copyText(plain(body), cp); }; bar2.appendChild(cp);
      var rd = tool('Read aloud', 'speaker', { 'data-ai-read': '' }); rd.hidden = true; bar2.appendChild(rd); if (w.MfVoice && w.MfVoice.bind) w.MfVoice.bind(rd, function () { return w.MfVoice.textOf(body); }, 'chat');
      if (last && lastUser && stage === 'ready') { var rg = tool('Try again', 'redo', { 'data-ai-regen': '' }); rg.onclick = function () { if (!busy) send(lastUser); }; bar2.appendChild(rg); }
      col.appendChild(bar2);
    }
    function lastTools() { log.querySelectorAll('[data-ai-regen]').forEach(function (b) { b.remove(); }); }
    data.messages.forEach(function (m) {
      if (m.role === 'user') { var u = bubble('user', m.at); if (m.voice) voiceBadge(u); u.appendChild(el('p', '', m.text)); if (m.files && m.files.length) { var at = el('div', 'ai-attached'); at.appendChild(svg('clip', 13)); at.appendChild(el('span', '', m.files.join(', '))); u.appendChild(at); } lastUser = m.text; }
      else { var b = bubble('assistant', m.at); if (m.voice) voiceBadge(b); (m.blocks || []).forEach(function (x) { renderBlock(b, x); }); addTools(b, false); }
    });
    var allA = log.querySelectorAll('.ai-msg--assistant .ai-msg__b'); if (allA.length && stage === 'ready') { var lb = allA[allA.length - 1]; var colL = lb.parentNode; var rg0 = tool('Try again', 'redo', { 'data-ai-regen': '' }); rg0.onclick = function () { if (!busy && lastUser) send(lastUser); }; var tl = colL.querySelector('.ai-msg__tools'); if (tl && lastUser) tl.appendChild(rg0); }
    // name prompts and interview chips only make sense on the latest message
    function trimOldPrompts() { var all = log.querySelectorAll('.ai-name'); for (var i = 0; i < all.length - 1; i++) all[i].remove(); }
    trimOldPrompts();
    // ---------- empty state ----------
    var emptyEl = null;
    function hideEmpty() { if (emptyEl) { emptyEl.remove(); emptyEl = null; } }
    function showEmpty() {
      if (emptyEl || stage !== 'ready' || log.querySelector('.ai-msg')) return; emptyEl = el('div', 'ai-empty'); var lg = el('div', 'ai-empty__logo'), pm = el('span', 'pills-mark'); pm.setAttribute('data-pills', ''); pm.setAttribute('data-size', '56'); pm.setAttribute('aria-hidden', 'true'); lg.appendChild(pm); emptyEl.appendChild(lg);
      emptyEl.appendChild(el('h2', '', 'Hi ' + (data.user || 'there') + ', I’m ' + (data.agent.name || 'your advisor'))); emptyEl.appendChild(el('p', '', 'Ask me anything about your money. Or hand me a receipt, a photo of a statement or a PDF, and I’ll turn it into entries you approve first. Nothing is saved until you tap Apply.'));
      var grid = el('div', 'ai-sgrid'), cards = [['How am I doing this month?', 'Income, spending and what is left', null], ['Where does my money go?', 'Spending by category, with a chart', null], ['Read a receipt or statement', data.docs && data.docs.pdf ? 'Photo or PDF, price shown first' : 'Photo now; PDFs are a plugin', 'files'], ['Which bills are due?', 'Upcoming and overdue', null], ['Compare old vs new tax regime', 'With your own numbers', null], ['What would a ₹10 lakh loan at 9% cost me?', 'EMI and total interest', null]];
      cards.forEach(function (c) { var b = el('button', 'ai-sbtn'); b.type = 'button'; b.appendChild(el('b', '', c[0])); b.appendChild(el('span', '', c[1])); b.onclick = function () { if (c[2] === 'files') pickFiles(); else send(c[0]); }; grid.appendChild(b); }); emptyEl.appendChild(grid);
      var chips = el('div', 'ai-chips'); [['Add an expense', 'plus', 'I want to add an expense'], ['Upload a document', 'upload', 'files'], ['Set a budget', 'percent', 'Help me set a budget'], ['Plan a goal', 'target', 'Help me plan a savings goal']].forEach(function (c) { var b = el('button', 'chip'); b.type = 'button'; b.appendChild(svg(c[1], 15)); b.appendChild(el('span', '', c[0])); b.onclick = function () { if (c[2] === 'files') pickFiles(); else send(c[2]); }; chips.appendChild(b); }); emptyEl.appendChild(chips);
      log.appendChild(emptyEl); if (w.PillsMark) { try { w.PillsMark.scan(emptyEl); } catch (e) { } }
    }
    function suggestions() { sug.textContent = ''; if (stage !== 'ready' || emptyEl || log.querySelectorAll('.ai-msg--user').length > 2) return; data.suggestions.slice(0, 5).forEach(function (t) { var c = el('button', 'chip', t); c.type = 'button'; c.onclick = function () { send(t); }; sug.appendChild(c); }); }
    showEmpty(); suggestions(); if (emptyEl) log.scrollTop = 0; else scroll(true);
    var SOURCE_NAMES = { web: 'Web search', accounts: 'Accounts', transactions: 'Transactions', bills: 'Bills', goals: 'Goals', budgets: 'Budgets' };
    function ls(k, v) { try { if (v === undefined) return w.localStorage.getItem('mf.ai.' + k); w.localStorage.setItem('mf.ai.' + k, v); } catch (e) { } return null; }
    function nav(href) { var a = d.createElement('a'); a.href = href; if (w.MfncSpa && MfncSpa.go) MfncSpa.go(href); else w.location.href = href; }
    function pickFiles() {
      var fi = d.createElement('input'); fi.type = 'file'; fi.multiple = true; fi.accept = data.upload.accept;
      fi.onchange = function () {
        var files = Array.prototype.slice.call(fi.files || []), have = bar.getState().attachments.length, keep = '';
        files.forEach(function (f) {
          if (have >= data.upload.max_files) { keep = 'You can attach up to ' + data.upload.max_files + ' files per message. The extra ones were not added.'; bar.setNotice(keep); return; }
          if (f.size > data.upload.max_bytes) { keep = f.name + ' is too big (limit ' + Math.round(data.upload.max_bytes / 1048576) + ' MB).'; bar.setNotice(keep); return; } have++;
          var h = bar.addAttachment({ name: f.name, status: 'uploading' }), fd = new FormData(); fd.append('file', f);
          // normal upload first; when a hosting firewall refuses it (403 page that is not ours, dropped connection, 413) the same file is sent as base64 text parts
          function viaText() {
            if (!w.MfncUpfb || !data.endpoints.upload_b64) { bar.setNotice('The web host blocked the upload. Ask the host to allow POST /ai/upload, or try a smaller file.'); h.remove(); return; }
            w.MfncUpfb.bytesOf(f).then(function (bytes) { return w.MfncUpfb.sendBytes(bytes, { endpoint: data.endpoints.upload_b64, csrf: csrf(), kind: 'ai', name: f.name }); })
              .then(function (j) { if (j && j.type === 'ok') { h.update({ id: j.id, name: j.name, status: 'ready' }); bar.setNotice(keep); } else { bar.setNotice((j && j.message) || 'Upload failed.'); h.remove(); } },
                function (er) { bar.setNotice(er && er.server ? er.message : 'Your web host’s firewall blocked this upload, even as text. Try a smaller file or a different format.'); h.remove(); });
          }
          fetch(data.endpoints.upload, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf() }, body: fd }).then(function (r) {
            if (!r.headers.get('X-Mfnc-App') && w.MfncUpfb && w.MfncUpfb.blocked(r.status, r.headers)) { return 'blocked'; }
            return r.json().catch(function () { return { type: 'error', message: 'Upload failed.' }; });
          }).then(function (j) { if (j === 'blocked') { viaText(); return; } if (j.type === 'ok') { h.update({ id: j.id, name: j.name, status: 'ready' }); bar.setNotice(keep); } else { bar.setNotice(j.message || 'Upload failed.'); h.remove(); } })
            .catch(function () { viaText(); });
        });
      };
      fi.click();
    }
    var SR = w.SpeechRecognition || w.webkitSpeechRecognition, rec = null;
    function dictate() {
      if (w.MfVoice && w.MfVoice.canListen()) { return w.MfVoice.listen(); } // ElevenLabs when available, otherwise the browser recogniser (see assets/voice.js)
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
        onDictate: (SR || (w.MfVoice && w.MfVoice.canListen())) ? dictate : null, onDictateCancel: function () { try { rec && rec.abort(); } catch (e) { } try { w.MfVoice && w.MfVoice.cancel(); } catch (e) { } }, maxRows: 5 });
      bar.setModels(models, { name: 'Get in Plugins', href: data.endpoints.shop, description: 'Premium models' });
      mount.appendChild(bar.el);
    }
    function stop() { if (ctrl) ctrl.abort(); }
    function parseSources(text) {
      var low = text.toLowerCase(), scopes = [], web = low.indexOf('@web search') >= 0; data.scopes.forEach(function (k) { if (low.indexOf('@' + SOURCE_NAMES[k].toLowerCase()) >= 0) scopes.push(k); }); return { web: web, sources: scopes };
    }
    function setSub(t, busyNow) { if (!subEl) return; subEl.textContent = t; root.classList.toggle('is-busy', !!busyNow); }
    function send(text, o) {
      text = (text || '').trim(); o = o || (bar ? { attachments: bar.getState().attachments, model: bar.getState().model, effort: bar.getState().effort } : {});
      var files = o.fileIds ? o.fileIds.map(function (id) { return { id: id }; }) : (o.attachments || []).filter(function (a) { return a.id; });
      if ((!text && !files.length) || busy) return; busy = true; sug.textContent = ''; lastTools(); if (bar) bar.setBusy(true); stick = true;
      var shown = text || 'Please look at the attached file.'; if (!o.resume) { lastUser = shown; var u = bubble('user'); u.appendChild(el('p', '', shown)); if (files.length && o.attachments) { var at = el('div', 'ai-attached'); at.appendChild(svg('clip', 13)); at.appendChild(el('span', '', files.map(function (f) { return f.name; }).join(', '))); u.appendChild(at); } } scroll(true);
      var body = bubble('assistant'), box = el('div', 'ai-status'), steps = el('ul', 'status-steps status-steps--chat'), list = w.StatusMark ? StatusMark.list(steps) : null; box.setAttribute('role', 'status'); box.appendChild(steps); var st = el('span', 'ai-status__t sr-only', 'Thinking…'); box.appendChild(st); body.appendChild(box); setSub('Thinking…', true); scroll(true);
      ctrl = w.AbortController ? new AbortController() : null; var ps = parseSources(shown), stopped = false, payload = { message: text, model: o.model && o.model.key, effort: o.effort, files: files.map(function (f) { return f.id; }), sources: ps.sources, web: ps.web };
      if (o.docs_ok) payload.docs_ok = true;
      post(data.endpoints.chat, payload, function (ev) {
        if (ev.type === 'status') { st.textContent = ev.text; setSub(ev.text, true); }
        else if (ev.type === 'step' && list) { list.set(ev); scroll(); }
        else if (ev.type === 'block') { renderBlock(body, ev.block); body.appendChild(box); scroll(); }
        else if (ev.type === 'stage') { stage = ev.stage; if (ev.name) { root.querySelectorAll('[data-ai-name]').forEach(function (n) { n.textContent = ev.name; }); root.querySelectorAll('[data-ai-who]').forEach(function (n) { n.textContent = ev.name; }); data.agent.name = ev.name; if (ev.avatar) { data.agent.avatar = ev.avatar; root.querySelectorAll('.ai-avatar').forEach(function (a) { a.src = ev.avatar; }); } } if (stage === 'ready') { subIdle = 'Ready. Ask anything about your money.'; if (bar) bar.configure({ placeholder: 'Ask anything about your money' }); } }
        else if (ev.type === 'aborted' || ev.type === 'cancelled') { stopped = true; }
        else if (ev.type === 'error') { var c = el('div', 'ai-callout ai-callout--bad'); c.appendChild(el('p', '', ev.message)); if (ev.code === 'wallet' || ev.code === 'plan' || ev.code === 'budget') { var a = el('a', 'btn btn-sm', 'Plans & wallet'); a.href = base + '/plans'; c.appendChild(a); } else if (ev.code === 'model_locked' || ev.code === 'model') { var a2 = el('a', 'btn btn-sm', 'Get in Plugins'); a2.href = base + '/plugins'; c.appendChild(a2); } body.appendChild(c); }
      }, ctrl && ctrl.signal).then(function () {
        if (list) list.cancelRunning(); if (stopped) body.appendChild(el('p', 'ai-stopped', 'Stopped.'));
        var n2 = steps.children.length; if (n2) { var dt = el('details', 'ai-steps-done'); dt.appendChild(el('summary', '', n2 + (n2 === 1 ? ' step' : ' steps'))); dt.appendChild(steps); box.replaceWith(dt); body.insertBefore(dt, body.firstChild); } else box.remove();
        if (!body.childNodes.length) body.closest('.ai-msg').remove(); else addTools(body, true);
        busy = false; ctrl = null; setSub(subIdle, false); if (bar) { bar.setBusy(false); bar.focus(); } trimOldPrompts(); suggestions(); scroll();
      });
    }
    if (w.MfVoice && w.MfVoice.load) { w.MfVoice.load().then(buildBar, buildBar); } else { buildBar(); }
  }
  function boot() { init(d.querySelector('[data-ai]')); }
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', boot); else boot(); d.addEventListener('mfnc:page', boot);
})();
