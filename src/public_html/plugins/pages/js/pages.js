/** Formulários Pages: API privada com CSRF/revisão existentes; falhas preservam campos e documento do editor. */
(() => {
  'use strict';
  const root=document.querySelector('[data-pages-plugin]');if(!root)return;
  const status=root.querySelector('[data-pages-status]');
  /** Insere bloco/marcador selecionado no JSON em edição, após sincronizar campos; não persiste e preserva texto inválido em falha. */
  root.querySelector('[data-insert-page-slide]')?.addEventListener('click',()=>{
    document.querySelector('[data-mode="json"]').click();
    const documentField=root.querySelector('[data-document]');
    try {
      const value=JSON.parse(documentField.value),location=root.querySelector('[data-page-slide-location]').value;
      if(root.querySelector('[data-page-slide-method]').value==='block'){
        value.content.blocks??=[];value.content.blocks.push({type:'slides',location});
      }else{
        value.content.sections??=[];value.content.sections.push({title:'',body:['{{slides:'+location+'}}'],list:[]});
      }
      documentField.value=JSON.stringify(value,null,2)+'\n';documentField.dispatchEvent(new Event('input',{bubbles:true}));documentField.focus();
    }catch{status.textContent=root.dataset.networkError;status.hidden=false;}
  });
  for(const form of root.querySelectorAll('[data-pages-form]'))form.addEventListener('submit',async event=>{
    if(event.defaultPrevented)return;event.preventDefault();status.hidden=true;
    const submit=form.querySelector('button[type="submit"]'),payload=new FormData(form);if(submit)submit.disabled=true;
    try {
      const response=await fetch(form.getAttribute('action'),{method:'POST',body:payload,credentials:'same-origin',headers:{Accept:'application/json'}});
      if(!response.headers.get('content-type')?.includes('application/json'))throw new Error(root.dataset.networkError);
      const result=await response.json();
      if(!response.ok)throw new Error(result.error||root.dataset.networkError);
      const destination=new URL(result.redirect,location.href);if(destination.origin!==location.origin)throw new Error(root.dataset.networkError);location.assign(destination.href);
    } catch(error) {status.textContent=error instanceof Error?error.message:root.dataset.networkError;status.hidden=false;status.scrollIntoView({block:'nearest'});if(submit)submit.disabled=false;}
  });
  root.addEventListener('cms-component-insert',event=>{
    const documentField=root.querySelector('[data-document]');if(!documentField)return;
    try{const fieldsButton=document.querySelector('[data-mode="json"]');fieldsButton?.click();const value=JSON.parse(documentField.value);value.content.sections??=[];value.content.sections.push({title:'',body:[event.detail.marker],list:[]});documentField.value=JSON.stringify(value,null,2)+'\n';document.querySelector('[data-mode="fields"]')?.click();}catch(error){const status=root.querySelector('[data-pages-status]');status.textContent=error.message||root.dataset.networkError;status.hidden=false;}
  });
})();
