// Browser tests for the round 5 UI (account menu, voice assistant, rings). Not part of tests/run.php (they need a running dev server and Playwright):
//   MFNC_AI_MOCK=1 MFNC_VOICE_MOCK=1 MFNC_DEBUG=1 php -S 127.0.0.1:8000 router.php &
//   PW=$(npm root -g)/playwright node tests/e2e/account_menu.js     (also voice_flow.js, rings.js)
// Chromium runs with a fake microphone (--use-fake-device-for-media-stream) and the microphone permission granted, so the whole mic path works without hardware.
const { chromium } = require(process.env.PW || 'playwright');
const BASE = process.env.BASE || 'http://127.0.0.1:8000';
let bad = 0;
function t(name, ok, extra) { console.log((ok ? 'ok - ' : 'FAIL - ') + name + (ok ? '' : ' ' + (extra || ''))); if (!ok) bad++; }
async function launch() { return chromium.launch({ executablePath: process.env.CHROMIUM || '/opt/pw-browsers/chromium', args: ['--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream', '--no-sandbox', '--autoplay-policy=no-user-gesture-required'] }); }
async function session(browser, { mobile = false, dark = false, email = 'mom@example.com', password = 'Password123!', reduced = false, init = null } = {}) {
  const c = await browser.newContext({ viewport: mobile ? { width: 390, height: 844 } : { width: 1280, height: 800 }, hasTouch: mobile, isMobile: mobile, colorScheme: dark ? 'dark' : 'light', reducedMotion: reduced ? 'reduce' : 'no-preference', permissions: ['microphone'], baseURL: BASE });
  if (init) await c.addInitScript(init);
  const p = await c.newPage(); p.errs = []; p.on('console', m => { if (m.type() === 'error') p.errs.push(m.text()); }); p.on('pageerror', e => p.errs.push('PAGEERR ' + e.message));
  await p.goto('/login'); await p.fill('input[name=email]', email); await p.fill('input[name=password]', password); await p.click('form[action$="/do/login"] button'); await p.waitForURL(u => !/login/.test(u.pathname));
  return { c, p };
}
module.exports = { launch, session, t, done: () => process.exit(bad ? 1 : 0), BASE };
