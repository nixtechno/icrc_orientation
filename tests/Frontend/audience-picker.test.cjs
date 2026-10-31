const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup(initial = 'all') {
    const node = extra => Object.assign({ value:'', dataset:{}, listeners:{}, hidden:false, addEventListener(type, fn) { this.listeners[type] = fn; } }, extra);
    const person = (text, checked = false) => { const input = node({checked}); return node({dataset:{searchText:text}, querySelector:() => input, input}); };
    const people = [person('amina bello scodex-1 ict', true), person('grace okafor scodex-2 training'), person('musa idris scodex-3 ict')];
    const mode = node({value:initial}), groupField = node(), group = node(), staffField = node(), search = node(), count = node(), empty = node({hidden:true});
    const fields = {'[name="assignment_mode"]':mode,'[data-group-field]':groupField,'[name="assessment_group_id"]':group,'[data-staff-field]':staffField,'[data-staff-search]':search,'[data-staff-count]':count,'[data-staff-empty]':empty};
    const root = node({querySelector:selector => fields[selector], querySelectorAll:() => people});
    const document = node({querySelectorAll:() => [root]});
    vm.runInNewContext(fs.readFileSync('public/audience-picker.js','utf8'), {document});
    return {mode,groupField,group,staffField,search,count,empty,people};
}

test('only the picker for the chosen audience is shown and submitted', () => {
    const ui = setup();
    assert.equal(ui.groupField.hidden, true); assert.equal(ui.group.disabled, true); assert.equal(ui.staffField.hidden, true); assert.equal(ui.staffField.disabled, true);
    ui.mode.value = 'group'; ui.mode.listeners.change();
    assert.equal(ui.groupField.hidden, false); assert.equal(ui.group.required, true); assert.equal(ui.staffField.disabled, true);
    ui.mode.value = 'selected'; ui.mode.listeners.change();
    assert.equal(ui.staffField.hidden, false); assert.equal(ui.staffField.disabled, false); assert.equal(ui.group.required, false); assert.equal(ui.group.disabled, true);
});

test('staff search matches every word, shows an empty message and keeps the selection count', () => {
    const ui = setup('selected');
    assert.equal(ui.count.textContent, '(1 selected)');
    ui.search.value = 'ICT musa'; ui.search.listeners.input();
    assert.deepEqual(ui.people.map(p => p.hidden), [true, true, false]); assert.equal(ui.empty.hidden, true);
    ui.search.value = 'nobody'; ui.search.listeners.input(); assert.equal(ui.empty.hidden, false);
    ui.people[2].input.checked = true; ui.staffField.listeners.change(); assert.equal(ui.count.textContent, '(2 selected)');
    let prevented = false; ui.search.listeners.keydown({key:'Enter', preventDefault(){ prevented = true; }}); assert.equal(prevented, true);
});
