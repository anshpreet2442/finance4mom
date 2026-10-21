// node tests/js/accountmenu.test.js : keyboard model and clip geometry of assets/accountmenu.js
const A = require('../../assets/accountmenu.js').helpers; let bad = 0;
function t(name, ok, extra) { console.log((ok ? 'ok - ' : 'FAIL - ') + name + (ok ? '' : ' ' + (extra || ''))); if (!ok) bad++; }
const it = (row, checked) => ({ dataset: { row }, getAttribute: (k) => (k === 'aria-checked' && checked ? 'true' : null) });
const s1 = it('status', false), s2 = it('status', true), s3 = it('status', false), th1 = it('theme', false), th2 = it('theme', true), a = it('item-0'), b = it('item-1'), out = it('sign-out');
const list = [s1, s2, s3, th1, th2, a, b, out];
t('ArrowDown from nothing goes to the first row', A.nextTarget(list, null, 'ArrowDown') === s1 && A.nextTarget(list, null, 'ArrowUp') === out);
t('ArrowDown moves to the next row and lands on the checked radio', A.nextTarget(list, s2, 'ArrowDown') === th2);
t('ArrowUp from the first row wraps to the last', A.nextTarget(list, s2, 'ArrowUp') === out && A.nextTarget(list, out, 'ArrowDown') === s2);
t('ArrowRight / ArrowLeft move inside a radio row and wrap', A.nextTarget(list, s2, 'ArrowRight') === s3 && A.nextTarget(list, s3, 'ArrowRight') === s1 && A.nextTarget(list, s1, 'ArrowLeft') === s3);
t('ArrowLeft on a single item does nothing', A.nextTarget(list, a, 'ArrowLeft') === undefined);
t('Home and End', A.nextTarget(list, b, 'Home') === s1 && A.nextTarget(list, b, 'End') === out);
t('clip strings pin the top-right corner', A.clips(100, 50, 16, 'end').outer === 'inset(0px 0px calc(100% - 50px) calc(100% - 100px) round 16px)');
t('the inner clip is one pixel inside (the border)', A.clips(100, 50, 16, 'end').inner === 'inset(1px 1px calc(100% - 50px + 1px) calc(100% - 100px + 1px) round 15px)');
t('start alignment pins the top-left corner', A.clips(100, 50, 16, 'start').outer.startsWith('inset(0px calc(100% - 100px)') && A.clips(100, 50, 16, 'start').outer.includes('0px round 16px'));
process.exit(bad ? 1 : 0);
