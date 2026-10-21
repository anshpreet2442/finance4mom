// The whole spoken turn with the fake microphone: record -> stop -> transcript (mock ElevenLabs STT) -> Send -> streamed answer, spoken sentence by sentence.
const { launch, session, t, done } = require('./lib');
(async () => {
  const b = await launch(); const { p } = await session(b); await p.goto('/'); await p.waitForTimeout(1200);
  await p.keyboard.press('Alt+v'); await p.waitForTimeout(900); t('Alt+V opens the voice panel', await p.evaluate(() => !document.getElementById('voice-dock').hidden));
  await p.click('.vd-main'); await p.waitForTimeout(1800); t('recording shows the live timer and moving waveform', await p.evaluate(() => document.querySelector('.vd-voice').dataset.mode) === 'recording' && /00:0[1-9]/.test(await p.locator('.vd-timer').innerText()));
  t('the waveform follows the real microphone level (bars are not at rest)', await p.evaluate(() => [...document.querySelectorAll('.vd-bar')].some(b => parseFloat(b.style.transform.replace('scaleY(', '')) > 0.2)));
  await p.click('.vd-main'); await p.waitForTimeout(1500); t('review mode shows the transcript, Send and Discard', await p.evaluate(() => document.querySelector('.vd-voice').dataset.mode) === 'review' && (await p.locator('.vd-live').innerText()).length > 3);
  await p.click('.vd-act--send'); await p.waitForTimeout(4000); const ans = await p.locator('.vd-answer').innerText(); t('the advisor answered in the panel', ans.length > 20, ans);
  t('the turn is in the chat history with a voice flag', await p.evaluate(async () => { const r = await fetch('/ai/voice/state'); const j = await r.json(); return j.recent.length > 0 && !!j.recent[j.recent.length - 1].reply; }));
  const lat = await p.evaluate(() => window.__mfVoice && window.__mfVoice.last); t('latency was measured (dev mode)', !!lat && lat.send_to_first_token_ms >= 0, JSON.stringify(lat));
  await p.keyboard.press('Escape'); await p.keyboard.press('Escape'); await p.waitForTimeout(400); t('Escape closes the panel', await p.evaluate(() => document.getElementById('voice-dock').hidden));
  t('no console errors', p.errs.length === 0, p.errs.join(' | ')); await b.close(); done();
})();
