// Registers the service worker and offers to install the portal as an app.
// Android and desktop browsers show their own install prompt; iPhone and iPad need Share → Add to Home Screen.
(() => {
  'use strict';
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
  }

  const installed = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  const apple = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  let prompt = null;

  const appleSteps = () => {
    let dialog = document.querySelector('[data-install-steps]');
    if (!dialog) {
      dialog = document.createElement('dialog');
      dialog.className = 'install-steps';
      dialog.dataset.installSteps = '';
      dialog.setAttribute('aria-labelledby', 'install-steps-title');
      dialog.innerHTML = '<h2 id="install-steps-title">Install the SCODEX app</h2>'
        + '<ol><li>Open this page in <strong>Safari</strong>.</li><li>Tap the <strong>Share</strong> button (the square with an arrow).</li>'
        + '<li>Choose <strong>Add to Home Screen</strong>, then <strong>Add</strong>.</li><li>Open <strong>SCODEX 2026</strong> from your home screen and sign in.</li></ol>'
        + '<p class="muted">The app opens without the browser bars, which gives assessments the whole screen.</p>'
        + '<button type="button" data-install-close>Done</button>';
      dialog.querySelector('[data-install-close]').addEventListener('click', () => dialog.close());
      document.body.append(dialog);
    }
    dialog.showModal();
  };

  const ready = () => {
    const button = document.querySelector('[data-install-app]');
    if (!button || installed) return;
    if (apple) button.hidden = false;
    window.addEventListener('beforeinstallprompt', (event) => {
      event.preventDefault();
      prompt = event;
      button.hidden = false;
    });
    window.addEventListener('appinstalled', () => { button.hidden = true; prompt = null; });
    button.addEventListener('click', async () => {
      if (prompt) {
        prompt.prompt();
        const choice = await prompt.userChoice.catch(() => null);
        if (choice?.outcome === 'accepted') button.hidden = true;
        prompt = null;
      } else if (apple) {
        appleSteps();
      }
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready); else ready();
})();
