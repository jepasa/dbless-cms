/** Menu source/destination regressions: released Blog IDs, current slugs, unavailable-source preservation and strict typed payloads in disposable applications. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {applications,fixture,inspect} from './helpers/extension-media.mjs';
for(const application of applications){
 test(`${application}: Blog Menu targets follow immutable IDs and omit unavailable publications without GET writes`,()=>{
  const c=fixture(application);try{
   const blogFile=path.join(c.contents,'blog/catalog.json'),catalog=JSON.parse(fs.readFileSync(blogFile)),id=Object.keys(catalog.articles).find(key=>catalog.articles[key].status==='published'),article=catalog.articles[id];article.slug='renamed-public-post';article.aliases=[];fs.writeFileSync(blogFile,JSON.stringify(catalog));
   const before=fs.readFileSync(blogFile),result=inspect(c,`$target=new DBLESSCMS\\MenuTargets(new DBLESSCMS\\ContentContext($s,'essencial','','home'));$choices=$target->posts();$node=['id'=>'post-link','parent'=>'','label'=>'Stable post','kind'=>'post','page'=>'${id}','anchor'=>'','url'=>'','enabled'=>true,'newTab'=>true];DBLESSCMS\\MenuStore::items([$node]);$tree=(new DBLESSCMS\\Navigation($s,'essencial',''))->tree([$node]);echo json_encode([$choices,$tree]);`);
   assert.ok(result[0][id]);assert.equal(result[1][0].slug,'renamed-public-post');assert.match(result[1][0].href,/renamed-public-post/);assert.deepEqual(fs.readFileSync(blogFile),before);
   article.status='draft';fs.writeFileSync(blogFile,JSON.stringify(catalog));const absent=inspect(c,`$target=new DBLESSCMS\\MenuTargets(new DBLESSCMS\\ContentContext($s,'essencial','','home'));$node=['id'=>'post-link','parent'=>'','label'=>'Hidden','kind'=>'post','page'=>'${id}','anchor'=>'','url'=>'','enabled'=>true,'newTab'=>false];echo json_encode([$target->post('${id}'),(new DBLESSCMS\\Navigation($s,'essencial',''))->tree([$node])]);`);assert.deepEqual(absent,[null,[]]);
   article.status='published';article.publishedAt='2099-01-01T00:00:00Z';fs.writeFileSync(blogFile,JSON.stringify(catalog));assert.equal(inspect(c,`echo json_encode((new DBLESSCMS\\MenuTargets(new DBLESSCMS\\ContentContext($s,'essencial','','home')))->post('${id}'));`),null);
   article.publishedAt='2020-01-01T00:00:00Z';fs.writeFileSync(blogFile,JSON.stringify(catalog));const pluginsFile=path.join(c.contents,'plugins.json'),plugins=JSON.parse(fs.readFileSync(pluginsFile));plugins.plugins.posts={...inspect(c,"echo json_encode((new DBLESSCMS\\Plugins($s))->state('posts'));"),enabled:false};fs.writeFileSync(pluginsFile,JSON.stringify(plugins));const disabled=inspect(c,`$target=new DBLESSCMS\\MenuTargets(new DBLESSCMS\\ContentContext($s,'essencial','','home'));echo json_encode([$target->posts(),$target->post('${id}')]);`);assert.deepEqual(disabled,[[],null]);
  }finally{c.close();}
 });
 test(`${application}: source choices cover the bounded Blog catalog and reject post URL/anchor confusion`,()=>{
  const c=fixture(application);try{
   const file=path.join(c.contents,'blog/catalog.json'),catalog=JSON.parse(fs.readFileSync(file)),sample=Object.values(catalog.articles).find(row=>row.status==='published');catalog.articles={};for(let n=0;n<110;n++){const id='post-'+n;catalog.articles[id]={...structuredClone(sample),id,slug:id,aliases:[],publishedAt:'2020-01-01T00:00:00Z'};}catalog.articles.site={...catalog.articles['post-0'],id:'site',slug:'site'};delete catalog.articles['post-0'];fs.writeFileSync(file,JSON.stringify(catalog));
   const result=inspect(c,`$choices=(new DBLESSCMS\\MenuTargets(new DBLESSCMS\\ContentContext($s,'essencial','','home')))->posts();$bad=[];$node=['id'=>'item','parent'=>'','label'=>'Post','kind'=>'post','page'=>'post-1','anchor'=>'','url'=>'','enabled'=>true,'newTab'=>false];foreach(['url','anchor'] as $field){$copy=$node;$copy[$field]='forged';try{DBLESSCMS\\MenuStore::items([$copy]);$bad[]=200;}catch(DBLESSCMS\\HttpError $e){$bad[]=$e->status;}}$node['page']='site';$depth=DBLESSCMS\\MenuStore::items([$node]);$node['kind']='page';try{DBLESSCMS\\MenuStore::items([$node]);$bad[]=200;}catch(DBLESSCMS\\HttpError $e){$bad[]=$e->status;}echo json_encode([count($choices),array_keys($choices['post-109']),$bad,$depth,isset($choices['site'])]);`);assert.deepEqual(result,[110,['name','slug','href'],[422,422,422],1,true]);
  }finally{c.close();}
 });
}
