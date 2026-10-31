/** Authenticated Widgets forms: submit CSRF/revisions through the private API and preserve user input on errors. */
(() => {
  'use strict';
  const root=document.querySelector('[data-widgets-plugin]');if(!root)return;
  for(const form of root.querySelectorAll('[data-widget-form]'))form.addEventListener('submit',async event=>{
    if(event.defaultPrevented)return;event.preventDefault();
    const status=root.querySelector('[data-widget-status]'),submit=form.querySelector('[type="submit"]');submit.disabled=true;
    try{const response=await fetch(form.action,{method:'POST',body:new FormData(form),headers:{Accept:'application/json'},credentials:'same-origin'});const result=await response.json();if(!response.ok)throw new Error(result.message||result.error||root.dataset.networkError);const destination=new URL(result.redirect,location.href);if(destination.origin!==location.origin)throw new Error(root.dataset.networkError);window.location.assign(destination.href);}
    catch(error){status.textContent=error.message||root.dataset.networkError;status.hidden=false;status.scrollIntoView({block:'nearest'});submit.disabled=false;}
  });
})();
