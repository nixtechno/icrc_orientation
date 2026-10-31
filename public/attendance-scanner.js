(() => {
 'use strict';
 const form = document.querySelector('[data-attendance-scanner]');
 if (!form) return;
 const input = form.querySelector('[name="code"]'), button = form.querySelector('button'), result = document.querySelector('[data-scanner-result]');
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
  } catch (error) {
   result.className = 'errors'; result.textContent = error instanceof TypeError ? 'Connection lost. Check your connection, then scan the badge again. Duplicate attendance is prevented.' : error.message;
  } finally {
   input.value = ''; input.readOnly = false; if (button) button.disabled = false; busy = false; input.focus();
  }
 });
})();
