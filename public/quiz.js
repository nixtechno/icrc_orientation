(() => {
 'use strict';
 if(window.scodexQuizLoaded)return;window.scodexQuizLoaded=true;
 // Every answer is also kept on this device, so a poor connection cannot lose work. When the final submission cannot
 // reach the server, the attempt ends and the assessment page shows a pending submission that keeps retrying from
 // this copy until the server confirms it, then shows the results.
 const STORE='scodex-quiz:';
 const storage={
  set(record){try{window.localStorage.setItem(STORE+record.finishUrl,JSON.stringify(record));}catch{}},
  remove(record){try{window.localStorage.removeItem(STORE+record.finishUrl);}catch{}},
  all(){
   const found=[];
   try{const s=window.localStorage;for(let i=0;i<s.length;i++){const key=s.key(i);if(!key?.startsWith(STORE))continue;try{const record=JSON.parse(s.getItem(key));if(record?.finishUrl)found.push(record);}catch{}}}catch{}
   return found;
  },
 };
 const pathOf=url=>{try{return new URL(url,window.location.href).pathname.replace(/\/+$/,'');}catch{return String(url||'');}};
 const PENDING_RETRY_MS=10000,REQUEST_TIMEOUT_MS=20000;
 const timedFetch=(url,options)=>{
  const controller=typeof AbortController==='function'?new AbortController():null;
  const timer=controller?setTimeout(()=>controller.abort(),REQUEST_TIMEOUT_MS):null;
  return fetch(url,{...options,...(controller?{signal:controller.signal}:{})}).finally(()=>{if(timer)clearTimeout(timer);});
 };
 const showPending=(record,panel,token)=>{
  document.documentElement?.classList?.add('quiz-pending-active');panel.hidden=false;
  const message=panel.querySelector('[data-quiz-pending-message]'),retry=panel.querySelector('[data-quiz-pending-retry]');
  const say=text=>{if(message)message.textContent=text;};
  let timer,busy=false,stopped=false;
  const attempt=async()=>{
   if(busy||stopped)return;busy=true;clearTimeout(timer);say('Submitting your answers…');
   const body=new FormData();
   for(const [key,value] of record.entries||[])body.append(key,value);
   body.set('_token',token);body.set('reason',record.final?record.reason:'reopened');
   try{
    const response=await timedFetch(record.finishUrl,{method:'POST',body,credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});
    if(response.status===419){stopped=true;say('Your session needs refreshing. Reloading this page…');window.location.reload();return;}
    if(response.redirected){stopped=true;say('Please sign in again to finish submitting your assessment.');return;}
    let data={};try{data=await response.json();}catch{}
    if(response.ok&&data.submitted){stopped=true;storage.remove(record);say('Submitted. Loading your results…');window.location.assign(data.return_url||record.returnUrl);return;}
    if(response.status>=400&&response.status<500){stopped=true;say(`Your answers could not be submitted (${data.message||'the server refused them'}). Please show this screen to a facilitator.`);return;}
    throw new Error('Server unavailable');
   }catch{
    if(stopped)return;
    say(navigator.onLine===false?'No connection. Your assessment will be submitted as soon as you reconnect.':'Could not reach the server. Trying again in a few seconds…');
    timer=setTimeout(attempt,PENDING_RETRY_MS);
   }finally{busy=false;}
  };
  retry?.addEventListener('click',attempt);window.addEventListener('online',attempt);
  attempt();
 };
 // Opening the assessment page with an undelivered copy on this device for this person resumes the pending submission.
 const resumePending=()=>{
  const panel=document.querySelector?.('[data-quiz-pending]');
  if(!panel||panel.dataset.quizPendingBound)return;
  const here=pathOf(panel.dataset.activityUrl);
  const record=storage.all().find(item=>String(item.user)===String(panel.dataset.user)&&pathOf(item.returnUrl)===here);
  if(!record)return;
  panel.dataset.quizPendingBound='1';showPending(record,panel,panel.dataset.token);
 };
 const initialise=()=>document.querySelectorAll('[data-quiz-entry]').forEach(entry=>{
  if(entry.dataset.quizBound)return;entry.dataset.quizBound='1';
  const start=entry.querySelector('[data-quiz-start]'),ack=entry.querySelector('[data-quiz-ack]'),launchStatus=entry.querySelector('[data-quiz-launch-status]'),stage=entry.querySelector('[data-quiz-stage]');
  const token=entry.querySelector('[name="_token"]').value;
  const state={starting:false,active:false,ending:false,finished:false,manual:false,exitRequested:false,version:0,form:null,finishUrl:null,launchToken:null,record:null};
  let saveTimer,saveInterval,clockInterval,saving=false,saveAgain=false,finalPayload;
  // Real fullscreen is used wherever the device allows it, in the browser or the installed app. iPhones never allow it,
  // so the installed app runs in focus mode instead: the app already fills the screen, and leaving it (another app,
  // the home screen, or locking the phone) submits, just as leaving fullscreen does. An iPhone browser is asked to install.
  const installed=()=>window.matchMedia('(display-mode: standalone)').matches||navigator.standalone===true;
  const fullscreenElement=()=>document.fullscreenElement??document.webkitFullscreenElement??null;
  const canFullscreen=()=>Boolean((stage.requestFullscreen&&document.fullscreenEnabled!==false)||(stage.webkitRequestFullscreen&&document.webkitFullscreenEnabled!==false));
  const enterFullscreen=()=>stage.requestFullscreen?stage.requestFullscreen():Promise.resolve(stage.webkitRequestFullscreen());
  const inside=()=>state.focus?document.visibilityState==='visible':fullscreenElement()===stage;
  const exitReason=()=>state.focus?'page_exit':'fullscreen_exit';
  const setFocus=on=>document.documentElement?.classList.toggle('quiz-focus-mode',on);
  // Recorded with the attempt so automatic submissions can be traced to a device type or brand. Chrome on Android
  // reports the model (e.g. SM-T505); touch support tells an iPad apart from a Mac.
  const deviceDetails=async()=>{
   let model='';
   try{model=(await navigator.userAgentData?.getHighEntropyValues?.(['model']))?.model||'';}catch{}
   return JSON.stringify({model,touch:navigator.maxTouchPoints||0,screen:`${screen.width}x${screen.height}`,standalone:installed()});
  };
  const stopTimers=()=>{clearTimeout(saveTimer);clearInterval(saveInterval);clearInterval(clockInterval);};
  const capture=reason=>{
   const data=state.form?new FormData(state.form):new FormData();
   data.set('_token',token);data.set('draft_version',String(state.version));
   if(state.form)data.set('answers_present','1');
   if(reason)data.set('reason',reason);
   return data;
  };
  const smallEnough=data=>{
   let size=0;for(const [key,value] of data)size+=new Blob([key,String(value)]).size+200;
   return size<60000;
  };
  const json=async(url,body,keepalive=false)=>{
   const response=await timedFetch(url,{method:body?'POST':'GET',body,credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},keepalive});
   const data=await response.json();
   if(!response.ok||response.redirected)throw new Error(data.message||'The request could not be completed.');
   return data;
  };
  // Keeps the current answers on this device. A final copy is frozen: nothing changes it after the attempt ends.
  const remember=(final=false,reason=null)=>{
   if(!state.finishUrl||state.record?.final)return state.record;
   const entries=[...capture(reason)].filter(([key])=>key!=='_token').map(([key,value])=>[key,String(value)]);
   state.record={finishUrl:state.finishUrl,returnUrl:state.returnUrl,user:entry.dataset.user,entries,final,reason:reason||'reopened',savedAt:Date.now()};
   storage.set(state.record);
   return state.record;
  };
  const returnToActivity=data=>{state.finished=true;stopTimers();if(state.record)storage.remove(state.record);window.location.assign(data.return_url||state.returnUrl);};
  const endingScreen=message=>{
   stage.hidden=false;
   stage.innerHTML='<div class="quiz-ending"><h2>Assessment ended</h2><p data-quiz-end-message role="status"></p></div>';
   stage.querySelector('[data-quiz-end-message]').textContent=message;
  };
  // The final submission did not get through: end the session and leave a pending submission that keeps retrying.
  const goPending=()=>{
   state.finished=true;state.active=false;stopTimers();setFocus(false);
   if(fullscreenElement()===stage)(document.exitFullscreen?.()??document.webkitExitFullscreen?.())?.catch?.(()=>{});
   const record=state.record||remember(true,'load_failed');
   const panel=document.querySelector?.('[data-quiz-pending]');
   if(panel&&!panel.dataset.quizPendingBound){stage.hidden=true;stage.innerHTML='';panel.dataset.quizPendingBound='1';showPending(record,panel,token);return;}
   stage.hidden=false;
   stage.innerHTML='<div class="quiz-ending quiz-pending"><h2>Submission pending</h2><p>Your assessment has ended and is being submitted. Please keep this page open.</p><p class="quiz-pending-state" data-quiz-pending-message role="status"></p><button type="button" data-quiz-pending-retry>Try again now</button></div>';
   showPending(record,stage.querySelector('.quiz-pending'),token);
  };
  const sendFinal=async()=>{
   endingScreen('Submitting your answers. Please keep this page open.');
   try{returnToActivity(await json(state.finishUrl,finalPayload,smallEnough(finalPayload)));}
   catch{goPending();}
  };
  const finish=reason=>{
   if(state.finished||state.ending)return;
   state.exitRequested=true;
   if(!state.finishUrl)return;
   finalPayload=capture(reason);remember(true,reason);state.ending=true;state.active=false;stopTimers();
   sendFinal();
  };
  const save=async()=>{
   if(!state.active||state.ending||!state.form)return;
   if(!inside()){finish(exitReason());return;}
   if(saving){saveAgain=true;return;}saving=true;
   const status=stage.querySelector('[data-quiz-save-status]');
   try{
    const data=await json(state.draftUrl,capture());
    if(data.submitted){returnToActivity(data);return;}
    if(!state.ending){status.textContent='Answers saved.';status.classList.remove('quiz-save-error');}
   }catch{
    if(!state.ending){status.textContent='Connection problem. Carry on with the assessment; your answers will be saved when the connection returns.';status.classList.add('quiz-save-error');}
   }finally{saving=false;if(saveAgain){saveAgain=false;save();}}
  };
  const bindForm=payload=>{
   stage.innerHTML=payload.html;state.form=stage.querySelector('[data-quiz-form]');
   state.version=Number(payload.version)||0;state.active=true;
   state.draftUrl=payload.draft_url;state.finishUrl=payload.finish_url;state.returnUrl=payload.return_url;
   const submit=state.form.querySelector('[data-quiz-submit]');
   state.form.addEventListener('input',()=>{if(state.ending)return;state.version++;remember();clearTimeout(saveTimer);saveTimer=setTimeout(save,350);});
   // Submitting asks for confirmation in an in-page dialog; a browser confirm() could drop fullscreen and auto-submit.
   const confirmBox=state.form.querySelector('[data-quiz-confirm]');
   // One question is shown at a time. Moving on hides the question for good; its answer stays in the form so every
   // save still carries it, and the server locks it once the new position arrives.
   const questions=[...state.form.querySelectorAll('[data-quiz-question]')];
   const total=Number(state.form.dataset.quizTotal)||questions.length;
   const positionInput=state.form.querySelector('[data-quiz-position]'),progress=state.form.querySelector('[data-quiz-progress]');
   const next=state.form.querySelector('[data-quiz-next]'),navNote=state.form.querySelector('[data-quiz-nav-note]'),skipBox=state.form.querySelector('[data-quiz-skip]');
   const answered=field=>Boolean(field.querySelector('input:checked'))||[...field.querySelectorAll('textarea')].some(box=>box.value.trim());
   let current=0;
   const show=()=>{
    const last=current>=questions.length-1;
    questions.forEach((field,index)=>{field.hidden=index!==current;});
    if(next)next.hidden=last;submit.hidden=!last;if(navNote)navNote.hidden=last;
    if(questions[current]&&progress)progress.textContent=`Question ${Number(questions[current].dataset.quizQuestion)+1} of ${total}`;
    questions[current]?.querySelector('input:not([type="hidden"]),textarea')?.focus();
   };
   const advance=()=>{
    if(state.ending||current>=questions.length-1)return;
    current++;if(positionInput)positionInput.value=questions[current].dataset.quizQuestion;
    state.version++;remember();clearTimeout(saveTimer);save();show();
   };
   next?.addEventListener('click',()=>{
    if(state.ending)return;
    if(!answered(questions[current])&&skipBox?.showModal){skipBox.showModal();skipBox.querySelector('[data-quiz-skip-cancel]').focus();return;}
    advance();
   });
   skipBox?.querySelector('[data-quiz-skip-cancel]').addEventListener('click',()=>skipBox.close());
   skipBox?.querySelector('[data-quiz-skip-confirm]').addEventListener('click',()=>{skipBox.close();advance();});
   const askToSubmit=()=>{
    const done=(Number(state.form.dataset.quizEarlierAnswered)||0)+questions.filter(answered).length;
    const blank=Math.max(0,total-done);
    confirmBox.querySelector('[data-quiz-confirm-summary]').textContent=blank
     ?`You have answered ${done} of ${total} questions. ${blank} ${blank===1?'question is':'questions are'} unanswered and will score nothing.`
     :`You have answered all ${total} questions.`;
    confirmBox.showModal();
    confirmBox.querySelector(blank?'[data-quiz-confirm-cancel]':'[data-quiz-confirm-submit]').focus();
   };
   confirmBox?.querySelector('[data-quiz-confirm-cancel]').addEventListener('click',()=>confirmBox.close());
   confirmBox?.querySelector('[data-quiz-confirm-submit]').addEventListener('click',()=>{confirmBox.close();submitFinal();});
   state.form.addEventListener('submit',event=>{
    event.preventDefault();if(state.ending)return;
    // Pressing Enter on an earlier question must not submit the whole attempt.
    if(current<questions.length-1)return;
    if(confirmBox?.showModal)askToSubmit();else submitFinal();
   });
   // The final submission goes once; if it does not get through, the attempt ends with a pending submission.
   const submitFinal=async()=>{
    if(state.ending)return;
    const data=capture('manual');remember(true,'manual');state.ending=true;state.manual=true;state.active=false;submit.disabled=true;submit.textContent='Submitting your answers…';stopTimers();
    try{returnToActivity(await json(state.finishUrl,data,smallEnough(data)));}
    catch{goPending();}
   };
   show();startTimers(payload);save();
  };
  const startTimers=payload=>{
   stopTimers();saveInterval=setInterval(save,15000);
   if(!payload.expires_at)return;
   const expires=Date.parse(payload.expires_at),offset=payload.server_now?Date.parse(payload.server_now)-Date.now():0;
   const tick=()=>{
    if(state.ending)return;const remaining=Math.max(0,Math.ceil((expires-Date.now()-offset)/1000));
    const clock=stage.querySelector('[data-quiz-countdown]');
    clock.textContent=`Time remaining: ${Math.floor(remaining/60)}:${String(remaining%60).padStart(2,'0')}`;
    if(remaining===0)finish('deadline');
   };clockInterval=setInterval(tick,1000);tick();
  };
  start.addEventListener('click',async()=>{
   if(state.starting||state.active||state.ending)return;
   if(!ack.checked){ack.reportValidity();launchStatus.textContent='Please acknowledge the assessment notice before starting.';return;}
   state.focus=!canFullscreen()&&installed();
   if(!state.focus&&!canFullscreen()){launchStatus.textContent='This browser cannot run the assessment in fullscreen. On an iPhone, install the SCODEX app (Share, then Add to Home Screen, or Install the app in the menu), open it from your home screen and start the assessment there.';document.querySelector('[data-install-app]')?.click();return;}
   state.starting=true;state.exitRequested=false;start.disabled=true;stage.hidden=false;stage.textContent=state.focus?'Starting the assessment…':'Entering fullscreen…';
   if(state.focus)setFocus(true);
   else{
    try{await enterFullscreen();}
    catch{state.starting=false;start.disabled=false;stage.hidden=true;launchStatus.textContent='Fullscreen was not allowed. No assessment attempt has been started. Allow fullscreen and try again.';return;}
   }
   try{
    state.launchToken??=window.crypto?.randomUUID?.()||`${Date.now()}-${Math.random().toString(36).slice(2)}-quiz`;
    const data=await json(entry.dataset.startUrl,new URLSearchParams({_token:token,fullscreen:'1',policy_acknowledged:'1',launch_token:state.launchToken,device:await deviceDetails()}));
    if(data.submitted){returnToActivity(data);return;}
    state.finishUrl=data.url.replace(/\/complete(?:\?.*)?$/,'/auto-submit');state.returnUrl=data.return_url;
    if(state.exitRequested||!inside()){finish(exitReason());return;}
    const payload=await json(data.url);
    if(payload.submitted){returnToActivity(payload);return;}
    if(state.exitRequested||!inside()){finish(exitReason());return;}
    bindForm(payload);
   }catch(error){
    if(state.finishUrl){finish('load_failed');}
    else{stage.textContent=error.message;launchStatus.textContent=error.message;state.starting=false;start.disabled=false;stage.hidden=true;setFocus(false);if(fullscreenElement()===stage)(document.exitFullscreen?.()??document.webkitExitFullscreen?.())?.catch?.(()=>{});}
   }finally{state.starting=false;}
  });
  const left=()=>{if(!inside()&&(state.starting||state.active)&&!state.ending)finish(exitReason());};
  document.addEventListener('fullscreenchange',()=>{if(!state.focus)left();});
  document.addEventListener('webkitfullscreenchange',()=>{if(!state.focus)left();});
  document.addEventListener('visibilitychange',()=>{if(state.focus)left();});
  window.addEventListener('pagehide',()=>{
   if(state.finished||state.manual||!state.finishUrl)return;
   remember(true,'page_exit');
   const payload=finalPayload||capture('page_exit');
   if(!smallEnough(payload)){
    const compact=new FormData();compact.set('_token',token);compact.set('reason','page_exit');
    if(navigator.sendBeacon?.(state.finishUrl,compact))return;
    fetch(state.finishUrl,{method:'POST',body:compact,credentials:'same-origin',keepalive:true}).catch(()=>{});return;
   }
   if(navigator.sendBeacon?.(state.finishUrl,payload))return;
   fetch(state.finishUrl,{method:'POST',body:payload,credentials:'same-origin',keepalive:true}).catch(()=>{});
  });
  window.addEventListener('pageshow',event=>{if(event.persisted&&state.returnUrl)window.location.replace(state.returnUrl);});
 });
 initialise();resumePending();document.addEventListener('portal:search-updated',initialise);
})();
