const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('new facilitator selection enables teaching permissions without granting administrative modules', () => {
    let change;
    const fields = ['assessments', 'resources', 'announcements', 'qa_answer', 'staff', 'reports', 'attendance'].map(value => ({value, checked: false}));
    const role = {value: 'facilitator', addEventListener: (event, fn) => change = fn};
    const form = {dataset: {newAccountPermissions: 'assessments,resources,announcements,qa_answer'}, querySelector: () => role, querySelectorAll: () => fields};
    vm.runInNewContext(fs.readFileSync('public/account-permissions.js', 'utf8'), {document: {querySelector: () => form}});
    change();
    assert.deepEqual(fields.filter(field => field.checked).map(field => field.value), ['assessments', 'resources', 'announcements', 'qa_answer']);
    role.value = 'staff'; change();
    assert.equal(fields.some(field => field.checked), false);
});

test('existing account permissions are not overwritten by new-account defaults', () => {
    assert.doesNotThrow(() => vm.runInNewContext(fs.readFileSync('public/account-permissions.js', 'utf8'), {document: {querySelector: () => null}}));
});
