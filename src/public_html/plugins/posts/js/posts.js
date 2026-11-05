/** Posts identity suggestions and accessible declarative-type dialogs; shared editorial synchronization and authenticated CSRF/CAS submission remain canonical. */
(() => {
  'use strict';
  document.addEventListener('DOMContentLoaded', () => {
    const root=document.querySelector('[data-posts-plugin]');if(!root)return;
    const name=root.querySelector('[data-post-entry-name]'),identity=root.querySelector('[data-post-entry-id]');let automatic=true;
    identity?.addEventListener('input',()=>{automatic=false;});
    /** Suggest a stable creation identity from the name; no inputs/return, changes only an unsaved empty/new field and never renames existing entries. */
    function suggest(){if(automatic&&identity)identity.value=name.value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'').slice(0,64);}
    name?.addEventListener('input',suggest);
    const kind=root.querySelector('[name="kind"]'),policy=root.querySelector('[name="routeMode"]'),publicationType=root.querySelector('[data-post-type]');
    /** Reflect fixed type-configuration capabilities without executing declarations; no input/return, only disable incompatible controls and expose a custom-prefix field when selected. */
    function configure(){
      if(kind){const article=kind.value==='article',comments=root.querySelector('[name="supports_comments"]'),hierarchy=root.querySelector('[name="supports_hierarchical"]');if(comments){comments.disabled=!article;if(!article)comments.checked=false;}if(hierarchy){hierarchy.disabled=article;if(article)hierarchy.checked=false;}}
      const prefix=root.querySelector('[name="prefix"]');if(prefix&&policy){prefix.closest('label').hidden=policy.value!=='custom';prefix.required=policy.value==='custom';}
    }
    /** Show editor controls supported by the selected type; no input/return, retain inactive saved selections for server-side compatibility rather than erasing them. */
    function reflect(){
      if(!publicationType)return;const capabilities=JSON.parse(publicationType.selectedOptions[0].dataset.capabilities);
      for(const field of root.querySelectorAll('[data-post-capability]')){const available=capabilities[field.dataset.postCapability];field.disabled=!available;field.closest('label').hidden=!available&&field.selectedOptions.length===0;}
      const parent=root.querySelector('[name="parent"]'),order=root.querySelector('[name="order"]'),discussion=root.querySelector('[name="allowComments"]');if(parent){parent.disabled=!capabilities.hierarchical&&!parent.value;parent.closest('label').hidden=parent.disabled;}if(order){order.disabled=!capabilities.hierarchical;order.closest('label').hidden=order.disabled;}if(discussion){discussion.disabled=!capabilities.comments;discussion.closest('label').hidden=discussion.disabled;}
    }
    kind?.addEventListener('change',configure);policy?.addEventListener('change',configure);publicationType?.addEventListener('change',reflect);configure();reflect();
    const dialog=root.querySelector('[data-post-type-dialog]');
    if(dialog){
      const form=dialog.querySelector('form'),records=JSON.parse(dialog.dataset.records),heading=dialog.querySelector('h2');let trigger=null;
      /**
       * Open the native modal, resetting unsaved fields and applying capability rules without persistence.
       * @param {string} id Existing immutable identity, or empty for creation.
       * @param {HTMLButtonElement|null} button Invoking control to refocus after cancellation.
       * @returns {void} Shows and focuses the dialog when the requested operation is available.
       */
      function openType(id='',button=null){
        if(!id&&dialog.dataset.canCreate!=='true')return;
        const type=id?records[id]:{id:'',singular:'',plural:'',kind:'page',routeMode:'default',prefix:'',groups:true,tags:true,comments:false,hierarchical:true};
        if(!type)return;
        trigger=button;form.reset();form.querySelector('[data-pages-form-status]').hidden=true;
        form.elements.namedItem('id').value=id;
        for(const key of ['id','singular','plural','kind','routeMode','prefix'])form.elements.namedItem(key==='id'?'typeId':key).value=type[key];
        const builtIn=['page','article'].includes(id);form.elements.namedItem('typeId').readOnly=!!id;
        for(const key of ['singular','plural'])form.elements.namedItem(key).required=!builtIn;
        // Disabling only incompatible options keeps the selected built-in rendering kind in FormData.
        for(const option of kind.options)option.disabled=builtIn&&option.value!==type.kind;
        for(const key of ['groups','tags','comments','hierarchical'])form.elements.namedItem('supports_'+key).checked=type[key];
        heading.textContent=id?dialog.dataset.editTitle:dialog.dataset.newTitle;configure();dialog.showModal();form.elements.namedItem(id?'singular':'typeId').focus();
      }
      root.querySelector('[data-post-type-new]')?.addEventListener('click',event=>openType('',event.currentTarget));
      for(const button of root.querySelectorAll('[data-post-type-edit]'))button.addEventListener('click',()=>openType(button.dataset.postTypeEdit,button));
      dialog.querySelector('[data-post-type-cancel]').addEventListener('click',()=>dialog.close());
      /**
       * Cycle Tab within visible enabled controls, preventing only boundary traversal into browser chrome.
       * @param {KeyboardEvent} event Native dialog key event; other keys retain their default behavior.
       * @returns {void} Moves focus only at the first/last control, without changing form values.
       */
      function keepTypeFocus(event){
        if(event.key!=='Tab')return;
        const fields=[...dialog.querySelectorAll('input:not([type="hidden"]):not(:disabled),select:not(:disabled),button:not(:disabled)')].filter(field=>field.getClientRects().length),first=fields[0],last=fields.at(-1);
        if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
        else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
      }
      dialog.addEventListener('keydown',keepTypeFocus);
      // Native Escape and Cancel share close handling; focus returns to the exact invoking control.
      dialog.addEventListener('close',()=>trigger?.focus());
      if(dialog.dataset.initial)openType(dialog.dataset.initial,root.querySelector('[data-post-type-edit="'+dialog.dataset.initial+'"]'));
    }
  });
})();
