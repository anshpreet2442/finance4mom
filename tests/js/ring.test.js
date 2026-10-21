// node tests/js/ring.test.js : the pure maths of assets/ring.js (percent, tone, dash, split, text)
const R = require('../../assets/ring.js'), m = R.math; let bad = 0;
function t(name, ok, extra) { console.log((ok ? 'ok - ' : 'FAIL - ') + name + (ok ? '' : ' ' + (extra || ''))); if (!ok) bad++; }
const near = (a, b, e = 1e-6) => Math.abs(a - b) <= e;
t('percent of a plain value', m.percent(72, 0, 100).whole === 72);
t('percent respects min and max', m.percent(50, 0, 200).whole === 25 && m.percent(15, 10, 20).whole === 50);
t('over the limit keeps the real value but draws a full ring', m.percent(112, 0, 100).whole === 112 && m.percent(112, 0, 100).visual === 100);
t('negative values draw nothing', m.percent(-5, 0, 100).visual === 0);
t('an empty range is 0, never NaN', m.percent(5, 5, 5).whole === 0 && m.percent('abc', 0, 100).whole === 0);
t('limit tone: ok below 85', m.tone(40, { limit: true }) === 'ok' && m.tone(84.9, { limit: true }) === 'ok');
t('limit tone: amber from 85 up to 100', m.tone(85, { limit: true }) === 'warn' && m.tone(100, { limit: true }) === 'warn');
t('limit tone: red above 100', m.tone(100.01, { limit: true }) === 'bad' && m.tone(112, { limit: true }) === 'bad');
t('limit tone honours custom thresholds', m.tone(60, { limit: true, warn: 50, bad: 90 }) === 'warn' && m.tone(95, { limit: true, warn: 50, bad: 90 }) === 'bad');
t('goal tone stays neutral until complete', m.tone(99, {}) === 'ok' && m.tone(100, {}) === 'done');
t('dash length is the share of the circumference', near(m.dash(50), Math.PI * 90 / 2, 0.001) && m.dash(0) === 0 && near(m.dash(100), 2 * Math.PI * 45, 0.001));
t('split look: the unused arc is 90 - value, none from 90 up', m.split(30).secondary === 60 && m.split(95).secondary === 0 && m.split(90).secondary === 0);
t('split look rotates like the original (360 - 90 - 5 * 3.6)', m.split(10).secondaryRotate === 252 && m.split(10).primaryRotate === -90);
t('centre text: whole percent by default, real value over 100', m.text(71.6, {}) === '72%' && m.text(112.4, {}) === '112%');
t('centre text: raw, none and custom', m.text(5, { format: 'raw' }) === '5' && m.text(5, { format: 'none' }) === '' && m.text(5, { text: '₹5' }) === '₹5');
t('easing is monotonic from 0 to 1', (() => { let p = 0; for (let x = 0; x <= 1.0001; x += 0.05) { const y = m.ease(x); if (y < p - 1e-9) return false; p = y; } return m.ease(0) === 0 && m.ease(1) === 1; })());
process.exit(bad ? 1 : 0);
