/* Moms Finance — statement import preview (/import?p=…), remark quick-edit dialog and the "imported from" block in the transaction dialog.
   Progressive: without JavaScript the preview form still posts (every row ticked, mapping selects as shown). */
(function () {
  'use strict';
  var d = document, w = window;
  function $(s, r) { return (r || d).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); }
  var money = function (minor) { var loc = d.body.getAttribute('data-locale') || 'en-IN', sym = d.body.getAttribute('data-symbol') || '₹'; try { return sym + new Intl.NumberFormat(loc, { maximumFractionDigits: 2, minimumFractionDigits: minor % 100 ? 2 : 0 }).format(minor / 100); } catch (e) { return sym + (minor / 100).toFixed(2); } };

  // ---------- upload forms (statement file, JSON text/file): size check, then the upload with automatic fallbacks when a hosting firewall refuses it ----------
  // 1) normal POST  2) the same bytes as base64 text parts  3) (xlsx only) the browser reads the workbook itself and sends only the rows, which the server re-validates  4) a clear explanation
  function el(tag, cls, text) { var n = d.createElement(tag); if (cls) n.className = cls; if (text) n.textContent = text; return n; }
  function stepList(box) {
    box.hidden = false; box.textContent = ''; var ul = el('ul', 'imp-steps'); box.appendChild(ul);
    return function add(text, state) { var li = el('li', state ? 'is-' + state : '', text); ul.appendChild(li); return function (t, st) { if (t) li.textContent = t; li.className = st ? 'is-' + st : ''; }; };
  }
  function blockedHelp(err, tried, jsonUrl, isJson) {
    err.hidden = false; err.textContent = ''; err.className = 'imp-err imp-err--host';
    err.appendChild(el('b', '', 'Your web host’s firewall blocked this upload.'));
    err.appendChild(el('span', '', ' The page you saw (“403 Forbidden – Access to this resource on the server is denied”) comes from your hosting provider’s security system, not from this app and not from your file. Nothing was saved. We tried: ' + tried.join('; ') + '.'));
    var p = el('span', 'imp-err__next');
    if (isJson) { p.appendChild(d.createTextNode(' Try pasting a smaller part (ask your AI for the first 100 transactions), or ask your host to allow POST /do/import-json and /import/b64 and give them the rule id from their Imunify360 / ModSecurity log (README, “Troubleshooting uploads”).')); }
    else { p.appendChild(d.createTextNode(' What you can do: ')); var a = el('a', '', 'use “Use your own AI (JSON)”'); a.href = jsonUrl || '#json'; p.appendChild(a); p.appendChild(d.createTextNode(' (it pastes plain text, which the firewall normally lets through), or ask your host to allow POST /do/import-upload and give them the rule id from their Imunify360 / ModSecurity log (README, “Troubleshooting uploads”).')); }
    err.appendChild(p);
  }
  function attachUpload(form) {
    if (form._imp) return; form._imp = 1;
    var isJson = form.hasAttribute('data-imp-json'), f = $('input[type=file]', form), ta = $('textarea[name=json_text]', form), err = $('[data-imp-err]', form), prog = $('[data-imp-prog]', form), csrf = $('input[name=_csrf]', form), btn = $('[data-imp-upload]', form);
    var limit = f ? +f.getAttribute('data-max') || 8 * 1048576 : 0;
    function show(m) { if (err) { err.className = 'imp-err'; err.textContent = m || ''; err.hidden = !m; } }
    function busy(on) { if (btn) { if (on) btn.setAttribute('aria-busy', 'true'); else btn.removeAttribute('aria-busy'); } }
    if (f) f.addEventListener('change', function () { var file = f.files && f.files[0]; show(''); if (prog) prog.hidden = true; if (file && file.size > Math.max(limit, isJson ? 0 : 8 * 1048576)) { show('That file is ' + (file.size / 1048576).toFixed(1) + ' MB; the limit is ' + (Math.max(limit, isJson ? 0 : 8 * 1048576) / 1048576).toFixed(0) + ' MB.'); f.value = ''; } });
    form.addEventListener('submit', function (e) {
      if (btn && btn.getAttribute('aria-busy') === 'true') { e.preventDefault(); return; }
      var file = f && f.files && f.files[0], text = ta ? ta.value : '', hasText = isJson && text.trim() !== '';
      if ((!file && !hasText) || !w.fetch || !w.MfncUpfb || !prog) { busy(true); return; }   // nothing to send, or no JavaScript help: the plain form post (and its server-side message) is used
      e.preventDefault(); busy(true); show(''); var add = stepList(prog), tried = [], tok = csrf ? csrf.value : '', label = hasText ? 'your text' : file.name;
      var done = function (url) { w.location.href = url; }, failMsg = function (m) { show(m); busy(false); };
      var jsonUrl = (d.querySelector('[data-imp-jsonlink]') || {}).href || '#json', endpoint = form.getAttribute('data-b64');
      var s1 = add('Sending ' + label + '…', 'run');
      fetch(form.action, { method: 'POST', credentials: 'same-origin', headers: { 'X-Mfnc-Spa': '1', 'X-Requested-With': 'fetch' }, body: new FormData(form) }).then(function (r) {
        if (r.status === 204 && r.headers.get('X-Mfnc-Redirect')) { s1('Sent.', 'ok'); done(r.headers.get('X-Mfnc-Redirect')); return; }
        if (w.MfncUpfb.blocked(r.status, r.headers)) { s1('The host refused the normal upload (HTTP ' + r.status + ').', 'bad'); tried.push('the normal upload (HTTP ' + r.status + ')'); return fallback(); }
        s1('', 'ok'); busy(false); form.submit();   // our own answer that is not a redirect (for example a page-expired notice): let the normal form show it
      }, function () { s1('The connection was cut during the normal upload.', 'bad'); tried.push('the normal upload (connection reset)'); return fallback(); });
      function payload() { return hasText ? Promise.resolve(new TextEncoder().encode(text)) : w.MfncUpfb.bytesOf(file); }
      function fallback() {
        var s2 = add('Sending it as plain text instead…', 'run');
        return payload().then(function (bytes) {
          return w.MfncUpfb.sendBytes(bytes, { endpoint: endpoint, csrf: tok, kind: isJson ? 'json' : 'statement', name: hasText ? 'pasted JSON' : file.name, onProgress: function (p) { s2('Sending it as plain text… ' + Math.round(p * 100) + '%', 'run'); }, onStep: function (t) { s2(t, 'run'); } });
        }).then(function (j) { s2('Sent as text.', 'ok'); done(j.redirect); }, function (er) {
          if (er && er.server) { s2('Sent as text, but it was refused.', 'bad'); failMsg(er.message); return; }
          s2('The text upload was blocked too.', 'bad'); tried.push('the same content as plain text'); return isJson ? blocked() : clientParse();
        });
      }
      function clientParse() {
        if (!file || !/\.xlsx$/i.test(file.name)) { return blocked(); }
        var s3 = add('Reading the workbook in your browser and sending only the rows…', 'run');
        return w.MfncUpfb.parseXlsx(file).then(function (parsed) { return w.MfncUpfb.sendRows(parsed, { endpoint: endpoint, csrf: tok, name: file.name, onStep: function (t) { s3(t, 'run'); } }); })
          .then(function (j) { s3('Read in your browser. Please check the preview carefully.', 'ok'); done(j.redirect); }, function (er) {
            if (er && er.server) { s3('The rows were sent, but not accepted.', 'bad'); failMsg(er.message); return; }
            s3((er && er.blocked) ? 'That was blocked as well.' : 'Could not read the workbook in the browser (' + ((er && er.message) || 'unknown') + ').', 'bad'); tried.push('reading it in your browser'); return blocked();
          });
      }
      function blocked() { busy(false); blockedHelp(err, tried, jsonUrl, isJson); }
    });
  }
  function uploadForms() { $$('form[data-b64]').forEach(attachUpload); }
  // copy buttons on the JSON panel and the report on the preview
  function copyText(text, note) {
    function fallback() { var t = d.createElement('textarea'); t.value = text; t.style.position = 'fixed'; t.style.opacity = '0'; d.body.appendChild(t); t.select(); try { d.execCommand('copy'); } catch (x) { } t.remove(); }
    if (w.navigator.clipboard && w.navigator.clipboard.writeText) w.navigator.clipboard.writeText(text).catch(fallback); else fallback();
    if (note) { note.hidden = false; clearTimeout(note._t); note._t = setTimeout(function () { note.hidden = true; }, 6000); }
  }
  function copyButtons() {
    if (d._impCopy) return; d._impCopy = 1;
    d.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-imp-copy]'); if (b) { var ta = d.getElementById(b.getAttribute('data-imp-copy')); if (ta) copyText(ta.value, $('[data-imp-copied]', b.closest('li') || d)); return; }
      var r = e.target.closest && e.target.closest('[data-imp-copyreport]'); if (r) { var t = $('[data-imp-report]', r.closest('section') || d); if (t) copyText(t.value, $('[data-imp-copied]', r.closest('section') || d)); }
    });
  }

  // ---------- preview ----------
  function preview() {
    var form = $('form.imp-form'); if (!form || form._imp) return; form._imp = 1;
    var rows = $$('.imp-row', form), edits = $('[data-imp-rowedits]', form), rowCat = {}, exclude = {}, fixes = {};
    var tagSel = $$('[data-imp-cat]', form), accSel = $$('[data-imp-acc]', form);
    var q = $('[data-imp-q]', form), fdir = $('[data-imp-fdir]', form), fshow = $('[data-imp-fshow]', form), facc = $('[data-imp-facc]', form), shown = $('[data-imp-shown]', form);

    function tagLabel(i) { var s = tagSel[i]; if (!s) return 'Uncategorised'; var o = s.options[s.selectedIndex]; return o ? (o.value === 'new' ? 'New: ' + o.textContent.replace(/^Create category “|”$/g, '') : o.textContent.replace(/^\s*↳\s*/, '').trim()) : 'Uncategorised'; }
    function refreshLabels() { rows.forEach(function (r) { var n = r.getAttribute('data-n'); var l = $('[data-imp-catlabel]', r); if (l && !(n in rowCat)) l.textContent = tagLabel(+r.getAttribute('data-ti')); }); }
    function included(r) { var c = $('[data-imp-inc]', r); return c && !c.disabled && c.checked; }
    function totals() {
      var n = 0, off = 0, sum = 0;
      rows.forEach(function (r) { var dup = r.getAttribute('data-dup') === '1', on = included(r); r.classList.toggle('is-off', !on && !dup); if (on) { n++; sum += (r.getAttribute('data-dir') === 'in' ? 1 : -1) * +r.getAttribute('data-amt'); } else if (!dup) off++; });
      var nEl = $('[data-imp-n]', form), go = $('[data-imp-golabel]', form), ex = $('[data-imp-extra]', form), top = $('#imp-ready-top'), rsum = d.getElementById('imp-ready-sum'); if (rsum) rsum.textContent = n;
      if (nEl) nEl.textContent = n; if (top) top.textContent = n;
      if (go) go.textContent = n ? 'Import ' + n + ' transaction' + (n === 1 ? '' : 's') : 'Nothing selected';
      if (ex) ex.textContent = (off ? off + ' left out · ' : '') + 'net ' + (sum < 0 ? '−' : '+') + money(Math.abs(sum));
      var b = $('[data-imp-go]', form); if (b) b.disabled = n === 0;
    }
    function applyFilter() {
      var text = (q && q.value || '').toLowerCase().trim(), dir = fdir && fdir.value, show = fshow && fshow.value, acc = facc && facc.value, vis = 0;
      rows.forEach(function (r) {
        var ok = true;
        if (text && r.getAttribute('data-q').indexOf(text) < 0) ok = false;
        if (ok && dir && r.getAttribute('data-dir') !== dir) ok = false;
        if (ok && acc && r.getAttribute('data-ai') !== acc) ok = false;
        if (ok && show) { var on = included(r), dup = r.getAttribute('data-dup') === '1'; if (show === 'todo' && !on) ok = false; if (show === 'off' && (on || dup)) ok = false; if (show === 'dup' && !dup) ok = false; if (show === 'hint' && r.getAttribute('data-hint') !== '1') ok = false; }
        r.hidden = !ok; if (ok) vis++;
      });
      if (shown) shown.textContent = vis + ' of ' + rows.length + ' shown';
    }
    form.addEventListener('input', function (e) {
      var t = e.target;
      if (t.matches && t.matches('[data-imp-q]')) applyFilter();
      if (t.classList && t.classList.contains('imp-rem')) { /* stored on submit */ }
    });
    form.addEventListener('change', function (e) {
      var t = e.target;
      if (t.matches('[data-imp-inc]')) { var n = t.closest('.imp-row').getAttribute('data-n'); if (t.checked) delete exclude[n]; else exclude[n] = 1; totals(); if (fshow && fshow.value) applyFilter(); }
      else if (t.matches('[data-imp-cat]')) { refreshLabels(); }
      else if (t.matches('[data-imp-acc]')) { var box = t.closest('[data-imp-accrow]'), nw = $('[data-imp-newacc]', box); if (nw) nw.hidden = t.value !== 'new'; }
      else if (t.matches('[data-imp-fdir],[data-imp-fshow],[data-imp-facc]')) applyFilter();
      else if (t.matches('.imp-catpick')) {
        var row = t.closest('.imp-row'), n2 = row.getAttribute('data-n'), lab = d.createElement('span'), btn = d.createElement('button');
        if (t.value === '__map') delete rowCat[n2]; else rowCat[n2] = t.value;
        btn.type = 'button'; btn.className = 'imp-catbtn'; btn.setAttribute('data-imp-catbtn', ''); btn.setAttribute('aria-label', 'Change category'); lab.setAttribute('data-imp-catlabel', '');
        lab.textContent = t.value === '__map' ? tagLabel(+row.getAttribute('data-ti')) : (t.options[t.selectedIndex].textContent.replace(/^\s*↳\s*/, '').trim());
        btn.appendChild(lab); t.parentNode.replaceChild(btn, t); btn.focus();
      }
    });
    form.addEventListener('click', function (e) {
      var t = e.target;
      var cb = t.closest && t.closest('[data-imp-catbtn]');
      if (cb) {
        var row = cb.closest('.imp-row'), src = $('#imp-tpl-' + (row.getAttribute('data-cg') || row.getAttribute('data-dir'))), sel = d.createElement('select'), n = row.getAttribute('data-n');
        sel.className = 'input imp-catpick'; sel.setAttribute('aria-label', 'Category'); sel.innerHTML = src.innerHTML;
        sel.value = (n in rowCat) ? rowCat[n] : '__map'; cb.parentNode.replaceChild(sel, cb); sel.focus(); return;
      }
      var fb = t.closest && t.closest('[data-imp-fix]');
      if (fb) {   // one-click fixes from the checks (JSON import): skip / keep anyway / treat as the other direction / swap day and month / leave uncategorised
        var fn = fb.getAttribute('data-n'), act = fb.getAttribute('data-imp-fix'), row = form.querySelector('.imp-row[data-n="' + fn + '"]'), cb = row && $('[data-imp-inc]', row), on = fb.getAttribute('aria-pressed') !== 'true';
        $$('[data-imp-fix][data-n="' + fn + '"]').forEach(function (x) { x.setAttribute('aria-pressed', 'false'); x.classList.remove('is-on'); });
        if (on) { fb.setAttribute('aria-pressed', 'true'); fb.classList.add('is-on'); fixes[fn] = act; } else { delete fixes[fn]; }
        if (cb) {
          if (on && act === 'skip') { cb.checked = false; exclude[fn] = 1; }
          else if (on && act === 'keep') { cb.disabled = false; cb.checked = true; delete exclude[fn]; }
          else { cb.disabled = row.getAttribute('data-blocked') === '1' || row.getAttribute('data-dup') === '1'; cb.checked = !cb.disabled; if (cb.checked) delete exclude[fn]; }
        }
        totals(); if (fshow && fshow.value) applyFilter(); return;
      }
      var rm = t.closest && t.closest('[data-imp-remark]');
      if (rm) { var inp = $('.imp-rem', rm.parentNode); rm.hidden = true; inp.hidden = false; inp.focus(); return; }
      if (t.closest && t.closest('[data-imp-all],[data-imp-none]')) {
        var on = !!t.closest('[data-imp-all]'); rows.forEach(function (r) { if (r.hidden) return; var c = $('[data-imp-inc]', r); if (c && !c.disabled) { c.checked = on; var n3 = r.getAttribute('data-n'); if (on) delete exclude[n3]; else exclude[n3] = 1; } }); totals(); return;
      }
    });
    form.addEventListener('submit', function (e) {
      var b = $('[data-imp-go]', form);
      if (form._sent) { e.preventDefault(); return; }
      var ed = { ex: [], cat: {}, rm: {}, fx: fixes };
      Object.keys(exclude).forEach(function (n) { ed.ex.push(+n); });
      Object.keys(rowCat).forEach(function (n) { var v = rowCat[n]; ed.cat[n] = v === '' ? 'none' : (/^\d+$/.test(v) ? +v : v); });
      rows.forEach(function (r) { var i = $('.imp-rem', r); if (i && i.value.trim() !== i.getAttribute('data-orig')) ed.rm[r.getAttribute('data-n')] = i.value.trim(); });
      edits.value = JSON.stringify(ed);
      if (edits.value.length > 500000) { e.preventDefault(); alert('Too many individual edits at once. Import in smaller parts.'); return; }
      form._sent = true; if (b) { b.setAttribute('aria-busy', 'true'); }
    });
    totals(); applyFilter(); refreshLabels();
  }

  // ---------- remark quick edit (any page with transaction rows) ----------
  function remarkDialog() {
    if (d._impRemark) return; d._impRemark = 1;
    d.addEventListener('click', function (e) {
      var b = e.target.closest && e.target.closest('[data-remark-edit]'); if (!b) return;
      var dlg = $('#remark-dialog'); if (!dlg) return; var data = {}; try { data = JSON.parse(b.getAttribute('data-remark-edit')); } catch (x) { return; }
      $('[name=id]', dlg).value = data.id; $('[name=remarks]', dlg).value = data.remarks || ''; $('#remark-for', dlg).textContent = data.label || '';
      if (window.mfncDialog) window.mfncDialog.open(dlg, b); else if (dlg.showModal) dlg.showModal();
      setTimeout(function () { var i = $('[name=remarks]', dlg); if (i) i.focus(); }, 60);
    });
    // imported-from block inside the add/edit dialog
    d.addEventListener('mfnc:txopen', function (e) {
      var box = $('#tx-imp'); if (!box) return; var x = e.detail && e.detail.imp;
      if (!x) { box.hidden = true; return; }
      var rows = [['Source', x.label], ['UPI reference', x.ref], ['Time', x.time ? String(x.time).slice(0, 5) : ''], ['Statement text', x.details], ['Other party', x.other], ['Tag', x.tag], ['Statement account', x.account], ['Order id', x.order], ['Imported on', x.imported]].filter(function (r) { return r[1]; });
      box.innerHTML = ''; var h = d.createElement('b'); h.textContent = 'Imported from ' + x.label; h.style.color = 'var(--ink)'; box.appendChild(h);
      var dl = d.createElement('dl'); rows.forEach(function (r) { var a = d.createElement('dt'), c = d.createElement('dd'); a.textContent = r[0]; c.textContent = r[1]; dl.appendChild(a); dl.appendChild(c); }); box.appendChild(dl); box.hidden = false;
    });
  }

  function boot() { uploadForms(); copyButtons(); preview(); remarkDialog(); }
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', boot); else boot();
  d.addEventListener('mfnc:page', boot);
})();
