/** Editorial storage contracts in synthetic source/package fixtures: publication boundaries, export references, data-only queries and locale parity. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {applications,fixture,inspect} from './helpers/extension-media.mjs';
for(const application of applications){
  test(`${path.basename(application)}: export and recovery preserve publication boundaries and copy only referenced public media`,()=>{
    const c=fixture(application);try{
      const media=path.join(c.app,'public_html/media');fs.mkdirSync(media,{recursive:true});fs.copyFileSync(path.join(c.app,'public_html/templates/essencial/essencial.png'),path.join(media,'chosen.png'));fs.copyFileSync(path.join(media,'chosen.png'),path.join(media,'unreferenced.png'));
      const result=inspect(c,`
        $r=new DBLESSCMS\\PagePublications($s);$site=$s->read('site')['revision'];
        $created=$r->change('','create',['model'=>'blank','snapshot'=>DBLESSCMS\\PageBlueprints::create('blank','Export fixture','export-fixture')],$r->read()['revision'],$site);$id=$created['id'];
        $snapshot=$r->record($id)['draft'];$snapshot['document']['markup']['html']='<p>Authorized snapshot</p><img src="media/chosen.png" alt="Chosen"><video controls src="https://media.example.test/clip.mp4"></video>';
        $r->change($id,'save',['snapshot'=>$snapshot],$r->read()['revision'],$site);$r->change($id,'publish',[],$r->read()['revision'],$site);
        $snapshot['document']['markup']['html']='<p>PRIVATE-WORKING-CANARY</p>';$r->change($id,'save',['snapshot'=>$snapshot],$r->read()['revision'],$site);
        $target=$s->config->data['storage'].'/export';$r->export($target);DBLESSCMS\\EditorialMedia::export($s,$target);$export=file_get_contents($target.'/system/storage/contents/page-publications/catalog.json');
        $codes=[];foreach(['create','save','remove'] as $action){try{if($action==='create')$s->create('export-fixture','starter-page');elseif($action==='save')$s->save($id,DBLESSCMS\\JsonStore::encode($s->read($id)['data']),$s->read($id)['revision']);else $s->remove($id,$s->read($id)['revision']);$codes[]=200;}catch(DBLESSCMS\\HttpError $e){$codes[]=$e->status;}}
        $r->change($id,'trash',[],$r->read()['revision'],$site);$backup=array_key_first($r->history($id));$r->change($id,'restore',['backup'=>$backup],$r->read()['revision'],$site);
        echo json_encode(['export'=>$export,'copied'=>is_file($target.'/public_html/media/chosen.png'),'omitted'=>!file_exists($target.'/public_html/media/unreferenced.png'),'codes'=>$codes,'live'=>DBLESSCMS\\PagePublications::effective($r->record($id))]);
      `);
      assert.match(result.export,/Authorized snapshot/);assert.doesNotMatch(result.export,/PRIVATE-WORKING-CANARY/);assert.equal(result.copied,true);assert.equal(result.omitted,true);assert.deepEqual(result.codes,[409,409,409]);assert.equal(result.live,null);
    }finally{c.close();}
  });
  test(`${path.basename(application)}: data bindings distinguish empty and unavailable, prohibit raw data markers and expose no private fields`,()=>{
    const c=fixture(application);try{const result=inspect(c,`
      $context=new DBLESSCMS\\ContentContext($s,'essencial','','home');$components=new DBLESSCMS\\Components($context);
      $parameters=['limit'=>10,'page'=>1,'sort'=>'title','direction'=>'asc'];$records=$components->data('pages','records',$parameters);$components->environment([['plugin'=>'pages','service'=>'details','alias'=>'empty','parameters'=>['id'=>'does-not-exist']],['plugin'=>'missing','service'=>'none','alias'=>'absent','parameters'=>[]]]);
      try{$components->inspect('{{pages:records}}');$raw=200;}catch(DBLESSCMS\\HttpError $e){$raw=$e->status;}
      $unsafe=[];foreach(['<video src="javascript:alert(1)"></video>','<video src="/cms-admin/?view=media-library"></video>','<video onerror="alert(1)"></video>'] as $html){try{DBLESSCMS\\ContentMarkup::html($html);$unsafe[]=200;}catch(DBLESSCMS\\HttpError $e){$unsafe[]=$e->status;}}
      echo json_encode(['records'=>$records,'states'=>$components->states(),'raw'=>$raw,'unsafe'=>$unsafe,'html'=>DBLESSCMS\\ContentMarkup::html('<a href="https://example.test" target="_blank" rel="ugc">Link</a>',false)]);
    `);assert.ok(result.records.items.length>0);assert.deepEqual(Object.keys(result.records.items[0]),['id','title','summary','url','image','parentId','author','order','createdAt','updatedAt','isCurrent']);assert.deepEqual(result.states,{empty:{available:true,empty:true},absent:{available:false,empty:false}});assert.equal(result.raw,422);assert.deepEqual(result.unsafe,[422,422,422]);assert.match(result.html,/rel="ugc noopener noreferrer"/);
    }finally{c.close();}
  });
  test(`${path.basename(application)}: changed core and plugin dictionaries retain matching keys and placeholders`,()=>{
    for(const directory of ['system/languages',...['posts','homepage','slides','files-manager'].map(id=>'system/plugins/'+id+'/languages')]){
      const locales=['pt-br','en-us'].map(locale=>JSON.parse(fs.readFileSync(path.join(application,directory,locale+'.json'))).messages);assert.deepEqual(Object.keys(locales[0]).sort(),Object.keys(locales[1]).sort(),directory);
      for(const key of Object.keys(locales[0]))assert.deepEqual((locales[0][key].match(/\{[a-zA-Z0-9_]+\}/g)||[]).sort(),(locales[1][key].match(/\{[a-zA-Z0-9_]+\}/g)||[]).sort(),directory+': '+key);
    }
  });
}
