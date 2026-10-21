/* Moms Finance — statement import preview (/import?p=…), remark quick-edit dialog and the "imported from" block in the transaction dialog.
   Progressive: without JavaScript the preview form still posts (every row ticked, mapping selects as shown). */
(function () {
  'use strict';
  var d = document;
  function $(s, r) { return (r || d).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); }
  var money = function (minor) { var loc = d.body.getAttribute('data-locale') || 'en-IN', sym = d.body.getAttribute('data-symbol') || '₹'; try { return sym + new Intl.NumberFormat(loc, { maximumFractionDigits: 2, minimumFractionDigits: minor % 100 ? 2 : 0 }).format(minor / 100); } catch (e) { return sym + (minor / 100).toFixed(2); } };

  // ---------- upload form: size check before the browser sends megabytes ----------
  function uploadForm() {
    var f = $('input[type=file][data-max]'); if (!f || f._imp) return; f._imp = 1;
    var err = $('[data-imp-err]'), form = f.form;
    function show(m) { if (err) { err.textContent = m || ''; err.hidden = !m; } }
    f.addEventListener('change', function () { var file = f.files && f.files[0]; show(''); if (file && file.size > +f.getAttribute('data-max')) { show('That file is ' + (file.size / 1048576).toFixed(1) + ' MB; the limit is ' + (+f.getAttribute('data-max') / 1048576).toFixed(0) + ' MB.'); f.value = ''; } });
    form.addEventListener('submit', function (e) { var b = $('[data-imp-upload]', form); if (b) { if (b.getAttribute('aria-busy') === 'true') { e.preventDefault(); return; } b.setAttribute('aria-busy', 'true'); } });
  }

  // ---------- preview ----------
  function preview() {
    var form = $('form.imp-form'); if (!form || form._imp) return; form._imp = 1;
    var rows = $$('.imp-row', form), edits = $('[data-imp-rowedits]', form), rowCat = {}, exclude = {};
    var tagSel = $$('[data-imp-cat]', form), accSel = $$('[data-imp-acc]', form);
    var q = $('[data-imp-q]', form), fdir = $('[data-imp-fdir]', form), fshow = $('[data-imp-fshow]', form), facc = $('[data-imp-facc]', form), shown = $('[data-imp-shown]', form);

    function tagLabel(i) { var s = tagSel[i]; if (!s) return 'Uncategorised'; var o = s.options[s.selectedIndex]; return o ? (o.value === 'new' ? 'New: ' + o.textContent.replace(/^Create category “|”$/g, '') : o.textContent.replace(/^\s*↳\s*/, '').trim()) : 'Uncategorised'; }
    function refreshLabels() { rows.forEach(function (r) { var n = r.getAttribute('data-n'); var l = $('[data-imp-catlabel]', r); if (l && !(n in rowCat)) l.textContent = tagLabel(+r.getAttribute('data-ti')); }); }
    function included(r) { var c = $('[data-imp-inc]', r); return c && !c.disabled && c.checked; }
    function totals() {
      var n = 0, off = 0, sum = 0;
      rows.forEach(function (r) { var dup = r.getAttribute('data-dup') === '1', on = included(r); r.classList.toggle('is-off', !on && !dup); if (on) { n++; sum += (r.getAttribute('data-dir') === 'in' ? 1 : -1) * +r.getAttribute('data-amt'); } else if (!dup) off++; });
      var nEl = $('[data-imp-n]', form), go = $('[data-imp-golabel]', form), ex = $('[data-imp-extra]', form), top = $('#imp-ready-top');
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
        var row = cb.closest('.imp-row'), src = $('#imp-tpl-' + row.getAttribute('data-dir')), sel = d.createElement('select'), n = row.getAttribute('data-n');
        sel.className = 'input imp-catpick'; sel.setAttribute('aria-label', 'Category'); sel.innerHTML = src.innerHTML;
        sel.value = (n in rowCat) ? rowCat[n] : '__map'; cb.parentNode.replaceChild(sel, cb); sel.focus(); return;
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
      var ed = { ex: [], cat: {}, rm: {} };
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

  function boot() { uploadForm(); preview(); remarkDialog(); }
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', boot); else boot();
  d.addEventListener('mfnc:page', boot);
})();
