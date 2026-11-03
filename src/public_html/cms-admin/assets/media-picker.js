/** Core read-only media modal. Server-issued context controls scope; consumers receive typed references, never upload or mutation controls. */
(() => {
  'use strict';
  const metadata=document.querySelector('meta[name="dbless-editor"]'),standard=metadata?JSON.parse(metadata.content).media:null;
  /** Create text-only DOM; receives tag/text, returns an inert element without HTML parsing. */
  function node(tag,text=''){const element=document.createElement(tag);element.textContent=text;return element;}
  /** Open a paginated media browser; receives trusted server configuration and type, resolves one typed item or null, reports errors and aborts stale requests. */
  function open({config=standard,type='all'}={}){
    if(!config||!['image','video','all'].includes(type))return Promise.reject(new TypeError('Media selector unavailable.'));
    const endpoint=new URL(config.endpoint,location.href);if(endpoint.origin!==location.origin)return Promise.reject(new TypeError('Local endpoint required.'));
    return new Promise(resolve=>{
      const t=key=>config.labels[key]??key,dialog=node('dialog'),heading=node('h2',t('title')),toolbar=node('div'),controls=node('div'),items=node('div'),status=node('p'),footer=node('div'),search=node('input'),sort=node('select'),kind=node('select'),scope=node('select'),locationLabel=node('p'),trigger=document.activeElement;
      let currentPath='',page=1,mode='grid',direction='asc',request=null,timer=null,closed=false;dialog.className='media-picker';dialog.setAttribute('aria-label',t('title'));toolbar.className=controls.className=footer.className='media-picker-controls';items.className='media-picker-items';status.setAttribute('role','status');locationLabel.className='media-picker-path';
      /** Build a canonical icon-only command; receives icon/label/callback, returns non-submit accessible button. */
      function button(icon,label,callback){const b=node('button'),image=node('img');b.type='button';b.className='icon-button';b.title=t(label);b.setAttribute('aria-label',t(label));image.src=config.icons[icon];image.alt='';image.width=image.height=20;b.append(image);b.addEventListener('click',callback);return b;}
      /** End this modal once; receives selected item/null, aborts pending work, restores focus and resolves selection. */
      function close(value=null){if(closed)return;closed=true;clearTimeout(timer);request?.abort();dialog.close();dialog.remove();trigger?.focus();resolve(value);}
      const up=button('up','up',()=>{currentPath=currentPath.split('/').slice(0,-1).join('/');page=1;void load();}),grid=button('grid','grid',()=>{mode='grid';renderMode();}),list=button('list','list',()=>{mode='list';renderMode();}),reverse=button('sort-up','direction',()=>{direction=direction==='asc'?'desc':'asc';page=1;void load();}),previous=button('arrow-right','previous',()=>{page--;void load();}),next=button('arrow-right','next',()=>{page++;void load();});previous.classList.add('media-picker-previous');
      search.type='search';search.maxLength=120;search.placeholder=t('search');search.setAttribute('aria-label',t('search'));sort.setAttribute('aria-label',t('sort'));kind.setAttribute('aria-label',t('type'));scope.setAttribute('aria-label',t('scope'));
      for(const key of ['name','type','size','modified']){const option=node('option',t(key));option.value=key;sort.append(option);}
      for(const key of type==='all'?['all','image','video']:[type]){const option=node('option',t(key));option.value=key;kind.append(option);}
      for(const key of config.visibility==='private'?['public','private']:['public']){const option=node('option',t(key));option.value=key;scope.append(option);}
      toolbar.append(up,grid,list,button('close','close',()=>close()));controls.append(search,kind,sort,reverse);if(config.visibility==='private')controls.prepend(scope);footer.append(previous,status,next);dialog.append(heading,toolbar,locationLabel,controls,items,footer);document.body.append(dialog);
      /** Reflect view mode only; no arguments, never fetches or changes selected data. */
      function renderMode(){items.dataset.mode=mode;grid.setAttribute('aria-pressed',String(mode==='grid'));list.setAttribute('aria-pressed',String(mode==='list'));}
      /** Load one bounded authorized directory; no arguments, isolates stale results and renders labels using text nodes. */
      async function load(){
        request?.abort();const active=new AbortController();request=active;status.textContent=t('loading');items.setAttribute('aria-busy','true');previous.disabled=next.disabled=true;
        try{
          const body=new URLSearchParams({csrf_token:config.csrf,token:config.token,scope:scope.value,path:currentPath,page:String(page),search:search.value,type:kind.value,sort:sort.value,direction});
          const response=await fetch(endpoint,{method:'POST',credentials:'same-origin',cache:'no-store',redirect:'error',body,signal:active.signal}),data=await response.json();if(!response.ok)throw new Error(t('error'));if(closed||request!==active)return;items.replaceChildren();page=data.page;locationLabel.textContent='/'+data.path;up.disabled=!data.path;
          if(!data.items.length)items.append(node('p',t('empty')));
          for(const item of data.items){const row=node('button'),preview=node('img'),name=node('span',item.name),detail=node('small',item.folder?t('folder'):item.type+' · '+Math.ceil(item.size/1024)+' KiB');row.type='button';row.className='media-picker-item';row.title=item.name;row.setAttribute('aria-label',item.name);preview.alt='';preview.loading='lazy';preview.referrerPolicy='no-referrer';preview.src=item.folder?config.icons.folder:item.type.startsWith('image/')?item.url:config.icons.video;row.append(preview,name,detail);row.addEventListener('click',()=>{if(item.folder){currentPath=item.path;page=1;void load();}else close(item);});items.append(row);}
          status.textContent=t('page').replace('{page}',String(page)).replace('{pages}',String(data.pages)).replace('{total}',String(data.total));previous.disabled=page<=1;next.disabled=page>=data.pages;
        }catch(error){if(error.name!=='AbortError'){items.replaceChildren();status.textContent=t('error');}}
        finally{if(request===active)items.removeAttribute('aria-busy');}
      }
      search.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>{page=1;void load();},250);});for(const select of [sort,kind,scope])select.addEventListener('change',()=>{page=1;if(select===scope)currentPath='';void load();});dialog.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();event.stopPropagation();close();}});dialog.addEventListener('cancel',event=>{event.preventDefault();close();});renderMode();dialog.showModal();search.focus();void load();
    });
  }
  /** Attach one public media field selector; receives input/type, opens the shared modal and emits ordinary form events, never saves content. */
  function attach(input,type='image'){
    if(!standard||input.dataset.mediaMounted)return;input.dataset.mediaMounted='true';const button=node('button'),image=node('img');button.type='button';button.className='icon-button';button.title=standard.labels.select;button.setAttribute('aria-label',standard.labels.select);image.src=standard.icons.image;image.alt='';image.width=image.height=20;button.append(image);input.after(button);button.addEventListener('click',async()=>{const item=await open({type});if(item?.value){input.value=item.value;input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));}});
  }
  /** Enhance declared inputs after initial load or plugin editor rebuilds; no arguments/return or content mutation. */
  function scan(){for(const input of document.querySelectorAll('input[data-media-field]'))attach(input,input.dataset.mediaField||'image');}
  window.DBLESSMedia=Object.freeze({open,attach});scan();new MutationObserver(scan).observe(document.body,{childList:true,subtree:true});
})();
