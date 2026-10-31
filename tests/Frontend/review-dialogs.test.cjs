const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup(hash = '') {
    const dialog = id => ({id, open:false, focused:false, matches:selector => selector === 'dialog.review-dialog', showModal() { this.open = true; }, close() { this.open = false; },
        querySelector:() => ({focus() { dialog.focused = true; }}), getBoundingClientRect:() => ({left:100, right:500, top:100, bottom:500})});
    const dialogs = {'submission-a':dialog('submission-a'), 'submission-b':dialog('submission-b')};
    const listeners = {};
    const document = {
        getElementById:id => dialogs[id] || null,
        querySelectorAll:() => Object.values(dialogs).filter(d => d.open),
        addEventListener:(type, fn) => { listeners[type] = fn; },
    };
    vm.runInNewContext(fs.readFileSync('public/review-dialogs.js', 'utf8'), {document, location:{hash, href:'http://x/activities/a'+hash}, URL, decodeURIComponent});
    const click = (target, extra = {}) => { let prevented = false; listeners.click({target, button:0, preventDefault() { prevented = true; }, ...extra}); return prevented; };
    const link = href => ({href, closest:selector => selector === '[data-review-open]' ? {href} : null});
    return {dialogs, click, link};
}

test('a review link opens only its own dialog and close buttons shut it', () => {
    const ui = setup();
    assert.equal(ui.click(ui.link('http://x/activities/a#submission-a')), true);
    assert.equal(ui.dialogs['submission-a'].open, true);
    ui.click(ui.link('http://x/activities/a#submission-b'));
    assert.equal(ui.dialogs['submission-a'].open, false); assert.equal(ui.dialogs['submission-b'].open, true);
    const dialog = ui.dialogs['submission-b'];
    ui.click({closest:selector => selector === '[data-review-close]' ? {} : (selector === 'dialog' ? dialog : null)});
    assert.equal(dialog.open, false);
});

test('arriving with a submission hash opens that review, and unknown hashes are ignored', () => {
    assert.equal(setup('#submission-b').dialogs['submission-b'].open, true);
    const ui = setup('#missing');
    assert.equal(Object.values(ui.dialogs).some(d => d.open), false);
    assert.equal(ui.click(ui.link('http://x/activities/a#nothing')), false);
});

test('clicking the backdrop closes the dialog but clicks inside it do not', () => {
    const ui = setup('#submission-a'); const dialog = ui.dialogs['submission-a'];
    const target = {...dialog, closest:() => null, matches:selector => selector === 'dialog.review-dialog'};
    target.close = () => dialog.close();
    ui.click(target, {clientX:300, clientY:300}); assert.equal(dialog.open, true);
    ui.click(target, {clientX:20, clientY:20}); assert.equal(dialog.open, false);
});
