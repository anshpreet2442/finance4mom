/* Plugin shop helpers: top-up presets and printing a receipt. Re-binds after SPA navigation. */
(function () {
  'use strict';
  function bind() {
    document.querySelectorAll('[data-topup-preset]').forEach(function (b) {
      if (b._shop) return; b._shop = true;
      b.addEventListener('click', function () { var f = b.closest('form'), i = f && f.querySelector('input[name=amount]'); if (i) { i.value = b.getAttribute('data-topup-preset'); i.focus(); } });
    });
    document.querySelectorAll('[data-print]').forEach(function (b) { if (b._shop) return; b._shop = true; b.addEventListener('click', function () { window.print(); }); });
  }
  bind(); document.addEventListener('mfnc:page', bind);
})();
