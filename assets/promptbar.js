/* PromptBar: vanilla port of the React component (same DOM + class names + CSS in promptbar.css; same keyboard behaviour, menus, effort slider, send/stop morph, spark canvas at Max).
   var bar = PromptBar.create({ placeholder, sources, commands, models, defaultModel, efforts, defaultEffort, onEffortChange, busy, onSend(text,{attachments,model,effort}), onStop, onAttach, onDictate, onDictateCancel,
     background, color, menuBackground, sparkColor, sparkBoost, width, radius, maxRows, morphDuration, squash, tilt, pressScale, className });
   parent.appendChild(bar.el);  bar.setBusy(true); bar.setModels([...]); bar.addAttachment({name, status}) -> {update(patch), remove()}; bar.focus(); bar.setNotice(text); bar.destroy().
   Extras for the app: model rows may be {locked:true}; a row with {href} is a link row ("Get in Plugins"); sources may be {kind:'data'}. Needs motion.js. */
(function (w) {
  'use strict';
  var d = document, M = w.MfMotion;
  var ARROW_UP = [12, 4.5, 18.5, 11, 14.25, 11, 14.25, 19.5, 9.75, 19.5, 9.75, 11, 5.5, 11], SQUARE = [12, 6, 18, 6, 18, 12, 18, 18, 6, 18, 6, 12, 6, 6], EASE_IN_OUT = [0.77, 0, 0.175, 1], LINE = 22, EDGE = 11;
  var NS = 'http://www.w3.org/2000/svg';
  var ICONS = {
    plus: ['M12 5v14', 'M5 12h14'], chevron: ['m6 9 6 6 6-6'], sparkles: ['M9.9 15.5A2 2 0 0 0 8.5 14.1l-6.1-1.6a.5.5 0 0 1 0-1l6.1-1.6A2 2 0 0 0 9.9 8.5l1.6-6.1a.5.5 0 0 1 1 0l1.6 6.1a2 2 0 0 0 1.4 1.4l6.1 1.6a.5.5 0 0 1 0 1l-6.1 1.6a2 2 0 0 0-1.4 1.4l-1.6 6.1a.5.5 0 0 1-1 0z', 'M20 3v4', 'M22 5h-4', 'M4 17v2', 'M5 18H3'],
    mic: ['M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z', 'M19 10v2a7 7 0 0 1-14 0v-2', 'M12 19v3'], file: ['M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z', 'M14 2v6h6'], x: ['M18 6 6 18', 'M6 6l12 12'], check: ['M20 6 9 17l-5-5'],
    help: ['M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3', 'M12 17h.01', 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z'], clip: ['m21.4 11.1-9.2 9.2a6 6 0 0 1-8.5-8.5l8.6-8.6A4 4 0 1 1 18 8.8l-8.6 8.6a2 2 0 0 1-2.8-2.8l8.5-8.5'],
    globe: ['M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20z', 'M2 12h20', 'M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z'], lock: ['M5 11h14v10H5z', 'M7 11V7a5 5 0 0 1 10 0v4'],
    data: ['M3 22h18', 'M6 18v-7', 'M10 18v-7', 'M14 18v-7', 'M18 18v-7', 'M12 2 3 7v2h18V7z'], link: ['M7 17 17 7', 'M8 7h9v9']
  };
  function icon(name, size, sw) {
    var s = d.createElementNS(NS, 'svg'); s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('width', size); s.setAttribute('height', size); s.setAttribute('class', 'pb-i'); s.setAttribute('aria-hidden', 'true'); s.setAttribute('stroke-width', sw || 1.8);
    (ICONS[name] || []).forEach(function (p) { var e = d.createElementNS(NS, 'path'); e.setAttribute('d', p); s.appendChild(e); }); return s;
  }
  function h(tag, cls, attrs) { var e = d.createElement(tag); if (cls) e.className = cls; if (attrs) for (var k in attrs) { if (attrs[k] === false || attrs[k] == null) continue; e.setAttribute(k, attrs[k] === true ? '' : attrs[k]); } return e; }
  function mix(a, b, t) { return a + (b - a) * t; }
  function pathAt(a, b, t) { var s = ''; for (var i = 0; i < a.length; i += 2) s += (i ? 'L' : 'M') + mix(a[i], b[i], t).toFixed(2) + ' ' + mix(a[i + 1], b[i + 1], t).toFixed(2); return s + 'Z'; }
  function parseToken(draft) { var m = /(^|\s)([@/])([\w-]*)$/.exec(draft); if (!m) return null; return { kind: m[2] === '@' ? 'at' : 'slash', query: m[3].toLowerCase(), start: m.index + m[1].length }; }

  var DEFAULTS = {
    placeholder: 'Ask anything', sources: [], commands: [], models: [], defaultModel: '', efforts: ['Low', 'Medium', 'High', 'Extra', 'Max'], defaultEffort: '', busy: false, background: '', color: '', menuBackground: '', sparkColor: '', sparkBoost: 1,
    width: 400, radius: 16, maxRows: 5, morphDuration: 240, squash: 0.12, tilt: 8, pressScale: 0.96, className: ''
  };

  function create(options) {
    var o = Object.assign({}, DEFAULTS, options || {}), efforts = o.efforts, latest = o, reduce = function () { return M.reduced(); };
    var st = { draft: '', attachments: [], modelKey: o.defaultModel, plusOpen: false, modelOpen: false, effortOpen: false, effortIndex: 0, dismissed: false, active: 0, listening: false, pressed: false, busy: !!o.busy, notice: '' };
    var i0 = efforts.indexOf(o.defaultEffort); st.effortIndex = i0 >= 0 ? i0 : Math.max(0, Math.floor((efforts.length - 1) / 2));
    var typing = { energy: 0, strokes: 0 }, dictation = 0, lastOpen = null, rowEls = [], menuEl = null, glowEl = null, nextAtt = 1, destroyed = false;

    // ---------- DOM ----------
    var root = h('div', 'prompt-bar' + (o.className ? ' ' + o.className : ''));
    function setVars() {
      var s = root.style; if (o.background) s.setProperty('--pb-bg', o.background); if (o.color) s.setProperty('--pb-ink', o.color); if (o.menuBackground) s.setProperty('--pb-menu', o.menuBackground);
      s.setProperty('--pb-w', typeof o.width === 'number' ? o.width + 'px' : String(o.width)); s.setProperty('--pb-radius', o.radius + 'px'); if (o.sparkColor) s.setProperty('--pb-spark', o.sparkColor); s.setProperty('--pb-press', String(o.pressScale));
    }
    setVars();
    var field = h('div', 'prompt-bar__field', { role: 'presentation' }), sparks = h('canvas', 'prompt-bar__sparks', { 'aria-hidden': 'true' }), chips = h('div', 'prompt-bar__chips');
    var input = h('textarea', 'prompt-bar__input', { rows: 1, 'aria-label': 'Prompt', placeholder: o.placeholder, enterkeyhint: 'send' });
    var bar = h('div', 'prompt-bar__bar'), notice = h('div', 'prompt-bar__notice', { role: 'status' }); notice.hidden = true;
    var plusBtn = h('button', 'prompt-bar__tool', { type: 'button', 'aria-label': 'Add files and sources', 'aria-expanded': 'false' }); plusBtn.appendChild(icon('plus', 16, 2));
    var modelBtn = h('button', 'prompt-bar__pick', { type: 'button', 'aria-label': 'Choose model', 'aria-expanded': 'false' }), modelName = h('span'); modelBtn.appendChild(modelName); modelBtn.appendChild(icon('chevron', 12, 2.4));
    var effortBtn = h('button', 'prompt-bar__pick', { type: 'button', 'aria-label': 'Choose effort', 'aria-expanded': 'false' }), effortName = h('span'); effortBtn.appendChild(icon('sparkles', 13, 2)); effortBtn.appendChild(effortName);
    var spacer = h('span', 'prompt-bar__spacer'), micBtn = null;
    var sendBtn = h('button', 'prompt-bar__send', { type: 'button' });
    var glyph = d.createElementNS(NS, 'svg'); glyph.setAttribute('class', 'prompt-bar__glyph'); glyph.setAttribute('viewBox', '0 0 24 24'); glyph.setAttribute('aria-hidden', 'true'); glyph.setAttribute('fill', 'currentColor'); glyph.setAttribute('stroke', 'currentColor'); glyph.setAttribute('stroke-width', '2'); glyph.setAttribute('stroke-linejoin', 'round');
    var glyphPath = d.createElementNS(NS, 'path'); glyph.appendChild(glyphPath); sendBtn.appendChild(glyph);
    field.appendChild(sparks); field.appendChild(input); bar.appendChild(plusBtn); bar.appendChild(modelBtn); bar.appendChild(effortBtn); bar.appendChild(spacer);
    if (o.onDictate) { micBtn = h('button', 'prompt-bar__tool', { type: 'button' }); bar.appendChild(micBtn); }
    bar.appendChild(sendBtn); field.appendChild(bar); field.appendChild(notice); root.appendChild(field);
    // chips sit above the input, as in the original
    field.insertBefore(chips, input); chips.hidden = true;

    // ---------- derived state ----------
    function model() { var ms = o.models; for (var i = 0; i < ms.length; i++) if (ms[i].key === st.modelKey) return ms[i]; for (var j = 0; j < ms.length; j++) if (!ms[j].locked) return ms[j]; return ms[0]; }
    function token() { return st.dismissed ? null : parseToken(st.draft); }
    function openKind() { var t = token(); return st.plusOpen ? 'at' : (t ? t.kind : (st.modelOpen ? 'model' : (st.effortOpen ? 'effort' : null))); }
    function query() { var t = token(); return st.plusOpen ? '' : (t ? t.query : ''); }
    function list() {
      var k = openKind(), q = query();
      if (k === 'at') return o.sources.filter(function (s) { return s.name.toLowerCase().indexOf(q) >= 0; });
      if (k === 'slash') return o.commands.filter(function (c) { return c.name.replace(/^\//, '').toLowerCase().indexOf(q) === 0; });
      if (k === 'model') return o.models.concat(o.modelFooter ? [o.modelFooter] : []);
      return [];
    }
    function cursor(l) { return Math.min(st.active, Math.max(0, l.length - 1)); }
    function canSend() { return st.draft.trim().length > 0 || st.attachments.some(function (a) { return a.status !== 'error'; }); }
    function uploading() { return st.attachments.some(function (a) { return a.status === 'uploading'; }); }
    function armed() { return st.busy || canSend(); }
    function level() { return efforts[st.effortIndex] || ''; }
    function maxed() { return efforts.length > 1 && st.effortIndex === efforts.length - 1; }
    var quiet = false;
    function focusInput() { quiet = true; try { input.focus({ preventScroll: true }); } catch (e) { input.focus(); } quiet = false; }

    // ---------- send glyph morph ----------
    var t = M.value(st.busy ? 1 : 0), dir = st.busy ? 1 : -1;
    t.on(function (v) {
      glyphPath.setAttribute('d', pathAt(ARROW_UP, SQUARE, v)); var goo = reduce() ? 0 : Math.sin(v * Math.PI), sx = 1 - o.squash * goo;
      glyph.style.transform = goo ? 'rotate(' + (dir * o.tilt * goo) + 'deg) scale(' + sx + ', ' + (1 / sx) + ')' : '';
    });
    glyphPath.setAttribute('d', pathAt(ARROW_UP, SQUARE, t.get()));
    function morph() { var target = st.busy ? 1 : 0; dir = st.busy ? 1 : -1; if (t.get() === target) { t.stop(); return; } if (reduce()) { t.jump(target); return; } M.animate(t, target, { duration: o.morphDuration / 1000, ease: EASE_IN_OUT }); }

    // ---------- sparks (only at max effort) ----------
    var spark = { raf: 0, ro: null, on: false };
    function startSparks() {
      if (spark.on) return; var ctx = sparks.getContext && sparks.getContext('2d'); if (!ctx) return; spark.on = true; typing.strokes = 0;
      var last = performance.now(), W = 0, H = 0, due = 0, speed = 1, pulse = 0, parts = [];
      function resize() { var r = sparks.getBoundingClientRect(), dpr = Math.min(2, w.devicePixelRatio || 1); W = r.width; H = r.height; sparks.width = Math.round(W * dpr); sparks.height = Math.round(H * dpr); ctx.setTransform(dpr, 0, 0, dpr, 0, 0); }
      function spawn(burst) { parts.push({ x: Math.random() * W, y: burst ? H * (0.2 + Math.random() * 0.8) : H + 3, r: 0.9 + Math.random() * 1.1, vy: -(7 + Math.random() * 9), sway: (Math.random() - 0.5) * 10, phase: Math.random() * Math.PI * 2, life: burst ? Math.random() * 1.2 : 0, span: 2.4 + Math.random() * 2.4 }); }
      function color() { return getComputedStyle(root).getPropertyValue('--pb-spark').trim() || '#b39dff'; }
      var col = color();
      function tick(now) {
        if (d.hidden) { last = now; spark.raf = requestAnimationFrame(tick); return; }
        var dt = Math.min(0.05, (now - last) / 1000); last = now; var gain = o.sparkBoost;
        typing.energy *= Math.exp(-dt / 0.8); pulse *= Math.exp(-dt / 0.16); if (typing.strokes > 0) { typing.strokes = 0; if (gain > 0) pulse = 1; }
        var energy = typing.energy * gain; speed += (1 + energy * 6 - speed) * (1 - Math.exp(-dt / 0.15)); due += dt; while (due > 0.14) { due -= 0.14; if (parts.length < 30) spawn(false); }
        ctx.clearRect(0, 0, W, H); ctx.fillStyle = col; ctx.shadowColor = col; ctx.shadowBlur = 6 + energy * 10 + pulse * 6;
        for (var i = parts.length - 1; i >= 0; i--) {
          var p = parts[i]; p.life += dt; if (p.life > p.span) { parts.splice(i, 1); continue; } var k = p.life / p.span, twinkle = 0.7 + 0.3 * Math.sin((now / 160) * (1 + energy) + p.phase);
          p.y += p.vy * dt * speed; if (p.y < -4) { p.y = H + 3; p.x = Math.random() * W; }
          var edge = Math.min(1, Math.max(0, p.y / 14), Math.max(0, (H - p.y) / 14)); ctx.globalAlpha = Math.min(1, Math.sin(k * Math.PI) * (0.9 + energy * 0.25) * twinkle) * edge;
          ctx.beginPath(); ctx.arc(p.x + Math.sin((now / 900) * (1 + energy * 0.8) + p.phase) * p.sway, p.y, p.r * twinkle * (1 + energy * 0.35), 0, Math.PI * 2); ctx.fill();
        }
        spark.raf = requestAnimationFrame(tick);
      }
      resize(); for (var i = 0; i < 26; i++) spawn(true); if (w.ResizeObserver) { spark.ro = new ResizeObserver(resize); spark.ro.observe(sparks); } spark.raf = requestAnimationFrame(tick);
      spark.stop = function () { cancelAnimationFrame(spark.raf); if (spark.ro) spark.ro.disconnect(); ctx.clearRect(0, 0, W, H); spark.on = false; };
    }
    function stopSparks() { if (spark.on && spark.stop) spark.stop(); }

    // ---------- menu ----------
    function closeMenus() { st.plusOpen = false; st.modelOpen = false; st.effortOpen = false; }
    function effortStepAt(i) { return 'calc(' + EDGE + 'px + (100% - ' + EDGE * 2 + 'px) * ' + (i / Math.max(1, efforts.length - 1)) + ')'; }
    function effortFill(i) { return i === efforts.length - 1 ? '100%' : 'calc(' + effortStepAt(i) + ' + 7px)'; }
    var eff = {};
    function setEffort(i) { var n = Math.max(0, Math.min(efforts.length - 1, i)); if (n === st.effortIndex) return; st.effortIndex = n; if (latest.onEffortChange) latest.onEffortChange(efforts[n]); sync(); }
    function effortFromPointer(e) { var r = e.currentTarget.getBoundingClientRect(), k = (e.clientX - r.left - EDGE) / Math.max(1, r.width - 2 * EDGE); setEffort(Math.round(k * (efforts.length - 1))); }
    function buildEffort() {
      menuEl.textContent = ''; var head = h('div', 'prompt-bar__effort-head'), lv = h('span', 'prompt-bar__effort-level'), help = h('span', 'prompt-bar__effort-help', { title: 'Higher effort thinks longer before answering' });
      help.appendChild(icon('help', 14, 1.8)); head.appendChild(h('span', 'prompt-bar__effort-title')).textContent = 'Effort'; head.appendChild(lv); head.appendChild(help); menuEl.appendChild(head);
      var ends = h('div', 'prompt-bar__effort-ends'); ends.appendChild(h('span')).textContent = 'Faster'; ends.appendChild(h('span')).textContent = 'Smarter'; menuEl.appendChild(ends);
      var track = h('div', 'prompt-bar__effort-track', { role: 'slider', tabindex: 0, 'aria-label': 'Effort', 'aria-valuemin': 0, 'aria-valuemax': efforts.length - 1 });
      var fill = h('span', 'prompt-bar__effort-fill'); track.appendChild(fill); efforts.forEach(function (l, i) { var dot = h('i', 'prompt-bar__effort-dot'); dot.style.left = effortStepAt(i); track.appendChild(dot); });
      var thumb = h('span', 'prompt-bar__effort-thumb'); track.appendChild(thumb); menuEl.appendChild(track); eff = { lv: lv, track: track };
      track.addEventListener('pointerdown', function (e) { if (e.button !== 0) return; try { track.setPointerCapture(e.pointerId); } catch (x) { } try { track.focus({ preventScroll: true }); } catch (x2) { } effortFromPointer(e); });
      track.addEventListener('pointermove', function (e) { if (e.buttons & 1) effortFromPointer(e); });
      track.addEventListener('keydown', function (e) {
        var step = (e.key === 'ArrowRight' || e.key === 'ArrowUp') ? 1 : (e.key === 'ArrowLeft' || e.key === 'ArrowDown') ? -1 : 0;
        if (step) { e.preventDefault(); setEffort(st.effortIndex + step); } else if (e.key === 'Home') { e.preventDefault(); setEffort(0); } else if (e.key === 'End') { e.preventDefault(); setEffort(efforts.length - 1); }
        else if (e.key === 'Escape') { st.effortOpen = false; sync(); focusInput(); }
      });
      updateEffortUi();
    }
    function updateEffortUi() {
      if (!eff.track) return; eff.lv.textContent = level(); eff.track.setAttribute('aria-valuenow', st.effortIndex); eff.track.setAttribute('aria-valuetext', level());
      eff.track.style.setProperty('--pb-effort-x', effortStepAt(st.effortIndex)); eff.track.style.setProperty('--pb-effort-fill', effortFill(st.effortIndex));
    }
    function renderRows(k, l, cur) {
      // keep the glow element (it animates between rows); replace the rows
      rowEls.forEach(function (r) { if (r.parentNode) r.parentNode.removeChild(r); }); rowEls = []; var emp = menuEl.querySelector('.prompt-bar__empty'); if (emp) emp.remove();
      l.forEach(function (row, i) {
        var b = h('button', 'prompt-bar__row', { type: 'button', role: 'option', 'aria-selected': i === cur ? 'true' : 'false', 'data-locked': !!row.locked, 'data-link': !!row.href });
        if (k === 'at') { var ic = h('span', 'prompt-bar__row-icon'); ic.appendChild(icon(row.icon || 'file', 15, 1.8)); b.appendChild(ic); }
        if (k === 'model' && row.href && !row.key) { var li = h('span', 'prompt-bar__row-icon'); li.appendChild(icon('link', 15, 2)); b.appendChild(li); }
        b.appendChild(h('span', 'prompt-bar__row-name')).textContent = row.name; if (row.description) b.appendChild(h('span', 'prompt-bar__row-desc')).textContent = row.description;
        if (k === 'model' && row.key) {
          var tag = h('span', 'prompt-bar__row-tag'); if (row.locked) tag.appendChild(icon('lock', 12, 2)); tag.appendChild(d.createTextNode(row.locked ? (row.tag ? row.tag + ' · Plugin' : 'Plugin') : (row.tag || ''))); b.appendChild(tag);
          var ck = h('span', 'prompt-bar__row-check', { 'data-on': !row.locked && model() && row.key === model().key }); ck.appendChild(icon('check', 13, 2.5)); b.appendChild(ck);
        }
        b.addEventListener('mousedown', function (e) { e.preventDefault(); }); b.addEventListener('pointerenter', function () { st.active = i; placeGlow(false); updateSelected(); }); b.addEventListener('click', function () { pick(row); });
        menuEl.appendChild(b); rowEls.push(b);
      });
      if (!l.length) menuEl.appendChild(h('div', 'prompt-bar__empty')).textContent = 'No matches for “' + query() + '”';
    }
    function updateSelected() { var c = cursor(list()); rowEls.forEach(function (r, i) { r.setAttribute('aria-selected', i === c ? 'true' : 'false'); }); }
    function placeGlow(fresh) {
      if (!glowEl) return; var row = rowEls[cursor(list())]; if (!row) { glowEl.style.opacity = '0'; return; }
      if (fresh) glowEl.style.transition = 'none'; glowEl.style.top = row.offsetTop + 'px'; glowEl.style.height = row.offsetHeight + 'px'; glowEl.style.opacity = '1'; if (fresh) { void glowEl.offsetHeight; glowEl.style.transition = ''; }
    }
    function renderMenu() {
      var k = openKind();
      if (!k) { if (menuEl) { menuEl.remove(); menuEl = null; glowEl = null; rowEls = []; eff = {}; } lastOpen = null; return; }
      var fresh = lastOpen !== k, created = false;
      if (!menuEl) { menuEl = h('div', 'prompt-bar__menu'); root.insertBefore(menuEl, field); created = true; if (reduce()) menuEl.style.animation = 'none'; }
      menuEl.setAttribute('role', k === 'effort' ? 'dialog' : 'listbox'); menuEl.setAttribute('aria-label', k === 'at' ? 'Sources' : k === 'slash' ? 'Commands' : k === 'model' ? 'Models' : 'Effort'); menuEl.setAttribute('data-kind', k);
      if (k === 'effort') { if (fresh || created) { rowEls = []; glowEl = null; buildEffort(); } else updateEffortUi(); lastOpen = k; return; }
      if (fresh || created || !glowEl) { menuEl.textContent = ''; eff = {}; glowEl = h('span', 'prompt-bar__glow', { 'aria-hidden': 'true' }); menuEl.appendChild(glowEl); }
      var l = list(), c = cursor(l); renderRows(k, l, c); lastOpen = k; placeGlow(fresh || created);
    }
    function onDocDown(e) { if (!root.contains(e.target) && (st.plusOpen || st.modelOpen || st.effortOpen)) { closeMenus(); sync(); } }
    d.addEventListener('pointerdown', onDocDown);

    // ---------- actions ----------
    function pick(row) {
      var k = openKind();
      if (row.href && (k === 'model')) { closeMenus(); sync(); if (latest.onNavigate) latest.onNavigate(row.href); else w.location.href = row.href; return; }
      if (k === 'model') { if (row.locked) { closeMenus(); sync(); if (latest.onLocked) latest.onLocked(row); return; } st.modelKey = row.key; st.modelOpen = false; sync(); focusInput(); if (latest.onModelChange) latest.onModelChange(row.key); return; }
      var tk = token(), head = tk ? st.draft.slice(0, tk.start) : st.draft;
      if (row.attach) { st.draft = head; input.value = head; autosize(); Promise.resolve(latest.onAttach && latest.onAttach()).then(function (files) { if (!files) return; (Array.isArray(files) ? files : [files]).forEach(function (f) { addAtt(typeof f === 'string' ? { name: f } : f); }); }); }
      else if (k === 'at') { st.draft = head + '@' + row.name + ' '; input.value = st.draft; autosize(); }
      else { st.draft = head + row.name + ' '; input.value = st.draft; autosize(); }
      st.plusOpen = false; st.dismissed = false; sync(); focusInput();
    }
    function send() {
      if (!canSend() || st.busy) return; if (uploading()) { setNotice('Wait for the upload to finish.'); return; }
      var atts = st.attachments.filter(function (a) { return a.status !== 'error'; }).slice(), text = st.draft.trim();
      st.draft = ''; input.value = ''; st.attachments = []; st.dismissed = false; closeMenus(); autosize(); setNotice(''); sync(); focusInput();
      if (latest.onSend) latest.onSend(text, { attachments: atts, model: model(), effort: level() });
    }
    function toggleListen() {
      if (st.listening) { dictation++; st.listening = false; if (latest.onDictateCancel) latest.onDictateCancel(); sync(); return; }
      var seq = ++dictation; st.listening = true; sync();
      Promise.resolve(latest.onDictate && latest.onDictate()).then(function (text) { if (seq !== dictation) return; st.listening = false; if (text) { st.draft = st.draft.trim() ? st.draft.replace(/\s+$/, '') + ' ' + text : text; input.value = st.draft; autosize(); } sync(); focusInput(); },
        function () { if (seq === dictation) { st.listening = false; sync(); } });
    }
    function autosize() { input.style.height = '0px'; var max = LINE * o.maxRows; input.style.height = Math.min(Math.max(input.scrollHeight, LINE), max) + 'px'; input.style.overflowY = input.scrollHeight > max ? 'auto' : 'hidden'; }
    function setNotice(msg) { st.notice = msg || ''; notice.textContent = st.notice; notice.hidden = !st.notice; }
    function addAtt(a) {
      var att = Object.assign({ uid: nextAtt++, status: 'ready' }, a); st.attachments.push(att); sync();
      return { update: function (patch) { Object.assign(att, patch); sync(); }, remove: function () { var i = st.attachments.indexOf(att); if (i >= 0) { st.attachments.splice(i, 1); sync(); } }, att: att };
    }
    function renderChips() {
      chips.textContent = ''; chips.hidden = !st.attachments.length;
      st.attachments.forEach(function (a, i) {
        var chip = h('span', 'prompt-bar__chip', { 'data-state': a.status }); if (a.status === 'uploading') chip.appendChild(h('span', 'prompt-bar__chip-spin', { 'aria-hidden': 'true' })); else chip.appendChild(icon('file', 12, 2));
        chip.appendChild(h('span', 'prompt-bar__chip-name')).textContent = a.name; var x = h('button', 'prompt-bar__chip-x', { type: 'button', 'aria-label': 'Remove ' + a.name }); x.appendChild(icon('x', 10, 2.5));
        x.addEventListener('click', function (ev) { ev.stopPropagation(); var j = st.attachments.indexOf(a); if (j >= 0) st.attachments.splice(j, 1); if (a.onRemove) a.onRemove(a); sync(); }); chip.appendChild(x); chips.appendChild(chip);
      });
    }

    // ---------- sync ----------
    function sync() {
      if (destroyed) return; var m = model(), k = openKind();
      root.toggleAttribute('data-busy', st.busy); root.toggleAttribute('data-max', maxed()); field.toggleAttribute('data-max', maxed());
      input.placeholder = st.listening ? 'Listening…' : o.placeholder;
      plusBtn.setAttribute('aria-expanded', st.plusOpen ? 'true' : 'false'); plusBtn.toggleAttribute('data-on', st.plusOpen);
      modelBtn.hidden = !o.models.length; if (m) modelName.textContent = (w.matchMedia && w.matchMedia('(max-width: 480px)').matches) ? m.name.replace(/^Claude\s+/i, '') : m.name; /* phones: the brand prefix goes, so both pills stay readable */ modelBtn.setAttribute('aria-expanded', st.modelOpen ? 'true' : 'false'); modelBtn.toggleAttribute('data-on', st.modelOpen);
      effortBtn.hidden = !efforts.length; effortName.textContent = level(); effortBtn.setAttribute('aria-expanded', st.effortOpen ? 'true' : 'false'); effortBtn.toggleAttribute('data-on', st.effortOpen); effortBtn.toggleAttribute('data-max', maxed());
      if (micBtn) { micBtn.setAttribute('aria-label', st.listening ? 'Stop dictation' : 'Dictate'); micBtn.setAttribute('aria-pressed', st.listening ? 'true' : 'false'); micBtn.toggleAttribute('data-on', st.listening); micBtn.textContent = ''; if (st.listening) { var eq = h('span', 'prompt-bar__eq', { 'aria-hidden': 'true' }); eq.appendChild(h('i')); eq.appendChild(h('i')); eq.appendChild(h('i')); micBtn.appendChild(eq); } else micBtn.appendChild(icon('mic', 15, 2)); }
      var a = armed(); sendBtn.disabled = !a; sendBtn.setAttribute('aria-label', st.busy ? 'Stop' : 'Send'); sendBtn.toggleAttribute('data-armed', a); sendBtn.toggleAttribute('data-pressed', st.pressed);
      renderChips(); renderMenu(); updateEffortUi();
      if (maxed() && !reduce()) startSparks(); else stopSparks();
    }

    // ---------- events ----------
    input.addEventListener('input', function () { st.draft = input.value; typing.energy = Math.min(1.6, typing.energy + 0.22); typing.strokes = Math.min(4, typing.strokes + 1); st.dismissed = false; closeMenus(); st.active = 0; autosize(); sync(); });
    input.addEventListener('focus', function () { if (!quiet && (st.plusOpen || st.modelOpen || st.effortOpen)) { closeMenus(); sync(); } });
    input.addEventListener('keydown', function (e) {
      var k = openKind(), l = list();
      if (k && l.length && k !== 'effort') {
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); st.active = (cursor(l) + (e.key === 'ArrowDown' ? 1 : l.length - 1)) % l.length; sync(); return; }
        if ((e.key === 'Enter' && !e.shiftKey) || e.key === 'Tab') { e.preventDefault(); pick(l[cursor(l)]); return; }
      }
      if (e.key === 'Escape') { if (k) { e.preventDefault(); st.dismissed = true; closeMenus(); sync(); } return; }
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(); }
    });
    function noFocusSteal(b) { b.addEventListener('mousedown', function (e) { e.preventDefault(); }); }
    [plusBtn, modelBtn, effortBtn, sendBtn].forEach(noFocusSteal); if (micBtn) noFocusSteal(micBtn);
    plusBtn.addEventListener('click', function () { st.modelOpen = false; st.effortOpen = false; st.active = 0; st.plusOpen = !st.plusOpen; sync(); focusInput(); });
    modelBtn.addEventListener('click', function () { st.plusOpen = false; st.effortOpen = false; var m = model(); st.active = Math.max(0, o.models.indexOf(m)); st.modelOpen = !st.modelOpen; sync(); focusInput(); });
    effortBtn.addEventListener('click', function () { st.plusOpen = false; st.modelOpen = false; st.effortOpen = !st.effortOpen; sync(); focusInput(); });
    if (micBtn) micBtn.addEventListener('click', toggleListen);
    sendBtn.addEventListener('click', function () { if (st.busy) { if (latest.onStop) latest.onStop(); } else send(); });
    sendBtn.addEventListener('pointerdown', function (e) { if (e.button !== 0 || !armed()) return; st.pressed = true; sync(); });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (n) { sendBtn.addEventListener(n, function () { if (st.pressed) { st.pressed = false; sync(); } }); });
    field.addEventListener('pointerdown', function (e) { if (e.target === field || e.target === input) { closeMenus(); sync(); } });
    field.addEventListener('click', focusInput);

    sync(); autosize();
    return {
      el: root, input: input,
      setBusy: function (b) { b = !!b; if (b === st.busy) return; st.busy = b; morph(); sync(); },
      isBusy: function () { return st.busy; },
      setModels: function (ms, footer) { o.models = ms; o.modelFooter = footer || null; if (!ms.some(function (m) { return m.key === st.modelKey; })) st.modelKey = (ms.filter(function (m) { return !m.locked; })[0] || ms[0] || {}).key; sync(); },
      setValue: function (v) { st.draft = v; input.value = v; autosize(); sync(); },
      getState: function () { return { text: st.draft, model: model(), effort: level(), attachments: st.attachments.slice() }; },
      setNotice: setNotice, addAttachment: addAtt, focus: focusInput, send: send,
      configure: function (patch) { Object.assign(o, patch); latest = o; if ('placeholder' in patch) input.placeholder = patch.placeholder; setVars(); sync(); },
      destroy: function () { destroyed = true; d.removeEventListener('pointerdown', onDocDown); stopSparks(); t.stop(); dictation++; root.remove(); }
    };
  }
  w.PromptBar = { create: create };
})(window);
