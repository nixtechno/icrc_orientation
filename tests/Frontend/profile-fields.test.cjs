const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

test('role changes hide and disable staff sections and enable only facilitator contact inputs', () => {
    let change;
    const fields = [{disabled:false},{disabled:false}];
    const staff = {tagName:'FIELDSET',dataset:{profileRoleSection:'staff'},hidden:false,disabled:false};
    const contact = {tagName:'DIV',dataset:{profileRoleSection:'facilitator'},hidden:true,querySelectorAll:()=>fields};
    const role = {value:'staff',addEventListener:(type,fn)=>change=fn};
    const root = {dataset:{profileRole:'staff'},closest:()=>({querySelector:()=>role}),querySelectorAll:()=>[staff,contact]};
    const document = {querySelectorAll:s=>s==='[data-profile-fields]'?[root]:[],addEventListener(){}};
    vm.runInNewContext(fs.readFileSync('public/profile-fields.js','utf8'),{document});
    assert.equal(contact.hidden,true);assert.equal(fields[0].disabled,true);
    role.value='facilitator';change();
    assert.equal(staff.hidden,true);assert.equal(staff.disabled,true);assert.equal(contact.hidden,false);assert.equal(fields[0].disabled,false);
    role.value='staff';change();assert.equal(staff.hidden,false);assert.equal(staff.disabled,false);assert.equal(fields[0].disabled,true);
});

test('activation profiles without a role picker retain their assigned facilitator fields', () => {
    const section = {tagName:'DIV',dataset:{profileRoleSection:'facilitator'},querySelectorAll:()=>[]};
    const root = {dataset:{profileRole:'facilitator'},closest:()=>({querySelector:()=>null}),querySelectorAll:()=>[section]};
    vm.runInNewContext(fs.readFileSync('public/profile-fields.js','utf8'),{document:{querySelectorAll:s=>s==='[data-profile-fields]'?[root]:[],addEventListener(){}}});
    assert.equal(section.hidden,false);
});

test('linking an invited account locks the facilitator directory email and uses the invited address', () => {
    let change;
    const account = {selectedOptions:[{dataset:{emailLocked:'1',email:'invited@example.test'}}],addEventListener:(type,fn)=>change=fn};
    const email = {value:'other@example.test',readOnly:false};
    const form = {dataset:{emailLocked:'0'},querySelector:s=>s==='[name="user_id"]'?account:email};
    vm.runInNewContext(fs.readFileSync('public/profile-fields.js','utf8'),{document:{querySelectorAll:s=>s==='[data-facilitator-form]'?[form]:[],addEventListener(){}}});
    assert.equal(email.readOnly,true);assert.equal(email.value,'invited@example.test');
    account.selectedOptions=[{dataset:{}}];change();assert.equal(email.readOnly,false);
});
