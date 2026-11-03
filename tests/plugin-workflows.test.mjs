/** Fluxos privados: Histórico separado, locais do template ativo, marcadores em home e alertas sem perda de vínculos. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {pluginServer} from './helpers/plugins.mjs';
import {trees} from './helpers/runtime.mjs';
/** Extrai input escapado do formulário; recebe HTML/nome e retorna valor ou falha de contrato. */
function field(body,name){const match=body.match(new RegExp(`name="${name}"[^>]*value="([^"]*)"`));assert.ok(match,name);return match[1].replace(/&(?:amp|quot);/g,entity=>({'&amp;':'&','&quot;':'"'})[entity]);}
/** Calcula revisão real de um arquivo sintético; recebe caminho e retorna SHA-256 sem escrita. */
function revision(file){return createHash('sha256').update(fs.readFileSync(file)).digest('hex');}
for(const tree of trees)test(`${tree}: histórico e posicionamento integrados funcionam com troca de template e marcador na home`,{timeout:30000},async()=>{
  const sandbox=await pluginServer(tree);let cookie='';
  /** Executa HTTP somente no sandbox; recebe rota/payload e retorna status/corpo, mantendo cookie sintético. */
  async function request(route,post=null){const response=await fetch(sandbox.base+route,{redirect:'manual',method:post?'POST':'GET',headers:cookie?{Cookie:cookie}:{},body:post?(post instanceof URLSearchParams?post:new URLSearchParams(post)):null});for(const value of response.headers.getSetCookie())cookie=value.split(';')[0];return {status:response.status,body:await response.text(),headers:response.headers};}
  const screen=(plugin,route,id='')=>`/cms-admin/?view=plugin-panel&plugin=${plugin}&route=${route}${id?'&id='+id:''}`;
  try{
    const siteFile=path.join(sandbox.temporary,'contents/site.json'),site=JSON.parse(fs.readFileSync(siteFile));site.template='essencial';fs.writeFileSync(siteFile,JSON.stringify(site));
    const login=await request('/cms-admin/'),csrf=field(login.body,'csrf_token');assert.equal((await request('/cms-admin/',{action:'login',username:'editor',password:'Synthetic-DBLESS-CMS-password-2026',csrf_token:csrf})).status,303);
    /** Envia API com CSRF real; recebe plugin/rota/operação/campos, retorna resposta HTTP sem redirects automáticos. */
    async function api(plugin,route,operation,values){const post=new URLSearchParams({csrf_token:csrf,...values});return request(`/cms-admin/?view=plugin-api&plugin=${plugin}&route=${route}&operation=${operation}`,post);}
    const panel=await request('/cms-admin/');
    for(const id of ['files-manager','pages','menu','slides']){
      const dropdown=panel.body.match(new RegExp(`data-plugin-menu="${id}"[^>]*>[\\s\\S]*?</details>`))?.[0];assert.ok(dropdown);
      assert.match(dropdown,/\<hr>\s*<a[^>]*route=history[^>]*>Histórico<\/a>\s*<hr>/);
      assert.doesNotMatch(dropdown,/route=locations/);
      const settings=await request(screen(id,'settings'));assert.equal(settings.status,['files-manager','pages'].includes(id)?200:404);if(id==='files-manager')assert.doesNotMatch(settings.body,/id="plugin-options-history"|name="backup"/);
      const history=await request(screen(id,'history'));assert.equal(history.status,200);assert.equal(history.body.includes('id="plugin-options-history"'),id==='files-manager');
    }
    for(const id of ['menu','slides'])assert.equal((await request(screen(id,'locations'))).status,303);
    const menuFile=path.join(sandbox.temporary,'contents/menu/menus.json');let menu=JSON.parse(fs.readFileSync(menuFile));
    const item={id:'home',parent:'',label:'Fluxo de menu',kind:'page',page:'home',anchor:'',url:'',enabled:true,newTab:false};
    const values={revision:revision(menuFile),id:'workflow-menu',original:'',name:'Menu sintético',items:JSON.stringify([item]),placement_editor:'1',placement_template:'essencial',site_revision:revision(siteFile),'locations[]':'header-primary','locationTypes[header-primary]':'navbar'};
    assert.equal((await api('menu','editor','save',values)).status,200);menu=JSON.parse(fs.readFileSync(menuFile));assert.ok(menu.assignments.some(binding=>binding.menu==='workflow-menu'&&binding.location==='header-primary'));
    assert.match((await request('/')).body,/Fluxo de menu/);
    const editor=await request(screen('menu','editor','workflow-menu'));assert.match(editor.body,/data-location="header-primary"/);assert.doesNotMatch(editor.body,/data-location="sidebar-primary"/);
    const saved=fs.readFileSync(menuFile);assert.equal((await api('menu','editor','save',{...values,original:'workflow-menu',revision:revision(menuFile),'locations[]':'not-declared'})).status,422);assert.deepEqual(fs.readFileSync(menuFile),saved);
    site.template='synthetic-theme';fs.writeFileSync(siteFile,JSON.stringify(site));
    assert.equal((await api('menu','editor','save',{...values,original:'workflow-menu',revision:revision(menuFile)})).status,409);assert.deepEqual(fs.readFileSync(menuFile),saved);
    const switched=await request(screen('menu','editor','workflow-menu'));assert.equal(switched.status,200);assert.match(switched.body,/data-location="sidebar-primary"/);assert.match(switched.body,/Vínculos preservados/);
    const unassigned={...values,original:'workflow-menu',revision:revision(menuFile),placement_template:'synthetic-theme',site_revision:revision(siteFile)};delete unassigned['locations[]'];delete unassigned['locationTypes[header-primary]'];
    assert.equal((await api('menu','editor','save',unassigned)).status,200);assert.ok(JSON.parse(fs.readFileSync(menuFile)).assignments.some(binding=>binding.menu==='workflow-menu'&&binding.template==='essencial'));
    site.template='essencial';fs.writeFileSync(siteFile,JSON.stringify(site));
    const manifestFile=path.join(sandbox.application,'system/templates/essencial/template.json'),manifest=JSON.parse(fs.readFileSync(manifestFile));delete manifest.menuLocations['header-primary'];delete manifest.mustacheSlots['primary-menu'];fs.writeFileSync(manifestFile,JSON.stringify(manifest));
    assert.equal((await request(screen('menu','editor','workflow-menu'))).status,200);assert.match((await request(screen('menu','overview'))).body,/Vínculos preservados/);
    assert.equal((await api('menu','editor','save',{...unassigned,placement_template:'essencial',site_revision:revision(siteFile),revision:revision(menuFile)})).status,200);assert.equal((await request('/')).status,200);
    const homeFile=path.join(sandbox.temporary,'contents/pages/home.json'),home=JSON.parse(fs.readFileSync(homeFile));home.content.sections??=[];home.content.sections.push({title:'Marcador de teste',body:['{{slides:page-showcase}}'],list:[]});
    const homeEditor=await request('/cms-admin/?view=plugin-panel&plugin=pages&route=editor&key=home');assert.doesNotMatch(homeEditor.body,/data-page-slides/);
    assert.equal((await api('pages','editor','save',{key:'home',revision:revision(homeFile),document:JSON.stringify(home)})).status,200);
    const slidesFile=path.join(sandbox.temporary,'contents/slides/slides.json'),slides=JSON.parse(fs.readFileSync(slidesFile)),set=slides.collections['exemplo-banners'];
    const slide={id:'workflow-slides',original:'',name:'Slides sintéticos',contentType:'banner',status:'published',interval:'6000',items:JSON.stringify(set.items),revision:revision(slidesFile),placement_editor:'1',placement_template:'essencial',site_revision:revision(siteFile),placementMode:'page',placementPage:'home',page_revision:revision(homeFile),'locations[]':'page-showcase'};
    const created=await api('slides','editor','save',slide);assert.equal(created.status,200);assert.match(created.body,/placement=page/);
    assert.match((await request('/')).body,/data-location="page-showcase"/);assert.doesNotMatch((await request('/')).body,/\{\{slides:page-showcase\}\}/);
    const slideEditor=await request(screen('slides','editor','workflow-slides'));assert.match(slideEditor.body,/data-placement-state=/);
    const slideBytes=fs.readFileSync(slidesFile);assert.equal((await api('slides','editor','save',{...slide,original:slide.id,revision:revision(slidesFile),'locations[]':'home-hero'})).status,422);assert.deepEqual(fs.readFileSync(slidesFile),slideBytes);
    home.content.sections.push({title:'Nova revisão',body:['Conteúdo sintético'],list:[]});assert.equal((await api('pages','editor','save',{key:'home',revision:revision(homeFile),document:JSON.stringify(home)})).status,200);
    assert.equal((await api('slides','editor','save',{...slide,original:slide.id,revision:revision(slidesFile)})).status,409);assert.deepEqual(fs.readFileSync(slidesFile),slideBytes);
    site.template='synthetic-theme';fs.writeFileSync(siteFile,JSON.stringify(site));
    const inactive=await request(screen('slides','editor','workflow-slides'));assert.equal(inactive.status,200);assert.match(inactive.body,/Vínculos preservados/);assert.match(inactive.body,/não declara locais Slides/);
    const without={...slide,original:slide.id,revision:revision(slidesFile),placement_template:'synthetic-theme',site_revision:revision(siteFile),placementMode:'template'};delete without['locations[]'];assert.equal((await api('slides','editor','save',without)).status,200);
    assert.ok(JSON.parse(fs.readFileSync(slidesFile)).assignments.some(binding=>binding.collection===slide.id&&binding.template==='essencial'));
    const management=await request('/cms-admin/?view=plugins');assert.equal((await request('/cms-admin/',{action:'extension-toggle',kind:'plugins',id:'slides',enabled:'0',revision:field(management.body,'revision'),csrf_token:csrf})).status,303);
    const before=fs.readFileSync(slidesFile);const history=await request(screen('slides','history'));assert.equal(history.status,200);
    assert.equal((await api('slides','history','history-restore',{revision:revision(slidesFile),backup:'anything'})).status,404);assert.deepEqual(fs.readFileSync(slidesFile),before);
    assert.ok(!/PHP (?:Warning|Fatal|Parse)/.test(sandbox.logs()),sandbox.logs());
  }finally{await sandbox.close();}
});
