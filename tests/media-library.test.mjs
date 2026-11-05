/** Shared media regressions: synthetic public/private contexts, read-only pagination, root migration and remote-link policy. No live uploads. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {trees} from './helpers/runtime.mjs';
import {pluginServer} from './helpers/plugins.mjs';
/** Decode an escaped HTML metadata/form value; receives text, returns literal characters without DOM execution. */
function decode(value){return value.replace(/&(?:amp|quot|#039|lt|gt);/g,entity=>({'&amp;':'&','&quot;':'"','&#039;':"'",'&lt;':'<','&gt;':'>'})[entity]);}
for(const tree of trees)test(`${tree}: core media capability cannot expose private files to public authors`,async()=>{
  // Install the trusted fixture before PHP starts; never replace a live front controller while a prior response may still be executing.
  /** Prepare only synthetic private-capability code; receive fixture paths, write its controller before server startup and propagate I/O failures. */
  const sandbox=await pluginServer(tree,context=>{const entry=path.join(context.application,'public_html/cms-admin/index.php');fs.writeFileSync(entry,`<?php if(isset($_GET['private-picker-fixture'])){require __DIR__.'/../../system/bootstrap.php';$c=new \\DBLESSCMS\\Config();\\DBLESSCMS\\Security::start($c);$s=new \\DBLESSCMS\\JsonStore($c);$l=new \\DBLESSCMS\\Language();$l->select('en-us');header('Content-Type: application/json');echo json_encode(\\DBLESSCMS\\MediaLibrary::configuration($s,$l,'','private'));exit;} ?>`+fs.readFileSync(entry,'utf8').replace('declare(strict_types=1);',''));});let cookie='';
  /** Request only the isolated local app; receives path/form, returns HTTP response retaining the synthetic session. */
  async function request(url,form=null){const address=new URL(url,sandbox.base);assert.equal(address.origin,sandbox.base);const response=await fetch(address,{method:form?'POST':'GET',headers:cookie?{Cookie:cookie}:{},body:form,redirect:'manual'});for(const value of response.headers.getSetCookie())cookie=value.split(';')[0];return response;}
  try{
    const endpoint='/cms-admin/?view=media-library';assert.equal((await request(endpoint,new URLSearchParams())).status,403);
    const html=await(await request('/cms-admin/')).text(),csrf=decode(html.match(/name="csrf_token" value="([^"]+)"/)[1]);await request('/cms-admin/',new URLSearchParams({action:'login',username:'editor',password:'Synthetic-DBLESS-CMS-password-2026',csrf_token:csrf}));
    const panel=await(await request('/cms-admin/')).text(),config=JSON.parse(decode(panel.match(/name="dbless-editor" content="([^"]+)"/)[1])).media;assert.equal(config.visibility,'public');
    const publicRoot=path.join(sandbox.application,'public_html/media'),privateRoot=path.join(sandbox.temporary,'storage/files/media');fs.mkdirSync(publicRoot,{recursive:true});fs.mkdirSync(privateRoot,{recursive:true});const bytes=fs.readFileSync(path.join(sandbox.application,'public_html/templates/essencial/essencial.png'));
    for(let n=0;n<28;n++)fs.writeFileSync(path.join(publicRoot,`picture-${n}.png`),bytes,{flag:'wx'});fs.writeFileSync(path.join(privateRoot,'secret.png'),bytes,{flag:'wx'});fs.symlinkSync(privateRoot,path.join(publicRoot,'private-link'));
    const query={csrf_token:config.csrf,token:config.token,scope:'public',path:'',page:'1',type:'image',search:'picture-',sort:'name',direction:'asc'};
    let response=await request(endpoint,new URLSearchParams(query));assert.equal(response.status,200);let data=await response.json();assert.equal(data.total,28);assert.equal(data.items.length,25);assert.equal(data.pages,2);assert.ok(data.items.every(item=>item.reference.scope==='public'&&!item.url.includes('cms-admin')));
    data=await(await request(endpoint,new URLSearchParams({...query,page:'2'}))).json();assert.equal(data.items.length,3);
    for(const override of [{scope:'private'},{scope:'private',token:config.token.replace('public.','private.')}])assert.equal((await request(endpoint,new URLSearchParams({...query,...override}))).status,403);
    for(const invalid of ['../media','private-link'])assert.equal((await request(endpoint,new URLSearchParams({...query,path:invalid}))).status,422);
    assert.equal((await request(endpoint,new URLSearchParams({...query,csrf_token:'wrong'}))).status,403);assert.equal((await request(endpoint+'&operation=upload',new URLSearchParams(query))).status,405);
    // Trusted synthetic private consumer opts into the core capability. No public route in the product can mint this context.
    const privateResponse=await request('/cms-admin/?private-picker-fixture=1');assert.equal(privateResponse.status,200,sandbox.logs());const privateConfig=await privateResponse.json();response=await request(endpoint,new URLSearchParams({...query,token:privateConfig.token,scope:'private',search:''}));assert.equal(response.status,200);data=await response.json();assert.equal(data.items.length,1);assert.equal(data.items[0].value,null);assert.equal(data.items[0].reference.scope,'private');assert.equal((await request(data.items[0].url)).status,200);
    const siteFile=path.join(sandbox.temporary,'contents/site.json'),site=JSON.parse(fs.readFileSync(siteFile));site.settings.media={public:'new-media',private:'media'};fs.writeFileSync(siteFile,JSON.stringify(site));assert.equal((await request(endpoint,new URLSearchParams(query))).status,403,'Root changes invalidate old capabilities.');
    const fresh=await(await request('/cms-admin/?private-picker-fixture=1')).json();response=await request(endpoint,new URLSearchParams({...query,token:fresh.token}));assert.equal(response.status,200);assert.deepEqual((await response.json()).items,[]);assert.equal(fs.existsSync(path.join(sandbox.application,'public_html/new-media')),false,'Read-only selection does not create roots.');
    assert.doesNotMatch(sandbox.logs(),/PHP (?:Warning|Fatal|Parse)/);
  }finally{await sandbox.close();}
});
