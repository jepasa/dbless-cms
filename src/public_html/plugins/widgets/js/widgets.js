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
  const members=root.querySelector('[data-widget-members]');
  if(members){
    const list=members.querySelector('[data-member-list]'),choice=members.querySelector('[data-member-choice]'),labels=JSON.parse(members.dataset.messages);
    members.querySelector('[data-member-add]').addEventListener('click',()=>{
      const id=choice.value;if(!id||list.children.length>=20||Array.from(list.children).some(row=>row.dataset.member===id))return;
      const row=document.createElement('li');row.dataset.member=id;const caption=document.createElement('span');caption.textContent=choice.selectedOptions[0].textContent;const input=document.createElement('input');input.type='hidden';input.name='members[]';input.value=id;row.append(caption,input);
      for(const [key,text] of [['up','↑'],['down','↓'],['remove','×']]){const button=document.createElement('button');button.type='button';button.className='button-secondary';button.dataset['member'+key[0].toUpperCase()+key.slice(1)]='';button.textContent=text;button.setAttribute('aria-label',labels[key]);row.append(button);}list.append(row);
    });
    list.addEventListener('click',event=>{const button=event.target.closest('button'),row=button?.closest('[data-member]');if(!row)return;if(button.hasAttribute('data-member-up')&&row.previousElementSibling)list.insertBefore(row,row.previousElementSibling);else if(button.hasAttribute('data-member-down')&&row.nextElementSibling)list.insertBefore(row.nextElementSibling,row);else if(button.hasAttribute('data-member-remove'))row.remove();});
  }
})();
