/** Shared editor API: inert PHP/CSS/JS source profiles and isolated HTML/Mustache WYSIWYG. Original textarea and source patches remain authoritative; no authored program is executed. */
(() => {
  'use strict';
  const model=window.DBLESSEditorModel,instances=new WeakMap(),live=new Set();let nextId=0;
  const metadata=document.querySelector('meta[name="dbless-editor"]');
  const config=metadata?JSON.parse(metadata.content):null;
  /** Create a text-only UI element; receives tag/text/class, returns element without HTML interpretation. */
  function node(tag,text='',className=''){const result=document.createElement(tag);result.textContent=text;result.className=className;return result;}
  /** Translate a shared editor key; receives key, returns administrator-selected text or a stable key when not configured. */
  function t(key){return config?.labels[key]??key;}
  /** Escape a trusted asset URL for the constant iframe scaffold; receives text, returns attribute-safe text. */
  function escape(value){return value.replaceAll('&','&amp;').replaceAll('"','&quot;').replaceAll('<','&lt;');}
  /** Emit a genuine textarea input event; receives field, returns nothing and lets plugin bindings synchronize their existing model. */
  function changed(field){field.dispatchEvent(new Event('input',{bubbles:true}));}
  /** Add inert highlighting and optional Tab indentation; receives textarea/translator/options, returns owned nodes and cleanup, never evaluates code. */
  function code(textarea,translate=()=>t('captureTab'),input={}){
    if(textarea._dblessCode)return textarea._dblessCode;
    const settings=model.options({language:textarea.dataset.codeLanguage||'html',visual:false,...input});
    textarea.dataset.codeMounted='true';textarea.dataset.codeLanguage=settings.language;textarea.readOnly=settings.readOnly;
    const wrapper=node('div','','markup-code'),preview=node('pre'),numbers=node('pre','','markup-lines'),indent=node('input'),label=node('label','','editor-checkbox');
    preview.setAttribute('aria-hidden','true');numbers.setAttribute('aria-hidden','true');indent.type='checkbox';indent.disabled=settings.readOnly;label.append(indent,document.createTextNode(translate('captureTab')));
    const originalLabel=textarea.closest('label');if(originalLabel){textarea.id||='dbless-code-'+(++nextId);originalLabel.htmlFor=textarea.id;originalLabel.after(label,wrapper);}else textarea.before(label,wrapper);wrapper.append(numbers,preview,textarea);
    /** Refresh text highlighting without changing submitted bytes; no arguments/return or evaluation. */
    function refresh(){
      preview.replaceChildren();const value=textarea.value;let offset=0;
      const pattern=settings.language==='css'?/\/\*[\s\S]*?\*\/|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|#[a-fA-F0-9]{3,8}\b|[{}:;]/g:settings.language==='js'||settings.language==='php'?/\/\*[\s\S]*?\*\/|\/\/[^\n]*|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|<\?(?:php|=)?|\?>|\$[a-zA-Z_][a-zA-Z0-9_]*|\b(?:const|let|function|return|if|else|class|new|true|false|null|await|async|echo|foreach|public|private|namespace|use|declare|throw)\b/g:/\{\{[^{}]*\}\}|<\/?[a-zA-Z][^>]*>/g;
      for(const match of value.matchAll(pattern)){preview.append(document.createTextNode(value.slice(offset,match.index)),node('span',match[0],match[0].startsWith('{{')?'markup-token-mustache':'markup-token-html'));offset=match.index+match[0].length;}preview.append(document.createTextNode(value.slice(offset)+'\n'));
      numbers.textContent=Array.from({length:Math.min(4097,value.split('\n').length)},(_,index)=>String(index+1)).join('\n');scroll();
    }
    /** Mirror scrolling in inert decorations; no arguments/return or source mutation. */
    function scroll(){preview.scrollTop=textarea.scrollTop;preview.scrollLeft=textarea.scrollLeft;numbers.scrollTop=textarea.scrollTop;}
    /** Handle opt-in indentation while allowing Escape to release Tab; receives keyboard event, edits only the selection and emits input. */
    function key(event){if(event.key==='Escape'){indent.checked=false;return;}if(event.key==='Tab'&&indent.checked&&!textarea.readOnly){event.preventDefault();const start=textarea.selectionStart,end=textarea.selectionEnd;if(event.shiftKey){if(textarea.value.slice(Math.max(0,start-2),start)==='  ')textarea.setRangeText('',start-2,start,'end');}else textarea.setRangeText('  ',start,end,'end');changed(textarea);}}
    textarea.addEventListener('input',refresh);textarea.addEventListener('scroll',scroll);textarea.addEventListener('keydown',key);refresh();
    const result={wrapper,label,refresh,destroy(){textarea.removeEventListener('input',refresh);textarea.removeEventListener('scroll',scroll);textarea.removeEventListener('keydown',key);wrapper.before(textarea);wrapper.remove();label.remove();delete textarea._dblessCode;delete textarea.dataset.codeMounted;}};textarea._dblessCode=result;return result;
  }
  class Editor {
    /** Mount a profile on one textarea; receives field/options, initializes local UI and throws before any unsafe rendering for invalid configuration. */
    constructor(textarea,input={}){
      if(!(textarea instanceof HTMLTextAreaElement))throw new TypeError('A textarea is required.');
      this.options=model.options(input);this.textarea=textarea;this.originalReadOnly=textarea.readOnly;this.mode='code';this.history=[];this.future=[];this.generation=0;this.syncEvents=new EventTarget();this.ready=false;this.lastSource=textarea.value;
      this.originalRows=textarea.rows;textarea.rows=Math.max(6,Math.ceil((this.options.height-32)/21));this.code=code(textarea,()=>t('captureTab'),this.options);this.wrapper=node('section','','dbless-editor');this.wrapper.dataset.coreEditor=this.options.language;
      this.code.wrapper.before(this.wrapper);this.wrapper.append(this.code.label,this.code.wrapper);this.status=node('p','','muted dbless-editor-status');this.status.setAttribute('role','status');this.status.setAttribute('aria-live','polite');this.wrapper.append(this.status);
      this.input=()=>{if(this.internal)return;this.lastSource=this.textarea.value;this.update();if(this.mode!=='code')this.render();};textarea.addEventListener('input',this.input);
      if(this.options.language==='html'&&this.options.visual&&config)this.visual();else this.status.textContent=this.options.language==='php'?t('phpHelp'):t('codeHelp');
      instances.set(textarea,this);live.add(this);textarea.dataset.editorMounted='true';
    }
    /** Build the shared mode/format controls and opaque canvas; no arguments/return, all executable assets are trusted addressed local files. */
    visual(){
      this.toolbar=node('div','','dbless-editor-toolbar');this.toolbar.setAttribute('role','toolbar');this.toolbar.setAttribute('aria-label',t('tools'));this.buttons=new Map();
      for(const mode of ['visual','code','preview'])this.button(mode,()=>this.setMode(mode));
      if(!this.options.readOnly){this.button('undo',()=>this.undo(false));this.button('redo',()=>this.undo(true));for(const tool of this.options.toolbar)this.button(tool,()=>this.tool(tool));}
      this.wrapper.prepend(this.toolbar);this.frame=node('iframe','','dbless-editor-canvas');this.frame.title=t('canvas');this.frame.setAttribute('sandbox','allow-scripts');this.frame.referrerPolicy='no-referrer';
      this.channel=Array.from(crypto.getRandomValues(new Uint8Array(16)),value=>value.toString(16).padStart(2,'0')).join('');this.frame.name=this.channel;
      const origin=location.origin,script=new URL(config.script,location.href),style=new URL(config.style,location.href);
      if(script.origin!==origin||style.origin!==origin)throw new TypeError('Editor assets must be local.');
      this.frame.height=String(this.options.height);
      this.frame.srcdoc='<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="default-src &apos;none&apos;; script-src '+escape(origin)+'; style-src '+escape(origin)+'; img-src '+escape(origin)+'; font-src '+escape(origin)+'; connect-src &apos;none&apos;; frame-src &apos;none&apos;; object-src &apos;none&apos;; base-uri &apos;none&apos;; form-action &apos;none&apos;"><link rel="stylesheet" href="'+escape(style.href)+'"><script src="'+escape(script.href)+'" defer></script></head><body></body></html>';
      this.code.wrapper.after(this.frame);this.listener=event=>this.receive(event);window.addEventListener('message',this.listener);this.setMode('visual');
      this.invalid=()=>{this.setMode('code');this.textarea.focus();};this.textarea.addEventListener('invalid',this.invalid);
    }
    /** Add one localized non-submit command button; receives key/callback, returns element and records it for state updates. */
    button(key,callback){const button=node('button',t(key),'button-secondary');button.type='button';button.dataset.editorAction=key;button.title=t(key);button.addEventListener('click',async()=>{try{await callback();}catch(error){this.status.textContent=t('invalid')+' '+error.message;}});this.toolbar.append(button);this.buttons.set(key,button);return button;}
    /** Validate the current HTML and update advisory feedback; no arguments, returns model or null while keeping invalid source intact. */
    update(){
      try{this.model=model.analyze(this.textarea.value,this.options,config.policy);this.issue=null;this.status.textContent=this.model.tree?.protected||this.model.tokens.some(token=>['#','^','/','form','fragment'].includes(token.kind))?t('protected'):t('valid');return this.model;}
      catch(error){this.issue=error;this.model=null;this.status.textContent=t('invalid')+' '+error.message;return null;}
    }
    /** Switch an allowed editing mode without normalizing source; receives mode, returns nothing, retains invalid buffers in Code mode. */
    setMode(mode){
      if(!['visual','code','preview'].includes(mode)||!this.frame)return;
      if(mode!=='code'&&!this.update())mode='code';this.mode=mode;this.code.wrapper.hidden=mode!=='code';this.code.label.hidden=mode!=='code';this.frame.hidden=mode==='code';
      for(const [key,button] of this.buttons){if(['visual','code','preview'].includes(key))button.setAttribute('aria-pressed',String(key===mode));else button.disabled=mode!=='visual'||this.options.readOnly;}
      if(mode!=='code')this.render();else this.textarea.focus();
    }
    /** Send a validated source model to this opaque frame; no arguments/return, never includes session, provider values or PHP execution results. */
    render(){
      if(!this.ready||this.mode==='code')return;const current=this.update();if(!current)return;this.generation++;this.selected=null;
      const focusId=this.focusAfter;this.focusAfter=null;this.send('render',{focusId,tree:current.tree,readOnly:this.mode==='preview'||this.options.readOnly,preview:this.mode==='preview',styles:config.styles,locale:config.locale,direction:config.direction,origin:location.origin,publicBase:new URL(config.publicBase,location.href).href,labels:config.labels});
    }
    /** Post a closed operation on this instance channel; receives type/payload, returns nothing. */
    send(type,data={}){this.frame.contentWindow.postMessage({type,channel:this.channel,generation:this.generation,...data},'*');}
    /** Accept only this frame's opaque messages; receives event, validates operation shape/generation and applies bounded source patches or history actions. */
    receive(event){
      if(event.source!==this.frame.contentWindow||event.origin!=='null'||!event.data||event.data.channel!==this.channel)return;const data=event.data;
      if(data.type==='ready'){this.ready=true;this.render();return;}if(data.generation!==this.generation)return;
      if(data.type==='flushed'&&typeof data.request==='string'&&/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(data.request)){this.syncEvents.dispatchEvent(new CustomEvent('flush-ack',{detail:data.request}));return;}
      if(data.type==='selected'&&typeof data.id==='string'&&this.model?.blocks.some(block=>block.id===data.id)){this.selected=data.id;return;}
      if(data.type==='split'&&this.mode==='visual'&&!this.options.readOnly&&typeof data.id==='string'&&typeof data.before==='string'&&typeof data.after==='string'&&data.before.length+data.after.length<=131072){
        const block=this.model?.blocks.find(item=>item.id===data.id);if(!block||!['','p','h1','h2','h3','h4','h5','h6','blockquote'].includes(block.tag))return;
        const source=this.textarea.value,first=block.tag?source.slice(block.outerStart,block.start)+data.before+source.slice(block.end,block.outerEnd):'<p>'+data.before+'</p>',next=source.slice(0,block.outerStart)+first+'\n<p>'+data.after+'</p>'+source.slice(block.outerEnd);
        try{const parsed=model.analyze(next,this.options,config.policy),old=model.mustache(source,this.options.mustache);if(old.length!==parsed.tokens.length||old.some((token,index)=>token.raw!==parsed.tokens[index].raw))throw new SyntaxError('Protected tokens changed.');this.remember(source);this.write(next);this.focusAfter=parsed.blocks.find(item=>item.outerStart===block.outerStart+first.length+1)?.id;this.render();}catch(error){this.render();this.status.textContent=t('invalid')+' '+error.message;}return;
      }
      if(data.type==='paste'&&this.mode==='visual'&&!this.options.readOnly&&typeof data.id==='string'&&typeof data.text==='string'&&typeof data.html==='string'&&data.text.length<=131072&&data.html.length<=131072&&this.model?.blocks.some(block=>block.id===data.id)){
        let nodes=null;
        try{if(data.html){const clipboard=model.analyze(data.html,{language:'html',readOnly:true},config.policy);nodes=clipboard.tree.children;if(nodes.length===1&&nodes[0].tag==='p')nodes=nodes[0].children;
          /** Require inline clipboard elements; receives node, returns bool without executing or normalizing source. */
          const inline=node=>node.type!=='element'||(['strong','b','em','i','span','a','br','code','small','img'].includes(node.tag)&&node.children.every(inline));if(!nodes.every(inline))nodes=null;}}
        catch{/* Unsupported clipboard markup falls back explicitly to its text/plain representation. */}
        this.send('paste',{id:data.id,nodes,text:data.text});if(!nodes)this.status.textContent=t('pastePlain');return;
      }
      if(data.type==='history'&&typeof data.redo==='boolean'){this.undo(data.redo);return;}
      if(data.type!=='patch'||this.mode!=='visual'||this.options.readOnly||typeof data.id!=='string'||typeof data.html!=='string'||data.html.length>131072)return;
      const block=this.model?.blocks.find(item=>item.id===data.id);if(!block)return;
      const source=this.textarea.value,candidate=source.slice(0,block.start)+data.html+source.slice(block.end);if(candidate===source)return;
      try{
        const next=model.analyze(candidate,this.options,config.policy);const previousTokens=model.mustache(source.slice(block.start,block.end),this.options.mustache),nextTokens=model.mustache(data.html,this.options.mustache);
        if(previousTokens.length!==nextTokens.length||previousTokens.some((token,index)=>token.raw!==nextTokens[index].raw))throw new SyntaxError('Protected tokens changed.');
        // Update known leaf ranges in place so queued keystrokes never target a newly parsed ID.
        const delta=data.html.length-(block.end-block.start);for(const item of this.model.blocks){if(item===block){item.end+=delta;item.outerEnd+=delta;}else if(item.start>=block.end-delta){item.start+=delta;item.end+=delta;item.outerStart+=delta;item.outerEnd+=delta;}}
        this.remember(source);this.write(candidate);this.model.source=candidate;this.model.tree=next.tree;this.status.textContent=t('valid');
      }catch(error){this.render();this.status.textContent=t('invalid')+' '+error.message;}
    }
    /** Write authored bytes and emit the established plugin event path; receives source, no return, does not execute or persist anything. */
    write(source){this.internal=true;this.textarea.value=source;this.lastSource=source;changed(this.textarea);this.code.refresh();this.internal=false;}
    /** Save a bounded visual undo snapshot; receives previous source, returns nothing, trims memory without changing persisted history. */
    remember(source){if(this.history.at(-1)!==source)this.history.push(source);while(this.history.length>60||this.history.reduce((sum,item)=>sum+item.length,0)>2097152)this.history.shift();this.future=[];}
    /** Undo/redo one visual transaction; receives direction flag, returns nothing and preserves all content outside explicit edits. */
    undo(redo=false){if(this.mode!=='visual'||this.options.readOnly)return;const from=redo?this.future:this.history,to=redo?this.history:this.future;if(!from.length)return;to.push(this.textarea.value);this.write(from.pop());this.render();}
    /** Apply an explicit toolbar action; receives closed tool name, edits only a chosen source block or delegates safe inline commands. */
    async tool(name){
      if(this.mode!=='visual'||this.options.readOnly)return;await this.flush();
      if(name==='link'||name==='image'){const value=await this.dialog(name);if(value)this.send('command',{name,value});return;}
      if(['bold','italic'].includes(name)){this.send('command',{name});return;}
      const source=this.textarea.value,block=this.model?.blocks.find(item=>item.id===this.selected);let next;
      if(name==='paragraph')next=source+'\n<p>'+t('newText')+'</p>';
      else if(block&&['p','h1','h2','h3','h4','h5','h6','blockquote','pre','div','section','article'].includes(block.tag)){const raw=source.slice(block.outerStart,block.outerEnd),tag=name==='heading'?'h2':name==='quote'?'blockquote':block.tag;let replacement;
        if(name==='list')replacement='<ul>'+raw.replace(new RegExp('^<'+block.tag+'(?=[\\s>])'),'<li').replace(new RegExp('</'+block.tag+'>$'),'</li>')+'</ul>';
        else replacement=raw.replace(new RegExp('^<'+block.tag+'(?=[\\s>])'),'<'+tag).replace(new RegExp('</'+block.tag+'>$'),'</'+tag+'>');
        next=source.slice(0,block.outerStart)+replacement+source.slice(block.outerEnd);
      }
      if(next!==undefined&&next!==source){model.analyze(next,this.options,config.policy);this.remember(source);this.write(next);this.render();}
    }
    /** Show an accessible owned link/image dialog; receives kind, returns Promise of safe values/null and never submits the plugin form. */
    dialog(kind){
      return new Promise(resolve=>{
        const dialog=node('dialog','','dbless-editor-dialog'),heading=node('h2',t(kind)),urlLabel=node('label',t('url')),url=node('input'),textLabel=node('label',t(kind==='image'?'alt':'linkText')),text=node('input'),error=node('p','','notice error'),actions=node('div','','savebar'),cancel=node('button',t('cancel'),'button-secondary'),apply=node('button',t('apply'),'button-primary');
        url.type='text';url.required=true;url.maxLength=2048;text.maxLength=500;url.id='editor-url-'+(++nextId);urlLabel.htmlFor=url.id;text.id='editor-text-'+nextId;textLabel.htmlFor=text.id;urlLabel.append(url);textLabel.append(text);error.hidden=true;error.setAttribute('role','alert');cancel.type=apply.type='button';actions.append(cancel,apply);dialog.setAttribute('aria-labelledby','editor-dialog-'+nextId);heading.id='editor-dialog-'+nextId;dialog.append(heading,urlLabel,textLabel,error,actions);document.body.append(dialog);
        /** Close only this owned dialog; receives optional value, resolves once and restores focus to its toolbar trigger. */
        function close(value=null){dialog.close();dialog.remove();resolve(value);}
        cancel.addEventListener('click',()=>close());dialog.addEventListener('cancel',event=>{event.preventDefault();close();});
        apply.addEventListener('click',()=>{try{if(!url.reportValidity())return;model.url(url.value,kind==='image'?'src':'href');if(kind==='image'){const address=new URL(url.value,new URL(config.publicBase,location.href));if(address.origin!==location.origin||address.pathname.includes('/cms-admin/')||url.value.includes('{{'))throw new SyntaxError(t('localImage'));}close(kind==='image'?{url:url.value,alt:text.value}:{url:url.value,text:text.value});}catch(issue){error.hidden=false;error.textContent=t('invalid')+' '+issue.message;}});
        dialog.showModal();url.focus();
      });
    }
    /** Flush queued frame input before a real submit; no arguments, returns a bounded promise or rejects rather than silently losing edits. */
    flush(){
      if(!this.frame||this.mode==='code'||!this.ready)return Promise.resolve();
      return new Promise((resolve,reject)=>{
        const request=crypto.randomUUID();
        /** Complete only this lexical promise's exact request; receives a native acknowledgement event, returns nothing and never selects or invokes a callback by message-owned name. */
        const acknowledge=event=>{if(event.detail!==request)return;clearTimeout(timer);this.syncEvents.removeEventListener('flush-ack',acknowledge);resolve();};
        const timer=setTimeout(()=>{this.syncEvents.removeEventListener('flush-ack',acknowledge);reject(new Error(t('syncError')));},3000);
        this.syncEvents.addEventListener('flush-ack',acknowledge);this.send('flush',{request});
      });
    }
    /** Append an explicit safe integration snippet; receives HTML/Mustache, validates the combined document, records undo and notifies the owning plugin without provider execution. */
    insertMarkup(source){if(this.options.readOnly||this.options.language!=='html')throw new TypeError('HTML editing is unavailable.');const next=this.textarea.value+(this.textarea.value?'\n':'')+source;model.analyze(next,this.options,config.policy);this.remember(this.textarea.value);this.write(next);this.update();this.render();}
    /** Read canonical source without rendering; no arguments, returns the textarea bytes. */
    getValue(){return this.textarea.value;}
    /** Replace source from a trusted plugin integration; receives text, validates size/profile, emits input and preserves invalid HTML for correction. */
    setValue(value){if(typeof value!=='string'||new TextEncoder().encode(value).length>131072)throw new TypeError('Invalid editor source.');this.write(value);if(this.frame){this.update();if(!this.model)this.setMode('code');else this.render();}}
    /** Remove only this editor's UI/listeners and restore its original textarea; no arguments, no normalization, no persistence. */
    destroy(){this.textarea.removeEventListener('input',this.input);if(this.invalid)this.textarea.removeEventListener('invalid',this.invalid);if(this.listener)window.removeEventListener('message',this.listener);this.code.destroy();this.wrapper.before(this.textarea);this.wrapper.remove();this.textarea.readOnly=this.originalReadOnly;this.textarea.rows=this.originalRows;delete this.textarea.dataset.editorMounted;instances.delete(this.textarea);live.delete(this);}
  }
  /** Mount once, merging explicit options with optional data-editor-options; receives textarea/options, returns an instance or throws before changing the field. */
  function mount(textarea,input={}){if(instances.has(textarea))return instances.get(textarea);const declared=textarea.dataset.editorOptions?JSON.parse(textarea.dataset.editorOptions):{};return new Editor(textarea,{readOnly:textarea.readOnly,...declared,...input});}
  const resumed=new WeakSet();
  document.addEventListener('submit',event=>{
    const form=event.target;if(resumed.has(form)){resumed.delete(form);return;}const editors=[...live].filter(editor=>editor.frame&&editor.mode!=='code'&&editor.textarea.form===form&&!editor.wrapper.closest('[hidden]'));
    if(!editors.length)return;event.preventDefault();event.stopImmediatePropagation();const submitter=event.submitter;
    Promise.all(editors.map(editor=>editor.flush())).then(()=>{for(const editor of editors){editor.update();if(!editor.model){editor.setMode('code');editor.textarea.focus();return;}}resumed.add(form);form.requestSubmit(submitter);}).catch(error=>{for(const editor of editors)editor.status.textContent=error.message;});
  },true);
  window.DBLESSEditor=Object.freeze({version:1,profiles:model.profiles,mount,code,get:textarea=>instances.get(textarea),analyze:(source,options)=>model.analyze(source,options,config.policy)});
  for(const textarea of document.querySelectorAll('textarea[data-editor-options]:not([data-markup-html])'))mount(textarea);
})();
