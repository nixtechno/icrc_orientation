(() => {
 const target = document.querySelector('[data-live-attendance]');
 const indicator = document.querySelector('[data-live-indicator]');
 if (!target || !indicator) return;
 const filter = target.closest('section')?.querySelector('[data-live-filter]');
 const summarySlot = target.querySelector?.('[data-live-summary-slot]');
 const tableSlot = target.querySelector?.('[data-live-table-slot]');
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
   if (summarySlot && tableSlot) {
    const page = new DOMParser().parseFromString(html, 'text/html');
    const summary = page.querySelector('[data-live-summary-fragment]');
    const table = page.querySelector('[data-live-table-fragment]');
    if (!summary || !table) throw new Error('Unavailable');
    summarySlot.innerHTML = summary.innerHTML;
    tableSlot.innerHTML = table.innerHTML;
   } else target.innerHTML = html;
   indicator.textContent = '';
   indicator.hidden = true;
  } catch (error) {
   if (id === revision && error.name !== 'AbortError') {
    indicator.textContent = 'Attendance could not be updated. Displayed records may be out of date.';
    indicator.hidden = false;
   }
  } finally { if (id === revision) running = false; }
 }
 const apply = (history = true, resetPage = true) => {
  clearTimeout(timer);
  const params = new URLSearchParams(new FormData(filter));
  if (!resetPage && new URLSearchParams(location.search).has('attendance_page')) params.set('attendance_page', new URLSearchParams(location.search).get('attendance_page'));
  const endpoint = new URL(target.dataset.url, location.href); endpoint.search = params.toString();
  target.dataset.url = endpoint.href;
  const url = new URL(location.href);
  url.searchParams.delete('attendance_page');
  for (const [key, value] of params) { if (value) url.searchParams.set(key, value); else url.searchParams.delete(key); }
  if (history && url.href !== location.href) window.history.pushState({attendanceSearch: true}, '', url);
  refresh(true);
 };
 target.addEventListener?.('click', event => {
  const link = event.target.closest('[data-live-table-link]');
  if (!link || event.button > 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
  event.preventDefault();
  clearTimeout(timer);
  const endpoint = new URL(link.href, location.href);
  target.dataset.url = endpoint.href;
  if (filter) for (const field of filter.elements) if (field.name && endpoint.searchParams.has(field.name)) field.value = endpoint.searchParams.get(field.name);
  const url = new URL(location.href);
  for (const key of ['attendance_page','sort','direction']) {
   if (endpoint.searchParams.has(key)) url.searchParams.set(key, endpoint.searchParams.get(key));
   else url.searchParams.delete(key);
  }
  window.history.pushState({attendanceSearch:true}, '', url);
  refresh(true);
 });
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
  for (const field of filter.elements) if (field.name) field.value = params.get(field.name) || ({per_page:'10',sort:'name',direction:'asc'}[field.name] || '');
  apply(false, false);
 });
 refresh(); window.setInterval(refresh, 15000); document.addEventListener('visibilitychange', () => refresh());
})();
