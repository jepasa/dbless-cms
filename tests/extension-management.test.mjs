/** Regressão do gerenciamento: exclusividade/fallback, opções tipadas, quarentena recuperável e autorização em cópias sintéticas. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { pluginServer } from './helpers/plugins.mjs';
import { trees,appRoot } from './helpers/runtime.mjs';
import { installPublicPlugin } from './helpers/public-plugin.mjs';

/** Extrai valor de input escapado; recebe HTML/nome e falha quando o campo necessário está ausente. */
function field(html,name) {const value=html.match(new RegExp(`name="${name}" value="([^"]*)"`))?.[1];assert.notEqual(value,undefined,name);return value.replace(/&(?:amp|quot);/g,entity=>({'&amp;':'&','&quot;':'"'})[entity]);}
/** Cria cliente autenticado isolado; recebe sandbox, retorna helpers sem seguir redirects e sem acessar servidor real. */
async function client(sandbox) {
  const siteFile=path.join(sandbox.temporary,'contents/site.json'),site=JSON.parse(fs.readFileSync(siteFile));site.settings.adminLocale='pt-br';fs.writeFileSync(siteFile,JSON.stringify(site));
  let cookie='';
  /** Requisita rota/formulário sintético; retorna status/corpo e mantém somente a sessão deste teste. */
  async function request(route,form=null) {
    const response=await fetch(sandbox.base+route,{redirect:'manual',method:form?'POST':'GET',headers:cookie?{Cookie:cookie}:{},body:form?new URLSearchParams(form):null});
    for (const value of response.headers.getSetCookie()) cookie=value.split(';')[0];
    return {status:response.status,body:await response.text(),headers:response.headers};
  }
  const login=await request('/cms-admin/');
  assert.equal((await request('/cms-admin/',{action:'login',username:'editor',password:'Synthetic-DBLESS-CMS-password-2026',csrf_token:field(login.body,'csrf_token')})).status,303);
  /** Submete ação com revisão atual do catálogo; recebe tipo/ação/ID/extras e retorna resposta, sem alterar o pedido original. */
  async function action(kind,operation,id,extras={}) {
    const table=await request('/cms-admin/?view='+kind);assert.equal(table.status,200,table.body);
    return request('/cms-admin/?view='+kind,{action:operation,kind,id,revision:field(table.body,'revision'),csrf_token:field(table.body,'csrf_token'),...extras});
  }
  return {request,action};
}
for (const tree of trees) {
  test(`${tree}: padrão Essencial, menu de usuário, exclusividade, opções e desinstalação de template`,{timeout:20000},async()=>{
    assert.equal(JSON.parse(fs.readFileSync(path.join(appRoot(tree),tree==='src'?'system/resources/contents/site.json':'system/storage/contents/site.json'))).template,'essencial');
    const sandbox=await pluginServer(tree);
    try {
      const {request,action}=await client(sandbox);
      const site=()=>JSON.parse(fs.readFileSync(path.join(sandbox.temporary,'contents/site.json')));
      const table=await request('/cms-admin/?view=templates');assert.equal(table.status,200,table.body);
      assert.match(table.body,/icon-person-fill/);assert.match(table.body,/<table class="extension-table"/);assert.match(table.body,/class="catalog-sort"[^>]*>Nome<[^>]*class="admin-icon/);assert.match(table.body,/class="catalog-sort"[^>]*>Descrição<[^>]*class="admin-icon/);assert.match(table.body,/Padrão protegido/);
      const sidebar=table.body.match(/<aside id="admin-navigation"[\s\S]*?<\/aside>/)[0];assert.doesNotMatch(sidebar,/href="\?view=(site|templates)"/);
      assert.match(table.body,/data-user-menu[\s\S]*view=site[\s\S]*view=plugins[\s\S]*view=templates[\s\S]*<hr>[\s\S]*name="action" value="logout"/);
      const old=field(table.body,'revision'),csrf=field(table.body,'csrf_token');
      assert.equal((await request('/cms-admin/?view=templates',{action:'extension-toggle',kind:'templates',id:'synthetic-theme',enabled:'0',revision:old,csrf_token:'forged'})).status,403);
      assert.equal(site().template,'synthetic-theme');
      assert.equal((await action('templates','extension-toggle','synthetic-theme',{enabled:'0'})).status,303);assert.equal(site().template,'essencial');
      assert.equal((await request('/cms-admin/?view=templates',{action:'extension-toggle',kind:'templates',id:'synthetic-theme',enabled:'1',revision:old,csrf_token:csrf})).status,409);assert.equal(site().template,'essencial');
      assert.equal(((await request('/cms-admin/?view=templates')).body.match(/status published/g)||[]).length,1);
      assert.equal((await action('templates','extension-toggle','essencial',{enabled:'0'})).status,303);assert.equal(site().template,'essencial');
      assert.equal((await action('templates','extension-uninstall','essencial',{confirmation:'essencial'})).status,422);assert.ok(fs.existsSync(path.join(sandbox.application,'system/templates/essencial/template.json')));
      const options=await request('/cms-admin/?view=template-settings&template=essencial');assert.equal(options.status,200,options.body);
      const form={action:'template-options',template:'essencial',revision:field(options.body,'revision'),csrf_token:field(options.body,'csrf_token'),'options[appearance][palette]':'ocean','options[appearance][wide]':'1'};
      const before=fs.readFileSync(path.join(sandbox.temporary,'contents/templates.json'));
      assert.equal((await request('/cms-admin/?view=template-settings&template=essencial',{...form,'options[appearance][palette]':'arbitrary" onclick="bad'})).status,422);
      assert.deepEqual(fs.readFileSync(path.join(sandbox.temporary,'contents/templates.json')),before);
      assert.equal((await request('/cms-admin/?view=template-settings&template=essencial',form)).status,303);
      assert.match((await request('/')).body,/class="palette-ocean content-wide mode-light font-medium align-start"/);
      assert.equal((await request('/cms-admin/?view=template-settings&template=essencial',form)).status,409);
      assert.ok(fs.readdirSync(path.join(sandbox.temporary,'storage/backups/templates')).length>0);
      assert.equal((await request('/cms-admin/?view=template-settings&template=synthetic-theme')).status,404);
      assert.equal((await action('templates','extension-toggle','synthetic-theme',{enabled:'1'})).status,303);assert.equal(site().template,'synthetic-theme');
      assert.equal((await action('templates','extension-uninstall','synthetic-theme',{confirmation:'wrong'})).status,422);
      assert.equal((await action('templates','extension-uninstall','synthetic-theme',{confirmation:'synthetic-theme'})).status,303);assert.equal(site().template,'essencial');
      assert.ok(!fs.existsSync(path.join(sandbox.application,'system/templates/synthetic-theme')));
      assert.ok(fs.readdirSync(path.join(sandbox.temporary,'storage/uninstalled/templates')).some(id=>id.startsWith('synthetic-theme-')));
      assert.ok(fs.existsSync(path.join(sandbox.application,'public_html/templates/synthetic-theme/images/examples/studio.png')));
      assert.equal((await request('/templates/synthetic-theme/images/examples/studio.png')).status,200);assert.equal((await request('/')).status,200);
      assert.equal((await action('templates','extension-toggle','../escape',{enabled:'1'})).status,422);
      const logout=await request('/cms-admin/?view=templates');assert.equal((await request('/cms-admin/',{action:'logout',csrf_token:field(logout.body,'csrf_token')})).status,303);
      assert.match((await request('/cms-admin/')).body,/icon-person-circle/);
      assert.ok(!/PHP (?:Warning|Fatal|Parse)/.test(sandbox.logs()),sandbox.logs());
    } finally {await sandbox.close();}
  });
  test(`${tree}: plugins mantêm configuração própria e dados, bloqueando rotas após desinstalação`,{timeout:20000},async()=>{
    const sandbox=await pluginServer(tree);
    try {
      const {request,action}=await client(sandbox);
      let table=await request('/cms-admin/?view=plugins');assert.match(table.body,/Gerenciador de arquivos/);assert.match(table.body,/view=plugin-panel&amp;plugin=files-manager&amp;route=settings/);
      assert.equal((await action('plugins','extension-toggle','files-manager',{enabled:'0'})).status,303);
      assert.equal((await request('/cms-admin/?view=plugin-panel&plugin=files-manager&route=settings')).status,200);
      assert.equal((await request('/cms-admin/?view=plugin-panel&plugin=files-manager&route=overview')).status,404);
      assert.equal((await action('plugins','extension-toggle','files-manager',{enabled:'1'})).status,303);
      const media=path.join(sandbox.application,'public_html/media');fs.mkdirSync(media,{recursive:true});fs.writeFileSync(path.join(media,'retained.txt'),'SYNTHETIC PUBLIC');
      const storage=path.join(sandbox.temporary,'storage/files/media');fs.mkdirSync(storage,{recursive:true});fs.writeFileSync(path.join(storage,'retained.txt'),'SYNTHETIC PRIVATE');
      assert.equal((await action('plugins','extension-uninstall','files-manager',{confirmation:'files-manager'})).status,303);
      assert.equal(fs.readFileSync(path.join(media,'retained.txt'),'utf8'),'SYNTHETIC PUBLIC');assert.equal(fs.readFileSync(path.join(storage,'retained.txt'),'utf8'),'SYNTHETIC PRIVATE');
      assert.equal(JSON.parse(fs.readFileSync(path.join(sandbox.temporary,'contents/plugins.json'))).plugins['files-manager'].enabled,false);
      assert.equal((await request('/cms-admin/?view=plugin-panel&plugin=files-manager&route=settings')).status,404);
      const fixture=installPublicPlugin(sandbox);assert.equal((await request('/plugins/public-example/home')).status,200);
      assert.equal((await action('plugins','extension-uninstall',fixture.id,{confirmation:fixture.id})).status,303);
      assert.equal((await request('/plugins/public-example/home')).status,404);assert.equal((await request('/cms-admin/?view=plugin-panel&plugin=public-example&route=private')).status,404);
      const retained=JSON.parse(fs.readFileSync(path.join(sandbox.temporary,'contents/plugins.json'))).plugins[fixture.id];assert.equal(retained.enabled,false);assert.equal(retained.settings.privateValue,'PRIVATE SYNTHETIC VALUE');
      const archiveRoot=path.join(sandbox.temporary,'storage/uninstalled/plugins');const archived=fs.readdirSync(archiveRoot).find(id=>id.startsWith('public-example-'));
      fs.renameSync(path.join(archiveRoot,archived),fixture.directory);
      assert.equal((await request('/plugins/public-example/home')).status,404,'Reinstalação manual não deve reativar o plugin.');
      assert.equal((await action('plugins','extension-toggle',fixture.id,{enabled:'1'})).status,303);assert.equal((await request('/plugins/public-example/home')).status,200);
    } finally {await sandbox.close();}
  });
  test(`${tree}: catálogo diagnostica inválidos e quarentena rejeita links com reversão em fallback inválido`,{timeout:20000},async()=>{
    const sandbox=await pluginServer(tree);
    try {
      const {request,action}=await client(sandbox);
      const broken=path.join(sandbox.application,'system/plugins/broken');fs.mkdirSync(broken);fs.writeFileSync(path.join(broken,'plugin.json'),'{');
      let table=await request('/cms-admin/?view=plugins');assert.match(table.body,/data-extension-id="broken"/);assert.match(table.body,/Inválido/);
      assert.equal((await action('plugins','extension-toggle','broken',{enabled:'1'})).status,404);
      assert.equal((await action('plugins','extension-uninstall','broken',{confirmation:'broken'})).status,303);
      const outside=path.join(sandbox.temporary,'sentinel.txt');fs.writeFileSync(outside,'OUTSIDE');
      fs.symlinkSync(outside,path.join(sandbox.application,'system/plugins/files-manager/linked.txt'));
      assert.equal((await action('plugins','extension-uninstall','files-manager',{confirmation:'files-manager'})).status,422);assert.equal(fs.readFileSync(outside,'utf8'),'OUTSIDE');
      assert.ok(fs.existsSync(path.join(sandbox.application,'system/plugins/files-manager/Plugin.php')));
      const defaultManifest=path.join(sandbox.application,'system/templates/essencial/template.json');const original=fs.readFileSync(defaultManifest);fs.writeFileSync(defaultManifest,'{');
      const siteFile=path.join(sandbox.temporary,'contents/site.json'),before=fs.readFileSync(siteFile);
      assert.equal((await action('templates','extension-uninstall','synthetic-theme',{confirmation:'synthetic-theme'})).status,422);
      assert.deepEqual(fs.readFileSync(siteFile),before);assert.ok(fs.existsSync(path.join(sandbox.application,'system/templates/synthetic-theme/template.json')));
      fs.writeFileSync(defaultManifest,original);
      // Estrutura exclusiva de um tema não pode introduzir conteúdo sem retorno ao padrão.
      const privateTheme=path.join(sandbox.application,'system/templates/synthetic-theme');
      const themeFile=path.join(privateTheme,'template.json'),theme=JSON.parse(fs.readFileSync(themeFile));theme.pages.special=theme.pages['starter-page'];fs.writeFileSync(themeFile,JSON.stringify(theme));
      const contractFile=path.join(privateTheme,'schema.json'),contract=JSON.parse(fs.readFileSync(contractFile));contract.pages.special=contract.pages['starter-page'];fs.writeFileSync(contractFile,JSON.stringify(contract));
      const dashboard=await request('/cms-admin/');const csrf=field(dashboard.body,'csrf_token');
      assert.equal((await request('/cms-admin/',{action:'create',slug:'synthetic-special',model:'starter-page',csrf_token:csrf})).status,303);
      const editor=await request('/cms-admin/?view=edit&key=synthetic-special');const pageFile=path.join(sandbox.temporary,'contents/pages/synthetic-special.json'),pageBefore=fs.readFileSync(pageFile),page=JSON.parse(pageBefore);page.page='special';
      assert.equal((await request('/cms-admin/?view=edit&key=synthetic-special',{action:'save',key:'synthetic-special',document:JSON.stringify(page),revision:field(editor.body,'source_revision'),csrf_token:csrf})).status,422);
      assert.deepEqual(fs.readFileSync(pageFile),pageBefore);
      assert.equal((await action('templates','extension-toggle','essencial',{enabled:'1'})).status,303);
      assert.ok(!/PHP (?:Warning|Fatal|Parse)/.test(sandbox.logs()),sandbox.logs());
    } finally {await sandbox.close();}
  });
}
