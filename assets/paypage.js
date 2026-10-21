/* Admin payment page editor: live preview in a sandboxed iframe, insert {parameters}. */
(function () {
  var ta = document.querySelector('[data-pp-editor]'), fr = document.querySelector('[data-pp-frame]'); if (!ta || !fr) { return; }
  var warn = document.querySelector('[data-pp-warn]'), cnt = document.querySelector('[data-pp-count]'), timer = null, csrf = document.body.getAttribute('data-csrf') || '';
  function refresh() {
    if (cnt) { cnt.textContent = ta.value.length; }
    fetch(window.PAYPAGE_PREVIEW, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }, body: JSON.stringify({ html: ta.value }) })
      .then(function (r) { return r.json(); }).then(function (j) { if (j && j.ok) { fr.srcdoc = j.html; if (warn) { warn.textContent = j.unknown && j.unknown.length ? 'Unknown parameters: {' + j.unknown.join('}, {') + '}' : ''; } } }).catch(function () {});
  }
  ta.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(refresh, 350); });
  document.querySelectorAll('[data-pp-insert]').forEach(function (b) { b.addEventListener('click', function () {
    var t = b.getAttribute('data-pp-insert'), s = ta.selectionStart || 0, e = ta.selectionEnd || 0; ta.value = ta.value.slice(0, s) + t + ta.value.slice(e); ta.focus(); ta.selectionStart = ta.selectionEnd = s + t.length; refresh(); }); });
})();
