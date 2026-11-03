/** Jornada HTTP real do painel isolado: login, edição, conflito, rascunho, remoção/recuperação e templates. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import net from 'node:net';
import { once } from 'node:events';
import { spawn } from 'node:child_process';
import { root, trees, appRoot, workspace, environment } from './helpers/runtime.mjs';

/** Obtém porta efêmera local; sem argumentos; retorna número e fecha o socket de reserva. */
async function freePort() {
  const socket=net.createServer();socket.listen(0,'127.0.0.1');await once(socket,'listening');
  const port=socket.address().port;await new Promise((resolve,reject)=>socket.close(error=>error?reject(error):resolve()));return port;
}
/** Decodifica entidades produzidas pelo escape PHP; recebe texto e retorna JSON literal, sem avaliar HTML. */
function decode(text) { return text.replace(/&(quot|amp|lt|gt|#039);/g,(_,key)=>({quot:'"',amp:'&',lt:'<',gt:'>','#039':"'"}[key])); }
/** Extrai input de contrato do HTML; recebe corpo/nome e retorna valor ou falha por ausência. */
function field(body,name) { const match=body.match(new RegExp(`name="${name}" value="([^"]*)"`));assert.ok(match,`Campo ${name} ausente`);return decode(match[1]); }
for(const tree of trees) test(`${tree}: jornada administrativa completa por HTTP`,{timeout:30000},async()=>{
  const temporary=workspace(tree);const siteFile=path.join(temporary,'contents/site.json'),site=JSON.parse(fs.readFileSync(siteFile));site.settings.adminLocale='pt-br';fs.writeFileSync(siteFile,JSON.stringify(site));const port=await freePort();const base=`http://127.0.0.1:${port}`;
  const server=spawn('php',['-d','disable_functions=mail','-d','display_errors=0','-S',`127.0.0.1:${port}`,'-t',path.join(appRoot(tree),'public_html'),path.join(root,'tests/helpers/server-router.php')],{env:{...environment(temporary),DBLESS_CMS_APP_ROOT:appRoot(tree)}});
  let logs='';server.stderr.on('data',data=>logs+=data);server.stdout.on('data',data=>logs+=data);let cookie='';
  /** Requisita apenas o servidor efêmero; recebe caminho/formulário e retorna status/corpo/headers; acompanha cookie de sessão. */
  async function request(url,post=null) {
    const response=await fetch(base+url,{method:post?'POST':'GET',redirect:'manual',headers:{...(cookie?{Cookie:cookie}:{}),...(post?{'Content-Type':'application/x-www-form-urlencoded'}:{})},body:post?new URLSearchParams(post):undefined});
    for(const value of response.headers.getSetCookie()) cookie=value.split(';')[0];
    return {status:response.status,body:await response.text(),headers:response.headers};
  }
  try {
    let ready=false;
    for(let attempt=0;attempt<60;attempt++){try{await fetch(base+'/');ready=true;break;}catch{await new Promise(resolve=>setTimeout(resolve,50));}}
    assert.ok(ready,logs);
    let result=await request('/cms-admin/');assert.equal(result.status,200);assert.match(result.body,/name="action" value="login"/);
    assert.match(result.headers.get('cache-control'),/\bno-store\b/);assert.ok(!result.headers.get('content-security-policy').includes('unsafe-inline'));
    const token=field(result.body,'csrf_token');
    result=await request('/cms-admin/',{action:'save',key:'home',document:'{}',revision:'bad',csrf_token:token});assert.equal(result.status,403);
    result=await request('/cms-admin/',{action:'login',username:'editor',password:'wrong-password-for-test',csrf_token:token});assert.equal(result.status,401);
    result=await request('/cms-admin/',{action:'login',username:'editor',password:'Synthetic-DBLESS-CMS-password-2026',csrf_token:token});assert.equal(result.status,303);
    result=await request('/cms-admin/?view=pages');assert.equal(result.status,200);
    assert.match(result.body,/data-plugin-menu="pages" open/);assert.match(result.body,/plugin=pages&amp;route=new/);assert.match(result.body,/plugin=pages&amp;route=trash/);
    assert.match(result.body,/class="extension-table"/);assert.match(result.body,/data-page-id="home"/);assert.doesNotMatch(result.body,/class="stats-grid"|class="create-form"/);
    result=await request('/cms-admin/?view=page-new');assert.equal(result.status,200);assert.match(result.body,/operation=editorial-create/);
    result=await request('/cms-admin/?view=page-trash');assert.equal(result.status,200);assert.match(result.body,/Nenhuma página na lixeira/);
    result=await request('/cms-admin/?view=edit&key=home');assert.equal(result.status,200);
    const csrf=field(result.body,'csrf_token'),revision=field(result.body,'revision');
    const original=JSON.parse(decode(result.body.match(/data-document[^>]*>([\s\S]*?)<\/textarea>/)[1]));
    const edited=structuredClone(original);edited.content.intro='Conteúdo sintético alterado pelo painel.';
    result=await request('/cms-admin/?view=edit&key=home',{action:'save',key:'home',document:JSON.stringify(edited),revision,csrf_token:csrf});assert.equal(result.status,303);
    assert.match((await request('/')).body,/Conteúdo sintético alterado pelo painel/);
    result=await request('/cms-admin/?view=edit&key=home',{action:'save',key:'home',document:JSON.stringify(original),revision,csrf_token:csrf});assert.equal(result.status,409);
    assert.match(result.body,/Outra edição foi salva/);
    result=await request('/cms-admin/',{action:'create',slug:'pagina-teste',model:'portfolio-details',csrf_token:csrf});assert.equal(result.status,303);
    assert.equal((await request('/pagina-teste')).status,404);
    result=await request('/cms-admin/?view=edit&key=pagina-teste');
    const created=JSON.parse(fs.readFileSync(path.join(temporary,'contents/pages/pagina-teste.json')));created.status='published';
    result=await request('/cms-admin/?view=edit&key=pagina-teste',{action:'save',key:'pagina-teste',document:JSON.stringify(created),revision:field(result.body,'source_revision'),csrf_token:csrf});assert.equal(result.status,303);
    assert.equal((await request('/pagina-teste')).status,200);
    result=await request('/cms-admin/?view=edit&key=pagina-teste');
    result=await request('/cms-admin/',{action:'remove',key:'pagina-teste',revision:field(result.body,'source_revision'),csrf_token:csrf});assert.equal(result.status,303);
    assert.equal((await request('/pagina-teste')).status,404);
    result=await request('/cms-admin/?view=page-trash');assert.equal(result.status,200);assert.match(result.body,/plugin=pages&amp;route=history&amp;key=pagina-teste/);
    result=await request('/cms-admin/?view=pages');assert.doesNotMatch(result.body,/data-page-id="pagina-teste"/);
    result=await request('/cms-admin/?view=history&key=pagina-teste');
    result=await request('/cms-admin/',{action:'restore',key:'pagina-teste',revision:'',backup:field(result.body,'backup'),csrf_token:csrf});assert.equal(result.status,303);
    assert.equal((await request('/pagina-teste')).status,200);
    result=await request('/cms-admin/?view=page-trash');assert.doesNotMatch(result.body,/plugin=pages&amp;route=history&amp;key=pagina-teste/);
    result=await request('/cms-admin/?view=templates');const siteRevision=field(result.body,'revision');
    result=await request('/cms-admin/',{action:'template',template:'essencial',revision:siteRevision,csrf_token:csrf});assert.equal(result.status,303);
    assert.match((await request('/')).body,/templates\/essencial\/css\/style/);
    result=await request('/cms-admin/');assert.match(result.body,/cms-admin\/assets\/admin/);assert.ok(!result.body.includes('bootstrap'));
    for(const match of result.body.matchAll(/(?:href|src)="([^"]*cms-admin\/assets\/[^"]+)"/g)) assert.equal((await request(new URL(match[1]).pathname)).status,200);
    result=await request('/cms-admin/?view=site');
    const globalData=JSON.parse(decode(result.body.match(/data-document[^>]*>([\s\S]*?)<\/textarea>/)[1]));
    const globalRevision=field(result.body,'revision');
    assert.match(result.body,/data-global-groups=/);assert.match(result.body,/data-languages=/);assert.doesNotMatch(result.body,/<select[^>]*name="site_template"/);
    result=await request('/cms-admin/?view=site',{action:'save',key:'site',document:JSON.stringify(globalData),revision:globalRevision,admin_locale:'en-us',csrf_token:csrf});assert.equal(result.status,303);
    result=await request('/cms-admin/?view=site');
    assert.match(result.body,/<html lang="en-us"/);assert.match(result.body,/>Global settings</);
    assert.match(result.body,/Content saved with a new revision/);assert.match(result.body,/Default administration language/);
    const savedGlobal=JSON.parse(decode(result.body.match(/data-document[^>]*>([\s\S]*?)<\/textarea>/)[1]));
    assert.equal(savedGlobal.settings.name,globalData.settings.name);assert.equal(savedGlobal.settings.adminLocale,'en-us');assert.equal(savedGlobal.template,'essencial');
    const publicPage=await request('/');
    assert.match(publicPage.body,/templates\/essencial\/css\/style/);
    for(const match of publicPage.body.matchAll(/(?:href|src)="([^"]*\/templates\/[^\"]+)"/g)) assert.equal((await request(new URL(match[1]).pathname)).status,200);
    assert.equal((await request('/templates/essencial/unsafe.php')).status,403);
    const newGlobalRevision=field(result.body,'revision');
    const messages=JSON.parse(decode(result.body.match(/data-admin-language="([^"]*)"/)[1]));assert.equal(messages['field.title'],'Title');
    result=await request('/cms-admin/?view=site',{action:'save',key:'site',document:JSON.stringify(globalData),revision:newGlobalRevision,admin_locale:'en-us',csrf_token:'forged'});
    assert.equal(result.status,403);assert.match(result.body,/Session expired/);
    result=await request('/cms-admin/?view=site',{action:'save',key:'site',document:JSON.stringify(globalData),revision:newGlobalRevision,admin_locale:'../../escape',csrf_token:csrf});
    assert.equal(result.status,422);assert.match(result.body,/Admin language is unavailable/);
    result=await request('/cms-admin/?view=site',{action:'save',key:'site',document:JSON.stringify(globalData),revision:globalRevision,admin_locale:'pt-br',csrf_token:csrf});
    assert.equal(result.status,409);assert.match(result.body,/Another edit has been saved/);
    result=await request('/cms-admin/?view=site',{action:'save',key:'site',document:JSON.stringify(savedGlobal),revision:newGlobalRevision,site_template:'../escape',csrf_token:csrf});
    assert.equal(result.status,422);
    const missingTemplate={...savedGlobal,template:'not-installed'};
    fs.writeFileSync(path.join(temporary,'contents/site.json'),JSON.stringify(missingTemplate));
    assert.equal((await request('/')).status,422);
    result=await request('/cms-admin/?view=site');assert.equal(result.status,200);assert.match(result.body,/active template is unavailable/);
    result=await request('/cms-admin/',{action:'template',template:'essencial',revision:field(result.body,'revision'),csrf_token:csrf});
    assert.equal(result.status,303);assert.equal((await request('/')).status,200);
    result=await request('/cms-admin/',{action:'logout',csrf_token:csrf});assert.equal(result.status,303);
    result=await request('/cms-admin/');assert.match(result.body,/name="action" value="login"/);
    assert.match(result.body,/>Username<input/);assert.match(result.body,/aria-label="Sign in"/);assert.ok(!result.body.includes('ACESSO ADMINISTRATIVO'));
    assert.ok(!/PHP (Warning|Fatal|Parse)/.test(logs),logs);
  }finally{server.kill('SIGTERM');await once(server,'exit').catch(()=>{});fs.rmSync(temporary,{recursive:true,force:true});}
});
