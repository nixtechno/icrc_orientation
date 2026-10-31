const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

function mount(fetcher, withButton=true) {
 const input = {value:'https://portal.test/badges/staff-badge/verify',focus(){this.focused=true;}}, button={}, result={};
 const form = {action:'/attendance/scanner/sign-in',querySelector(selector){return selector==='button'?(withButton?button:null):input;},addEventListener(name,handler){this[name]=handler;}};
 const calls=[];
 class Data {constructor(){this.code=input.value;}}
 vm.runInNewContext(fs.readFileSync('public/attendance-scanner.js','utf8'),{
  document:{querySelector(selector){return selector==='[data-attendance-scanner]'?form:result;}},FormData:Data,TypeError,
  fetch:async (...args)=>{calls.push(args);return fetcher(...args);},
 });
 return {input,button,result,calls,submit:()=>form.submit({preventDefault(){}})};
}

test('scanner submits once while busy, then clears and focuses the next scan without navigation',async()=>{
 let resolve;const p=mount(()=>new Promise(r=>resolve=r));
 const pending=p.submit(); await p.submit();
 assert.equal(p.calls.length,1);assert.equal(p.input.readOnly,true);assert.equal(p.button.disabled,true);
 assert.equal(p.calls[0][1].body.code,'https://portal.test/badges/staff-badge/verify');
 resolve({ok:true,json:async()=>({message:'Amina — Sign-in recorded at 09:00:00.'})});await pending;
 assert.match(p.result.textContent,/Amina/);assert.equal(p.input.value,'');assert.equal(p.input.focused,true);assert.equal(p.button.disabled,false);
});

test('duplicate and invalid scans display the server reason and leave the station ready',async()=>{
 const p=mount(async()=>({ok:false,status:409,json:async()=>({message:'Attendance is already recorded for today.'})}));
 await p.submit();assert.match(p.result.textContent,/already recorded/);assert.equal(p.result.className,'errors');assert.equal(p.input.focused,true);
 p.input.value='next-badge';await p.submit();assert.equal(p.calls.length,2);
});

test('expired sessions and connection failures are visible instead of reporting false success',async()=>{
 const expired=mount(async()=>({status:419}));await expired.submit();assert.match(expired.result.textContent,/session has expired/);
 const offline=mount(async()=>{throw new TypeError('Offline');});await offline.submit();assert.match(offline.result.textContent,/Connection lost/);assert.equal(offline.input.focused,true);
});

test('stations without a submit button record on Enter and stay ready',async()=>{
 const p=mount(async()=>({ok:true,json:async()=>({message:'Amina — Sign-out recorded at 17:00:00.'})}),false);
 await p.submit();assert.match(p.result.textContent,/Amina/);assert.equal(p.input.value,'');assert.equal(p.input.readOnly,false);assert.equal(p.input.focused,true);
});
