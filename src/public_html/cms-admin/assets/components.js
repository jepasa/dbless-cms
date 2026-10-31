/** Shared component insertion controls from registered service schemas and authenticated provider choices; emits typed markers without HTML interpolation. */
(() => {
  'use strict';
  for(const root of document.querySelectorAll('[data-components-picker]')){
    const catalog=JSON.parse(root.dataset.catalog),labels=JSON.parse(root.dataset.labels),plugin=root.querySelector('[data-component-plugin]'),service=root.querySelector('[data-component-service]'),layout=root.querySelector('[data-component-layout]'),parameters=root.querySelector('[data-component-parameters]'),status=root.querySelector('[data-component-status]'),insert=root.querySelector('[data-component-insert]');
    /** Resolve one localized shared picker label; receive key, return text or the metadata key for third-party parameters without changing DOM. */
    function text(key){return labels['components.'+key]||key;}
    /** Append one literal option; receive select/value/label, change only its options without evaluating HTML. */
    function option(select,value,label){const node=document.createElement('option');node.value=value;node.textContent=label;select.append(node);}
    /** Render parameter controls for the current registered service; no arguments/return, uses schema bounds and never injects markup. */
    function fields(){
      const definition=catalog[service.value];parameters.replaceChildren();layout.replaceChildren();status.hidden=true;
      if(!definition){insert.disabled=true;status.textContent=text('empty');status.hidden=false;return;}
      root.querySelector('[data-component-description]').textContent=definition.description;for(const format of definition.formats)option(layout,format,format);insert.disabled=definition.formats.length===0;
      for(const [key,field] of Object.entries(definition.parameters.properties)){
        const label=document.createElement('label');label.textContent=text(key);let input;
        const choices=definition.choices?.[key];
        if(choices!==undefined||field.enum){input=document.createElement('select');for(const item of choices??field.enum.map(value=>({value,label:String(value)})))option(input,String(item.value),item.label);if(choices?.length===0){insert.disabled=true;status.textContent=text('noChoices');status.hidden=false;}}
        else{input=document.createElement('input');input.type=field.type==='integer'?'number':field.type==='boolean'?'checkbox':'text';if(field.maxLength)input.maxLength=field.maxLength;if(field.minimum!==undefined)input.min=field.minimum;if(field.maximum!==undefined)input.max=field.maximum;}
        input.dataset.componentParameter=key;input.dataset.kind=field.type;input.required=(definition.parameters.required??[]).includes(key);if(field.default!==undefined){if(field.type==='boolean')input.checked=field.default;else input.value=String(field.default);}label.append(input);parameters.append(label);
      }
    }
    /** Populate services for the selected plugin; no arguments/return, restrict options to discovered compatible metadata and reset parameter controls. */
    function services(){service.replaceChildren();for(const [code,entry] of Object.entries(catalog))if(entry.plugin===plugin.value)option(service,code,entry.service);fields();}
    const seen=new Set();for(const entry of Object.values(catalog))if(!seen.has(entry.plugin)){seen.add(entry.plugin);option(plugin,entry.plugin,entry.name);}plugin.addEventListener('change',services);service.addEventListener('change',fields);services();
    insert.addEventListener('click',()=>{
      try{
        if(insert.disabled)return;let marker='{{'+service.value;
        for(const input of parameters.querySelectorAll('[data-component-parameter]')){if(!input.reportValidity())throw new Error(text('invalid'));const value=input.dataset.kind==='boolean'?String(input.checked):input.value;if(/["{}\r\n]/.test(value))throw new Error(text('invalid'));marker+=' '+input.dataset.componentParameter+'="'+value+'"';}
        marker+=' layout="'+layout.value+'"}}';root.querySelector('[data-component-marker]').value=marker;status.hidden=true;root.dispatchEvent(new CustomEvent('cms-component-insert',{bubbles:true,detail:{marker}}));
      }catch(error){status.textContent=error.message||text('invalid');status.hidden=false;}
    });
  }
})();
