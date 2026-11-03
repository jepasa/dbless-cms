/** Synthetic privacy/Forms regressions: real PHP contracts, per-request composition, noncollecting defaults and minimal transport receipts; no live data or outgoing mail. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {applications,fixture,inspect,server} from './helpers/extension-media.mjs';

for (const application of applications) {
  const tree = application.includes('system/storage') ? 'package' : path.basename(application);
  test(`${tree}: Forms manifest, separate data contract and safe interactive slots`, () => {
    const c=fixture(application);
    try {
      const result=inspect(c,`$p=new DBLESSCMS\\Plugins($s);$ctx=new DBLESSCMS\\ContentContext($s,'essencial','http://localhost.test','home');$p->instance('forms');$data=$p->instance('forms')->content('definition',['id'=>'contact-example'],$ctx);$html=DBLESSCMS\\ContentMarkup::render(['html'=>'<section>{{forms:form id="contact-example"}}</section>','bindings'=>[]],$ctx);$preview=DBLESSCMS\\ContentMarkup::render(['html'=>'{{forms:form id="contact-example"}}','bindings'=>[]],new DBLESSCMS\\ContentContext($s,'essencial','http://localhost.test','home',true));echo json_encode(['invalid'=>$p->invalid(),'data'=>$data,'html'=>$html,'preview'=>$preview]);`);
      assert.deepEqual(result.invalid,[]);
      assert.match(result.html,/<form method="post"/);
      assert.match(result.html,/name="csrf_token"/);
      assert.match(result.html,/name="ticket"/);
      assert.match(result.html,/<label for=/);
      assert.match(result.html,/Demonstration only/);
      assert.doesNotMatch(result.html,/data-cms-form-slot/);
      assert.doesNotMatch(JSON.stringify(result.data),/csrf|ticket|recipient|retainDays|legalBasis/);
      assert.doesNotMatch(result.preview,/<form|csrf_token|name="ticket"/);
      assert.equal(result.data.fields.length,2);
      const denied=inspect(c,`$out=[];foreach(['<form action="https://evil.example"><input name="secret"></form>','<input type="file">','<div data-cms-form-slot="fake"></div>'] as $html){try{DBLESSCMS\\ContentMarkup::definition(['html'=>$html,'bindings'=>[]]);$out[]=0;}catch(DBLESSCMS\\HttpError $e){$out[]=$e->status;}}echo json_encode($out);`);
      assert.deepEqual(denied,[422,422,422]);
    } finally {c.close();}
  });
  test(`${tree}: declarative fields reject extra data, arrays, CRLF and invalid choices`,()=>{
    const c=fixture(application);
    try{
      const result=inspect(c,`require $s->config->root.'/plugins/forms/Plugin.php';$f=DBLESSCMS\\FormsModel::published($s,'contact-example');$out=[];foreach([['email'=>'reader@example.test','message'=>'Hello'],['email'=>['bad'],'message'=>'Hello'],['email'=>"a@example.test\\r\\nBcc: b@example.test",'message'=>'Hello'],['email'=>'reader@example.test','message'=>'Hello','recipient'=>'other@example.test'],['email'=>'bad','message'=>'Hello']] as $input){try{$values=DBLESSCMS\\FormsModel::values($f,$input);$out[]=array_keys($values);}catch(DBLESSCMS\\HttpError $e){$out[]=$e->status;}}echo json_encode($out);`);
      assert.deepEqual(result,[['email','message'],422,422,422,422]);
    }finally{c.close();}
  });
  test(`${tree}: delivery is truthful and session tickets prevent duplicate sends without retaining message content`,()=>{
    const c=fixture(application);
    try{
      const config=JSON.parse(fs.readFileSync(path.join(c.temporary,'app.json')));config.contact_enabled=true;fs.writeFileSync(path.join(c.temporary,'app.json'),JSON.stringify(config));
      const siteFile=path.join(c.contents,'site.json'),site=JSON.parse(fs.readFileSync(siteFile));site.settings.contact.email='operator@example.test';fs.writeFileSync(siteFile,JSON.stringify(site));
      const result=inspect(c,`require $s->config->root.'/plugins/forms/Plugin.php';$f=DBLESSCMS\\FormsModel::published($s,'contact-example');$f['demo']=false;$f['privacyPage']='starter-page';$ticket=DBLESSCMS\\FormsDelivery::ticket($f);$calls=0;$delivery=new DBLESSCMS\\FormsDelivery($s,static function($to,$subject,$body,$headers)use(&$calls):bool{$calls++;return true;});$input=['id'=>$f['id'],'email'=>'visitor-canary@example.test','message'=>'PRIVATE-MESSAGE-CANARY','ticket'=>$ticket];$a=$delivery->submit($f,$input);$b=$delivery->submit($f,$input);$record=DBLESSCMS\\FormsDelivery::receipt(DBLESSCMS\\FormsDelivery::directory($s).'/'.$a.'.json');$changed=$f;$changed['noticeVersion']='2';try{$delivery->submit($changed,$input);$stale=0;}catch(DBLESSCMS\\HttpError $e){$stale=$e->status;}echo json_encode(['calls'=>$calls,'same'=>$a===$b,'receipt'=>$record,'stale'=>$stale,'session'=>$_SESSION['forms-tickets']]);`);
      assert.equal(result.calls,1);assert.equal(result.same,true);assert.equal(result.receipt.status,'accepted');assert.deepEqual(result.receipt.values,[]);assert.equal(result.stale,409);
      assert.doesNotMatch(JSON.stringify(result),/visitor-canary|PRIVATE-MESSAGE-CANARY/);
      assert.equal(fs.readdirSync(path.join(c.temporary,'storage/plugins/forms/receipts')).length,1);
    }finally{c.close();}
  });
  test(`${tree}: transport failure/uncertainty never becomes a successful replay; scoped retention supports dry run`,()=>{
    const c=fixture(application);
    try{
      const cfg=path.join(c.temporary,'app.json'),config=JSON.parse(fs.readFileSync(cfg));config.contact_enabled=true;fs.writeFileSync(cfg,JSON.stringify(config));
      const siteFile=path.join(c.contents,'site.json'),site=JSON.parse(fs.readFileSync(siteFile));site.settings.contact.email='operator@example.test';fs.writeFileSync(siteFile,JSON.stringify(site));
      const result=inspect(c,`require $s->config->root.'/plugins/forms/Plugin.php';$f=DBLESSCMS\\FormsModel::published($s,'contact-example');$f['demo']=false;$f['privacyPage']='starter-page';$out=[];foreach(['false','uncertain'] as $mode){$ticket=DBLESSCMS\\FormsDelivery::ticket($f);$input=['id'=>$f['id'],'email'=>'visitor@example.test','message'=>'Canary','ticket'=>$ticket];$delivery=new DBLESSCMS\\FormsDelivery($s,static function()use($mode):bool{if($mode==='uncertain')throw new RuntimeException('synthetic transport failure');return false;});for($i=0;$i<2;$i++){try{$delivery->submit($f,$input);$out[]=200;}catch(DBLESSCMS\\HttpError $e){$out[]=$e->status;}}}$dry=DBLESSCMS\\FormsDelivery::cleanup($s,false,time()+400*86400);$before=count(glob(DBLESSCMS\\FormsDelivery::directory($s).'/*.json'));$applied=DBLESSCMS\\FormsDelivery::cleanup($s,true,time()+400*86400);echo json_encode(['statuses'=>$out,'dry'=>$dry,'before'=>$before,'applied'=>$applied,'after'=>count(glob(DBLESSCMS\\FormsDelivery::directory($s).'/*.json'))]);`);
      assert.deepEqual(result.statuses,[503,503,503,503]);assert.equal(result.dry.applied,false);assert.equal(result.before,result.dry.expired);assert.ok(result.before>=2);assert.equal(result.applied.applied,true);assert.equal(result.after,0);
    }finally{c.close();}
  });
  test(`${tree}: optional-resource registry is closed, defaults to no banner and does not decorate private/non-HTML surfaces`,()=>{
    const c=fixture(application);
    try{
      const result=inspect(c,`$privacy=new DBLESSCMS\\Privacy($s);$html='<!doctype html><html><head><title>Test</title></head><body>Public</body></html>';$unchanged=$privacy->decorate($html,'http://localhost.test');$site=$s->read('site');$siteData=$site['data'];$siteData['settings']['privacy']=['controller'=>'Synthetic site','email'=>'privacy@example.test','privacyPage'=>'starter-page'];$s->save('site',DBLESSCMS\\JsonStore::encode($siteData),$site['revision']);$data=DBLESSCMS\\Privacy::defaults();$data['resources'][]=['id'=>'measurement','name'=>'Synthetic measurement','category'=>'analytics','type'=>'script','url'=>'/tracking/measure.js','origins'=>['connect'=>['https://metrics.example.test']]];$repo=$privacy->repository();$current=$repo->read();$saved=$repo->change($current['revision'],static fn(array $old):array=>$data);$decorated=$privacy->decorate($html,'http://localhost.test');$invalid=$data;$invalid['resources'][0]['origins']['connect']=['https:'];try{DBLESSCMS\\Privacy::validate($invalid);$bad=0;}catch(DBLESSCMS\\HttpError $e){$bad=$e->status;}try{$repo->change($current['revision'],static fn(array $old):array=>$data);$stale=0;}catch(DBLESSCMS\\HttpError $e){$stale=$e->status;}echo json_encode(['unchanged'=>$unchanged===$html,'html'=>$decorated,'fragment'=>$privacy->decorate('<section>Fragment</section>','http://localhost.test'),'invalid'=>$bad,'stale'=>$stale]);`);
      assert.equal(result.unchanged,true);assert.match(result.html,/data-cms-consent hidden/);assert.match(result.html,/data-privacy-reject/);assert.doesNotMatch(result.html,/<script[^>]*src="\/tracking\/measure.js"/);assert.equal(result.fragment,'<section>Fragment</section>');assert.equal(result.invalid,422);assert.equal(result.stale,409);
    }finally{c.close();}
  });
  test(`${tree}: optional local resources cannot replace core code during export and forms never reuse rendered tokens`,()=>{
    const c=fixture(application);try{
      const result=inspect(c,`$out=[];foreach(['/cms-admin/assets/admin.js','/index.php','/tracking/../index.js','/tracking/nested/tool.js'] as $url){$d=DBLESSCMS\\Privacy::defaults();$d['resources']=[['id'=>'test','name'=>'Test','category'=>'analytics','type'=>'script','url'=>$url,'origins'=>[]]];try{DBLESSCMS\\Privacy::validate($d);$out[]=200;}catch(DBLESSCMS\\HttpError $e){$out[]=$e->status;}}$ctx=new DBLESSCMS\\ContentContext($s,'essencial','http://localhost.test','home');$definition=['html'=>'{{forms:form id="contact-example"}}','bindings'=>[]];$a=DBLESSCMS\\ContentMarkup::render($definition,$ctx);$b=DBLESSCMS\\ContentMarkup::render($definition,$ctx);preg_match('/name="ticket" value="([^"]+)"/',$a,$ta);preg_match('/name="ticket" value="([^"]+)"/',$b,$tb);echo json_encode(['paths'=>$out,'fresh'=>$ta[1]!==$tb[1],'providers'=>substr_count($a,'data-cms-form-provider="forms"')]);`);
      assert.deepEqual(result.paths,[422,422,422,200]);assert.equal(result.fresh,true);assert.equal(result.providers,1);
    }finally{c.close();}
  });

  test(`${tree}: production export preserves configured optional URLs, minifies code and omits image permission evidence`,async()=>{
    const c=fixture(application);try{
      const directory=path.join(c.app,'public_html/tracking'),target=path.join(c.temporary,'exported');fs.mkdirSync(directory,{recursive:true});const source='/*! synthetic license */\nfunction exampleCounter ( amount ) { const counter = amount + 1; return counter; }\nwindow.exampleCounter = exampleCounter;\n';fs.writeFileSync(path.join(directory,'measure.js'),source);
      const out=inspect(c,`$privacy=new DBLESSCMS\\Privacy($s);$doc=DBLESSCMS\\Privacy::defaults();$doc['resources']=[['id'=>'measure','name'=>'Example','category'=>'analytics','type'=>'script','url'=>'/tracking/measure.js','origins'=>[]]];$repo=$privacy->repository();$repo->change($repo->read()['revision'],static fn(array $d):array=>$doc);$target=dirname($s->config->data['storage']).'/exported';$privacy->export($target);$rights=new DBLESSCMS\\MediaRights($s);$root=dirname($s->config->root).'/public_html';DBLESSCMS\\Security::directory($root.'/media');copy($root.'/templates/essencial/essencial.png',$root.'/media/picture.png');$context=(new DBLESSCMS\\Plugins($s))->context('files-manager','','en-us','overview',true);$file=(new DBLESSCMS\\ManagedFiles($context,'public'))->describe('picture.png');$snapshot=$rights->save($file,['author'=>'Synthetic author','source'=>'https://source.example.test','license'=>'permission','licenseUrl'=>'','credit'=>'Example credit','evidence'=>'PRIVATE-EXPORT-PROOF-CANARY','altSuggestion'=>'Contextual suggestion','review'=>'approved','derivativesAllowed'=>true],$rights->repository()->read()['revision']);DBLESSCMS\\Security::directory($target.'/public_html/media');copy($root.'/media/picture.png',$target.'/public_html/media/picture.png');$rights->export($target);echo json_encode(['records'=>count($snapshot['data']['assets'])]);`);
      const {privacyResources}=await import('../scripts/lib/privacy.mjs');await privacyResources(target);const bytes=fs.readFileSync(path.join(target,'public_html/tracking/measure.js'),'utf8');assert.ok(bytes.length<source.length);assert.match(bytes,/synthetic license/);assert.equal(fs.readFileSync(path.join(directory,'measure.js'),'utf8'),source);assert.equal(out.records,1);const rights=fs.readFileSync(path.join(target,'system/storage/contents/files-manager/rights.json'),'utf8');assert.doesNotMatch(rights,/PRIVATE-EXPORT-PROOF-CANARY/);assert.match(rights,/Synthetic author/);assert.equal(fs.existsSync(path.join(target,'system/storage/plugins/forms/receipts')),false);
    }finally{c.close();}
  });

}
