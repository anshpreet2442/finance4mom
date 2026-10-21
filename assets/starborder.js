/* StarBorder — vanilla port of the React StarBorder button/link.
   <a|button class="star-border" data-star data-color="#ffffff" data-duration="4" data-trail="0.3" data-thickness="1" data-radius="12" data-glow="0.6" data-theme="dark" data-hover-lap data-click-pulse>
   Props → data attributes (same names): color, duration (seconds per lap), trailLength → data-trail (0..1 of the border that is lit), thickness (px), radius (px), glow (0..1),
   hoverLap (speeds up for one lap on hover), clickPulse (ring pulse on click), theme (dark|light). prefers-reduced-motion: the ring stops. */
(function () {
  'use strict';
  var d = document, reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
  function init(el) {
    if (el._star) return; el._star = true;
    var g = function (k, def) { var v = el.getAttribute('data-' + k); return v === null || v === '' ? def : v; };
    el.style.setProperty('--sb-color', g('color', '#ffffff')); el.style.setProperty('--sb-duration', parseFloat(g('duration', '4')) + 's'); el.style.setProperty('--sb-trail', Math.max(0.02, Math.min(0.95, parseFloat(g('trail', '0.3')))));
    el.style.setProperty('--sb-thickness', parseFloat(g('thickness', '1')) + 'px'); el.style.setProperty('--sb-radius', parseFloat(g('radius', '12')) + 'px'); el.style.setProperty('--sb-glow', Math.max(0, Math.min(1, parseFloat(g('glow', '0.6')))));
    if (!el.hasAttribute('data-theme')) el.setAttribute('data-theme', 'dark');
    var label = el.innerHTML; el.innerHTML = '<span class="star-border__base" aria-hidden="true"></span><span class="star-border__glow" aria-hidden="true"></span><span class="star-border__ring" aria-hidden="true"></span><span class="star-border__inner">' + label + '</span><span class="star-border__pulse" aria-hidden="true"></span>';
    if (reduce) return;
    var dur = parseFloat(g('duration', '4')) * 1000 / 3, t = 0;
    if (el.hasAttribute('data-hover-lap')) el.addEventListener('pointerenter', function (e) { if (e.pointerType === 'touch') return; el.classList.add('is-lap'); clearTimeout(t); t = setTimeout(function () { el.classList.remove('is-lap'); }, dur); });
    if (el.hasAttribute('data-click-pulse')) el.addEventListener('click', function () { el.classList.remove('is-pulse'); void el.offsetWidth; el.classList.add('is-pulse'); setTimeout(function () { el.classList.remove('is-pulse'); }, 650); });
  }
  function scan() { [].forEach.call(d.querySelectorAll('[data-star]'), init); }
  scan(); if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', scan); d.addEventListener('mfnc:page', scan);
  window.StarBorder = { scan: scan };
})();
