/** Opaque-origin visual canvas. Receives typed source models over a per-instance channel; never runs authored code, fetches providers or sends rendered snapshots for persistence. */
(() => {
  'use strict';
  const channel=window.name;
  if(!/^[a-f0-9]{32}$/.test(channel)||parent===window)return;
  let config=null,generation=0,lastRange=null,selected=null;const snapshots=new WeakMap();
  const root=document.createElement('main');root.className='dbless-canvas';document.body.append(root);
  /** Send a bounded operation to the owning host; receives type/data, returns nothing, using only the established opaque channel. */
  function send(type,data={}){parent.postMessage({channel,type,generation,...data},'*');}
  /** Resolve the active editable leaf without crossing the canvas; no arguments, returns element or null and never interprets source. */
  function active(){const selection=getSelection();const anchor=selection?.anchorNode;const element=anchor?.nodeType===Node.ELEMENT_NODE?anchor:anchor?.parentElement;return element?.closest('[data-dbless-editable]')??selected;}
  /** Remember a selection owned by one editable leaf; no arguments/return, ignores protected or cross-block ranges. */
  function remember(){const selection=getSelection();if(!selection?.rangeCount)return;const range=selection.getRangeAt(0),block=active();if(block&&block.contains(range.commonAncestorContainer)){lastRange=range.cloneRange();selected=block;send('selected',{id:block.dataset.dblessEditable});}}
  /** Restore the last owned selection; returns editable element or null, never permits cross-document selection. */
  function restore(){if(!lastRange||!root.contains(lastRange.commonAncestorContainer))return null;const selection=getSelection();selection.removeAllRanges();selection.addRange(lastRange);selected?.focus();return selected;}
  /** Decode text using an inert textarea; receives entity source, returns text without executing HTML or requesting resources. */
  function decode(value){const area=document.createElement('textarea');area.innerHTML=value.replace(/</g,'&lt;');return area.value;}
  /** Build visual tokens as noneditable nodes; receives raw source and parent, returns nothing and keeps token bytes in an internal marker. */
  function text(value,parentNode){let offset=0;for(const match of value.matchAll(/\{\{[\s\S]*?\}\}/g)){parentNode.append(document.createTextNode(decode(value.slice(offset,match.index))));const token=document.createElement('span');token.className='dbless-token';token.contentEditable='false';token.dataset.dblessToken=match[0];token.textContent=match[0];token.title=config.labels.token;parentNode.append(token);offset=match.index+match[0].length;}parentNode.append(document.createTextNode(decode(value.slice(offset))));}
  /** Construct one validated model node using DOM APIs; receives model node, returns inert node, with no HTML string injection. */
  function build(node){
    if(node.type==='comment')return document.createComment(node.raw.slice(4,-3));
    if(node.type==='text'){const fragment=document.createDocumentFragment();text(node.raw,fragment);return fragment;}
    const element=document.createElement(node.tag||'div');element.dataset.dblessOrder=JSON.stringify(Object.keys(node.attrs||{}));
    for(const [key,value] of Object.entries(node.attrs||{})){
      // Preserve URL expressions in the model; the canvas does not request unresolved or remote images.
      if(key==='src'){element.dataset.dblessSrc=value;if(value.includes('{{'))continue;const url=new URL(value,config.publicBase);if(url.origin!==config.origin||url.pathname.includes('/cms-admin/'))continue;element.setAttribute('src',url.href);continue;}
      if(key==='href'){element.dataset.dblessHref=value;continue;}
      if(key==='tabindex'||key==='hidden'){element.dataset[key==='hidden'?'dblessHidden':'dblessTabindex']=value;if(config.preview&&key==='hidden')element.setAttribute(key,value);continue;}
      element.setAttribute(key,value);
    }
    for(const child of node.children||[])element.append(build(child));
    if(node.editable&&!config.readOnly){element.contentEditable='true';element.dataset.dblessEditable=node.id;element.setAttribute('role','textbox');element.setAttribute('aria-label',config.labels.block);element.setAttribute('aria-multiline','true');}
    if(node.protected){element.classList.add('dbless-protected');element.title=config.labels.protected;}
    if(node.tag==='button'){element.dataset.dblessDisabled=Object.hasOwn(node.attrs,'disabled')?'1':'0';element.disabled=true;}
    if(node.editable&&!config.readOnly)snapshots.set(element,serialize(element));
    return element;
  }
  /** Escape text or attribute data without creating DOM nodes; receives value/attribute flag, returns inert HTML text and never starts resource requests. */
  function escape(value,attribute=false){const text=value.replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;');return attribute?text.replaceAll('"','&quot;'):text;}
  /** Serialize one owned DOM node as text; receives node, restores source attributes in a plain map and never assigns src/href or mounts a clone. */
  function serializeNode(current){
    if(current.nodeType===Node.TEXT_NODE)return escape(current.data);
    if(current.nodeType===Node.COMMENT_NODE)return '<!--'+current.data+'-->';
    if(current.nodeType!==Node.ELEMENT_NODE)return '';
    if(current.hasAttribute('data-dbless-token'))return escape(current.dataset.dblessToken);
    const attributes=new Map([...current.attributes].filter(attribute=>!attribute.name.startsWith('data-dbless-')&&!['contenteditable','spellcheck'].includes(attribute.name)).map(attribute=>[attribute.name,attribute.value]));
    for(const [marker,name] of [['data-dbless-href','href'],['data-dbless-src','src'],['data-dbless-hidden','hidden'],['data-dbless-tabindex','tabindex']])if(current.hasAttribute(marker))attributes.set(name,current.getAttribute(marker));
    if(current.dataset.dblessDisabled==='0')attributes.delete('disabled');
    const order=JSON.parse(current.dataset.dblessOrder||'[]'),names=[...new Set([...order,...attributes.keys()])].filter(name=>attributes.has(name));
    const tag=current.tagName.toLowerCase(),start='<'+tag+names.map(name=>' '+name+'="'+escape(attributes.get(name),true)+'"').join('')+'>';
    return ['img','br','hr'].includes(tag)?start:start+[...current.childNodes].map(serializeNode).join('')+'</'+tag+'>';
  }
  /** Serialize only one edited leaf without resource side effects; receives element, returns HTML/source markers with no editor metadata or URL hydration. */
  function serialize(element){return [...element.childNodes].map(serializeNode).join('');}
  /** Commit one editable leaf as a source patch; receives element, posts only its ID/interior and preserves all other original source bytes. */
  function commit(element){if(!element?.dataset.dblessEditable)return;const html=serialize(element);if(snapshots.get(element)===html)return;snapshots.set(element,html);send('patch',{id:element.dataset.dblessEditable,html});}
  /** Split an owned paragraph selection into two source patches; receives block/range, sends serialized halves while retaining protected tokens. */
  function split(block,range){
    if(!(block.dataset.dblessEditable==='root'||['p','h1','h2','h3','h4','h5','h6','blockquote'].includes(block.tagName.toLowerCase()))||!block.contains(range.commonAncestorContainer)||crossesToken(range,block))return;
    const before=range.cloneRange(),after=range.cloneRange();before.selectNodeContents(block);before.setEnd(range.startContainer,range.startOffset);after.selectNodeContents(block);after.setStart(range.endContainer,range.endOffset);
    const left=document.createElement('div'),right=document.createElement('div');left.append(before.cloneContents());right.append(after.cloneContents());send('split',{id:block.dataset.dblessEditable,before:serialize(left),after:serialize(right)});
  }
  /** Check whether a selection would partially remove a token; receives range/block, returns true if any protected marker intersects. */
  function crossesToken(range,block){return [...block.querySelectorAll('[data-dbless-token]')].some(token=>range.intersectsNode(token));}
  /** Apply a closed rich-text command to an owned selection; receives name/value, emits a validated host patch, never evaluates scripts or uses arbitrary browser commands. */
  function command(name,value){
    const block=restore();if(!block)return;const selection=getSelection(),range=selection.getRangeAt(0);if(!block.contains(range.commonAncestorContainer)||crossesToken(range,block))return;
    if(['bold','italic','link'].includes(name)){
      if(name==='link'){const anchor=range.startContainer.nodeType===Node.ELEMENT_NODE?range.startContainer:range.startContainer.parentElement;const existing=anchor.closest('a');if(existing&&block.contains(existing)){existing.dataset.dblessHref=value.url;existing.setAttribute('href',value.url);remember();commit(block);return;}}
      if(range.collapsed&&name!=='link')return;const element=document.createElement(name==='bold'?'strong':name==='italic'?'em':'a');
      if(name==='link'){element.dataset.dblessHref=value.url;element.setAttribute('href',value.url);if(value.text&&range.collapsed)element.textContent=value.text;}
      if(!range.collapsed)element.append(range.extractContents());range.insertNode(element);range.selectNodeContents(element);selection.removeAllRanges();selection.addRange(range);
    }else if(name==='image'){const image=document.createElement('img');image.setAttribute('src',value.url);image.setAttribute('alt',value.alt);range.deleteContents();range.insertNode(image);range.setStartAfter(image);range.collapse(true);selection.removeAllRanges();selection.addRange(range);}
    remember();commit(block);
  }
  /** Render a new safe model for visual or read-only preview mode; receives payload, replaces canvas, restores no arbitrary HTML, and acknowledges readiness. */
  function render(data){
    config=data;generation=data.generation;lastRange=null;selected=null;
    for(const link of document.querySelectorAll('[data-template-style]'))link.remove();
    if(data.preview)for(const href of data.styles){const link=document.createElement('link');link.rel='stylesheet';link.href=href;link.dataset.templateStyle='';document.head.prepend(link);}
    root.replaceChildren();if(data.tree.editable)root.append(build(data.tree));else for(const node of data.tree.children)root.append(build(node));document.documentElement.lang=data.locale;root.dir=data.direction;root.classList.toggle('dbless-readonly',data.readOnly);if(data.focusId){const block=[...root.querySelectorAll('[data-dbless-editable]')].find(item=>item.dataset.dblessEditable===data.focusId);if(block){block.focus();const range=document.createRange();range.selectNodeContents(block);range.collapse(true);const selection=getSelection();selection.removeAllRanges();selection.addRange(range);remember();}}send('rendered');
  }
  /** Insert only host-validated inline clipboard nodes or plain text; receives typed payload, preserves token boundaries and emits one source patch. */
  function paste(data){
    const block=restore()||active(),selection=getSelection();if(!block||block.dataset.dblessEditable!==data.id||!selection?.rangeCount)return;const range=selection.getRangeAt(0);if(!block.contains(range.commonAncestorContainer)||crossesToken(range,block))return;
    const fragment=document.createDocumentFragment();if(Array.isArray(data.nodes))for(const child of data.nodes)fragment.append(build(child));else fragment.append(document.createTextNode(data.text));
    const last=fragment.lastChild;range.deleteContents();range.insertNode(fragment);if(last)range.setStartAfter(last);range.collapse(true);selection.removeAllRanges();selection.addRange(range);remember();commit(block);
  }
  window.addEventListener('message',event=>{
    if(event.source!==parent||!event.data||event.data.channel!==channel)return;const data=event.data;
    if(data.type==='render'&&Array.isArray(data.tree?.children)&&Array.isArray(data.styles))render(data);
    else if(config&&data.generation===generation&&data.type==='command'&&['bold','italic','link','image'].includes(data.name)&&!config.readOnly)command(data.name,data.value);
    else if(config&&data.generation===generation&&data.type==='paste'&&!config.readOnly)paste(data);
    else if(config&&data.generation===generation&&data.type==='flush'){if(selected)commit(selected);send('flushed',{request:data.request});}
  });
  root.addEventListener('input',event=>commit(event.target.closest('[data-dbless-editable]')));
  root.addEventListener('keyup',remember);root.addEventListener('mouseup',remember);document.addEventListener('selectionchange',remember);
  root.addEventListener('click',event=>{if(event.target.closest('a,button,summary'))event.preventDefault();});
  root.addEventListener('submit',event=>event.preventDefault());root.addEventListener('drop',event=>event.preventDefault());
  root.addEventListener('keydown',event=>{if(event.key==='Enter'&&!event.shiftKey&&!event.isComposing){const block=active(),selection=getSelection();if(block&&selection?.rangeCount&&block.tagName.toLowerCase()!=='pre'){event.preventDefault();split(block,selection.getRangeAt(0));return;}}if((event.ctrlKey||event.metaKey)&&['z','y','b','i'].includes(event.key.toLowerCase())){event.preventDefault();if(['b','i'].includes(event.key.toLowerCase()))command(event.key.toLowerCase()==='b'?'bold':'italic');else send('history',{redo:event.key.toLowerCase()==='y'||event.shiftKey});}if(['Backspace','Delete'].includes(event.key)){const block=active(),selection=getSelection();if(block&&selection?.rangeCount&&crossesToken(selection.getRangeAt(0),block))event.preventDefault();}});
  root.addEventListener('beforeinput',event=>{const block=active(),selection=getSelection();if(block&&selection?.rangeCount&&crossesToken(selection.getRangeAt(0),block))event.preventDefault();if(['insertParagraph','insertOrderedList','insertUnorderedList'].includes(event.inputType))event.preventDefault();});
  root.addEventListener('paste',event=>{
    event.preventDefault();if(config.readOnly)return;const block=restore()||active(),selection=getSelection();if(!block||!selection?.rangeCount)return;const range=selection.getRangeAt(0);if(!block.contains(range.commonAncestorContainer)||crossesToken(range,block))return;
    send('paste',{id:block.dataset.dblessEditable,html:event.clipboardData?.getData('text/html')||'',text:event.clipboardData?.getData('text/plain')||''});
  });
  send('ready');
})();
