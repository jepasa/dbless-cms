/** Slides públicos Essencial: paginação acessível, teclado/RTL e reprodução opcional com pausa por foco/movimento/visibilidade. */
(() => {
  'use strict';
  for(const root of document.querySelectorAll('[data-slides-component]')){
    const items=[...root.querySelectorAll('[data-slide-item]')],controls=root.querySelector('[data-slides-controls]'),status=root.querySelector('[data-slide-status]'),pause=root.querySelector('[data-slide-pause]'),reduced=matchMedia('(prefers-reduced-motion: reduce)');
    let index=0,timer=null,paused=false,hovered=false;
    /** Calcula quantidade por linha do modo/template; sem parâmetros, retorna inteiro sem alterar conteúdo. */
    function size(){return root.dataset.mode==='carousel'?(innerWidth>900?3:innerWidth>640?2:1):1;}
    /** Reagenda avanço opcional somente em condições acessíveis; sem parâmetros, cancela timer anterior e não move foco. */
    function schedule(){clearTimeout(timer);timer=null;if(root.dataset.autoplay==='true'&&!paused&&!reduced.matches&&!document.hidden&&!hovered&&!root.contains(document.activeElement)&&items.length>size())timer=setTimeout(()=>advance(size(),false),Number(root.dataset.interval));}
    /** Atualiza itens visíveis sem mover foco; recebe bool anúncio manual e mantém leitura silenciosa em reprodução automática. */
    function render(announce=true){const count=size();index=Math.floor(Math.min(index,Math.max(0,items.length-1))/count)*count;items.forEach((item,position)=>{item.hidden=position<index||position>=index+count;});controls.hidden=items.length<=count;pause.hidden=root.dataset.autoplay!=='true'||reduced.matches;pause.textContent=paused?'Retomar reprodução':'Pausar reprodução';if(announce)status.textContent=`${index+1}–${Math.min(index+count,items.length)} de ${items.length}`;schedule();}
    /** Avança/retorna grupo com wrap; recebe deslocamento e origem manual; sem persistência ou foco automático. */
    function advance(offset,manual=true){index+=offset;if(index>=items.length)index=0;if(index<0)index=Math.floor((items.length-1)/size())*size();render(manual);}
    root.classList.add('is-enhanced');root.querySelector('[data-slide-prev]').addEventListener('click',()=>advance(-size()));root.querySelector('[data-slide-next]').addEventListener('click',()=>advance(size()));
    pause.addEventListener('click',()=>{paused=!paused;render();});root.addEventListener('mouseenter',()=>{hovered=true;schedule();});root.addEventListener('mouseleave',()=>{hovered=false;schedule();});root.addEventListener('focusin',schedule);root.addEventListener('focusout',()=>setTimeout(schedule,0));document.addEventListener('visibilitychange',schedule);reduced.addEventListener('change',()=>render());
    root.addEventListener('keydown',event=>{if(event.target!==root)return;const rtl=getComputedStyle(root).direction==='rtl';if(['ArrowLeft','ArrowRight'].includes(event.key)){event.preventDefault();advance((event.key==='ArrowRight'?!rtl:rtl)?size():-size());}else if(event.key==='Home'||event.key==='End'){event.preventDefault();index=event.key==='Home'?0:Math.floor((items.length-1)/size())*size();render();}});
    window.addEventListener('resize',()=>render());render();
  }
})();
