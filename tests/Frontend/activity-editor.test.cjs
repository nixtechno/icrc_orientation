const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function setup(supported = true) {
    const node = extra => Object.assign({ value:'', dataset:{}, listeners:{}, addEventListener(type, fn) { this.listeners[type] = fn; }, setAttribute(key, value) { this[key] = value; }, dispatchEvent() {}, focus() {} }, extra);
    const week = node();
    const session = node({options:[node(),node({value:'s1',dataset:{week:'w1'}}),node({value:'s2',dataset:{week:'w2'}})]});
    const audience = node({value:'all'}), groupField = node(), group = node();
    const toggle = node(), instructions = node({value:'Existing instructions.',maxLength:20000}), status = node(), preview = node();
    const fields = {'[name="programme_week_id"]':week,'[name="session_id"]':session,'[name="assignment_mode"]':audience,'[data-group-field]':groupField,'[name="assessment_group_id"]':group,'[data-dictation-toggle]':toggle,'[name="instructions"]':instructions,'[data-dictation-status]':status,'[data-dictation-preview]':preview};
    const form = node({isConnected:true,querySelector:selector=>fields[selector],closest:()=>null});
    let recognition;
    class Recognition {
        constructor() { recognition = this; }
        start() { this.starts=(this.starts||0)+1; this.onstart(); }
        stop() { this.stopped=true; this.onend(); }
        abort() { this.aborted=true; this.onend(); }
    }
    const document = node({querySelectorAll:()=>[form],hidden:false});
    const window = node(supported ? {SpeechRecognition:Recognition} : {});
    vm.runInNewContext(fs.readFileSync('public/activity-editor.js','utf8'), {document, window, Event:class {}});
    return {week,session,audience,groupField,group,toggle,instructions,status,preview,form,document,window,get recognition(){return recognition;}};
}

test('sessions stay disabled until a week is chosen, filter by that week and clear on changes', () => {
    const ui=setup();
    assert.equal(ui.session.disabled,true);
    ui.week.value='w1';ui.week.listeners.change();
    assert.equal(ui.session.disabled,false);assert.equal(ui.session.options[1].disabled,false);assert.equal(ui.session.options[2].hidden,true);
    ui.session.value='s1';ui.week.value='w2';ui.week.listeners.change();
    assert.equal(ui.session.value,'');assert.equal(ui.session.options[1].disabled,true);assert.equal(ui.session.options[2].disabled,false);
    ui.week.value='';ui.week.listeners.change();assert.equal(ui.session.disabled,true);
});

test('group selection is required only for the group audience', () => {
    const ui=setup();assert.equal(ui.groupField.hidden,true);assert.equal(ui.group.disabled,true);
    ui.audience.value='group';ui.audience.listeners.change();assert.equal(ui.groupField.hidden,false);assert.equal(ui.group.required,true);
    ui.audience.value='all';ui.audience.listeners.change();assert.equal(ui.group.required,false);assert.equal(ui.group.disabled,true);
});

test('dictation appends final text once without replacing edits or adding interim speech', () => {
    const ui=setup();ui.toggle.listeners.click();assert.equal(ui.toggle['aria-pressed'],'true');
    const result=(text,final)=>Object.assign([{transcript:text}],{isFinal:final});
    const event={resultIndex:0,results:[result('Answer every question.',true),result('Still speaking',false)]};
    ui.recognition.onresult(event);
    assert.equal(ui.instructions.value,'Existing instructions. Answer every question.');assert.equal(ui.preview.textContent,'Still speaking');
    ui.recognition.onresult(event);assert.equal(ui.instructions.value,'Existing instructions. Answer every question.');
    ui.instructions.value+=' Manual edit.';
    ui.recognition.onresult({resultIndex:1,results:[event.results[0],result('Take your time.',true)]});
    assert.equal(ui.instructions.value,'Existing instructions. Answer every question. Manual edit. Take your time.');
    ui.toggle.listeners.click();assert.equal(ui.toggle['aria-pressed'],'false');assert.equal(ui.preview.textContent,'');
});

test('dictation errors restore controls, preserve text and explain microphone permission failures', () => {
    const ui=setup();ui.toggle.listeners.click();ui.recognition.onerror({error:'not-allowed'});ui.recognition.onend();
    assert.equal(ui.toggle['aria-pressed'],'false');assert.match(ui.status.textContent,/denied/);assert.equal(ui.instructions.value,'Existing instructions.');
    ui.toggle.listeners.click();assert.equal(ui.recognition.starts,2);
});

test('submitting while dictating stops recording for review and page navigation aborts the microphone', () => {
    const ui=setup();ui.toggle.listeners.click();let prevented=false;
    ui.form.listeners.submit({preventDefault(){prevented=true;}});
    assert.equal(prevented,true);assert.equal(ui.recognition.stopped,true);assert.match(ui.status.textContent,/Create draft again/);
    ui.window.listeners.pagehide();assert.equal(ui.recognition.aborted,true);
});

test('unsupported browsers keep typed instruction entry usable', () => {
    const ui=setup(false);assert.equal(ui.toggle.disabled,true);assert.match(ui.status.textContent,/unavailable/);assert.equal(ui.instructions.value,'Existing instructions.');
});
