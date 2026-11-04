/** Posts-specific identity suggestions; shared editorial synchronization, validation and CSRF submission remain in the canonical Pages/Core runtime. */
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
      const prefix=root.querySelector('[name="prefix"]');if(prefix&&policy)prefix.closest('label').hidden=policy.value!=='custom';
    }
    /** Show editor controls supported by the selected type; no input/return, retain inactive saved selections for server-side compatibility rather than erasing them. */
    function reflect(){
      if(!publicationType)return;const capabilities=JSON.parse(publicationType.selectedOptions[0].dataset.capabilities);
      for(const field of root.querySelectorAll('[data-post-capability]')){const available=capabilities[field.dataset.postCapability];field.disabled=!available;field.closest('label').hidden=!available&&field.selectedOptions.length===0;}
      const parent=root.querySelector('[name="parent"]'),order=root.querySelector('[name="order"]'),discussion=root.querySelector('[name="allowComments"]');if(parent){parent.disabled=!capabilities.hierarchical&&!parent.value;parent.closest('label').hidden=parent.disabled;}if(order){order.disabled=!capabilities.hierarchical;order.closest('label').hidden=order.disabled;}if(discussion){discussion.disabled=!capabilities.comments;discussion.closest('label').hidden=discussion.disabled;}
    }
    kind?.addEventListener('change',configure);policy?.addEventListener('change',configure);publicationType?.addEventListener('change',reflect);configure();reflect();
  });
})();
