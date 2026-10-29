const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function mount() {
    const handlers = {}, windowHandlers = {}, attrs = {}, button = {textContent: 'Submit final answers', disabled: false};
    const form = {querySelector: () => button, addEventListener: (event, fn) => handlers[event] = fn, setAttribute: (key, value) => attrs[key] = value, removeAttribute: key => delete attrs[key]};
    vm.runInNewContext(fs.readFileSync('public/submission.js', 'utf8'), {document: {querySelector: () => form}, window: {addEventListener: (event, fn) => windowHandlers[event] = fn}});
    return {button, attrs, restore: () => windowHandlers.pageshow({persisted: true}), submit: () => {let blocked = false; handlers.submit({preventDefault() {blocked = true;}}); return blocked;}};
}

test('answer form permits the first POST and blocks repeated clicks while submitting', () => {
    const p = mount(); assert.equal(p.submit(), false); assert.equal(p.button.disabled, true);
    assert.equal(p.attrs['aria-busy'], 'true'); assert.equal(p.submit(), true);
});

test('returning through browser history restores the submission button', () => {
    const p = mount(); p.submit(); p.restore();
    assert.equal(p.button.disabled, false); assert.equal(p.button.textContent, 'Submit final answers');
    assert.equal(p.attrs['aria-busy'], undefined); assert.equal(p.submit(), false);
});
