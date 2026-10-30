/** Editor Slides: dados tipados, ordem por teclado/arraste, seletor público do File-Manager e POST revisionado sem perda em falhas. */
(() => {
  'use strict';
  const root=document.querySelector('[data-slides-plugin]');if(!root)return;
  const status=root.querySelector('[data-slides-status]'),editor=root.querySelector('[data-slides-editor]');
  /** Exibe erro textual sem apagar formulário; recebe mensagem e altera somente feedback. */
  function failure(message){status.textContent=message;status.hidden=false;status.scrollIntoView({block:'nearest'});}
  if(editor){
    const placement=editor.querySelector('[data-slides-placement]');
    if(placement){
      const state=JSON.parse(placement.dataset.placementState),mode=placement.querySelector('[data-placement-mode]'),page=placement.querySelector('[data-placement-page]');
      /** Filtra locais por destino/tipo, identifica revisão da página e atualiza link ao editor; não grava páginas ou conjuntos. */
      function updatePlacement(){
        const inPage=mode.value==='page',selected=state.pages[page.value],type=editor.elements.namedItem('contentType').value;
        placement.querySelector('[data-placement-page-label]').hidden=!inPage;
        placement.querySelector('[data-placement-page-help]').hidden=!inPage||!selected;
        placement.querySelector('[data-placement-edit-page]').href=placement.dataset.pageEditor+encodeURIComponent(page.value);
        placement.querySelector('[name="page_revision"]').value=selected?.revision||'';
        let count=0;
        for(const row of placement.querySelectorAll('[data-placement-location]')){
          const visible=row.dataset.contentType===type&&(!inPage||selected?.keys.includes(row.dataset.placementLocation));
          row.hidden=!visible;row.querySelector('input').disabled=!visible;if(visible)count++;
        }
        placement.querySelector('[data-placement-empty]').hidden=!inPage||count>0;
      }
      mode.addEventListener('change',updatePlacement);page.addEventListener('change',updatePlacement);editor.elements.namedItem('contentType').addEventListener('change',updatePlacement);updatePlacement();
    }
    const messages=JSON.parse(editor.dataset.messages),list=editor.querySelector('[data-slide-items]'),dialog=root.querySelector('[data-slide-media]');
    let items=JSON.parse(editor.dataset.state),dragged='',mediaTarget='',mediaPath='',mediaGeneration=0;
    /** Traduz controles do plugin; recebe chave e retorna texto administrativo. */
    function t(key){return messages['slides.'+key]||key;}
    /** Cria elemento textual seguro; recebe tag/texto/classe e retorna nó sem inserção automática. */
    function node(tag,text='',className=''){const element=document.createElement(tag);element.textContent=text;if(className)element.className=className;return element;}
    /** Atualiza payload JSON sem enviar ou gravar; não recebe parâmetros. */
    function synchronize(){editor.elements.namedItem('items').value=JSON.stringify(items);}
    /** Cria botão administrativo; recebe rótulo/callback e retorna botão sem submit. */
    function button(key,callback){const element=node('button',t(key),'button-secondary');element.type='button';element.title=t(key);element.addEventListener('click',callback);return element;}
    /** Reordena item por teclado e restaura foco; recebe índice/deslocamento, sem persistência remota. */
    function move(index,offset){const target=index+offset;if(target<0||target>=items.length)return;[items[index],items[target]]=[items[target],items[index]];render(items[target].id,offset<0?'up':'down');}
    /** Lista somente escopo público via API autenticada existente; recebe caminho e mantém diálogo aberto em falha. */
    async function media(path=''){
      const generation=++mediaGeneration;mediaPath=path;const listing=dialog.querySelector('[data-media-list]'),feedback=dialog.querySelector('[data-media-status]');listing.replaceChildren();feedback.textContent='';dialog.querySelector('[data-media-path]').textContent=path||'/';dialog.querySelector('[data-media-back]').disabled=path==='';
      try{
        const endpoint=new URL(editor.dataset.mediaApi,location.href);endpoint.searchParams.set('path',path);
        const response=await fetch(endpoint,{credentials:'same-origin',headers:{Accept:'application/json'}});if(!response.headers.get('content-type')?.includes('application/json'))throw new Error(t('network'));
        const result=await response.json();if(!response.ok)throw new Error(result.error||t('network'));if(generation!==mediaGeneration||!dialog.open)return;
        for(const entry of result.entries.filter(value=>value.directory||/\.(png|jpe?g|webp|gif|avif)$/i.test(value.name))){
          const choice=node('button');choice.type='button';choice.setAttribute('aria-label',entry.name);
          if(entry.directory)choice.append(node('span','▰ '+entry.name));
          else {const image=node('img');const reference=[editor.dataset.mediaRoot,entry.path].filter(Boolean).join('/');image.src=editor.dataset.base+'/'+reference.split('/').map(encodeURIComponent).join('/');image.alt='';image.loading='lazy';choice.append(image,node('span',entry.name));}
          choice.addEventListener('click',()=>{if(entry.directory){void media(entry.path);return;}const item=items.find(value=>value.id===mediaTarget);if(item){item.image=[editor.dataset.mediaRoot,entry.path].filter(Boolean).join('/');synchronize();render(item.id,'image');}dialog.close();});listing.append(choice);
        }
        if(!listing.childElementCount)feedback.textContent=t('noImages');
      }catch(error){if(generation===mediaGeneration)feedback.textContent=error instanceof Error?error.message:t('network');}
    }
    /** Abre seletor público sem acessar raiz privada; recebe item e foca diálogo ou exibe indisponibilidade. */
    function choose(item){if(!editor.dataset.mediaApi){failure(t('mediaUnavailable'));return;}mediaTarget=item.id;dialog.showModal();void media();}
    dialog.querySelector('[data-media-close]').addEventListener('click',()=>dialog.close());
    dialog.querySelector('[data-media-back]').addEventListener('click',()=>void media(mediaPath.split('/').slice(0,-1).join('/')));
    dialog.querySelector('[data-media-refresh]').addEventListener('click',()=>void media(mediaPath));
    dialog.addEventListener('close',()=>{mediaGeneration++;editor.querySelector(`[data-item-id="${mediaTarget}"] [data-choose-image]`)?.focus();});
    /** Constrói campo seguro ligado ao modelo; recebe item/chave/container, sem envio remoto. */
    function field(item,key,parent){
      const label=node('label',t(key)),input=node(key==='text'?'textarea':'input');input.id='slide-'+item.id+'-'+key;input.dataset.itemField=key;label.htmlFor=input.id;
      if(key==='enabled'){label.className='slides-checkbox';input.type='checkbox';input.checked=item[key];}else{input.value=item[key];input.maxLength=({title:200,text:2000,image:1024,alt:300,href:2048,label:100,attribution:200,price:80})[key];input.required=key==='title';}
      input.addEventListener('input',()=>{item[key]=key==='enabled'?input.checked:input.value;synchronize();if(key==='title')parent.closest('fieldset').querySelector('legend').textContent=item.title||t('items');});label.append(input);parent.append(label);
    }
    /** Redesenha itens a partir do modelo sem innerHTML; recebe foco opcional e preserva ordem/dados. */
    function render(focusId='',action=''){
      list.replaceChildren();
      for(const [index,item] of items.entries()){
        const card=node('fieldset','','slide-item');card.dataset.itemId=item.id;card.append(node('legend',item.title||t('items')));
        const toolbar=node('div','','slide-item-toolbar'),up=button('up',()=>move(index,-1)),down=button('down',()=>move(index,1));up.dataset.itemAction='up';down.dataset.itemAction='down';up.disabled=index===0;down.disabled=index===items.length-1;
        const drag=button('drag',()=>{});drag.draggable=true;drag.addEventListener('dragstart',event=>{dragged=item.id;event.dataTransfer.setData('text/plain',item.id);event.dataTransfer.effectAllowed='move';});drag.addEventListener('dragend',()=>{dragged='';});
        card.addEventListener('dragover',event=>{if(dragged&&dragged!==item.id)event.preventDefault();});card.addEventListener('drop',event=>{event.preventDefault();const from=items.findIndex(value=>value.id===dragged),to=items.indexOf(item);if(from>=0){const [moved]=items.splice(from,1);items.splice(to,0,moved);render(moved.id,'title');}dragged='';});
        const chooseButton=button('chooseImage',()=>choose(item));chooseButton.dataset.chooseImage='';toolbar.append(drag,up,down,chooseButton,button('remove',()=>{items=items.filter(value=>value!==item);render();editor.querySelector('[data-add-slide]').focus();}));card.append(toolbar);
        const fields=node('div','','field-grid');for(const key of ['title','text','image','alt','href','label','attribution','price','enabled'])field(item,key,fields);card.append(fields);list.append(card);
      }
      synchronize();if(focusId){const card=list.querySelector(`[data-item-id="${focusId}"]`);(card?.querySelector(`[data-item-action="${action}"]:not(:disabled),[data-item-field="${action}"]`)||card?.querySelector('[data-item-field="title"]'))?.focus();}
    }
    editor.querySelector('[data-add-slide]').addEventListener('click',()=>{if(items.length>=50){failure(t('error.contract'));return;}const item={id:'item-'+crypto.randomUUID().slice(0,12),title:'',text:'',image:'',alt:'',href:'',label:'',attribution:'',price:'',enabled:true};items.push(item);render(item.id,'title');});render();
  }
  for(const form of root.querySelectorAll('[data-slides-form]'))form.addEventListener('submit',async event=>{
    if(event.defaultPrevented)return;event.preventDefault();status.hidden=true;const payload=new FormData(form),buttons=[...form.querySelectorAll('button')],disabled=buttons.map(button=>button.disabled);buttons.forEach(button=>{button.disabled=true;});
    try{const response=await fetch(form.getAttribute('action'),{method:'POST',body:payload,credentials:'same-origin',headers:{Accept:'application/json'}});if(!response.headers.get('content-type')?.includes('application/json'))throw new Error(root.dataset.networkError);const result=await response.json();if(!response.ok)throw new Error(result.error||root.dataset.networkError);const destination=new URL(result.redirect,location.href);if(destination.origin!==location.origin)throw new Error(root.dataset.networkError);location.assign(destination.href);}
    catch(error){failure(error instanceof Error?error.message:root.dataset.networkError);buttons.forEach((button,index)=>{button.disabled=disabled[index];});}
  });
})();
