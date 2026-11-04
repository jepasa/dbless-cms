/** Revisão funcional: configurações operacionais de uploads, ausência de telas redundantes e compatibilidade legada em dados sintéticos. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {pluginServer} from './helpers/plugins.mjs';
import {trees,environment} from './helpers/runtime.mjs';

/** Extrai input escapado; recebe HTML/nome, retorna valor ou falha de contrato sem efeitos. */
function field(body,name) {
  const value=body.match(new RegExp(`name="${name}"[^>]*value="([^"]*)"`))?.[1];
  assert.notEqual(value,undefined,name);return value.replaceAll('&quot;','"').replaceAll('&amp;','&');
}
for(const tree of trees)test(`${tree}: uploads e navegação possuem configuração; editores e histórico preservam compatibilidade`,{timeout:30000},async()=>{
  const sandbox=await pluginServer(tree);let cookie='';
  /** Executa HTTP no sandbox; recebe rota/payload e retorna status/corpo, mantendo somente a sessão sintética. */
  async function request(route,post=null) {
    const response=await fetch(sandbox.base+route,{redirect:'manual',method:post?'POST':'GET',headers:cookie?{Cookie:cookie}:{},body:post?new URLSearchParams(post):null});
    for(const value of response.headers.getSetCookie())cookie=value.split(';')[0];
    return {status:response.status,body:await response.text()};
  }
  const screen=(id,route='settings')=>`/cms-admin/?view=plugin-panel&plugin=${id}&route=${route}`;
  const api=(id,route,operation)=>`/cms-admin/?view=plugin-api&plugin=${id}&route=${route}&operation=${operation}`;
  try {
    assert.equal((await request(api('files-manager','settings','settings-save'))).status,403);
    const login=await request('/cms-admin/');assert.equal((await request('/cms-admin/',{action:'login',username:'editor',password:'Synthetic-DBLESS-CMS-password-2026',csrf_token:field(login.body,'csrf_token')})).status,303);
    const configFile=path.join(sandbox.temporary,'contents/plugins.json'),read=()=>JSON.parse(fs.readFileSync(configFile));
    // Simula uma instalação anterior sem migrar/regravar conteúdo real.
    const legacy=read();Object.assign(legacy.plugins.posts.settings,{creation:{model:'starter-page'}});Object.assign(legacy.plugins.menu.settings,{creation:{newTab:true}});Object.assign(legacy.plugins.slides.settings,{creation:{contentType:'product',autoplay:true,interval:9000}});fs.writeFileSync(configFile,JSON.stringify(legacy));
    const contentFiles=['pages/home.json','menu/menus.json','slides/slides.json'].map(file=>path.join(sandbox.temporary,'contents',file)),contents=contentFiles.map(file=>fs.readFileSync(file));
    const opened=await request(screen('files-manager')),csrf=field(opened.body,'csrf_token');assert.equal(opened.status,200);
    assert.match(opened.body,/data-editor-schema=/);assert.match(opened.body,/class="savebar"/);assert.doesNotMatch(opened.body,/name="enabled"|id="plugin-options-history"/);
    const originalBytes=fs.readFileSync(configFile);
    for(const id of ['menu','slides']) {
      for(const route of [screen(id),`/cms-admin/?view=plugin&plugin=${id}&route=settings`,`/cms-admin/?view=plugin-settings&plugin=${id}`])assert.equal((await request(route)).status,404,route);
      for(const [route,operation] of [['settings','settings'],['settings','options-restore'],['history','options-restore'],['editor','settings']])assert.equal((await request(api(id,route,operation),{csrf_token:csrf,revision:field(opened.body,'revision'),document:'{}',backup:'unused'})).status,404);
      assert.equal((await request('/cms-admin/?view=plugins',{action:'plugin-settings',plugin:id,csrf_token:csrf,revision:field(opened.body,'revision')})).status,404);
      const history=await request(screen(id,'history'));assert.equal(history.status,200);assert.doesNotMatch(history.body,/id="plugin-options-history"|operation=options-restore/);
      const catalog=await request('/cms-admin/?view=plugins'),row=catalog.body.match(new RegExp(`<tr data-extension-id="${id}"[\\s\\S]*?</tr>`))?.[0];assert.ok(row);assert.doesNotMatch(row,/href="[^"]*(?:plugin-settings|route=settings)/);assert.match(row,/disabled title="Esta extensão não declara parâmetros configuráveis/);
    }
    assert.deepEqual(fs.readFileSync(configFile),originalBytes,'GETs e operações retiradas não regravam preferências legadas');
    const page=await request(screen('pages','new'));assert.equal(page.status,200);assert.match(page.body,/select name="model"/);const native=page.body.match(/<form\b[^>]*action="[^"]*editorial-create[^"]*"[\s\S]*?<\/form>/)?.[0];assert.ok(native);const modelSelect=native.match(/<select name="model"[^>]*>[\s\S]*?<\/select>/)?.[0];assert.ok(modelSelect);assert.doesNotMatch(modelSelect,/<option[^>]* selected/);
    const menu=await request(screen('menu','editor'));assert.equal(menu.status,200);assert.doesNotMatch(menu.body,/data-default-new-tab/);
    const slides=await request(screen('slides','editor'));assert.equal(slides.status,200);assert.match(slides.body,/<option value="banner" selected>/);assert.match(slides.body,/name="interval"[^>]*value="6000"/);assert.doesNotMatch(slides.body,/name="autoplay"[^>]*checked/);
    const snapshot=fs.readFileSync(configFile);
    const guards=spawnSync('php',['-r',`require $argv[1].'/system/bootstrap.php';$p=new DBLESSCMS\\Plugins(new DBLESSCMS\\JsonStore(new DBLESSCMS\\Config()));$out=[];foreach(['menu','slides'] as $id){foreach([false,true] as $restore){try{if($restore)$p->restore($id,'unused','unused',true);else $p->saveOptions($id,[],'unused');$out[]=200;}catch(DBLESSCMS\\HttpError $e){$out[]=$e->status;}}}echo json_encode($out);`,sandbox.application],{env:environment(sandbox.temporary),encoding:'utf8'});
    assert.equal(guards.status,0,guards.stderr);assert.equal(guards.stderr,'');assert.deepEqual(JSON.parse(guards.stdout),[404,404,404,404]);assert.deepEqual(fs.readFileSync(configFile),snapshot);
    // A única configuração operacional continua com sessão, CSRF, revisão, contrato e recuperação próprios.
    const target=api('files-manager','settings','settings-save'),settings={uploads:{public:'media',private:'secure-media'}},revision=field(opened.body,'revision'),document=JSON.stringify(settings);
    assert.equal((await request(target,{revision,document})).status,403);assert.equal((await request(target)).status,405);
    assert.equal((await request(target,{revision,document,csrf_token:csrf,enabled:'0'})).status,200);assert.equal(read().plugins['files-manager'].enabled,true);assert.deepEqual(read().plugins['files-manager'].settings,{...settings,browser:{pageSize:25}});
    for(const id of ['menu','slides'])assert.deepEqual(read().plugins[id],legacy.plugins[id]);
    assert.equal((await request(target,{revision,document,csrf_token:csrf})).status,409);
    const current=await request(screen('files-manager')),bytes=fs.readFileSync(configFile);
    for(const invalid of ['{','[]','null',JSON.stringify({...settings,enabled:false}),JSON.stringify({uploads:{public:'../outside',private:'media'}})]) {
      assert.equal((await request(target,{revision:field(current.body,'revision'),document:invalid,csrf_token:csrf})).status,422);assert.deepEqual(fs.readFileSync(configFile),bytes);
    }
    const backups=fs.readdirSync(path.join(sandbox.temporary,'storage/backups/plugins')).sort().reverse(),backup=backups[0];assert.ok(backup);
    const management=await request('/cms-admin/?view=plugins');assert.equal((await request('/cms-admin/',{action:'extension-toggle',kind:'plugins',id:'files-manager',enabled:'0',revision:field(management.body,'revision'),csrf_token:csrf})).status,303);
    const disabled=await request(screen('files-manager'));assert.equal(disabled.status,200);
    assert.equal((await request(api('files-manager','history','options-restore'),{csrf_token:csrf,revision:field(disabled.body,'revision'),backup})).status,200);assert.equal(read().plugins['files-manager'].enabled,false);assert.deepEqual(read().plugins['files-manager'].settings,{...legacy.plugins['files-manager'].settings,browser:{pageSize:25}});
    for(const id of ['menu','slides'])assert.deepEqual(read().plugins[id],legacy.plugins[id]);
    contentFiles.forEach((file,index)=>assert.deepEqual(fs.readFileSync(file),contents[index]));assert.ok(!/PHP (?:Warning|Fatal|Parse)/.test(sandbox.logs()),sandbox.logs());
  } finally {await sandbox.close();}
});
