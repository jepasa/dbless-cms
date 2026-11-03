/** Opt-in Core editorial shell: responsive properties and keyboard access without saving, mounting editors or changing plugin documents. */
(() => {
  'use strict';
  for(const root of document.querySelectorAll('[data-editorial-workspace]')){
    const properties=root.querySelector('[data-editorial-properties]'),button=root.querySelector('[data-editorial-properties-toggle]');
    if(!properties||!button)continue;
    properties.open=matchMedia('(min-width: 1000px)').matches;
    /** Reflect the native disclosure state; no arguments/return, updates only accessibility state. */
    function sync(){button.setAttribute('aria-expanded',String(properties.open));}
    button.addEventListener('click',()=>{properties.open=!properties.open;sync();if(properties.open&&matchMedia('(max-width: 999px)').matches){properties.querySelector('summary').focus();properties.scrollIntoView({block:'nearest'});}});
    properties.addEventListener('toggle',sync);
    properties.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();properties.open=false;sync();button.focus();}});
    sync();
  }
})();
