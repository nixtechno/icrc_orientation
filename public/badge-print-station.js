(() => {
 'use strict';
 const form = document.querySelector('[data-badge-print-station]');
 if (!form) return;
 const input = form.querySelector('[name="code"]'), result = document.querySelector('[data-print-result]'), frame = document.querySelector('[data-print-frame]');
 let busy = false;
 const show = (kind, text) => { result.className = kind; result.textContent = text; };
 const ready = () => { input.value = ''; input.readOnly = false; busy = false; input.focus(); };
 // Shrink long names and titles until they fit their box, as the badge editor does before printing.
 const fit = doc => doc.querySelectorAll('[data-badge-text]').forEach(block => {
  let size = parseFloat(block.style.fontSize) || 0;
  while (size > 1.7 && (block.scrollHeight > block.clientHeight + 1 || block.scrollWidth > block.clientWidth + 1)) { size -= .15; block.style.fontSize = size + 'mm'; }
 });
 const print = url => new Promise((resolve, reject) => {
  frame.onload = async () => {
   try {
    const doc = frame.contentDocument;
    await Promise.all([...doc.images].map(img => img.complete ? null : new Promise(done => { img.onload = img.onerror = done; })));
    if (doc.fonts?.ready) await doc.fonts.ready;
    fit(doc);
    frame.contentWindow.focus();
    frame.contentWindow.print(); // Opens the print dialog; the operator confirms or cancels there.
    resolve();
   } catch (error) { reject(error); }
  };
  frame.onerror = () => reject(new TypeError('Badge could not be loaded.'));
  frame.src = url;
 });
 form.addEventListener('submit', async event => {
  event.preventDefault();
  if (busy || !input.value.trim()) return;
  busy = true; input.readOnly = true;
  show('muted', 'Preparing the badge…');
  try {
   const response = await fetch(form.action, {method:'POST', body:new FormData(form), credentials:'same-origin', headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
   if (response.redirected || response.status === 401 || response.status === 419) throw new Error('Your session has expired. Reload this page and sign in again.');
   const payload = await response.json();
   if (!response.ok) throw new Error(payload.message || 'The badge could not be prepared (error '+response.status+').');
   show('notice', payload.message);
   await print(payload.print_url);
   show('notice', payload.name+' — done. Ready for the next scan.');
  } catch (error) {
   show('errors', error instanceof TypeError ? 'Connection lost. Check your connection, then scan the QR code again.' : error.message);
  } finally {
   ready();
  }
 });
})();
