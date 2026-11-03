/** Formulários Pages: API privada com CSRF/revisão existentes; falhas preservam campos e documento do editor. */
(() => {
  'use strict';
  const root=document.querySelector('[data-pages-plugin]');if(!root)return;
  const status=root.querySelector('[data-pages-status]');
  const title=root.querySelector('[data-pages-title]'),slug=root.querySelector('[data-pages-slug]');let automatic=true,dirty=false;
  if(title&&slug){slug.addEventListener('input',()=>{automatic=false;});title.addEventListener('input',()=>{if(automatic)slug.value=title.value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'').slice(0,64);});}
  const working=root.querySelector('[data-page-markup-form]'),workState=root.querySelector('[data-page-work-state]');
  /** Mark only inputs belonging to the working document; receives input event, updates status/publication availability without saving. */
  function markDirty(event){if(event.target.form&&event.target.form!==working)return;dirty=true;if(workState){workState.textContent=workState.dataset.unsaved;workState.dataset.dirty='true';}for(const button of root.querySelectorAll('[data-page-publish]'))button.disabled=true;const help=root.querySelector('[data-page-publication-help]');if(help)help.textContent=working.dataset.unsaved;}
  working?.addEventListener('input',markDirty);working?.addEventListener('change',markDirty);
  const formatMode=root.querySelector('[data-page-mode]'),formatTabs=[...root.querySelectorAll('[data-page-format]')];let formatRequest=0;
  /** Reflect the existing submitted format; no arguments/return, updates panels and ARIA without changing either content buffer. */
  function reflectFormat(){
    if(!formatMode)return;
    root.querySelector('[data-page-authored]').hidden=!formatMode.checked;root.querySelector('[data-page-native]').hidden=formatMode.checked;
    for(const tab of formatTabs){const selected=(tab.dataset.pageFormat==='markup')===formatMode.checked;tab.setAttribute('aria-selected',String(selected));tab.tabIndex=selected?0:-1;}
  }
  /** Activate the requested tab after flushing visual edits; receives tab element, preserves buffers, reports sync errors and never persists. */
  async function selectFormat(tab){
    const request=++formatRequest,next=tab.dataset.pageFormat==='markup';if(next===formatMode.checked)return;
    try{if(formatMode.checked)await window.DBLESSEditor?.get(root.querySelector('[data-markup-html]'))?.flush();if(request!==formatRequest)return;formatMode.checked=next;formatMode.dispatchEvent(new Event('change',{bubbles:true}));}
    catch(error){status.textContent=error.message||root.dataset.networkError;status.hidden=false;}
  }
  formatMode?.addEventListener('change',reflectFormat);reflectFormat();
  for(const tab of formatTabs){
    tab.addEventListener('click',()=>selectFormat(tab));
    /** Move focus/selection with standard tablist keys; receives keyboard event, prevents scrolling only for handled keys. */
    tab.addEventListener('keydown',event=>{const index=formatTabs.indexOf(tab),target=event.key==='Home'?0:event.key==='End'?formatTabs.length-1:event.key==='ArrowRight'?(index+1)%formatTabs.length:event.key==='ArrowLeft'?(index+formatTabs.length-1)%formatTabs.length:null;if(target===null)return;event.preventDefault();formatTabs[target].focus();selectFormat(formatTabs[target]);});
  }
  root.querySelector('[data-page-apply-model]')?.addEventListener('click',async event=>{
    const button=event.currentTarget,field=root.querySelector('[data-markup-html]'),editor=window.DBLESSEditor.get(field);
    try{await editor.flush();if(field.value.trim()&&!window.confirm(button.dataset.confirm))return;const models=JSON.parse(button.dataset.models),choice=root.querySelector('[name="model"]').value;editor.setValue(models[choice]);const mode=root.querySelector('[data-page-mode]');mode.checked=true;mode.dispatchEvent(new Event('change',{bubbles:true}));}catch(error){status.textContent=error.message||root.dataset.networkError;status.hidden=false;}
  });
  // Required fields may be inside a collapsed properties disclosure; expose them before native validation focuses the field.
  working?.addEventListener('invalid',event=>{for(let parent=event.target.parentElement;parent&&parent!==working;parent=parent.parentElement)if(parent.tagName==='DETAILS')parent.open=true;},true);
  window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
  /** Insere bloco/marcador selecionado no JSON em edição, após sincronizar campos; não persiste e preserva texto inválido em falha. */
  root.querySelector('[data-insert-page-slide]')?.addEventListener('click',()=>{
    document.querySelector('[data-mode="json"]')?.click();
    const documentField=root.querySelector('[data-native-content]')||root.querySelector('[data-document]');
    try {
      const native=documentField.hasAttribute('data-native-content'),parsed=JSON.parse(documentField.value),value=native?{content:parsed}:parsed,location=root.querySelector('[data-page-slide-location]').value;
      if(root.querySelector('[data-page-slide-method]').value==='block'){
        value.content.blocks??=[];value.content.blocks.push({type:'slides',location});
      }else{
        value.content.sections??=[];value.content.sections.push({title:'',body:['{{slides:'+location+'}}'],list:[]});
      }
      documentField.value=JSON.stringify(native?value.content:value,null,2)+'\n';documentField.dispatchEvent(new Event('input',{bubbles:true}));documentField.focus();
    }catch{status.textContent=root.dataset.networkError;status.hidden=false;}
  });
  /** Submit the selected native form action with CSRF/revisions; receive submit events, preserve inputs on failures and follow only server-confirmed navigation. */
  for(const form of root.querySelectorAll('[data-pages-form]'))form.addEventListener('submit',async event=>{
    if(event.defaultPrevented)return;event.preventDefault();status.hidden=true;
    const submit=event.submitter,payload=new FormData(form);if(submit)submit.disabled=true;
    try {
      const response=await fetch(submit?.getAttribute('formaction')||form.getAttribute('action'),{method:'POST',body:payload,credentials:'same-origin',headers:{Accept:'application/json'}});
      if(!response.headers.get('content-type')?.includes('application/json'))throw new Error(root.dataset.networkError);
      const result=await response.json();
      if(!response.ok)throw new Error(result.error||root.dataset.networkError);
      const destination=new URL(result.redirect,location.href);if(destination.origin!==location.origin)throw new Error(root.dataset.networkError);dirty=false;location.assign(destination.href);
    } catch(error) {status.textContent=error instanceof Error?error.message:root.dataset.networkError;status.hidden=false;status.scrollIntoView({block:'nearest'});if(submit)submit.disabled=false;}
  });
  root.addEventListener('cms-component-insert',event=>{
    const documentField=root.querySelector('[data-document]');if(!documentField)return;
    try{const fieldsButton=document.querySelector('[data-mode="json"]');fieldsButton?.click();const value=JSON.parse(documentField.value);value.content.sections??=[];value.content.sections.push({title:'',body:[event.detail.marker],list:[]});documentField.value=JSON.stringify(value,null,2)+'\n';document.querySelector('[data-mode="fields"]')?.click();}catch(error){const status=root.querySelector('[data-pages-status]');status.textContent=error.message||root.dataset.networkError;status.hidden=false;}
  });
})();
