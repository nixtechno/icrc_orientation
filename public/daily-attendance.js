(() => {
 const target = document.querySelector('[data-live-attendance]');
 const indicator = document.querySelector('[data-live-indicator]');
 if (!target || !indicator) return;
 const filter = target.closest('section')?.querySelector('[data-live-filter]');
 let running = false, controller, revision = 0, timer;
 async function refresh(force = false) {
  if (document.hidden || !target.isConnected || (running && !force)) return;
  controller?.abort(); controller = new AbortController();
  const id = ++revision; running = true;
  try {
   const response = await fetch(target.dataset.url, {headers: {'X-Requested-With': 'XMLHttpRequest'}, credentials: 'same-origin', cache: 'no-store', signal: controller.signal});
   if (!response.ok || response.redirected) throw new Error('Unavailable');
   const html = await response.text();
   if (id !== revision) return;
   target.innerHTML = html;
   indicator.textContent = 'Connected · Last updated ' + new Date().toLocaleTimeString() + ' · Refreshes every 15 seconds';
  } catch (error) {
   if (id === revision && error.name !== 'AbortError') indicator.textContent = 'Connection interrupted. Retrying every 15 seconds; displayed data may be out of date.';
  } finally { if (id === revision) running = false; }
 }
 const apply = (history = true) => {
  clearTimeout(timer);
  const params = new URLSearchParams(new FormData(filter));
  const endpoint = new URL(target.dataset.url, location.href); endpoint.search = params.toString();
  target.dataset.url = endpoint.href;
  const url = new URL(location.href);
  for (const [key, value] of params) { if (value) url.searchParams.set(key, value); else url.searchParams.delete(key); }
  if (history && url.href !== location.href) window.history.pushState({attendanceSearch: true}, '', url);
  indicator.textContent = 'Updating attendance results…';
  refresh(true);
 };
 filter?.addEventListener('submit', event => { event.preventDefault(); apply(); });
 filter?.addEventListener('change', event => { if (event.target.matches('select,input[type=date]')) apply(); });
 filter?.addEventListener('input', event => {
  if (!event.target.matches('input:not([type=date])')) return;
  clearTimeout(timer); controller?.abort(); revision++; running = false;
  timer = setTimeout(() => apply(), 350);
 });
 window.addEventListener('popstate', () => {
  if (!filter || !target.isConnected) return;
  const params = new URLSearchParams(location.search);
  for (const field of filter.elements) if (field.name) field.value = params.get(field.name) || '';
  apply(false);
 });
 refresh(); window.setInterval(refresh, 15000); document.addEventListener('visibilitychange', () => refresh());
})();
