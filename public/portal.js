(() => {
 'use strict';
 const paths={grid:'M3 3h6v6H3z M15 3h6v6h-6z M3 15h6v6H3z M15 15h6v6h-6z',calendar:'M4 5h16v16H4z M8 3v4 M16 3v4 M4 10h16 M8 14h2 M14 14h2 M8 18h2',check:'M8 4H5v17h14V4h-3 M8 3h8v4H8z M8 14l3 3 5-6',file:'M5 3h10l4 4v14H5z M14 3v5h5 M9 12h6 M9 16h6',screen:'M3 4h18v13H3z M12 17v4 M7 21h10',book:'M12 5c-4-3-8-2-9-1v15c3-2 6-2 9 0 3-2 6-2 9 0V4c-3-2-6-2-9 1z M12 5v14',bell:'M5 17h14l-2-4V9a5 5 0 0 0-10 0v4z M10 21h4',chat:'M4 4h16v12H9l-5 4z M8 8h8 M8 12h5',people:'M16 21v-3c0-4-12-4-12 0v3 M10 3a4 4 0 1 0 0 8 4 4 0 0 0 0-8 M17 4c4 0 4 6 0 6 M20 21v-4c0-2-1-3-3-3',chart:'M4 3v18h17 M8 16v-5 M13 16V7 M18 16v-8',settings:'M9 3h6l1 4 4 1v7l-4 1-1 5H9l-1-5-4-1V8l4-1z M12 9a3 3 0 1 0 0 6 3 3 0 0 0 0-6',badge:'M6 3h12v18l-6-4-6 4z M9 8h6 M9 12h6',logout:'M10 4H4v16h6 M9 12h12 M17 8l4 4-4 4',menu:'M4 6h16 M4 12h16 M4 18h16',search:'M10 3a7 7 0 1 0 0 14 7 7 0 0 0 0-14 M15 15l6 6'};
 document.querySelectorAll('[data-icon]').forEach(el=>{const svg=document.createElementNS('http://www.w3.org/2000/svg','svg');svg.setAttribute('viewBox','0 0 24 24');svg.setAttribute('aria-hidden','true');const path=document.createElementNS(svg.namespaceURI,'path');path.setAttribute('d',paths[el.dataset.icon]||paths.file);svg.append(path);el.append(svg);});
 document.documentElement.classList.add('portal-js');
 const account=document.querySelector('.portal-account');
 const accountTrigger=account?.querySelector('summary');
 const accountItems=()=>[...account.querySelectorAll('nav a, form button')];
 const dismissAccount=(restore=false)=>{if(account){account.open=false;if(restore)accountTrigger.focus();}};
 account?.addEventListener('toggle',()=>accountTrigger.setAttribute('aria-expanded',String(account.open)));
 accountTrigger?.setAttribute('aria-expanded','false');
 document.addEventListener('click',e=>{if(account?.open&&!account.contains(e.target))dismissAccount();});
 account?.addEventListener('focusout',()=>queueMicrotask(()=>{if(!account.contains(document.activeElement))dismissAccount();}));
 account?.addEventListener('keydown',e=>{
  if(e.key==='Escape'&&account.open){e.preventDefault();e.stopPropagation();dismissAccount(true);return;}
  if(!['ArrowDown','ArrowUp','Home','End'].includes(e.key))return;
  if(!account.open&&!['ArrowDown','ArrowUp'].includes(e.key))return;
  e.preventDefault();account.open=true;const items=accountItems(),index=items.indexOf(document.activeElement);
  const next=e.key==='Home'?0:e.key==='End'?items.length-1:e.key==='ArrowDown'?(index+1)%items.length:index<=0?items.length-1:index-1;
  items[next]?.focus();
 });
 const toggle=document.querySelector('.portal-menu'),sidebar=document.querySelector('.portal-sidebar'),scrim=document.querySelector('.portal-scrim');
 const close=()=>{document.documentElement.classList.remove('portal-nav-open');toggle?.setAttribute('aria-expanded','false');if(scrim)scrim.hidden=true;if(sidebar)sidebar.inert=window.matchMedia('(max-width:800px)').matches;};
 toggle?.addEventListener('click',()=>{const open=toggle.getAttribute('aria-expanded')!=='true';document.documentElement.classList.toggle('portal-nav-open',open);toggle.setAttribute('aria-expanded',String(open));scrim.hidden=!open;sidebar.inert=!open;if(open)sidebar.querySelector('a')?.focus();});
 scrim?.addEventListener('click',close);
 document.addEventListener('keydown',e=>{if(e.key==='Escape'&&toggle?.getAttribute('aria-expanded')==='true'){close();toggle.focus();}if(e.key==='/'&&!e.ctrlKey&&!e.metaKey&&!e.altKey&&!e.target.matches('input,textarea,select,[contenteditable]')){e.preventDefault();document.querySelector('.portal-search input')?.focus();}if(e.key==='Tab'&&toggle?.getAttribute('aria-expanded')==='true'){const links=[...sidebar.querySelectorAll('a,button')];if(e.shiftKey&&document.activeElement===links[0]){e.preventDefault();links.at(-1).focus();}else if(!e.shiftKey&&document.activeElement===links.at(-1)){e.preventDefault();links[0].focus();}}});
 const mobile=window.matchMedia('(max-width:800px)');const adapt=()=>{close();sidebar.inert=mobile.matches;};mobile.addEventListener('change',adapt);adapt();
})();
