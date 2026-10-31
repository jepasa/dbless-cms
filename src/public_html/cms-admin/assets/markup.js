/** Shared local HTML/Mustache editor: textarea source of truth, safe syntax highlighting, opt-in Tab indentation and typed data bindings with manual-use snippets. */
(() => {
  'use strict';
  let nextId=0;
  // Uncommitted query controls belong to an independent inert form; only Add binding validates them, and host forms submit the HTML/bindings textareas.
  const pickerForm=document.createElement('form');pickerForm.id='cms-markup-picker-controls';pickerForm.hidden=true;pickerForm.addEventListener('submit',event=>event.preventDefault());document.body.append(pickerForm);
  /** Create an inert text-only DOM element; receive tag/text/class, return node without interpreting user HTML. */
  function node(tag,text='',className=''){const result=document.createElement(tag);result.textContent=text;if(className)result.className=className;return result;}
  /** Append a literal select option; receive select/value/label, modify options and return nothing. */
  function option(select,value,label){const item=node('option',label);item.value=value;select.append(item);}
  /** Enhance one existing textarea without changing its submitted value; receive textarea/translator, return nothing and keep keyboard navigation accessible. */
  function code(textarea,t){
    if(textarea.dataset.codeMounted)return;textarea.dataset.codeMounted='true';const wrapper=node('div','','markup-code'),preview=node('pre'),numbers=node('pre','','markup-lines'),indent=node('input'),label=node('label','','editor-checkbox');preview.setAttribute('aria-hidden','true');numbers.setAttribute('aria-hidden','true');indent.type='checkbox';label.append(indent,document.createTextNode(t('captureTab')));const originalLabel=textarea.closest('label');if(originalLabel){textarea.id||='markup-code-'+(++nextId);originalLabel.htmlFor=textarea.id;originalLabel.after(label,wrapper);}else textarea.before(label,wrapper);wrapper.append(numbers,preview,textarea);
    /** Refresh inert highlighting/line numbers from text; no arguments/return, never evaluates HTML or changes the source. */
    function refresh(){
      preview.replaceChildren();const value=textarea.value;let offset=0;for(const match of value.matchAll(/\{\{[^{}]*\}\}|<\/?[a-zA-Z][^>]*>/g)){preview.append(document.createTextNode(value.slice(offset,match.index)),node('span',match[0],match[0].startsWith('{{')?'markup-token-mustache':'markup-token-html'));offset=match.index+match[0].length;}preview.append(document.createTextNode(value.slice(offset)+'\n'));numbers.textContent=Array.from({length:Math.min(4097,value.split('\n').length)},(_,index)=>String(index+1)).join('\n');preview.scrollTop=textarea.scrollTop;preview.scrollLeft=textarea.scrollLeft;numbers.scrollTop=textarea.scrollTop;
    }
    textarea.addEventListener('input',refresh);textarea.addEventListener('scroll',()=>{preview.scrollTop=textarea.scrollTop;preview.scrollLeft=textarea.scrollLeft;numbers.scrollTop=textarea.scrollTop;});
    textarea.addEventListener('keydown',event=>{if(event.key==='Escape'){indent.checked=false;return;}if(event.key==='Tab'&&indent.checked){event.preventDefault();const start=textarea.selectionStart,end=textarea.selectionEnd,original=textarea.value;if(event.shiftKey){if(original.slice(Math.max(0,start-2),start)==='  ')textarea.setRangeText('',start-2,start,'end');}else textarea.setRangeText('  ',start,end,'end');textarea.dispatchEvent(new Event('input',{bubbles:true}));}});refresh();
  }
  /** Produce a documented data-loop skeleton from result metadata; receive binding/schema, return inert source text without fetching data or rendering provider values. */
  function snippet(binding,schema){
    const alias=binding.alias,items=schema.properties?.items;
    if(items?.type==='array'){const fields=Object.entries(items.items?.properties||{}).filter(([,field])=>['string','integer','boolean'].includes(field.type));return '{{#'+alias+'.items}}\n  <article>\n'+fields.map(([key])=>'    <p>{{'+key+'}}</p>').join('\n')+'\n  </article>\n{{/'+alias+'.items}}\n{{^'+alias+'.items}}<p>—</p>{{/'+alias+'.items}}';}
    return Object.keys(schema.properties||{}).map(key=>'{{'+alias+'.'+key+'}}').join('\n');
  }
  /** Mount one authoring control; receive root, initialize local metadata/bindings UI and emit change events while preserving invalid JSON for correction. */
  function mount(root){
    if(root.dataset.markupMounted)return;root.dataset.markupMounted='true';const catalog=JSON.parse(root.dataset.catalog||'{}'),labels=JSON.parse(root.dataset.labels||'{}'),html=root.querySelector('[data-markup-html]'),json=root.querySelector('[data-markup-bindings-json]'),container=root.querySelector('[data-markup-bindings]');
    /** Translate one hardcoded label, falling back to literal schema field names; receive key, return text only. */
    const t=key=>labels['markup.'+key]||key;code(html,t);const heading=node('h3',t('plugins')),description=node('p',t('bindingHelp'),'muted'),fields=node('div','','field-grid'),plugin=node('select'),service=node('select'),alias=node('input'),parameters=node('div','','field-grid'),list=node('div','','markup-binding-list'),status=node('p','','notice error'),add=node('button',t('add'),'button-secondary');status.hidden=true;status.setAttribute('role','alert');add.type='button';alias.maxLength=100;alias.pattern='[a-z][a-zA-Z0-9\\-]*(\\.[a-z][a-zA-Z0-9\\-]*){0,2}';
    for(const [caption,input] of [['plugin',plugin],['service',service],['alias',alias]]){const label=node('label',t(caption));input.id='markup-field-'+(++nextId);input.setAttribute('form',pickerForm.id);input.setAttribute('aria-label',t(caption));label.append(input);fields.append(label);}container.append(heading,description,fields,parameters,add,status,list);
    /** Read current JSON without dropping invalid source; no arguments, return array or throw with localized feedback. */
    function bindings(){const values=JSON.parse(json.value);if(!Array.isArray(values)||values.length>20)throw new Error(t('invalid'));return values;}
    /** Synchronize binding source and notify the host editor; receive values, change only the owned textarea and emit a structured event. */
    function write(values){json.value=JSON.stringify(values,null,2);json.setCustomValidity('');root.dispatchEvent(new CustomEvent('cms-markup-change',{bubbles:true,detail:{html:html.value,bindings:values}}));renderList();}
    /** Render saved binding snippets/field schemas with safe text DOM; no arguments/return, preserve declarations for absent providers. */
    function renderList(){
      list.replaceChildren();let values;try{values=bindings();json.setCustomValidity('');}catch{json.setCustomValidity(t('invalid'));return;}
      for(const [index,binding] of values.entries()){
        const entry=catalog[binding.plugin+':'+binding.service],card=node('section','','markup-binding-card'),top=node('div','','dashboard-card-heading'),remove=node('button',t('remove'),'button-secondary');remove.type='button';top.append(node('h4',binding.alias+' · '+binding.plugin+':'+binding.service),remove);remove.addEventListener('click',()=>{const current=bindings();current.splice(index,1);write(current);});card.append(top);
        if(!entry){card.append(node('p',t('unavailable'),'notice'));list.append(card);continue;}
        const label=node('label',t('code')),area=node('textarea');area.readOnly=true;area.rows=7;area.value=snippet(binding,entry.result);area.dataset.mustacheCode='';label.append(area);card.append(label);const details=node('details'),pre=node('pre');details.append(node('summary',t('fields')),pre);pre.textContent=JSON.stringify(entry.result,null,2);card.append(details);list.append(card);
      }
    }
    /** Render typed parameter inputs for a discovered data service, independent of template formats; no arguments/return, no provider calls. */
    function parameterFields(){
      parameters.replaceChildren();const entry=catalog[service.value];alias.value=service.value.replace(':','.');add.disabled=!entry;status.hidden=true;if(!entry)return;
      for(const [key,field] of Object.entries(entry.parameters.properties)){
        const label=node('label',key),choices=entry.choices?.[key];let input;
        if((choices&&choices.length)||field.enum){input=node('select');for(const item of choices||field.enum.map(value=>({value,label:String(value)})))option(input,String(item.value),item.label);}
        else {input=node('input');input.type=field.type==='integer'?'number':field.type==='boolean'?'checkbox':'text';if(field.minimum!==undefined)input.min=field.minimum;if(field.maximum!==undefined)input.max=field.maximum;if(field.maxLength)input.maxLength=field.maxLength;}
        input.setAttribute('form',pickerForm.id);input.setAttribute('aria-label',key);input.dataset.bindingParameter=key;input.dataset.kind=field.type;input.required=field.type!=='boolean'&&(entry.parameters.required||[]).includes(key);if(field.default!==undefined){if(field.type==='boolean')input.checked=field.default;else input.value=String(field.default);}label.append(input);parameters.append(label);
      }
    }
    /** Populate services for the selected plugin; no arguments/return, uses only discovered static metadata. */
    function services(){service.replaceChildren();for(const [key,entry] of Object.entries(catalog))if(entry.plugin===plugin.value)option(service,key,entry.service);parameterFields();}
    const seen=new Set();for(const entry of Object.values(catalog))if(!seen.has(entry.plugin)){seen.add(entry.plugin);option(plugin,entry.plugin,entry.name);}plugin.addEventListener('change',services);service.addEventListener('change',parameterFields);services();renderList();
    add.addEventListener('click',()=>{try{if(!alias.reportValidity()||!alias.value)throw new Error(t('invalid'));const current=bindings();if(current.length>=20||current.some(item=>item.alias===alias.value||item.alias.startsWith(alias.value+'.')||alias.value.startsWith(item.alias+'.')))throw new Error(t('duplicate'));const entry=catalog[service.value],values=Object.create(null);for(const input of parameters.querySelectorAll('[data-binding-parameter]')){if(!input.reportValidity())throw new Error(t('invalid'));values[input.dataset.bindingParameter]=input.dataset.kind==='boolean'?input.checked:input.dataset.kind==='integer'?Number(input.value):input.value;}current.push({plugin:entry.plugin,service:entry.service,alias:alias.value,parameters:values});write(current);status.hidden=true;}catch(error){status.textContent=error.message||t('invalid');status.hidden=false;}});
    json.addEventListener('input',()=>{renderList();try{root.dispatchEvent(new CustomEvent('cms-markup-change',{bubbles:true,detail:{html:html.value,bindings:bindings()}}));}catch{/* Keep invalid JSON available for correction; native validation prevents submit. */}});html.addEventListener('input',()=>{try{root.dispatchEvent(new CustomEvent('cms-markup-change',{bubbles:true,detail:{html:html.value,bindings:bindings()}}));}catch{/* Binding errors remain attached to their textarea. */}});
  }
  /** Create a shared editor for dynamically added plugin blocks; receive host/state/catalog/labels, return mounted root with no external resource or HTML evaluation. */
  function create(parent,state,catalog,labels){const root=node('div','','markup-editor');root.dataset.markupEditor='';root.dataset.catalog=JSON.stringify(catalog);root.dataset.labels=JSON.stringify(labels);const label=node('label',labels['markup.content']||'HTML'),html=node('textarea'),json=node('textarea'),details=node('details');html.dataset.markupHtml='';html.value=state.html;html.maxLength=60000;html.rows=14;html.spellcheck=false;json.dataset.markupBindingsJson='';json.value=JSON.stringify(state.bindings,null,2);json.rows=4;label.append(html);details.append(node('summary',labels['markup.bindingsJson']||'Bindings JSON'),json);root.append(label,node('div'),details);root.children[1].dataset.markupBindings='';parent.append(root);mount(root);return root;}
  window.DBLESSMarkup=Object.freeze({mount,create});for(const root of document.querySelectorAll('[data-markup-editor]'))mount(root);
})();
