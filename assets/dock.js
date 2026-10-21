/* Dock — vanilla port of the React Dock (magnifying icon bar).
   Options (data attributes on [data-dock], same names/defaults as the React props):
     data-base="50"      baseItemSize   data-magnify="70"  magnification   data-panel="68"  panelHeight
     data-gap="10"       gap (spacing)  data-round="0.5"   roundness (0 = square, 1 = circle; border-radius = roundness * size)
     data-distance="150" distance over which the magnification falls off   data-spring="150,12,0.1" stiffness,damping,mass
   Features: spring-smoothed magnification following the pointer, hover labels, active indicator dot, badges, bounce on click,
   context menu (right click / press-and-hold / Menu key) with Open + Unpin, arrow-key navigation. Hidden below the desktop breakpoint by CSS.
   prefers-reduced-motion: no magnification animation and no bounce. */
(function () {
  'use strict';
  var d = document, reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  function num(el, k, def) { var v = parseFloat(el.getAttribute('data-' + k)); return isNaN(v) ? def : v; }

  function init(root) {
    if (!root || root._dock) return; root._dock = true;
    var panel = root.querySelector('.mf-dock__panel'), items = [].slice.call(root.querySelectorAll('.mf-dock__item')), menu = root.querySelector('.mf-dock__menu');
    var base = num(root, 'base', 50), mag = Math.max(num(root, 'magnify', 70), base), ph = num(root, 'panel', 68), gap = num(root, 'gap', 10), round = Math.max(0, Math.min(1, num(root, 'round', 0.5))), dist = num(root, 'distance', 150);
    var sp = (root.getAttribute('data-spring') || '150,12,0.1').split(',').map(parseFloat), K = sp[0] || 150, C = sp[1] || 12, M = sp[2] || 0.1;
    root.style.setProperty('--dock-h', ph + 'px'); root.style.setProperty('--dock-gap', gap + 'px'); root.style.setProperty('--dock-max', Math.max(mag + 16, ph) + 'px');
    var st = items.map(function () { return { v: base, x: base, vel: 0 }; }), mouseX = null, raf = 0, last = 0;
    function apply(i) { var s = Math.round(st[i].v * 10) / 10, it = items[i], t = it.firstElementChild; it.style.width = s + 'px'; it.style.height = s + 'px'; t.style.borderRadius = (round * s) + 'px'; it.style.setProperty('--s', s + 'px'); }
    function target(i) {
      if (mouseX === null) return base; var r = items[i].getBoundingClientRect(), c = r.left + r.width / 2, dd = Math.abs(mouseX - c);
      if (dd >= dist) return base; return base + (mag - base) * Math.max(0, 0.5 + 0.5 * Math.cos(Math.PI * dd / dist)); // smooth bump: full at 0, base at distance
    }
    function tick(now) {
      var dt = Math.min(0.032, (now - last) / 1000 || 0.016); last = now; var moving = false;
      for (var i = 0; i < items.length; i++) {
        var s = st[i], tg = target(i); for (var n = 0, h = dt / 8; n < 8; n++) { s.vel += ((-K * (s.v - tg) - C * s.vel) / M) * h; s.v += s.vel * h; } // sub-stepped so the stiff spring stays stable
        if (Math.abs(s.v - tg) > 0.05 || Math.abs(s.vel) > 0.05) moving = true; else { s.v = tg; s.vel = 0; } apply(i);
      }
      raf = moving ? requestAnimationFrame(tick) : 0;
    }
    function kick() { if (!raf && !reduce) { last = performance.now(); raf = requestAnimationFrame(tick); } }
    items.forEach(function (it, i) { st[i].v = base; apply(i); });
    if (!reduce) {
      panel.addEventListener('pointermove', function (e) { if (e.pointerType === 'touch') return; mouseX = e.clientX; kick(); });
      panel.addEventListener('pointerleave', function () { mouseX = null; kick(); });
    }
    // bounce on click (keyboard activation too); navigation itself is not delayed
    panel.addEventListener('click', function (e) { var it = e.target.closest('.mf-dock__item'); if (!it || reduce) return; it.classList.remove('is-bounce'); void it.offsetWidth; it.classList.add('is-bounce'); setTimeout(function () { it.classList.remove('is-bounce'); }, 620); });

    // keyboard: arrows / Home / End move focus; Menu or Shift+F10 opens the context menu
    panel.addEventListener('keydown', function (e) {
      var it = e.target.closest('.mf-dock__item'); if (!it) return; var i = items.indexOf(it), n = -1;
      if (e.key === 'ArrowRight') n = (i + 1) % items.length; else if (e.key === 'ArrowLeft') n = (i - 1 + items.length) % items.length; else if (e.key === 'Home') n = 0; else if (e.key === 'End') n = items.length - 1;
      else if (e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10')) { e.preventDefault(); openMenu(it, true); return; }
      if (n >= 0) { e.preventDefault(); items[n].focus(); }
    });
    // focus also magnifies the item for keyboard users
    panel.addEventListener('focusin', function (e) { var it = e.target.closest('.mf-dock__item'); if (!it || reduce || mouseX !== null) return; var r = it.getBoundingClientRect(); mouseX = r.left + r.width / 2; kick(); });
    panel.addEventListener('focusout', function () { if (!panel.contains(d.activeElement)) { mouseX = null; kick(); } });

    // ---- context menu
    var cur = null;
    function closeMenu() { if (!menu || menu.hidden) return; menu.hidden = true; cur = null; }
    function openMenu(it, focus) {
      if (!menu) return; cur = it; var r = it.getBoundingClientRect(), pr = root.getBoundingClientRect();
      menu.hidden = false; menu.querySelector('[data-act=open]').setAttribute('href', it.getAttribute('href'));
      var w = menu.offsetWidth; menu.style.left = Math.max(4, Math.min(pr.width - w - 4, r.left - pr.left + r.width / 2 - w / 2)) + 'px';
      if (focus) menu.querySelector('[data-act=open]').focus();
    }
    panel.addEventListener('contextmenu', function (e) { var it = e.target.closest('.mf-dock__item'); if (!it) return; e.preventDefault(); openMenu(it, false); });
    var hold = 0, held = false, sx = 0, sy = 0;
    panel.addEventListener('pointerdown', function (e) { var it = e.target.closest('.mf-dock__item'); held = false; if (!it || e.button > 0) return; sx = e.clientX; sy = e.clientY; clearTimeout(hold); hold = setTimeout(function () { held = true; openMenu(it, false); }, 520); });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) { panel.addEventListener(ev, function () { clearTimeout(hold); }); });
    panel.addEventListener('pointermove', function (e) { if (hold && Math.hypot(e.clientX - sx, e.clientY - sy) > 8) clearTimeout(hold); });
    panel.addEventListener('click', function (e) { if (held) { e.preventDefault(); e.stopPropagation(); held = false; } }, true); // the press that opened the menu must not also navigate
    d.addEventListener('pointerdown', function (e) { if (menu && !menu.hidden && !menu.contains(e.target)) closeMenu(); });
    d.addEventListener('keydown', function (e) { if (e.key === 'Escape' && menu && !menu.hidden) { var it = cur; closeMenu(); if (it) it.focus(); } });
    if (menu) {
      menu.addEventListener('click', function (e) {
        var b = e.target.closest('[data-act]'); if (!b) return; var act = b.getAttribute('data-act');
        if (act === 'unpin' && cur) {
          var f = d.createElement('form'); f.method = 'post'; f.action = root.getAttribute('data-unpin-action'); f.hidden = true;
          [['_csrf', d.body.getAttribute('data-csrf')], ['page', cur.getAttribute('data-key')], ['_back', location.pathname + location.search]].forEach(function (p) { var i = d.createElement('input'); i.type = 'hidden'; i.name = p[0]; i.value = p[1]; f.appendChild(i); });
          d.body.appendChild(f); closeMenu(); if (f.requestSubmit) f.requestSubmit(); else f.submit();
        } else closeMenu();
      });
      menu.addEventListener('keydown', function (e) { var bs = [].slice.call(menu.querySelectorAll('[role=menuitem]')), i = bs.indexOf(d.activeElement); if (e.key === 'ArrowDown') { e.preventDefault(); bs[(i + 1) % bs.length].focus(); } else if (e.key === 'ArrowUp') { e.preventDefault(); bs[(i - 1 + bs.length) % bs.length].focus(); } });
    }
  }
  function boot() { [].forEach.call(d.querySelectorAll('[data-dock]'), init); }
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', boot); else boot();
  d.addEventListener('mfnc:page', boot); // the single-page router swaps #mf-dock when pins change
})();
