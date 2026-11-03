/** Portable HTTP regressions for private timestamps, recoverable replacement, settings compatibility and bounded MP4 handling; synthetic data only. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {pluginServer} from './helpers/plugins.mjs';
import {trees} from './helpers/runtime.mjs';

/** Extract an escaped hidden field from a synthetic page; fails when the page is unavailable. */
function field(html,name) { const value=html.match(new RegExp(`name="${name}" value="([^"]*)"`))?.[1];assert.notEqual(value,undefined);return value.replaceAll('&quot;','"').replaceAll('&amp;','&'); }
for(const tree of trees) test(`${tree}: explorer preserves bytes, dates, CAS and legacy settings`,{timeout:30000},async()=>{
  const context=await pluginServer(tree);let cookie='',csrf='';
  /** Request only the temporary local server, keeping its synthetic session. */
  async function request(route,body=null,authenticated=true){const response=await fetch(context.base+route,{method:body?'POST':'GET',redirect:'manual',headers:authenticated&&cookie?{Cookie:cookie}:{},body});if(authenticated)for(const value of response.headers.getSetCookie())cookie=value.split(';')[0];return response;}
  const api=(operation)=>'/cms-admin/?view=plugin-api&plugin=files-manager&operation='+operation;
  /** Read a directory snapshot and require a successful private JSON response. */
  async function list(directory=''){const response=await request(api('list')+'&scope=public&path='+encodeURIComponent(directory));assert.equal(response.status,200);return response.json();}
  /** Apply one revisioned synthetic command; assert exact status and return parsed result. */
  async function mutate(operation,values,status=200){const response=await request(api(operation),new URLSearchParams({csrf_token:csrf,scope:'public',path:'',...values}));const data=await response.json();assert.equal(response.status,status,JSON.stringify(data));return data;}
  /** Submit real multipart file bytes to upload/replace; caller supplies the revision under test. */
  async function file(operation,name,bytes,values,status=200){const body=new FormData();for(const [key,value] of Object.entries({csrf_token:csrf,scope:'public',path:'',...values}))body.append(key,value);body.append('file',new Blob([bytes]),name);const response=await request(api(operation),body);const data=await response.json();assert.equal(response.status,status,JSON.stringify(data));return data;}
  try{
    csrf=field(await(await request('/cms-admin/')).text(),'csrf_token');await request('/cms-admin/',new URLSearchParams({action:'login',username:'editor',password:'Synthetic-DBLESS-CMS-password-2026',csrf_token:csrf}));
    let settings=await(await request('/cms-admin/?view=plugin-panel&plugin=files-manager&route=settings')).text();csrf=field(settings,'csrf_token');
    const save='/cms-admin/?view=plugin-api&plugin=files-manager&route=settings&operation=settings-save';
    let response=await request(save,new URLSearchParams({csrf_token:csrf,revision:field(settings,'revision'),document:JSON.stringify({uploads:{public:'media',private:'media'},browser:{pageSize:50}})}));assert.equal(response.status,200);
    settings=await(await request('/cms-admin/?view=plugin-panel&plugin=files-manager&route=settings')).text();
    response=await request(save,new URLSearchParams({csrf_token:csrf,revision:field(settings,'revision'),public:'media',private:'media'}));assert.equal(response.status,200);assert.equal((await list()).pageSize,50,'Legacy saves retain page size.');
    settings=await(await request('/cms-admin/?view=plugin-panel&plugin=files-manager&route=settings')).text();response=await request(save,new URLSearchParams({csrf_token:csrf,revision:field(settings,'revision'),document:JSON.stringify({uploads:{public:'media',private:'media'},browser:{pageSize:26}})}));assert.equal(response.status,422);
    let snapshot=await list();snapshot=await file('upload','note.txt','Original bytes',{revision:snapshot.revision});const added=snapshot.entries.find(e=>e.name==='note.txt').added;assert.ok(added);
    const oldRevision=snapshot.revision;
    await file('replace','other.txt','bad csrf',{target:'note.txt',revision:oldRevision,csrf_token:'bad'},403);
    await file('replace','other.txt','stale',{target:'note.txt',revision:'bad'},409);
    await file('replace','other.php','<?php echo 1;',{target:'note.txt',revision:oldRevision},422);
    await file('replace','other.txt','<script>alert(1)</script>',{target:'note.txt',revision:oldRevision},422);
    assert.equal(fs.readFileSync(path.join(context.application,'public_html/media/note.txt'),'utf8'),'Original bytes');
    snapshot=await file('replace','other.txt','Replacement bytes',{target:'note.txt',revision:oldRevision});assert.equal(snapshot.entries.find(e=>e.name==='note.txt').added,added);assert.ok(!snapshot.entries.some(e=>e.name==='other.txt'));
    await file('replace','other.txt','Second writer',{target:'note.txt',revision:oldRevision},409);
    let trash=await(await request(api('trash')+'&scope=public')).json();const previous=trash.entries.find(e=>e.name==='note.txt');assert.equal(previous.status,'replaced');assert.equal(previous.added,added);assert.ok(!('dates' in previous));
    assert.equal(fs.readFileSync(path.join(context.temporary,'storage/plugins/files-manager/trash',previous.id,'data'),'utf8'),'Original bytes');
    await mutate('restore',{target:previous.id,revision:trash.revision},409);
    snapshot=await mutate('rename',{target:'note.txt',name:'changed.txt',revision:snapshot.revision});assert.equal(snapshot.entries.find(e=>e.name==='changed.txt').added,added);
    trash=await(await request(api('trash')+'&scope=public')).json();await mutate('restore',{target:previous.id,revision:trash.revision});snapshot=await list();assert.equal(snapshot.entries.find(e=>e.name==='note.txt').added,added);assert.equal(fs.readFileSync(path.join(context.application,'public_html/media/note.txt'),'utf8'),'Original bytes');
    snapshot=await mutate('mkdir',{name:'Folder',revision:snapshot.revision});snapshot=await mutate('move',{target:'changed.txt',destination:'Folder',revision:snapshot.revision});assert.equal((await list('Folder')).entries[0].added,added);
    await mutate('delete',{target:'Folder',revision:snapshot.revision});trash=await(await request(api('trash')+'&scope=public')).json();await mutate('restore',{target:trash.entries.find(e=>e.name==='Folder').id,revision:trash.revision});assert.equal((await list('Folder')).entries[0].added,added);
    snapshot=await list();snapshot=await mutate('mkdir',{name:'123',revision:snapshot.revision});const numericAdded=snapshot.entries.find(e=>e.name==='123').added;snapshot=await mutate('rename',{target:'123',name:'456',revision:snapshot.revision});assert.equal(snapshot.entries.find(e=>e.name==='456').added,numericAdded);await mutate('delete',{target:'456',revision:snapshot.revision});trash=await(await request(api('trash')+'&scope=public')).json();await mutate('restore',{target:trash.entries.find(e=>e.name==='456').id,revision:trash.revision});assert.equal((await list()).entries.find(e=>e.name==='456').added,numericAdded);
    // Legacy/out-of-band data has no invented inclusion date, even when mtime exists.
    fs.writeFileSync(path.join(context.application,'public_html/media/legacy.txt'),'Existing file');snapshot=await list();assert.equal(snapshot.entries.find(e=>e.name==='legacy.txt').added,null);
    const before=fs.readdirSync(path.join(context.temporary,'storage/plugins/files-manager')).sort();await list();assert.deepEqual(fs.readdirSync(path.join(context.temporary,'storage/plugins/files-manager')).sort(),before);
    // A magic string alone is not an MP4 container; bytes are never accepted as executable media.
    await file('upload','bad.mp4',Buffer.from('0000ftypisom0000moov0000mdat'),{revision:snapshot.revision},422);
    assert.equal((await request(api('preview')+'&scope=public&path=note.txt',null,false)).status,403);
    const index=fs.readdirSync(path.join(context.temporary,'storage/plugins/files-manager')).find(name=>name.startsWith('dates-'));
    const indexPath=path.join(context.temporary,'storage/plugins/files-manager',index), backup=fs.readFileSync(indexPath);fs.unlinkSync(indexPath);fs.symlinkSync(path.join(context.temporary,'outside.json'),indexPath);
    assert.equal((await request(api('list')+'&scope=public&path=')).status,422,'Index links are denied.');fs.unlinkSync(indexPath);fs.writeFileSync(indexPath,backup);
    assert.ok(!/PHP (?:Warning|Fatal|Parse)/.test(context.logs()),context.logs());
  }finally{await context.close();}
});
