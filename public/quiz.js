(() => {
 'use strict';
 const initialise=()=>document.querySelectorAll('[data-quiz-entry]').forEach(entry=>{
  if(entry.dataset.quizBound)return;entry.dataset.quizBound='1';
  const start=entry.querySelector('[data-quiz-start]'),ack=entry.querySelector('[data-quiz-ack]'),launchStatus=entry.querySelector('[data-quiz-launch-status]'),stage=entry.querySelector('[data-quiz-stage]');
  const token=entry.querySelector('[name="_token"]').value;
  const state={starting:false,active:false,ending:false,finished:false,manual:false,exitRequested:false,version:0,form:null,finishUrl:null,launchToken:null};
  let saveTimer,saveInterval,clockInterval,saving=false,saveAgain=false,finalPayload;
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
   const response=await fetch(url,{method:body?'POST':'GET',body,credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'},keepalive});
   const data=await response.json();
   if(!response.ok||response.redirected)throw new Error(data.message||'The request could not be completed.');
   return data;
  };
  const returnToActivity=data=>{state.finished=true;stopTimers();window.location.assign(data.return_url||state.returnUrl);};
  const endingScreen=(message,retry=false)=>{
   stage.hidden=false;
   stage.innerHTML='<div class="quiz-ending"><h2>Assessment ended</h2><p data-quiz-end-message role="status"></p></div>';
   stage.querySelector('[data-quiz-end-message]').textContent=message;
   if(retry){const button=document.createElement('button');button.type='button';button.textContent='Retry submitting saved answers';button.addEventListener('click',sendFinal);stage.querySelector('.quiz-ending').append(button);}
  };
  const sendFinal=async()=>{
   endingScreen('Submitting your current answers. Please keep this page open.');
   try{returnToActivity(await json(state.finishUrl,finalPayload,smallEnough(finalPayload)));}
   catch{endingScreen('Your attempt has ended, but the server has not confirmed submission. Reconnect and retry. Answers cannot be edited after leaving fullscreen.',true);}
  };
  const finish=reason=>{
   if(state.finished||state.ending)return;
   state.exitRequested=true;
   if(!state.finishUrl)return;
   finalPayload=capture(reason);state.ending=true;state.active=false;stopTimers();
   sendFinal();
  };
  const save=async()=>{
   if(!state.active||state.ending||!state.form)return;
   if(document.fullscreenElement!==stage){finish('fullscreen_exit');return;}
   if(saving){saveAgain=true;return;}saving=true;
   const status=stage.querySelector('[data-quiz-save-status]');
   try{
    const data=await json(state.draftUrl,capture());
    if(data.submitted){returnToActivity(data);return;}
    if(!state.ending){status.textContent='Answers saved.';status.classList.remove('quiz-save-error');}
   }catch{
    if(!state.ending){status.textContent='Your latest answers have not been saved. Check your connection and keep this assessment open.';status.classList.add('quiz-save-error');}
   }finally{saving=false;if(saveAgain){saveAgain=false;save();}}
  };
  const bindForm=payload=>{
   stage.innerHTML=payload.html;state.form=stage.querySelector('[data-quiz-form]');
   state.version=Number(payload.version)||0;state.active=true;
   state.draftUrl=payload.draft_url;state.finishUrl=payload.finish_url;state.returnUrl=payload.return_url;
   const submit=state.form.querySelector('[data-quiz-submit]');
   state.form.addEventListener('input',()=>{if(state.ending)return;state.version++;clearTimeout(saveTimer);saveTimer=setTimeout(save,350);});
   state.form.addEventListener('submit',async event=>{
    event.preventDefault();if(state.ending)return;
    const data=capture();state.ending=true;state.manual=true;submit.disabled=true;submit.textContent='Submitting your answers…';stopTimers();
    try{returnToActivity(await json(state.form.action,data,smallEnough(data)));}
    catch(error){
     state.ending=false;state.manual=false;submit.disabled=false;submit.textContent='Submit final answers';
     const status=stage.querySelector('[data-quiz-save-status]');status.textContent=error.message;status.classList.add('quiz-save-error');
     if(document.fullscreenElement!==stage)finish('fullscreen_exit');else startTimers(payload);
    }
   });
   state.form.querySelector('input:not([type="hidden"]),textarea')?.focus();startTimers(payload);save();
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
   if(!stage.requestFullscreen||document.fullscreenEnabled===false){launchStatus.textContent='Fullscreen is unavailable in this browser. Use a browser that supports fullscreen assessments.';return;}
   state.starting=true;state.exitRequested=false;start.disabled=true;stage.hidden=false;stage.textContent='Entering fullscreen…';
   try{await stage.requestFullscreen();}
   catch{state.starting=false;start.disabled=false;stage.hidden=true;launchStatus.textContent='Fullscreen was not allowed. No assessment attempt has been started. Allow fullscreen and try again.';return;}
   try{
    state.launchToken??=window.crypto?.randomUUID?.()||`${Date.now()}-${Math.random().toString(36).slice(2)}-quiz`;
    const data=await json(entry.dataset.startUrl,new URLSearchParams({_token:token,fullscreen:'1',policy_acknowledged:'1',launch_token:state.launchToken}));
    if(data.submitted){returnToActivity(data);return;}
    state.finishUrl=data.url.replace(/\/complete(?:\?.*)?$/,'/auto-submit');state.returnUrl=data.return_url;
    if(state.exitRequested||document.fullscreenElement!==stage){finish('fullscreen_exit');return;}
    const payload=await json(data.url);
    if(payload.submitted){returnToActivity(payload);return;}
    if(state.exitRequested||document.fullscreenElement!==stage){finish('fullscreen_exit');return;}
    bindForm(payload);
   }catch(error){
    if(state.finishUrl){finish('load_failed');}
    else{stage.textContent=error.message;launchStatus.textContent=error.message;state.starting=false;start.disabled=false;stage.hidden=true;if(document.fullscreenElement===stage)document.exitFullscreen?.().catch(()=>{});}
   }finally{state.starting=false;}
  });
  document.addEventListener('fullscreenchange',()=>{
   if(document.fullscreenElement!==stage&&(state.starting||state.active)&&!state.ending)finish('fullscreen_exit');
  });
  window.addEventListener('pagehide',()=>{
   if(state.finished||state.manual||!state.finishUrl)return;
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
 initialise();document.addEventListener('portal:search-updated',initialise);
})();
