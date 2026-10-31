(() => {
 const root=document.querySelector('[data-badge-editor]');if(!root)return;
 const profile=root.querySelector('[data-badge-profile-form]');
 const sizeForm=root.querySelector('[data-badge-size-form]');
 const print=root.querySelector('[data-print-badge]');
 const pdf=root.querySelector('[data-badge-pdf]');
 const unsaved=root.querySelector('[data-badge-unsaved]');
 const panel=root.querySelector('[data-badge-panel]');
 const sheet=root.querySelector('.badge-sheet');
 const close=root.querySelector('[data-close-badge-editor]');
 const showPanel=open=>{
  if(!panel)return;
  panel.hidden=!open;panel.inert=!open;root.classList.toggle('badge-editor-open',open);
  sheet?.setAttribute('aria-expanded',String(open));
  if(open)close?.focus();else sheet?.focus();
 };
 if(sheet&&panel){
  sheet.setAttribute('tabindex','0');sheet.setAttribute('aria-controls','badge-edit-panel');sheet.setAttribute('aria-expanded','false');
  sheet.addEventListener('dblclick',()=>showPanel(true));
  sheet.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();showPanel(true);}});
  close?.addEventListener('click',()=>showPanel(false));
  root.addEventListener('keydown',event=>{if(event.key==='Escape'&&!panel.hidden){event.preventDefault();showPanel(false);}});
 }
 const scale=Number(root.dataset.widthScale)||1;
 let dirty=false;
 const fit=()=>{
  root.querySelectorAll('[data-badge-text]').forEach(block=>{
   const control=sizeForm.querySelector(`[data-badge-size="${block.dataset.badgeText}"]`);
   if(!control||!control.checkValidity())return;
   let size=Number(control.value)*scale;
   block.style.fontSize=`${size}mm`;
   while(size>1.7*scale&&(block.scrollHeight>block.clientHeight+1||block.scrollWidth>block.clientWidth+1)){
    size-=.15*scale;block.style.fontSize=`${size}mm`;
   }
  });
 };
 profile.addEventListener('input',event=>{
  const key=event.target.dataset.profileField;if(!key)return;
  root.querySelectorAll(`[data-badge-field="${key}"]`).forEach(block=>block.textContent=event.target.value);
  dirty=true;unsaved.hidden=false;print.disabled=true;pdf.setAttribute('aria-disabled','true');fit();
 });
 sizeForm.addEventListener('input',event=>{
  if(!event.target.matches('[data-badge-size]')||!event.target.checkValidity())return;
  fit();const url=new URL(pdf.href,location.href);const page=new URL(location.href);
  sizeForm.querySelectorAll('[data-badge-size]').forEach(control=>{
   if(control.checkValidity()){url.searchParams.set(control.name,control.value);page.searchParams.set(control.name,control.value);}
  });
  pdf.href=url.href;window.history.replaceState({},'',page);
 });
 pdf.addEventListener('click',event=>{if(dirty)event.preventDefault();});
 print.addEventListener('click',()=>{if(!dirty){fit();window.print();}});
 window.addEventListener('beforeprint',fit);
 if(document.fonts?.ready)document.fonts.ready.then(fit);else fit();
})();
