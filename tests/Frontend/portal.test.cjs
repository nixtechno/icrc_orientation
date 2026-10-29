const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function mount(mobile = false) {
    let document;
    class Element {
        constructor() { this.handlers = {}; this.attributes = {}; this.classes = new Set(); this.inert = false; this.hidden = true; this._open = false; this.classList = {add: x => this.classes.add(x), remove: x => this.classes.delete(x), toggle: (x, on) => on ? this.classes.add(x) : this.classes.delete(x)}; }
        addEventListener(event, callback) { (this.handlers[event] ??= []).push(callback); }
        fire(event, values = {}) { const e = {target: this, key: '', preventDefault() { this.prevented = true; }, stopPropagation() {}, ...values}; (this.handlers[event] ?? []).forEach(fn => fn(e)); return e; }
        setAttribute(key, value) { this.attributes[key] = value; }
        getAttribute(key) { return this.attributes[key]; }
        focus() { document.activeElement = this; }
        matches() { return false; }
        set open(value) { this._open = value; this.fire('toggle'); }
        get open() { return this._open; }
    }
    document = new Element(); document.documentElement = new Element();
    const trigger = new Element(), account = new Element(), options = Array.from({length: 5}, () => new Element());
    account.querySelector = () => trigger; account.querySelectorAll = () => options;
    account.contains = el => el === account || el === trigger || options.includes(el);
    const toggle = new Element(), sidebar = new Element(), scrim = new Element(), search = new Element();
    toggle.setAttribute('aria-expanded', 'false');
    const navigation = [new Element(), new Element(), new Element()];
    sidebar.querySelector = () => navigation[0]; sidebar.querySelectorAll = () => navigation;
    document.querySelectorAll = () => [];
    document.querySelector = selector => ({'.portal-account': account, '.portal-menu': toggle, '.portal-sidebar': sidebar, '.portal-scrim': scrim, '.portal-search input': search})[selector];
    const media = new Element(); media.matches = mobile;
    vm.runInNewContext(fs.readFileSync('public/portal.js', 'utf8'), {document, window: {matchMedia: () => media}, setTimeout});
    return {document, account, trigger, options, toggle, sidebar, scrim, search, navigation, media};
}

test('avatar menu supports arrow navigation, wrapping, Home, End and Escape focus restoration', () => {
    const p = mount(); p.trigger.focus();
    p.account.fire('keydown', {key: 'ArrowDown'});
    assert.equal(p.account.open, true); assert.equal(p.trigger.getAttribute('aria-expanded'), 'true');
    assert.equal(p.document.activeElement, p.options[0]);
    p.account.fire('keydown', {key: 'ArrowUp'}); assert.equal(p.document.activeElement, p.options.at(-1));
    p.account.fire('keydown', {key: 'Home'}); assert.equal(p.document.activeElement, p.options[0]);
    p.account.fire('keydown', {key: 'End'}); assert.equal(p.document.activeElement, p.options.at(-1));
    p.account.fire('keydown', {key: 'Escape'}); assert.equal(p.account.open, false);
    assert.equal(p.trigger.getAttribute('aria-expanded'), 'false'); assert.equal(p.document.activeElement, p.trigger);
});

test('outside click and tabbing out dismiss the avatar menu while internal clicks do not', async () => {
    const p = mount(); p.account.open = true;
    p.document.fire('click', {target: p.options[0]}); assert.equal(p.account.open, true);
    p.document.fire('click', {target: p.search}); assert.equal(p.account.open, false);
    p.account.open = true; p.search.focus(); p.account.fire('focusout');
    await new Promise(resolve=>setTimeout(resolve,5)); assert.equal(p.account.open, false);
});

test('moving focus from the avatar trigger to Sign out does not close the menu before the click can submit',async()=>{
    const p=mount();p.account.open=true;p.trigger.focus();
    p.document.activeElement=p.document;
    p.account.fire('focusout',{relatedTarget:p.options.at(-1)});
    await new Promise(resolve=>setTimeout(resolve,5));
    assert.equal(p.account.open,true);
    p.options.at(-1).focus();
    assert.equal(p.options.at(-1).fire('click').prevented,undefined);
    assert.equal(p.account.open,true);
});

test('browsers without a focusout related target get time to settle on a menu control',async()=>{
    const p=mount();p.account.open=true;p.document.activeElement=p.document;
    p.account.fire('focusout');p.options.at(-1).focus();
    await new Promise(resolve=>setTimeout(resolve,5));
    assert.equal(p.account.open,true);
});

test('mobile navigation opens accessibly and Escape restores focus without leaving a scrim', () => {
    const p = mount(true); assert.equal(p.sidebar.inert, true);
    p.toggle.fire('click'); assert.equal(p.sidebar.inert, false); assert.equal(p.scrim.hidden, false);
    assert.equal(p.document.activeElement, p.navigation[0]); assert.equal(p.toggle.getAttribute('aria-expanded'), 'true');
    p.document.fire('keydown', {key: 'Escape'}); assert.equal(p.sidebar.inert, true);
    assert.equal(p.scrim.hidden, true); assert.equal(p.document.activeElement, p.toggle);
});

test('mobile drawer traps Tab at both boundaries and dismisses on backdrop click', () => {
    const p = mount(true); p.toggle.fire('click'); p.navigation.at(-1).focus();
    p.document.fire('keydown', {key: 'Tab'}); assert.equal(p.document.activeElement, p.navigation[0]);
    p.document.fire('keydown', {key: 'Tab', shiftKey: true}); assert.equal(p.document.activeElement, p.navigation.at(-1));
    p.scrim.fire('click'); assert.equal(p.scrim.hidden, true); assert.equal(p.sidebar.inert, true);
});

test('responsive changes close the drawer and keep desktop navigation keyboard accessible', () => {
    const p = mount(true); p.toggle.fire('click'); p.media.matches = false; p.media.fire('change');
    assert.equal(p.scrim.hidden, true); assert.equal(p.sidebar.inert, false);
    assert.equal(p.toggle.getAttribute('aria-expanded'), 'false');
});

test('search keyboard shortcut focuses search without stealing typed slashes from form fields', () => {
    const p = mount(); p.document.fire('keydown', {key: '/'}); assert.equal(p.document.activeElement, p.search);
    const input = {matches: () => true}; p.document.activeElement = input;
    p.document.fire('keydown', {key: '/', target: input}); assert.equal(p.document.activeElement, input);
    p.document.fire('keydown', {key: '/', ctrlKey: true}); assert.equal(p.document.activeElement, input);
});
