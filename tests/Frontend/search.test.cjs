const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const tick = () => new Promise(setImmediate);

function mount({path = '/manage/staff', searchable = true, fetcher} = {}) {
    const handlers = {}, windowHandlers = {}, calls = [], timers = new Map(), changes = [];
    let nextTimer = 0;
    const location = {href: 'http://localhost'+path, pathname: path, origin: 'http://localhost'};
    const viewport = {scrollTop: 120}, status = {setAttribute() {}};
    const makeResults = value => ({childNodes: [value], replaceChildren(...nodes) {this.childNodes = nodes;}});
    const main = {dataset: {}, attrs: {}, results: searchable ? makeResults('original') : null, setAttribute(key, value) {this.attrs[key] = value;}, removeAttribute(key) {delete this.attrs[key];}, querySelector() {return this.results;}, querySelectorAll() {return [form];}, closest() {return viewport;}, replaceChildren(...nodes) {this.results = nodes[0].results;}};
    const fields = [{name: 'q', type: 'text', value: ''}, {name: 'role', type: 'select-one', value: ''}];
    const form = {elements: fields, fields, kind: 'local', getAttribute: () => null, hasAttribute: () => false, matches(selector) {return selector === (this.kind === 'global' ? 'form.portal-search' : 'form.filters');}};
    const globalForm = {...form, kind: 'global', fields: [{name: 'q', value: 'PPP'}], getAttribute: () => '/timetable'};
    const document = {getElementById: () => main, body: {classList: {contains: () => true}}, createElement: () => status, querySelector: () => ({after() {}}), querySelectorAll: () => [], dispatchEvent() {}, addEventListener: (type, fn) => handlers[type] = fn};
    const move = (mode, state, title, url) => {changes.push({mode, url: url.href}); Object.assign(location, {href: url.href, pathname: url.pathname});};
    const response = value => ({ok: true, text: async () => value});
    vm.runInNewContext(fs.readFileSync('public/search.js', 'utf8'), {
        document, location, URL, URLSearchParams, AbortController, CustomEvent: class {},
        FormData: class {constructor(f) {this.f = f;} *[Symbol.iterator]() {for (const field of this.f.fields) yield [field.name, field.value];}},
        DOMParser: class {parseFromString(value) {const results = makeResults(value); return {getElementById: () => ({querySelector: () => results, childNodes: [{results}]})};}},
        fetch: async (url, options) => {calls.push({url, options}); return fetcher ? fetcher(url, options) : response('filtered');},
        history: {pushState: (...args) => move('push', ...args), replaceState: (...args) => move('replace', ...args)},
        window: {addEventListener: (type, fn) => windowHandlers[type] = fn, location: {assign: url => changes.push({navigate: url}), reload: () => changes.push({reload: true})}},
        setTimeout: fn => {timers.set(++nextTimer, fn); return nextTimer;}, clearTimeout: id => timers.delete(id),
    });
    return {main, form, globalForm, fields, status, calls, changes, location, viewport,
        submit(f = form) {let prevented = false; handlers.submit({target: f, preventDefault() {prevented = true;}}); return prevented;},
        input(f = form) {handlers.input({target: {closest: () => f, matches: () => true}});},
        runTimers() {const pending = [...timers.values()]; timers.clear(); pending.forEach(fn => fn());},
        pop: () => windowHandlers.popstate(), response,
    };
}

test('search updates results without navigating, preserves filters, and records a shareable URL', async () => {
    const p = mount(); p.fields[0].value = 'Amina'; p.fields[1].value = 'staff';
    assert.equal(p.submit(), true); await tick();
    assert.equal(p.main.results.childNodes[0], 'filtered');
    assert.match(p.calls[0].url.search, /q=Amina/); assert.match(p.calls[0].url.search, /role=staff/);
    assert.equal(p.calls[0].options.credentials, 'same-origin');
    assert.equal(p.changes[0].mode, 'push'); assert.equal(p.changes.some(x => x.navigate), false);
    assert.equal(p.fields[0].value, 'Amina'); assert.equal(p.viewport.scrollTop, 120);
});

test('typing is debounced and aborts stale requests before their results can replace the new query', async () => {
    const pending = []; const p = mount({fetcher: () => new Promise(resolve => pending.push(resolve))});
    p.fields[0].value = 'A'; p.input(); p.fields[0].value = 'Ami'; p.input();
    assert.equal(p.calls.length, 0); p.runTimers(); assert.equal(p.calls.length, 1);
    p.fields[0].value = 'Amina'; p.input(); assert.equal(p.calls[0].options.signal.aborted, true);
    p.runTimers(); assert.equal(p.calls.length, 2);
    pending[1](p.response('new results')); await tick();
    pending[0](p.response('old results')); await tick();
    assert.equal(p.main.results.childNodes[0], 'new results');
});

test('failed searches preserve current results and show an accessible retry message', async () => {
    const p = mount({fetcher: async () => ({ok: false})}); p.submit(); await tick();
    assert.equal(p.main.results.childNodes[0], 'original'); assert.match(p.status.textContent, /Try searching again/);
    assert.equal(p.main.attrs['aria-busy'], undefined); assert.equal(p.changes.length, 0);
});

test('top-bar search opens timetable content in place from a dashboard', async () => {
    const p = mount({path: '/dashboard', searchable: false}); p.submit(p.globalForm); await tick();
    assert.equal(p.main.dataset.searchPath, '/timetable'); assert.equal(p.location.pathname, '/timetable');
    assert.equal(p.main.results.childNodes[0], 'filtered'); assert.equal(p.viewport.scrollTop, 0);
});

test('returning to a non-search page through history performs a safe regular navigation', async () => {
    const p = mount({path: '/dashboard', searchable: false}); p.submit(p.globalForm); await tick();
    p.location.pathname = '/dashboard'; p.location.href = 'http://localhost/dashboard'; p.pop();
    assert.equal(p.changes.at(-1).reload, true);
});

test('history restores filters and results on a searchable page', async () => {
    const p = mount(); p.location.href = 'http://localhost/manage/staff?q=Tunde&role=staff'; p.pop(); await tick();
    assert.equal(p.fields[0].value, 'Tunde'); assert.equal(p.fields[1].value, 'staff');
    assert.equal(p.main.results.childNodes[0], 'filtered'); assert.equal(p.changes.length, 0);
});

test('ordinary data-changing forms are not intercepted', () => {
    const p = mount(); const other = {matches: () => false}; assert.equal(p.submit(other), false); assert.equal(p.calls.length, 0);
});
