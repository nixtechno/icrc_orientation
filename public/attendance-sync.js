// Venue sync status line on the scanner pages, with an optional Sync now button.
(function () {
  const box = document.querySelector('[data-attendance-sync]');
  if (!box) return;
  const text = box.querySelector('[data-sync-text]');
  const form = box.querySelector('form[data-sync-now]');
  const button = form && form.querySelector('button');
  const clock = (iso) => iso ? new Intl.DateTimeFormat('en-GB', {hour: '2-digit', minute: '2-digit', timeZone: 'Africa/Lagos'}).format(new Date(iso)) : null;
  const plural = (n, word) => n + ' ' + word + (n === 1 ? '' : 's');

  function describe(s) {
    if (s.mode !== 'venue') {
      const r = s.received;
      return r ? 'Last venue upload at ' + clock(r.at) + ' from ' + r.origin + ' · ' + plural(r.records, 'record') + (r.problems ? ' · ' + plural(r.problems, 'problem') : '') : 'No venue uploads yet.';
    }
    const parts = [s.online === true ? 'Online' : s.online === false ? 'Offline' : 'Not synced yet'];
    parts.push(s.last_synced_at ? 'last synced ' + clock(s.last_synced_at) : 'never synced');
    parts.push(s.waiting + ' waiting');
    if (s.problems) parts.push(plural(s.problems, 'problem'));
    let line = parts.join(' · ');
    if (s.online === false && s.error) line += '. ' + s.error;
    if (s.online === true && Math.abs(s.clock_offset || 0) > 60) line += '. This computer\'s clock is ' + Math.round(Math.abs(s.clock_offset) / 60) + ' min off the live site; correct it so scan times are right.';
    return line;
  }

  function show(s) {
    text.textContent = describe(s);
    box.dataset.state = s.mode !== 'venue' ? 'online' : s.online === false ? 'offline' : s.problems ? 'problems' : 'online';
  }

  async function refresh() {
    try {
      const response = await fetch('/attendance/sync', {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
      if (response.ok) show(await response.json());
    } catch (e) { /* The status line keeps its last value while this page cannot reach the server. */ }
  }

  if (form) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      button.disabled = true;
      text.textContent = 'Syncing with the live site…';
      try {
        const response = await fetch(form.action, {method: 'POST', body: new FormData(form), headers: {Accept: 'application/json'}, credentials: 'same-origin'});
        if (response.ok) show(await response.json()); else text.textContent = 'Sync could not start (error ' + response.status + ').';
      } catch (e) {
        text.textContent = 'Sync could not start. Check this computer is running the venue server.';
      }
      button.disabled = false;
    });
  }

  refresh();
  setInterval(refresh, 30000);
})();
