const { launch, session, t, done } = require('./lib');
(async () => {
  const b = await launch();
  for (const [name, opts] of [['desktop', {}], ['phone', { mobile: true }], ['dark', { dark: true }], ['reduced motion', { reduced: true }]]) {
    const { p } = await session(b, opts);
    for (const url of ['/', '/expenses', '/goals', '/house', '/yearly']) { await p.goto(url); await p.waitForTimeout(1200); const h = await p.evaluate(() => document.body.scrollHeight); for (let y = 0; y < h; y += 450) { await p.evaluate(y => window.scrollTo(0, y), y); await p.waitForTimeout(80); } await p.waitForTimeout(1300);
      const r = await p.evaluate(() => [...document.querySelectorAll('.mf-ring')].map(el => { const arc = el.querySelector('.mf-ring__arc'), da = getComputedStyle(arc).strokeDasharray.split(' ')[0]; return { now: el.getAttribute('aria-valuenow'), text: el.querySelector('.mf-ring__n').textContent, drawn: parseFloat(da) || 0, state: el.dataset.state }; }));
      t(`${name} ${url}: ${r.length} rings, each drawn to its value after it was seen`, r.every(x => { const pct = Math.min(100, parseFloat(x.text) || 0); return Math.abs(x.drawn - pct / 100 * 282.743) < 3; }), JSON.stringify(r.slice(0, 3)));
      t(`${name} ${url}: no horizontal scroll`, await p.evaluate(() => document.documentElement.scrollWidth <= innerWidth)); }
    t(`${name}: no console errors`, p.errs.length === 0, p.errs.join(' | ')); await p.context().close();
  }
  await b.close(); done();
})();
