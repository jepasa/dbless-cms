/** Unified publication regressions in disposable source/package fixtures: route/type/term contracts, explicit adoption, snapshot isolation, query safety and export boundaries. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {applications,fixture,inspect} from './helpers/extension-media.mjs';
/** Enable the new plugin only inside the fixture; receive isolated context, preserves real activation/content and returns nothing. */
function enable(c){const file=path.join(c.contents,'plugins.json'),data=JSON.parse(fs.readFileSync(file));data.plugins.posts={enabled:true,settings:{browser:{pageSize:25}}};fs.writeFileSync(file,JSON.stringify(data,null,2));}
for(const application of applications){
  test(`${path.basename(application)}: Posts adopts native records explicitly and keeps saved drafts away from public providers/export`,()=>{
    const c=fixture(application);try{enable(c);const result=inspect(c,`
      $r=new DBLESSCMS\\Posts($s);$before=hash('sha256',file_get_contents($s->config->data['contents'].'/blog/catalog.json'));$originalPages=[];foreach(glob($s->config->data['contents'].'/pages/*.json') as $file)$originalPages[basename($file)]=hash_file('sha256',$file);
      $rows=$r->records();$absent=!file_exists($s->config->data['contents'].'/posts/catalog.json');$blogRows=array_filter($rows,static fn($row)=>$row['source']['plugin']==='blog'&&DBLESSCMS\\Posts::effective($row)!==null);$row=reset($blogRows);$id=$row['id'];$source=$row['source']['id'];$initial=$row['draft'];$draft=$initial;$draft['document']['markup']=['html'=>'<p>PRIVATE-POST-DRAFT</p>','bindings'=>[]];$draft['authored']=true;
      $change=function($action,$id,$input=[])use($r,$s){return $r->change($action,$id,$input,$r->read()['revision'],$s->read('site')['revision'],$r->sourceRevision());};
      $change('save',$id,['snapshot'=>$draft]);$rawUnchanged=$before===hash('sha256',file_get_contents($s->config->data['contents'].'/blog/catalog.json'));
      $blog=(new DBLESSCMS\\BlogStore($s))->read()['data'];$publicBefore=json_encode($blog['articles'][$source]);$guard=0;try{(new DBLESSCMS\\Posts($s))->guard('blog',$source);}catch(DBLESSCMS\\HttpError $e){$guard=$e->status;}
      $change('publish',$id);$published=(new DBLESSCMS\\BlogStore($s))->read()['data']['articles'][$source];$draft['document']['markup']['html']='<p>PRIVATE-POST-SECOND-DRAFT</p>';$change('save',$id,['snapshot'=>$draft]);
      $target=$s->config->data['storage'].'/posts-export';$r->export($target);$export=file_get_contents($target.'/system/storage/contents/posts/catalog.json');
      $change('schedule',$id,['publishAt'=>gmdate('Y-m-d\\TH:i:s\\Z',time()+3600)]);$record=$r->record($id);$future=DBLESSCMS\\Posts::effective($record,time()+7200);$current=DBLESSCMS\\Posts::effective($record);$scheduledRaw=hash_file('sha256',$s->config->data['contents'].'/posts/catalog.json');$r->search([]);$noReadWrite=$scheduledRaw===hash_file('sha256',$s->config->data['contents'].'/posts/catalog.json');
      $page=array_values(array_filter($r->records(),static fn($row)=>$row['source']['plugin']==='pages'))[0];$pageDraft=$page['draft'];$pageDraft['document']['markup']=['html'=>'<p>PAGE-ADOPTION-DRAFT</p>','bindings'=>[]];$change('save',$page['id'],['snapshot'=>$pageDraft]);$pageBefore=$s->read($page['source']['id'])['data'];$change('publish',$page['id']);$pageAfter=$s->read($page['source']['id'])['data'];
      $pageUnchanged=true;foreach($originalPages as $name=>$hash)if(hash_file('sha256',$s->config->data['contents'].'/pages/'.$name)!==$hash)$pageUnchanged=false;
      echo json_encode(compact('absent','rawUnchanged','publicBefore','guard','published','export','current','future','noReadWrite','pageBefore','pageAfter','pageUnchanged'));
    `);assert.equal(result.absent,true);assert.equal(result.rawUnchanged,true);assert.doesNotMatch(result.publicBefore,/PRIVATE-POST-DRAFT/);assert.equal(result.guard,409);assert.match(JSON.stringify(result.published),/PRIVATE-POST-DRAFT/);assert.match(result.export,/PRIVATE-POST-DRAFT/);assert.doesNotMatch(result.export,/PRIVATE-POST-SECOND-DRAFT/);assert.match(JSON.stringify(result.current),/PRIVATE-POST-DRAFT/);assert.doesNotMatch(JSON.stringify(result.current),/PRIVATE-POST-SECOND-DRAFT/);assert.match(JSON.stringify(result.future),/PRIVATE-POST-SECOND-DRAFT/);assert.equal(result.noReadWrite,true);assert.doesNotMatch(JSON.stringify(result.pageBefore),/PAGE-ADOPTION-DRAFT/);assert.match(JSON.stringify(result.pageAfter),/PAGE-ADOPTION-DRAFT/);assert.equal(result.pageUnchanged,true);
    }finally{c.close();}
  });
  test(`${path.basename(application)}: Posts type/term routes enforce immutable capabilities, collisions, stale revisions and bounded combined queries`,()=>{
    const c=fixture(application);try{enable(c);const codes=inspect(c,`
      $r=new DBLESSCMS\\Posts($s);$change=function($action,$id,$input=[])use($r,$s){return $r->change($action,$id,$input,$r->read()['revision'],$s->read('site')['revision'],$r->sourceRevision());};$codes=[];
      foreach(['../escape','cms-admin/x','plugins/blog','media/images','foo?bar','foo//bar'] as $path){try{DBLESSCMS\\PostTypes::path($path);$codes[]=200;}catch(DBLESSCMS\\HttpError $e){$codes[]=$e->status;}}
      $type=DBLESSCMS\\PostTypes::defaults()['page'];$type['id']='project';$type['singular']='Project';$type['plural']='Projects';$type['routeMode']='custom';$type['prefix']='projects';$change('type-save','',['type'=>$type]);
      $change('term-save','parent',['kind'=>'groups','original'=>'','term'=>['id'=>'parent','name'=>'Parent','description'=>'','parent'=>'','deleted'=>false]]);$change('term-save','child',['kind'=>'groups','original'=>'','term'=>['id'=>'child','name'=>'Child','description'=>'','parent'=>'parent','deleted'=>false]]);
      foreach(['alpha','beta'] as $tag)$change('term-save',$tag,['kind'=>'tags','original'=>'','term'=>['id'=>$tag,'name'=>$tag,'description'=>'','parent'=>'','deleted'=>false]]);
      echo json_encode($codes);
    `);const result=inspect(c,`
      $r=new DBLESSCMS\\Posts($s);$change=function($action,$id,$input=[])use($r,$s){return $r->change($action,$id,$input,$r->read()['revision'],$s->read('site')['revision'],$r->sourceRevision());};$type=$r->read()['data']['types']['project'];
      $native=array_values(array_filter($r->records(),static fn($row)=>$row['source']['plugin']==='pages'))[0]['draft'];$native['type']='project';$native['slug']='example';$native['groups']=['child'];$native['tags']=['alpha'];$native['document']['meta']['title']='Needle project';$native['document']['markup']=['html'=>'<p>Visible body</p>','bindings'=>[]];$created=$change('create','',['snapshot'=>$native]);$id=$created['id'];$change('publish',$id);
      $query=$r->search(['q'=>'needle','type'=>'project','group'=>'parent','tags'=>'alpha,beta']);$miss=$r->search(['q'=>'needle','type'=>'page','group'=>'parent','tags'=>'alpha']);
      $conflicts=[];foreach([['type-remove','project',[]],['term-remove','child',['kind'=>'groups']],['term-remove','alpha',['kind'=>'tags']],['settings','',['searchPath'=>'projects']]] as [$action,$key,$input]){try{$change($action,$key,$input);$conflicts[]=200;}catch(DBLESSCMS\\HttpError $e){$conflicts[]=$e->status;}}
      $oldUrl=$r->url($r->record($id));$type['prefix']='work';$change('type-save','project',['type'=>$type]);$record=$r->record($id);$newUrl=$r->url($record);$alias=in_array('projects/example',$record['aliases'],true);
      $stale=0;try{$r->change('settings','',['searchPath'=>'find'],hash('sha256','stale'),$s->read('site')['revision'],$r->sourceRevision());}catch(DBLESSCMS\\HttpError $e){$stale=$e->status;}
      echo json_encode(compact('query','miss','conflicts','oldUrl','newUrl','alias','stale'));
    `);assert.deepEqual(codes,[422,422,422,422,422,422]);assert.equal(result.query.total,1);assert.equal(result.query.items[0].type,'project');assert.equal(result.miss.total,0);assert.deepEqual(result.conflicts,[409,409,409,409]);assert.equal(result.oldUrl,'/projects/example');assert.equal(result.newUrl,'/work/example');assert.equal(result.alias,true);assert.equal(result.stale,409);
    }finally{c.close();}
  });
  test(`${path.basename(application)}: Posts declarations, navigation replacement, locales and tutorial outline are valid`,()=>{
    const c=fixture(application);try{enable(c);const result=inspect(c,`$p=new DBLESSCMS\\Plugins($s);$nav=$p->navigation('en-us');$dependency=0;try{(new DBLESSCMS\\Extensions($s))->uninstall('plugins','pages',$s->read('plugins')['revision'],'pages');}catch(DBLESSCMS\\HttpError $e){$dependency=$e->status;}echo json_encode(['dependency'=>$dependency,'invalid'=>$p->invalid(),'posts'=>isset($nav['posts']),'pages'=>isset($nav['pages']),'blog'=>isset($nav['blog'])]);`);assert.deepEqual(result,{dependency:409,invalid:[],posts:true,pages:false,blog:false});
      const dir=path.join(application,'system/plugins/posts'),en=JSON.parse(fs.readFileSync(path.join(dir,'languages/en-us.json'))),pt=JSON.parse(fs.readFileSync(path.join(dir,'languages/pt-br.json')));assert.deepEqual(Object.keys(en.messages).sort(),Object.keys(pt.messages).sort());for(const key in en.messages)assert.deepEqual(en.messages[key].match(/\{\w+\}/g)||[],pt.messages[key].match(/\{\w+\}/g)||[]);
      const canonical=JSON.parse(fs.readFileSync(path.join(dir,'presentation.json'))),localized=JSON.parse(fs.readFileSync(path.join(dir,'languages/presentation-pt-br.json')));assert.deepEqual(canonical.sections.map(x=>x.id),localized.sections.map(x=>x.id));
    }finally{c.close();}
  });
  test(`${path.basename(application)}: Posts export omits unused native drafts, suppresses adopted trash and copies reserved-base literal media`,()=>{
    const c=fixture(application);try{enable(c);const media=path.join(c.app,'public_html/media');fs.mkdirSync(media,{recursive:true});fs.copyFileSync(path.join(c.app,'public_html/templates/essencial/essencial.png'),path.join(media,'publication.png'));const result=inspect(c,`
      $r=new DBLESSCMS\\Posts($s);$change=function($action,$id,$input=[])use($r,$s){return $r->change($action,$id,$input,$r->read()['revision'],$s->read('site')['revision'],$r->sourceRevision());};
      $source=array_values(array_filter($r->records(),static fn($row)=>$row['source']['plugin']==='blog'&&DBLESSCMS\\Posts::effective($row)!==null))[0];$snap=$source['draft'];$snap['slug']='export-native-boundary';$snap['document']['meta']['image']='';$snap['blocks']=[['type'=>'paragraph','text'=>'UNUSED-PRIVATE-NATIVE-CANARY']];$snap['authored']=true;$snap['document']['markup']=['html'=>'<p>AUTHORIZED-PUBLIC-BODY</p><img src="{{cms.base}}/media/publication.png" alt="Publication">','bindings'=>[]];$created=$change('create','',['snapshot'=>$snap]);$id=$created['id'];$change('publish',$id);
      $target=$s->config->data['storage'].'/full-export';$r->export($target);DBLESSCMS\\EditorialMedia::export($s,$target);$export=file_get_contents($target.'/system/storage/contents/posts/catalog.json');$private=str_contains(file_get_contents($s->config->data['contents'].'/posts/catalog.json'),'UNUSED-PRIVATE-NATIVE-CANARY');
      $change('trash',$source['id']);require $argv[1].'/system/plugins/blog/Export.php';DBLESSCMS\\BlogExport::run($s,$target);$native=json_decode(file_get_contents($target.'/system/storage/contents/blog/catalog.json'),true);$omitted=!isset($native['articles'][$source['source']['id']]);$change('recover',$source['id']);$recoverPrivate=DBLESSCMS\\Posts::effective($r->record($source['id']))===null;
      echo json_encode(['export'=>$export,'private'=>$private,'copied'=>is_file($target.'/public_html/media/publication.png'),'omitted'=>$omitted,'recoverPrivate'=>$recoverPrivate]);
    `);assert.match(result.export,/AUTHORIZED-PUBLIC-BODY/);assert.doesNotMatch(result.export,/UNUSED-PRIVATE-NATIVE-CANARY/);assert.equal(result.private,true);assert.equal(result.copied,true);assert.equal(result.omitted,true);assert.equal(result.recoverPrivate,true);
    }finally{c.close();}
  });

  test(`${path.basename(application)}: native history restores only the unified draft while source bytes and current publication remain unchanged`,()=>{
    const c=fixture(application);try{enable(c);const result=inspect(c,`
      $registry=new DBLESSCMS\\Plugins($s);$registry->instance('blog');$b=new DBLESSCMS\\BlogStore($s);$before=$b->read(true);$sourceId='designing-with-clarity';$original=$before['data']['articles'][$sourceId]['title'];$b->mutate($before['revision'],static function($data)use($sourceId){$data['articles'][$sourceId]['title']='Native current title';return $data;});$sourceHash=hash_file('sha256',$s->config->data['contents'].'/blog/catalog.json');
      $r=new DBLESSCMS\\Posts($s);$id=DBLESSCMS\\Posts::identity('blog',$sourceId);$change=function($action,$input=[])use($r,$s,$id){return $r->change($action,$id,$input,$r->read()['revision'],$s->read('site')['revision'],$r->sourceRevision());};$snapshot=$r->record($id)['draft'];$snapshot['document']['meta']['title']='Unified current title';$change('save',['snapshot'=>$snapshot]);$change('publish');$native=$r->nativeHistory($r->record($id));$backup=array_key_first($native);$change('restore-native',['backup'=>$backup]);$row=$r->record($id);
      $page=array_values(array_filter($r->records(),static fn($row)=>$row['source']['plugin']==='pages'))[0];$key=$page['source']['id'];$opened=$s->read($key);$doc=$opened['data'];$pageOriginal=$doc['meta']['title'];$doc['meta']['title']='Native page current';$s->save($key,DBLESSCMS\\JsonStore::encode($doc),$opened['revision']);$nativePages=$r->nativeHistory($r->record($page['id']));
      echo json_encode(['original'=>$original,'draft'=>$row['draft']['document']['meta']['title'],'published'=>DBLESSCMS\\Posts::effective($row)['document']['meta']['title'],'sourceUnchanged'=>$sourceHash===hash_file('sha256',$s->config->data['contents'].'/blog/catalog.json'),'pageOriginal'=>$pageOriginal,'pageHistory'=>array_column(array_column($nativePages,'document'),'meta')]);
    `);assert.equal(result.draft,result.original);assert.equal(result.published,'Unified current title');assert.equal(result.sourceUnchanged,true);assert.ok(result.pageHistory.some(meta=>meta.title===result.pageOriginal));
    }finally{c.close();}
  });

}
