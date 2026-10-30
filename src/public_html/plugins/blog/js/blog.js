/** Blog admin interactions: typed blocks, authenticated image selection, accessible reordering and CAS form submission with entered data preserved on failure. */
(() => {
  'use strict';
  const root=document.querySelector('[data-blog-plugin]');if(!root)return;
  const messages=JSON.parse(root.dataset.messages),status=root.querySelector('[data-blog-status]'),editor=root.querySelector('[data-blog-editor]');
  let dirty=false;
  /** Look up plugin-owned administrative copy; takes key, returns plain text without HTML insertion. */
  function t(key){return messages['blog.'+key]||key;}
  /** Show a recoverable request error; takes message and preserves all form values, with no network effects. */
  function failure(message){status.textContent=message;status.hidden=false;status.scrollIntoView({block:'nearest'});}
  /** Create an unattached safe text node; takes tag/text/class, returns DOM element with no parsed markup. */
  function node(tag,text='',className=''){const element=document.createElement(tag);element.textContent=text;if(className)element.className=className;return element;}
  for(const form of root.querySelectorAll('[data-blog-form]')){
    form.addEventListener('input',()=>{dirty=true;});
    const title=form.querySelector('[data-blog-title]'),slug=form.querySelector('[data-blog-slug]');
    if(title&&slug&&!slug.readOnly){let automatic=slug.value==='';slug.addEventListener('input',()=>{automatic=false;});title.addEventListener('input',()=>{if(automatic)slug.value=title.value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'').replace(/^[^a-z]+/,'').slice(0,64);});}
  }
  window.addEventListener('beforeunload',event=>{if(dirty){event.preventDefault();event.returnValue='';}});
  if(editor){
    let blocks=JSON.parse(editor.dataset.state),mediaPath='',generation=0,mediaSelection=null,mediaFocus=null,mediaBlockIndex=null;
    const list=editor.querySelector('[data-blog-blocks]'),dialog=root.querySelector('[data-blog-media]');
    /** Synchronize block JSON without submitting; no arguments/return, does not mutate other fields. */
    function synchronize(){editor.elements.namedItem('blocks').value=JSON.stringify(blocks);}
    /** Create an accessible non-submit control; takes label key/callback, returns a button with translated text/title. */
    function button(key,callback){const element=node('button',t(key),'button-secondary');element.type='button';element.title=t(key);element.addEventListener('click',callback);return element;}
    /** Attach a bounded block field; takes parent/block/key/type/choices and returns control, editing only the current model. */
    function field(parent,block,key,type='textarea',choices=null){
      const label=node('label',t(key)),input=node(choices?'select':type==='checkbox'?'input':type);input.dataset.blockField=key;input.setAttribute('aria-label',t(key));
      if(choices)for(const value of choices){const option=node('option',String(value));option.value=String(value);input.append(option);}
      if(type==='checkbox'){label.className='editor-checkbox';input.type='checkbox';input.checked=block[key];}
      else input.value=Array.isArray(block[key])?block[key].join('\n'):String(block[key]);
      if(type==='input')input.maxLength=['src','url'].includes(key)?2048:500;
      if(type==='textarea'){input.rows=block.type==='code'?8:4;input.maxLength=12000;}
      input.addEventListener('input',()=>{block[key]=key==='level'?Number(input.value):key==='items'?input.value.split('\n').filter(item=>item.trim()!==''):type==='checkbox'?input.checked:input.value;synchronize();});
      label.append(input);parent.append(label);return input;
    }
    /** Rebuild typed blocks and restore an optional focus position; takes index or null, preserves all entered values and sets order controls. */
    function render(focus=null){
      list.replaceChildren();
      blocks.forEach((block,index)=>{
        const card=node('fieldset','','blog-block');card.dataset.blockIndex=String(index);card.append(node('legend',String(index+1)+' · '+t(block.type==='list'?'listBlock':block.type)));
        const toolbar=node('div','','blog-admin-toolbar');
        const up=button('up',()=>move(index,-1)),down=button('down',()=>move(index,1));up.disabled=index===0;down.disabled=index===blocks.length-1;toolbar.append(up,down,button('remove',()=>{blocks.splice(index,1);dirty=true;render(Math.min(index,blocks.length-1));}));card.append(toolbar);
        if(block.type==='heading'){field(card,block,'level','input',[2,3]);field(card,block,'text');}
        else if(block.type==='image'){
          field(card,block,'src','input');field(card,block,'alt','input');field(card,block,'caption','input');
          const choose=button('chooseImage',()=>chooseImage(reference=>{block.src=reference;synchronize();render(index);},choose));card.append(choose);
        }else if(block.type==='list'){field(card,block,'items');field(card,block,'ordered','checkbox');}
        else if(block.type==='link'){field(card,block,'text','input');field(card,block,'url','input');}
        else field(card,block,'text');
        list.append(card);
      });
      synchronize();if(focus!==null)list.querySelector(`[data-block-index="${focus}"] input,[data-block-index="${focus}"] textarea`)?.focus();
    }
    /** Move one block using accessible controls; takes index/offset, changes local model and restores field focus without a request. */
    function move(index,offset){const target=index+offset;if(target<0||target>=blocks.length)return;[blocks[index],blocks[target]]=[blocks[target],blocks[index]];dirty=true;render(target);}
    editor.querySelector('[data-add-block]').addEventListener('click',()=>{
      if(blocks.length>=100){failure(t('error.contract'));return;}
      const type=editor.querySelector('[data-block-type]').value;
      blocks.push(type==='heading'?{type,text:'',level:2}:type==='image'?{type,src:'',alt:'',caption:''}:type==='list'?{type,items:[],ordered:false}:type==='link'?{type,text:'',url:''}:{type,text:''});dirty=true;render(blocks.length-1);
    });
    /** List public images through the existing Images API; takes relative path, rejects other origins, ignores stale completions, and keeps errors within the dialog. */
    async function media(path=''){
      const current=++generation;mediaPath=path;const listing=dialog.querySelector('[data-media-list]'),feedback=dialog.querySelector('[data-media-status]');listing.replaceChildren();feedback.textContent='';dialog.querySelector('[data-media-path]').textContent=path||'/';dialog.querySelector('[data-media-back]').disabled=path==='';
      try{
        const endpoint=new URL(editor.dataset.mediaApi,location.href);if(endpoint.origin!==location.origin)throw new Error(t('network'));endpoint.searchParams.set('path',path);
        const response=await fetch(endpoint,{credentials:'same-origin',headers:{Accept:'application/json'}});
        if(!response.headers.get('content-type')?.includes('application/json'))throw new Error(t('network'));const data=await response.json();if(!response.ok)throw new Error(data.error||t('network'));if(current!==generation||!dialog.open)return;
        for(const entry of data.entries.filter(item=>item.directory||/\.(png|jpe?g|webp|gif|avif)$/i.test(item.name))){
          const choice=node('button');choice.type='button';choice.setAttribute('aria-label',entry.name);
          if(entry.directory)choice.append(node('span','▰ '+entry.name));
          else{const image=node('img');const reference=[editor.dataset.mediaRoot,entry.path].filter(Boolean).join('/');image.src=editor.dataset.base+'/'+reference.split('/').map(encodeURIComponent).join('/');image.alt='';image.loading='lazy';choice.append(image,node('span',entry.name));}
          choice.addEventListener('click',()=>{if(entry.directory){void media(entry.path);return;}mediaSelection?.([editor.dataset.mediaRoot,entry.path].filter(Boolean).join('/'));dirty=true;dialog.close();});listing.append(choice);
        }
        if(!listing.childElementCount)feedback.textContent=t('empty');
      }catch(error){if(current===generation)feedback.textContent=error instanceof Error?error.message:t('network');}
    }
    /** Open the Images selector; takes setter/trigger, focuses the dialog or reports disabled integration, with no private file access. */
    function chooseImage(setter,trigger){if(!editor.dataset.mediaApi){failure(t('mediaUnavailable'));return;}mediaSelection=setter;mediaFocus=trigger;mediaBlockIndex=trigger.closest('[data-block-index]')?.dataset.blockIndex;dialog.showModal();void media();}
    const cover=editor.querySelector('[data-choose-cover]');cover.addEventListener('click',()=>chooseImage(reference=>{editor.elements.namedItem('cover').value=reference;},cover));
    dialog.querySelector('[data-media-close]').addEventListener('click',()=>dialog.close());dialog.querySelector('[data-media-back]').addEventListener('click',()=>void media(mediaPath.split('/').slice(0,-1).join('/')));dialog.querySelector('[data-media-refresh]').addEventListener('click',()=>void media(mediaPath));
    dialog.addEventListener('close',()=>{generation++;if(mediaFocus?.isConnected)mediaFocus.focus();else if(mediaBlockIndex!==undefined)editor.querySelector(`[data-block-index="${mediaBlockIndex}"] [data-block-field="src"]`)?.focus();});render();
  }
  for(const form of root.querySelectorAll('[data-blog-form]'))form.addEventListener('submit',async event=>{
    if(event.defaultPrevented)return;event.preventDefault();status.hidden=true;
    const buttons=[...form.querySelectorAll('button')],disabled=buttons.map(button=>button.disabled),payload=new FormData(form);buttons.forEach(button=>{button.disabled=true;});
    try{
      const response=await fetch(form.action,{method:'POST',body:payload,credentials:'same-origin',headers:{Accept:'application/json'}});
      if(!response.headers.get('content-type')?.includes('application/json'))throw new Error(t('network'));const result=await response.json();if(!response.ok)throw new Error(result.error||t('network'));
      const destination=new URL(result.redirect,location.href);if(destination.origin!==location.origin)throw new Error(t('network'));dirty=false;location.assign(destination.href);
    }catch(error){failure(error instanceof Error?error.message:t('network'));}finally{buttons.forEach((button,index)=>{button.disabled=disabled[index];});}
  });
})();
