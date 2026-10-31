/** Homepage private authoring: accessible section ordering, bounded composition, shared data editor and same-origin revisioned saves; no HTML interpolation. */
(() => {
  'use strict';
  const root=document.querySelector('[data-homepage-plugin]');if(!root)return;
  const messages=JSON.parse(root.dataset.language),composer=root.querySelector('[data-homepage-composer]'),form=root.querySelector('[data-homepage-editor]'),hidden=root.querySelector('[data-homepage-composition]');let focusedBody=null;
  /** Translate a hardcoded Homepage control; receive key, return text or throw on a missing dictionary entry without DOM writes. */
  function text(key){const result=messages['homepage.'+key];if(typeof result!=='string')throw new Error('Missing Homepage translation');return result;}
  /** Serialize visible order and fields into the draft composition input; no arguments/return, modifies only the pending form and preserves unsupported stored keys. */
  function synchronizeComposition(){
    if(!composer)return;const layout=JSON.parse(hidden.value),rows=[...composer.querySelectorAll('[data-homepage-section]')],known=new Set(rows.map(row=>row.dataset.homepageSection));
    layout.order=[...rows.map(row=>row.dataset.homepageSection),...layout.order.filter(key=>!known.has(key))];layout.hidden=[...layout.hidden.filter(key=>!known.has(key)),...rows.filter(row=>row.dataset.native==='true'&&!row.querySelector('[data-section-visible]').checked).map(row=>row.dataset.homepageSection)];
    layout.sections=rows.filter(row=>row.dataset.native!=='true').map(row=>({key:row.dataset.homepageSection,title:row.querySelector('[data-section-title]').value,body:row.querySelector('[data-section-body]').value.split(/\n\s*\n/).map(value=>value.trim()).filter(Boolean),visible:row.querySelector('[data-section-visible]').checked}));hidden.value=JSON.stringify(layout);
  }
  /** Build one escaped section control from trusted row data; receive entry, append DOM and return its row; unknown values never become HTML. */
  function addRow(entry){
    const row=document.createElement('section');row.className='homepage-section';row.dataset.homepageSection=entry.key;row.dataset.native=String(entry.section===null);
    const toolbar=document.createElement('div');toolbar.className='homepage-section-toolbar';const title=document.createElement('strong');title.textContent=entry.name;toolbar.append(title);
    for(const [action,symbol] of [['up','↑'],['down','↓'],...(entry.section!==null?[['remove','×']]:[])]){const button=document.createElement('button');button.type='button';button.className='button-secondary';button.dataset.sectionAction=action;button.textContent=symbol;button.setAttribute('aria-label',text(action));toolbar.append(button);}row.append(toolbar);
    const label=document.createElement('label'),visible=document.createElement('input');label.className='homepage-visibility';visible.type='checkbox';visible.dataset.sectionVisible='';visible.checked=entry.visible;visible.disabled=entry.required;label.append(visible,document.createTextNode(' '+text(entry.required?'required':'visible')));row.append(label);
    if(entry.section!==null){for(const [key,caption,tag] of [['title','title','input'],['body','body','textarea']]){const field=document.createElement('label');field.textContent=text(caption);const input=document.createElement(tag);input.dataset[key==='body'?'sectionBody':'sectionTitle']='';input.value=key==='body'?entry.section.body.join('\n\n'):entry.section.title;input.maxLength=key==='body'?60000:200;if(key==='body')input.addEventListener('focus',()=>{focusedBody=input;});field.append(input);row.append(field);}const id=document.createElement('small');id.className='muted';id.textContent=text('key')+': '+entry.key;row.append(id);}
    composer.querySelector('[data-homepage-rows]').append(row);return row;
  }
  if(composer){
    for(const entry of JSON.parse(composer.dataset.rows))addRow(entry);
    composer.querySelector('[data-homepage-add]').addEventListener('click',()=>{if(composer.querySelectorAll('[data-section-body]').length>=20)return;const key='section-'+crypto.randomUUID().replaceAll('-','').slice(0,20),row=addRow({key,name:text('custom'),required:false,visible:true,section:{title:'',body:[]}});row.querySelector('[data-section-title]').focus();});
    composer.addEventListener('click',event=>{const button=event.target.closest('[data-section-action]'),row=button?.closest('[data-homepage-section]');if(!row)return;const list=row.parentElement;if(button.dataset.sectionAction==='up'&&row.previousElementSibling)list.insertBefore(row,row.previousElementSibling);else if(button.dataset.sectionAction==='down'&&row.nextElementSibling)list.insertBefore(row.nextElementSibling,row);else if(button.dataset.sectionAction==='remove'&&window.confirm(text('removeConfirm'))){if(focusedBody===row.querySelector('[data-section-body]'))focusedBody=null;const key=row.dataset.homepageSection,layout=JSON.parse(hidden.value);layout.order=layout.order.filter(value=>value!==key);layout.hidden=layout.hidden.filter(value=>value!==key);layout.sections=layout.sections.filter(section=>section.key!==key);hidden.value=JSON.stringify(layout);row.remove();}});
    root.addEventListener('cms-component-insert',event=>{if(!focusedBody){const key='section-'+crypto.randomUUID().replaceAll('-','').slice(0,20),row=addRow({key,name:text('custom'),required:false,visible:true,section:{title:'',body:[]}});focusedBody=row.querySelector('[data-section-body]');}focusedBody.value+=(focusedBody.value.trim()?'\n\n':'')+event.detail.marker;focusedBody.focus();synchronizeComposition();});
  }
  for(const target of root.querySelectorAll('[data-homepage-form]'))target.addEventListener('submit',async event=>{
    if(event.defaultPrevented)return;event.preventDefault();const button=target.querySelector('[type=submit]'),status=root.querySelector('[data-homepage-status]');button.disabled=true;
    try{if(target===form)synchronizeComposition();const response=await fetch(target.action,{method:'POST',body:new FormData(target),credentials:'same-origin',headers:{Accept:'application/json'}}),result=await response.json();if(!response.ok)throw new Error(result.message||result.error||root.dataset.networkError);const destination=new URL(result.redirect,location.href);if(destination.origin!==location.origin)throw new Error(root.dataset.networkError);location.assign(destination.href);}
    catch(error){status.textContent=error.message||root.dataset.networkError;status.hidden=false;status.scrollIntoView({block:'nearest'});button.disabled=false;}
  });
  const dialog=root.querySelector('[data-homepage-media]');let mediaInput=null,mediaPath='',generation=0;
  /** List only public Images files from the declared same-origin API; receive path, render safe choices and retain the field on failures without private downloads. */
  async function browse(path=''){
    const current=++generation;mediaPath=path;const list=dialog.querySelector('[data-homepage-media-list]'),status=dialog.querySelector('[data-homepage-media-status]');list.replaceChildren();status.textContent='';dialog.querySelector('[data-homepage-media-path]').textContent=path||'/';dialog.querySelector('[data-homepage-media-back]').disabled=path==='';
    try{
      const endpoint=new URL(root.dataset.mediaApi,location.href);if(endpoint.origin!==location.origin)throw new Error(text('mediaUnavailable'));endpoint.searchParams.set('path',path);const response=await fetch(endpoint,{credentials:'same-origin',headers:{Accept:'application/json'}}),data=await response.json();if(!response.ok)throw new Error(data.error||text('mediaUnavailable'));if(current!==generation||!dialog.open)return;
      for(const entry of data.entries.filter(item=>item.directory||/\.(png|jpe?g|webp|gif|avif)$/i.test(item.name))){
        const button=document.createElement('button');button.type='button';button.className='button-secondary';button.setAttribute('aria-label',entry.name);
        const reference=[root.dataset.mediaRoot,entry.path].filter(Boolean).join('/');if(!entry.directory){const image=document.createElement('img');image.src=root.dataset.base+'/'+reference.split('/').map(encodeURIComponent).join('/');image.alt='';image.loading='lazy';button.append(image);}const caption=document.createElement('span');caption.textContent=entry.name;button.append(caption);
        button.addEventListener('click',()=>{if(entry.directory){void browse(entry.path);return;}if(mediaInput){mediaInput.value=reference;mediaInput.dispatchEvent(new Event('input',{bubbles:true}));}dialog.close();});list.append(button);
      }
    }catch(error){if(current===generation)status.textContent=error.message||text('mediaUnavailable');}
  }
  /** Attach media choices to structured image fields after Fields/JSON changes; no arguments/return, adds only localized buttons and never mutates content until selection. */
  function mediaControls(){
    for(const input of root.querySelectorAll('input[data-path]')){const trail=JSON.parse(input.dataset.path);if(!['image','hero','profile','feature','featuredImage'].includes(trail.at(-1))||input.parentElement.querySelector('[data-homepage-pick]'))continue;const button=document.createElement('button');button.type='button';button.className='button-secondary';button.dataset.homepagePick='';button.textContent=text('selectImage');button.addEventListener('click',()=>{if(!root.dataset.mediaApi){const status=root.querySelector('[data-homepage-status]');status.textContent=text('mediaUnavailable');status.hidden=false;return;}mediaInput=input;dialog.showModal();void browse();});input.parentElement.append(button);}
  }
  if(dialog&&form){mediaControls();new MutationObserver(mediaControls).observe(root.querySelector('[data-fields]'),{childList:true,subtree:true});dialog.querySelector('[data-homepage-media-close]').addEventListener('click',()=>dialog.close());dialog.querySelector('[data-homepage-media-back]').addEventListener('click',()=>void browse(mediaPath.split('/').slice(0,-1).join('/')));}
})();

/** Homepage publication tables/editor use the shared data/code controls; preserve entered source and display typed API failures without automatic reloads. */
(() => {
  'use strict';
  const root=document.querySelector('[data-homepage-catalog]');if(!root)return;const status=root.querySelector('[data-catalog-status]'),editor=root.querySelector('[data-homepage-publication]');
  if(editor){
    const mode=editor.querySelector('[data-homepage-mode]'),plugin=editor.querySelector('[data-source-plugin]'),documentId=editor.querySelector('[data-source-id]'),sources=JSON.parse(editor.dataset.sources),saved=JSON.parse(editor.dataset.source),warning=editor.querySelector('[data-source-warning]'),local=editor.querySelector('[data-publication-local]'),utc=editor.querySelector('[data-publication-utc]'),timezone=editor.querySelector('[data-publication-timezone]');
    /** Fill source IDs for one selected plugin, preserving an unavailable saved reference; no arguments/return or network calls. */
    function sourceOptions(){documentId.replaceChildren();const items=sources[plugin.value]?.items||[];for(const item of items){const option=document.createElement('option');option.value=item.id;option.textContent=item.name;documentId.append(option);}if(plugin.value===saved.plugin&&saved.id&&!items.some(item=>item.id===saved.id)){const option=document.createElement('option');option.value=saved.id;option.textContent=saved.id;documentId.append(option);}if(plugin.value===saved.plugin)documentId.value=saved.id;warning.hidden=mode.value!=='source'||items.some(item=>item.id===documentId.value);}
    /** Toggle mode-specific controls while preserving dormant source/code values; no arguments/return, never mutates published data. */
    function fields(){editor.querySelector('[data-homepage-html]').hidden=mode.value!=='html';editor.querySelector('[data-homepage-source]').hidden=mode.value!=='source';editor.querySelector('[data-homepage-legacy]').hidden=mode.value!=='legacy';editor.querySelector('[data-markup-html]').required=mode.value==='html';plugin.required=mode.value==='source';documentId.required=mode.value==='source';warning.hidden=mode.value!=='source'||(sources[plugin.value]?.items||[]).some(item=>item.id===documentId.value);}
    /** Convert explicit UTC/browser-local form input to UTC seconds; no arguments/return, reject invalid/nonexistent local times rather than silently adjust them. */
    function publication(){local.setCustomValidity('');if(!local.value){utc.value='';return;}const value=new Date(local.value+(timezone.value==='UTC'?'Z':''));if(Number.isNaN(value.getTime())){local.setCustomValidity(root.dataset.dateError);return;}const comparable=timezone.value==='UTC'?value.toISOString().slice(0,19):new Date(value.getTime()-value.getTimezoneOffset()*60000).toISOString().slice(0,19);if(comparable!==local.value.padEnd(19,':00').slice(0,19)){local.setCustomValidity(root.dataset.dateError);return;}utc.value=value.toISOString().slice(0,19)+'Z';}
    plugin.addEventListener('change',sourceOptions);documentId.addEventListener('change',fields);mode.addEventListener('change',fields);local.addEventListener('input',publication);timezone.addEventListener('change',()=>{if(utc.value){const value=new Date(utc.value);local.value=(timezone.value==='UTC'?value:new Date(value.getTime()-value.getTimezoneOffset()*60000)).toISOString().slice(0,19);}publication();});sourceOptions();fields();publication();
    editor.addEventListener('submit',publication);
  }
  for(const form of root.querySelectorAll('[data-catalog-form]'))form.addEventListener('submit',async event=>{
    if(event.defaultPrevented)return;event.preventDefault();if(form.dataset.submitting==='true')return;if(!form.reportValidity())return;const body=new FormData(form);if(event.submitter?.name)body.set(event.submitter.name,event.submitter.value);const buttons=[...form.querySelectorAll('button[type="submit"],button:not([type])')];form.dataset.submitting='true';for(const button of buttons)button.disabled=true;
    try{const response=await fetch(form.action,{method:'POST',body,headers:{Accept:'application/json'},credentials:'same-origin'}),payload=await response.json();if(!response.ok)throw new Error(payload.message||payload.error||root.dataset.networkError);if(!payload.redirect)throw new Error(root.dataset.networkError);window.location.assign(payload.redirect);}
    catch(error){status.textContent=error.message||root.dataset.networkError;status.hidden=false;status.scrollIntoView({block:'nearest'});form.dataset.submitting='false';for(const button of buttons)button.disabled=false;}
  });
})();
