/** Core CSS/JS editing: inert highlighting, advisory syntax parsing and saved-draft preview in an opaque sandbox. Never evaluates authored code in the admin. */
(() => {
  'use strict';
  const form=document.querySelector('[data-customizations]');if(!form)return;
  for(const textarea of form.querySelectorAll('[data-code-language]'))window.DBLESSMarkup.code(textarea,()=>form.dataset.tabLabel);
  const status=form.querySelector('[data-custom-syntax]');let parsing=false,validated=null;
  /** Parse enabled authored JavaScript without execution; takes submit event, preserves draft on failure and resubmits with the original action after successful parsing. */
  async function validate(event){
    if(validated===event.submitter){validated=null;return;}if(!['custom-save','custom-publish'].includes(event.submitter?.value))return;
    event.preventDefault();if(parsing)return;parsing=true;const button=event.submitter,code=form.elements.js_code.value;status.hidden=true;
    try{if(code.trim())await window.Terser.minify(code,{compress:false,mangle:false,sourceMap:false});setTimeout(()=>{validated=button;form.requestSubmit(button);},0);}catch(error){status.textContent=form.dataset.syntaxLabel+': '+error.message;status.hidden=false;status.focus();}finally{parsing=false;}
  }
  form.addEventListener('submit',validate);
  document.querySelector('[data-custom-preview]').addEventListener('click',event=>{const button=event.currentTarget,frame=document.createElement('iframe');frame.title=button.textContent;frame.setAttribute('sandbox','allow-scripts');frame.referrerPolicy='no-referrer';frame.src=button.dataset.customPreview;frame.className='customization-preview';document.querySelector('[data-custom-preview-host]').replaceChildren(frame);});
})();
