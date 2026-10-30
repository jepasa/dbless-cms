/** Editor Menu: árvore por dados, ordenação acessível/arraste e formulários autenticados com preservação de erros. */
(() => {
  'use strict';
  const root=document.querySelector('[data-menu-plugin]');if(!root)return;
  const status=root.querySelector('[data-menu-status]'),editor=root.querySelector('[data-menu-editor]');
  /** Exibe falha como texto; recebe mensagem, sem HTML nem alteração do documento submetido. */
  function failure(message) {status.textContent=message;status.hidden=false;status.scrollIntoView({block:'nearest'});}
  if(editor) {
    const labels=JSON.parse(editor.dataset.messages),pages=JSON.parse(editor.dataset.pages),list=editor.querySelector('[data-menu-items]');
    let items=JSON.parse(editor.dataset.state),dragged='';
    /** Traduz controle hardcoded do editor; recebe chave, retorna texto do catálogo administrativo. */
    function t(key) {return labels['menu.'+key]||key;}
    /** Cria nó com texto seguro; recebe tag/texto/classe, retorna elemento sem inseri-lo. */
    function element(tag,text='',className='') {const node=document.createElement(tag);node.textContent=text;if(className)node.className=className;return node;}
    /** Sincroniza somente o payload JSON; sem argumentos, não envia requisição. */
    function synchronize() {editor.elements.namedItem('items').value=JSON.stringify(items);}
    /** Identifica descendência com limite; recebe item/ancestral e retorna bool sem alterar itens. */
    function descendant(item,ancestor) {let parent=item.parent;for(let count=0;parent&&count<100;count++){if(parent===ancestor)return true;parent=items.find(node=>node.id===parent)?.parent||'';}return false;}
    /** Reordena irmãos sem alterar descendentes; recebe ID/deslocamento, redesenha e recupera foco do botão. */
    function move(id,offset) {
      const item=items.find(node=>node.id===id),siblings=items.filter(node=>node.parent===item.parent),other=siblings[siblings.indexOf(item)+offset];if(!other)return;
      const a=items.indexOf(item),b=items.indexOf(other);[items[a],items[b]]=[items[b],items[a]];render(id,offset<0?'up':'down');
    }
    /** Cria botão de operação acessível; recebe rótulo/ação/callback, retorna botão sem submeter formulário. */
    function button(key,action,callback) {const node=element('button',t(key),'button-secondary');node.type='button';node.dataset.itemAction=action;node.title=t(key);node.addEventListener('click',callback);return node;}
    /** Monta campo de item; recebe container/item/chave/tipo/opções; retorna controle associado e atualiza apenas o modelo em input. */
    function field(container,item,key,type='text',options=null) {
      const label=element('label',t(key)),control=element(options?'select':'input');control.id='menu-'+item.id+'-'+key;label.htmlFor=control.id;control.dataset.itemField=key;
      if(options)for(const [value,text] of options){const option=element('option',text);option.value=value;control.append(option);}
      else control.type=type;
      if(type==='checkbox')control.checked=item[key];else control.value=item[key];
      if(type==='text'){control.maxLength=key==='url'?2048:key==='anchor'?100:160;control.required=key!=='parent';}
      control.addEventListener('input',()=>{item[key]=type==='checkbox'?control.checked:control.value;synchronize();if(key==='label')container.closest('fieldset').querySelector('legend').textContent=item.label||t('items');});
      label.append(control);container.append(label);return control;
    }
    /** Reconstrói árvore visível sem innerHTML; recebe foco opcional, preserva dados e desabilita ações inviáveis. */
    function render(focusId='',action='') {
      list.replaceChildren();
      /** Insere ramo por parent; recebe ID/profundidade, cria campos e controles dos descendentes em ordem. */
      function branch(parent='',depth=0) {
        const siblings=items.filter(node=>node.parent===parent);
        for(const [position,item] of siblings.entries()) {
          const card=element('fieldset','','menu-item');card.dataset.itemId=item.id;card.dataset.depth=String(depth);card.append(element('legend',item.label||t('items')));
          const toolbar=element('div','','menu-item-toolbar'),handle=button('drag','drag',()=>{});handle.draggable=true;
          handle.addEventListener('dragstart',event=>{dragged=item.id;event.dataTransfer.setData('text/plain',item.id);event.dataTransfer.effectAllowed='move';});
          card.addEventListener('dragover',event=>{if(dragged&&dragged!==item.id&&items.find(node=>node.id===dragged)?.parent===item.parent)event.preventDefault();});
          card.addEventListener('drop',event=>{event.preventDefault();const from=items.findIndex(node=>node.id===dragged),to=items.indexOf(item);if(from>=0&&items[from].parent===item.parent){const [moved]=items.splice(from,1);items.splice(to,0,moved);render(moved.id,'drag');}dragged='';});
          handle.addEventListener('dragend',()=>{dragged='';});toolbar.append(handle);
          const up=button('up','up',()=>move(item.id,-1)),down=button('down','down',()=>move(item.id,1));up.disabled=position===0;down.disabled=position===siblings.length-1;toolbar.append(up,down);
          const indent=button('indent','indent',()=>{item.parent=siblings[position-1].id;render(item.id,'outdent');});indent.disabled=position===0||depth>=4||items.some(node=>descendant(node,item.id));
          const outdent=button('outdent','outdent',()=>{item.parent=items.find(node=>node.id===parent)?.parent||'';render(item.id,'indent');});outdent.disabled=!parent;toolbar.append(indent,outdent);
          toolbar.append(button('remove','remove',()=>{items=items.filter(node=>node.id!==item.id&&!descendant(node,item.id));render();editor.querySelector('[data-add-item]').focus();}));card.append(toolbar);
          const fields=element('div','','field-grid');field(fields,item,'label');
          const kind=field(fields,item,'kind','text',['page','anchor','url','group'].map(value=>[value,t(value)]));
          kind.addEventListener('change',()=>{item.page=['page','anchor'].includes(item.kind)?Object.keys(pages).find(value=>value!=='404')||'home':'';item.anchor='';item.url='';render(item.id,'kind');});
          const parents=[['',t('root')],...items.filter(node=>node.id!==item.id&&!descendant(node,item.id)).map(node=>[node.id,node.label||node.id])];
          const parentField=field(fields,item,'parent','text',parents);parentField.addEventListener('change',()=>render(item.id,'parent'));
          if(['page','anchor'].includes(item.kind))field(fields,item,'page','text',Object.entries(pages).filter(([slug])=>slug!=='404'));
          if(item.kind==='anchor')field(fields,item,'anchor');if(item.kind==='url')field(fields,item,'url');
          field(fields,item,'enabled','checkbox');field(fields,item,'newTab','checkbox');card.append(fields);list.append(card);branch(item.id,depth+1);
        }
      }
      branch();synchronize();
      if(focusId){const card=list.querySelector('[data-item-id="'+focusId+'"]');const target=card?.querySelector('[data-item-action="'+action+'"]:not(:disabled),[data-item-field="'+action+'"]')||card?.querySelector('[data-item-field="label"]');if(target)target.focus();}
    }
    /** Cria link sem nova aba por padrão; sem parâmetros/retorno, atualiza o editor ou informa o limite de 100 itens. */
    editor.querySelector('[data-add-item]').addEventListener('click',()=>{
      if(items.length>=100){failure(t('error.contract'));return;}
      const id='item-'+crypto.randomUUID().slice(0,12);items.push({id,parent:'',label:'',kind:'page',page:Object.keys(pages).find(slug=>slug!=='404')||'home',anchor:'',url:'',enabled:true,newTab:false});render(id,'label');
    });
    render();
  }
  for(const form of root.querySelectorAll('[data-menu-form]'))form.addEventListener('submit',async event=>{
    if(event.defaultPrevented)return;event.preventDefault();status.hidden=true;
    const buttons=[...form.querySelectorAll('button')],disabled=buttons.map(button=>button.disabled),payload=new FormData(form);buttons.forEach(button=>{button.disabled=true;});
    try {
      const response=await fetch(form.getAttribute('action'),{method:'POST',body:payload,credentials:'same-origin',headers:{Accept:'application/json'}});
      if(!response.headers.get('content-type')?.includes('application/json'))throw new Error(root.dataset.networkError);
      const result=await response.json();
      if(!response.ok)throw new Error(result.error||root.dataset.networkError);
      const destination=new URL(result.redirect,location.href);if(destination.origin!==location.origin)throw new Error(root.dataset.networkError);location.assign(destination.href);
    } catch(error){failure(error instanceof Error?error.message:root.dataset.networkError);buttons.forEach((button,index)=>{button.disabled=disabled[index];});}
  });
})();
