const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

class Element {
    constructor(dataset = {}) { this.dataset = dataset; this.attributes = {}; this.handlers = {}; this.hidden = false; this.textContent = ''; this.classes = new Set(); this.classList = {add: value => this.classes.add(value), remove: value => this.classes.delete(value), toggle: (value, active) => { if (active) this.classes.add(value); else this.classes.delete(value); }}; }
    setAttribute(key, value) { this.attributes[key] = value; }
    getAttribute(key) { return this.attributes[key]; }
    removeAttribute(key) { delete this.attributes[key]; }
    addEventListener(type, callback) { (this.handlers[type] ??= []).push(callback); }
    fire(type, values = {}) { const event = {preventDefault() {}, ...values}; for (const callback of this.handlers[type] ?? []) callback(event); }
    focus() { this.focused = true; }
}

function mount({reduced = false, now = '2026-11-01T23:00:00+01:00'} = {}) {
    const toggle = new Element(); toggle.setAttribute('aria-expanded', 'false');
    const navigation = new Element();
    const choices = Array.from({length: 6}, (_, i) => new Element({week: String(i + 1)}));
    const panels = Array.from({length: 6}, (_, i) => new Element({weekPanel: String(i + 1)}));
    const link = new Element({openWeek: '4'});
    const root = new Element();
    const explorer = {querySelectorAll: selector => selector === '[data-week]' ? choices : panels};
    const status = new Element();
    const units = Object.fromEntries(['days', 'hours', 'minutes', 'seconds'].map(unit => [unit, new Element()]));
    const countdown = new Element({start: '2026-11-02T00:00:00+01:00', end: '2026-12-11T23:59:59+01:00'});
    countdown.querySelector = selector => units[/data-unit="(\w+)"/.exec(selector)[1]];
    const document = new Element(); document.documentElement = root;
    document.querySelector = selector => ({'.menu-toggle': toggle, '[data-countdown]': countdown, '[data-programme-status]': status})[selector];
    document.getElementById = () => navigation;
    document.querySelectorAll = selector => ({'[data-week-explorer]': [explorer], '[data-open-week]': [link], '.reveal': []})[selector] ?? [];
    let observers = 0; let clears = 0;
    const sandbox = {document, Date: {parse: Date.parse, now: () => Date.parse(now)}, clearInterval: () => clears++, window: {matchMedia: () => ({matches: reduced}), location: {hash: ''}, setInterval: () => 1}};
    sandbox.IntersectionObserver = class {constructor() { observers++; } observe() {} unobserve() {}};
    sandbox.window.IntersectionObserver = sandbox.IntersectionObserver;
    vm.runInNewContext(fs.readFileSync('public/programme.js', 'utf8'), sandbox);
    return {toggle, navigation, choices, panels, link, root, status, units, countdown, document, get observers() {return observers;}, get clears() {return clears;}};
}

test('week selections and experience links open the requested chapter', () => {
    const page = mount();
    assert.equal(page.panels.filter(panel => !panel.hidden).length, 1);
    page.choices[5].fire('click');
    assert.equal(page.panels[5].hidden, false);
    assert.equal(page.panels[0].hidden, true);
    assert.equal(page.choices[5].getAttribute('aria-current'), 'true');
    page.link.fire('click');
    assert.equal(page.panels[3].hidden, false);
});

test('keyboard navigation wraps between chapters and supports Home and End', () => {
    const page = mount();
    page.choices[0].fire('keydown', {key: 'ArrowLeft'});
    assert.equal(page.choices[5].focused, true);
    assert.equal(page.panels[5].hidden, false);
    page.choices[5].fire('keydown', {key: 'Home'});
    assert.equal(page.panels[0].hidden, false);
    page.choices[0].fire('keydown', {key: 'End'});
    assert.equal(page.panels[5].hidden, false);
});

test('mobile menu exposes its state and Escape restores focus', () => {
    const page = mount();
    page.toggle.fire('click');
    assert.equal(page.toggle.getAttribute('aria-expanded'), 'true');
    assert.equal(page.navigation.classes.has('is-open'), true);
    page.document.fire('keydown', {key: 'Escape'});
    assert.equal(page.toggle.getAttribute('aria-expanded'), 'false');
    assert.equal(page.navigation.classes.has('is-open'), false);
    assert.equal(page.toggle.focused, true);
});

test('countdown respects the Lagos offset and transitions through programme states', () => {
    const before = mount();
    assert.equal(before.units.hours.textContent, '01');
    assert.equal(before.units.days.textContent, '00');
    const during = mount({now: '2026-11-02T00:00:00+01:00'});
    assert.equal(during.status.textContent, 'The orientation programme is in progress.');
    const after = mount({now: '2026-12-12T00:00:00+01:00'});
    assert.equal(after.status.textContent, 'The 2026 orientation has concluded.');
    assert.equal(after.clears, 1);
});

test('reduced motion leaves content visible without reveal animations', () => {
    const page = mount({reduced: true});
    assert.equal(page.root.classes.has('motion-ready'), false);
    assert.equal(page.observers, 0);
});
