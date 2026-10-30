/** Public source/export regressions with synthetic state: examples, contracts, authentication, addressed assets and the optional Hello plugin. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import {fileURLToPath} from 'node:url';
import {spawnSync} from 'node:child_process';
import {createHash} from 'node:crypto';
const root=fileURLToPath(new URL('../',import.meta.url));
const trees=[['source',path.join(root,'src')],...(process.env.DBLESS_CMS_PACKAGE_ROOT?[['production',process.env.DBLESS_CMS_PACKAGE_ROOT]]:[])];
/** Create a private sandbox from versioned examples; receives application root and returns paths/env, never reads existing runtime content or credentials. */
function fixture(application) {
  const temporary=fs.mkdtempSync(path.join(os.tmpdir(),'dbless-test-')),copy=path.join(temporary,'app');
  fs.cpSync(application,copy,{recursive:true,filter:file=>!file.startsWith(path.join(application,'system/storage'))});
  const seeds=fs.existsSync(path.join(application,'system/resources/contents'))?path.join(application,'system/resources/contents'):path.join(application,'system/storage/contents');
  fs.cpSync(seeds,path.join(temporary,'contents'),{recursive:true});
  const config=path.join(temporary,'app.json'),hash=spawnSync('php',['-r',"echo password_hash('Synthetic-Dbless-Password-2026',PASSWORD_ARGON2ID,['memory_cost'=>8192,'time_cost'=>1]);"],{encoding:'utf8'});assert.equal(hash.status,0,hash.stderr);
  fs.writeFileSync(config,JSON.stringify({schema_version:1,environment:'production',editor:{enabled:true,username:'editor',password_hash:hash.stdout},contact_enabled:false}));
  const env={...process.env,DBLESS_CMS_IGNORE_DOTENV:'1',DBLESS_CMS_APP_CONFIG:config,DBLESS_CMS_CONTENTS_ROOT:path.join(temporary,'contents'),DBLESS_CMS_STORAGE_ROOT:path.join(temporary,'storage')};
  return {temporary,application:copy,env};
}
/** Invoke isolated PHP with warnings visible and mail blocked; receives sandbox/code, returns stdout or assertion failure, and never invokes a shell. */
function php(context,code){const result=spawnSync('php',['-d','disable_functions=mail','-d','display_errors=stderr','-r',code,context.application],{env:context.env,encoding:'utf8',timeout:10000});assert.equal(result.status,0,result.stderr);assert.equal(result.stderr,'');return result.stdout;}
/** Render a synthetic CLI request; receives sandbox/request, returns response/session, keeps sessions inside the sandbox and treats warnings as failures. */
function request(context,input={}){const result=spawnSync('php',['-d','disable_functions=mail','-d','display_errors=stderr','-d',`session.save_path=${context.temporary}`,path.join(root,'tests/helpers/request.php'),context.application],{input:JSON.stringify(input),env:context.env,encoding:'utf8',timeout:10000});assert.equal(result.status,0,result.stderr);assert.equal(result.stderr,'');return JSON.parse(result.stdout);}
/** Walk structured data and collect local media references; receives value/list, returns the list without mutation of input data. */
function media(value,items=[]){if(Array.isArray(value))value.forEach(item=>media(item,items));else if(value&&typeof value==='object')Object.values(value).forEach(item=>media(item,items));else if(typeof value==='string'&&value.startsWith('templates/'))items.push(value);return items;}
for(const [tree,application] of trees){
  test(`${tree}: Essencial examples render every page and all media without a second template`,()=>{
    const context=fixture(application);
    try {
      assert.deepEqual(fs.readdirSync(path.join(context.application,'system/templates')),['essencial']);
      const site=JSON.parse(fs.readFileSync(path.join(context.temporary,'contents/site.json')));assert.equal(site.template,'essencial');
      for(const file of fs.readdirSync(path.join(context.temporary,'contents/pages'))){const data=JSON.parse(fs.readFileSync(path.join(context.temporary,'contents/pages',file)));for(const reference of media(data))assert.ok(fs.existsSync(path.join(context.application,'public_html',reference)),reference);const response=request(context,{query:{page:data.slug}});assert.equal(response.status,data.slug==='404'?404:200,response.body);assert.doesNotMatch(response.body,/jepasaDotCom|<svg\b|cdn\.jsdelivr|fonts\.googleapis/);}
      const output=php(context,"require $argv[1].'/system/bootstrap.php';$s=new DBLESSCMS\\JsonStore(new DBLESSCMS\\Config());$p=new DBLESSCMS\\Plugins($s);foreach((new DBLESSCMS\\MenuStore($s))->read()['data']['assignments'] as $b)DBLESSCMS\\Navigation::binding($s,(new DBLESSCMS\\MenuStore($s))->read()['data'],$b);$slides=(new DBLESSCMS\\SlidesStore($s))->read()['data'];foreach($slides['assignments'] as $b)DBLESSCMS\\Slides::binding($s,$slides,$b);echo json_encode([array_keys($s->compatibleTemplates()),array_keys($p->all()),$p->invalid()]);");
      const [templates,plugins,invalid]=JSON.parse(output);assert.deepEqual(templates,['essencial']);assert.deepEqual(plugins,['blog','files-manager','menu','pages','slides']);assert.deepEqual(invalid,[]);
      for(const file of ['site.json','slides/slides.json'])for(const reference of media(JSON.parse(fs.readFileSync(path.join(context.temporary,'contents',file)))))assert.ok(fs.existsSync(path.join(context.application,'public_html',reference)),reference);
    }finally{fs.rmSync(context.temporary,{recursive:true,force:true});}
  });
  test(`${tree}: admin uses dbless-cms identity and rejects forged CSRF before login`,()=>{
    const context=fixture(application);
    try{const login=request(context,{admin:true});assert.equal(login.status,200);assert.match(login.body,/<title>[^<]*dbless-cms<\/title>/);const denied=request(context,{admin:true,method:'POST',session:login.session,post:{action:'login',csrf_token:'forged',username:'editor',password:'Synthetic-Dbless-Password-2026'}});assert.equal(denied.status,403);const token=login.body.match(/name="csrf_token"[^>]*value="([^"]+)"/)[1];const signed=request(context,{admin:true,method:'POST',session:login.session,post:{action:'login',csrf_token:token,username:'editor',password:'Synthetic-Dbless-Password-2026'}});assert.equal(signed.status,303);const panel=request(context,{admin:true,session:signed.session});assert.equal(panel.status,200);assert.match(panel.body,/dbless-cms<small>/);assert.doesNotMatch(panel.body,new RegExp('>'+['CMS','2JSON'].join('')+'<'));}finally{fs.rmSync(context.temporary,{recursive:true,force:true});}
  });
}
test('distribution identity, notices, documentation and image hashes are coherent',()=>{
  const pkg=JSON.parse(fs.readFileSync(path.join(root,'package.json')));assert.equal(pkg.name,'dbless-cms');assert.equal(pkg.repository.url,'https://github.com/jepasa/dbless-cms.git');assert.equal(pkg.license,'Apache-2.0');
  assert.match(fs.readFileSync(path.join(root,'LICENSE'),'utf8'),/Apache License/);assert.match(fs.readFileSync(path.join(root,'NOTICE'),'utf8'),/JEPASA/);assert.deepEqual(fs.readdirSync(path.join(root,'docs')).sort(),['images','plugins.md','templates.md']);
  for(const file of ['README.md','LEIAME.md','docs/templates.md','docs/plugins.md','SECURITY.md']){const content=fs.readFileSync(path.join(root,file),'utf8');for(const match of content.matchAll(/\]\(([^)#]+)(?:#[^)]*)?\)/g)){if(/^(?:https?:|mailto:)/.test(match[1]))continue;assert.ok(fs.existsSync(path.resolve(path.dirname(path.join(root,file)),match[1])),file+': '+match[1]);}}
  const directory=path.join(root,'src/public_html/templates/essencial/images/examples'),provenance=JSON.parse(fs.readFileSync(path.join(directory,'provenance.json')));assert.equal(provenance.license,'Apache-2.0');assert.equal(provenance.assets.length,3);for(const asset of provenance.assets){assert.equal(createHash('sha256').update(fs.readFileSync(path.join(directory,asset.file))).digest('hex'),asset.sha256);assert.ok(asset.prompt.length>100);}
  for(const unwanted of ['specs','.specify','doc','AGENTS.md','src/system/templates/jepasaDotCom','src/public_html/templates/jepasaDotCom','src/system/config/app.json'])assert.ok(!fs.existsSync(path.join(root,unwanted)),unwanted);
});
test('Hello developer example installs, separates routes and exposes only its public message',()=>{
  const context=fixture(path.join(root,'src'));
  try{
    for(const surface of ['system','public_html'])fs.cpSync(path.join(root,'examples/hello',surface,'plugins/hello'),path.join(context.application,surface,'plugins/hello'),{recursive:true});
    const file=path.join(context.temporary,'contents/plugins.json'),data=JSON.parse(fs.readFileSync(file));data.plugins.hello={enabled:true,settings:{message:'Hello example'}};fs.writeFileSync(file,JSON.stringify(data));
    const output=JSON.parse(php(context,"require $argv[1].'/system/bootstrap.php';$s=new DBLESSCMS\\JsonStore(new DBLESSCMS\\Config());$p=new DBLESSCMS\\Plugins($s);$c=$p->publicContext('hello','https://example.test','en-us','welcome','GET','token');$out=[$p->invalid(),array_keys(get_object_vars($c)),$p->instance('hello')->publicPage($c)];try{$p->route('hello','overview','public');$out[]=200;}catch(DBLESSCMS\\HttpError $e){$out[]=$e->status;}echo json_encode($out);"));
    assert.deepEqual(output[0],[]);assert.ok(!output[1].includes('config')&&!output[1].includes('store'));assert.match(output[2],/Hello example/);assert.equal(output[3],404);
  }finally{fs.rmSync(context.temporary,{recursive:true,force:true});}
});
