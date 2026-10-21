/* Voice assistant, available on every page: a vanilla port of the KokonutUI "AI Voice" control (idle mic -> recording with a live scrolling waveform -> review with a
   playhead -> Send / Discard) wired to the real microphone and to the advisor.
   Flow: the person speaks -> transcript (ElevenLabs speech-to-text through /voice/stt when their plan and the key allow it, otherwise the browser's Web Speech API,
   otherwise typing) -> POST /ai/voice (the same engine as /ai/chat: gate, budget, wallet, tools, previews, metering) streams the answer as text deltas -> a sentence
   chunker hands every finished sentence to the speaker (ElevenLabs per sentence with the next ones fetched while the current one plays, or speechSynthesis) so the first
   words are heard before the answer is complete. Pressing the mic, Escape, closing the panel or a new recording stops the speech at once (barge-in) and aborts the turn.
   Rich results (cards, previews with Apply / Undo) appear in the panel; nothing is applied without a tap. Raw audio is never stored: it stays in memory in this tab,
   goes to the speech-to-text provider only when ElevenLabs is the engine, and only the transcript is saved (with a voice flag) in the chat history.
   Pure helpers (sentence chunker, speakable text, level smoothing, condense, route decision) are exported as MfVoiceDock.pure for the node tests. */
(function (root, factory) {
  var api = factory(root);
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else { root.MfVoiceDock = api; if (root.document) api.boot(); }
})(typeof window !== 'undefined' ? window : globalThis, function (w) {
  'use strict';
  var BAR_COUNT = 48, BAR_PITCH = 5, SAMPLE_MS = 70, REST = 0.12;
  var STILL = []; for (var si = 0; si <= BAR_COUNT; si++) STILL.push(0.25 + 0.5 * Math.abs(Math.sin(si * 0.55) * Math.cos(si * 0.21)));
  var EASE_OUT = 'cubic-bezier(0.23, 1, 0.32, 1)';

  // ================================================================ pure helpers
  /** Plain speakable text: markdown, links, emoji and list markers removed, rupees spoken as a word. */
  function speakable(t) {
    return String(t == null ? '' : t)
      .replace(/```[\s\S]*?```/g, ' ').replace(/`([^`]*)`/g, '$1').replace(/!\[[^\]]*\]\([^)]*\)/g, ' ').replace(/\[([^\]]+)\]\(https?:\/\/[^)]*\)/g, '$1').replace(/https?:\/\/\S+/g, ' link ')
      .replace(/^\s{0,3}#{1,6}\s+/gm, '').replace(/^\s*(?:[-*•]|\d{1,2}[.)])\s+/gm, '').replace(/(\*\*|__)(.*?)\1/g, '$2').replace(/(^|[\s(])[*_]([^*_\n]+)[*_](?=[\s).,;:!?]|$)/g, '$1$2')
      .replace(/\|/g, ', ').replace(/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}\u{FE0F}]/gu, '').replace(/₹\s?(\d[\d,]*(?:\.\d+)?)/g, '$1 rupees').replace(/\s+/g, ' ').trim();
  }
  var ABBR = /^(?:mr|mrs|ms|dr|prof|sr|jr|st|vs|etc|e\.g|i\.e|no|rs|inr|approx|fig|inc|ltd|co|mt|a\.m|p\.m)\.$/i;
  /** First confirmed sentence end in s: the index just after it, or -1. A sentence end needs punctuation followed by a space (so "3.5" and "Rs.1,000" never split), or a line break. */
  function boundary(s) {
    var re = /[.!?…]+["')\]”’]*\s|\n+/g, m;
    while ((m = re.exec(s))) {
      var end = m.index + m[0].length;
      if (m[0].charAt(0) === '\n') { if (s.slice(0, m.index).trim() === '') continue; return end; }
      var head = s.slice(0, m.index + 1), tok = (head.match(/(\S+)$/) || ['', ''])[1].replace(/^["'(\[]+/, ''), lineStart = head.lastIndexOf('\n') + 1, atLine = head.slice(lineStart).trim() === tok;
      if (ABBR.test(tok)) continue; if (/^[A-Z]\.$/.test(tok)) continue; if (atLine && /^\d{1,2}\.$/.test(tok)) continue;
      return end;
    }
    return -1;
  }
  function lastClause(s, max) {
    var cut = -1, re = /[,;:—–]\s/g, m; while ((m = re.exec(s)) && m.index < max) cut = m.index + m[0].length;
    if (cut > 40) return cut; var sp = s.lastIndexOf(' ', max); return sp > 40 ? sp + 1 : -1;
  }
  /** Cuts a growing text into speakable chunks. The first sentence is released at once (even a short "Sure."), later ones are joined until they are long enough to sound natural.
      feed(text) -> [chunk, ...] (finished chunks); flush() -> [last chunk]. */
  function createChunker(o) {
    o = o || {}; var firstMin = o.firstMin || 4, minLen = o.min || 28, maxLen = o.max || 240, buf = '', first = true, hold = '';
    function take(sentence, out) {
      var s = speakable(sentence); if (!s) return;
      if (first) { if (s.length >= firstMin) { out.push(s); first = false; } else hold = (hold ? hold + ' ' : '') + s; if (hold.length >= firstMin) { out.push(hold); hold = ''; first = false; } return; }
      hold = (hold ? hold + ' ' : '') + s; if (hold.length >= minLen) { out.push(hold); hold = ''; }
    }
    return {
      feed: function (t) {
        buf += String(t || ''); var out = [], i;
        for (;;) {
          i = boundary(buf);
          if (i < 0) { if (buf.length > maxLen) { var c = lastClause(buf, maxLen); if (c > 0) { take(buf.slice(0, c), out); buf = buf.slice(c); continue; } } break; }
          take(buf.slice(0, i), out); buf = buf.slice(i);
        }
        return out;
      },
      flush: function () { var out = []; var s = speakable(buf); buf = ''; if (s) hold = (hold ? hold + ' ' : '') + s; if (hold) { out.push(hold); hold = ''; first = false; } return out; },
      pending: function () { return buf.length + hold.length; }
    };
  }
  /** Input level 0..1 from a time-domain RMS: perceptual curve, quick attack, slower release. */
  function levelFromRms(rms, prev) { var x = Math.min(1, Math.pow(Math.max(0, rms) * 5.5, 0.75)), k = x > prev ? 0.55 : 0.2; return prev + (x - prev) * k; }
  function condense(take) {
    if (!take.length) return STILL.slice(0, BAR_COUNT);
    var out = []; for (var i = 0; i < BAR_COUNT; i++) { var from = Math.floor(i / BAR_COUNT * take.length), to = Math.max(from + 1, Math.floor((i + 1) / BAR_COUNT * take.length)), m = REST; for (var j = from; j < to && j < take.length; j++) if (take[j] > m) m = take[j]; out.push(m); }
    return out;
  }
  function formatTime(sec) { var s = Math.floor(sec), m = Math.floor(s / 60), r = s % 60; return (m < 10 ? '0' : '') + m + ':' + (r < 10 ? '0' : '') + r; }
  /** Speech-like level without a microphone (the original's simulator), used only when the analyser is not available. */
  function createSimulator() {
    var speaking = false, switchAt = 0, gate = 0;
    return function (time) {
      if (time >= switchAt) { speaking = !speaking; switchAt = time + (speaking ? 700 + Math.random() * 1600 : 180 + Math.random() * 500); }
      gate += ((speaking ? 1 : 0) - gate) * 0.35; var t = time / 1000, syl = Math.abs(Math.sin(t * Math.PI * 4.4 + Math.sin(t * 1.7) * 2)), grain = 0.65 + Math.random() * 0.35;
      return REST + gate * syl * grain * (1 - REST);
    };
  }
  /** Which engines this device + this person get. status = what /ai/voice/state says, caps = what the browser can do. Never an error: it degrades to typing. */
  function pickRoute(status, caps) {
    status = status || {}; caps = caps || {};
    var micOk = !!(caps.secure && caps.getUserMedia);
    var stt = (status.stt === 'elevenlabs' && micOk && caps.mediaRecorder) ? 'elevenlabs' : ((caps.speechRecognition && caps.secure) ? 'browser' : 'typed');
    var tts = status.tts === 'elevenlabs' ? 'elevenlabs' : (caps.speechSynthesis ? 'browser' : 'none');
    var why = ''; if (!caps.secure) why = 'insecure'; else if (!caps.getUserMedia && stt !== 'browser') why = 'nomic'; else if (stt === 'typed') why = 'nostt';
    return { stt: stt, tts: tts, mic: micOk || (stt === 'browser'), why: why };
  }
  /** Silence detector for hands-free mode: true once the person has spoken and then been quiet for holdMs. */
  function createVad(o) {
    o = o || {}; var thr = o.threshold || 0.16, holdMs = o.holdMs || 1400, minSpeechMs = o.minSpeechMs || 250, spokeAt = 0, spoke = false, quietSince = 0;
    return function (level, now) {
      if (level > thr) { if (!spokeAt) spokeAt = now; if (now - spokeAt >= minSpeechMs) spoke = true; quietSince = 0; return false; }
      if (!spoke) { spokeAt = 0; return false; } if (!quietSince) quietSince = now; return now - quietSince >= holdMs;
    };
  }
  var pure = { speakable: speakable, createChunker: createChunker, boundary: boundary, levelFromRms: levelFromRms, condense: condense, formatTime: formatTime, pickRoute: pickRoute, createVad: createVad, createSimulator: createSimulator, BAR_COUNT: BAR_COUNT, REST: REST };
  if (!w.document) return { pure: pure, boot: function () {} };

  // ================================================================ DOM
  var d = w.document, M = w.MfMotion, body = d.body;
  var SR = w.SpeechRecognition || w.webkitSpeechRecognition, synth = 'speechSynthesis' in w && typeof w.SpeechSynthesisUtterance === 'function';
  var coarse = !!(w.matchMedia && w.matchMedia('(pointer: coarse)').matches);
  function reduced() { return !!(w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches); }
  function ls(k, v) { try { if (v === undefined) return w.localStorage.getItem('mf.vd.' + k); w.localStorage.setItem('mf.vd.' + k, v); } catch (e) { } return null; }
  function el(tag, cls, text) { var e = d.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  var NS = 'http://www.w3.org/2000/svg';
  var ICONS = {
    mic: ['M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z', 'M19 10v2a7 7 0 0 1-14 0v-2', 'M12 19v3'], play: ['M5 5a2 2 0 0 1 3-1.7l12 7a2 2 0 0 1 0 3.4l-12 7A2 2 0 0 1 5 19Z'], pause: ['M14 4h4v16h-4z', 'M6 4h4v16H6z'], x: ['M18 6 6 18', 'm6 6 12 12'], up: ['m5 12 7-7 7 7', 'M12 19V5'],
    speaker: ['M11 5 6 9H3v6h3l5 4z', 'M15.5 8.5a5 5 0 0 1 0 7', 'M18.5 5.5a9 9 0 0 1 0 13'], mute: ['M11 5 6 9H3v6h3l5 4z', 'm22 9-6 6', 'm16 9 6 6'], keyboard: ['M2 6h20v12H2z', 'M6 10h.01', 'M10 10h.01', 'M14 10h.01', 'M18 10h.01', 'M7 14h10'], chat: ['M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z'],
    pin: ['M12 17v5', 'M9 10.8a2 2 0 0 1-1.1 1.8l-1.8.9A2 2 0 0 0 5 15.2V16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-.8a2 2 0 0 0-1.1-1.8l-1.8-.9a2 2 0 0 1-1.1-1.8V7a1 1 0 0 1 1-1 2 2 0 0 0 0-4H8a2 2 0 0 0 0 4 1 1 0 0 1 1 1z'], stop: ['M6 6h12v12H6z'], history: ['M3 12a9 9 0 1 0 3-6.7L3 8', 'M3 3v5h5', 'M12 7v5l4 2']
  };
  function icon(name, size, fill) {
    var s = d.createElementNS(NS, 'svg'); s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('width', size || 16); s.setAttribute('height', size || 16); s.setAttribute('fill', fill ? 'currentColor' : 'none'); s.setAttribute('stroke', 'currentColor'); s.setAttribute('stroke-width', '2'); s.setAttribute('stroke-linecap', 'round'); s.setAttribute('stroke-linejoin', 'round'); s.setAttribute('aria-hidden', 'true');
    (ICONS[name] || []).forEach(function (p) { var e = d.createElementNS(NS, 'path'); e.setAttribute('d', p); s.appendChild(e); }); return s;
  }
  function csrf() { return body.getAttribute('data-csrf') || ''; }
  function base() { return body.getAttribute('data-base') || ''; }
  function now() { return w.performance && performance.now ? performance.now() : Date.now(); }
  var SILENT = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQAAAAA=';

  // ---------------------------------------------------------------- the AI Voice control (port)
  function VoiceControl(host, cb) {
    var s = this; s.cb = cb; s.mode = 'idle'; s.seconds = 0; s.duration = 0; s.playing = false; s.playhead = 0; s.take = []; s.startedAt = 0; s.level = null; s.audio = null; s.simulate = null; s.dead = false;
    var root = s.root = el('div', 'vd-voice'), stage = el('div', 'vd-voice__stage'), ringWrap = el('div', 'vd-voice__btnwrap');
    s.ring = el('span', 'vd-ring'); s.ring.setAttribute('aria-hidden', 'true');
    s.main = el('button', 'vd-main'); s.main.type = 'button'; s.main.setAttribute('aria-pressed', 'false');
    s.faces = { mic: icon('mic', 24), stop: el('span', 'vd-stopface'), play: icon('play', 24, true), pause: icon('pause', 24, true) };
    Object.keys(s.faces).forEach(function (k) { s.faces[k].classList.add('vd-face'); if (k === 'mic') s.faces[k].classList.add('is-on'); s.faces[k].setAttribute('aria-hidden', 'true'); s.main.appendChild(s.faces[k]); });
    ringWrap.appendChild(s.ring); ringWrap.appendChild(s.main); stage.appendChild(ringWrap);
    s.timer = el('span', 'vd-timer'); s.rec = el('span', 'vd-rec'); s.rec.setAttribute('aria-hidden', 'true'); s.timeTxt = d.createTextNode('00:00'); s.timer.appendChild(s.rec); s.timer.appendChild(s.timeTxt); stage.appendChild(s.timer);
    s.wave = el('div', 'vd-wave'); s.wave.setAttribute('aria-hidden', 'true'); s.row = el('div', 'vd-row'); s.played = el('div', 'vd-played'); s.bars = []; s.pbars = [];
    for (var i = 0; i <= BAR_COUNT; i++) { var b = el('span', 'vd-bar'); b.style.transform = 'scaleY(' + REST + ')'; b.style.transitionDelay = ((BAR_COUNT - i) * 6) + 'ms'; s.row.appendChild(b); s.bars.push(b); var pb = el('span', 'vd-pbar'); pb.style.transform = 'scaleY(' + REST + ')'; s.played.appendChild(pb); s.pbars.push(pb); }
    s.played.style.clipPath = 'inset(0 100% 0 0)'; s.wave.appendChild(s.row); s.wave.appendChild(s.played); stage.appendChild(s.wave);
    s.under = el('div', 'vd-under'); s.caption = el('span', 'vd-caption is-on', coarse ? 'Tap to speak' : 'Click to speak'); s.actions = el('div', 'vd-actions');
    s.discard = el('button', 'vd-act'); s.discard.type = 'button'; s.discard.appendChild(icon('x', 14)); s.discard.appendChild(d.createTextNode('Discard')); s.send = el('button', 'vd-act vd-act--send'); s.send.type = 'button'; s.send.appendChild(d.createTextNode('Send')); s.send.appendChild(icon('up', 14));
    s.actions.appendChild(s.discard); s.actions.appendChild(s.send); s.under.appendChild(s.caption); s.under.appendChild(s.actions); stage.appendChild(s.under);
    s.live = el('output', 'sr-only'); s.live.setAttribute('aria-live', 'polite'); stage.appendChild(s.live); root.appendChild(stage); host.appendChild(root);
    s.main.addEventListener('click', function () { s.mainClick(); }); s.discard.addEventListener('click', function () { s.finish(false); }); s.send.addEventListener('click', function () { s.finish(true); });
    s.render();
  }
  VoiceControl.prototype.scales = function (bars, levels) { for (var i = 0; i < bars.length; i++) bars[i].style.transform = 'scaleY(' + (levels[i] == null ? REST : levels[i]).toFixed(3) + ')'; };
  VoiceControl.prototype.mainLabel = function () { return this.mode === 'recording' ? 'Stop recording' : (this.mode === 'review' ? (this.playing ? 'Pause playback' : 'Play recording') : 'Start recording'); };
  VoiceControl.prototype.render = function () {
    var s = this, rec = s.mode === 'recording', rev = s.mode === 'review';
    s.root.setAttribute('data-mode', s.mode); s.main.setAttribute('aria-label', s.mainLabel()); s.main.setAttribute('aria-pressed', rec ? 'true' : 'false');
    var vis = { mic: s.mode === 'idle', stop: rec, play: rev && !s.playing, pause: rev && s.playing }; Object.keys(vis).forEach(function (k) { s.faces[k].classList.toggle('is-on', vis[k]); });
    s.caption.classList.toggle('is-on', !rev); s.actions.classList.toggle('is-on', rev); s.actions.hidden = false; [].forEach.call(s.actions.querySelectorAll('button'), function (b) { b.tabIndex = rev ? 0 : -1; });
    s.caption.textContent = rec ? 'Listening...' : (s.busyText || (coarse ? 'Tap to speak' : 'Click to speak')); s.timer.classList.toggle('is-on', s.mode !== 'idle'); s.rec.classList.toggle('is-on', rec);
    s.wave.classList.toggle('is-rec', rec); s.row.classList.toggle('is-rec', rec); s.played.classList.toggle('is-on', rev);
    s.live.textContent = rec ? 'Recording' : (rev ? 'Recording ready to send' : '');
    s.timeTxt.nodeValue = formatTime(rev ? (s.playing || s.playhead > 0 ? s.playhead : s.duration) : (rec ? s.seconds : 0));
  };
  VoiceControl.prototype.setBusy = function (text) { this.busyText = text || ''; if (this.mode === 'idle') this.caption.textContent = this.busyText || (coarse ? 'Tap to speak' : 'Click to speak'); };
  VoiceControl.prototype.start = function (levelFn) {
    var s = this; if (s.mode === 'recording') return; s.stopPlay(); s.mode = 'recording'; s.level = levelFn || null; s.take = []; s.seconds = 0; s.startedAt = Date.now(); s.render(); s.row.style.transform = 'translateX(0px)';
    if (reduced()) { s.scales(s.bars, STILL); s.take = STILL.slice(); } else s.loop();
    s.tick = setInterval(function () { s.seconds = Math.floor((Date.now() - s.startedAt) / 1000); s.timeTxt.nodeValue = formatTime(s.seconds); }, 250);
  };
  VoiceControl.prototype.loop = function () {
    var s = this, samples = []; for (var i = 0; i <= BAR_COUNT; i++) samples.push(REST); var phase = 0, smooth = 0, last = now(), frame = 0, sim = s.level ? null : createSimulator();
    function tick(t) {
      if (s.mode !== 'recording' || s.dead) return; var dt = Math.min(t - last, 100); last = t; phase += dt / SAMPLE_MS;
      while (phase >= 1) { phase -= 1; var live = s.level ? s.level() : null, sample = live == null ? sim(t) : Math.max(REST, Math.min(1, live)); samples.shift(); samples.push(sample); s.take.push(sample); }
      s.row.style.transform = 'translateX(' + (-phase * BAR_PITCH).toFixed(2) + 'px)'; s.scales(s.bars, samples);
      var latest = samples[samples.length - 1] || REST; smooth += (latest - smooth) * 0.18; var lift = Math.max(0, smooth - REST); s.ring.style.transform = 'scale(' + (1 + lift * 0.22).toFixed(3) + ')'; s.ring.style.opacity = (0.4 + lift * 0.8).toFixed(3);
      frame = requestAnimationFrame(tick); s.frame = frame;
    }
    s.frame = frame = requestAnimationFrame(function (t) { last = t; tick(t); });
    if ('IntersectionObserver' in w) { s.io = new IntersectionObserver(function (es) { var on = es[0].isIntersecting; if (!on) cancelAnimationFrame(s.frame); else if (s.mode === 'recording') { cancelAnimationFrame(s.frame); last = now(); s.frame = requestAnimationFrame(tick); } }); s.io.observe(s.root); }
  };
  VoiceControl.prototype.halt = function () { cancelAnimationFrame(this.frame); clearInterval(this.tick); if (this.io) { this.io.disconnect(); this.io = null; } this.ring.style.transform = 'scale(1)'; this.ring.style.opacity = '0'; };
  /** Stops recording and shows the take. blob = the audio kept in memory for playback (optional). */
  VoiceControl.prototype.stop = function (blob) {
    var s = this; if (s.mode !== 'recording') return 0; s.halt();
    var taken = Math.max((Date.now() - s.startedAt) / 1000, s.take.length * (SAMPLE_MS / 1000)); s.duration = taken; s.playhead = 0; s.played.style.clipPath = 'inset(0 100% 0 0)'; s.mode = 'review';
    s.row.style.transform = 'translateX(0px)'; var lv = condense(s.take); s.scales(s.bars, lv); s.scales(s.pbars, lv);
    if (s.audio) { try { s.audio.pause(); } catch (e) { } s.audio = null; } if (s.url) { URL.revokeObjectURL(s.url); s.url = null; }
    s.setBlob(blob); s.render(); s.cb.onStop && s.cb.onStop(taken); return taken;
  };
  VoiceControl.prototype.setBlob = function (blob) { var s = this; if (!blob || s.mode !== 'review' || !(w.URL && URL.createObjectURL)) return; if (s.url) URL.revokeObjectURL(s.url); s.url = URL.createObjectURL(blob); s.audio = new Audio(s.url); s.audio.preload = 'auto'; };
  VoiceControl.prototype.stopPlay = function () { var s = this; s.playing = false; cancelAnimationFrame(s.pframe); if (s.audio) { try { s.audio.pause(); } catch (e) { } } };
  VoiceControl.prototype.togglePlay = function () {
    var s = this; if (s.playing) { s.stopPlay(); s.render(); return; }
    s.playing = true; s.played.style.clipPath = 'inset(0 100% 0 0)'; s.playhead = 0; var len = Math.max(s.duration, 1) * 1000, t0 = now(), a = s.audio;
    if (a) { try { a.currentTime = 0; var pr = a.play(); if (pr && pr.catch) pr.catch(function () { }); } catch (e) { } }
    function step(t) {
      if (!s.playing) return; var p = a && a.duration && isFinite(a.duration) && a.currentTime > 0 ? a.currentTime / a.duration : Math.min((t - t0) / len, 1);
      if (a && a.ended) p = 1; p = Math.min(1, p); s.played.style.clipPath = 'inset(0 ' + ((1 - p) * 100).toFixed(2) + '% 0 0)'; s.playhead = p * Math.max(s.duration, 1); s.timeTxt.nodeValue = formatTime(s.playhead);
      if (p < 1) s.pframe = requestAnimationFrame(step); else { s.playing = false; s.stopPlay(); s.render(); }
    }
    s.render(); s.pframe = requestAnimationFrame(step);
  };
  VoiceControl.prototype.finish = function (sent) {
    var s = this; s.stopPlay(); var dur = s.duration; s.mode = 'idle'; s.playhead = 0; s.scales(s.bars, STILL.map(function () { return REST; })); s.scales(s.pbars, STILL.map(function () { return REST; })); s.played.style.clipPath = 'inset(0 100% 0 0)'; s.render();
    var url = s.url; s.url = null; if (s.audio) { s.audio = null; } if (url) setTimeout(function () { URL.revokeObjectURL(url); }, 400);
    if (sent) s.cb.onSend && s.cb.onSend(dur); else s.cb.onDiscard && s.cb.onDiscard();
  };
  VoiceControl.prototype.mainClick = function () {
    var s = this; if (s.mode === 'recording') s.cb.onStopRequest && s.cb.onStopRequest(); else if (s.mode === 'review') s.togglePlay(); else s.cb.onStartRequest && s.cb.onStartRequest();
  };
  VoiceControl.prototype.reset = function () { var s = this; s.halt(); s.stopPlay(); s.mode = 'idle'; s.level = null; s.scales(s.bars, STILL.map(function () { return REST; })); s.scales(s.pbars, STILL.map(function () { return REST; })); s.row.style.transform = 'translateX(0px)'; s.played.style.clipPath = 'inset(0 100% 0 0)'; if (s.url) { URL.revokeObjectURL(s.url); s.url = null; } s.audio = null; s.render(); };

  // ---------------------------------------------------------------- microphone capture (real level, optional recorder, optional Web Speech)
  function micErrorText(err) {
    var n = err && err.name || '';
    if (!w.isSecureContext) return 'The microphone only works on secure (HTTPS) pages. Ask whoever runs this site to switch HTTPS on, or type your question instead.';
    if (n === 'NotAllowedError' || n === 'SecurityError') return 'Microphone access is blocked. Allow it for this site in your browser settings, then try again, or type your question instead.';
    if (n === 'NotFoundError' || n === 'OverconstrainedError') return 'No microphone was found on this device. You can type your question instead.';
    if (n === 'NotReadableError') return 'The microphone is busy in another app. Close that app and try again, or type instead.';
    return 'The microphone could not be started. You can type your question instead.';
  }
  function bestMime() { if (!w.MediaRecorder || !MediaRecorder.isTypeSupported) return ''; var c = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg;codecs=opus']; for (var i = 0; i < c.length; i++) if (MediaRecorder.isTypeSupported(c[i])) return c[i]; return ''; }
  function openMic(route, onInterim) {
    var gum = w.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia;
    var ask = gum ? navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true, autoGainControl: true } }) : Promise.reject({ name: w.isSecureContext ? 'NotFoundError' : 'SecurityError' });
    return ask.catch(function (err) {   // the recogniser can listen on its own when the stream is busy or missing (not when the person said no)
      var n = err && err.name; if (route.stt === 'browser' && SR && w.isSecureContext && n !== 'NotAllowedError' && n !== 'SecurityError') return null; throw err;
    }).then(function (stream) {
      var cap = { stream: stream, chunks: [], ac: null, an: null, lvl: 0, rec: null, sr: null, text: '', interim: '', recording: true, mime: '', srEnded: null };
      if (stream) {
        try { var AC = w.AudioContext || w.webkitAudioContext; cap.ac = new AC(); if (cap.ac.state === 'suspended') cap.ac.resume(); cap.an = cap.ac.createAnalyser(); cap.an.fftSize = 1024; cap.an.smoothingTimeConstant = 0.3; cap.ac.createMediaStreamSource(stream).connect(cap.an); cap.buf = new Float32Array(cap.an.fftSize); } catch (e) { cap.an = null; }
        if (w.MediaRecorder) { try { cap.mime = bestMime(); cap.rec = cap.mime ? new MediaRecorder(stream, { mimeType: cap.mime, audioBitsPerSecond: 24000 }) : new MediaRecorder(stream); cap.rec.ondataavailable = function (e) { if (e.data && e.data.size) cap.chunks.push(e.data); }; cap.rec.start(500); } catch (e) { cap.rec = null; } }
      }
      cap.level = function () { if (!cap.an) return null; cap.an.getFloatTimeDomainData(cap.buf); var sum = 0; for (var i = 0; i < cap.buf.length; i++) sum += cap.buf[i] * cap.buf[i]; cap.lvl = levelFromRms(Math.sqrt(sum / cap.buf.length), cap.lvl); return cap.lvl; };
      if (route.stt === 'browser' && SR) {
        try {
          var rec = new SR(); cap.sr = rec; rec.lang = d.documentElement.lang && d.documentElement.lang !== 'en' ? d.documentElement.lang : (navigator.language || 'en-IN'); rec.continuous = true; rec.interimResults = true; rec.maxAlternatives = 1;
          rec.onresult = function (e) { var fin = '', itm = ''; for (var i = 0; i < e.results.length; i++) { var r = e.results[i]; if (r.isFinal) fin += r[0].transcript + ' '; else itm += r[0].transcript; } cap.text = fin.trim(); cap.interim = itm.trim(); onInterim && onInterim((cap.text + ' ' + cap.interim).trim()); };
          rec.onerror = function (e) { cap.srError = e && e.error; };
          rec.onend = function () { if (cap.recording && !cap.srStopped) { try { rec.start(); } catch (x) { } } else if (cap.srEnded) cap.srEnded(); };
          rec.start();
        } catch (e) { cap.sr = null; }
      }
      function closeAll() { try { stream && stream.getTracks().forEach(function (t) { t.stop(); }); } catch (e) { } if (cap.ac) { try { cap.ac.close(); } catch (e) { } } }
      cap.stop = function () {
        cap.recording = false; cap.srStopped = true;
        return new Promise(function (res) {
          var pending = 0, finished = false, doneOne = function () { if (--pending <= 0) finish(); };
          function finish() { if (finished) return; finished = true; closeAll(); var blob = cap.chunks.length ? new Blob(cap.chunks, { type: cap.mime || (cap.rec && cap.rec.mimeType) || 'audio/webm' }) : null; res({ blob: blob, text: (cap.text + ' ' + cap.interim).trim(), srError: cap.srError }); }
          if (cap.rec && cap.rec.state !== 'inactive') { pending++; cap.rec.onstop = doneOne; try { cap.rec.stop(); } catch (e) { pending--; } }
          if (cap.sr) { pending++; var t = setTimeout(doneOne, 900); cap.srEnded = function () { clearTimeout(t); doneOne(); }; try { cap.sr.stop(); } catch (e) { clearTimeout(t); pending--; } }
          if (pending <= 0) finish();
        });
      };
      cap.abort = function () { cap.recording = false; cap.srStopped = true; try { cap.sr && cap.sr.abort(); } catch (e) { } try { cap.rec && cap.rec.state !== 'inactive' && cap.rec.stop(); } catch (e) { } closeAll(); cap.chunks = []; };
      return cap;
    });
  }

  // ---------------------------------------------------------------- the speaker: sentence by sentence, prefetching the next while one plays
  var POOL = null; // two audio elements shared by every speaker: unlocked once by a tap, reused for every sentence
  function audioPool() { if (!POOL) { POOL = [new Audio(), new Audio()]; POOL.forEach(function (a) { a.preload = 'auto'; }); } return POOL; }
  function Speaker(o) {
    var s = this; s.o = o; s.engine = o.engine; s.q = []; s.pool = audioPool(); s.cur = 0; s.playing = null; s.stopped = false; s.ended = false; s.firstDone = false; s.ss = 0; s.ctrls = []; s.spoken = 0; s.idleCb = null;
  }
  Speaker.prototype.unlock = function () { // inside a click: lets later, asynchronous play() calls through on iOS / Safari
    this.pool.forEach(function (a) { try { a.muted = true; a.src = SILENT; var p = a.play(); if (p && p.then) p.then(function () { a.pause(); a.muted = false; }).catch(function () { a.muted = false; }); } catch (e) { } });
    if (synth) { try { var u = new SpeechSynthesisUtterance(' '); u.volume = 0; w.speechSynthesis.speak(u); } catch (e) { } }
  };
  Speaker.prototype.say = function (text) {
    var s = this; if (s.stopped || !text) return; s.spoken++;
    if (s.engine === 'browser') { s.speakSS(text); return; } if (s.engine === 'none') return;
    var item = { text: text, url: null, failed: false, ready: null }, ctrl = w.AbortController ? new AbortController() : null; if (ctrl) s.ctrls.push(ctrl);
    item.ready = fetch(base() + '/voice/tts', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() }, body: JSON.stringify({ text: text, source: 'voice' }), signal: ctrl && ctrl.signal })
      .then(function (r) { return r.json(); }).then(function (j) { if (j && j.engine === 'elevenlabs' && j.url) { item.url = j.url; return j.url; } item.failed = true; if (synth) s.engine = 'browser'; return null; }).catch(function () { item.failed = true; return null; });
    s.q.push(item); s.pump();
  };
  Speaker.prototype.pump = function () {
    var s = this; if (s.stopped || s.playing || !s.q.length) { s.checkIdle(); return; } var item = s.q[0]; s.playing = item;
    item.ready.then(function (url) {
      if (s.stopped) return; s.q.shift();
      if (!url) { if (synth && item.text) { s.speakSS(item.text, function () { s.playing = null; s.pump(); }); } else { s.playing = null; s.pump(); } return; }
      var a = s.pool[s.cur]; s.cur ^= 1; if (a.src !== url && a.currentSrc !== url) a.src = url; a.muted = false;
      var next = s.q[0]; if (next) next.ready.then(function (u2) { if (u2 && !s.stopped) { var b = s.pool[s.cur]; if (!b.src || b.ended || b.paused) { b.preload = 'auto'; b.src = u2; try { b.load(); } catch (e) { } } } });
      var fin = false, wd = setTimeout(function () { end(); }, Math.max(4000, item.text.length * 110 + 3000)), end = function () { if (fin) return; fin = true; clearTimeout(wd); a.onended = a.onerror = a.onplaying = null; s.playing = null; s.pump(); };
      a.onended = end; a.onerror = function () { if (fin) return; fin = true; clearTimeout(wd); s.playing = null; if (synth && item.text) s.speakSS(item.text, function () { s.pump(); }); else s.pump(); };
      a.onplaying = function () { if (!s.firstDone) { s.firstDone = true; s.o.onFirstAudio && s.o.onFirstAudio(); } s.o.onState && s.o.onState('playing'); };
      var pr = a.play(); if (pr && pr.catch) pr.catch(function () { a.onerror && a.onerror(); });
    });
  };
  Speaker.prototype.speakSS = function (text, done) {
    var s = this; if (!synth || s.stopped) { done && done(); return; } s.ss++; var finished = false;
    var fin = function () { if (finished) return; finished = true; clearTimeout(wd); s.ss = Math.max(0, s.ss - 1); done && done(); s.checkIdle(); };
    var wd = setTimeout(fin, Math.max(2500, text.length * 95 + 2500)); var u = new SpeechSynthesisUtterance(text); u.lang = d.documentElement.lang && d.documentElement.lang !== 'en' ? d.documentElement.lang : (navigator.language || 'en-IN'); u.rate = 1.03;
    u.onstart = function () { if (!s.firstDone) { s.firstDone = true; s.o.onFirstAudio && s.o.onFirstAudio(); } s.o.onState && s.o.onState('playing'); }; u.onend = u.onerror = fin; try { w.speechSynthesis.speak(u); } catch (e) { fin(); }
  };
  Speaker.prototype.end = function () { this.ended = true; this.checkIdle(); };
  Speaker.prototype.checkIdle = function () { var s = this; if (s.ended && !s.stopped && !s.playing && !s.q.length && !s.ss) { s.stopped = true; s.o.onState && s.o.onState('idle'); s.o.onDone && s.o.onDone(); } };
  Speaker.prototype.stop = function () {
    var s = this, was = !s.stopped; s.stopped = true; s.q = []; s.playing = null; s.ctrls.forEach(function (c) { try { c.abort(); } catch (e) { } }); s.ctrls = [];
    s.pool.forEach(function (a) { try { a.onended = a.onerror = a.onplaying = null; a.pause(); a.removeAttribute('src'); a.load(); } catch (e) { } }); if (synth) { try { w.speechSynthesis.cancel(); } catch (e) { } } s.ss = 0; if (was) s.o.onState && s.o.onState('idle');
  };

  // ---------------------------------------------------------------- the panel
  var P = null; // the single panel instance
  function Panel(host) {
    var p = this; p.host = host; p.open = false; p.state = null; p.route = { stt: 'typed', tts: 'none' }; p.cap = null; p.turn = null; p.speaker = null; p.opener = null; p.timings = []; p.built = false; p.recent = [];
    p.mute = ls('mute') === '1'; p.hands = ls('hands') === '1';
  }
  Panel.prototype.build = function () {
    var p = this, h = p.host; h.textContent = ''; h.setAttribute('role', 'dialog'); h.setAttribute('aria-label', 'Voice assistant'); h.setAttribute('aria-modal', 'false');
    var head = el('div', 'vd-head'); p.avatar = el('img', 'vd-avatar'); p.avatar.alt = ''; p.avatar.width = p.avatar.height = 32; var who = el('div', 'vd-who'); p.nameEl = el('b', 'vd-name', 'Voice'); p.subEl = el('span', 'vd-sub', 'Talk to your advisor'); who.appendChild(p.nameEl); who.appendChild(p.subEl);
    var tools = el('div', 'vd-tools'); p.muteBtn = p.tool('Speak answers aloud', 'speaker', function () { p.setMute(!p.mute); }); p.typeBtn = p.tool('Type instead', 'keyboard', function () { p.toggleType(); });
    p.chatLink = el('a', 'vd-tool'); p.chatLink.href = base() + '/ai'; p.chatLink.title = 'Open the chat'; p.chatLink.setAttribute('aria-label', 'Open the chat'); p.chatLink.appendChild(icon('chat', 18));
    p.closeBtn = p.tool('Close', 'x', function () { p.close(true); });
    [p.muteBtn, p.typeBtn, p.chatLink, p.closeBtn].forEach(function (b) { tools.appendChild(b); }); head.appendChild(p.avatar); head.appendChild(who); head.appendChild(tools);
    p.bodyEl = el('div', 'vd-body'); p.notice = el('div', 'vd-notice'); p.notice.hidden = true; p.bodyEl.appendChild(p.notice);
    p.turnEl = el('div', 'vd-turn'); p.bodyEl.appendChild(p.turnEl);
    p.recentEl = el('details', 'vd-recent'); var sm = el('summary', '', 'Recent voice chats'); p.recentEl.appendChild(sm); p.recentList = el('ul', 'vd-recent__list'); p.recentEl.appendChild(p.recentList); p.recentEl.hidden = true; p.bodyEl.appendChild(p.recentEl);
    var foot = el('div', 'vd-foot'); p.voiceHost = el('div'); foot.appendChild(p.voiceHost);
    p.typeRow = el('form', 'vd-type'); p.typeRow.hidden = true; p.typeIn = el('input', 'vd-type__in'); p.typeIn.type = 'text'; p.typeIn.placeholder = 'Type your question'; p.typeIn.maxLength = 600; p.typeIn.setAttribute('aria-label', 'Type your question'); p.typeIn.autocomplete = 'off'; var tb = el('button', 'vd-type__go'); tb.type = 'submit'; tb.setAttribute('aria-label', 'Send'); tb.appendChild(icon('up', 18)); p.typeRow.appendChild(p.typeIn); p.typeRow.appendChild(tb);
    p.typeRow.addEventListener('submit', function (e) { e.preventDefault(); var t = p.typeIn.value.trim(); if (t) { p.typeIn.value = ''; p.submit(t, { stt: 'typed' }); } }); foot.appendChild(p.typeRow);
    p.pinRow = el('div', 'vd-pin'); p.pinRow.hidden = true; foot.appendChild(p.pinRow);
    p.handsBtn = el('button', 'vd-link vd-hands'); p.handsBtn.type = 'button'; p.handsBtn.addEventListener('click', function () { p.hands = !p.hands; ls('hands', p.hands ? '1' : '0'); p.paintHands(); }); foot.appendChild(p.handsBtn); p.paintHands();
    var hint = el('p', 'vd-hint'); hint.hidden = true; p.hintEl = hint; foot.appendChild(hint);
    h.appendChild(head); h.appendChild(p.bodyEl); h.appendChild(foot);
    p.vc = new VoiceControl(p.voiceHost, { onStartRequest: function () { p.beginRecording(); }, onStopRequest: function () { p.endRecording(); }, onSend: function () { p.sendTake(); }, onDiscard: function () { p.discardTake(); } });
    h.addEventListener('keydown', function (e) { p.onKey(e); }); p.built = true; p.paintMute();
  };
  Panel.prototype.tool = function (label, ic, fn) { var b = el('button', 'vd-tool'); b.type = 'button'; b.title = label; b.setAttribute('aria-label', label); b.appendChild(icon(ic, 18)); b.addEventListener('click', fn); return b; };
  Panel.prototype.paintMute = function () { var b = this.muteBtn, on = !this.mute; b.setAttribute('aria-pressed', on ? 'true' : 'false'); b.title = on ? 'Answers are spoken. Tap to mute.' : 'Answers are muted. Tap to speak them.'; b.setAttribute('aria-label', on ? 'Spoken answers on' : 'Spoken answers off'); b.textContent = ''; b.appendChild(icon(on ? 'speaker' : 'mute', 18)); b.classList.toggle('is-off', !on); };
  Panel.prototype.paintHands = function () { var b = this.handsBtn; b.setAttribute('aria-pressed', this.hands ? 'true' : 'false'); b.textContent = this.hands ? 'Hands-free is on: I send when you pause' : 'Hands-free: send when I pause'; };
  Panel.prototype.setMute = function (m) { this.mute = m; ls('mute', m ? '1' : '0'); this.paintMute(); if (m && this.speaker) this.speaker.stop(); };
  Panel.prototype.toggleType = function (force) { var p = this, on = force != null ? force : p.typeRow.hidden; p.typeRow.hidden = !on; p.typeBtn.setAttribute('aria-pressed', on ? 'true' : 'false'); if (on) setTimeout(function () { p.typeIn.focus(); }, 30); };
  Panel.prototype.say = function (text, tone, cta) {
    var n = this.notice; n.textContent = ''; if (!text) { n.hidden = true; return; } n.hidden = false; n.className = 'vd-notice' + (tone ? ' vd-notice--' + tone : ''); n.appendChild(el('p', '', text));
    if (cta) { var a = el('a', 'btn btn-sm', cta.label); a.href = cta.href; n.appendChild(a); }
  };
  Panel.prototype.caps = function () { return { secure: !!w.isSecureContext, getUserMedia: !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia), mediaRecorder: !!w.MediaRecorder, speechRecognition: !!SR, speechSynthesis: synth }; };
  Panel.prototype.load = function () {
    var p = this; return fetch(base() + '/ai/voice/state', { credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw new Error('state'); return r.json(); }).then(function (j) {
      p.state = j; p.route = pickRoute(j.voice || {}, p.caps()); p.nameEl.textContent = j.ai.name; if (j.ai.avatar) p.avatar.src = j.ai.avatar; p.avatar.hidden = !j.ai.avatar; p.recent = j.recent || []; p.paintRecent(); p.paintRoute();
      if (!j.ai.ok) { var cta = ['wallet', 'plan', 'budget'].indexOf(j.ai.code) >= 0 ? { label: 'Plans & wallet', href: j.links.plans } : (j.ai.code === 'setup' ? { label: 'Open the chat', href: j.links.chat } : null); p.say(j.ai.message, 'warn', cta); p.vc.main.disabled = true; p.typeIn.disabled = true; }
      else { p.vc.main.disabled = p.route.stt === 'typed'; p.typeIn.disabled = false; if (!p.turn) p.say(''); }
      return j;
    }).catch(function () { p.say('Could not load the voice assistant. Check your connection and try again.', 'warn'); });
  };
  Panel.prototype.paintRoute = function () {
    var p = this, r = p.route, st = p.state, parts = [];
    p.subEl.textContent = r.stt === 'elevenlabs' ? 'ElevenLabs voice' : (r.stt === 'browser' ? 'Your device voice' : 'Typing only');
    if (r.stt === 'typed') { p.vc.main.disabled = true; p.vc.setBusy(r.why === 'insecure' ? 'Microphone needs HTTPS' : 'Type your question'); p.toggleType(true); }
    else { if (p.state && p.state.ai && p.state.ai.ok) p.vc.main.disabled = false; p.vc.setBusy(''); }
    if (r.why === 'insecure') p.hintEl.textContent = 'The microphone needs a secure (HTTPS) page.'; else if (st && st.voice && st.voice.hint && (r.tts === 'browser' || r.stt === 'browser')) p.hintEl.textContent = st.voice.hint; else p.hintEl.textContent = '';
    p.hintEl.hidden = !p.hintEl.textContent; p.muteBtn.hidden = r.tts === 'none';
    var dock = st && st.dock; p.pinRow.textContent = ''; p.pinRow.hidden = !(dock && dock.on && w.matchMedia && w.matchMedia('(min-width: 1024px)').matches);
    if (!p.pinRow.hidden) { var pb = el('button', 'vd-link', dock.pinned ? 'Unpin Voice from the dock' : 'Pin Voice to the dock'); pb.type = 'button'; pb.addEventListener('click', function () { p.pinForm(dock.pinned ? 'dock-unpin' : 'dock-pin'); }); p.pinRow.appendChild(pb); }
  };
  Panel.prototype.pinForm = function (action) {
    var f = d.createElement('form'); f.method = 'post'; f.action = base() + '/do/' + action; f.hidden = true; [['_csrf', csrf()], ['page', 'voice'], ['_back', location.pathname + location.search]].forEach(function (x) { var i = d.createElement('input'); i.type = 'hidden'; i.name = x[0]; i.value = x[1]; f.appendChild(i); });
    d.body.appendChild(f); if (f.requestSubmit) f.requestSubmit(); else f.submit();
  };
  Panel.prototype.paintRecent = function () {
    var p = this, ul = p.recentList; ul.textContent = ''; p.recentEl.hidden = !p.recent.length;
    p.recent.slice().reverse().forEach(function (t) {
      var li = el('li', 'vd-recent__item'), q = el('p', 'vd-recent__q', t.user || '(spoken)'), a = el('p', 'vd-recent__a', t.reply || (t.cards ? t.cards + ' result card' + (t.cards === 1 ? '' : 's') + ' in the chat' : ''));
      li.appendChild(q); li.appendChild(a); var row = el('div', 'vd-recent__row');
      if (t.reply && p.route.tts !== 'none') { var rd = el('button', 'vd-link', 'Read aloud'); rd.type = 'button'; rd.addEventListener('click', function () { p.readAloud(t.reply); }); row.appendChild(rd); }
      var lk = el('a', 'vd-link', 'Open in chat'); lk.href = base() + '/ai'; row.appendChild(lk); li.appendChild(row); ul.appendChild(li);
    });
  };
  Panel.prototype.readAloud = function (text) { var p = this; p.stopAll(); p.speaker = new Speaker({ engine: p.route.tts, onState: function (s) { p.setSpeaking(s === 'playing'); } }); p.speaker.unlock(); var ch = createChunker(); ch.feed(text + ' ').concat(ch.flush()).forEach(function (c) { p.speaker.say(c); }); p.speaker.end(); };
  Panel.prototype.setSpeaking = function (on) { this.host.classList.toggle('is-speaking', !!on); if (this.stopBtn) this.stopBtn.hidden = !on && !this.turnBusy; };

  // ----- recording
  Panel.prototype.beginRecording = function () {
    var p = this; p.stopAll(true); p.say(''); if (!p.state || !p.state.ai.ok) return; if (p.route.stt === 'typed') { p.say(micErrorText({ name: w.isSecureContext ? 'NotFoundError' : 'SecurityError' }), 'warn'); return; }
    p.unlocker = p.unlocker || new Speaker({ engine: 'none' }); p.unlocker.unlock(); p.vc.main.disabled = true; p.live = ''; p.liveEl && p.liveEl.remove(); p.liveEl = null;
    openMic(p.route, function (txt) { p.live = txt; p.paintLive(); }).then(function (cap) {
      p.cap = cap; p.vc.main.disabled = false; var vad = ls('hands') === '1' ? createVad() : null; p.vad = vad;
      p.vc.start(function () { var l = cap.level(); if (vad && l != null && vad(l, Date.now())) { vad = null; setTimeout(function () { p.endRecording(); }, 0); } return l; });
      p.vc.main.focus();
    }).catch(function (err) { p.vc.main.disabled = false; p.cap = null; p.say(micErrorText(err), 'warn'); if (err && (err.name === 'NotAllowedError' || err.name === 'SecurityError')) p.toggleType(true); });
  };
  Panel.prototype.paintLive = function () {
    var p = this; if (!p.live) { if (p.liveEl) p.liveEl.hidden = true; return; } if (!p.liveEl) { p.liveEl = el('p', 'vd-live'); p.turnEl.appendChild(p.liveEl); } p.liveEl.hidden = false; p.liveEl.textContent = p.live;
  };
  Panel.prototype.endRecording = function () {
    var p = this, cap = p.cap; if (!cap || p.vc.mode !== 'recording') return; p.stopAt = now(); p.vc.main.disabled = true;
    p.vc.stop(null);   // the waveform settles at once; the audio for playback and the final transcript follow
    cap.stop().then(function (r) { p.cap = null; p.take = r; p.vc.main.disabled = false; p.vc.setBlob(r.blob); p.afterStop(r); });
  };
  Panel.prototype.afterStop = function (r) {
    var p = this, secs = Math.max(1, Math.round(p.vc.duration)); p.take.secs = secs; p.take.text = (r.text || '').trim(); p.take.state = 'pending'; p.take.stt = p.route.stt;
    p.say(''); p.paintLive(); p.vc.send.disabled = true;
    if (p.route.stt === 'elevenlabs' && r.blob) {
      p.vc.setBusy('Transcribing...'); p.live = 'Transcribing...'; p.paintLive();
      fetch(base() + '/voice/stt', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': r.blob.type || 'audio/webm', 'X-CSRF-Token': csrf(), 'X-Audio-Seconds': String(secs), 'X-Voice-Source': 'voice' }, body: r.blob })
        .then(function (x) { return x.json(); }).then(function (j) {
          p.textAt = now();
          if (j && j.engine === 'elevenlabs' && typeof j.text === 'string') { p.take.text = j.text.trim(); }
          else { p.take.text = ''; p.take.fallback = true; p.say('The premium transcription is not available right now (' + (j && j.why ? String(j.why).replace(/_/g, ' ') : 'limit reached') + '). Try again, it will use your device voice, or type your question.', 'warn'); p.route.stt = SR && w.isSecureContext ? 'browser' : 'typed'; p.paintRoute(); }
          p.takeReady();
        }).catch(function () { p.take.text = ''; p.say('Could not transcribe that. Check your connection and try again, or type your question.', 'warn'); p.takeReady(); });
    } else { p.textAt = now(); p.takeReady(); }
  };
  Panel.prototype.takeReady = function () {
    var p = this, t = p.take; if (!t) return; p.vc.setBusy(''); p.vc.send.disabled = !t.text; t.state = 'ready';
    if (!t.text) { p.live = ''; p.paintLive(); if (!p.notice.textContent) p.say('I did not catch any words. Discard and try again, or type your question.', 'warn'); }
    else { p.live = t.text; p.paintLive(); if (p.liveEl) { p.liveEl.setAttribute('contenteditable', 'true'); p.liveEl.setAttribute('role', 'textbox'); p.liveEl.setAttribute('aria-label', 'Transcript, you can edit it before sending'); p.liveEl.oninput = function () { t.text = p.liveEl.textContent.trim(); p.vc.send.disabled = !t.text; }; } if (ls('hands') === '1') { p.vc.finish(true); } }
  };
  Panel.prototype.sendTake = function () { var p = this, t = p.take; if (!t || !t.text) return; p.take = null; if (p.liveEl) { p.liveEl.removeAttribute('contenteditable'); p.liveEl.oninput = null; } var stopAt = p.stopAt, textAt = p.textAt; p.liveEl = null; p.live = ''; p.submit(t.text, { stt: t.stt, stopAt: stopAt, textAt: textAt, voiceUnlock: true }); };
  Panel.prototype.discardTake = function () { var p = this; p.take = null; p.live = ''; if (p.liveEl) { p.liveEl.remove(); p.liveEl = null; } p.say(''); p.vc.setBusy(''); };

  // ----- one turn
  Panel.prototype.stopAll = function (keepTurnUi) {
    var p = this; if (p.speaker) { p.speaker.stop(); p.speaker = null; } if (p.turn && p.turn.ctrl) { try { p.turn.ctrl.abort(); } catch (e) { } } p.setSpeaking(false); p.turnBusy = false; if (p.stopBtn) p.stopBtn.hidden = true; p.host.classList.remove('is-busy');
    if (p.cap) { p.cap.abort(); p.cap = null; } if (!keepTurnUi) { } p.setStatus('');
  };
  Panel.prototype.setStatus = function (t) { if (!this.statusEl) return; this.statusEl.textContent = t || ''; this.statusEl.hidden = !t; };
  Panel.prototype.submit = function (text, meta) {
    var p = this; meta = meta || {}; text = String(text || '').trim(); if (!text || !p.state || !p.state.ai.ok) return;
    p.stopAll(true); p.say(''); p.turnEl.textContent = ''; p.liveEl = null;
    var user = el('div', 'vd-bubble vd-bubble--user'); user.appendChild(el('p', '', text)); var tag = el('span', 'vd-tag'); tag.appendChild(icon('mic', 11)); tag.appendChild(d.createTextNode(meta.stt === 'typed' ? 'Typed' : 'Spoken')); user.appendChild(tag); p.turnEl.appendChild(user);
    var ans = el('div', 'vd-answer'); p.answerEl = ans; p.turnEl.appendChild(ans); p.statusEl = el('p', 'vd-status'); p.statusEl.setAttribute('role', 'status'); p.turnEl.appendChild(p.statusEl);
    p.stopBtn = el('button', 'vd-stop'); p.stopBtn.type = 'button'; p.stopBtn.appendChild(icon('stop', 12, true)); p.stopBtn.appendChild(d.createTextNode('Stop')); p.stopBtn.addEventListener('click', function () { p.stopAll(true); p.setStatus('Stopped.'); }); p.turnEl.appendChild(p.stopBtn);
    p.setStatus('Thinking...'); p.turnBusy = true; p.host.classList.add('is-busy'); p.bodyEl.scrollTop = p.bodyEl.scrollHeight;
    var speak = !p.mute && p.route.tts !== 'none', T = { sendAt: now(), stopAt: meta.stopAt || null, textAt: meta.textAt || null, firstDelta: null, firstAudio: null, serverFirst: null }, chunker = createChunker(), textEls = {}, streamed = {}, ctrl = w.AbortController ? new AbortController() : null;
    p.turn = { ctrl: ctrl, T: T };
    if (speak) { p.speaker = new Speaker({ engine: p.route.tts, onFirstAudio: function () { T.firstAudio = now(); p.logTimings(T); }, onState: function (s) { p.setSpeaking(s === 'playing'); if (s === 'idle' && !p.turnBusy) { p.setStatus(''); p.host.classList.remove('is-busy'); } }, onDone: function () { p.setStatus(''); } }); p.speaker.unlock(); }
    var enqueue = function (chunks) { if (!p.speaker) return; chunks.forEach(function (c) { p.setStatus('Speaking...'); p.speaker.say(c); }); };
    var payload = { message: text, model: p.state.ai.model, effort: p.state.ai.effort, stt: meta.stt || 'typed' };
    var chain = Promise.resolve(), card = function (b) { chain = chain.then(function () { return p.loadBlocks(); }).then(function (api) { p.addCard(api, b); }); };   // the card renderers (ai.js) load the first time a card arrives
    function onEv(ev) {
      if (ev.type === 'status') { if (!p.speaker || !p.speaker.spoken) p.setStatus(ev.text || 'Thinking...'); }
      else if (ev.type === 'delta') {
        if (T.firstDelta == null) T.firstDelta = now(); streamed[ev.step] = true; var te = textEls[ev.step]; if (!te) { te = textEls[ev.step] = el('p', 'vd-text'); ans.appendChild(te); } te.textContent += ev.text; p.bodyEl.scrollTop = p.bodyEl.scrollHeight; enqueue(chunker.feed(ev.text));
      }
      else if (ev.type === 'block') {
        var b = ev.block;
        if (b.type === 'text') { var t2 = textEls[ev.step]; if (!t2) { t2 = textEls[ev.step] = el('p', 'vd-text'); ans.appendChild(t2); } t2.textContent = b.text; if (!streamed[ev.step]) { if (T.firstDelta == null) T.firstDelta = now(); enqueue(chunker.feed(b.text + ' ')); } enqueue(chunker.flush()); chunker = createChunker(); }
        else { card(b); }
      }
      else if (ev.type === 'error') { p.showError(ev); }
      else if (ev.type === 'timing') { T.serverFirst = ev.first_token_ms; T.serverTotal = ev.total_ms; }
      else if (ev.type === 'cancelled' || ev.type === 'aborted') { p.setStatus('Stopped.'); }
    }
    fetch(base() + '/ai/voice', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() }, body: JSON.stringify(payload), signal: ctrl && ctrl.signal }).then(function (r) { return readNdjson(r, onEv); })
      .catch(function (err) { if (err && err.name === 'AbortError') return; onEv({ type: 'error', message: 'Could not reach the server. Check your connection and try again.' }); })
      .then(function () {
        p.turnBusy = false; enqueue(chunker.flush()); if (p.speaker) { p.speaker.end(); if (!p.speaker.spoken) { p.setStatus(''); p.host.classList.remove('is-busy'); } } else { p.setStatus(''); p.host.classList.remove('is-busy'); }
        if (p.stopBtn && !(p.speaker && !p.speaker.stopped)) p.stopBtn.hidden = true; T.doneAt = now(); p.logTimings(T, true); p.refreshRecent(text, ans);
      });
  };
  function readNdjson(r, onEv) {
    if (!r.body || !r.body.getReader) return r.text().then(function (t) { t.split('\n').forEach(function (l) { if (l.trim()) { try { onEv(JSON.parse(l)); } catch (e) { } } }); });
    var rd = r.body.getReader(), dec = new TextDecoder(), buf = '';
    function pump() { return rd.read().then(function (x) { if (x.done) { if (buf.trim()) { try { onEv(JSON.parse(buf)); } catch (e) { } } return; } buf += dec.decode(x.value, { stream: true }); var i; while ((i = buf.indexOf('\n')) >= 0) { var ln = buf.slice(0, i); buf = buf.slice(i + 1); if (ln.trim()) { try { onEv(JSON.parse(ln)); } catch (e) { } } } return pump(); }); }
    return pump();
  }
  Panel.prototype.showError = function (ev) {
    var p = this, code = ev.code || '', cta = null, l = p.state && p.state.links || {};
    if (code === 'wallet' || code === 'plan' || code === 'budget') cta = { label: 'Plans & wallet', href: l.plans || base() + '/plans' }; else if (code === 'model' || code === 'model_locked') cta = { label: 'Get in Plugins', href: l.plugins || base() + '/plugins' }; else if (code === 'setup') cta = { label: 'Open the chat', href: l.chat || base() + '/ai' };
    p.say(ev.message || 'Something went wrong.', 'bad', cta); p.setStatus('');
  };
  Panel.prototype.logTimings = function (T, final) {
    var p = this, o = {};
    if (T.stopAt && T.textAt) { o.speech_end_to_text_ms = Math.round(T.textAt - T.stopAt); o.text_to_send_ms = Math.round(T.sendAt - T.textAt); } if (T.firstDelta) o.send_to_first_token_ms = Math.round(T.firstDelta - T.sendAt); if (T.firstAudio && T.firstDelta) o.first_token_to_first_audio_ms = Math.round(T.firstAudio - T.firstDelta);
    if (T.firstAudio) { o.send_to_first_audio_ms = Math.round(T.firstAudio - T.sendAt); if (T.stopAt) o.speech_end_to_first_audio_ms = Math.round(T.firstAudio - T.stopAt); } if (T.serverFirst != null) o.server_first_token_ms = T.serverFirst; if (final && T.doneAt) o.send_to_done_ms = Math.round(T.doneAt - T.sendAt);
    if (!p.state || !p.state.debug) return; w.__mfVoice = w.__mfVoice || { timings: [] }; w.__mfVoice.last = o; if (final || T.firstAudio) { w.__mfVoice.timings.push(o); try { console.info('[mf-voice] latency', JSON.stringify(o)); } catch (e) { } }
  };
  Panel.prototype.loadBlocks = function () {
    var p = this; if (w.MfAiBlocks) return Promise.resolve(w.MfAiBlocks); if (p._blocks) return p._blocks;
    p._blocks = new Promise(function (res) {
      var css = p.host.getAttribute('data-ai-css'), js = p.host.getAttribute('data-ai-js'); if (css && !d.querySelector('link[data-vd-ai]')) { var l = d.createElement('link'); l.rel = 'stylesheet'; l.href = css; l.setAttribute('data-vd-ai', ''); d.head.appendChild(l); }
      if (!js) { res(null); return; } var sc = d.createElement('script'); sc.src = js; sc.onload = function () { res(w.MfAiBlocks || null); }; sc.onerror = function () { res(null); }; d.head.appendChild(sc);
    }); return p._blocks;
  };
  Panel.prototype.addCard = function (api, b) {
    var p = this, wrap = el('div', 'vd-card');
    try {
      var f = api && api.R && api.R[b.type]; if (!f) return; var ctx = { act: base() + '/ai/act', base: base(), send: function (t) { p.submit(t, { stt: 'typed' }); }, read: function () { }, post: function (url, bodyObj) { return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf() }, body: JSON.stringify(bodyObj) }).then(function (r) { return r.json(); }).catch(function () { return { type: 'error', message: 'Could not reach the server.' }; }).then(function (x) { return x; }); } };
      wrap.appendChild(f(b, ctx)); p.answerEl.appendChild(wrap); wrap.classList.add('ai-cardwrap'); p.bodyEl.scrollTop = p.bodyEl.scrollHeight;
    } catch (e) { /* a malformed card must never break the panel */ }
  };
  Panel.prototype.refreshRecent = function (text, ans) { var p = this, reply = ''; [].forEach.call(ans.querySelectorAll('.vd-text'), function (n) { reply += (reply ? ' ' : '') + n.textContent; }); if (!text) return; p.recent.push({ user: text, reply: reply, cards: ans.querySelectorAll('.vd-card').length }); p.recent = p.recent.slice(-8); p.paintRecent(); };

  // ----- open / close / anchoring / keys
  Panel.prototype.position = function (anchor) {
    var p = this, h = p.host, vw = d.documentElement.clientWidth, vh = w.innerHeight, wide = vw >= 1024;
    h.classList.toggle('vd--sheet', !wide); if (!wide) { h.style.left = h.style.right = ''; h.style.bottom = ''; h.style.maxHeight = ''; h.style.transformOrigin = '50% 100%'; return; }
    var W = Math.min(392, vw - 24), left, bottom, tr = anchor && anchor.getBoundingClientRect && anchor.offsetParent !== null ? anchor.getBoundingClientRect() : null;
    if (tr && tr.width) { left = tr.left + tr.width / 2 - W / 2; bottom = vh - tr.top + 16; h.style.transformOrigin = (tr.left + tr.width / 2 - Math.max(12, Math.min(vw - 12 - W, left))) + 'px 100%'; } else { left = (vw - W) / 2; bottom = 104; h.style.transformOrigin = '50% 100%'; }
    left = Math.max(12, Math.min(vw - 12 - W, left)); h.style.width = W + 'px'; h.style.left = left + 'px'; h.style.right = 'auto'; h.style.bottom = bottom + 'px'; h.style.maxHeight = Math.max(320, vh - bottom - 16) + 'px';
  };
  Panel.prototype.show = function (anchor) {
    var p = this, h = p.host; if (p.open) return; p.opener = d.activeElement && d.activeElement !== d.body ? d.activeElement : anchor; p.anchor = anchor; if (!p.built) p.build();
    p.open = true; h.hidden = false; p.position(anchor); if (!p.moved) { p.moved = true; w.addEventListener('resize', function () { if (p.open) p.position(p.anchor); }); d.addEventListener('pointerdown', function (e) { p.outside(e); }, true); }
    document.querySelectorAll('[data-voice-open]').forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
    h.classList.remove('is-closing'); var wide = d.documentElement.clientWidth >= 1024;
    if (!reduced() && M) { var mv = M.value(0); h.style.opacity = '0'; var apply = function (v) { h.style.opacity = String(Math.min(1, v * 1.4)); h.style.transform = wide ? 'scale(' + (0.72 + 0.28 * v).toFixed(3) + ')' : 'translateY(' + ((1 - v) * 28).toFixed(1) + 'px)'; }; apply(0); mv.on(apply); M.animate(mv, 1, { type: 'spring', duration: 0.3 }).finished.then(function () { h.style.transform = ''; h.style.opacity = ''; }); }
    p.load().then(function () { if (p.open && p.vc && !p.vc.main.disabled) p.vc.main.focus({ preventScroll: true }); }); setTimeout(function () { if (p.open && p.vc && !p.vc.main.disabled) p.vc.main.focus({ preventScroll: true }); }, 60);
  };
  Panel.prototype.close = function (restore) {
    var p = this, h = p.host; if (!p.open) return; p.open = false; p.stopAll(true); if (p.vc && p.vc.mode !== 'idle') p.vc.reset(); p.take = null;
    document.querySelectorAll('[data-voice-open]').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
    var wide = d.documentElement.clientWidth >= 1024, fin = function () { if (!p.open) { h.hidden = true; h.style.transform = ''; h.style.opacity = ''; } };
    if (!reduced() && M) { var mv = M.value(1), apply = function (v) { h.style.opacity = String(Math.max(0, Math.min(1, v * 1.4))); h.style.transform = wide ? 'scale(' + (0.72 + 0.28 * v).toFixed(3) + ')' : 'translateY(' + ((1 - v) * 28).toFixed(1) + 'px)'; }; mv.on(apply); M.animate(mv, 0, { duration: 0.18, ease: [0.4, 0, 1, 1] }).finished.then(fin); } else fin();
    if (restore && p.opener && p.opener.focus && p.opener.isConnected) { try { p.opener.focus({ preventScroll: true }); } catch (e) { } }
  };
  Panel.prototype.outside = function (e) {
    var p = this; if (!p.open || !p.host.classList.contains('vd--sheet')) return; var t = e.target; if (p.host.contains(t) || (t.closest && (t.closest('[data-voice-open]') || t.closest('.m-pill') || t.closest('.toast-wrap')))) return;
    if (p.vc && p.vc.mode !== 'idle') return; if (p.turnBusy || (p.speaker && !p.speaker.stopped)) return; // never lose a recording or cut off an answer with a stray tap; Escape / Close do that
    p.close(false);
  };
  Panel.prototype.onKey = function (e) {
    var p = this; if (e.key !== 'Escape') return; e.preventDefault(); e.stopPropagation();
    if (p.speaker && !p.speaker.stopped || p.turnBusy) { p.stopAll(true); p.setStatus('Stopped.'); return; }   // barge-in: Escape first stops the voice
    if (p.vc.mode === 'recording') { if (p.cap) { p.cap.abort(); p.cap = null; } p.vc.reset(); return; }
    if (p.vc.mode === 'review') { p.vc.finish(false); return; }
    p.close(true);
  };

  // ---------------------------------------------------------------- wiring
  function toggle(anchor) { if (!P) return; if (P.open) P.close(true); else P.show(anchor); }
  function scan() {
    var host = d.getElementById('voice-dock'); if (!host) { return; } if (!P || P.host !== host) { P = new Panel(host); }
  }
  function boot() {
    if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', scan); else scan(); d.addEventListener('mfnc:page', scan);
    d.addEventListener('click', function (e) { var a = e.target.closest && e.target.closest('[data-voice-open]'); if (!a) return; if (e.metaKey || e.ctrlKey || e.shiftKey) return; e.preventDefault(); scan(); toggle(a); }, true);   // capture: runs before the single-page router sees the link
    d.addEventListener('keydown', function (e) { if (e.key === 'Escape' && P && P.open && !e.defaultPrevented && !(e.target.closest && e.target.closest('dialog[open]'))) { P.onKey(e); return; } if (e.altKey && !e.ctrlKey && !e.metaKey && (e.code === 'KeyV' || e.key === 'v')) { var host = d.getElementById('voice-dock'); if (!host) return; e.preventDefault(); scan(); var tile = d.querySelector('.mf-dock [data-voice-open]') || d.querySelector('.vd-fab'); toggle(tile && tile.offsetParent !== null ? tile : null); } });
  }
  return { pure: pure, boot: boot, scan: scan, open: function () { scan(); if (P && !P.open) P.show(d.querySelector('.mf-dock [data-voice-open]') || d.querySelector('.vd-fab')); }, close: function () { if (P) P.close(true); }, panel: function () { return P; } };
});
