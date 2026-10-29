const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function mount(fetcher = async () => ({ok: true, json: async () => ({token: 'fresh-token'})})) {
    const handlers = {}, windowHandlers = {}, calls = [];
    const token = {value: 'expired-token'}, password = {value: 'Never copied or replayed'}, button = {textContent: 'Sign in', disabled: false}, error = {hidden: true};
    const attributes = {};
    const form = {dataset: {sessionUrl: '/login/csrf-token'}, querySelector: selector => selector.includes('_token') ? token : selector.includes('error') ? error : button, addEventListener: (key, fn) => handlers[key] = fn, setAttribute: (key, val) => attributes[key] = val, removeAttribute: key => delete attributes[key]};
    let submitted = 0;
    vm.runInNewContext(fs.readFileSync('public/login.js', 'utf8'), {document: {querySelector: () => form}, window: {addEventListener: (key, fn) => windowHandlers[key] = fn}, AbortSignal: {timeout: () => ({})}, HTMLFormElement: {prototype: {submit() {submitted++;}}}, fetch: async (...args) => {calls.push(args); return fetcher(...args);}});
    return {form, button, error, token, password, attributes, handlers, windowHandlers, calls, get submitted() {return submitted;}, submit: () => handlers.submit({preventDefault() {}})};
}

test('stale login token is refreshed before one native POST and credentials are never sent in the refresh', async () => {
    const p = mount(); await p.submit();
    assert.equal(p.token.value, 'fresh-token'); assert.equal(p.submitted, 1);
    assert.equal(p.calls[0][0], '/login/csrf-token');
    assert.equal(p.calls[0][1].credentials, 'same-origin'); assert.equal(p.calls[0][1].cache, 'no-store');
    assert.equal(p.calls[0][1].body, undefined); assert.equal(p.password.value, 'Never copied or replayed');
});

test('repeated clicks while refreshing submit only once', async () => {
    let resolve; const p = mount(() => new Promise(done => resolve = done));
    const first = p.submit(); await p.submit();
    assert.equal(p.calls.length, 1); assert.equal(p.button.disabled, true);
    resolve({ok: true, json: async () => ({token: 'new'})}); await first;
    assert.equal(p.submitted, 1);
});

test('network failure preserves the form, does not submit, and permits retry', async () => {
    const p = mount(async () => {throw new Error('Offline');}); await p.submit();
    assert.equal(p.submitted, 0); assert.equal(p.button.disabled, false); assert.equal(p.error.hidden, false);
    assert.match(p.error.textContent, /Check your connection/); assert.equal(p.token.value, 'expired-token');
    await p.submit(); assert.equal(p.calls.length, 2);
});

test('invalid token-refresh responses cannot cause an unprotected login POST', async () => {
    for (const response of [{ok: false}, {ok: true, redirected: true}, {ok: true, json: async () => ({})}]) {
        const p = mount(async () => response); await p.submit();
        assert.equal(p.submitted, 0); assert.equal(p.error.hidden, false);
    }
});

test('restoring the login form from browser history resets its submission state', async () => {
    const p = mount(); await p.submit(); p.windowHandlers.pageshow({persisted: true});
    assert.equal(p.button.disabled, false); assert.equal(p.button.textContent, 'Sign in');
    await p.submit(); assert.equal(p.calls.length, 2);
});
