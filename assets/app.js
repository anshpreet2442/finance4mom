/* Moms Finance: small progressive-enhancement script (no framework). */
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  // ---------- dialogs: they grow out of the thing you pressed and shrink back into it (transform + opacity only, so it stays smooth) ----------
  function centerOf(el) { var r = el.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; }
  function offsetFor(d) { var r = d.getBoundingClientRect(), o = d._origin; return o ? { x: o.x - (r.left + r.width / 2), y: o.y - (r.top + r.height / 2) } : { x: 0, y: 28 }; }
  function openDialog(d, origin) {
    if (!d || d.open) return; d._origin = origin && origin.getBoundingClientRect ? centerOf(origin) : null; d._closing = false; d.classList.remove('is-closing'); d.showModal();
    var f = d.querySelector('input:not([type=hidden]):not([disabled]), select:not([disabled])'); if (f && f.type !== 'checkbox') { setTimeout(function () { try { f.focus({ preventScroll: true }); } catch (x) {} }, 120); }
    if (reduce || !d.animate) return; var o = offsetFor(d);
    d.animate([{ opacity: 0, transform: 'translate(' + o.x * 0.92 + 'px,' + o.y * 0.92 + 'px) scale(' + (d._origin ? 0.18 : 0.94) + ')' }, { opacity: 1, transform: 'translate(0,0) scale(1)' }], { duration: d._origin ? 380 : 260, easing: 'cubic-bezier(.2,.9,.25,1)' });
  }
  function closeDialog(d) {
    if (!d || !d.open || d._closing) return;
    if (reduce || !d.animate) { d.close(); return; }
    d._closing = true; d.classList.add('is-closing'); var o = offsetFor(d);
    var a = d.animate([{ opacity: 1, transform: 'translate(0,0) scale(1)' }, { opacity: 0, transform: 'translate(' + o.x * 0.92 + 'px,' + o.y * 0.92 + 'px) scale(' + (d._origin ? 0.18 : 0.96) + ')' }], { duration: 250, easing: 'cubic-bezier(.5,0,.9,.55)', fill: 'forwards' });
    a.onfinish = function () { d.close(); d.classList.remove('is-closing'); d._closing = false; a.cancel(); };
  }
  window.mfncDialog = { open: openDialog, close: closeDialog };
  document.addEventListener('cancel', function (e) { if (e.target && e.target.tagName === 'DIALOG') { e.preventDefault(); closeDialog(e.target); } }, true); // Esc
  document.addEventListener('click', function (e) {
    var t = e.target;
    var o = t.closest('[data-open]'); if (o) { openDialog($(o.getAttribute('data-open')), o); return; }
    if (t.closest('[data-close]')) { var d = t.closest('dialog'); if (d) closeDialog(d); return; }
    if (t.tagName === 'DIALOG') { var r = t.getBoundingClientRect(); if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) closeDialog(t); return; }
    var c = t.closest('[data-confirm]');
    if (c) {
      var dlg = $('#confirm-dialog'), form = $('#confirm-form');
      form.action = c.dataset.action; $('#confirm-title').textContent = c.dataset.title; $('#confirm-message').textContent = c.dataset.message; $('#confirm-ok').textContent = c.dataset.label || 'Delete';
      var holder = $('#confirm-fields'); holder.innerHTML = '';
      var fields = JSON.parse(c.dataset.fields || '{}');
      Object.keys(fields).forEach(function (k) { var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = fields[k]; holder.appendChild(i); });
      openDialog(dlg, c); return;
    }
    var n = t.closest('[data-tx-new]'); if (n) { openTx(null, n.getAttribute('data-tx-new') || 'expense', n.dataset, n); return; }
    var ed = t.closest('[data-tx-edit]'); if (ed) { openTx(JSON.parse(ed.getAttribute('data-tx-edit')), null, null, ed); return; }
    if (t.closest('[data-nav-open]')) { $('#sidebar').classList.add('is-open'); $('#scrim').classList.remove('hidden'); return; }
    if (t.closest('[data-nav-close]')) { $('#sidebar').classList.remove('is-open'); $('#scrim').classList.add('hidden'); return; }
    var chip = t.closest('#tx-form [data-type]'); if (chip) { setType(chip.getAttribute('data-type')); return; }
  });

  // ---------- transaction form ----------
  var form = null;
  function setType(type) {
    if (!form) return;
    form.elements['type'].value = type;
    $$('[data-type]', form).forEach(function (b) { b.setAttribute('aria-checked', b.getAttribute('data-type') === type ? 'true' : 'false'); });
    $$('[data-show]', form).forEach(function (el) { var on = el.getAttribute('data-show').split(' ').indexOf(type) >= 0; el.classList.toggle('hidden', !on); $$('input,select,textarea', el).forEach(function (i) { i.disabled = !on; }); });
    $$('[data-hide]', form).forEach(function (el) { var off = el.getAttribute('data-hide').split(' ').indexOf(type) >= 0; el.classList.toggle('hidden', off); $$('input,select,textarea', el).forEach(function (i) { i.disabled = off; }); });
    var exp = $('[data-cat-expense]', form), inc = $('[data-cat-income]', form), isInc = type === 'income', isTr = type === 'transfer';
    if (exp && inc) {
      exp.name = isInc ? '' : 'category_id'; inc.name = isInc ? 'category_id' : '';
      exp.classList.toggle('hidden', isInc); inc.classList.toggle('hidden', !isInc);
      exp.disabled = isInc || isTr; inc.disabled = !isInc || isTr;
    }
    var lab = $('[data-planned-label]', form); if (lab) lab.textContent = type === 'income' ? 'Not received yet (expected income)' : 'Not paid yet (planned expense)';
    var amt = form.elements['amount']; if (amt) amt.setAttribute('pattern', type === 'adjustment' ? '-?[0-9,]*\\.?[0-9]{0,2}' : '[0-9,]*\\.?[0-9]{0,2}');
  }
  function openTx(data, type, ds, origin) {
    if (!form) return;
    form.reset(); form.elements['id'].value = '';
    setType((data && data.type) || type || 'expense');
    $('#tx-title').textContent = data ? 'Edit transaction' : 'Add transaction';
    $('[data-tx-submit]', form).textContent = data ? 'Save changes' : 'Add transaction';
    var del = $('#tx-delete'); if (del) { del.classList.toggle('hidden', !data); if (data) del.setAttribute('data-fields', JSON.stringify({ id: String(data.id) })); }
    if (ds && ds.account && form.elements['account_id']) { form.elements['account_id'].value = ds.account; }
    if (ds && ds.date) { form.elements['date'].value = ds.date; }
    if (ds && ds.planned) { form.elements['planned'].checked = true; }
    if (data) {
      ['id', 'amount', 'date', 'account_id', 'to_account_id', 'description', 'payment_method', 'notes', 'remarks', 'refund_of', 'fund_goal_id'].forEach(function (k) { var el = form.elements[k]; if (el && data[k] != null) el.value = data[k]; });
      var cat = form.elements['category_id']; if (cat && data.category_id != null) cat.value = data.category_id;
      var p = form.elements['planned']; if (p) p.checked = data.status === 'planned';
    }
    document.dispatchEvent(new CustomEvent('mfnc:txopen', { detail: data || {} }));
    openDialog($('#tx-dialog'), origin);
  }
  function initForm() { form = $('#tx-form'); if (form) setType('expense'); }
  window.mfncOpenTx = function (t) { openTx(null, t || 'expense'); };

  // ---------- flash auto-hide ----------
  $$('[data-flash]').forEach(function (el) { if (el.getAttribute('role') === 'status') setTimeout(function () { el.style.transition = 'opacity .4s'; el.style.opacity = '0'; setTimeout(function () { el.remove(); }, 450); }, 6000); });

  // ---------- money inputs: keep it numeric ----------
  document.addEventListener('input', function (e) {
    var el = e.target; if (el.matches && el.matches('input[inputmode=decimal]')) { var neg = /^\s*-/.test(el.value) && el.getAttribute('pattern') && el.getAttribute('pattern').indexOf('-') === 0; el.value = (neg ? '-' : '') + el.value.replace(/[^0-9.,]/g, ''); }
  });

  // ---------- monthly planner ----------
  function initPlanner() {
  var pl = $('#planner');
  if (pl) {
    var cfg = JSON.parse(pl.getAttribute('data-config'));
    cfg.fmt = function (n) {
      var neg = n < 0, a = Math.abs(n), int = String(Math.floor(a / 100)), fr = a % 100, loc = cfg.locale, s;
      if (loc === 'en-IN') { s = int.length > 3 ? int.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + int.slice(-3) : int; }
      else { var sep = loc === 'de-DE' ? '.' : loc === 'fr-FR' ? '\u202F' : ','; s = int.replace(/\B(?=(\d{3})+(?!\d))/g, sep); }
      if (fr) s += (loc === 'de-DE' || loc === 'fr-FR' ? ',' : '.') + String(fr).padStart(2, '0');
      s = (loc === 'de-DE' || loc === 'fr-FR') ? s + '\u00A0' + cfg.symbol.trim() : cfg.symbol + s;
      return (neg ? '\u2212' : '') + s;
    };
    var inputs = $$('[data-line]', pl), money = function (n) { return cfg.fmt(n); };
    var parse = function (s) { s = String(s).replace(/[,\s]/g, ''); if (s === '') return 0; if (!/^\d+(\.\d{0,2})?$/.test(s)) return null; var p = s.split('.'); return parseInt(p[0], 10) * 100 + parseInt(((p[1] || '') + '00').slice(0, 2), 10); };
    var toInput = function (n) { var w = Math.floor(n / 100), f = n % 100; return f === 0 ? String(w) : w + '.' + String(f).padStart(2, '0').replace(/0$/, ''); };
    var lines = cfg.lines;
    var current = function () { return lines.map(function (l, i) { var v = parse(inputs[i].value); return Object.assign({}, l, { amount: v === null ? 0 : v, _bad: v === null }); }); };
    var suggest = function (ls, deficit) {
      var out = [], need = deficit; if (need <= 0) return out;
      ls.filter(function (l) { return l.section === 'personal' && l.amount > 0; }).sort(function (a, b) { return b.amount - a.amount; }).forEach(function (l) { if (need <= 0) return; var cut = Math.min(Math.floor(l.amount / 2), need); if (cut <= 0) return; out.push({ key: l.key, label: l.label, from: l.amount, to: l.amount - cut, saves: cut, reason: 'Reduce discretionary personal spending (by up to half)' }); need -= cut; });
      var rank = { low: 0, medium: 1, high: 2 };
      ls.filter(function (l) { return !l.essential && l.section !== 'personal' && l.amount > 0; }).sort(function (a, b) { return rank[a.priority] - rank[b.priority] || a.amount - b.amount; }).forEach(function (l) { if (need <= 0) return; var cut = Math.min(l.amount, need); out.push({ key: l.key, label: l.label, from: l.amount, to: l.amount - cut, saves: cut, reason: (l.priority === 'low' ? 'Delay low-priority ' : 'Reduce ') + (cfg.sections[l.section] || '').toLowerCase() + ' contribution' }); need -= cut; });
      return out;
    };
    var esc = function (s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var applyOne = function (key, to) { var i = lines.findIndex(function (l) { return l.key === key; }); if (i >= 0) { inputs[i].value = to ? toInput(to) : '0'; } };
    function recalc() {
      var ls = current(), alloc = ls.reduce(function (a, l) { return a + l.amount; }, 0), un = cfg.available - alloc, deficit = Math.max(-un, 0), bad = ls.some(function (l) { return l._bad; });
      inputs.forEach(function (el, i) { el.setAttribute('aria-invalid', ls[i]._bad ? 'true' : 'false'); el.classList.toggle('outline-2', ls[i]._bad); el.classList.toggle('outline-neg', ls[i]._bad); });
      Object.keys(cfg.sections).forEach(function (sec) { var el = $('[data-sub="' + sec + '"]', pl); if (el) el.textContent = money(ls.filter(function (l) { return l.section === sec; }).reduce(function (a, l) { return a + l.amount; }, 0)); });
      $('#pl-alloc').textContent = '−' + money(alloc);
      var u = $('#pl-un'); u.textContent = money(deficit > 0 ? deficit : un); u.parentElement.classList.toggle('text-neg', deficit > 0); $('#pl-un-label').textContent = deficit > 0 ? 'Shortfall' : 'Unallocated';
      $('#pl-lines').value = JSON.stringify(ls.map(function (l) { return { key: l.key, section: l.section, refType: l.refType, refId: l.refId, label: l.label, amount: l.amount, priority: l.priority }; }));
      $('#pl-confirm').disabled = deficit > 0 || bad || ls.length === 0; $('#pl-draft').disabled = bad || ls.length === 0;
      var box = $('#pl-deficit'), ok = $('#pl-ok'), buf = $('#pl-buffer');
      box.classList.toggle('hidden', deficit <= 0); ok.classList.toggle('hidden', !(deficit === 0 && un === 0 && ls.length)); buf.classList.toggle('hidden', !(un > 0 && ls.length));
      if (un > 0) $('#pl-buffer-amt').textContent = money(un);
      if (deficit > 0) {
        var sg = suggest(ls, deficit), closes = sg.reduce(function (a, s) { return a + s.saves; }, 0);
        $('#pl-def-amt').textContent = money(deficit);
        $('#pl-sugg').innerHTML = sg.length ? sg.map(function (s) { return '<li class="rounded-2xl bg-soft p-3 text-sm"><p class="font-medium">' + esc(s.label) + ': ' + money(s.from) + ' → ' + money(s.to) + '</p><p class="text-xs text-mute">' + esc(s.reason) + '. Frees ' + money(s.saves) + '.</p><button type="button" class="btn btn-sm mt-2" data-apply="' + esc(s.key) + '" data-to="' + s.to + '">Apply</button></li>'; }).join('') + '<li><button type="button" class="btn btn-sm" data-apply-all>Apply all suggestions</button></li>' : '<li class="text-sm">No optional lines are left to reduce.</li>';
        $('#pl-still').textContent = closes < deficit ? 'Even with all suggestions you would still be ' + money(deficit - closes) + ' short. Consider lowering a savings target, moving a payment date, or adding income.' : '';
        box._sg = sg;
      }
      var dirty = ls.some(function (l, i) { return l.amount !== lines[i].saved; });
      $$('[data-apply-line]', pl).forEach(function (b) { b.disabled = dirty; b.title = dirty ? 'Save the plan first' : ''; });
    }
    pl.addEventListener('input', function (e) { if (e.target.matches('[data-line]')) recalc(); });
    pl.addEventListener('click', function (e) {
      var a = e.target.closest('[data-apply]'); if (a) { applyOne(a.getAttribute('data-apply'), parseInt(a.getAttribute('data-to'), 10)); recalc(); return; }
      if (e.target.closest('[data-apply-all]')) { ($('#pl-deficit')._sg || []).forEach(function (s) { applyOne(s.key, s.to); }); recalc(); return; }
      if (e.target.closest('[data-reset-edits]')) { lines.forEach(function (l, i) { inputs[i].value = l.proposal ? toInput(l.proposal) : ''; }); recalc(); }
    });
    inputs.forEach(function (el, i) { el.value = lines[i].amount ? toInput(lines[i].amount) : ''; lines[i].saved = lines[i].amount; });
    recalc();
  }
  }

  // ---------- transactions page: shared edit buttons are plain markup; nothing else needed ----------

  // ---------- number formatting shared by count-up, pulse and planner ----------
  var body = document.body, LOCALE = 'en-IN', SYMBOL = '₹';
  function readLocale() { LOCALE = body.getAttribute('data-locale') || 'en-IN'; SYMBOL = body.getAttribute('data-symbol') || '₹'; }
  readLocale();
  function group(int) {
    if (LOCALE === 'en-IN') return int.length > 3 ? int.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + int.slice(-3) : int;
    var sep = LOCALE === 'de-DE' ? '.' : LOCALE === 'fr-FR' ? ' ' : ','; return int.replace(/\B(?=(\d{3})+(?!\d))/g, sep);
  }
  window.MfncFmt = function (minor, symbol) {
    var neg = minor < 0, a = Math.abs(minor), int = String(Math.floor(a / 100)), fr = a % 100, de = LOCALE === 'de-DE' || LOCALE === 'fr-FR', s = group(int) + (fr ? (de ? ',' : '.') + String(fr).padStart(2, '0') : '');
    s = de ? s + ' ' + (symbol || SYMBOL).trim() : (symbol || SYMBOL) + s; return (neg ? '−' : '') + s;
  };

  // ---------- count-up: numbers roll up to their value the first time they scroll into view ----------
  function parseNum(text) {
    var m = String(text).trim().match(/^([^\d−-]*)([−-]?)([\d.,   ]+)(.*)$/); if (!m) return null;
    var de = LOCALE === 'de-DE' || LOCALE === 'fr-FR', decSep = de ? ',' : '.', body_ = m[3].replace(/[   ]/g, ''), idx = body_.lastIndexOf(decSep), decimals = 0, whole = body_;
    if (idx >= 0 && body_.length - idx - 1 === 2) { decimals = 2; whole = body_.slice(0, idx); }
    var digits = whole.replace(/[.,]/g, ''); if (!/^\d+$/.test(digits)) return null;
    var v = parseInt(digits, 10) + (decimals ? parseInt(body_.slice(idx + 1), 10) / 100 : 0);
    return { pre: m[1] + (m[2] ? '−' : ''), post: m[4], v: v, dec: decimals, neg: !!m[2] };
  }
  function fmtPlain(v, dec) { var a = Math.abs(v), int = String(Math.floor(a)), de = LOCALE === 'de-DE' || LOCALE === 'fr-FR'; return group(int) + (dec ? (de ? ',' : '.') + String(Math.round((a - Math.floor(a)) * 100)).padStart(2, '0') : ''); }
  function countUp(el) {
    var final = el.textContent, p = parseNum(final); if (!p || reduce || p.v === 0) return;
    var t0 = null, dur = 1000 + Math.min(600, Math.log10(p.v + 1) * 90); el.setAttribute('aria-label', final);
    function step(t) { if (t0 === null) t0 = t; var k = Math.min((t - t0) / dur, 1), e = k === 1 ? 1 : 1 - Math.pow(2, -10 * k); el.textContent = p.pre + fmtPlain(p.v * e, p.dec) + p.post; if (k < 1) requestAnimationFrame(step); else { el.textContent = final; el.removeAttribute('aria-label'); } }
    el.textContent = p.pre + fmtPlain(0, p.dec) + p.post; requestAnimationFrame(step);
    setTimeout(function () { if (el.textContent !== final) { el.textContent = final; el.removeAttribute('aria-label'); } }, dur + 500); // frames are paused in background tabs: always land on the real number
  }
  window.mfncCountUp = countUp;

  // ---------- reveal on scroll (staggered), progress bars that fill, live touches ----------
  function initReveal() {
  var cards = $$('main :is(.card, .m-sheet, .bubbles, [data-rv]):not(.in)'), counts = $$('main [data-count]'), bars = $$('[role=progressbar] > div:not(.go)');
  function settle(el, delay) { setTimeout(function () { el.classList.add('settled'); el.style.removeProperty('--rv-d'); }, 900 + delay); }
  if (!('IntersectionObserver' in window) || reduce) { cards.forEach(function (c) { c.classList.add('in', 'settled'); }); bars.forEach(function (b) { b.classList.add('go'); }); }
  else {
    var io = new IntersectionObserver(function (es) {
      var n = 0; es.forEach(function (e) { if (!e.isIntersecting) return; var el = e.target; io.unobserve(el); var d = n++ * 55; el.style.setProperty('--rv-d', d + 'ms'); el.classList.add('in'); settle(el, d); });
    }, { threshold: 0.06, rootMargin: '0px 0px -3% 0px' });
    cards.forEach(function (c) { io.observe(c); });
    var bio = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { bio.unobserve(e.target); setTimeout(function () { e.target.classList.add('go'); }, 200); } }); }, { threshold: 0.3 });
    bars.forEach(function (b) { bio.observe(b); });
    var cio = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { cio.unobserve(e.target); setTimeout(function () { countUp(e.target); }, 150); } }); }, { threshold: 0.4 });
    counts.forEach(function (c) { cio.observe(c); });
  }
  }

  // ---------- live pulse: quiet background refresh of the numbers that change (balance, dues, reminders) ----------
  var last = null, failed = 0;
  function setBadge(sel, n) { $$(sel).forEach(function (b) { b.textContent = n; b.classList.toggle('hidden', !n); }); }
  function pulse() {
    if (document.hidden || !navigator.onLine || !body.hasAttribute('data-user')) return;
    fetch((body.getAttribute('data-base') || '') + '/api/pulse', { credentials: 'same-origin', headers: { 'X-Requested-With': 'mfnc' } }).then(function (r) { if (!r.ok) throw new Error('pulse'); return r.json(); }).then(function (j) {
      failed = 0; var dot = $('#live-dot'); if (dot) { dot.classList.remove('opacity-30'); dot.title = 'Live: updated ' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }); }
      setBadge('[data-live-badge=dues]', j.dues); setBadge('[data-live-badge=bills]', j.overdue);
      if (last) {
        $$('[data-live=cash]').forEach(function (el) { if (j.cash !== last.cash) { el.textContent = window.MfncFmt(j.cash); el.classList.add('shimmer'); setTimeout(function () { el.classList.remove('shimmer'); }, 2600); countUp(el); } });
        if (j.unread > last.unread && window.toast && body.getAttribute('data-presence') !== 'busy') toast({ type: 'info', title: 'New reminder', text: 'Something changed on your dues or bills.', action: { label: 'View', href: (body.getAttribute('data-base') || '') + '/dues' } });
        if (j.level !== last.level) { if (window.toast) toast({ type: j.level > last.level ? 'warn' : 'ok', title: j.level > last.level ? 'Payment overdue' : 'You are back on track', text: j.level > last.level ? 'Some features are limited until your dues are paid.' : 'Everything is available again.' }); setTimeout(function () { location.reload(); }, 2500); }
      }
      last = j;
    }).catch(function () { failed++; var dot = $('#live-dot'); if (dot && failed > 1) { dot.classList.add('opacity-30'); dot.title = 'Offline: showing the last known numbers'; } });
  }
  if (body.hasAttribute('data-user')) { setTimeout(pulse, 4000); setInterval(pulse, 45000); document.addEventListener('visibilitychange', function () { if (!document.hidden) pulse(); }); }

  // ---------- (re)initialise everything that lives inside a page; the SPA router fires mfnc:page after each swap ----------
  function initPage() {
    readLocale(); initForm(); initPlanner(); initReveal();
    if (window.SloshGauge) window.SloshGauge.init(); if (window.JellyRadio) window.JellyRadio.init();
    if (/[?&]add=1/.test(location.search) && form) setTimeout(function () { openTx(null, 'expense'); }, 400);
  }
  document.addEventListener('mfnc:page', initPage);
  initPage();
})();
