/** Images history/removal regressions: locale parity, private read-only audit descriptions, retained snapshots and unavailable rights routes/operations. Synthetic data only. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {root,trees} from './helpers/runtime.mjs';
import {pluginServer} from './helpers/plugins.mjs';
/** Extract one escaped form field; receives HTML/name, returns text or fails for unavailable forms. */
function field(html,name){const value=html.match(new RegExp(`name="${name}" value="([^"]*)"`))?.[1];assert.notEqual(value,undefined);return value.replaceAll('&quot;','"').replaceAll('&amp;','&');}
/** Replace an existing synthetic regular audit through a no-follow descriptor; receives path/text, writes only the opened file, propagates I/O errors and always closes it. */
function replaceAudit(file,text){const descriptor=fs.openSync(file,fs.constants.O_WRONLY|fs.constants.O_NOFOLLOW);try{assert.ok(fs.fstatSync(descriptor).isFile());fs.ftruncateSync(descriptor,0);fs.writeFileSync(descriptor,text);}finally{fs.closeSync(descriptor);}}
test('Images dictionaries remove retired controls and keep matching keys/placeholders',()=>{
  const catalogs=['pt-br','en-us'].map(locale=>JSON.parse(fs.readFileSync(path.join(root,'src/system/plugins/files-manager/languages',locale+'.json'))).messages);
  assert.deepEqual(Object.keys(catalogs[0]).sort(),Object.keys(catalogs[1]).sort());
  for(const key of Object.keys(catalogs[0]))assert.deepEqual((catalogs[0][key].match(/\{[a-z]+\}/g)||[]).sort(),(catalogs[1][key].match(/\{[a-z]+\}/g)||[]).sort(),key);
  for(const catalog of catalogs){for(const key of ['fm.rights','fm.metadata','fm.saveRights','fm.publishCopy','fm.license.owned','fm.review.approved'])assert.equal(catalog[key],undefined,key);for(const action of ['upload','mkdir','rename','move','delete','restore','purge','replace','unknown'])assert.ok(catalog['fm.event.'+action]);assert.ok(catalog['fm.snapshotDescription']);assert.ok(catalog['fm.error.rights'],'Retained internal legacy contract still needs its error translation.');}
});
for(const tree of trees)test(`${tree}: history describes real operations and removed rights endpoints cannot mutate`,async()=>{
  const sandbox=await pluginServer(tree);let cookie='';
  /** Request only the synthetic loopback app; retain its session, return a response without redirects. */
  async function request(query,form=null){const response=await fetch(sandbox.base+'/cms-admin/?'+query,{method:form?'POST':'GET',redirect:'manual',headers:cookie?{Cookie:cookie}:{},body:form});for(const value of response.headers.getSetCookie())cookie=value.split(';')[0];return response;}
  const panel='view=plugin-panel&plugin=files-manager&route=',api='view=plugin-api&plugin=files-manager&route=overview&operation=';
  try{
    const anonymous=await(await request(panel+'history')).text();assert.match(anonymous,/name="password"/);assert.doesNotMatch(anonymous,/data-file-history/);
    const login=await(await request('')).text();await request('',new URLSearchParams({action:'login',username:'editor',password:'Synthetic-DBLESS-CMS-password-2026',csrf_token:field(login,'csrf_token')}));
    const settings=await(await request(panel+'settings')).text(),csrf=field(settings,'csrf_token');
    const overview=await(await request(panel+'overview')).text();assert.doesNotMatch(overview,/route=rights|data-action="metadata"/);
    assert.equal((await request(panel+'rights')).status,404);
    for(const operation of ['rights-save','rights-copy'])assert.equal((await request(api+operation,new URLSearchParams({csrf_token:csrf}))).status,404);
    const audit=path.join(sandbox.temporary,'storage/plugins/files-manager/audit.jsonl');assert.equal(fs.existsSync(audit),false);assert.match(await(await request(panel+'history')).text(),/Nenhum histórico|histórico disponível/i);assert.equal(fs.existsSync(audit),false,'GET must not create audit state.');
    let snapshot=await(await request(api+'list&scope=public&path=')).json();let response=await request(api+'mkdir',new URLSearchParams({csrf_token:csrf,scope:'public',path:'',name:'Synthetic',revision:snapshot.revision}));assert.equal(response.status,200);snapshot=await response.json();response=await request(api+'rename',new URLSearchParams({csrf_token:csrf,scope:'public',path:'',target:'Synthetic',name:'Renamed',revision:snapshot.revision}));assert.equal(response.status,200);
    const before=fs.readFileSync(audit);let html=await(await request(panel+'history')).text();assert.match(html,/Pasta criada\./);assert.match(html,/Arquivo ou pasta renomeado\./);assert.ok(html.indexOf('Arquivo ou pasta renomeado.')<html.indexOf('Pasta criada.'));assert.deepEqual(fs.readFileSync(audit),before);
    response=await request('view=plugin-api&plugin=files-manager&route=settings&operation=settings-save',new URLSearchParams({csrf_token:csrf,revision:field(settings,'revision'),public:'media',private:'media'}));assert.equal(response.status,200);html=await(await request(panel+'history')).text();assert.match(html,/Cópia das configurações preservada/);assert.match(html,/name="backup"/);
    replaceAudit(audit,'\n'.repeat(1048576)+Array.from({length:120},()=>JSON.stringify({at:'2026-11-03T00:00:00+00:00',event:'<script>legacy</script>',scope:'private',target:'PRIVATE-TARGET-CANARY'})).join('\n')+'\n');html=await(await request(panel+'history')).text();assert.equal((html.match(/Operação registrada; descrição indisponível\./g)||[]).length,100);assert.doesNotMatch(html,/PRIVATE-TARGET-CANARY|<script>legacy/);
    replaceAudit(audit,'invalid-json\n');assert.equal((await request(panel+'history')).status,503);
    fs.unlinkSync(audit);fs.symlinkSync(path.join(sandbox.temporary,'outside.json'),audit);assert.equal((await request(panel+'history')).status,422);
    assert.doesNotMatch(sandbox.logs(),/PHP (?:Warning|Fatal|Parse)/);
  }finally{await sandbox.close();}
});
