/* PillsMark: animated logo. WebGL2 port of the React component: a signed-distance field is built from pills.svg (alpha), a fragment shader renders a flowing gradient glow.
   - ONE shared WebGL2 canvas renders every logo on the page (each logo owns a plain 2D canvas that receives a copy), so there is one GL context, one field texture, one program.
   - Runs only while visible (IntersectionObserver) and while the tab is visible; capped at 30fps (small marks) / 45fps and DPR 2.
   - Static SVG fallback: no WebGL2, prefers-reduced-motion, field/shader failure.
   PillsMark.mount(el, { size, colors, innerGlow, outerGlow, contour, angle, speed, src, label }) -> { update(opts), destroy() };  PillsMark.scan(root) mounts every [data-pills] (data-size / data-speed).
   (Fixes the original's pack() bug: the typed array no longer shadows the `colors` argument.) */
(function (w) {
  'use strict';
  var d = document, SRC = null;
  var VERTEX = '#version 300 es\nin vec2 aPosition;\nvoid main() {\n  gl_Position = vec4(aPosition, 0.0, 1.0);\n}';
  var FRAGMENT = `#version 300 es
precision highp float;
#define PI 3.14159265359
#define TAU 6.28318530718
#define MATERIAL_OUTSIDE true
#define MATERIAL_REACH 0.3200
uniform sampler2D uField;
uniform sampler2D uSurf;
uniform vec2 uFieldScale;
uniform vec2 uResolution;
uniform float uTime;
uniform vec4 uMatP[8];
uniform vec4 uMatC[16];
uniform vec4 uSurface;
out vec4 outColor;
#define C_colors(k) uMatC[0 + (k)]
#define N_colors uMatP[0].x
#define P_innerGlow uMatP[0].y
#define P_outerGlow uMatP[0].z
#define P_contour uMatP[0].w
#define P_angle uMatP[1].x
#define P_speed uMatP[1].y
struct Surface { vec2 p; vec2 uv; vec2 frag; vec2 res; float unit; float px; float d; float aa; float cov; vec2 n; float slope; float time; };
float shapeAt(vec2 q) { return texture(uField, q * uFieldScale + 0.5).r; }
float m_hash(vec2 p) { uvec2 u = floatBitsToUint(p * vec2(141421356.0, 2718281828.0)); return float((u.x ^ u.y) * 3141592653u) / 4294967295.0; }
float m_value(vec2 p) {
  vec2 i = floor(p); vec2 f = p - i; f = f * f * (3.0 - 2.0 * f);
  return mix(mix(m_hash(i), m_hash(i + vec2(1.0, 0.0)), f.x), mix(m_hash(i + vec2(0.0, 1.0)), m_hash(i + vec2(1.0, 1.0)), f.x), f.y);
}
float m_front(float a, float b, float tk, float phase) {
  float pos = mix(-0.6, 0.6, tk);
  float wob = m_value(vec2(b * 4.0 + phase * 13.7, tk * 3.0 + phase * 7.1)) - 0.5;
  float e = a - pos + wob * 0.12;
  float band = exp(-e * e / 0.0256);
  float gate = smoothstep(0.0, 0.15, tk) * (1.0 - smoothstep(0.85, 1.0, tk));
  return clamp(band * gate, 0.0, 1.0);
}
vec3 toLinear(vec3 c) { return pow(c, vec3(2.2)); }
vec3 toDisplay(vec3 c) { return pow(max(c, 0.0), vec3(1.0 / 2.2)); }
vec3 m_toLab(vec3 c) {
  float l = 0.4122214708 * c.r + 0.5363325363 * c.g + 0.0514459929 * c.b;
  float m = 0.2119034982 * c.r + 0.6806995451 * c.g + 0.1073969566 * c.b;
  float s = 0.0883024619 * c.r + 0.2817188376 * c.g + 0.6299787005 * c.b;
  l = sign(l) * pow(abs(l), 1.0 / 3.0); m = sign(m) * pow(abs(m), 1.0 / 3.0); s = sign(s) * pow(abs(s), 1.0 / 3.0);
  return vec3(0.2104542553 * l + 0.7936177850 * m - 0.0040720468 * s, 1.9779984951 * l - 2.4285922050 * m + 0.4505937099 * s, 0.0259040371 * l + 0.7827717662 * m - 0.8086757660 * s);
}
vec3 m_fromLab(vec3 c) {
  float l = c.x + 0.3963377774 * c.y + 0.2158037573 * c.z; float m = c.x - 0.1055613458 * c.y - 0.0638541728 * c.z; float s = c.x - 0.0894841775 * c.y - 1.2914855480 * c.z;
  l = l * l * l; m = m * m * m; s = s * s * s;
  return vec3(4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s, -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s, -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s);
}
vec4 m_ramp(float t) {
  float n = max(N_colors, 1.0); vec4 c0 = C_colors(0); vec3 lab = m_toLab(toLinear(c0.rgb)); float alpha = c0.a;
  for (int i = 1; i < 8; i++) {
    if (float(i) >= n) break;
    float a = float(i - 1) / (n - 1.0); float b = float(i) / (n - 1.0); float seg = clamp((t - a) / max(b - a, 1e-6), 0.0, 1.0);
    vec4 ci = C_colors(i); float wa = alpha * (1.0 - seg); float wb = ci.a * seg;
    lab = (lab * wa + m_toLab(toLinear(ci.rgb)) * wb) / max(wa + wb, 0.001); alpha = wa + wb;
  }
  return vec4(toDisplay(m_fromLab(lab)), alpha);
}
vec4 material(Surface s) {
  float t = s.time * P_speed; float depth = clamp(-s.d, 0.0, 0.7);
  float f = s.d > 0.0 ? s.d * 0.5 : -0.5 * sqrt(max(depth * (1.4 - depth), 0.0));
  float inside = s.cov; vec2 rel = s.p * 0.5; float ang = P_angle * PI / 180.0;
  vec2 along = vec2(cos(ang), sin(ang)); vec2 across = vec2(-along.y, along.x);
  float a = dot(rel, along); float b = -dot(rel, across); float base = 0.1 * t - 0.3;
  float innerBlur = exp(-max(-f, 0.0) / 0.12); float inner = 0.8 + 0.8 * innerBlur;
  inner = mix(inner, 0.0, m_front(a, b, fract(base), 1.0));
  inner = mix(inner, 0.0, m_front(a, b, fract(base + 1.0 / 3.0), 2.0));
  inner = mix(inner, 0.0, m_front(a, b, fract(base + 2.0 / 3.0), 3.0));
  inner *= 2.0 * P_innerGlow; inner += P_contour * 2.0 * exp(-abs(f) / 0.03); inner = min(inner, 1.0) * inside; inner = pow(max(inner, 0.0), 1.2);
  float reach = 0.05 + 0.15 * P_outerGlow; float outerBlur = exp(-max(f, 0.0) / reach);
  if (s.d > 0.0) {
    float r = reach * 1.2; float k = 0.5 / reach;
    outerBlur += exp(-max(shapeAt(s.p + vec2(r, 0.0)), 0.0) * k); outerBlur += exp(-max(shapeAt(s.p - vec2(r, 0.0)), 0.0) * k);
    outerBlur += exp(-max(shapeAt(s.p + vec2(0.0, r)), 0.0) * k); outerBlur += exp(-max(shapeAt(s.p - vec2(0.0, r)), 0.0) * k); outerBlur *= 0.2;
  }
  vec2 edge = smoothstep(vec2(-0.06), vec2(0.1), s.uv) * (1.0 - smoothstep(vec2(0.9), vec2(1.06), s.uv));
  float sweep = fract(base * 3.0 - 0.1); float y = fract(a * 1.2 - sweep);
  float mask = 0.5 + smoothstep(0.3, 0.65, y) * (1.0 - smoothstep(0.65, 1.0, y));
  float outer = 0.9 * pow(outerBlur, 0.8) * mask * 5.0 * P_outerGlow * P_outerGlow * (1.0 - inside) * edge.x * edge.y;
  float heat = clamp(inner + outer, 0.0, 1.0); heat += 0.005 * (m_hash(s.frag * vec2(1.7, 1.3)) - 0.5); heat = clamp(heat, 0.0, 1.0);
  float cover = clamp(inside + outer, 0.0, 1.0); vec4 ramp = m_ramp(heat);
  return vec4(clamp(ramp.rgb, 0.0, 1.0), ramp.a * cover);
}
void main() {
  float unit = min(uResolution.x, uResolution.y) * 0.5; vec2 frag = vec2(gl_FragCoord.x, uResolution.y - gl_FragCoord.y);
  vec2 p = (frag - uResolution * 0.5) / unit; float px = 1.0 / unit; float d = shapeAt(p);
  float aa = clamp(length(vec2(dFdx(d), dFdy(d))), px * 0.5, px * 1.5);
  Surface s; s.p = p; s.uv = frag / uResolution; s.frag = frag; s.res = uResolution; s.unit = unit; s.px = px; s.d = d; s.aa = aa; s.cov = clamp(0.5 - d / aa, 0.0, 1.0); s.slope = 0.0; s.n = vec2(0.0); s.time = uTime;
  vec4 m = material(s); float reach = 1.0 - smoothstep(MATERIAL_REACH * 0.55, MATERIAL_REACH, d);
  float a = clamp(MATERIAL_OUTSIDE ? m.a * max(reach, s.cov) : m.a * s.cov, 0.0, 1.0);
  outColor = vec4(clamp(m.rgb, 0.0, 1.0) * a, a);
}
`;
  var DEFAULT = { colors: ['#02010e', '#260c84', '#972a96', '#d86b3c', '#f5e374', '#ffffff'], innerGlow: 0.4, outerGlow: 0, contour: 0.5, angle: 90, speed: 1 };
  function toRgba(value) {
    var hex = String(value || '#ffffff').replace('#', ''), full = hex.length <= 4 ? hex.split('').map(function (c) { return c + c; }).join('') : hex;
    function ch(i) { return parseInt(full.slice(i, i + 2), 16) / 255; } return [ch(0), ch(2), ch(4), full.length >= 8 ? ch(6) : 1];
  }
  /** Packs the settings into the two uniform arrays. (Original bug: a local `colors` array shadowed the argument; it is called `ramp` here.) */
  function pack(s) {
    var floats = new Float32Array(32), ramp = new Float32Array(64), list = (Array.isArray(s.colors) ? s.colors : [s.colors]).slice(0, 8);
    list.forEach(function (v, i) { ramp.set(toRgba(v), i * 4); });
    floats[0] = list.length; floats[1] = Number(s.innerGlow) || 0; floats[2] = Number(s.outerGlow) || 0; floats[3] = Number(s.contour) || 0; floats[4] = Number(s.angle) || 0; floats[5] = Number(s.speed) || 0;
    return { floats: floats, ramp: ramp };
  }
  // Felzenszwalb squared-distance transform
  function solve(grid, offset, stride, length, f, v, z) {
    var i, k = 0, q; for (i = 0; i < length; i++) f[i] = grid[offset + i * stride]; v[0] = 0; z[0] = -1e20; z[1] = 1e20;
    for (q = 1; q < length; q++) { var s = (f[q] + q * q - (f[v[k]] + v[k] * v[k])) / (2 * q - 2 * v[k]); while (s <= z[k]) { k--; s = (f[q] + q * q - (f[v[k]] + v[k] * v[k])) / (2 * q - 2 * v[k]); } k++; v[k] = q; z[k] = s; z[k + 1] = 1e20; }
    k = 0; for (q = 0; q < length; q++) { while (z[k + 1] < q) k++; grid[offset + q * stride] = (q - v[k]) * (q - v[k]) + f[v[k]]; }
  }
  function distances(grid, width, height) { var size = Math.max(width, height), f = new Float64Array(size), v = new Int32Array(size), z = new Float64Array(size + 1), x, y; for (x = 0; x < width; x++) solve(grid, x, width, height, f, v, z); for (y = 0; y < height; y++) solve(grid, y * width, 1, width, f, v, z); }
  var fields = {};
  function loadField(src) { // cached per source: the expensive part runs once per page
    if (fields[src]) return fields[src];
    return fields[src] = new Promise(function (resolve, reject) {
      var image = new Image();
      image.onload = function () {
        var long = 320, ratio = image.width / image.height || 1, width = Math.round(ratio >= 1 ? long : long * ratio), height = Math.round(ratio >= 1 ? long / ratio : long);
        var c = d.createElement('canvas'); c.width = width; c.height = height; var ctx = c.getContext('2d', { willReadFrequently: true }); ctx.drawImage(image, 0, 0, width, height);
        var px = ctx.getImageData(0, 0, width, height).data, outer = new Float64Array(width * height), inner = new Float64Array(width * height), i;
        for (i = 0; i < width * height; i++) { var a = px[i * 4 + 3] / 255, edge = 0.5 - a; outer[i] = a >= 1 ? 0 : a <= 0 ? 1e20 : edge > 0 ? edge * edge : 0; inner[i] = a <= 0 ? 0 : a >= 1 ? 1e20 : edge < 0 ? edge * edge : 0; }
        distances(outer, width, height); distances(inner, width, height);
        var unit = Math.min(width, height) / 2, data = new Float32Array(width * height); for (i = 0; i < data.length; i++) data[i] = (Math.sqrt(outer[i]) - Math.sqrt(inner[i])) / unit;
        resolve({ data: data, width: width, height: height });
      };
      image.onerror = reject; image.src = src;
    });
  }

  // ---------- the one shared renderer ----------
  var R = { gl: null, canvas: null, ok: null, field: null, loading: false, u: {}, items: [], raf: 0, last: 0, t0: performance.now() };
  function compile(gl, type, src) { var s = gl.createShader(type); gl.shaderSource(s, src); gl.compileShader(s); if (!gl.getShaderParameter(s, gl.COMPILE_STATUS)) throw new Error(gl.getShaderInfoLog(s) || 'shader'); return s; }
  function initGL() {
    if (R.ok !== null) return R.ok;
    try {
      var cv = d.createElement('canvas'); cv.width = cv.height = 8; var gl = cv.getContext('webgl2', { alpha: true, premultipliedAlpha: true, antialias: false, preserveDrawingBuffer: false }); if (!gl) throw new Error('no webgl2');
      var pr = gl.createProgram(); gl.attachShader(pr, compile(gl, gl.VERTEX_SHADER, VERTEX)); gl.attachShader(pr, compile(gl, gl.FRAGMENT_SHADER, FRAGMENT)); gl.bindAttribLocation(pr, 0, 'aPosition'); gl.linkProgram(pr);
      if (!gl.getProgramParameter(pr, gl.LINK_STATUS)) throw new Error('link'); gl.useProgram(pr);
      var buf = gl.createBuffer(); gl.bindBuffer(gl.ARRAY_BUFFER, buf); gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 1, -1, -1, 1, 1, 1]), gl.STATIC_DRAW); gl.enableVertexAttribArray(0); gl.vertexAttribPointer(0, 2, gl.FLOAT, false, 0, 0);
      ['uResolution', 'uFieldScale', 'uTime', 'uMatP', 'uMatC', 'uSurface', 'uField', 'uSurf'].forEach(function (n) { R.u[n] = gl.getUniformLocation(pr, n); });
      R.tex = gl.createTexture(); R.gl = gl; R.canvas = cv; R.ok = true;
      cv.addEventListener('webglcontextlost', function (e) { e.preventDefault(); R.ok = false; R.items.forEach(function (it) { it.showFallback(); }); });
    } catch (e) { R.ok = false; }
    return R.ok;
  }
  function uploadField(f) {
    var gl = R.gl; gl.bindTexture(gl.TEXTURE_2D, R.tex); gl.pixelStorei(gl.UNPACK_ALIGNMENT, 1); gl.texImage2D(gl.TEXTURE_2D, 0, gl.R16F, f.width, f.height, 0, gl.RED, gl.FLOAT, f.data);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR); gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR); gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE); gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE); R.field = f;
  }
  function frame(now) {
    R.raf = 0; var active = R.items.filter(function (it) { return it.visible && !it.dead; }); if (!active.length || d.hidden || !R.field) { return; }
    R.raf = requestAnimationFrame(frame);
    var minGap = 1000 / (active.some(function (it) { return it.px > 120; }) ? 45 : 30); if (now - R.last < minGap - 2) return; R.last = now;
    var gl = R.gl, f = R.field, fu = Math.min(f.width, f.height) / 2;
    active.forEach(function (it) {
      var ratio = Math.min(w.devicePixelRatio || 1, 2), px = Math.max(8, Math.round(it.size * ratio)); it.px = px;
      if (it.canvas.width !== px) { it.canvas.width = px; it.canvas.height = px; } if (R.canvas.width !== px || R.canvas.height !== px) { R.canvas.width = px; R.canvas.height = px; }
      var p = pack(it.opts); gl.viewport(0, 0, px, px); gl.uniform2f(R.u.uResolution, px, px); gl.uniform2f(R.u.uFieldScale, fu / f.width, fu / f.height); gl.uniform1f(R.u.uTime, (now - R.t0) / 1000 + it.phase);
      gl.uniform4fv(R.u.uMatP, p.floats); gl.uniform4fv(R.u.uMatC, p.ramp); gl.uniform4f(R.u.uSurface, 0.04, 0, 0, 0); gl.uniform1i(R.u.uField, 0); gl.uniform1i(R.u.uSurf, 1);
      gl.activeTexture(gl.TEXTURE0); gl.bindTexture(gl.TEXTURE_2D, R.tex); gl.clearColor(0, 0, 0, 0); gl.clear(gl.COLOR_BUFFER_BIT); gl.drawArrays(gl.TRIANGLE_STRIP, 0, 4);
      var c2 = it.ctx; c2.clearRect(0, 0, px, px); c2.drawImage(R.canvas, 0, 0);
      if (!it.shown) { it.shown = true; it.el.setAttribute('data-live', ''); }
    });
  }
  function wake() { if (!R.raf) R.raf = requestAnimationFrame(frame); }
  d.addEventListener('visibilitychange', function () { if (!d.hidden) wake(); });

  var FALLBACK = '<svg viewBox="0 0 100 100" aria-hidden="true" focusable="false"><defs><linearGradient id="__ID__" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#260c84"/><stop offset=".45" stop-color="#972a96"/><stop offset=".8" stop-color="#d86b3c"/><stop offset="1" stop-color="#f5e374"/></linearGradient></defs><g transform="rotate(-45 50 50)"><rect x="7" y="21" width="86" height="25" rx="12.5" fill="url(#__ID__)"/><rect x="19" y="55" width="62" height="25" rx="12.5" fill="url(#__ID__)"/><rect x="14" y="26" width="72" height="5" rx="2.5" fill="#fff" opacity=".28"/><rect x="26" y="60" width="48" height="5" rx="2.5" fill="#fff" opacity=".28"/></g></svg>';
  var uid = 0;
  function mount(el, options) {
    var opts = Object.assign({}, DEFAULT, options || {}), size = Number(opts.size) || el.clientWidth || 28, label = opts.label; el.classList.add('pills-mark'); el.style.setProperty('--pm-size', size + 'px'); el.textContent = '';
    if (label) { el.setAttribute('role', 'img'); el.setAttribute('aria-label', label); } else { el.setAttribute('aria-hidden', 'true'); }
    var fb = d.createElement('span'); fb.className = 'pills-mark__fallback'; fb.innerHTML = FALLBACK.replace(/__ID__/g, 'pm' + (++uid)); el.appendChild(fb);
    var item = { el: el, size: size, opts: opts, visible: false, dead: false, phase: Math.random() * 20, px: 0, shown: false, canvas: null, ctx: null, io: null,
      showFallback: function () { el.removeAttribute('data-live'); item.shown = false; item.dead = true; } };
    var still = M().reduced() || !w.WebGL2RenderingContext;
    if (!still && initGL()) {
      var cv = d.createElement('canvas'); cv.className = 'pills-mark__canvas'; cv.setAttribute('aria-hidden', 'true'); el.appendChild(cv); item.canvas = cv; item.ctx = cv.getContext('2d');
      var src = opts.src || SRC || (SRC = (d.querySelector('meta[name="pills-src"]') || {}).content) || 'pills.svg';
      loadField(src).then(function (f) { if (item.dead) return; if (!R.field) { try { uploadField(f); } catch (e) { R.ok = false; item.showFallback(); return; } } wake(); }).catch(function () { item.showFallback(); });
      R.items.push(item);
      if (w.IntersectionObserver) { item.io = new IntersectionObserver(function (es) { item.visible = es[es.length - 1].isIntersecting; if (item.visible) wake(); }, { rootMargin: '40px' }); item.io.observe(el); } else { item.visible = true; wake(); }
    }
    var mq = w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)'), onMq = function () { if (mq.matches) item.showFallback(); }; if (mq && mq.addEventListener) mq.addEventListener('change', onMq);
    return {
      update: function (o) { Object.assign(item.opts, o || {}); },
      destroy: function () { item.dead = true; if (item.io) item.io.disconnect(); var i = R.items.indexOf(item); if (i >= 0) R.items.splice(i, 1); if (mq && mq.removeEventListener) mq.removeEventListener('change', onMq); el.textContent = ''; el.classList.remove('pills-mark'); }
    };
  }
  function M() { return w.MfMotion || { reduced: function () { return !!(w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches); } }; }
  function scan(root) {
    (root || d).querySelectorAll('[data-pills]').forEach(function (el) {
      if (el._pills && el.isConnected) return; var o = { size: Number(el.getAttribute('data-size')) || undefined, label: el.getAttribute('data-label') || '' }; if (el.hasAttribute('data-speed')) o.speed = Number(el.getAttribute('data-speed'));
      if (el.hasAttribute('data-colors')) o.colors = el.getAttribute('data-colors').split(',');
      el._pills = mount(el, o);
    });
    // marks whose element was swapped out by the SPA are cleaned up
    R.items = R.items.filter(function (it) { if (!it.el.isConnected) { it.dead = true; if (it.io) it.io.disconnect(); return false; } return true; });
  }
  w.PillsMark = { mount: mount, scan: scan, pack: pack, _loadField: loadField };
  if (d.readyState === 'loading') d.addEventListener('DOMContentLoaded', function () { scan(); }); else scan();
  d.addEventListener('mfnc:page', function () { scan(); });
})(window);
