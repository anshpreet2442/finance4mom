/* Share links: copy, native share sheet. Plain JS, no dependencies. */
(function () {
  function init(root) {
    (root || document).querySelectorAll('[data-share-sheet]').forEach(function (box) {
      if (box.__shareReady) { return; } box.__shareReady = true;
      var url = box.getAttribute('data-url') || '', title = box.getAttribute('data-title') || '';
      var native = box.querySelector('[data-share-native]'), copy = box.querySelector('[data-share-copy]');
      if (native && navigator.share) { native.classList.remove('hidden'); native.addEventListener('click', function () { navigator.share({ title: title, url: url }).catch(function () {}); }); }
      if (copy) { copy.addEventListener('click', function () {
        var done = function () { var t = copy.innerHTML; copy.textContent = 'Copied'; setTimeout(function () { copy.innerHTML = t; }, 1500); };
        if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(url).then(done, fallback); } else { fallback(); }
        function fallback() { var i = document.createElement('input'); i.value = url; document.body.appendChild(i); i.select(); try { document.execCommand('copy'); done(); } catch (e) {} i.remove(); }
      }); }
    });
  }
  window.mfShareInit = init; if (document.readyState !== 'loading') { init(); } else { document.addEventListener('DOMContentLoaded', function () { init(); }); }
})();
