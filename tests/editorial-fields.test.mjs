/** Editorial field parity on disposable source/package copies: public snapshot isolation, review state, ordering, compatibility and discussion enforcement. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {applications,fixture,inspect,server} from './helpers/extension-media.mjs';
for(const application of applications){
  test(`${application}: Pages review, byline and order remain snapshot-bound with legacy-client preservation`,()=>{
    const c=fixture(application);try{
      const result=inspect(c,`
        $r=new DBLESSCMS\\PagePublications($s);$site=$s->read('site')['revision'];$snapshot=DBLESSCMS\\PageBlueprints::create('blank','Field parity','field-parity');$snapshot['editorial']=['author'=>'Public Author','order'=>-2,'review'=>true];
        $id=$r->change('','create',['snapshot'=>$snapshot,'model'=>'blank'],$r->read()['revision'],$site)['id'];$pending=DBLESSCMS\\PagePublications::status($r->record($id));
        $context=new DBLESSCMS\\ContentContext($s,'essencial','','home');$services=new DBLESSCMS\\Components($context);$parameters=['limit'=>50,'page'=>1,'sort'=>'order','direction'=>'asc'];$before=$services->data('pages','records',$parameters);
        $r->change($id,'publish',['publishAt'=>'2020-01-01T00:00:00Z'],$r->read()['revision'],$site);$record=$r->record($id);$snapshot=$record['draft'];$snapshot['editorial']=['author'=>'PRIVATE DRAFT AUTHOR','order'=>99,'review'=>true];$r->change($id,'save',['snapshot'=>$snapshot],$r->read()['revision'],$site);
        $services=new DBLESSCMS\\Components($context);$live=$services->data('pages','details',['id'=>$id]);$ordered=$services->data('pages','records',$parameters);unset($snapshot['editorial']);$r->change($id,'save',['snapshot'=>$snapshot],$r->read()['revision'],$site);$preserved=$r->record($id)['draft']['editorial'];
        $codes=[];foreach([['author'=>[]],['order'=>'2'],['order'=>1000000],['review'=>1],['unexpected'=>true]] as $bad){try{DBLESSCMS\\PagePublications::editorial($bad);$codes[]=200;}catch(DBLESSCMS\\HttpError $e){$codes[]=$e->status;}}
        $r->change($id,'publish',['publishAt'=>'2099-01-01T00:00:00Z'],$r->read()['revision'],$site);$scheduled=DBLESSCMS\\PagePublications::status($r->record($id));
        echo json_encode(compact('id','pending','before','record','live','ordered','preserved','codes','scheduled'));
      `);
      assert.equal(result.pending,'pending');assert.ok(!result.before.items.some(row=>row.id===result.id));assert.equal(result.record.publishAt,'2020-01-01T00:00:00Z');assert.equal(result.record.draft.editorial.review,false);assert.equal(result.live.items[0].author,'Public Author');assert.equal(result.live.items[0].order,-2);assert.equal(result.ordered.items[0].id,result.id);assert.equal(result.preserved.author,'PRIVATE DRAFT AUTHOR');assert.equal(result.preserved.order,99);assert.deepEqual(result.codes,[422,422,422,422,422]);assert.equal(result.scheduled,'scheduled');
    }finally{c.close();}
  });
  test(`${application}: Blog pending articles are nonpublic and old catalogs gain additive discussion defaults without writes`,()=>{
    const c=fixture(application);try{
      const file=path.join(c.contents,'blog/catalog.json'),legacy=JSON.parse(fs.readFileSync(file));for(const article of Object.values(legacy.articles))delete article.allowComments;fs.writeFileSync(file,JSON.stringify(legacy));const before=fs.readFileSync(file);
      const result=inspect(c,`(new DBLESSCMS\\Plugins($s))->instance('blog');$b=new DBLESSCMS\\BlogStore($s);$d=$b->read()['data'];$default=$d['articles']['designing-with-clarity']['allowComments'];$d['articles']['designing-with-clarity']['status']='pending';$d=DBLESSCMS\\BlogModel::catalog($d);$status=DBLESSCMS\\BlogModel::status($d['articles']['designing-with-clarity']);$public=DBLESSCMS\\BlogModel::published($d);$d['articles']['designing-with-clarity']['allowComments']='false';try{DBLESSCMS\\BlogModel::catalog($d);$invalid=200;}catch(DBLESSCMS\\HttpError $e){$invalid=$e->status;}echo json_encode([$default,$status,isset($public['designing-with-clarity']),$invalid]);`);
      assert.deepEqual(result,[true,'pending',false,422]);assert.deepEqual(fs.readFileSync(file),before);
    }finally{c.close();}
  });
  test(`${application}: closed article retains approved discussion but rejects a previously issued valid submission`,async()=>{
    const c=await server(application);let cookie='';
    /** Request this synthetic origin only; receives route/options, returns response while retaining the fixture cookie without logging it. */
    async function request(route,options={}){const response=await fetch(c.base+route,{...options,redirect:'manual',headers:{Cookie:cookie,...options.headers}});for(const header of response.headers.getSetCookie())if(header.startsWith('DBLESSCMSSESSID='))cookie=header.split(';')[0];return response;}
    try{
      inspect(c,"$p=new DBLESSCMS\\Plugins($s);$o=$p->state('blog')['settings'];$o['discussion']['enabled']=true;$p->saveOptions('blog',$o,$p->document()['revision']);echo 'true';");
      const html=await(await request('/plugins/blog/article?slug=designing-with-clarity')).text(),token=html.match(/name="csrf_token" value="([^"]+)"/)[1];
      inspect(c,"(new DBLESSCMS\\Plugins($s))->instance('blog');$b=new DBLESSCMS\\BlogStore($s);$b->mutate($b->read()['revision'],static function($d){$d['articles']['designing-with-clarity']['allowComments']=false;return $d;});$b=new DBLESSCMS\\BlogStore($s,'comments');$b->mutate($b->read()['revision'],static function($d){$d['comments']['approved-fixture']=['id'=>'approved-fixture','article'=>'designing-with-clarity','name'=>'Fixture Reader','body'=>'Previously approved contribution','status'=>'approved','createdAt'=>'2020-01-01T00:00:00Z'];return $d;});echo 'true';");
      const closed=await(await request('/plugins/blog/article?slug=designing-with-clarity')).text();assert.match(closed,/Previously approved contribution/);assert.doesNotMatch(closed,/class="blog-comment-form"/);
      const response=await request('/plugins/blog/comment',{method:'POST',body:new URLSearchParams({csrf_token:token,article:'designing-with-clarity',name:'Fixture Reader',body:'A late submission',consent:'1',website:''})});assert.equal(response.status,404);assert.equal(Object.keys(JSON.parse(fs.readFileSync(path.join(c.contents,'blog/comments.json'))).comments).length,1);
    }finally{await c.close();}
  });
}
