/** Shared direct-source authoring adapter: Core WYSIWYG/code, automatic plugin references and lossless hidden legacy bindings. No binding selectors or provider execution. */
(() => {
  'use strict';
  /** Mount an inert code profile; receives textarea, returns editor, propagates validation errors. */
  function code(textarea){return window.DBLESSEditor.mount(textarea,{language:textarea.dataset.codeLanguage||'html',visual:false});}
  /** Mount shared HTML authoring, preserving submitted bindings; receives root, registers change notifications without rewriting source or fetching data. */
  function mount(root){
    if(root.dataset.markupMounted)return;root.dataset.markupMounted='true';
    const html=root.querySelector('[data-markup-html]'),json=root.querySelector('[data-markup-bindings-json]');
    window.DBLESSEditor.mount(html,{language:'mustache'});
    html.addEventListener('input',()=>root.dispatchEvent(new CustomEvent('cms-markup-change',{bubbles:true,detail:{html:html.value,bindings:JSON.parse(json.value)}})));
  }
  /** Create an editor for a dynamic block; receives host/state/legacy metadata/labels, returns mounted root preserving legacy declarations, no HTML interpretation. */
  function create(parent,state,catalog,labels){
    const root=document.createElement('div'),label=document.createElement('label'),html=document.createElement('textarea'),json=document.createElement('input');
    root.className='markup-editor';root.dataset.markupEditor='';label.textContent=labels['markup.content']||'HTML';html.dataset.markupHtml='';html.value=state.html;html.maxLength=60000;html.rows=14;html.spellcheck=false;json.type='hidden';json.dataset.markupBindingsJson='';json.value=JSON.stringify(state.bindings||[]);label.append(html);root.append(label,json);parent.append(root);mount(root);return root;
  }
  window.DBLESSMarkup=Object.freeze({mount,create,code});for(const root of document.querySelectorAll('[data-markup-editor]'))mount(root);
})();
