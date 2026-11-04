/** Shared seven-position pager for server catalogs and asynchronous files: lazy upward page menus, keyboard navigation and no arbitrary HTML or off-origin navigation. */
(() => {
  'use strict';if(window.DBLESSPager)return;
  let sequence=0;
  /** Format a server-provided localized label; receive text/finite scalar values, return plain text, never interpret HTML. */
  function format(text,values){return text.replace(/\{([a-zA-Z]+)\}/g,(token,key)=>Object.hasOwn(values,key)?String(values[key]):token);}
  /** Build a safe addressed decorative icon; receive trusted icon URL, return image without inline SVG or handlers. */
  function icon(source){const image=document.createElement('img');image.src=source;image.className='admin-icon';image.width=image.height=24;image.alt='';image.setAttribute('aria-hidden','true');return image;}
  /** Render/update one bounded pager; receive host/state, preserve focus and navigate through fixed same-origin links or a module-owned page event, never fetch or mutate editorial state. */
  function render(host,input){
    const state={...input},labels=state.labels,focused=host.contains(document.activeElement);for(const key of ['page','pages','total','count','start','end'])if(!Number.isSafeInteger(state[key])||state[key]<0)throw new TypeError('Invalid pager state.');if(state.page<1||state.pages<1||state.page>state.pages)throw new TypeError('Invalid pager range.');
    if(host._popup?.matches(':popover-open'))host._popup.hidePopover();host._pager=state;const nav=document.createElement('nav');nav.className='catalog-pagination';nav.setAttribute('aria-label',state.paginationLabel);
    /** Create an ordinary local link or asynchronous page button; receive target/label/content/current flag, return a disabled control for missing targets. */
    function control(target,label,content,current=false){
      const enabled=!state.busy&&state.total>0&&target>=1&&target<=state.pages&&!current;
      const node=document.createElement(current?'span':state.href?'a':'button');if(node.tagName==='BUTTON'){node.type='button';node.disabled=!enabled;}
      node.className='icon-button';node.title=label;node.setAttribute('aria-label',label);if(current)node.setAttribute('aria-current','page');if(!enabled){node.setAttribute('aria-disabled','true');node.tabIndex=-1;}else if(state.href){const url=new URL(state.href,location.href);if(url.origin!==location.origin||!/^https?:$/.test(url.protocol))throw new TypeError('Invalid pager destination.');url.searchParams.set(state.pageKey||'table_page',String(target));node.href=url.href;}else node.addEventListener('click',()=>{host.dispatchEvent(new CustomEvent('cms-page-change',{bubbles:true,detail:{page:target}}));host.querySelector('[aria-current]')?.focus();});
      if(content instanceof Node)node.append(content);else node.textContent=content;return node;
    }
    /** Create an upward lazy menu for omitted page numbers; receive finite first/last and label, return its permanent trigger with accessible popup behavior. */
    function menu(first,last,label){
      const trigger=document.createElement('button');trigger.type='button';trigger.className='icon-button';trigger.title=label;trigger.setAttribute('aria-label',label);trigger.setAttribute('aria-haspopup','menu');trigger.setAttribute('aria-expanded','false');trigger.append(icon(state.icons.more));trigger.disabled=!!state.busy||!state.total||first>last;
      trigger.addEventListener('click',()=>{
        const popup=document.createElement('div');popup.className='catalog-page-menu';popup.id='catalog-page-menu-'+(++sequence);popup.setAttribute('popover','auto');popup.setAttribute('role','menu');popup.setAttribute('aria-label',label);trigger.setAttribute('aria-controls',popup.id);
        for(let page=first;page<=last;page++){const item=control(page,format(labels.page,{page}),String(page));item.setAttribute('role','menuitem');popup.append(item);}
        host._popup=popup;document.body.append(popup);const rect=trigger.getBoundingClientRect();popup.style.left=Math.max(8,Math.min(rect.left,innerWidth-272))+'px';popup.style.bottom=(innerHeight-rect.top+8)+'px';popup.style.maxHeight=Math.max(40,Math.min(240,rect.top-16))+'px';popup.showPopover();trigger.setAttribute('aria-expanded','true');popup.firstElementChild?.focus();
        popup.addEventListener('toggle',event=>{if(event.newState==='closed'){trigger.setAttribute('aria-expanded','false');trigger.removeAttribute('aria-controls');if(document.activeElement===document.body||popup.contains(document.activeElement))trigger.focus();popup.remove();}});
        popup.addEventListener('keydown',event=>{const items=[...popup.children],index=items.indexOf(document.activeElement);let next=event.key==='Home'?0:event.key==='End'?items.length-1:event.key==='ArrowRight'?index+1:event.key==='ArrowLeft'?index-1:event.key==='ArrowDown'?index+5:event.key==='ArrowUp'?index-5:null;if(event.key==='Tab'){popup.hidePopover();trigger.focus();return;}if(event.key==='Escape'){event.preventDefault();popup.hidePopover();trigger.focus();return;}if(next!==null){event.preventDefault();items[Math.max(0,Math.min(items.length-1,next))]?.focus();}});
        popup.addEventListener('click',event=>{if(event.target.closest('a,button'))popup.hidePopover();});
      });return trigger;
    }
    nav.append(control(state.page-1,labels.previous,icon(state.icons.previous)),menu(1,state.page-2,labels.earlier),control(state.page-1,state.page>1?format(labels.page,{page:state.page-1}):labels.unavailable,state.page>1?String(state.page-1):'—'),control(state.page,format(labels.page,{page:state.page}),String(state.page),true),control(state.page+1,state.page<state.pages?format(labels.page,{page:state.page+1}):labels.unavailable,state.page<state.pages?String(state.page+1):'—'),menu(state.page+2,state.pages,labels.later),control(state.page+1,labels.next,icon(state.icons.next)));
    nav.firstElementChild.dataset.page='previous';nav.lastElementChild.dataset.page='next';const summary=document.createElement('p');summary.dataset.pageInfo='';summary.className='catalog-summary';summary.setAttribute('role','status');summary.textContent=format(labels.summary,state);host.replaceChildren(nav,summary);if(focused)nav.querySelector('[aria-current]')?.focus();
  }
  window.DBLESSPager=Object.freeze({render});for(const host of document.querySelectorAll('[data-catalog-pager]'))render(host,JSON.parse(host.dataset.pager));
})();
