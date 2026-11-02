(() => {
 'use strict';
 const form = document.querySelector('[data-attendance-scanner]');
 if (!form) return;
 const input = form.querySelector('[name="code"]'), button = form.querySelector('button'), result = document.querySelector('[data-scanner-result]');

 // Scanner sounds made in the browser, so there is no audio file to load: the chosen success sound when a scan is
 // recorded, a low double buzz when it is rejected. Browsers only allow sound after the first key press or tap.
 let audio = null;
 const choice = document.querySelector('[data-scanner-sound-choice]');
 const stored = () => { try { return localStorage.getItem('scanner-sound'); } catch { return null; } };
 if (choice && stored() && [...choice.options].some(option => option.value === stored())) choice.value = stored();
 choice?.addEventListener('change', () => { try { localStorage.setItem('scanner-sound', choice.value); } catch {} beep(true); input.focus(); });
 const sound = () => {
  const Context = window.AudioContext || window.webkitAudioContext;
  if (!Context) return null;
  audio ??= new Context();
  if (audio.state === 'suspended') audio.resume().catch(() => {});
  return audio;
 };
 // A tone that rises quickly and either holds then stops (flat) or fades away like a bell (ring).
 const tone = (context, start, frequency, length, type, volume, ring = false) => {
  const oscillator = context.createOscillator(), gain = context.createGain();
  oscillator.type = type; oscillator.frequency.value = frequency;
  gain.gain.setValueAtTime(0.0001, start);
  gain.gain.exponentialRampToValueAtTime(volume, start + 0.008);
  if (!ring) gain.gain.setValueAtTime(volume, start + length - 0.03);
  gain.gain.exponentialRampToValueAtTime(0.0001, start + length);
  oscillator.connect(gain).connect(context.destination);
  oscillator.start(start); oscillator.stop(start + length + 0.02);
 };
 const successes = {
  chime: (c, t) => { tone(c, t, 1047, 0.35, 'sine', 0.5, true); tone(c, t + 0.12, 1568, 0.5, 'sine', 0.45, true); },
  gentle: (c, t) => tone(c, t, 880, 0.16, 'sine', 0.55),
  blip: (c, t) => { tone(c, t, 1400, 0.07, 'triangle', 0.5); tone(c, t + 0.11, 1400, 0.07, 'triangle', 0.5); },
  sharp: (c, t) => tone(c, t, 2700, 0.18, 'square', 0.9),
  off: () => {},
 };
 const beep = accepted => {
  const context = sound();
  if (!context) return;
  const now = context.currentTime + 0.01;
  if (accepted) (successes[choice?.value] ?? successes.chime)(context, now);
  else { tone(context, now, 220, 0.2, 'sawtooth', 0.8); tone(context, now + 0.26, 220, 0.2, 'sawtooth', 0.8); }
 };
 ['keydown', 'pointerdown'].forEach(type => document.addEventListener(type, sound, {once: true, capture: true}));
 document.querySelector('[data-scanner-sound]')?.addEventListener('click', () => { beep(true); input.focus(); });

 let busy = false;
 form.addEventListener('submit', async event => {
  event.preventDefault();
  if (busy || !input.value.trim()) return;
  busy = true;
  const data = new FormData(form);
  input.readOnly = true; if (button) button.disabled = true;
  result.className = 'muted'; result.textContent = 'Recording attendance…';
  try {
   const response = await fetch(form.action, {method:'POST', body:data, credentials:'same-origin', headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
   if (response.redirected || response.status === 401 || response.status === 419) throw new Error('Your session has expired. Reload this page and sign in again.');
   const payload = await response.json();
   if (!response.ok) throw new Error(payload.message || 'Attendance could not be recorded (error '+response.status+').');
   result.className = 'notice'; result.textContent = payload.message;
   beep(true);
  } catch (error) {
   result.className = 'errors'; result.textContent = error instanceof TypeError ? 'Connection lost. Check your connection, then scan the badge again. Duplicate attendance is prevented.' : error.message;
   beep(false);
  } finally {
   input.value = ''; input.readOnly = false; if (button) button.disabled = false; busy = false; input.focus();
  }
 });
})();
