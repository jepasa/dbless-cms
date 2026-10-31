/** Portable source/export regressions for private customization publication and shared atomic authoring; fixtures never use real state. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {customizations} from '../scripts/lib/customizations.mjs';
import {createHash} from 'node:crypto';
import {applications,fixture,inspect,server,password} from './helpers/extension-media.mjs';
/** Encode synthetic JSON as inert PHP input; takes value, returns a fixed decoding expression without shell interpolation. */
function php(value){return "json_decode(base64_decode('"+Buffer.from(JSON.stringify(value)).toString('base64')+"'),true,32,JSON_THROW_ON_ERROR)";}
const source={css:{enabled:true,code:'/* synthetic CSS */\nbody { --custom-test: 26; }'},js:{enabled:true,code:'/* synthetic JS */\ndocument.body.dataset.customTest = "26";'}};
const setup="$c=new DBLESSCMS\\SiteCustomizations($s);$r=$c->read();$site=$s->read('site')['revision'];";
for(const application of applications){
  test(`${application}: drafts remain private, publication creates verified hashed artifacts and restore does not silently publish`,()=>{
    const f=fixture(application);try{
      const result=inspect(f,setup+"$source="+php(source)+";$a=$c->change('save',$source,$r['revision'],$site);$private=!is_dir(dirname($s->config->root).'/public_html/customizations');$b=$c->change('publish',$source,$a['revision'],$site);$valid=[];foreach(['css','js'] as $kind)$valid[]=$c->artifact($kind,$b['data']['published']['files'][$kind])===$source[$kind]['code'];$latest=$c->history()[0];$d=$c->change('save',DBLESSCMS\\SiteCustomizations::emptySource(),$b['revision'],$site);$restored=$c->change('restore',[],$d['revision'],$site,$latest);echo json_encode([$private,$valid,$restored['data']['draft']===$source,$restored['data']['published']===$b['data']['published'],count($c->history())]);");assert.deepEqual(result,[true,[true,true],true,true,4]);
    }finally{f.close();}
  });
  test(`${application}: stale revisions, site changes and malformed input cannot overwrite a draft or publish artifacts`,()=>{
    const f=fixture(application);try{
      const result=inspect(f,setup+"$source="+php(source)+";$r=$c->change('save',$source,$r['revision'],$site);$cases=[['save',$source,str_repeat('0',64),$site],['publish',$source,$r['revision'],str_repeat('0',64)]];$bad=$source;$bad['css']['code']='body {';$cases[]=['publish',$bad,$r['revision'],$site];$bad=$source;$bad['js']['code']='<?php echo 1;';$cases[]=['save',$bad,$r['revision'],$site];$bad=$source;$bad['css']['enabled']='yes';$cases[]=['save',$bad,$r['revision'],$site];$codes=[];foreach($cases as $case)try{$c->change(...$case);$codes[]=200;}catch(DBLESSCMS\\HttpError $e){$codes[]=$e->status;}echo json_encode([$codes,$c->read()['revision']===$r['revision'],!is_dir(dirname($s->config->root).'/public_html/customizations')]);");assert.deepEqual(result,[[409,409,422,422,422],true,true]);
    }finally{f.close();}
  });
  test(`${application}: public decoration is opt-in, complete-document-only, addressed, deferred, template-aware and absent from private previews`,()=>{
    const f=fixture(application);try{
      const result=inspect(f,setup+"$html='<html><head><link rel=\"stylesheet\" href=\"/template.css\"></head><body>ação</body></html>';$unchanged=$c->decorate($html,'/sub')===$html;$r=$c->change('publish',"+php(source)+",$r['revision'],$site);$output=$c->decorate($html,'/sub');$template=$s->read('site')['data']['template'];$dom=new DOMDocument();@$dom->loadHTML($output);$xpath=new DOMXPath($dom);$assets=$xpath->query('//*[@data-site-customization]');$public=DBLESSCMS\\App::render($s,'home',false,null,'/sub');$preview=DBLESSCMS\\App::render($s,'home',true,null,'/sub');echo json_encode([$unchanged,$assets->length,$dom->getElementsByTagName('link')->item(1)->getAttribute('href'),$dom->getElementsByTagName('script')->item(0)->hasAttribute('defer'),$dom->getElementsByTagName('body')->item(0)->getAttribute('data-template')===$template,str_contains(html_entity_decode($output,ENT_QUOTES|ENT_HTML5,'UTF-8'),'ação'),$c->decorate('<p>fragment</p>','')==='<p>fragment</p>'&&$c->decorate('<!-- <html><head></head><body></body></html> --><p>fragment</p>','')==='<!-- <html><head></head><body></body></html> --><p>fragment</p>',$c->decorate($output,'/sub')===$output,str_contains($public,'data-site-customization'),!str_contains($preview,'data-site-customization')]);");assert.equal(result[0],true);assert.equal(result[1],2);assert.match(result[2],/^\/sub\/customizations\/site-[a-f0-9]{64}\.css$/);assert.deepEqual(result.slice(3),Array(7).fill(true));
    }finally{f.close();}
  });
  test(`${application}: storage/public/history symlinks and artifact tampering are rejected; optional corrupt publication retains native HTML`,()=>{
    const f=fixture(application);try{
      inspect(f,setup+"$c->change('publish',"+php(source)+",$r['revision'],$site);echo 'true';");
      const dir=path.join(f.temporary,'storage/customizations'),pub=path.join(f.app,'public_html/customizations'),catalog=JSON.parse(fs.readFileSync(path.join(dir,'catalog.json'))),file=path.join(pub,'site-'+catalog.published.files.css.sha256+'.css');fs.writeFileSync(file,'tampered');
      assert.deepEqual(inspect(f,"ini_set('error_log',dirname($argv[1]).'/custom-errors.log');"+setup+"try{$c->artifact('css',$r['data']['published']['files']['css']);$status=200;}catch(DBLESSCMS\\HttpError $e){$status=$e->status;}$html='<html><head></head><body>native</body></html>';echo json_encode([$status,$c->decorate($html,'')===$html]);"),[503,true]);
      fs.rmSync(dir,{recursive:true});fs.symlinkSync(f.contents,dir);assert.equal(inspect(f,setup.replace("$r=$c->read();",'')+"try{$c->read();echo '200';}catch(DBLESSCMS\\HttpError $e){echo $e->status;}"),503);
    }finally{f.close();}
  });
  test(`${application}: export carries published sources/artifacts, excludes draft/history and private preview does not expose admin token`,()=>{
    const f=fixture(application);try{
      const target=path.join(f.temporary,'export');fs.mkdirSync(target);const result=inspect(f,setup+"$r=$c->change('publish',"+php(source)+",$r['revision'],$site);$r=$c->change('save',DBLESSCMS\\SiteCustomizations::emptySource(),$r['revision'],$site);$target=dirname($argv[1]).'/export';$c->export($target);$_SESSION=['csrf_token'=>'SYNTHETIC-ADMIN-TOKEN','admin'=>'SYNTHETIC-ADMIN-SESSION'];$session=$_SESSION;$html=$c->preview('',$r['revision']);$export=DBLESSCMS\\SiteCustomizations::decode(file_get_contents($target.'/system/storage/customizations/catalog.json'));echo json_encode([$export['draft']===$r['data']['published']['source'],!is_dir($target.'/system/storage/customizations/history'),!str_contains($html,'SYNTHETIC-ADMIN-TOKEN'),$_SESSION===$session]);");assert.deepEqual(result,[true,true,true,true]);
    }finally{f.close();}
  });
  test(`${application}: shared Pages create validates metadata and markup before a single write; native source remains intact`,()=>{
    const f=fixture(application);try{
      const result=inspect(f,"$native=$s->read('starter-page')['data'];$a=['meta'=>$native['meta'],'status'=>'published','markup'=>['html'=>'<section><h2>Author text</h2></section>','bindings'=>[]]];$a['meta']['title']='Atomic authored page';$bad=$a;$bad['markup']['html']='<script>alert(1)</script>';try{$s->create('bad-authored','starter-page',$bad);$code=200;}catch(DBLESSCMS\\HttpError $e){$code=$e->status;}$created=$s->create('authored','starter-page',$a);echo json_encode([$code,!is_file($s->config->data['contents'].'/pages/bad-authored.json'),$created['data']['meta']['title'],$created['data']['content']===$native['content'],$s->read('starter-page')['data']===$native]);");assert.deepEqual(result,[422,true,'Atomic authored page',true,true]);
    }finally{f.close();}
  });
  test(`${application}: browser routes enforce auth/CSRF and public plugin feed excludes published globals`,async()=>{
    const f=await server(application);try{
      inspect(f,setup+"$c->change('publish',"+php(source)+",$r['revision'],$site);echo 'true';");
      const guest=await fetch(f.base+'/cms-admin/?view=customizations-preview&revision=x');assert.doesNotMatch(await guest.text(),/data-site-customization/);
      const login=await fetch(f.base+'/cms-admin/'),cookie=login.headers.get('set-cookie').split(';')[0],html=await login.text(),csrf=html.match(/name="csrf_token" value="([^"]+)"/)[1];
      const auth=await fetch(f.base+'/cms-admin/',{method:'POST',redirect:'manual',headers:{Cookie:cookie},body:new URLSearchParams({action:'login',csrf_token:csrf,username:'editor',password})});assert.equal(auth.status,303);const session=auth.headers.get('set-cookie')?.split(';')[0]||cookie;
      const denied=await fetch(f.base+'/cms-admin/?view=customizations',{method:'POST',headers:{Cookie:session},body:new URLSearchParams({action:'custom-publish'})});assert.equal(denied.status,403);
      const blog=await fetch(f.base+'/plugins/blog/list');assert.equal(blog.status,200);assert.match(await blog.text(),/data-site-customization/);
      const hostile=await fetch(f.base+'/plugins/blog/list?locale='+encodeURIComponent('<script>window.pwned=1</script>'));assert.equal(hostile.status,200);const escaped=await hostile.text();assert.doesNotMatch(escaped,/<script>window\.pwned=1<\/script>/);assert.match(escaped,/data-site-customization/);const rejected=await fetch(f.base+'/plugins/blog/list?q='+encodeURIComponent('<img src=x onerror=alert(1)>'));assert.equal(rejected.status,422);assert.doesNotMatch(await rejected.text(),/<img src=x onerror=alert\(1\)>|data-site-customization/);
      const feed=await fetch(f.base+'/plugins/blog/feed');assert.match(feed.headers.get('content-type'),/xml/);assert.doesNotMatch(await feed.text(),/data-site-customization/);
    }finally{await f.close();}
  });
  test(`${application}: backup and restore preserve source/history and immutable public assets`,()=>{
    const f=fixture(application);try{
      const result=inspect(f,setup+"$r=$c->change('publish',"+php(source)+",$r['revision'],$site);$r=$c->change('save',DBLESSCMS\\SiteCustomizations::emptySource(),$r['revision'],$site);$root=dirname($argv[1]);$ops=new DBLESSCMS\\Operations($s->config);$ops->backup($root.'/snapshot');$ops->restore($root.'/snapshot',$root.'/restored');$restored=$root.'/restored';$catalog=$restored.'/system/storage/customizations/catalog.json';$history=glob($restored.'/system/storage/customizations/history/*.json');$file=$r['data']['published']['files']['css'];echo json_encode([file_get_contents($catalog)===file_get_contents($s->config->data['storage'].'/customizations/catalog.json'),count($history)===2,file_get_contents($restored.'/public_html/customizations/'.DBLESSCMS\\SiteCustomizations::filename('css',$file))===$c->artifact('css',$file)]);");assert.deepEqual(result,[true,true,true]);
    }finally{f.close();}
  });
  test(`${application}: export compiler minifies published bytes, recomputes URLs and aborts on forged invalid JavaScript`,async()=>{
    const f=fixture(application);try{
      const target=path.join(f.temporary,'compiled');fs.mkdirSync(target);
      inspect(f,setup+"$r=$c->change('publish',"+php(source)+",$r['revision'],$site);$r=$c->change('save',DBLESSCMS\\SiteCustomizations::emptySource(),$r['revision'],$site);$c->export(dirname($argv[1]).'/compiled');echo 'true';");
      await customizations(target);const state=JSON.parse(fs.readFileSync(path.join(target,'system/storage/customizations/catalog.json')));
      assert.deepEqual(state.draft,source);for(const kind of ['css','js']){const descriptor=state.published.files[kind],bytes=fs.readFileSync(path.join(target,'public_html/customizations','site-'+descriptor.sha256+'.'+kind));assert.equal(createHash('sha256').update(bytes).digest('hex'),descriptor.sha256);assert.equal(bytes.length,descriptor.bytes);assert.ok(bytes.length<Buffer.byteLength(source[kind].code));}
      assert.equal(fs.readdirSync(path.join(target,'public_html/customizations')).length,2);assert.equal(fs.existsSync(path.join(target,'system/storage/customizations/history')),false);
      const secret=path.join(f.temporary,'private.css');fs.writeFileSync(secret,'body { --private-marker: DO-NOT-INLINE-PRIVATE-FILE; }');const importing=structuredClone(source);importing.css.code='@import "'+secret+'"; body { color: red; }';const importTarget=path.join(f.temporary,'imports');fs.mkdirSync(importTarget);
      inspect(f,setup+"$c->change('publish',"+php(importing)+",$r['revision'],$site);$c->export(dirname($argv[1]).'/imports');echo 'true';");await customizations(importTarget);for(const file of fs.readdirSync(path.join(importTarget,'public_html/customizations')))assert.doesNotMatch(fs.readFileSync(path.join(importTarget,'public_html/customizations',file),'utf8'),/DO-NOT-INLINE-PRIVATE-FILE/);
      const bad=structuredClone(source);bad.js.code='const invalid = ;';const denied=path.join(f.temporary,'invalid');fs.mkdirSync(denied);
      inspect(f,setup+"$c->change('publish',"+php(bad)+",$r['revision'],$site);$c->export(dirname($argv[1]).'/invalid');echo 'true';");await assert.rejects(customizations(denied),/Unexpected token/);
    }finally{f.close();}
  });
  test(`${application}: publication survives a template switch without rewriting original template assets`,()=>{
    const f=fixture(application);try{
      for(const kind of ['system','public_html'])fs.cpSync(path.join(f.app,kind,'templates/essencial'),path.join(f.app,kind,'templates/synthetic-theme'),{recursive:true});
      const file=path.join(f.app,'system/templates/synthetic-theme/template.json'),manifest=JSON.parse(fs.readFileSync(file));manifest.id='synthetic-theme';fs.writeFileSync(file,JSON.stringify(manifest));
      const style=path.join(f.app,'public_html/templates/essencial/css',fs.existsSync(path.join(f.app,'public_html/templates/essencial/css/style.css'))?'style.css':'style.min.css'),original=fs.readFileSync(style);
      const result=inspect(f,setup+"$r=$c->change('publish',"+php(source)+",$r['revision'],$site);$site=$s->read('site');(new DBLESSCMS\\Extensions($s))->toggle('templates','synthetic-theme',true,$site['revision']);$html=DBLESSCMS\\App::render($s,'home',false,null,'');echo json_encode([str_contains($html,'data-template=\"synthetic-theme\"'),str_contains($html,'data-site-customization'),$c->read()['data']['published']===$r['data']['published']]);");assert.deepEqual(result,[true,true,true]);assert.deepEqual(fs.readFileSync(style),original);
    }finally{f.close();}
  });

  test(`${application}: public/history/catalog links cannot redirect writes outside owned storage`,()=>{
    for(const kind of ['public','history','catalog']){
      const f=fixture(application);try{
        const target=path.join(f.temporary,'untouched');fs.mkdirSync(target);fs.writeFileSync(path.join(target,'keep.txt'),'UNCHANGED');const privateRoot=path.join(f.temporary,'storage/customizations');fs.mkdirSync(privateRoot,{recursive:true});
        if(kind==='public')fs.symlinkSync(target,path.join(f.app,'public_html/customizations'));
        if(kind==='history')fs.symlinkSync(target,path.join(privateRoot,'history'));
        if(kind==='catalog')fs.symlinkSync(path.join(target,'keep.txt'),path.join(privateRoot,'catalog.json'));
        const result=inspect(f,"$c=new DBLESSCMS\\SiteCustomizations($s);try{$r=$c->read();$c->change('publish',"+php(source)+",$r['revision'],$s->read('site')['revision']);echo '200';}catch(DBLESSCMS\\HttpError $e){echo $e->status;}");assert.equal(result,503);assert.deepEqual(fs.readdirSync(target),['keep.txt']);assert.equal(fs.readFileSync(path.join(target,'keep.txt'),'utf8'),'UNCHANGED');
      }finally{f.close();}
    }
  });

}
