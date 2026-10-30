/** Blog regressions against isolated source/production copies: editorial contracts, visibility, CAS/history, path safety, HTTP/CSRF, moderation and sanitized export. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {applications,fixture,inspect,server,password} from './helpers/extension-media.mjs';

const boot="$p=(new DBLESSCMS\\Plugins($s))->instance('blog');$b=new DBLESSCMS\\BlogStore($s);";
/** Read the private synthetic catalog; takes fixture, returns parsed data without contacting an application or touching live content. */
function catalog(context){return JSON.parse(fs.readFileSync(path.join(context.contents,'blog/catalog.json'),'utf8'));}
/** Build a loopback cookie client; takes base, returns request function that persists only this fixture's cookie and never follows redirects. */
function client(base){
  let cookie='';
  return async (route,options={})=>{const response=await fetch(base+route,{...options,redirect:'manual',headers:{...(cookie?{Cookie:cookie}:{}),...options.headers}});for(const header of response.headers.getSetCookie())if(header.startsWith('DBLESSCMSSESSID='))cookie=header.split(';')[0];return response;};
}
/** Extract and unescape a hidden input; takes HTML/name, returns value or fails the test when a required control is missing. */
function field(html,name){const match=html.match(new RegExp('name="'+name+'"[^>]*value="([^"]*)"'));assert.ok(match,name);return match[1].replaceAll('&amp;','&').replaceAll('&quot;','"');}
/** Authenticate normally using the login form; takes request callback, returns its session CSRF and asserts the redirect. */
async function login(request){const html=await (await request('/cms-admin/')).text();const response=await request('/cms-admin/',{method:'POST',body:new URLSearchParams({action:'login',csrf_token:field(html,'csrf_token'),username:'editor',password})});assert.equal(response.status,303);return field(await (await request('/cms-admin/?view=plugin-panel&plugin=blog&route=editor')).text(),'csrf_token');}

for(const application of applications){
  test(`${application}: blog contracts reject unsafe content, bad references, duplicate slugs and invalid schedules`,()=>{
    const context=fixture(application);
    try{
      const mutations=["$d['extra']=true;","$d['articles']['designing-with-clarity']['cover']='../secret.png';","$d['articles']['designing-with-clarity']['cover']='https://outside.test/a.png';","$d['articles']['designing-with-clarity']['blocks']=[['type'=>'html','text'=>'<script>bad</script>']];","$d['articles']['designing-with-clarity']['blocks']=[['type'=>'link','text'=>'Unsafe','url'=>'javascript:alert(1)']];","$d['articles']['designing-with-clarity']['blocks']=[['type'=>'link','text'=>'Unsafe','url'=>'//outside.test/x']];","$d['articles']['designing-with-clarity']['blocks']=[['type'=>'link','text'=>'Unsafe','url'=>'https://user:pass@example.test/x']];","$d['articles']['designing-with-clarity']['title']='<img src=x onerror=alert(1)>';","$d['articles']['designing-with-clarity']['author']='missing';","$d['articles']['designing-with-clarity']['author']=[];","$d['articles']['designing-with-clarity']['publishedAt']='2026-02-30T10:00:00Z';","$d['articles']['designing-with-clarity']['publishedAt']='2026-01-01T10:00:00+03:00';","$d['articles']['designing-with-clarity']['slug']='content-without-a-database';","$d['articles']['designing-with-clarity']['coverAlt']='';","$d['categories']['guides']['parent']='publishing';","$d['articles']['designing-with-clarity']['tags']=['missing'];"];
      for(const mutation of mutations){const status=inspect(context,boot+"$d=$b->read()['data'];"+mutation+"try{DBLESSCMS\\BlogModel::catalog($d);echo '200';}catch(DBLESSCMS\\HttpError $e){echo $e->status;}");assert.equal(status,422,mutation);}
      const output=inspect(context,boot+"$d=$b->read()['data'];$d['articles']['designing-with-clarity']['blocks']=[['type'=>'code','text'=>'<script>literal only</script>']];echo json_encode(DBLESSCMS\\BlogModel::catalog($d)['articles']['designing-with-clarity']['blocks']);");assert.equal(output[0].text,'<script>literal only</script>');
    }finally{context.close();}
  });
  test(`${application}: scheduled visibility, literal search, descendant categories and bounded pagination`,()=>{
    const context=fixture(application);
    try{
      const data=inspect(context,boot+"$d=$b->read()['data'];$o=(new DBLESSCMS\\Plugins($s))->state('blog')['settings'];$o['publication']['pageSize']=2;$q=['q'=>'','category'=>'','tag'=>'','author'=>'','page'=>'1'];$a=DBLESSCMS\\BlogView::listing($d,$o,$q);$q['page']='2';$c=DBLESSCMS\\BlogView::listing($d,$o,$q);$q['page']='1';$q['category']='guides';$g=DBLESSCMS\\BlogView::listing($d,$o,$q);$q['category']='';$q['q']='database';$f=DBLESSCMS\\BlogView::listing($d,$o,$q);$post=$d['articles']['a-scheduled-example'];$clock=strtotime($post['publishedAt']);echo json_encode([$a['total'],array_column($a['articles'],'slug'),array_column($c['articles'],'slug'),$g['total'],array_column($f['articles'],'slug'),DBLESSCMS\\BlogModel::visible($post,$clock-1),DBLESSCMS\\BlogModel::visible($post,$clock),DBLESSCMS\\BlogModel::readingTime($d['articles']['designing-with-clarity'],200),array_keys($a['catalog']['articles'])]);");
      assert.equal(data[0],3);assert.equal(data[1].length,2);assert.equal(data[2].length,1);assert.equal(new Set([...data[1],...data[2]]).size,3);assert.equal(data[3],2);assert.deepEqual(data[4],['content-without-a-database']);assert.equal(data[5],false);assert.equal(data[6],true);assert.ok(data[7]>=1);assert.equal(data[8].length,3);assert.ok(!data[8].includes('a-draft-to-make-your-own'));assert.ok(!data[8].includes('a-scheduled-example'));
    }finally{context.close();}
  });
  test(`${application}: CAS rejects stale/invalid changes, backups restore and retained history stays bounded`,()=>{
    const context=fixture(application);
    try{
      const data=inspect(context,boot+"$first=$b->read();$changed=$b->mutate($first['revision'],static function($d){$d['articles']['designing-with-clarity']['title']='Changed';return $d;});$codes=[];try{$b->mutate($first['revision'],static fn($d)=>$d);}catch(DBLESSCMS\\HttpError $e){$codes[]=$e->status;}try{$b->mutate($changed['revision'],static function($d){$d['bad']=1;return $d;});}catch(DBLESSCMS\\HttpError $e){$codes[]=$e->status;}$unchanged=$b->read()['revision']===$changed['revision'];$b->restore($b->history()[0],$changed['revision']);$restored=$b->read()['data']['articles']['designing-with-clarity']['title'];for($i=0;$i<24;$i++)$b->mutate($b->read()['revision'],static fn($d)=>$d);echo json_encode([$codes,$unchanged,$restored,count($b->history())]);");
      assert.deepEqual(data[0],[409,422]);assert.equal(data[1],true);assert.equal(data[2],'Designing with clarity');assert.equal(data[3],20);
      const audit=fs.readFileSync(path.join(context.temporary,'storage/audit.jsonl'),'utf8');assert.doesNotMatch(audit,/Designing|Changed|Synthetic-Media-Password/);
    }finally{context.close();}
  });
  test(`${application}: symlinked documents, ancestors, history and audit are rejected without writing outside storage`,()=>{
    for(const surface of ['catalog','ancestor','history','audit']){
      const context=fixture(application);
      try{
        const outside=path.join(context.temporary,'outside');fs.mkdirSync(outside);const marker=path.join(outside,'marker.json');fs.writeFileSync(marker,'PRIVATE TEST MARKER');
        if(surface==='catalog'){fs.unlinkSync(path.join(context.contents,'blog/catalog.json'));fs.symlinkSync(marker,path.join(context.contents,'blog/catalog.json'));}
        if(surface==='ancestor'){fs.renameSync(path.join(context.contents,'blog'),path.join(context.contents,'blog-original'));fs.symlinkSync(outside,path.join(context.contents,'blog'));}
        if(surface==='history'){fs.mkdirSync(path.join(context.temporary,'storage'),{recursive:true});fs.symlinkSync(outside,path.join(context.temporary,'storage/blog-history'));}
        if(surface==='audit'){fs.mkdirSync(path.join(context.temporary,'storage'),{recursive:true});fs.symlinkSync(marker,path.join(context.temporary,'storage/audit.jsonl'));}
        const status=inspect(context,boot+"try{$d=$b->read();$b->mutate($d['revision'],static fn($x)=>$x);echo '200';}catch(DBLESSCMS\\HttpError $e){echo $e->status;}");assert.equal(status,503,surface);assert.equal(fs.readFileSync(marker,'utf8'),'PRIVATE TEST MARKER');assert.deepEqual(fs.readdirSync(outside),['marker.json']);
      }finally{context.close();}
    }
  });
  test(`${application}: private operations preserve IDs, reserve old slugs and block deletion of referenced taxonomies`,()=>{
    const context=fixture(application);
    try{
      const result=inspect(context,boot+"$r=new DBLESSCMS\\Plugins($s);$c=$r->context('blog','http://localhost','en-us','editor');$old=$b->read()['data']['articles']['designing-with-clarity'];$old['publishedAt']='2026-01-20T12:34:56Z';$_SERVER['REQUEST_METHOD']='POST';$_POST=$old;$_POST['featured']=$old['featured']?'1':'0';$_POST['noindex']=$old['noindex']?'1':'0';$_POST['id']=$old['id'];$_POST['revision']=$b->read()['revision'];$_POST['blocks']=json_encode($old['blocks']);$_POST['publishedAt']=substr($old['publishedAt'],0,19);$_POST['slug']='a-clearer-design';$p->api($c,'save');$d=$b->read();$a=$d['data']['articles'][$old['id']];$codes=[];$_POST=['revision'=>$d['revision'],'id'=>'ideas','kind'=>'categories'];try{$p->api($r->context('blog','http://localhost','en-us','taxonomies'),'taxonomy-remove');}catch(DBLESSCMS\\HttpError $e){$codes[]=$e->status;}try{$p->api($r->context('blog','http://localhost','en-us','overview'),'save');}catch(DBLESSCMS\\HttpError $e){$codes[]=$e->status;}echo json_encode([$a['id'],$a['slug'],$a['aliases'],$codes,$a['publishedAt']]);");
      assert.equal(result[0],'designing-with-clarity');assert.equal(result[1],'a-clearer-design');assert.deepEqual(result[2],['designing-with-clarity']);assert.deepEqual(result[3],[409,404]);assert.equal(result[4],'2026-01-20T12:34:56Z');
    }finally{context.close();}
  });
  test(`${application}: export includes private drafts/schedules and only referenced raster files, excluding trash/comments/history`,()=>{
    const context=fixture(application);
    try{
      const folder=path.join(context.app,'public_html/media');fs.mkdirSync(folder,{recursive:true});fs.copyFileSync(path.join(context.app,'public_html/templates/essencial/images/examples/studio.png'),path.join(folder,'Capa da equipe (1).png'));fs.writeFileSync(path.join(folder,'unrelated.txt'),'MUST NOT EXPORT');
      inspect(context,boot+"$b->mutate($b->read()['revision'],static function($d){$d['articles']['designing-with-clarity']['cover']='media/Capa da equipe (1).png';return $d;});$c=new DBLESSCMS\\BlogStore($s,'comments');$c->mutate($c->read()['revision'],static function($d){$d['comments']['comment-test']=['id'=>'comment-test','article'=>'designing-with-clarity','name'=>'Synthetic Reader','body'=>'NOT FOR EXPORT','status'=>'approved','createdAt'=>'2026-01-01T00:00:00Z'];return $d;});require $argv[1].'/system/plugins/blog/Export.php';DBLESSCMS\\BlogExport::run($s,dirname($argv[1]).'/export');echo 'true';");
      const target=path.join(context.temporary,'export'),data=JSON.parse(fs.readFileSync(path.join(target,'system/storage/contents/blog/catalog.json')));assert.ok(data.articles['a-draft-to-make-your-own']);assert.ok(data.articles['a-scheduled-example']);assert.ok(!data.articles['a-restorable-example']);assert.ok(fs.existsSync(path.join(target,'public_html/media/Capa da equipe (1).png')));assert.ok(!fs.existsSync(path.join(target,'public_html/media/unrelated.txt')));assert.ok(!fs.existsSync(path.join(target,'system/storage/contents/blog/comments.json')));assert.ok(!fs.existsSync(path.join(target,'system/storage/blog-history')));
      fs.unlinkSync(path.join(folder,'Capa da equipe (1).png'));fs.symlinkSync(path.join(folder,'unrelated.txt'),path.join(folder,'Capa da equipe (1).png'));
      const status=inspect(context,boot+"require $argv[1].'/system/plugins/blog/Export.php';try{DBLESSCMS\\BlogExport::run($s,dirname($argv[1]).'/blocked');echo '200';}catch(RuntimeException $e){echo '422';}");assert.equal(status,422);
    }finally{context.close();}
  });
  test(`${application}: public templates receive only visible articles while authenticated preview remains explicit`,()=>{
    const context=fixture(application);
    try{
      for(const template of ['essencial','jepasaDotCom']){
        const partial=path.join(context.app,'system/templates',template,'partials/blog.php');
        if(fs.existsSync(partial))fs.writeFileSync(partial,"<?php /** Synthetic projection observer: print only validated opaque IDs to verify least-data delivery. */ echo DBLESSCMS\\e(implode(',',array_keys($blogModel['catalog']['articles'])));");
      }
      const output=inspect(context,boot+"$c=$b->read()['data'];$o=(new DBLESSCMS\\Plugins($s))->state('blog')['settings'];$html=DBLESSCMS\\BlogView::document($s,'http://localhost',['kind'=>'article','article'=>$c['articles']['designing-with-clarity'],'catalog'=>$c,'preview'=>false],$o);echo json_encode([str_contains($html,'a-draft-to-make-your-own'),str_contains($html,'a-scheduled-example'),str_contains($html,'a-restorable-example'),str_contains($html,'content-without-a-database')]);");
      assert.deepEqual(output,[false,false,false,true]);
      const codes=inspect(context,boot+"$c=$b->read()['data'];$o=(new DBLESSCMS\\Plugins($s))->state('blog')['settings'];$codes=[];foreach([true,false] as $preview){try{DBLESSCMS\\BlogView::document($s,'http://localhost',['kind'=>'article','article'=>$c['articles']['a-draft-to-make-your-own'],'catalog'=>$c,'preview'=>$preview],$o);}catch(DBLESSCMS\\HttpError $e){$codes[]=$e->status;}}echo json_encode($codes);");assert.deepEqual(codes,[403,404]);
    }finally{context.close();}
  });
  test(`${application}: explicit installation is additive and idempotent without replacing content or credentials`,()=>{
    const context=fixture(application);
    try{
      inspect(context,"$r=new DBLESSCMS\\Plugins($s);$state=$r->state('blog');$r->save('blog',false,$state['settings'],$r->document()['revision']);echo 'true';");
      const beforeContent=fs.readFileSync(path.join(context.contents,'blog/catalog.json')),beforeCredentials=fs.readFileSync(path.join(context.temporary,'app.json')),beforePlugins=JSON.parse(fs.readFileSync(path.join(context.contents,'plugins.json')));
      const result=spawnSync('php',['-d','disable_functions=mail',path.join(context.app,'system/plugins/blog/install.php'),'--enable'],{env:context.env,encoding:'utf8'});assert.equal(result.status,0,result.stderr);assert.equal(result.stderr,'');
      const afterPlugins=JSON.parse(fs.readFileSync(path.join(context.contents,'plugins.json')));assert.equal(afterPlugins.plugins.blog.enabled,true);delete beforePlugins.plugins.blog;delete afterPlugins.plugins.blog;assert.deepEqual(afterPlugins,beforePlugins);assert.deepEqual(fs.readFileSync(path.join(context.contents,'blog/catalog.json')),beforeContent);assert.deepEqual(fs.readFileSync(path.join(context.temporary,'app.json')),beforeCredentials);
      const first=fs.readFileSync(path.join(context.contents,'plugins.json'));const second=spawnSync('php',[path.join(context.app,'system/plugins/blog/install.php'),'--enable'],{env:context.env,encoding:'utf8'});assert.equal(second.status,0,second.stderr);assert.deepEqual(fs.readFileSync(path.join(context.contents,'plugins.json')),first);
    }finally{context.close();}
  });
  test(`${application}: public HTTP excludes private posts, applies template SEO/XML and protects authenticated preview`,async()=>{
    const sandbox=await server(application),request=client(sandbox.base);
    try{
      const list=await request('/plugins/blog/list');assert.equal(list.status,200);const html=await list.text();assert.match(html,/Designing with clarity/);assert.doesNotMatch(html,/A draft to make your own|A scheduled example|A restorable example|<svg\b/);
      for(const slug of ['a-draft-to-make-your-own','a-scheduled-example','a-restorable-example','missing'])assert.equal((await request('/plugins/blog/article?slug='+slug)).status,404,slug);
      assert.equal((await request('/plugins/blog/editor')).status,404);assert.equal((await request('/plugins/blog/article?slug[]=bad')).status,400);assert.equal((await request('/plugins/blog/list?page=0')).status,400);assert.equal((await request('/plugins/blog/list?page=9999')).status,404);
      const search=await request('/plugins/blog/list?q=database');assert.equal(search.status,200);assert.equal(search.headers.get('x-robots-tag'),'noindex, follow');
      const article=await request('/plugins/blog/article?slug=designing-with-clarity');const body=await article.text();assert.equal(article.status,200);assert.match(body,/itemtype="https:\/\/schema.org\/BlogPosting"/);assert.match(body,/og:type" content="article"/);assert.match(body,/article:published_time/);assert.match(body,/<span itemprop="name">Editorial Team<\/span>/);assert.match(body,/itemprop="position" content="2"/);assert.match(body,/&lt;sample&gt;/);assert.doesNotMatch(body,/<sample>/);assert.ok(!body.includes('Join the conversation'));
      for(const route of ['feed','sitemap']){const response=await request('/plugins/blog/'+route);assert.equal(response.status,200);assert.match(response.headers.get('content-type'),/xml/);const xml=await response.text();assert.match(xml,/designing-with-clarity/);assert.doesNotMatch(xml,/a-draft-to-make-your-own|a-scheduled-example|a-restorable-example/);}
      const previewPath='/cms-admin/?view=plugin&plugin=blog&route=preview&id=a-draft-to-make-your-own';assert.doesNotMatch(await (await request(previewPath)).text(),/Authenticated preview/);
      const csrf=await login(request);const integratedPreview=await request('/cms-admin/?view=plugin-panel&plugin=blog&route=preview&id=a-draft-to-make-your-own');assert.equal(integratedPreview.status,303);assert.equal(integratedPreview.headers.get('location'),sandbox.base+previewPath);const preview=await request(previewPath);assert.equal(preview.status,200);assert.match(preview.headers.get('x-robots-tag'),/noindex/);assert.match(await preview.text(),/Authenticated preview/);
      assert.equal((await request('/cms-admin/?view=plugin-api&plugin=blog&route=editor&operation=save',{method:'POST',body:new URLSearchParams({csrf_token:'forged'})})).status,403);
      assert.equal((await request('/cms-admin/?view=plugin-api&plugin=blog&route=overview&operation=save',{method:'POST',body:new URLSearchParams({csrf_token:csrf})})).status,404);
      inspect(sandbox,boot+"$b->mutate($b->read()['revision'],static function($d){$a=&$d['articles']['designing-with-clarity'];$a['aliases'][]=$a['slug'];$a['slug']='a-clearer-design';return $d;});echo 'true';");const old=await request('/plugins/blog/article?slug=designing-with-clarity');assert.equal(old.status,301);assert.equal(old.headers.get('location'),sandbox.base+'/plugins/blog/article?slug=a-clearer-design');
      for(const id of ['essencial',...(fs.existsSync(path.join(sandbox.app,'system/templates/jepasaDotCom'))?['jepasaDotCom']:[])]){
        inspect(sandbox,"$d=$s->read('site');$d['data']['template']='"+id+"';$s->save('site',DBLESSCMS\\JsonStore::encode($d['data']),$d['revision']);echo 'true';");const response=await request('/plugins/blog/article?slug=a-clearer-design');assert.equal(response.status,200);const markup=await response.text();assert.match(markup,/og:type" content="article"/);assert.match(markup,/rel="canonical" href="[^"]*plugins\/blog\/article\?slug=a-clearer-design"/);
      }
      inspect(sandbox,"$p=new DBLESSCMS\\Plugins($s);$state=$p->state('blog');$p->save('blog',false,$state['settings'],$p->document()['revision']);echo 'true';");assert.equal((await request('/plugins/blog/list')).status,404);assert.equal((await request('/cms-admin/?view=plugin-panel&plugin=blog&route=settings')).status,200);
      assert.doesNotMatch(sandbox.logs(),/PHP (?:Warning|Fatal|Parse)/);
    }finally{await sandbox.close();}
  });
  test(`${application}: comments require CSRF/origin/consent and approval, reject duplicates and enforce submission quota`,async()=>{
    const sandbox=await server(application),reader=client(sandbox.base),admin=client(sandbox.base);
    try{
      inspect(sandbox,"$p=new DBLESSCMS\\Plugins($s);$state=$p->state('blog');$state['settings']['discussion']['enabled']=true;$p->saveOptions('blog',$state['settings'],$p->document()['revision']);echo 'true';");
      const html=await (await reader('/plugins/blog/article?slug=designing-with-clarity')).text(),token=field(html,'csrf_token');
      const values={article:'designing-with-clarity',name:'Synthetic Reader',body:'A useful example, thank you.',consent:'1',csrf_token:token,website:''};
      assert.equal((await reader('/plugins/blog/comment',{method:'POST',body:new URLSearchParams({...values,csrf_token:'forged'})})).status,403);
      assert.equal((await reader('/plugins/blog/comment',{method:'POST',headers:{Origin:'https://outside.test'},body:new URLSearchParams(values)})).status,403);
      assert.equal((await reader('/plugins/blog/comment',{method:'POST',body:new URLSearchParams({...values,consent:'0'})})).status,422);
      const submitted=await reader('/plugins/blog/comment',{method:'POST',body:new URLSearchParams(values)});assert.equal(submitted.status,303);assert.match(submitted.headers.get('location'),/submitted=1#comments$/);
      assert.doesNotMatch(await (await reader('/plugins/blog/article?slug=designing-with-clarity')).text(),/A useful example, thank you\./);
      const comments=JSON.parse(fs.readFileSync(path.join(sandbox.contents,'blog/comments.json')));const item=Object.values(comments.comments)[0];assert.equal(item.status,'pending');assert.deepEqual(Object.keys(item).sort(),['article','body','createdAt','id','name','status'].sort());
      assert.equal((await reader('/plugins/blog/comment',{method:'POST',body:new URLSearchParams(values)})).status,409);
      const csrf=await login(admin),moderation=await (await admin('/cms-admin/?view=plugin-panel&plugin=blog&route=comments')).text();
      const result=await admin('/cms-admin/?view=plugin-api&plugin=blog&route=comments&operation=moderate',{method:'POST',body:new URLSearchParams({csrf_token:csrf,revision:field(moderation,'revision'),id:item.id,status:'approved'})});assert.equal(result.status,200);assert.match(await (await reader('/plugins/blog/article?slug=designing-with-clarity')).text(),/A useful example, thank you\./);
      for(let index=0;index<3;index++)assert.equal((await reader('/plugins/blog/comment',{method:'POST',body:new URLSearchParams({...values,body:'Honeypot '+index,website:'bot'})})).status,303);
      assert.equal((await reader('/plugins/blog/comment',{method:'POST',body:new URLSearchParams({...values,body:'Over quota'})})).status,429);assert.equal(Object.keys(JSON.parse(fs.readFileSync(path.join(sandbox.contents,'blog/comments.json'))).comments).length,1);
      assert.doesNotMatch(sandbox.logs(),/PHP (?:Warning|Fatal|Parse)/);
    }finally{await sandbox.close();}
  });
}
