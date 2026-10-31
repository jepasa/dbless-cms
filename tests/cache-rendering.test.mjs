/** Public rendering/cache regressions: live contracts, scheduled snapshots, current/unknown providers and HTTP privacy in disposable fixtures only. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {applications,fixture,inspect,server} from './helpers/extension-media.mjs';
const warm="$cache=new DBLESSCMS\\Cache($s->config,['enabled'=>true,'development'=>true,'ttl'=>86400]);$s->publicCache($cache);$s->read('home');$s->read('home');";
/** Encode trusted fixture data as a PHP JSON expression; receive JSON-compatible value, return inert base64 source without shell interpolation or execution. */
function php(value){return "json_decode(base64_decode('"+Buffer.from(JSON.stringify(value)).toString('base64')+"'),true,32,JSON_THROW_ON_ERROR)";}
/** Replace only a fixture JSON document; receive owned root, relative file and mutation, write updated bytes or propagate errors. */
function change(c,relative,mutate){const file=path.join(c.contents,relative),data=JSON.parse(fs.readFileSync(file));mutate(data);fs.writeFileSync(file,JSON.stringify(data));}
/** Create a portable author body used by the real publication catalog; receive HTML/date, return a closed body definition without side effects. */
function body(html,date='2000-01-01T00:00:00Z'){return {mode:'html',html,bindings:[],source:{plugin:'',id:''},publicationAt:date};}
for(const application of applications){
  test(`${application}: a warm page obeys changed plugin contracts without a hand-maintained dependency list`,()=>{
    const c=fixture(application);try{
      const file=path.join(c.contents,'pages/starter-page.json'),page=JSON.parse(fs.readFileSync(file));page.markup={html:'<section>{{#news.items}}<h2>{{title}}</h2>{{/news.items}}</section>',bindings:[{plugin:'blog',service:'posts',alias:'news',parameters:{limit:2,category:''}}]};fs.writeFileSync(file,JSON.stringify(page));
      inspect(c,warm+"$s->read('starter-page');echo 'true';");
      const manifest=path.join(c.app,'system/plugins/blog/plugin.json'),data=JSON.parse(fs.readFileSync(manifest));data.contentServices.posts.parameters.properties.limit.maximum=1;data.contentServices.posts.parameters.properties.limit.default=1;fs.writeFileSync(manifest,JSON.stringify(data));
      const result=inspect(c,warm+"$codes=[];foreach([true,false] as $enabled){$store=new DBLESSCMS\\JsonStore($s->config);if($enabled)$store->publicCache($cache);try{$store->read('starter-page');$codes[]=200;}catch(DBLESSCMS\\HttpError $e){$codes[]=$e->status;}}echo json_encode([$codes,$cache->snapshot()['metrics']['hits']]);");assert.deepEqual(result[0],[422,422]);assert.ok(result[1]>=3);
    }finally{c.close();}
  });
  test(`${application}: changed normalization rules reprocess original source on a hit rather than a previously transformed snapshot`,()=>{
    const c=fixture(application);try{
      change(c,'pages/home.json',p=>{p.meta.title='<b>Original source</b>';});
      const before=inspect(c,warm+"echo json_encode([$s->read('home')['data']['meta']['title'],$cache->snapshot()['metrics']['hits']]);");assert.equal(before[0],'Original source');
      const schema=path.join(c.app,'system/lib/Schema.php'),code=fs.readFileSync(schema,'utf8');assert.ok(code.includes('strip_tags($value)'));fs.writeFileSync(schema,code.replace('strip_tags($value)',"strip_tags(str_replace('<b>Original source</b>','New normalization',$value))"));
      const after=inspect(c,warm+"echo json_encode([$s->read('home')['data']['meta']['title'],$cache->snapshot()['metrics']['hits']]);");assert.equal(after[0],'New normalization');assert.ok(after[1]>before[1]);
    }finally{c.close();}
  });
  test(`${application}: decoded cache round-trips preserve numeric types and cannot turn invalid floats into accepted integer parameters`,()=>{
    const c=fixture(application);try{
      const file=path.join(c.contents,'pages/starter-page.json'),page=JSON.parse(fs.readFileSync(file));page.markup={html:'<section>{{#news.items}}{{title}}{{/news.items}}</section>',bindings:[{plugin:'blog',service:'posts',alias:'news',parameters:{limit:2,category:''}}]};fs.writeFileSync(file,JSON.stringify(page).replace('"limit":2','"limit":2.0'));
      const result=inspect(c,warm+"$codes=[];for($i=0;$i<2;$i++){try{$s->read('starter-page');$codes[]=200;}catch(DBLESSCMS\\HttpError $e){$codes[]=$e->status;}}$cache->remember('float-fidelity',static fn()=>['number'=>2.0]);$number=$cache->remember('float-fidelity',static fn()=>['number'=>3.0]);echo json_encode([$codes,is_float($number['number']),$number['number'],$cache->snapshot()['metrics']['hits']]);");assert.deepEqual(result.slice(0,3),[[422,422],true,2]);assert.ok(result[3]>=2);
    }finally{c.close();}
  });
  test(`${application}: numeric overflow in origin JSON is rejected by live contracts, never converted into a cache-induced 500`,()=>{
    const c=fixture(application);try{
      const file=path.join(c.contents,'pages/starter-page.json'),page=JSON.parse(fs.readFileSync(file));page.markup={html:'<section>{{#news.items}}{{title}}{{/news.items}}</section>',bindings:[{plugin:'blog',service:'posts',alias:'news',parameters:{limit:2,category:''}}]};fs.writeFileSync(file,JSON.stringify(page).replace('"limit":2','"limit":1e400'));
      const result=inspect(c,"ini_set('error_log',dirname($argv[1]).'/cache-overflow.log');"+warm+"$codes=[];for($i=0;$i<2;$i++){try{$s->read('starter-page');$codes[]=200;}catch(DBLESSCMS\\HttpError $e){$codes[]=$e->status;}} $uncacheable=$cache->remember('nonfinite',static fn()=>['value'=>INF]);echo json_encode([$codes,is_infinite($uncacheable['value'])]);");assert.deepEqual(result,[[422,422],true]);assert.match(fs.readFileSync(path.join(c.temporary,'cache-overflow.log'),'utf8'),/cache unavailable/);
    }finally{c.close();}
  });
  test(`${application}: cache hits always sanitize and validate; obsolete records, oversized origins and source removal cannot bypass the boundary`,()=>{
    const c=fixture(application);try{
      change(c,'pages/home.json',p=>{p.meta.title='<strong>Safe source title</strong>';});
      const result=inspect(c,warm+"$dir=$s->config->data['storage'].'/cache';$safe=$s->read('home')['data']['meta']['title'];foreach(glob($dir.'/*.json') as $f){if(basename($f)==='metrics.json')continue;$row=json_decode(file_get_contents($f),true);$row['value']['status']='invalid';file_put_contents($f,json_encode($row));}$repaired=$s->read('home')['data']['status'];foreach(glob($dir.'/*.json') as $f){if(basename($f)==='metrics.json')continue;$row=json_decode(file_get_contents($f),true);unset($row['version'],$row['digest']);file_put_contents($f,json_encode($row));}$obsolete=$s->read('home')['data']['status'];$cfile=$s->config->data['contents'].'/pages/home.json';$original=json_decode(file_get_contents($cfile),true);$original['status']='invalid';file_put_contents($cfile,DBLESSCMS\\JsonStore::encode($original));try{$s->read('home');$invalid=200;}catch(DBLESSCMS\\HttpError $e){$invalid=$e->status;}file_put_contents($cfile,str_repeat(' ',524289));try{$s->read('home');$size=200;}catch(DBLESSCMS\\HttpError $e){$size=$e->status;}unlink($cfile);try{$s->read('home');$missing=200;}catch(DBLESSCMS\\HttpError $e){$missing=$e->status;}echo json_encode([$safe,$repaired,$obsolete,$invalid,$size,$missing]);");assert.deepEqual(result,['Safe source title','published','published',422,413,404]);
    }finally{c.close();}
  });
  test(`${application}: scheduled Homepages switch at the exact boundary despite a 24-hour page cache, without publication writes`,()=>{
    const c=fixture(application);try{
      const result=inspect(c,warm+"(new DBLESSCMS\\Plugins($s))->instance('homepage');$catalog=new DBLESSCMS\\HomepageCatalog($s);$site=$s->read('site');$a=$catalog->save('','Previous','',"+php(body('<section>Previous live</section>'))+",$catalog->read()['revision'],$site['revision'],true);$b=$catalog->save('','Scheduled','',"+php(body('<section>Scheduled live</section>','2040-01-01T00:00:00Z'))+",$a['revision'],$site['revision'],true);$before=$catalog->read()['revision'];$context=new DBLESSCMS\\ContentContext($s,$site['data']['template'],'https://example.test','home');$homepages=new DBLESSCMS\\Homepages($s);$early=$homepages->resolve($context,strtotime('2039-12-31T23:59:59Z'));$due=$homepages->resolve($context,strtotime('2040-01-01T00:00:00Z'));$after=$homepages->resolve($context,strtotime('2040-01-01T00:00:01Z'));echo json_encode([$early['id']===$a['id'],$due['id']===$b['id'],$after['id']===$b['id'],$due['html'],$before===$catalog->read()['revision'],$cache->snapshot()['metrics']['hits']]);");assert.deepEqual(result.slice(0,5),[true,true,true,'<section>Scheduled live</section>',true]);assert.ok(result[5]>0);
    }finally{c.close();}
  });
  test(`${application}: linked Page edits, withdrawals, fallback and recovery stay live with cache enabled`,()=>{
    const c=fixture(application);try{
      const source=body('', '2001-01-01T00:00:00Z');source.mode='source';source.source={plugin:'pages',id:'starter-page'};
      inspect(c,warm+"(new DBLESSCMS\\Plugins($s))->instance('homepage');$catalog=new DBLESSCMS\\HomepageCatalog($s);$site=$s->read('site');$a=$catalog->save('','Fallback','',"+php(body('<section>Public fallback</section>'))+",$catalog->read()['revision'],$site['revision'],true);$catalog->save('','Linked page','',"+php(source)+",$a['revision'],$site['revision'],true);echo 'true';");
      const query=warm+"ini_set('error_log',dirname($argv[1]).'/source-fallback.log');echo json_encode(DBLESSCMS\\App::render($s,'home',false,null,'https://example.test'));";
      change(c,'pages/starter-page.json',p=>{p.markup={html:'<section id="current-source">First released source</section>',bindings:[]};});assert.match(inspect(c,query),/First released source/);
      change(c,'pages/starter-page.json',p=>{p.markup.html='<section id="current-source">Changed released source</section>';});let html=inspect(c,query);assert.match(html,/Changed released source/);assert.doesNotMatch(html,/First released source/);
      change(c,'pages/starter-page.json',p=>{p.status='draft';});html=inspect(c,query);assert.match(html,/Public fallback/);assert.doesNotMatch(html,/Changed released source/);
      change(c,'pages/starter-page.json',p=>{p.status='published';});assert.match(inspect(c,query),/Changed released source/);
    }finally{c.close();}
  });
  test(`${application}: Blog, Pages, Menu, Slides and Widgets data refresh behind warm page snapshots`,()=>{
    const c=fixture(application);try{
      change(c,'site.json',s=>{s.template='essencial';});
      const query=warm+"$context=new DBLESSCMS\\ContentContext($s,'essencial','https://example.test','home');$components=new DBLESSCMS\\Components($context);echo json_encode([$components->data('blog','posts',['limit'=>12,'category'=>'']),$components->data('pages','list',['limit'=>12]),$components->data('menu','tree',['key'=>'footer-links']),$components->data('slides','data',['key'=>'home-hero']),$components->data('widgets','value',['key'=>'example-message']),$cache->snapshot()['metrics']['hits']]);";
      const before=inspect(c,query);
      change(c,'blog/catalog.json',d=>{for(const v of Object.values(d.articles))v.title='Fresh article '+v.title;});
      for(const file of fs.readdirSync(path.join(c.contents,'pages')).filter(v=>v.endsWith('.json')))change(c,'pages/'+file,d=>{d.meta.title='Fresh page '+d.meta.title;});
      change(c,'menu/menus.json',d=>{for(const v of Object.values(d.menus))for(const item of v.items)item.label='Fresh menu '+item.label;});
      change(c,'slides/slides.json',d=>{for(const v of Object.values(d.collections))for(const item of v.items)item.title='Fresh slide '+item.title;});
      change(c,'widgets/widgets.json',d=>{d.reusable['example-message'].value='Fresh widget {{literal}}';});
      const after=inspect(c,query);for(let i=0;i<5;i++)assert.notDeepEqual(after[i],before[i],`Provider ${i} must remain dynamic`);assert.equal(after[4].value,'Fresh widget {{literal}}');assert.ok(after[5]>before[5]);
    }finally{c.close();}
  });
  test(`${application}: newly discovered providers refresh, fail empty and recover without cache registration or purges`,()=>{
    const c=fixture(application);try{
      const id='cache-fixture',priv=path.join(c.app,'system/plugins',id),pub=path.join(c.app,'public_html/plugins',id);fs.cpSync(path.join(c.app,'system/plugins/files-manager'),priv,{recursive:true});fs.cpSync(path.join(c.app,'public_html/plugins/files-manager'),pub,{recursive:true});
      const file=path.join(priv,'plugin.json'),manifest=JSON.parse(fs.readFileSync(file));manifest.id=id;manifest.name='Cache fixture';manifest.class='DBLESSCMS\\CacheFixturePlugin';manifest.contentServices={value:{api:1,description:'Read a synthetic public value.',context:'text',parameters:{type:'object',additionalProperties:false,properties:{}},result:{type:'object',additionalProperties:false,required:['value'],properties:{value:{type:'string',maxLength:100}}},formats:['text']}};fs.writeFileSync(file,JSON.stringify(manifest));
      fs.writeFileSync(path.join(priv,'Plugin.php'),`<?php /** Disposable public provider: fixture JSON read, never installed in a real application. */ declare(strict_types=1); namespace DBLESSCMS; final class CacheFixturePlugin implements PluginInterface,ContentProviderInterface { /** Validate fixture settings without writes. */ public function validateSettings(array $settings,Config $config):void{} /** No admin output. */ public function render(PluginContext $context):string{return '';} /** No mutations. */ public function api(PluginContext $context,string $operation):?array{return null;} /** Read fixture value or explicitly fail; core must not serve a stale result. */ public function content(string $service,array $parameters,ContentContext $context):array{$value=file_get_contents($context->store->config->data['contents'].'/public-value.txt');if($value==='unavailable')throw new \\RuntimeException('Synthetic provider failure.');return ['value'=>$value];}}`);
      change(c,'plugins.json',r=>{r.plugins[id]={enabled:true,settings:manifest.defaults};});change(c,'pages/starter-page.json',p=>{p.markup={html:'<section id="future-data">{{sample.value}}</section>',bindings:[{plugin:id,service:'value',alias:'sample',parameters:{}}]};});
      const value=path.join(c.contents,'public-value.txt'),query=warm+"ini_set('error_log',dirname($argv[1]).'/optional-provider.log');echo json_encode(DBLESSCMS\\App::render($s,'starter-page',false,null,'https://example.test'));";
      fs.writeFileSync(value,'First future value');assert.match(inspect(c,query),/First future value/);fs.writeFileSync(value,'Second future value');let html=inspect(c,query);assert.match(html,/Second future value/);assert.doesNotMatch(html,/First future value/);
      fs.writeFileSync(value,'unavailable');html=inspect(c,query);assert.doesNotMatch(html,/Second future value|Synthetic provider failure/);assert.match(html,/<section id="future-data"><\/section>/);
      fs.writeFileSync(value,'Recovered future value');assert.match(inspect(c,query),/Recovered future value/);change(c,'plugins.json',r=>{r.plugins[id].enabled=false;});assert.doesNotMatch(inspect(c,query),/Recovered future value/);change(c,'plugins.json',r=>{r.plugins[id].enabled=true;});assert.match(inspect(c,query),/Recovered future value/);
    }finally{c.close();}
  });
  test(`${application}: core and plugin HTML prohibit HTTP caching, including conditional requests and errors`,async()=>{
    const c=await server(application);try{
      change(c,'site.json',s=>{s.settings.cache={enabled:true,development:true,ttl:86400};});
      for(const route of ['/', '/?page=starter-page','/?page=missing-fixture-page','/plugins/blog/','/plugins/missing-fixture/','/cms-admin/']){
        for(let i=0;i<2;i++){const r=await fetch(c.base+route,{redirect:'manual',headers:{'If-None-Match':'"stale-fixture"','If-Modified-Since':'Thu, 01 Jan 2099 00:00:00 GMT'}});assert.notEqual(r.status,304);assert.match(r.headers.get('cache-control'),/\bno-store\b/);assert.equal(r.headers.get('etag'),null);assert.equal(r.headers.get('last-modified'),null);await r.text();}
      }assert.doesNotMatch(c.logs(),/PHP (?:Warning|Fatal|Parse)/);
    }finally{await c.close();}
  });
}
