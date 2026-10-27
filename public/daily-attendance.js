(() => {
 const target=document.querySelector('[data-live-attendance]');
 const indicator=document.querySelector('[data-live-indicator]');
 if(!target||!indicator)return;
 let running=false;
 async function refresh(){
  if(running||document.hidden)return;
  running=true;
  try{const response=await fetch(target.dataset.url,{headers:{'X-Requested-With':'XMLHttpRequest'},credentials:'same-origin',cache:'no-store'});if(!response.ok||response.redirected)throw new Error('Unavailable');target.innerHTML=await response.text();indicator.textContent='Connected · Last updated '+new Date().toLocaleTimeString()+' · Refreshes every 15 seconds';}
  catch{indicator.textContent='Connection interrupted. Retrying every 15 seconds; displayed data may be out of date.';}
  finally{running=false;}
 }
 refresh();window.setInterval(refresh,15000);document.addEventListener('visibilitychange',refresh);
})();
