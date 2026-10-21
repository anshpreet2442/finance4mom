/* Voice: read aloud, voice typing, spoken replies. Uses ElevenLabs through the server when the person has it, otherwise the browser's own
   speechSynthesis / SpeechRecognition. Never shows an error for "not available": the button is simply hidden when neither works. */
(function () {
  'use strict';
  var w = window, d = document, base = '', csrfOf = function () { return d.body ? d.body.getAttribute('data-csrf') || '' : ''; };
  var SR = w.SpeechRecognition || w.webkitSpeechRecognition, hasSynth = 'speechSynthesis' in w && typeof w.SpeechSynthesisUtterance === 'function';
  var status = { tts: hasSynth ? 'browser' : 'none', stt: SR ? 'browser' : 'none', hint: '' }, ready = null, audio = null, current = null, rec = null;
  function ls(k, v) { try { if (v === undefined) return w.localStorage.getItem('mf.voice.' + k); w.localStorage.setItem('mf.voice.' + k, v); } catch (e) { } return null; }
  function url(p) { return (d.body && d.body.getAttribute('data-base') || '') + p; }
  function lang() { var l = d.documentElement.lang; return !l || l === 'en' ? (navigator.language || 'en-IN') : l; }

  function load() {
    if (ready) return ready;
    ready = fetch(url('/voice/status'), { credentials: 'same-origin' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
      if (j && j.ok) { status.tts = j.tts === 'elevenlabs' ? 'elevenlabs' : (hasSynth ? 'browser' : 'none'); status.stt = j.stt === 'elevenlabs' && w.MediaRecorder && navigator.mediaDevices ? 'elevenlabs' : (SR ? 'browser' : 'none'); status.hint = status.tts === 'browser' ? (j.hint || '') : ''; }
      return status;
    }).catch(function () { return status; });
    return ready;
  }
  function post(path, body) { return fetch(url(path), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfOf() }, body: JSON.stringify(body) }).then(function (r) { return r.json(); }); }

  // ---------------------------------------------------------------- speaking
  function stop() {
    if (audio) { try { audio.pause(); } catch (e) { } audio = null; } if (hasSynth) { try { w.speechSynthesis.cancel(); } catch (e) { } }
    if (current) { var c = current; current = null; c(); }
  }
  function speakBrowser(text, done) {
    if (!hasSynth) { done(); return; } var u = new w.SpeechSynthesisUtterance(text); u.lang = lang(); u.rate = 1; u.onend = u.onerror = function () { done(); };
    try { w.speechSynthesis.cancel(); w.speechSynthesis.speak(u); } catch (e) { done(); }
  }
  /** Speaks text. Resolves when finished or stopped; rejects never. onState('loading'|'playing'|'idle'). */
  function speak(text, o) {
    o = o || {}; stop(); text = String(text || '').trim(); if (!text) return Promise.resolve();
    return load().then(function () {
      return new Promise(function (resolve) {
        var finished = false, onState = o.onState || function () { }; function done() { if (finished) return; finished = true; current = null; onState('idle'); resolve(); } current = done; onState('loading');
        function fallback() { if (finished) return; if (!hasSynth) { done(); return; } onState('playing'); speakBrowser(text, done); }
        if (status.tts !== 'elevenlabs') { fallback(); return; }
        post('/voice/tts', { text: text, source: o.source || 'web' }).then(function (j) {
          if (finished) return; if (!j || j.engine !== 'elevenlabs' || !j.url) { fallback(); return; }
          var a = new Audio(j.url); audio = a; a.onended = a.onerror = function () { if (audio === a) { audio = null; } if (a.error) { fallback(); return; } done(); };
          a.play().then(function () { onState('playing'); }).catch(function () { audio = null; fallback(); });
        }).catch(fallback);
      });
    });
  }
  function textOf(node) {
    var c = node.cloneNode(true); c.querySelectorAll('button,nav,script,style,svg,[data-voice-skip],.sr-only').forEach(function (n) { n.remove(); }); return (c.innerText || c.textContent || '').replace(/\s+/g, ' ').trim();
  }
  function mkBtn(cls, label) {
    var b = d.createElement('button'); b.type = 'button'; b.className = cls; b.setAttribute('aria-label', label); b.title = label;
    b.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M11 4.7a.7.7 0 0 0-1.2-.5L6.4 7.6A1.4 1.4 0 0 1 5.4 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.4a1.4 1.4 0 0 1 1 .4l3.4 3.4A.7.7 0 0 0 11 19.3z"/><path d="M16 9a5 5 0 0 1 0 6"/><path d="M19.4 18.4a9 9 0 0 0 0-12.8"/></svg>';
    return b;
  }
  function bind(btn, getText, src) {
    if (btn._voice) return; btn._voice = true; var on = false;
    load().then(function () { if (status.tts === 'none') { return; } btn.hidden = false; btn.removeAttribute('hidden'); if (status.hint) { btn.setAttribute('data-voice-hint', status.hint); btn.title = (btn.getAttribute('aria-label') || '') + '. ' + status.hint; } });
    btn.addEventListener('click', function () {
      if (on) { stop(); return; } var t = getText(); if (!t) return;
      speak(t, { source: src, onState: function (s) { on = s !== 'idle'; btn.setAttribute('aria-pressed', on ? 'true' : 'false'); btn.classList.toggle('is-on', on); btn.setAttribute('data-state', s); } });
    });
  }

  // ---------------------------------------------------------------- listening
  function listenBrowser() {
    return new Promise(function (res, rej) {
      try { rec = new SR(); rec.lang = lang(); rec.interimResults = false; rec.maxAlternatives = 1; rec.continuous = false; var got = ''; rec.onresult = function (e) { got = e.results[0][0].transcript; }; rec.onerror = function () { rej(); }; rec.onend = function () { res(got); }; rec.start(); } catch (e) { rej(e); }
    });
  }
  function listenRecorder() {
    return navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      return new Promise(function (res, rej) {
        var chunks = [], mr, t0 = Date.now(), stopped = false, ac = null, timer = null, spoke = false, quietSince = 0, mime = w.MediaRecorder.isTypeSupported && w.MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : (w.MediaRecorder.isTypeSupported && w.MediaRecorder.isTypeSupported('audio/mp4') ? 'audio/mp4' : '');
        try { mr = mime ? new w.MediaRecorder(stream, { mimeType: mime }) : new w.MediaRecorder(stream); } catch (e) { stream.getTracks().forEach(function (t) { t.stop(); }); rej(e); return; }
        function finish(discard) { if (stopped) return; stopped = true; clearInterval(timer); try { mr.stop(); } catch (e) { } stream.getTracks().forEach(function (t) { t.stop(); }); if (ac) { try { ac.close(); } catch (e) { } } rec = null; if (discard) { chunks = null; } }
        mr.ondataavailable = function (e) { if (e.data && e.data.size && chunks) chunks.push(e.data); };
        mr.onstop = function () {
          if (!chunks || !chunks.length) { res(''); return; } var blob = new Blob(chunks, { type: mr.mimeType || 'audio/webm' }), secs = Math.max(1, Math.round((Date.now() - t0) / 1000));
          fetch(url('/voice/stt'), { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': blob.type || 'audio/webm', 'X-CSRF-Token': csrfOf(), 'X-Audio-Seconds': String(secs) }, body: blob }).then(function (r) { return r.json(); }).then(function (j) { res(j && j.engine === 'elevenlabs' ? j.text || '' : ''); }).catch(function () { res(''); });
        };
        rec = { abort: function () { finish(true); res(''); } };
        try { ac = new (w.AudioContext || w.webkitAudioContext)(); var an = ac.createAnalyser(); an.fftSize = 512; ac.createMediaStreamSource(stream).connect(an); var buf = new Uint8Array(an.fftSize);
          timer = setInterval(function () { an.getByteTimeDomainData(buf); var s = 0; for (var i = 0; i < buf.length; i++) { var v = (buf[i] - 128) / 128; s += v * v; } var rms = Math.sqrt(s / buf.length); var now = Date.now();
            if (rms > 0.03) { spoke = true; quietSince = 0; } else if (spoke) { if (!quietSince) quietSince = now; else if (now - quietSince > 1400) finish(false); } if (now - t0 > 30000) finish(false); }, 120); } catch (e) { setTimeout(function () { finish(false); }, 8000); }
        mr.start();
      });
    });
  }
  function canListen() { return status.stt === 'elevenlabs' || (status.stt === 'browser' && !!SR); }
  function listen() { return load().then(function () { if (status.stt === 'elevenlabs') { return listenRecorder().catch(function () { return SR ? listenBrowser() : ''; }); } if (SR) return listenBrowser(); return ''; }); }
  function cancel() { try { rec && rec.abort && rec.abort(); } catch (e) { } }

  // ---------------------------------------------------------------- page wiring
  function scan(root) {
    (root || d).querySelectorAll('[data-voice-read]').forEach(function (b) { bind(b, function () { var n = d.querySelector(b.getAttribute('data-voice-read')); return n ? textOf(n) : ''; }, 'report'); });
    (root || d).querySelectorAll('.ai-msg--assistant').forEach(function (m) {
      if (m._voice) return; m._voice = true; var body = m.querySelector('.ai-msg__b') || m; var b = mkBtn('ai-voice-btn', 'Read this answer aloud'); b.hidden = true; body.appendChild(b); bind(b, function () { return textOf(body); }, 'chat');
    });
    var log = (root || d).querySelector && (root || d).querySelector('[data-ai-log]'); if (log && !log._voiceAuto) { log._voiceAuto = true; autoReplies(log); }
    (root || d).querySelectorAll('[data-voice-test]').forEach(function (b) { if (b._vt) return; b._vt = true; b.addEventListener('click', function () { var out = d.querySelector('[data-voice-test-out]'); if (out) out.textContent = 'Playing…'; speak('Hello. This is a test of your voice settings.', { source: 'test', onState: function (s) { if (out && s === 'idle') out.textContent = status.tts === 'elevenlabs' ? 'Played with ElevenLabs.' : 'Played with the device voice (ElevenLabs is not active for you).'; } }); }); });
  }
  function autoReplies(log) {
    var wrap = d.createElement('label'); wrap.className = 'voice-auto'; wrap.hidden = true; var cb = d.createElement('input'); cb.type = 'checkbox'; cb.checked = ls('auto') === '1'; wrap.appendChild(cb); wrap.appendChild(d.createTextNode(' Speak replies')); var hint = d.createElement('span'); hint.className = 'voice-hint'; wrap.appendChild(hint);
    log.parentNode.insertBefore(wrap, log); load().then(function () { if (status.tts !== 'none') { wrap.hidden = false; hint.textContent = status.hint ? ' · ' + status.hint : ''; } });
    cb.addEventListener('change', function () { ls('auto', cb.checked ? '1' : '0'); if (!cb.checked) stop(); });
    var timer = null, spokenN = log.querySelectorAll('.ai-msg--assistant').length;
    new MutationObserver(function () { clearTimeout(timer); timer = setTimeout(function () {
      var all = log.querySelectorAll('.ai-msg--assistant'); if (!cb.checked || all.length <= spokenN) { spokenN = all.length; return; } var last = all[all.length - 1]; spokenN = all.length; var body = last.querySelector('.ai-msg__b') || last; var t = textOf(body); if (t) speak(t, { source: 'chat' });
    }, 1600); }).observe(log, { childList: true, subtree: true, characterData: true });
  }
  w.MfVoice = { speak: speak, stop: stop, listen: listen, cancel: cancel, canListen: canListen, canSpeak: function () { return status.tts !== 'none'; }, status: function () { return status; }, load: load, scan: scan };
  var obs = null; function start() { scan(d); if (!obs && d.body) { obs = new MutationObserver(function (ml) { for (var i = 0; i < ml.length; i++) { if (ml[i].addedNodes.length) { scan(d); break; } } }); obs.observe(d.body, { childList: true, subtree: true }); } }
  if (d.readyState !== 'loading') start(); else d.addEventListener('DOMContentLoaded', start);
})();
