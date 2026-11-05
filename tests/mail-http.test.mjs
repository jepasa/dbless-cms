/** Private global mail card HTTP regression: authentication/CSRF/capabilities/CAS and redaction, with synthetic accounts and captured mail only. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {applications} from './helpers/extension-media.mjs';
import {usersServer,client,field,password,inspect,prelude} from './helpers/users.mjs';
import {smtpInput} from './helpers/smtp.mjs';

/** Authenticate only a synthetic fixture account; preserve manual redirects so a partial MFA session remains observable. */
async function login(request,username='editor',value=password){const html=await(await request('/cms-admin/?view=login')).text(),csrf=field(html,'csrf_token'),response=await request('/cms-admin/',{method:'POST',body:new URLSearchParams({action:'login',csrf_token:csrf,username,password:value})});await response.arrayBuffer();assert.equal(response.status,303);return csrf;}
/** Build the actual private card payload with a bounded fixture-only destination; no network verification occurs during settings save. */
function payload(revision,csrf,input=smtpInput({port:587,security:'starttls'})){return {action:'mail-save',csrf_token:csrf,mail_revision:revision,...Object.fromEntries(Object.entries(input).map(([key,value])=>['mail_'+key,value]))};}

for(const application of applications){
 test(`${path.basename(application)}: the global mail card saves privately with CSRF/CAS, preserves/clears its secret and never enters site JSON`,async()=>{
  const c=await usersServer(application);try{
   const request=client(c),file=path.join(c.env.DBLESS_CMS_STORAGE_ROOT,'mail/settings.json'),siteFile=path.join(c.contents,'site.json'),siteBefore=fs.readFileSync(siteFile);let html=await(await request('/cms-admin/?view=login')).text();
   let response=await request('/cms-admin/?view=site',{method:'POST',body:new URLSearchParams(payload('invalid',field(html,'csrf_token')))});assert.equal(response.status,403);await response.arrayBuffer();assert.equal(fs.existsSync(file),false);
   await login(request);html=await(await request('/cms-admin/?view=site')).text();assert.match(html,/data-mail-settings/);assert.match(html,/<h2>Email delivery<\/h2>/);assert.match(html,/name="mail_password" value=""/);const revision=field(html,'mail_revision'),csrf=field(html,'csrf_token');
   response=await request('/cms-admin/?view=site',{method:'POST',body:new URLSearchParams(payload(revision,'forged'))});assert.equal(response.status,403);await response.arrayBuffer();assert.equal(fs.existsSync(file),false);
   response=await request('/cms-admin/?view=site',{method:'POST',body:new URLSearchParams(payload(revision,csrf))});assert.equal(response.status,303);await response.arrayBuffer();assert.match(response.headers.get('location'),/view=site/);const saved=fs.readFileSync(file),data=JSON.parse(saved);assert.equal(data.method,'smtp');assert.ok(data.smtp.password);assert.ok(!saved.includes('Synthetic-SMTP-Secret-2026'));assert.deepEqual(fs.readFileSync(siteFile),siteBefore);assert.equal(fs.existsSync(path.join(c.env.DBLESS_CMS_STORAGE_ROOT,'keys/users.key')),false);
   html=await(await request('/cms-admin/?view=site')).text();assert.ok(!html.includes('Synthetic-SMTP-Secret-2026'));assert.ok(!html.includes(data.smtp.password));assert.match(html,/name="mail_password" value=""/);assert.match(html,/An SMTP password is stored/);
   response=await request('/cms-admin/?view=site',{method:'POST',body:new URLSearchParams(payload(revision,csrf))});assert.equal(response.status,409);await response.arrayBuffer();assert.deepEqual(fs.readFileSync(file),saved);
   const unchanged=smtpInput({port:587,security:'starttls'},{method:'mail',password:''});response=await request('/cms-admin/?view=site',{method:'POST',body:new URLSearchParams(payload(field(html,'mail_revision'),csrf,unchanged))});assert.equal(response.status,303);await response.arrayBuffer();assert.equal(JSON.parse(fs.readFileSync(file)).smtp.password,data.smtp.password);
   html=await(await request('/cms-admin/?view=site')).text();response=await request('/cms-admin/?view=site',{method:'POST',body:new URLSearchParams(payload(field(html,'mail_revision'),csrf,{...unchanged,clear_password:'1'}))});assert.equal(response.status,303);await response.arrayBuffer();assert.equal(JSON.parse(fs.readFileSync(file)).smtp.password,'');assert.deepEqual(fs.readFileSync(siteFile),siteBefore);
   assert.equal(fs.existsSync(path.join(c.temporary,'synthetic-mail.txt')),false);assert.doesNotMatch(c.logs(),/PHP (?:Warning|Fatal|Parse)/);
  }finally{await c.close();}
 });

 test(`${path.basename(application)}: partial and editor sessions cannot configure mail, and broken private mail never enumerates reset recipients`,async()=>{
  const c=await usersServer(application);try{
   const info=inspect(c,prelude+`$result=$initialize();$id=$u->saveUser(['id'=>'','username'=>'writer','name'=>'Synthetic Writer','email'=>'writer@example.invalid','group'=>'editor','status'=>'pending'],$u->snapshot()['revision'],$result['user']['id']);$reset=$u->requestReset('writer@example.invalid');$u->reset($reset['token'],'Account-password-synthetic-2026');echo json_encode(['recovery'=>$result['recovery'][0]]);`);
   const owner=client(c),csrf=await login(owner);let response=await owner('/cms-admin/?view=site',{method:'POST',body:new URLSearchParams(payload('invalid',csrf))});assert.equal(response.status,403);await response.arrayBuffer();const file=path.join(c.env.DBLESS_CMS_STORAGE_ROOT,'mail/settings.json');assert.equal(fs.existsSync(file),false);
   const factor=await(await owner('/cms-admin/?view=twofactor')).text();response=await owner('/cms-admin/?view=twofactor',{method:'POST',body:new URLSearchParams({action:'users-factor',csrf_token:field(factor,'csrf_token'),code:info.recovery})});assert.equal(response.status,303);await response.arrayBuffer();assert.match(await(await owner('/cms-admin/?view=site')).text(),/data-mail-settings/);
   const editor=client(c),editorCsrf=await login(editor,'writer','Account-password-synthetic-2026');response=await editor('/cms-admin/?view=site');assert.equal(response.status,403);await response.arrayBuffer();response=await editor('/cms-admin/?view=site',{method:'POST',body:new URLSearchParams(payload('invalid',editorCsrf))});assert.equal(response.status,403);await response.arrayBuffer();assert.equal(fs.existsSync(file),false);
   // A private storage failure must remain indistinguishable from an unknown recovery recipient.
   fs.mkdirSync(path.dirname(file),{recursive:true});fs.writeFileSync(file,'{"broken":true}');const guest=client(c),forgot='/cms-admin/?view=forgot',html=await(await guest(forgot)).text(),token=field(html,'csrf_token'),replies=[];
   for(const email of ['owner@example.invalid','absent@example.invalid']){response=await guest(forgot,{method:'POST',body:new URLSearchParams({action:'users-forgot',csrf_token:token,email})});assert.equal(response.status,303);await response.arrayBuffer();replies.push(await(await guest(forgot)).text());}
   const notice=html=>html.match(/<div class="notice success"[^>]*>([^<]+)<\/div>/)?.[1];assert.ok(notice(replies[0]));assert.equal(notice(replies[0]),notice(replies[1]));assert.doesNotMatch(replies.join(''),/Private mail|broken|owner@example.invalid|absent@example.invalid/);assert.equal(fs.existsSync(path.join(c.temporary,'synthetic-mail.txt')),false);assert.doesNotMatch(c.logs(),/PHP (?:Warning|Fatal|Parse)/);
  }finally{await c.close();}
 });
}
