/* Tiny motion helpers (replaces the `motion` library for the vanilla ports): MotionValue, tween with cubic-bezier easing, critically damped spring, repeat.
   window.MfMotion = { value(v), animate(mv, to, opts), bezier(x1,y1,x2,y2), reduced() }
   animate(mv, to, { duration: seconds, ease: [x1,y1,x2,y2] | 'linear', type: 'spring', bounce: 0, repeat: Infinity })  `to` may be a number or [from, to] keyframes. Returns { stop(), finished: Promise }. */
(function (w) {
  'use strict';
  var mq = w.matchMedia ? w.matchMedia('(prefers-reduced-motion: reduce)') : null;
  function reduced() { return !!(mq && mq.matches); }
  function bezier(x1, y1, x2, y2) {
    var cx = 3 * x1, bx = 3 * (x2 - x1) - cx, ax = 1 - cx - bx, cy = 3 * y1, by = 3 * (y2 - y1) - cy, ay = 1 - cy - by;
    function sx(t) { return ((ax * t + bx) * t + cx) * t; }
    function dx(t) { return (3 * ax * t + 2 * bx) * t + cx; }
    function sy(t) { return ((ay * t + by) * t + cy) * t; }
    return function (x) {
      if (x <= 0) return 0; if (x >= 1) return 1; var t = x, i, d;
      for (i = 0; i < 8; i++) { d = sx(t) - x; if (Math.abs(d) < 1e-5) return sy(t); var s = dx(t); if (Math.abs(s) < 1e-6) break; t -= d / s; }
      var lo = 0, hi = 1; t = x; while (lo < hi) { d = sx(t); if (Math.abs(d - x) < 1e-5) break; if (x > d) lo = t; else hi = t; t = (hi - lo) / 2 + lo; if (hi - lo < 1e-7) break; }
      return sy(t);
    };
  }
  function MV(v) { this.v = v; this.subs = []; this.run = null; }
  MV.prototype.get = function () { return this.v; };
  MV.prototype.set = function (v) { if (v === this.v) return; this.v = v; for (var i = 0; i < this.subs.length; i++) this.subs[i](v); };
  MV.prototype.on = function (fn) { var s = this.subs; s.push(fn); return function () { var i = s.indexOf(fn); if (i >= 0) s.splice(i, 1); }; };
  MV.prototype.stop = function () { if (this.run) { this.run.stop(); this.run = null; } };
  MV.prototype.jump = function (v) { this.stop(); this.set(v); };
  function animate(mv, to, o) {
    o = o || {}; mv.stop();
    var from = mv.get(), target = to; if (Array.isArray(to)) { from = to[0]; target = to[1]; mv.set(from); }
    var spring = o.type === 'spring', dur = Math.max(0.001, o.duration || 0.3), ease = o.ease === 'linear' || !o.ease ? function (x) { return x; } : bezier.apply(null, o.ease), omega = 2 * Math.PI / dur;
    var repeat = o.repeat || 0, t0 = null, raf = 0, done, stopped = false, res, finished = new Promise(function (r) { res = r; });
    function prog(t) { return spring ? 1 - (1 + omega * t) * Math.exp(-omega * t) : ease(Math.min(1, t / dur)); }
    function tick(now) {
      if (stopped) return; if (t0 === null) t0 = now; var t = (now - t0) / 1000, p = prog(t), fin = spring ? Math.abs(1 - p) < 0.0008 && t > dur * 0.5 : t >= dur;
      if (fin) { if (repeat === Infinity || (repeat > 0 && repeat--)) { t0 = now; mv.set(from); raf = requestAnimationFrame(tick); return; } mv.set(target); mv.run = null; res(); return; }
      mv.set(from + (target - from) * p); raf = requestAnimationFrame(tick);
    }
    if (from === target && !Array.isArray(to)) { res(); return { stop: function () {}, finished: finished }; }
    raf = requestAnimationFrame(tick);
    mv.run = { stop: function () { stopped = true; cancelAnimationFrame(raf); res(); } };
    return { stop: function () { mv.stop(); }, finished: finished };
  }
  w.MfMotion = { value: function (v) { return new MV(v); }, animate: animate, bezier: bezier, reduced: reduced };
})(window);
