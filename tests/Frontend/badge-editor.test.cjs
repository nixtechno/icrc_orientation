const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');

function mount(withPanel=false){
 const node=(extra={})=>Object.assign({listeners:{},style:{},dataset:{},value:'',hidden:false,addEventListener(type,fn){this.listeners[type]=fn;},setAttribute(key,value){this[key]=value;},checkValidity(){return true;}},extra);
 const profile=node(),sizeForm=node(),print=node(),pdf=node({href:'http://localhost/badges/batch?ids[]=sample&pdf=1'}),unsaved=node({hidden:true});
 const controls=['name','title','organisation'].map(key=>node({name:key+'_size',value:key==='name'?'8.5':'3.4',dataset:{badgeSize:key}}));
 const texts=controls.map(c=>node({dataset:{badgeText:c.dataset.badgeSize},clientHeight:40,scrollHeight:40,clientWidth:300,scrollWidth:300}));
 const names=[node({textContent:'Original'}),node({textContent:'Original'})];
 const panel=node({hidden:true,inert:true}),sheet=node({focus(){this.focused=true;}}),close=node({focus(){this.focused=true;}});
 sizeForm.querySelector=selector=>controls.find(c=>selector.includes(`"${c.dataset.badgeSize}"`));sizeForm.querySelectorAll=()=>controls;
 const root=node({dataset:{widthScale:'1'},querySelector:selector=>({'[data-badge-profile-form]':profile,'[data-badge-size-form]':sizeForm,'[data-print-badge]':print,'[data-badge-pdf]':pdf,'[data-badge-unsaved]':unsaved})[selector],querySelectorAll:selector=>selector==='[data-badge-text]'?texts:names});
 if(withPanel){const original=root.querySelector;root.querySelector=selector=>({'[data-badge-panel]':panel,'.badge-sheet':sheet,'[data-close-badge-editor]':close})[selector]||original(selector);root.classList={toggle(key,value){root.open=value;}};}
 let prints=0;const history=[];const window=node({print(){prints++;},history:{replaceState(state,title,url){history.push(url.href);}}});
 vm.runInNewContext(fs.readFileSync('public/badge-editor.js','utf8'),{document:{querySelector:()=>root},window,location:{href:'http://localhost/badges/sample/edit'},URL});
 return {profile,sizeForm,print,pdf,unsaved,controls,texts,names,history,window,root,panel,sheet,close,get prints(){return prints;}};
}

test('editing profile text previews both faces safely and blocks printing until saved',()=>{
 const ui=mount();ui.profile.listeners.input({target:{dataset:{profileField:'first_name'},value:'<script>not markup</script>'}});
 assert.equal(ui.names[0].textContent,'<script>not markup</script>');assert.equal(ui.names[1].textContent,ui.names[0].textContent);
 assert.equal(ui.unsaved.hidden,false);assert.equal(ui.print.disabled,true);assert.equal(ui.pdf['aria-disabled'],'true');
 ui.print.listeners.click();assert.equal(ui.prints,0);let blocked=false;ui.pdf.listeners.click({preventDefault(){blocked=true;}});assert.equal(blocked,true);
});

test('text size controls update print styling and PDF query without saving profile data',()=>{
 const ui=mount();const control=ui.controls[0];control.value='7';control.matches=()=>true;
 ui.sizeForm.listeners.input({target:control});
 assert.equal(ui.texts[0].style.fontSize,'7mm');assert.match(ui.pdf.href,/name_size=7/);assert.match(ui.history[0],/name_size=7/);assert.equal(ui.unsaved.hidden,true);
});

test('explicit print opens the browser print dialog without recording collection',()=>{
 const ui=mount();ui.print.listeners.click();assert.equal(ui.prints,1);assert.equal(ui.unsaved.hidden,true);
});

test('badge editor starts hidden, opens on double click and closes with the close button or Escape',()=>{
 const ui=mount(true);assert.equal(ui.panel.hidden,true);assert.equal(ui.sheet['aria-expanded'],'false');assert.equal(ui.sheet.listeners.click,undefined);
 ui.sheet.listeners.dblclick();assert.equal(ui.panel.hidden,false);assert.equal(ui.panel.inert,false);assert.equal(ui.root.open,true);assert.equal(ui.close.focused,true);
 ui.close.listeners.click();assert.equal(ui.panel.hidden,true);assert.equal(ui.panel.inert,true);assert.equal(ui.sheet.focused,true);
 ui.sheet.listeners.keydown({key:'Enter',preventDefault(){}});assert.equal(ui.panel.hidden,false);
 ui.root.listeners.keydown({key:'Escape',preventDefault(){}});assert.equal(ui.panel.hidden,true);assert.equal(ui.sheet['aria-expanded'],'false');
});
