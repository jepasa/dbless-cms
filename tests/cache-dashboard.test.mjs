/** Core cache/dashboard regressions in isolated source/export fixtures: configuration, freshness, quotas, expiry, failure isolation, auth, CSRF and plugin-owned metric cards. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {applications,fixture,inspect,server,password,root} from './helpers/extension-media.mjs';
const setup="$c=new DBLESSCMS\\Cache($s->config,['enabled'=>true,'development'=>true]);";
/** Invoke the real CLI request harness in a synthetic fixture; receive request/context, return response/session, fail on PHP warnings/errors, no real state. */
function request(context,input={}){const result=spawnSync('php',['-d','disable_functions=mail','-d','display_errors=stderr','-d',`session.save_path=${context.temporary}`,path.join(root,'tests/helpers/request.php'),context.app],{env:context.env,input:JSON.stringify(input),encoding:'utf8',timeout:10000});assert.equal(result.status,0,result.stderr);assert.equal(result.stderr,'');return JSON.parse(result.stdout);}
for(const application of applications){
  test(`${application}: cache defaults are additive, environment-aware and strictly bounded`,()=>{
    const c=fixture(application);try{
      const result=inspect(c,"$before=file_get_contents($s->config->data['contents'].'/site.json');$out=[(new DBLESSCMS\\Cache($s->config))->snapshot(),(new DBLESSCMS\\Cache($s->config,['enabled'=>true]))->enabled(),$s->config->production];foreach([['ttl'=>0],['ttl'=>86401],['maxMiB'=>257],['maxEntries'=>2001],['enabled'=>'true'],['ttl'=>'300'],['extra'=>true]] as $o){try{DBLESSCMS\\Cache::options($o);$out[]=200;}catch(DBLESSCMS\\HttpError $e){$out[]=$e->status;}}$out[]=$before===file_get_contents($s->config->data['contents'].'/site.json');echo json_encode($out);");
      assert.equal(result[0].status,'disabled');assert.equal(result[0].metrics,null);assert.equal(result[1],result[2]);assert.deepEqual(result.slice(3,-1),Array(7).fill(422));assert.equal(result.at(-1),true);assert.ok(!fs.existsSync(path.join(c.temporary,'storage/cache')));
    }finally{c.close();}
  });
  test(`${application}: structural page cache hits, source edits and live contract edits never return stale content`,()=>{
    const c=fixture(application);try{
      const result=inspect(c,setup+"$s->publicCache($c);$a=$s->read('home');$b=$s->read('home');$p=$s->config->data['contents'].'/pages/home.json';$d=json_decode(file_get_contents($p),true);$d['meta']['title']='Changed synthetic title';file_put_contents($p,DBLESSCMS\\JsonStore::encode($d));$v=$s->read('home');$d['status']='invalid';file_put_contents($p,DBLESSCMS\\JsonStore::encode($d));try{$s->read('home');$error=200;}catch(DBLESSCMS\\HttpError $e){$error=$e->status;}echo json_encode([$a===$b,$v['data']['meta']['title'],$v['revision']!==$a['revision'],$error,$c->snapshot()]);");
      assert.deepEqual(result.slice(0,4),[true,'Changed synthetic title',true,422]);assert.equal(result[4].metrics.hits,1);assert.equal(result[4].metrics.misses,2);
      inspect(c,setup+"$s->publicCache($c);$s->read('404');echo 'true';");
      const schema=path.join(c.app,'system/config/schemas/page.schema.json'),data=JSON.parse(fs.readFileSync(schema));data.properties.meta.properties.title.maxLength=2;fs.writeFileSync(schema,JSON.stringify(data));
      assert.equal(inspect(c,setup+"$s->publicCache($c);try{$s->read('404');echo '200';}catch(DBLESSCMS\\HttpError $e){echo $e->status;}"),422);
      const removed=inspect(c,setup+"$s->publicCache($c);unlink($s->config->data['contents'].'/pages/404.json');try{$s->read('404');echo '200';}catch(DBLESSCMS\\HttpError $e){echo $e->status;}");assert.equal(removed,404);
    }finally{c.close();}
  });
  test(`${application}: cache expiry, quota eviction, metric window and purge operate only on owned files`,()=>{
    const c=fixture(application);try{
      const result=inspect(c,"$c=new DBLESSCMS\\Cache($s->config,['enabled'=>true,'development'=>true,'ttl'=>30,'maxEntries'=>2,'maxMiB'=>1]);$c->remember('one',static fn()=>['v'=>1]);$c->remember('one',static fn()=>['v'=>2]);$dir=$s->config->data['storage'].'/cache';foreach(glob($dir.'/*.json') as $file){if(basename($file)==='metrics.json')continue;$d=json_decode(file_get_contents($file),true);$d['expires']=time()-1;file_put_contents($file,json_encode($d));}$expired=$c->snapshot()['expired'];$v=$c->remember('one',static fn()=>['v'=>3]);$c->remember('two',static fn()=>['v'=>4]);$c->remember('three',static fn()=>['v'=>5]);$bounded=$c->snapshot();$c->remember('large-one',static fn()=>['v'=>str_repeat('x',600000)]);$c->remember('large-two',static fn()=>['v'=>str_repeat('y',600000)]);$quota=$c->snapshot();$before=file_get_contents($s->config->data['contents'].'/site.json');$cleared=$c->clear();$destination=dirname($argv[1]).'/snapshot';(new DBLESSCMS\\Operations($s->config))->backup($destination);echo json_encode([$expired,$v,$bounded,$quota,$cleared,$c->snapshot(),$before===file_get_contents($s->config->data['contents'].'/site.json'),is_dir($destination.'/installation/system/storage/cache')]);");
      assert.equal(result[0],1);assert.deepEqual(result[1],{v:3});assert.equal(result[2].entries,2);assert.equal(result[2].metrics.hits,1);assert.equal(result[2].metrics.misses,4);assert.ok(result[3].bytes<=1048576);assert.equal(result[4],true);assert.equal(result[5].entries,0);assert.equal(result[5].metrics,null);assert.equal(result[6],true);assert.equal(result[7],false,'Ephemeral cache must not enter operational snapshots');
      const window=inspect(c,setup+"$c->remember('fresh',static fn()=>[]);$file=$s->config->data['storage'].'/cache/metrics.json';$d=json_decode(file_get_contents($file),true);$d['since']=time()-86401;file_put_contents($file,json_encode($d));echo json_encode($c->snapshot()['metrics']);");assert.equal(window,null);
    }finally{c.close();}
  });
  test(`${application}: corrupt cache, busy locks and symlinks degrade safely while producer failures propagate`,()=>{
    const c=fixture(application);try{
      const result=inspect(c,"ini_set('error_log',dirname($argv[1]).'/cache-errors.log');"+setup+"$c->remember('one',static fn()=>['v'=>1]);$dir=$s->config->data['storage'].'/cache';foreach(glob($dir.'/*.json') as $file)if(basename($file)!=='metrics.json')file_put_contents($file,'invalid JSON');$v=$c->remember('one',static fn()=>['v'=>2]);$state=$c->snapshot()['status'];$cleared=$c->clear();$lock=fopen($dir.'/cache.lock','c+');flock($lock,LOCK_EX);$busy=$c->remember('busy',static fn()=>['v'=>3]);flock($lock,LOCK_UN);fclose($lock);try{$c->remember('error',static function(){throw new DBLESSCMS\\HttpError(422,'Synthetic invalid data','error.json');});$code=200;}catch(DBLESSCMS\\HttpError $e){$code=$e->status;}echo json_encode([$v,$state,$cleared,$busy,$code]);");assert.deepEqual(result,[{v:2},'unavailable',true,{v:3},422]);assert.match(fs.readFileSync(path.join(c.temporary,'cache-errors.log'),'utf8'),/cache unavailable/);
      const cache=path.join(c.temporary,'storage/cache'),sentinel=path.join(c.temporary,'sentinel');fs.mkdirSync(sentinel);fs.writeFileSync(path.join(sentinel,'keep'),'unchanged');fs.rmSync(cache,{recursive:true});fs.symlinkSync(sentinel,cache);
      const link=inspect(c,"ini_set('error_log',dirname($argv[1]).'/cache-errors.log');"+setup+"echo json_encode([$c->remember('safe',static fn()=>['v'=>4]),$c->clear(),$c->snapshot()['status']]);");assert.deepEqual(link,[{v:4},false,'unavailable']);assert.deepEqual(fs.readdirSync(sentinel),['keep']);assert.equal(fs.readFileSync(path.join(sentinel,'keep'),'utf8'),'unchanged');
    }finally{c.close();}
  });
  test(`${application}: root and login target core dashboard, cache purge rejects missing auth and forged CSRF`,()=>{
    const c=fixture(application);try{
      const login=request(c,{admin:true});assert.match(login.body,/auth-avatar/);assert.doesNotMatch(login.body,/data-dashboard/);
      assert.equal(request(c,{admin:true,method:'POST',post:{action:'cache-clear',csrf_token:'forged'}}).status,403);
      const csrf=login.body.match(/name="csrf_token"[^>]*value="([^"]+)"/)[1],signed=request(c,{admin:true,session:login.session,method:'POST',post:{action:'login',username:'editor',password,csrf_token:csrf}});assert.equal(signed.status,303);
      const panel=request(c,{admin:true,session:signed.session});assert.equal(panel.status,200,panel.body);assert.match(panel.body,/data-dashboard/);assert.match(panel.body,/href="\?view=dashboard" aria-current="page"/);assert.ok(panel.body.indexOf('class="dashboard-link"')<panel.body.indexOf('data-plugin-menu='));assert.match(panel.body,/No data|Sem dados/);assert.match(panel.body,/Published articles|Artigos publicados/);
      const denied=request(c,{admin:true,session:signed.session,method:'POST',post:{action:'cache-clear',csrf_token:'forged'}});assert.equal(denied.status,403);
      const token=panel.body.match(/name="csrf_token"[^>]*value="([^"]+)"/)[1],cleared=request(c,{admin:true,session:signed.session,method:'POST',post:{action:'cache-clear',csrf_token:token}});assert.equal(cleared.status,303);assert.equal(request(c,{admin:true,session:signed.session,query:{view:'cache'}}).status,404);
      const globals=request(c,{admin:true,session:signed.session,query:{view:'site'}});assert.match(globals.body,/cache\.maxEntries/);assert.match(globals.body,/&quot;enabled&quot;: false/);
    }finally{c.close();}
  });
  test(`${application}: only anonymous public GET data enters cache; forms/admin/authenticated views stay dynamic`,()=>{
    const c=fixture(application);try{
      const siteFile=path.join(c.contents,'site.json'),site=JSON.parse(fs.readFileSync(siteFile));site.settings.cache={enabled:true,development:true};fs.writeFileSync(siteFile,JSON.stringify(site));
      const a=request(c),b=request(c);assert.equal(a.status,200);assert.equal(b.status,200);let stats=inspect(c,setup+"echo json_encode($c->snapshot());");assert.ok(stats.metrics.hits>0);const before=stats.metrics;
      const login=request(c,{admin:true});let token=login.body.match(/name="csrf_token"[^>]*value="([^"]+)"/)[1];const signed=request(c,{admin:true,session:login.session,method:'POST',post:{action:'login',username:'editor',password,csrf_token:token}});request(c,{admin:true,session:signed.session});request(c,{session:signed.session});
      stats=inspect(c,setup+"echo json_encode($c->snapshot());");assert.deepEqual(stats.metrics,before);
      const form=request(c,{query:{action:'contact'},method:'POST',post:{}});assert.equal(form.status,400);assert.deepEqual(inspect(c,setup+"echo json_encode($c->snapshot()['metrics']);"),before);
    }finally{c.close();}
  });
  test(`${application}: plugin dashboard providers are optional, localized, bounded and isolated on failure`,()=>{
    const c=fixture(application);try{
      let data=inspect(c,"echo json_encode(DBLESSCMS\\Dashboard::snapshot($s,'https://example.test','en-us'));");assert.equal(data.warnings.length,0);assert.equal(data.indicators.length,2);assert.equal(data.indicators[0].label,'Published articles');assert.ok(data.indicators[0].value>=1);assert.deepEqual(Object.keys(data.groups),['blog']);assert.deepEqual(data.groups.blog.items,data.indicators);
      const registry=path.join(c.contents,'plugins.json'),saved=fs.readFileSync(registry),reg=JSON.parse(saved);reg.plugins.blog.enabled=false;fs.writeFileSync(registry,JSON.stringify(reg));assert.equal(inspect(c,"echo json_encode(DBLESSCMS\\Dashboard::snapshot($s,'https://example.test','en-us')['indicators']);").length,0);assert.deepEqual(Object.keys(inspect(c,"echo json_encode(DBLESSCMS\\Dashboard::snapshot($s,'https://example.test','en-us')['groups']);")),[]);fs.writeFileSync(registry,saved);
      const provider=path.join(c.app,'system/plugins/blog/Plugin.php'),code=fs.readFileSync(provider,'utf8');fs.writeFileSync(provider,code.replace("return [['label'=>'blog.dashboard.published','value'=>$published],['label'=>'blog.dashboard.pending','value'=>$pending]];","return array_fill(0,9,['label'=>'blog.dashboard.published','value'=>0]);"));assert.deepEqual(inspect(c,"ini_set('error_log',dirname($argv[1]).'/provider-errors.log');echo json_encode(DBLESSCMS\\Dashboard::snapshot($s,'https://example.test','en-us')['warnings']);"),['Blog']);fs.writeFileSync(provider,code);

      const file=path.join(c.contents,'blog/catalog.json');fs.writeFileSync(file,'invalid JSON');
      data=inspect(c,"ini_set('error_log',dirname($argv[1]).'/provider-errors.log');echo json_encode(DBLESSCMS\\Dashboard::snapshot($s,'https://example.test','en-us'));");assert.equal(data.indicators.length,0);assert.deepEqual(Object.keys(data.groups),[]);assert.deepEqual(data.warnings,['Blog']);assert.ok(data.published>0);assert.match(fs.readFileSync(path.join(c.temporary,'provider-errors.log'),'utf8'),/dashboard provider blog/);
    }finally{c.close();}
  });
  test(`${application}: each plugin owns one card with all metrics, including empty, malformed and coincident-name providers`,()=>{
    const c=fixture(application);try{
      const provider=path.join(c.app,'system/plugins/blog/Plugin.php'),code=fs.readFileSync(provider,'utf8'),original="return [['label'=>'blog.dashboard.published','value'=>$published],['label'=>'blog.dashboard.pending','value'=>$pending]];";
      const manifest=path.join(c.app,'system/plugins/blog/plugin.json'),metadata=JSON.parse(fs.readFileSync(manifest));metadata.name='Páginas';fs.writeFileSync(manifest,JSON.stringify(metadata));
      const login=request(c,{admin:true}),csrf=login.body.match(/name="csrf_token"[^>]*value="([^"]+)"/)[1],signed=request(c,{admin:true,session:login.session,method:'POST',post:{action:'login',username:'editor',password,csrf_token:csrf}});assert.equal(signed.status,303);
      const html=request(c,{admin:true,session:signed.session}).body,cards=[...html.matchAll(/<section\b[^>]*data-dashboard-plugin="([^"]+)"[^>]*>([\s\S]*?)<\/section>/g)];assert.deepEqual(cards.map(card=>card[1]),['pages','blog']);
      for(const card of cards){assert.equal([...card[2].matchAll(/<h2>/g)].length,1);assert.equal([...card[2].matchAll(/<dt>/g)].length,2);assert.equal([...card[2].matchAll(/<dd\b/g)].length,2);assert.match(card[2],/<dl class="dashboard-plugin-metrics">/);}
      assert.match(cards[0][2],/Published pages|Páginas publicadas/);assert.match(cards[0][2],/Page drafts|Rascunhos de páginas/);assert.match(cards[1][2],/Published articles|Artigos publicados/);assert.match(cards[1][2],/Comments awaiting moderation|Comentários aguardando moderação/);
      let snapshot=inspect(c,"echo json_encode(DBLESSCMS\\Dashboard::snapshot($s,'https://example.test','en-us'));");assert.equal(snapshot.groups.blog.plugin,'Páginas');assert.deepEqual(snapshot.groups.blog.items,snapshot.indicators);
      fs.writeFileSync(provider,code.replace(original,'return [];'));snapshot=inspect(c,"echo json_encode(DBLESSCMS\\Dashboard::snapshot($s,'https://example.test','en-us'));");assert.deepEqual(Object.keys(snapshot.groups),[]);assert.deepEqual(snapshot.warnings,[]);assert.doesNotMatch(request(c,{admin:true,session:signed.session}).body,/data-dashboard-plugin="blog"/);
      fs.writeFileSync(provider,code.replace(original,"return [['label'=>'blog.dashboard.published','value'=>1],['label'=>'blog.dashboard.pending','value'=>-1]];"));snapshot=inspect(c,"ini_set('error_log',dirname($argv[1]).'/provider-errors.log');echo json_encode(DBLESSCMS\\Dashboard::snapshot($s,'https://example.test','en-us'));");assert.deepEqual(snapshot.indicators,[]);assert.deepEqual(Object.keys(snapshot.groups),[]);assert.deepEqual(snapshot.warnings,['Páginas']);
    }finally{c.close();}
  });

}
