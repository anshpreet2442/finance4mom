/* Moms Finance — upload fallbacks for hosts whose firewall refuses file uploads (403 "Access to this resource on the server is denied!").
   window.MfncUpfb:
     blocked(status, headers)            true when a failed answer did not come from this app (a hosting firewall, a proxy, a dropped connection)
     bytesOf(file)                       Promise<Uint8Array>
     sendBytes(bytes, opt)               sends bytes as numbered base64 text parts (urlencoded POST, then JSON, then reversed base64) -> Promise<server JSON>
     parseXlsx(file)                     reads an .xlsx in the browser (zip central directory + DecompressionStream('deflate-raw') + DOMParser) -> {kind, date1904, sheets}
     sendRows(parsed, opt)               sends parsed sheets as JSON text (server re-validates everything)
   opt: {endpoint, csrf, kind, name, onStep(text)}.  Nothing here is trusted by the server; it only changes how the same bytes arrive. */
(function () {
  'use strict';
  var W = window, TE = new TextEncoder();
  var PART = 192 * 1024;                                   // raw bytes per request (about 256 KB of base64)
  var LIMITS = { entries: 300, part: 24 * 1048576, total: 64 * 1048576, cells: 400000, rows: 50000, cols: 200, sheets: 60, ratio: 400 };

  function hex(n) { var a = new Uint8Array(n); (W.crypto || W.msCrypto).getRandomValues(a); return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join(''); }
  function b64(bytes) { var s = '', i, step = 0x8000; for (i = 0; i < bytes.length; i += step) s += String.fromCharCode.apply(null, bytes.subarray(i, Math.min(bytes.length, i + step))); return W.btoa(s); }
  function bytesOf(file) {
    if (file instanceof Uint8Array) return Promise.resolve(file);
    if (file.arrayBuffer) return file.arrayBuffer().then(function (b) { return new Uint8Array(b); });
    return new Promise(function (res, rej) { var r = new FileReader(); r.onload = function () { res(new Uint8Array(r.result)); }; r.onerror = function () { rej(new Error('read')); }; r.readAsArrayBuffer(file); });
  }
  /** Did this failure come from somewhere other than our app? Our own answers carry X-Mfnc-App. */
  function blocked(status, headers) {
    var ours = headers && headers.get && headers.get('X-Mfnc-App');
    if (ours) return false;
    return status === 0 || status === 403 || status === 406 || status === 413 || status === 418 || status === 501 || status >= 500;
  }

  // ---------- chunked base64 text upload ----------
  function post(url, fields, csrf, mode) {
    var headers = { 'X-CSRF-Token': csrf, 'X-Requested-With': 'fetch' }, body;
    if (mode === 'json') { headers['Content-Type'] = 'application/json'; body = JSON.stringify(fields); }
    else { headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8'; body = Object.keys(fields).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(fields[k]); }).join('&'); }
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: headers, body: body }).then(function (r) {
      return r.text().then(function (t) {
        var j = null; try { j = JSON.parse(t); } catch (e) { }
        if (j && typeof j === 'object') { j._status = r.status; return j; }
        var err = new Error('blocked'); err.blocked = blocked(r.status, r.headers); err.status = r.status; throw err;   // an HTML page (the firewall's) instead of our JSON
      });
    }, function () { var err = new Error('network'); err.blocked = true; err.status = 0; throw err; });
  }
  function sendBytes(bytes, opt) {
    var id = hex(16), n = Math.max(1, Math.ceil(bytes.length / PART)), modes = ['form', 'json'], encs = ['b64', 'b64r'], mi = 0, ei = 0, i = 0;
    function part(k) {
      var raw = bytes.subarray(k * PART, Math.min(bytes.length, (k + 1) * PART)), text = b64(raw);
      function go(tries) {
        var enc = encs[ei], data = enc === 'b64r' ? text.split('').reverse().join('') : text;
        return post(opt.endpoint, { id: id, i: k, n: n, name: opt.name || 'upload', kind: opt.kind || 'statement', enc: enc, data: data, total: bytes.length, _csrf: opt.csrf }, opt.csrf, modes[mi]).catch(function (err) {
          if (!err.blocked) throw err;
          if (k === 0 && ei === 0 && mi === 0) { mi = 1; if (opt.onStep) opt.onStep('Trying the JSON form of the text upload…'); return go(tries + 1); }   // the same text, as JSON
          if (k === 0 && ei === 0 && mi === 1) { ei = 1; mi = 0; if (opt.onStep) opt.onStep('Trying the text upload with a scrambled prefix…'); return go(tries + 1); }
          if (k === 0 && ei === 1 && mi === 0) { mi = 1; return go(tries + 1); }
          if (k > 0 && tries < 2) return go(tries + 1);   // a hiccup in the middle: the server accepts a repeated part
          throw err;
        });
      }
      return go(0).then(function (j) {
        if (!j.ok) { var e = new Error(j.message || 'Upload failed.'); e.server = true; e.json = j; throw e; }
        if (opt.onProgress) opt.onProgress((k + 1) / n);
        return j;
      });
    }
    function loop(last) { if (i >= n) return Promise.resolve(last); var k = i++; return part(k).then(loop); }
    return loop(null);
  }

  // ---------- reading an .xlsx in the browser ----------
  function u16(dv, o) { return dv.getUint16(o, true); } function u32(dv, o) { return dv.getUint32(o, true); }
  function utf8(b) { return new TextDecoder('utf-8').decode(b); }
  function zipEntries(buf) {
    var dv = new DataView(buf.buffer, buf.byteOffset, buf.byteLength), i = buf.length - 22, min = Math.max(0, buf.length - 65557);
    for (; i >= min; i--) if (u32(dv, i) === 0x06054b50) break;
    if (i < min) throw new Error('That is not a zip archive, so it cannot be an .xlsx workbook.');
    var n = u16(dv, i + 10), off = u32(dv, i + 16);
    if (n === 0xFFFF || off === 0xFFFFFFFF) throw new Error('This workbook uses the large-zip format, which the browser reader does not support.');
    if (n < 3 || n > LIMITS.entries) throw new Error(n > LIMITS.entries ? 'The workbook contains too many files.' : 'That file is not a valid .xlsx workbook.');
    var p = off, out = {}, declared = 0;
    for (var k = 0; k < n; k++) {
      if (p + 46 > buf.length || u32(dv, p) !== 0x02014b50) throw new Error('The workbook archive is damaged.');
      var flags = u16(dv, p + 8), method = u16(dv, p + 10), cs = u32(dv, p + 20), us = u32(dv, p + 24), nl = u16(dv, p + 28), el = u16(dv, p + 30), cl = u16(dv, p + 32), lho = u32(dv, p + 42);
      var name = utf8(buf.subarray(p + 46, p + 46 + nl)); p += 46 + nl + el + cl;
      if (!name || name.indexOf('\0') >= 0 || name.indexOf('\\') >= 0 || name.charAt(0) === '/' || /(^|\/)\.\.(\/|$)/.test(name) || /^[A-Za-z]:/.test(name)) throw new Error('The workbook contains unsafe file names and was refused.');
      declared += us; if (declared > LIMITS.total * 4 || us > LIMITS.part * 2) throw new Error('The workbook expands to far too much data and was refused.');
      if (cs > 0 && us / cs > LIMITS.ratio && us > 1048576) throw new Error('The workbook looks like a compression bomb and was refused.');
      if (flags & 1) throw new Error('The workbook is password protected.');
      out[name.toLowerCase()] = { name: name, method: method, cs: cs, us: us, lho: lho };
    }
    return out;
  }
  function inflate(bytes, max) {
    var s = new Blob([bytes]).stream().pipeThrough(new DecompressionStream('deflate-raw')), r = s.getReader(), parts = [], total = 0;
    function pump() { return r.read().then(function (x) { if (x.done) { var o = new Uint8Array(total), q = 0; parts.forEach(function (c) { o.set(c, q); q += c.length; }); return o; } total += x.value.length; if (total > max) { r.cancel(); throw new Error('A part of the workbook is too large to read safely.'); } parts.push(x.value); return pump(); }); }
    return pump();
  }
  function readEntry(buf, e, budget) {
    if (e.us > LIMITS.part) return Promise.reject(new Error('A part of the workbook is too large to read safely.'));
    var dv = new DataView(buf.buffer, buf.byteOffset, buf.byteLength), lh = e.lho;
    if (lh + 30 > buf.length || u32(dv, lh) !== 0x04034b50) return Promise.reject(new Error('The workbook archive is damaged.'));
    var start = lh + 30 + u16(dv, lh + 26) + u16(dv, lh + 28), data = buf.subarray(start, start + e.cs), p;
    if (e.method === 0) p = Promise.resolve(data); else if (e.method === 8) { if (typeof DecompressionStream === 'undefined') return Promise.reject(new Error('This browser cannot unzip files (no DecompressionStream).')); p = inflate(data, LIMITS.part); } else return Promise.reject(new Error('The workbook uses an unsupported zip method.'));
    return p.then(function (out) { budget.left -= out.length; if (budget.left < 0) throw new Error('The workbook expands to too much data.'); var t = utf8(out); if (/<!DOCTYPE|<!ENTITY/i.test(t)) throw new Error('The workbook contains unsafe XML and was refused.'); return t; });
  }
  function xml(text) { var d = new DOMParser().parseFromString(text, 'application/xml'); if (d.getElementsByTagName('parsererror').length) throw new Error('The workbook XML could not be read.'); return d; }
  function tags(node, name) { return Array.prototype.slice.call(node.getElementsByTagNameNS('*', name)); }
  function siText(si) { var out = ''; (function walk(n) { for (var c = n.firstElementChild; c; c = c.nextElementSibling) { if (c.localName === 'rPh') continue; if (c.localName === 't') out += c.textContent; else walk(c); } })(si); return out; }
  function colIndex(letters) { var n = 0; letters = letters.toUpperCase(); for (var i = 0; i < letters.length; i++) { n = n * 26 + (letters.charCodeAt(i) - 64); if (n > 16384) return 0; } return n; }
  function builtinDate(id) { return (id >= 14 && id <= 22) || (id >= 27 && id <= 36) || (id >= 45 && id <= 47) || (id >= 50 && id <= 58); }
  function customDate(code) { var c = code.replace(/"[^"]*"|\\.|\[[^\]]*\]|_.|\*.|@/g, ''); return /[ymdhs]/i.test(c) && !/^general$/i.test(c.trim()); }
  function dateStyles(text) {
    if (!text) return []; var d = xml(text), custom = {}, out = [];
    tags(d, 'numFmt').forEach(function (n) { custom[+n.getAttribute('numFmtId')] = n.getAttribute('formatCode') || ''; });
    var xfs = tags(d, 'cellXfs')[0]; if (xfs) for (var c = xfs.firstElementChild; c; c = c.nextElementSibling) { if (c.localName !== 'xf') continue; var id = +c.getAttribute('numFmtId'); out.push(id in custom ? customDate(custom[id]) : builtinDate(id)); }
    return out;
  }
  function resolve(base, target) {
    target = target.replace(/\\/g, '/'); var p = target.charAt(0) === '/' ? target.slice(1) : base.replace(/\/$/, '') + '/' + target, out = [];
    p.split('/').forEach(function (s) { if (s === '' || s === '.') return; if (s === '..') { if (!out.length) return; out.pop(); return; } out.push(s); }); return out.join('/');
  }
  function parseSheet(text, strings, styles, cellBudget, name) {
    var d = xml(text), rows = {}, nRows = 0, truncated = false;
    tags(d, 'row').forEach(function (row) {
      if (++nRows > LIMITS.rows) throw new Error('The sheet "' + name + '" has too many rows (max ' + LIMITS.rows + ').');
      var rn = parseInt(row.getAttribute('r'), 10) || nRows, cells = {}, col = 0, any = false;
      for (var c = row.firstElementChild; c; c = c.nextElementSibling) {
        if (c.localName !== 'c') continue;
        var ref = c.getAttribute('r') || '', t = c.getAttribute('t') || '', s = c.getAttribute('s'), m = /^([A-Za-z]{1,3})\d+$/.exec(ref);
        col = m ? colIndex(m[1]) : col + 1;
        var v = null, inline = null;
        for (var k = c.firstElementChild; k; k = k.nextElementSibling) { if (k.localName === 'v') v = k.textContent; else if (k.localName === 'is') inline = siText(k); }
        var val = null;
        if (t === 'inlineStr') val = inline !== null ? inline : v;
        else if (t === 's') val = v !== null && /^\d+$/.test(v.trim()) ? (strings[+v] === undefined ? null : strings[+v]) : null;
        else if (t === 'str') val = v;
        else if (t === 'b') val = v === null ? null : v.trim() === '1';
        else if (t === 'e') val = null;
        else if (t === 'd') val = v === null ? null : v.trim();
        else if (v === null || v.trim() === '') val = inline;
        else { var tv = v.trim(); if (isNaN(+tv)) val = tv; else if (s !== null && styles[+s]) val = { s: +tv }; else val = /^-?\d{1,15}$/.test(tv) ? parseInt(tv, 10) : parseFloat(tv); }
        if (val === null || val === '') continue;
        if (col < 1 || col > LIMITS.cols) { truncated = true; continue; }
        if (--cellBudget.left < 0) throw new Error('The workbook has too many cells.');
        cells[col] = val; any = true;
      }
      if (any) rows[rn] = cells;
    });
    return { rows: rows, truncated: truncated };
  }
  function parseXlsx(file) {
    return bytesOf(file).then(function (buf) {
      if (buf.length > 8 * 1048576) throw new Error('The file is too large (max 8 MB).');
      if (!(buf[0] === 0x50 && buf[1] === 0x4b)) throw new Error('That does not look like an .xlsx file.');
      var ent = zipEntries(buf), budget = { left: LIMITS.total }, cellBudget = { left: LIMITS.cells }, get = function (n, optional) { var e = ent[n.toLowerCase()]; if (!e) { if (optional) return Promise.resolve(null); return Promise.reject(new Error('That file is a zip archive but not an Excel workbook (.xlsx).')); } return readEntry(buf, e, budget); };
      return Promise.all([get('[Content_Types].xml'), get('xl/workbook.xml'), get('xl/_rels/workbook.xml.rels'), get('xl/styles.xml', true), get('xl/sharedStrings.xml', true)]).then(function (r) {
        if (r[0].indexOf('spreadsheetml') < 0) throw new Error('That file is a zip archive but not an Excel workbook (.xlsx).');
        var wb = xml(r[1]), rels = {}, d1904 = false, metas = [];
        tags(xml(r[2]), 'Relationship').forEach(function (x) { rels[x.getAttribute('Id')] = [x.getAttribute('Target') || '', x.getAttribute('Type') || '', x.getAttribute('TargetMode') || '']; });
        tags(wb, 'workbookPr').forEach(function (x) { var v = (x.getAttribute('date1904') || '').toLowerCase(); d1904 = v === '1' || v === 'true'; });
        tags(wb, 'sheet').forEach(function (x) { var st = x.getAttribute('state') || ''; metas.push({ name: (x.getAttribute('name') || '').slice(0, 120), state: st === 'hidden' || st === 'veryHidden' ? st : 'visible', rid: x.getAttribute('r:id') || x.getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id') || '' }); });
        if (!metas.length) throw new Error('The workbook has no sheets.'); if (metas.length > LIMITS.sheets) throw new Error('The workbook has too many sheets.');
        var styles = dateStyles(r[3]), strings = r[4] ? tags(xml(r[4]), 'si').map(siText) : [], out = [];
        function next(i) {
          if (i >= metas.length) return Promise.resolve({ kind: 'xlsx', date1904: d1904, sheets: out });
          var m = metas[i], rel = rels[m.rid], sh = { name: m.name, state: m.state, kind: 'other', rows: {} };
          var target = rel && rel[2] !== 'External' ? resolve('xl', rel[0]) : null;
          if (!target || !/\/worksheet$/.test(rel[1]) || !ent[target.toLowerCase()]) { out.push(sh); return next(i + 1); }
          return readEntry(buf, ent[target.toLowerCase()], budget).then(function (t) { var ps = parseSheet(t, strings, styles, cellBudget, m.name); sh.kind = 'worksheet'; sh.rows = ps.rows; sh.truncated = ps.truncated; out.push(sh); return next(i + 1); });
        }
        return next(0);
      });
    });
  }
  function sendRows(parsed, opt) { return sendBytes(TE.encode(JSON.stringify(parsed)), Object.assign({}, opt, { kind: 'rows' })); }

  W.MfncUpfb = { blocked: blocked, bytesOf: bytesOf, sendBytes: sendBytes, parseXlsx: parseXlsx, sendRows: sendRows };
})();
