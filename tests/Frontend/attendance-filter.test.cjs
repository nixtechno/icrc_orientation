const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const tick = () => new Promise(setImmediate);

function mount(fetcher, split = false) {
 const handlers = {}, events = {}, calls = [], history = [], tableEvents = {};
 const fields = [{name:'date',value:'2026-10-29'},{name:'name',value:''},{name:'department',value:''},{name:'status',value:''}];
 const filter = {elements:fields,addEventListener:(event,fn)=>handlers[event]=fn};
 fields.push({name:'per_page',value:'10'},{name:'sort',value:'name'},{name:'direction',value:'asc'});
 const target = {dataset:{url:'/attendance/live'},isConnected:true,innerHTML:'original',closest:()=>({querySelector:()=>filter}),addEventListener:(type,fn)=>tableEvents[type]=fn};
 const summarySlot = {innerHTML:''}, tableSlot = {innerHTML:''};
 if (split) target.querySelector = selector => selector==='[data-live-summary-slot]'?summarySlot:tableSlot;
 const indicator = {textContent:''};
 const location = {href:'http://localhost/attendance?staff=5',search:'?staff=5'};
 vm.runInNewContext(fs.readFileSync('public/daily-attendance.js','utf8'), {
  document:{hidden:false,querySelector:s=>s==='[data-live-attendance]'?target:indicator,addEventListener(){}},
  location, URL, URLSearchParams, AbortController, clearTimeout, setTimeout,
  DOMParser:class{parseFromString(html){return {querySelector:selector=>({innerHTML:(selector==='[data-live-summary-fragment]'?'summary:':'table:')+html})};}},
  FormData:class{*[Symbol.iterator](){for(const field of fields)yield [field.name,field.value];}},
  window:{addEventListener:(type,fn)=>events[type]=fn,setInterval(fn){events.refresh=fn;},history:{pushState(state,title,url){history.push(url.href);location.href=url.href;location.search=url.search;}}},
  fetch:async(url,options)=>{calls.push({url,options});return fetcher?fetcher(url,options):{ok:true,text:async()=> 'updated'};},
 });
 return {fields,target,indicator,calls,history,events,tableEvents,location,summarySlot,tableSlot,submit(){let prevented=false;handlers.submit({preventDefault(){prevented=true;}});return prevented;}};
}

test('attendance search replaces live results without submitting or losing the selected staff history',async()=>{
 const p=mount();await tick();p.fields[1].value='Amina';assert.equal(p.submit(),true);await tick();
 assert.match(p.target.dataset.url,/name=Amina/);assert.match(p.history[0],/staff=5/);
 assert.equal(p.target.innerHTML,'updated');assert.equal(p.indicator.textContent,'');assert.equal(p.indicator.hidden,true);
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

test('pagination and sorting update only the table and persist through automatic refresh',async()=>{
 const p=mount();await tick();let prevented=false;
 p.tableEvents.click({target:{closest:()=>({href:'http://localhost/attendance/live?attendance_page=2&sort=name&direction=desc&per_page=10'})},preventDefault(){prevented=true;}});await tick();
 assert.equal(prevented,true);assert.match(p.calls.at(-1).url,/attendance_page=2/);assert.match(p.location.href,/staff=5/);assert.match(p.location.href,/attendance_page=2/);
 assert.equal(p.fields.find(field=>field.name==='direction').value,'desc');
 p.events.refresh();await tick();assert.match(p.calls.at(-1).url,/attendance_page=2/);
 p.fields[1].value='Amina';p.submit();await tick();assert.doesNotMatch(p.calls.at(-1).url,/attendance_page/);assert.doesNotMatch(p.location.href,/attendance_page/);
});

test('browser back restores table pagination and sorting without a document reload',async()=>{
 const p=mount();await tick();p.location.search='?attendance_page=3&per_page=25&sort=status&direction=desc';p.events.popstate();await tick();
 assert.match(p.calls.at(-1).url,/attendance_page=3/);assert.match(p.calls.at(-1).url,/per_page=25/);assert.match(p.calls.at(-1).url,/sort=status/);
});

test('refresh updates summary and table slots while preserving the filter form and hiding routine connection messages',async()=>{
 const p=mount(null,true);await tick();p.fields[1].value='Amina';p.submit();await tick();
 assert.equal(p.target.innerHTML,'original');assert.equal(p.summarySlot.innerHTML,'summary:updated');assert.equal(p.tableSlot.innerHTML,'table:updated');
 assert.equal(p.fields[1].value,'Amina');assert.equal(p.indicator.hidden,true);assert.equal(p.indicator.textContent,'');
});

test('only failed refreshes show an attendance message and successful recovery hides it',async()=>{
 let failed=true;const p=mount(()=>Promise.resolve({ok:!failed,text:async()=>'recovered'}));await tick();
 assert.equal(p.indicator.hidden,false);assert.match(p.indicator.textContent,/could not be updated/);
 failed=false;p.events.refresh();await tick();assert.equal(p.indicator.hidden,true);assert.equal(p.indicator.textContent,'');
});
