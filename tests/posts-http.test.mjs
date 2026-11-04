/** Public/private publication HTTP contracts on loopback fixtures: friendly dispatch, alias/canonical safety, published-only XML/search and authenticated CAS/CSRF denial. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {applications,server,inspect,password} from './helpers/extension-media.mjs';
/** Extract one required hidden control from trusted synthetic HTML; receive document/name, return attribute bytes or fail without logging credentials. */
function field(html,name){const match=html.match(new RegExp('name="'+name+'"[^>]*value="([^"]*)"'));assert.ok(match,name);return match[1];}
for(const application of applications)test(`${path.basename(application)}: publication HTTP preserves aliases, blocks private snapshots and enforces session/CSRF/site/source CAS`,async()=>{
 const c=await server(application);let cookie='';
 /** Request only this loopback fixture and retain its session cookie; receive relative URL/options, return response without following redirects. */
 async function request(url,options={}){const response=await fetch(c.base+url,{...options,redirect:'manual',headers:{...(cookie?{Cookie:cookie}:{}),...options.headers}});for(const value of response.headers.getSetCookie())if(value.startsWith('DBLESSCMSSESSID='))cookie=value.split(';')[0];return response;}
 try{
  const configFile=path.join(c.contents,'plugins.json'),config=JSON.parse(fs.readFileSync(configFile));config.plugins.posts={enabled:true,settings:{browser:{pageSize:25}}};fs.writeFileSync(configFile,JSON.stringify(config));
  const info=inspect(c,`
    $r=new DBLESSCMS\\Posts($s);$change=function($action,$id,$input=[])use($r,$s){return $r->change($action,$id,$input,$r->read()['revision'],$s->read('site')['revision'],$r->sourceRevision());};
    $type=DBLESSCMS\\PostTypes::defaults()['page'];$type['id']='project';$type['singular']='Project';$type['plural']='Projects';$type['routeMode']='custom';$type['prefix']='projects';$change('type-save','',['type'=>$type]);
    $snap=array_values(array_filter($r->records(),static fn($row)=>$row['source']['plugin']==='pages'))[0]['draft'];$snap['type']='project';$snap['slug']='http-item';$snap['document']['meta']['title']='HTTP-PUBLIC-PUBLICATION';$snap['document']['markup']=['html'=>'<p>HTTP-PUBLIC-BODY</p>','bindings'=>[]];$created=$change('create','',['snapshot'=>$snap]);$id=$created['id'];$change('publish',$id);$snap=$r->record($id)['draft'];$snap['document']['markup']['html']='<p>HTTP-PRIVATE-DRAFT-CANARY</p>';$snap['slug']='http-private-slug';$change('save',$id,['snapshot'=>$snap]);
    $future=$snap;$future['slug']='http-future-slug';$future['document']['markup']['html']='<p>HTTP-FUTURE-CANARY</p>';$change('save',$id,['snapshot'=>$future]);$change('schedule',$id,['publishAt'=>gmdate('Y-m-d\\TH:i:s\\Z',time()+3600)]);$change('save',$id,['snapshot'=>$snap]);
    $type['prefix']='work';$change('type-save','project',['type'=>$type]);$article=DBLESSCMS\\PostTypes::defaults()['article'];$article['routeMode']='custom';$article['prefix']='press';$change('type-save','article',['type'=>$article]);echo json_encode(['id'=>$id]);
  `);
  const file=path.join(c.contents,'posts/catalog.json'),before=fs.readFileSync(file,'utf8');
  let response=await request('/work/http-item');assert.equal(response.status,200);let html=await response.text();assert.match(html,/HTTP-PUBLIC-BODY/);assert.doesNotMatch(html,/HTTP-PRIVATE-DRAFT-CANARY/);assert.match(html,/rel="canonical"[^>]+\/work\/http-item/);
  for(const route of ['/work/http-private-slug','/work/http-future-slug']){response=await request(route);assert.equal(response.status,404,'Unreleased URL must stay unavailable: '+route);await response.arrayBuffer();}
  response=await request('/projects/http-item');assert.equal(response.status,301);assert.equal(new URL(response.headers.get('location')).pathname,'/work/http-item');await response.arrayBuffer();
  response=await request('/projects?q=HTTP');assert.equal(response.status,301);assert.equal(new URL(response.headers.get('location')).pathname,'/work');assert.equal(new URL(response.headers.get('location')).searchParams.get('q'),'HTTP');await response.arrayBuffer();
  response=await request('/plugins/blog/article?slug=designing-with-clarity');assert.equal(response.status,301);assert.equal(new URL(response.headers.get('location')).pathname,'/press/designing-with-clarity');await response.arrayBuffer();
  response=await request('/press/designing-with-clarity');assert.equal(response.status,200);assert.match(await response.text(),/Designing with clarity/);
  for(const route of ['/search?q=HTTP&type=project','/plugins/posts/feed','/plugins/posts/sitemap']){response=await request(route);assert.equal(response.status,200,route);html=await response.text();assert.match(html,/HTTP-PUBLIC-PUBLICATION|http-item/);assert.doesNotMatch(html,/HTTP-PRIVATE-DRAFT-CANARY|HTTP-FUTURE-CANARY/);}
  response=await request('/search?page=2');assert.equal(response.status,200);assert.doesNotMatch(await response.text(),/HTTP-PRIVATE-DRAFT-CANARY/);assert.equal((await request('/work/missing')).status,404);assert.equal((await request('/search?tags=missing')).status,400);assert.equal((await request('/plugins/posts/settings')).status,404);assert.equal((await request('/work/http-item',{method:'POST'})).status,405);
  response=await request('/search?q='+encodeURIComponent('<script>alert(1)</script>'));assert.equal(response.status,422);assert.doesNotMatch(await response.text(),/<script>|onerror=/);
  assert.equal(fs.readFileSync(file,'utf8'),before,'Public GETs must not persist lifecycle changes.');
  const api='/cms-admin/?view=plugin-api&plugin=posts&route=editor&operation=publish';response=await request(api,{method:'POST',body:new URLSearchParams({id:info.id})});assert.equal(response.status,403);await response.arrayBuffer();
  html=await(await request('/cms-admin/')).text();response=await request('/cms-admin/',{method:'POST',body:new URLSearchParams({action:'login',username:'editor',password,csrf_token:field(html,'csrf_token')})});assert.equal(response.status,303);await response.arrayBuffer();html=await(await request('/cms-admin/?view=plugin-panel&plugin=posts&route=editor&id='+info.id)).text();
  const payload={id:info.id,csrf_token:field(html,'csrf_token'),revision:field(html,'revision'),site_revision:field(html,'site_revision'),source_revision:field(html,'source_revision')};
  for(const [changed,status] of [[{csrf_token:'invalid'},403],[{site_revision:'stale'},409],[{source_revision:'stale'},409],[{revision:'stale'},409]]){response=await request(api,{method:'POST',headers:{Origin:c.base},body:new URLSearchParams({...payload,...changed})});assert.equal(response.status,status);await response.arrayBuffer();}
  assert.equal(fs.readFileSync(file,'utf8'),before,'Denied operations must preserve current publication bytes.');const due=JSON.parse(before);due.records[info.id].scheduled.publishedAt=new Date(Date.now()-2000).toISOString().slice(0,19)+'Z';fs.writeFileSync(file,JSON.stringify(due));const dueBytes=fs.readFileSync(file,'utf8');response=await request('/work/http-future-slug');assert.equal(response.status,200);assert.match(await response.text(),/HTTP-FUTURE-CANARY/);response=await request('/work/http-item');assert.equal(response.status,301);assert.equal(new URL(response.headers.get('location')).pathname,'/work/http-future-slug');await response.arrayBuffer();assert.equal(fs.readFileSync(file,'utf8'),dueBytes,'Due URL selection must not persist a transition.');assert.doesNotMatch(c.logs(),/PHP (?:Warning|Fatal|Parse)/);
 }finally{await c.close();}
});
