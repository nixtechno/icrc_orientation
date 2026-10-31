const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
const tick=()=>new Promise(setImmediate);

function mount({supported=true,denied=false,acknowledged=true,fetcher,expired=false}={}){
 const element=(extra={})=>Object.assign({handlers:{},dataset:{},hidden:false,textContent:'',disabled:false,addEventListener(name,fn){this.handlers[name]=fn;},fire(name,event={}){return this.handlers[name]?.({preventDefault(){},...event});},focus(){},classList:{add(){},remove(){}}},extra);
 const calls=[],beacons=[],navigations=[],timers=new Map();let timerId=0;
 const document=element({fullscreenEnabled:supported,fullscreenElement:null});const status=element(),clock=element(),submit=element();
 const form=element({action:'http://localhost/submissions/attempt/complete',values:{_token:'token','answers[1]':''},querySelector:selector=>selector==='[data-quiz-submit]'?submit:null});
 const message=element(),retryButtons=[],ending=element({append(button){retryButtons.push(button);}});
 const stage=element({hidden:true,mode:'loading',querySelector(selector){return {'[data-quiz-form]':form,'[data-quiz-save-status]':status,'[data-quiz-countdown]':clock,'[data-quiz-end-message]':message,'.quiz-ending':ending}[selector];}});
 Object.defineProperty(stage,'innerHTML',{set(value){stage.mode=value==='FORM'?'form':'ending';},get(){return stage.mode;}});
 if(supported)stage.requestFullscreen=async()=>{if(denied)throw Error('Denied');document.fullscreenElement=stage;document.fire('fullscreenchange');};
 const start=element(),ack=element({checked:acknowledged,reportValidity(){this.reported=true;}}),launch=element();
 const entry=element({dataset:{startUrl:'http://localhost/activities/quiz/start'},querySelector:selector=>({'[data-quiz-start]':start,'[data-quiz-ack]':ack,'[data-quiz-launch-status]':launch,'[data-quiz-stage]':stage,'[name="_token"]':{value:'token'}})[selector]});
 document.querySelectorAll=()=>[entry];document.createElement=()=>element();document.exitFullscreen=async()=>{document.fullscreenElement=null;document.fire('fullscreenchange');};
 class Data{
  constructor(source){this.values=new Map(Object.entries(source?.values||{}));}
  set(key,value){this.values.set(key,value);}get(key){return this.values.get(key);}*[Symbol.iterator](){yield* this.values;}
 }
 const window=element({crypto:{randomUUID:()=> '12345678-1234-1234-1234-123456789012'},location:{assign(url){navigations.push(url);},replace(url){navigations.push(url);}}});
 const response=(data,ok=true)=>({ok,redirected:false,json:async()=>data});
 const defaultFetch=(url,options)=>{
  if(url.endsWith('/start'))return response({submitted:false,url:'http://localhost/submissions/attempt/complete',return_url:'http://localhost/activities/quiz'});
  if(!options.body)return response({html:'FORM',draft_url:'http://localhost/submissions/attempt/draft',finish_url:'http://localhost/submissions/attempt/auto-submit',return_url:'http://localhost/activities/quiz',version:0,expires_at:expired?new Date(Date.now()-1000).toISOString():null,server_now:new Date().toISOString()});
  if(url.endsWith('/draft'))return response({submitted:false,version:Number(options.body.get('draft_version'))});
  return response({submitted:true,return_url:'http://localhost/activities/quiz'});
 };
 vm.runInNewContext(fs.readFileSync('public/quiz.js','utf8'),{document,window,navigator:{sendBeacon(url,data){beacons.push({url,data});return true;}},fetch:async(url,options)=>{calls.push({url,options});return fetcher?fetcher(url,options,defaultFetch,response):defaultFetch(url,options);},FormData:Data,URLSearchParams,Blob,Date,Math,Number,String,setTimeout(fn){timers.set(++timerId,fn);return timerId;},clearTimeout(id){timers.delete(id);},setInterval(fn){timers.set(++timerId,fn);return timerId;},clearInterval(id){timers.delete(id);}});
 return {document,window,start,ack,launch,stage,form,status,clock,submit,calls,beacons,navigations,message,retryButtons,response,async begin(){await start.fire('click');await tick();},exit(){document.fullscreenElement=null;document.fire('fullscreenchange');},runTimers(){[...timers.values()].forEach(fn=>fn());}};
}

test('fullscreen consent is required and denied or unsupported fullscreen never starts a server attempt',async()=>{
 for(const options of [{acknowledged:false},{supported:false},{denied:true}]){
  const p=mount(options);await p.begin();assert.equal(p.calls.length,0);assert.equal(p.stage.hidden,true);
 }
});

test('starting enters fullscreen and exiting submits the current answers once',async()=>{
 const p=mount();await p.begin();assert.equal(p.document.fullscreenElement,p.stage);assert.equal(p.stage.mode,'form');
 p.form.values['answers[1]']='B';p.form.fire('input');p.exit();p.exit();await tick();
 const finals=p.calls.filter(call=>call.url.endsWith('/auto-submit'));assert.equal(finals.length,1);
 assert.equal(finals[0].options.body.get('answers[1]'),'B');assert.equal(finals[0].options.body.get('reason'),'fullscreen_exit');assert.equal(finals[0].options.body.get('_token'),'token');
 assert.equal(p.navigations.at(-1),'http://localhost/activities/quiz');
});

test('manual final submission suppresses a following fullscreen-exit submission',async()=>{
 const p=mount();await p.begin();p.form.values['answers[1]']='B';await p.form.fire('submit');p.exit();await tick();
 assert.equal(p.calls.filter(call=>call.url.endsWith('/auto-submit')).length,0);assert.equal(p.calls.filter(call=>call.url.endsWith('/complete')&&call.options.body).length,1);
});

test('closing the page queues a CSRF-protected submission with current answers',async()=>{
 const p=mount();await p.begin();p.form.values['answers[1]']='B';p.form.fire('input');p.window.fire('pagehide');
 assert.equal(p.beacons.length,1);assert.equal(p.beacons[0].data.get('reason'),'page_exit');assert.equal(p.beacons[0].data.get('answers[1]'),'B');assert.equal(p.beacons[0].data.get('_token'),'token');
});

test('failed auto submission freezes the attempt and retry sends the same captured answers',async()=>{
 let failing=true;const p=mount({fetcher:(url,options,normal,response)=>url.endsWith('/auto-submit')&&failing?response({message:'Offline'},false):normal(url,options)});await p.begin();p.form.values['answers[1]']='B';p.exit();await tick();
 assert.equal(p.stage.mode,'ending');assert.match(p.message.textContent,/Reconnect/);assert.equal(p.retryButtons.length,1);
 p.form.values['answers[1]']='A';failing=false;await p.retryButtons[0].fire('click');
 assert.equal(p.calls.filter(call=>call.url.endsWith('/auto-submit')).at(-1).options.body.get('answers[1]'),'B');
});

test('the deadline submits automatically and browser-history restoration cannot reopen the quiz',async()=>{
 const p=mount({expired:true});await p.begin();await tick();assert.equal(p.calls.find(call=>call.url.endsWith('/auto-submit')).options.body.get('reason'),'deadline');
 p.window.fire('pageshow',{persisted:true});assert.equal(p.navigations.at(-1),'http://localhost/activities/quiz');
});
