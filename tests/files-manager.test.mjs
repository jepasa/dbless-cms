/** Regressões HTTP de plugins/uploads: autenticação, revisão, MIME, confinamento, lixeira e configuração. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { pluginServer, pluginSandbox } from './helpers/plugins.mjs';
import { root, trees, environment } from './helpers/runtime.mjs';

/** Extrai campo escapado do painel sintético; recebe HTML/nome e retorna valor. */
function field(html,name) { const value=html.match(new RegExp(`name="${name}" value="([^"]*)"`))?.[1]; assert.ok(value!==undefined,name); return value.replaceAll('&quot;','"').replaceAll('&amp;','&'); }
for (const tree of trees) {
  test(`${tree}: registro descobre sem executar, rejeita manifestos e mantém novos plugins desativados`,()=>{
    const context=pluginSandbox(tree);
    try {
      const folder=path.join(context.application,'system/plugins');
      fs.cpSync(path.join(folder,'files-manager'),path.join(folder,'other'),{recursive:true});
      const manifest=JSON.parse(fs.readFileSync(path.join(folder,'other/plugin.json'))); manifest.id='other';
      fs.writeFileSync(path.join(folder,'other/plugin.json'),JSON.stringify(manifest));
      fs.writeFileSync(path.join(folder,'other/Plugin.php'),'<?php throw new RuntimeException("MUST NOT EXECUTE");');
      fs.cpSync(path.join(context.application,'public_html/plugins/files-manager'),path.join(context.application,'public_html/plugins/other'),{recursive:true});
      fs.mkdirSync(path.join(folder,'broken'));fs.writeFileSync(path.join(folder,'broken/plugin.json'),'{');
      fs.mkdirSync(path.join(folder,'bad-class'));fs.writeFileSync(path.join(folder,'bad-class/plugin.json'),JSON.stringify({...manifest,id:'bad-class',class:[]}));
      fs.cpSync(path.join(folder,'files-manager'),path.join(folder,'bad-schema'),{recursive:true});
      fs.cpSync(path.join(context.application,'public_html/plugins/files-manager'),path.join(context.application,'public_html/plugins/bad-schema'),{recursive:true});
      fs.writeFileSync(path.join(folder,'bad-schema/plugin.json'),JSON.stringify({...manifest,id:'bad-schema'}));
      fs.writeFileSync(path.join(folder,'bad-schema/settings.schema.json'),JSON.stringify({type:'object',additionalProperties:false,properties:{},required:'bad'}));
      fs.cpSync(path.join(folder,'files-manager'),path.join(folder,'bad-route'),{recursive:true});fs.cpSync(path.join(context.application,'public_html/plugins/files-manager'),path.join(context.application,'public_html/plugins/bad-route'),{recursive:true});
      fs.writeFileSync(path.join(folder,'bad-route/plugin.json'),JSON.stringify({...manifest,id:'bad-route',navigation:{label:'fm.menu',items:[{label:'fm.trash',route:'https://outside.invalid'}]}}));
      const result=spawnSync('php',['-r',`require $argv[1].'/system/bootstrap.php'; $p=new DBLESSCMS\\Plugins(new DBLESSCMS\\JsonStore(new DBLESSCMS\\Config())); echo json_encode([array_keys($p->all()),array_keys($p->invalid()),$p->state('other'),$p->navigation('en-us')]);`,context.application],{env:environment(context.temporary),encoding:'utf8'});
      assert.equal(result.status,0,result.stderr); assert.equal(result.stderr,'');
      const [valid,invalid,state,menus]=JSON.parse(result.stdout); assert.deepEqual(valid,['blog','files-manager','forms','homepage','menu','other','pages','slides','widgets']);assert.deepEqual(invalid,['bad-class','bad-route','bad-schema','broken']);assert.equal(state.enabled,false);assert.equal(menus.other.label,'Images');assert.deepEqual(menus.other.items[1],{separator:true});assert.ok(!menus.other.items.some(item=>item.route==='rights'));
    } finally {fs.rmSync(context.temporary,{recursive:true,force:true});}
  });
  test(`${tree}: sidebar e roteador aceitam página própria declarada sem mudança no núcleo`,{timeout:15000},async()=>{
    const context=await pluginServer(tree);let cookie='';
    /** Requisita sandbox e mantém cookie; recebe rota/formulário, retorna resposta HTTP sem redirects. */
    async function request(route,form=null) {
      const response=await fetch(context.base+route,{redirect:'manual',method:form?'POST':'GET',headers:cookie?{Cookie:cookie}:{},body:form});for(const value of response.headers.getSetCookie())cookie=value.split(';')[0];return response;
    }
    try {
      const login=await (await request('/cms-admin/')).text();
      assert.equal((await request('/cms-admin/',new URLSearchParams({action:'login',username:'editor',password:'Synthetic-DBLESS-CMS-password-2026',csrf_token:field(login,'csrf_token')}))).status,303);
      const sidebar=await (await request('/cms-admin/')).text();
      for(const id of ['files-manager','menu','pages','slides']) {
        const dropdown=sidebar.match(new RegExp(`data-plugin-menu="${id}"[^>]*>[\\s\\S]*?</details>`))?.[0];
        assert.ok(dropdown,`${id}: dropdown presente`);
        const links=[...dropdown.matchAll(/<a\b[^>]*href="([^"]*)"[^>]*>([^<]*)<\/a>|<hr>/g)];
        const settings=links.filter(link=>link[1]?.endsWith('route=settings'));
        assert.equal(settings.length,id==='files-manager'?1:0,`${id}: somente raízes de uploads possuem configuração`);
        if(id!=='files-manager')continue;
        assert.equal(settings[0][2],'Configurações');
        const separator=links.indexOf(settings[0])+1;
        assert.equal(links[separator-1],settings[0],`${id}: configurações imediatamente antes do separador`);
        assert.ok(links[separator+1][1].endsWith('route=trash'),`${id}: lixeira após o separador`);
        const url=settings[0][1].replaceAll('&amp;','&');
        assert.ok(url.includes(`plugin=${id}&route=settings`));
        const target=new URL(url,context.base+'/cms-admin/');
        assert.equal(target.origin,context.base);
        assert.equal(target.pathname,'/cms-admin/');
        const response=await request(target.pathname+target.search);assert.equal(response.status,200);
        assert.match(await response.text(),new RegExp(`action="[^"\\s]*plugin=${id}&amp;route=settings(?:&amp;[^"\\s]*)?"`));
      }
      const directory=path.join(context.application,'system/plugins/files-manager');
      const file=path.join(directory,'plugin.json'),manifest=JSON.parse(fs.readFileSync(file));
      manifest.routes.reports={title:'fm.reports',file:'pages/reports.php',requiresEnabled:true};manifest.navigation={label:'fm.reports',route:'reports'};
      fs.writeFileSync(file,JSON.stringify(manifest));
      fs.writeFileSync(path.join(directory,'pages/reports.php'),'<?php /** Página sintética de extensão para testar despacho privado. */ echo $context->integrated ? \'<div data-integrated="true">SYNTHETIC PLUGIN REPORT</div>\' : "SYNTHETIC PLUGIN REPORT";');
      for(const locale of ['pt-br','en-us']){const catalogFile=path.join(directory,'languages',locale+'.json');const catalog=JSON.parse(fs.readFileSync(catalogFile));catalog.messages['fm.reports']=locale==='pt-br'?'Relatório próprio':'Own report';fs.writeFileSync(catalogFile,JSON.stringify(catalog));}
      const navigation=await (await request('/cms-admin/')).text();
      assert.match(navigation,/route=reports[^>]*><img[^>]*class="admin-icon plugin-icon"[^>]*files-manager\.svg[^>]*>Relatório próprio<\/a>/);assert.ok(!navigation.includes('data-plugin-menu="files-manager"'));
      const panel=await request('/cms-admin/?view=plugin-panel&plugin=files-manager&route=reports');assert.equal(panel.status,200);const panelHtml=await panel.text();assert.match(panelHtml,/<div data-integrated="true">SYNTHETIC PLUGIN REPORT<\/div>/);assert.doesNotMatch(panelHtml,/<iframe/);
      const rendered=await request('/cms-admin/?view=plugin&plugin=files-manager&route=reports');assert.equal(rendered.status,200);assert.equal(await rendered.text(),'SYNTHETIC PLUGIN REPORT');
      manifest.navigation.items=[{label:'fm.trash',route:'missing'}];delete manifest.navigation.route;fs.writeFileSync(file,JSON.stringify(manifest));
      assert.equal((await request('/cms-admin/?view=plugin&plugin=files-manager&route=reports')).status,404);
      assert.ok(!/PHP (?:Warning|Fatal|Parse)/.test(context.logs()),context.logs());
    } finally {await context.close();}
  });
  test(`${tree}: upload e operações isolados, configurações vazias e contenção das duas raízes`,{timeout:30000},async()=>{
    const context=await pluginServer(tree);let cookie='',csrf='';
    /** Requisita somente sandbox, acompanha sessão e deixa redirecionamento visível. */
    async function request(route,body=null,authenticated=true) {
      const response=await fetch(context.base+route,{method:body?'POST':'GET',redirect:'manual',headers:authenticated&&cookie?{Cookie:cookie}:{},body});
      if(authenticated) for(const value of response.headers.getSetCookie()) cookie=value.split(';')[0];return response;
    }
    const api=(operation,scope='public',relative='')=>`/cms-admin/?view=plugin-api&plugin=files-manager&operation=${operation}&scope=${scope}&path=${encodeURIComponent(relative)}`;
    /** Lê snapshot corrente para uma área/pasta sintética. */
    async function list(scope='public',relative='') { const response=await request(api('list',scope,relative));assert.equal(response.status,200,await response.clone().text());return response.json(); }
    /** Envia formulário protegido e mantém revisão fornecida; recebe operação/dados/status. */
    async function mutate(operation,values={},status=200) {
      const response=await request(api(operation),new URLSearchParams({csrf_token:csrf,scope:'public',path:'',...values}));
      const data=await response.json();assert.equal(response.status,status,JSON.stringify(data));return data;
    }
    /** Faz upload real multipart via HTTP; recebe nome/bytes/dados/status e retorna snapshot ou erro. */
    async function upload(name,bytes,values={},status=200) {
      const form=new FormData();for(const [key,value] of Object.entries({csrf_token:csrf,scope:'public',path:'',...values}))form.append(key,value);
      form.append('file',new Blob([bytes]),name);const response=await request(api('upload'),form);const data=await response.json();assert.equal(response.status,status,JSON.stringify(data));return data;
    }
    try {
      assert.equal((await request(api('list'),null,false)).status,403);
      let response=await request('/cms-admin/');csrf=field(await response.text(),'csrf_token');
      response=await request('/cms-admin/',new URLSearchParams({action:'login',username:'editor',password:'Synthetic-DBLESS-CMS-password-2026',csrf_token:csrf}));assert.equal(response.status,303);
      const panel=await (await request('/cms-admin/?view=plugin-settings&plugin=files-manager')).text();csrf=field(panel,'csrf_token');
      assert.match(panel,/data-editor-schema=/);assert.match(panel,/setting.uploads/);assert.match(panel,/data-fm-settings/);assert.doesNotMatch(panel,/data-open-plugin/);assert.match(panel,/class="sidebar-dropdown"/);
      const overview=await request('/cms-admin/?view=plugin-panel&plugin=files-manager&route=overview');assert.equal(overview.status,200);const overviewHtml=await overview.text();assert.match(overviewHtml,/data-screen="overview"/);assert.doesNotMatch(overviewHtml,/<iframe/);assert.equal((overviewHtml.match(/<!doctype html>/gi)||[]).length,1);assert.equal((overviewHtml.match(/<h1>/g)||[]).length,1);assert.equal((overviewHtml.match(/<link[^>]+files-manager(?:\.min)?\.css/g)||[]).length,1);
      const bin=await request('/cms-admin/?view=plugin-panel&plugin=files-manager&route=trash');assert.equal(bin.status,200);assert.match(await bin.text(),/data-screen="trash"/);
      const settingsScreen=await request('/cms-admin/?view=plugin-panel&plugin=files-manager&route=settings');assert.equal(settingsScreen.status,200);assert.match(await settingsScreen.text(),/data-fm-settings/);
      assert.equal((await request('/cms-admin/?view=plugin-panel&plugin=files-manager&route=invalid')).status,404);
      assert.equal((await request('/cms-admin/?view=plugin&plugin=files-manager&screen[]=trash')).status,400);
      const trashDocument=await request('/cms-admin/?view=plugin&plugin=files-manager&screen=trash');assert.equal(trashDocument.status,200);assert.match(await trashDocument.text(),/data-screen="trash"/);
      const ownSettings=await (await request('/cms-admin/?view=plugin&plugin=files-manager&route=settings')).text();
      const ownRevision=field(ownSettings,'revision');
      const ownApi='/cms-admin/?view=plugin-api&plugin=files-manager&route=settings&operation=settings-save';
      assert.equal((await request(ownApi,new URLSearchParams({revision:ownRevision,public:'media',private:'media'}))).status,403);
      let own=await request(ownApi,new URLSearchParams({csrf_token:csrf,revision:ownRevision,public:'media',private:'media'}));assert.equal(own.status,200);let ownState=await own.json();
      assert.equal((await request(api('list'))).status,200);
      assert.equal((await request(api('list')+'&route=settings')).status,404);
      assert.equal((await request('/cms-admin/?view=plugin&plugin=files-manager&route=settings')).status,200);
      own=await request(ownApi,new URLSearchParams({csrf_token:csrf,revision:ownState.revision,public:'media',private:'media',enabled:'1'}));assert.equal(own.status,200);
      ownState=await own.json();
      const ownBackup=field(await (await request('/cms-admin/?view=plugin&plugin=files-manager&route=history')).text(),'backup');
      const configurationFile=path.join(context.temporary,'contents/plugins.json');const newer=JSON.parse(fs.readFileSync(configurationFile));newer.plugins.foreign={enabled:false,settings:{value:'newer-setting'}};fs.writeFileSync(configurationFile,JSON.stringify(newer));
      ownState.revision=field(await (await request('/cms-admin/?view=plugin&plugin=files-manager&route=settings')).text(),'revision');
      const ownRestore=await request('/cms-admin/?view=plugin-api&plugin=files-manager&route=settings&operation=settings-restore',new URLSearchParams({csrf_token:csrf,revision:ownState.revision,backup:ownBackup}));assert.equal(ownRestore.status,200);
      ownState=await ownRestore.json();assert.equal(JSON.parse(fs.readFileSync(configurationFile)).plugins.foreign.settings.value,'newer-setting');
      // Salvar/restaurar opções preserva a ativação; o payload legado enabled não altera o estado.
      own=await request(ownApi,new URLSearchParams({csrf_token:csrf,revision:ownState.revision,public:'media',private:'media',enabled:'1'}));assert.equal(own.status,200);
      let snapshot=await list();
      await mutate('mkdir',{name:'docs',revision:snapshot.revision,csrf_token:'invalid'},403);
      snapshot=await mutate('mkdir',{name:'docs',revision:snapshot.revision});
      await mutate('mkdir',{name:'other',revision:'stale'},409);
      await mutate('mkdir',{name:'../escape',revision:snapshot.revision},422);
      assert.equal((await request(api('upload'))).status,405);
      assert.equal((await request(api('list','public','../'))).status,422);
      const originalCode=fs.readFileSync(path.join(context.application,'public_html/index.php'));
      await mutate('rename',{target:'index.php',name:'renamed.php',revision:snapshot.revision},404);
      assert.deepEqual(fs.readFileSync(path.join(context.application,'public_html/index.php')),originalCode);
      await upload('run.php','<?php echo 1;',{revision:snapshot.revision},422);
      await upload('fake.jpg','synthetic plain text',{revision:snapshot.revision},422);
      await upload('large.txt','x'.repeat(10485761),{revision:snapshot.revision},413);
      await upload('payload.txt','<?php echo 1;',{revision:snapshot.revision},422);
      await mutate('move',{target:'docs',destination:'docs',revision:snapshot.revision},422);
      snapshot=await upload('notes.txt','Synthetic upload\n',{revision:snapshot.revision});
      await upload('notes.txt','replacement',{revision:snapshot.revision},409);
      assert.equal(await (await request('/media/notes.txt',null,false)).text(),'Synthetic upload\n');
      snapshot=await mutate('rename',{target:'notes.txt',name:'renamed.txt',revision:snapshot.revision});
      snapshot=await mutate('move',{target:'renamed.txt',destination:'docs',revision:snapshot.revision});
      let docs=await list('public','docs');assert.equal(docs.entries[0].name,'renamed.txt');
      await mutate('delete',{target:'renamed.txt',path:'docs',revision:docs.revision});
      const deleted=await (await request(api('trash'))).json();assert.equal(deleted.entries[0].name,'renamed.txt');
      assert.equal(deleted.entries[0].status,'deleted');assert.ok(deleted.entries[0].deleted_at);
      assert.equal(fs.readFileSync(path.join(context.temporary,'storage/plugins/files-manager/trash',deleted.entries[0].id,'data'),'utf8'),'Synthetic upload\n');
      assert.equal((await request('/media/docs/renamed.txt',null,false)).status,404);
      assert.equal((await list('public','docs')).entries.length,0);
      await mutate('restore',{target:deleted.entries[0].id,revision:deleted.revision});
      docs=await list('public','docs');assert.equal(docs.entries.length,1);
      await mutate('delete',{target:'renamed.txt',path:'docs',revision:docs.revision});
      const trash=await (await request(api('trash'))).json();await mutate('purge',{target:trash.entries[0].id,name:'wrong',revision:trash.revision},422);
      await mutate('purge',{target:trash.entries[0].id,name:'renamed.txt',revision:trash.revision});
      docs=await list('public','docs');await mutate('mkdir',{path:'docs',name:'nested',revision:docs.revision});
      const nested=await list('public','docs/nested');await upload('kept.txt','Nested bytes retained',{path:'docs/nested',revision:nested.revision});
      snapshot=await list();await mutate('delete',{target:'docs',revision:snapshot.revision});
      const folders=await (await request(api('trash'))).json();const folder=folders.entries.find(item=>item.name==='docs');assert.ok(folder.directory);assert.equal(folder.status,'deleted');
      assert.equal(fs.readFileSync(path.join(context.temporary,'storage/plugins/files-manager/trash',folder.id,'data/nested/kept.txt'),'utf8'),'Nested bytes retained');
      await mutate('restore',{target:folder.id,revision:folders.revision});
      assert.equal(fs.readFileSync(path.join(context.application,'public_html/media/docs/nested/kept.txt'),'utf8'),'Nested bytes retained');
      const privateList=await list('private');await upload('secret.txt','Synthetic private file',{scope:'private',revision:privateList.revision});
      assert.equal((await request('/system/storage/files/media/secret.txt',null,false)).status,404);
      assert.equal((await request(api('download','private','secret.txt'),null,false)).status,403);
      assert.equal(await (await request(api('download','private','secret.txt'))).text(),'Synthetic private file');
      const outside=path.join(context.temporary,'outside');fs.mkdirSync(outside);fs.writeFileSync(path.join(outside,'sentinel.txt'),'unchanged');
      fs.symlinkSync(outside,path.join(context.application,'public_html/media/link'));
      assert.equal((await request(api('list','public','link'))).status,422);
      assert.equal((await request('/media/link/sentinel.txt',null,false)).status,403);
      fs.symlinkSync(outside,path.join(context.temporary,'storage/files/media/link'));
      assert.equal((await request(api('list','private','link'))).status,422);
      assert.equal(fs.readFileSync(path.join(outside,'sentinel.txt'),'utf8'),'unchanged');
      /** Salva campos relativos pelas configurações reais do painel; nenhum conteúdo original é alterado. */
      async function settings(publicRoot,privateRoot,status=303,enabled=true) {
        const html=await (await request('/cms-admin/?view=plugin-settings&plugin=files-manager')).text();
        const response=await request('/cms-admin/?view=plugin-settings&plugin=files-manager',new URLSearchParams({action:'plugin-settings',plugin:'files-manager',csrf_token:csrf,revision:field(html,'revision'),'settings[uploads][public]':publicRoot,'settings[uploads][private]':privateRoot,...(enabled?{enabled:'1'}:{})}));
        assert.equal(response.status,status,await response.text());
      }
      await settings('/tmp','media',422);await settings('../escape','media',422);await settings('cms-admin','media',403);await settings('media/link','media',422);await settings('media','media/link',422);
      await settings('','');let empty=await list();assert.ok(!empty.entries.some(item=>item.name==='cms-admin'));
      await mutate('mkdir',{name:'cms-admin',revision:empty.revision},403);
      await mutate('delete',{target:'index.php',revision:empty.revision},403);
      empty=await upload('at-root.txt','Root upload',{revision:empty.revision});assert.ok(fs.existsSync(path.join(context.application,'public_html/at-root.txt')));
      let privateRoot=await list('private');await upload('at-private-root.txt','Private root upload',{scope:'private',revision:privateRoot.revision});assert.ok(fs.existsSync(path.join(context.temporary,'storage/files/at-private-root.txt')));
      await settings('photos/2026','documents/2026');empty=await list();await upload('nested.txt','nested',{revision:empty.revision});assert.ok(fs.existsSync(path.join(context.application,'public_html/photos/2026/nested.txt')));
      await settings('media','media');
      const management=await (await request('/cms-admin/?view=plugins')).text();
      assert.equal((await request('/cms-admin/',new URLSearchParams({action:'extension-toggle',kind:'plugins',id:'files-manager',enabled:'0',revision:field(management,'revision'),csrf_token:csrf}))).status,303);
      assert.equal((await request(api('list'))).status,404);
      assert.ok(fs.readdirSync(path.join(context.temporary,'storage/backups/plugins')).length>0);
      const history=await (await request('/cms-admin/?view=plugin-panel&plugin=files-manager&route=history')).text();
      const restored=await request('/cms-admin/?view=plugin-settings&plugin=files-manager',new URLSearchParams({action:'restore',key:'plugins',backup:field(history,'backup'),revision:field(history,'revision'),csrf_token:csrf}));
      assert.equal(restored.status,303);assert.equal((await request(api('list'))).status,200);
      const backupDirectory=path.join(context.temporary,'storage/backups/plugins');
      const backup=fs.readdirSync(backupDirectory).sort().reverse()[0];
      const bad=JSON.parse(fs.readFileSync(path.join(backupDirectory,backup)));bad.plugins['files-manager'].settings.uploads.public='../outside';fs.writeFileSync(path.join(backupDirectory,backup),JSON.stringify(bad));
      const before=fs.readFileSync(path.join(context.temporary,'contents/plugins.json'),'utf8');
      const current=await (await request('/cms-admin/?view=plugin-settings&plugin=files-manager')).text();
      const rejected=await request('/cms-admin/?view=plugin-settings&plugin=files-manager',new URLSearchParams({action:'restore',key:'plugins',backup,revision:field(current,'revision'),csrf_token:csrf}));
      assert.equal(rejected.status,422);assert.equal(fs.readFileSync(path.join(context.temporary,'contents/plugins.json'),'utf8'),before);
      assert.ok(!/PHP (?:Warning|Fatal|Parse)/.test(context.logs()),context.logs());
    } finally {await context.close();}
  });
}

for(const tree of trees) {
  test(`${tree}: PNG C2PA metadata stays intact while malformed PNG and appended executable data are rejected`,{timeout:30000},async()=>{
    const context=await pluginServer(tree);let cookie='';
    /** Request the synthetic admin with the current cookie; receives URL/body, returns the response and updates only the synthetic session. */
    async function request(route,body=null){const response=await fetch(context.base+route,{method:body?'POST':'GET',redirect:'manual',headers:cookie?{Cookie:cookie}:{},body});for(const value of response.headers.getSetCookie())cookie=value.split(';')[0];return response;}
    try {
      const login=await request('/cms-admin/'),csrf=field(await login.text(),'csrf_token');
      assert.equal((await request('/cms-admin/',new URLSearchParams({action:'login',username:'editor',password:'Synthetic-DBLESS-CMS-password-2026',csrf_token:csrf}))).status,303);
      const original=fs.readFileSync(path.join(root,'src/public_html/templates/essencial/images/examples/studio.png')),corrupt=Buffer.from(original);corrupt[29]^=1;
      const cases=[['credentials.png',original,200],['bad-crc.png',corrupt,422],['truncated.png',original.subarray(0,-1),422],['trailing.png',Buffer.concat([original,Buffer.from('TRAILING')]),422],['script.png',Buffer.concat([original,Buffer.from('<?php echo 1;')]),422]];
      for(const [name,bytes,status] of cases){const listing=await(await request('/cms-admin/?view=plugin-api&plugin=files-manager&operation=list&scope=public&path=')).json();const form=new FormData();for(const [key,value] of Object.entries({csrf_token:csrf,scope:'public',path:'',revision:listing.revision}))form.append(key,value);form.append('file',new Blob([bytes],{type:'image/png'}),name);const uploaded=await request('/cms-admin/?view=plugin-api&plugin=files-manager&operation=upload',form);assert.equal(uploaded.status,status,await uploaded.text());}
      assert.deepEqual(fs.readFileSync(path.join(context.application,'public_html/media/credentials.png')),original);
    } finally {await context.close();}
  });
}
