const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const tick = () => new Promise(setImmediate);

function mount(fetcher) {
 const handlers = {}, events = {}, calls = [], history = [];
 const fields = [{name:'date',value:'2026-10-29'},{name:'name',value:''},{name:'department',value:''},{name:'status',value:''}];
 const filter = {elements:fields,addEventListener:(event,fn)=>handlers[event]=fn};
 const target = {dataset:{url:'/attendance/live'},isConnected:true,innerHTML:'original',closest:()=>({querySelector:()=>filter})};
 const indicator = {textContent:''};
 const location = {href:'http://localhost/attendance?staff=5',search:'?staff=5'};
 vm.runInNewContext(fs.readFileSync('public/daily-attendance.js','utf8'), {
  document:{hidden:false,querySelector:s=>s==='[data-live-attendance]'?target:indicator,addEventListener(){}},
  location, URL, URLSearchParams, AbortController, clearTimeout, setTimeout,
  FormData:class{*[Symbol.iterator](){for(const field of fields)yield [field.name,field.value];}},
  window:{addEventListener:(type,fn)=>events[type]=fn,setInterval(){},history:{pushState(state,title,url){history.push(url.href);location.href=url.href;location.search=url.search;}}},
  fetch:async(url,options)=>{calls.push({url,options});return fetcher?fetcher(url,options):{ok:true,text:async()=> 'updated'};},
 });
 return {fields,target,indicator,calls,history,events,location,submit(){let prevented=false;handlers.submit({preventDefault(){prevented=true;}});return prevented;}};
}

test('attendance search replaces live results without submitting or losing the selected staff history',async()=>{
 const p=mount();await tick();p.fields[1].value='Amina';assert.equal(p.submit(),true);await tick();
 assert.match(p.target.dataset.url,/name=Amina/);assert.match(p.history[0],/staff=5/);
 assert.equal(p.target.innerHTML,'updated');assert.match(p.indicator.textContent,/Connected/);
});

test('new attendance filters cancel stale requests so old responses cannot replace current results',async()=>{
 const pending=[];const p=mount(()=>new Promise(resolve=>pending.push(resolve)));
 p.fields[1].value='Tunde';p.submit();assert.equal(p.calls[0].options.signal.aborted,true);
 pending[1]({ok:true,text:async()=> 'Tunde results'});await tick();
 pending[0]({ok:true,text:async()=> 'old results'});await tick();
 assert.equal(p.target.innerHTML,'Tunde results');
});

test('detached attendance widgets do not request data after top-bar search changes the content',async()=>{
 const p=mount();await tick();p.target.isConnected=false;p.fields[1].value='Amina';p.submit();
 assert.equal(p.calls.length,1);
});
