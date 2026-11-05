/** Consolidated-owner regressions: retired packages are absent, old installations remain readable without migration, and aliases cannot acquire package authority. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {applications,fixture,inspect} from './helpers/extension-media.mjs';

/** Write JSON exclusively inside a disposable fixture; receive path/value, return nothing and propagate filesystem errors. */
function write(file,value){fs.writeFileSync(file,JSON.stringify(value,null,2)+'\n');}

/** Read effective owner options in a disposable context; receive fixture, return defaults/legacy projection without writes, propagating PHP contract errors. */
function options(context){return inspect(context,"echo json_encode((new DBLESSCMS\\Plugins($s))->state('posts')['settings']);");}

for(const application of applications){
  test(`${application}: inherited activation and search configuration preserve an existing legacy search URL`,()=>{
    const c=fixture(application);try{
      const result=inspect(c,`$p=$s->read('plugins')['data'];$o=(new DBLESSCMS\\Plugins($s))->state('posts')['settings'];$p['plugins']['pages']=['enabled'=>false,'settings'=>[]];$p['plugins']['blog']=['enabled'=>true,'settings'=>['publication'=>$o['publication'],'discussion'=>$o['discussion']]];$p['plugins']['posts']=['enabled'=>false,'settings'=>['browser'=>['pageSize'=>25]]];file_put_contents($s->config->data['contents'].'/plugins.json',DBLESSCMS\\JsonStore::encode($p));$page=DBLESSCMS\\PageBlueprints::create('blank','Search legacy','search')['document'];$page['status']='published';$page['markup']=['html'=>'<p>LEGACY-SEARCH-BODY</p>','bindings'=>[]];$file=$s->config->data['contents'].'/pages/search.json';file_put_contents($file,DBLESSCMS\\JsonStore::encode($page));$before=hash_file('sha256',$file);$r=new DBLESSCMS\\Posts($s);ob_start();DBLESSCMS\\PostsView::dispatch($s,'search','http://fixture.test');$initial=ob_get_clean();$fallback=$r->publicSearchPath();$r->change('settings','',['searchPath'=>'find'],$r->read()['revision'],$s->read('site')['revision'],$r->sourceRevision());ob_start();DBLESSCMS\\PostsView::dispatch($s,'search','http://fixture.test');$after=ob_get_clean();echo json_encode([str_contains($initial,'LEGACY-SEARCH-BODY'),$fallback,!isset($r->read()['data']['routeAliases']['search']),str_contains($after,'LEGACY-SEARCH-BODY'),$r->publicSearchPath(),$before===hash_file('sha256',$file)]);`);
      assert.deepEqual(result,[true,'plugins/posts/search',true,true,'find',true]);
    }finally{c.close();}
  });
  test(`${application}: saving or changing routes never releases records from a disabled legacy source`,()=>{
    const c=fixture(application);try{
      const file=path.join(c.contents,'plugins.json'),data=JSON.parse(fs.readFileSync(file)),settings=options(c);data.plugins.blog={enabled:false,settings:{publication:settings.publication,discussion:settings.discussion}};write(file,data);
      const result=inspect(c,`$r=new DBLESSCMS\\Posts($s);$source=$s->config->data['contents'].'/blog/catalog.json';$before=hash_file('sha256',$source);$change=function($action,$id,$input=[])use($r,$s){return $r->change($action,$id,$input,$r->read()['revision'],$s->read('site')['revision'],$r->sourceRevision());};$type=$r->read()['data']['types']['article'];$type['routeMode']='custom';$type['prefix']='quiet';$change('type-save','article',['type'=>$type]);$afterRoutes=count($r->read()['data']['records']);$id=DBLESSCMS\\Posts::identity('blog','designing-with-clarity');$row=$r->record($id);$change('save',$id,['snapshot'=>$row['draft']]);$private=DBLESSCMS\\Posts::effective($r->record($id))===null;$count=$r->search(['type'=>'article'])['total'];$change('publish',$id);echo json_encode([$afterRoutes,$private,$count,$r->search(['type'=>'article'])['total'],$before===hash_file('sha256',$source)]);`);
      assert.deepEqual(result,[0,true,0,1,true]);
      inspect(c,`(new DBLESSCMS\\Extensions($s))->uninstall('plugins','posts',$s->read('plugins')['revision'],'posts');echo 'true';`);
      const afterRemoval=inspect(c,`echo json_encode([count($s->pages())>0,str_contains(DBLESSCMS\\App::render($s,'home',false,null,'http://fixture.test'),'<!doctype html>'),is_file($s->config->data['contents'].'/posts/catalog.json')]);`);assert.deepEqual(afterRemoval,[true,true,true]);
    }finally{c.close();}
  });
  test(`${application}: adopting a native schedule preserves its release gate independently from the editorial timestamp`,()=>{
    const c=fixture(application);try{
      const result=inspect(c,`$native=new DBLESSCMS\\PagePublications($s);$created=$native->change('','create',['snapshot'=>DBLESSCMS\\PageBlueprints::create('blank','Schedule fixture','native-release-fixture'),'parent'=>''],$native->read()['revision'],$s->read('site')['revision']);$key=$created['id'];$native->change($key,'publish',['publishAt'=>'2020-01-01T00:00:00Z'],$native->read()['revision'],$s->read('site')['revision']);$data=$native->read(true)['data'];$row=&$data['records'][$key];$row['scheduled']=$row['published'];$row['scheduled']['document']['markup']=['html'=>'<p>NATIVE-FUTURE-CANARY</p>','bindings'=>[]];$row['publishAt']='2099-01-01T00:00:00Z';$file=$s->config->data['contents'].'/page-publications/catalog.json';file_put_contents($file,DBLESSCMS\\JsonStore::encode($data));$before=hash_file('sha256',$file);$r=new DBLESSCMS\\Posts($s);$id=DBLESSCMS\\Posts::identity('pages',$key);$projected=$r->record($id);$r->change('save',$id,['snapshot'=>$projected['draft']],$r->read()['revision'],$s->read('site')['revision'],$r->sourceRevision());$owned=$r->record($id);$target=$s->config->data['storage'].'/schedule-export';$r->export($target);$export=json_decode(file_get_contents($target.'/system/storage/contents/posts/catalog.json'),true);echo json_encode(['before'=>DBLESSCMS\\Posts::effective($owned,strtotime('2030-01-01')),'after'=>DBLESSCMS\\Posts::effective($owned,strtotime('2100-01-01')),'release'=>$export['records'][$id]['scheduled']['releaseAt'],'unchanged'=>$before===hash_file('sha256',$file)]);`);
      assert.doesNotMatch(JSON.stringify(result.before),/NATIVE-FUTURE-CANARY/);assert.match(JSON.stringify(result.after),/NATIVE-FUTURE-CANARY/);assert.equal(result.after.publishedAt,'2020-01-01T00:00:00Z');assert.equal(result.release,'2099-01-01T00:00:00Z');assert.equal(result.unchanged,true);
    }finally{c.close();}
  });
  test(`${application}: Posts owns all editorial packages, assets and lifecycle; compatibility aliases are never installed extensions`,()=>{
    const c=fixture(application);try{
      for(const surface of ['system','public_html'])for(const id of ['pages','blog'])assert.equal(fs.existsSync(path.join(c.app,surface,'plugins',id)),false);
      // Installed code ownership is independent from preserved installation seeds; new-install seeds are checked by the distribution suite.
      const result=inspect(c,`$p=new DBLESSCMS\\Plugins($s);$out=['catalog'=>array_keys($p->all()),'nav'=>array_keys($p->navigation('en-us')),'codes'=>[]];foreach(['pages','blog'] as $id){foreach(['toggle','uninstall'] as $action){try{$e=new DBLESSCMS\\Extensions($s);if($action==='toggle')$e->toggle('plugins',$id,false,$p->document()['revision']);else $e->uninstall('plugins',$id,$p->document()['revision'],$id);$out['codes'][]=200;}catch(DBLESSCMS\\HttpError $e){$out['codes'][]=$e->status;}}}$out['settings']=$p->state('blog')['settings']==array_intersect_key($p->state('posts')['settings'],['publication'=>true,'discussion'=>true]);echo json_encode($out);`);
      assert.ok(result.catalog.includes('posts'));for(const id of ['pages','blog']){assert.ok(!result.catalog.includes(id));assert.ok(!result.nav.includes(id));}assert.deepEqual(result.codes,[404,404,404,404]);assert.equal(result.settings,true);
    }finally{c.close();}
  });
  test(`${application}: pre-consolidation activation/settings are projected without writes and explicit owner choices prevail`,()=>{
    const c=fixture(application);try{
      const file=path.join(c.contents,'plugins.json'),data=JSON.parse(fs.readFileSync(file));
      const oldSettings=options(c);oldSettings.publication.title='Preserved installation journal';oldSettings.discussion.enabled=true;
      data.plugins.blog={enabled:false,settings:{publication:oldSettings.publication,discussion:oldSettings.discussion}};
      data.plugins.pages={enabled:true,settings:{creation:{model:'starter-page'},browser:{pageSize:50}}};
      // A pre-Posts installation has no owner entry; reading its effective options must not migrate the retained seed.
      delete data.plugins.posts;write(file,data);const missingBefore=fs.readFileSync(file);
      const missing=inspect(c,"echo json_encode((new DBLESSCMS\\Plugins($s))->state('posts'));");assert.equal(missing.enabled,true);assert.equal(missing.settings.browser.pageSize,50);assert.equal(missing.settings.publication.title,oldSettings.publication.title);assert.deepEqual(fs.readFileSync(file),missingBefore);
      data.plugins.posts={enabled:false,settings:{browser:{pageSize:25}}};write(file,data);
      const before=fs.readFileSync(file),source=fs.readFileSync(path.join(c.contents,'blog/catalog.json'));
      const projected=inspect(c,`$p=new DBLESSCMS\\Plugins($s);$r=new DBLESSCMS\\Posts($s);$rows=$r->records();$blog=array_values(array_filter($rows,static fn($row)=>$row['source']['plugin']==='blog'))[0];echo json_encode([$p->state('posts'),$r->sourceEnabled($blog),count($r->nativeHistory($blog)),count((new DBLESSCMS\\BlogStore($s))->read()['data']['articles'])]);`);
      assert.equal(projected[0].enabled,true);assert.equal(projected[0].settings.publication.title,oldSettings.publication.title);assert.equal(projected[0].settings.discussion.enabled,true);assert.equal(projected[0].settings.creation.model,'starter-page');assert.equal(projected[0].settings.browser.pageSize,25);assert.equal(projected[1],false);assert.equal(projected[3],0);assert.deepEqual(fs.readFileSync(file),before);assert.deepEqual(fs.readFileSync(path.join(c.contents,'blog/catalog.json')),source);
      const chosen=inspect(c,`$p=new DBLESSCMS\\Plugins($s);$state=$p->state('posts');$p->save('posts',false,$state['settings'],$p->document()['revision']);$off=$p->state('posts')['enabled'];$p->saveOptions('blog',$p->state('blog')['settings'],$p->document()['revision']);echo json_encode([$off,$p->state('posts')['enabled'],$p->document()['data']['plugins']['pages']['enabled']]);`);
      assert.deepEqual(chosen,[false,false,true]);assert.deepEqual(fs.readFileSync(path.join(c.contents,'blog/catalog.json')),source);assert.equal(fs.existsSync(path.join(c.contents,'posts/catalog.json')),false);
      const removed=inspect(c,`(new DBLESSCMS\\Extensions($s))->uninstall('plugins','posts',$s->read('plugins')['revision'],'posts');echo json_encode([$s->read('plugins')['data']['plugins']['pages']['enabled'],$s->read('plugins')['data']['plugins']['blog']['enabled'],is_dir($s->config->root.'/plugins/posts')]);`);assert.deepEqual(removed,[false,false,false]);assert.deepEqual(fs.readFileSync(path.join(c.contents,'blog/catalog.json')),source);assert.equal(fs.existsSync(path.join(c.app,'public_html/plugins/posts/posts.svg')),true);
    }finally{c.close();}
  });
  test(`${application}: malicious alias ownership, contained paths, public scopes and contracts fail closed without executing adapters`,()=>{
    const c=fixture(application);try{
      const file=path.join(c.app,'system/plugins/posts/plugin.json'),manifest=JSON.parse(fs.readFileSync(file));
      const mutations=[m=>{m.compatibilityAliases='bad';},m=>{m.compatibilityAliases.pages.directory='../pages';},m=>{m.compatibilityAliases.pages.assets.css='../private.css';},m=>{m.compatibilityAliases['files-manager']=m.compatibilityAliases.pages;},m=>{m.activationFallback.from=['not-declared'];},m=>{m.activationFallback.from=[[]];}];
      for(const mutation of mutations){const copy=structuredClone(manifest);mutation(copy);write(file,copy);const result=inspect(c,`$p=new DBLESSCMS\\Plugins($s);echo json_encode([isset($p->invalid()['posts']),isset($p->available()['blog']),isset($p->available()['pages'])]);`);assert.deepEqual(result,[true,false,false]);}
      write(file,manifest);
      const contractFile=path.join(c.app,'system/plugins/posts/compatibility/blog/contract.json'),contract=JSON.parse(fs.readFileSync(contractFile));
      for(const mutation of [m=>{m.publicSettings=['credentials'];},m=>{m.publicSettings='publication';},m=>{m.configuration=[];},m=>{m.routes.comment.methods=['DELETE'];},m=>{m.routes.comment.requiresEnabled=false;},m=>{m.routes.article.file='../outside.php';}]){const copy=structuredClone(contract);mutation(copy);write(contractFile,copy);assert.equal(inspect(c,`echo json_encode(isset((new DBLESSCMS\\Plugins($s))->invalid()['posts']));`),true);}
      write(contractFile,contract);
      const adapter=path.join(c.app,'system/plugins/posts/compatibility/blog/Adapter.php'),outside=path.join(c.temporary,'untrusted.php');fs.writeFileSync(outside,'<?php throw new RuntimeException("ALIAS MUST NOT EXECUTE");');fs.unlinkSync(adapter);fs.symlinkSync(outside,adapter);assert.equal(inspect(c,`echo json_encode(isset((new DBLESSCMS\\Plugins($s))->invalid()['posts']));`),true);assert.match(fs.readFileSync(outside,'utf8'),/ALIAS MUST NOT EXECUTE/);
    }finally{c.close();}
  });
}
