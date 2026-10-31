/** Global/Widgets regressions: canonical fields, legacy migration, strict contracts, CAS/history, safe template projection and authenticated HTTP. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {spawnSync} from 'node:child_process';
import {applications,fixture,inspect,server,password,root} from './helpers/extension-media.mjs';
import {legacy} from './helpers/global-settings.mjs';
const boot="$p=(new DBLESSCMS\\Plugins($s))->instance('widgets');$w=new DBLESSCMS\\WidgetsStore($s);";
/** Run the explicit migration against only fixture state; receives fixture/flag, returns JSON receipt and fails on PHP errors or warnings. */
function migrate(context,flag){const result=spawnSync('php',[path.join(root,'src/system/bin/migrate-global-settings.php'),flag],{env:context.env,encoding:'utf8'});if(result.error)throw result.error;assert.equal(result.status,0,result.stderr);assert.equal(result.stderr,'');return JSON.parse(result.stdout);}
for(const application of applications){
  test(`${application}: canonical globals expose exactly three cards and immutable presentation identifiers`,()=>{
    const context=fixture(application);try{
      const result=inspect(context,"$l=new DBLESSCMS\\Language();$d=$s->read('site')['data'];$g=DBLESSCMS\\GlobalSettings::groups($s,$l);echo json_encode([$d,array_column($g,'title'),array_merge(...array_map(static fn($c)=>array_merge(...array_column($c['groups'],'fields')),$g)),DBLESSCMS\\GlobalSettings::theme($s,'essencial')]);");
      assert.deepEqual(Object.keys(result[0]),['schema_version','template','settings']);assert.equal(result[0].schema_version,2);assert.equal(result[1].length,3);assert.equal(result[2].length,26);assert.equal(new Set(result[2]).size,26);assert.deepEqual(result[1],['global.card.global','global.card.identity','global.card.contact']);assert.match(result[3],/forest \/ light/);
      for(const mutation of ["$d['settings']['logo']='../secret.png';","$d['settings']['image']='https://outside.test/a.png';","$d['settings']['featuredImage']='media/a.php';","$d['settings']['baseUrl']='https://user:pass@example.test';","$d['settings']['canonical']='javascript:alert(1)';","$d['settings']['imageWidth']=-1;","$d['settings']['imageWidth']='1200';","$d['settings']['extra']='unexpected';"]){const code=inspect(context,"$d=$s->read('site')['data'];"+mutation+"try{$s->document('site',DBLESSCMS\\JsonStore::encode($d));echo '200';}catch(DBLESSCMS\\HttpError $e){echo $e->status;}");assert.equal(code,422,mutation);}
      const good=inspect(context,"$d=$s->read('site')['data'];$d['settings']['logo']='media/minha marca.png';$d['settings']['baseUrl']='https://example.test/sub';$d=$s->document('site',DBLESSCMS\\JsonStore::encode($d));echo json_encode([$d['settings']['logo'],$d['settings']['baseUrl']]);");assert.deepEqual(good,['media/minha marca.png','https://example.test/sub']);
    }finally{context.close();}
  });
  test(`${application}: widget manifest rejects dynamic data and theme metadata must reference actual string options`,()=>{
    const context=fixture(application);try{
      const result=inspect(context,"$out=[];foreach([['bad/key'=>'Unsafe'],['key'=>['value'=>'dynamic']],['key'=>'<script>bad</script>']] as $locations){try{DBLESSCMS\\Widgets::locations(['widgetLocations'=>$locations]);$out[]=200;}catch(DBLESSCMS\\HttpError $e){$out[]=$e->status;}}$out[]=DBLESSCMS\\Widgets::locations(['widgetLocations'=>['new-position'=>'New position']]);$all=$s->templates->all();if(isset($all['jepasaDotCom']))$out[]=DBLESSCMS\\GlobalSettings::theme($s,'jepasaDotCom');echo json_encode($out);");assert.deepEqual(result.slice(0,4),[422,422,422,{'new-position':'New position'}]);if(result.length===5)assert.equal(result[4],'');
      const file=path.join(context.app,'system/templates/essencial/template.json');const manifest=JSON.parse(fs.readFileSync(file));manifest.themeFields=['appearance.unknown'];fs.writeFileSync(file,JSON.stringify(manifest));assert.equal(inspect(context,"try{$s->templates->get('essencial');echo '200';}catch(DBLESSCMS\\HttpError $e){echo $e->status;}"),422);
    }finally{context.close();}
  });
  test(`${application}: Widgets reject duplicate bindings, code and unsafe metadata without silently changing values`,()=>{
    const context=fixture(application);try{
      for(const mutation of ["$d['unexpected']=true;","$d['widgets']['a']=['id'=>'a','template'=>'essencial','key'=>'footer-note','value'=>'<script>bad</script>','description'=>'','deleted'=>false];","$first=array_key_first($d['widgets']);$d['widgets']['duplicate']=$d['widgets'][$first];$d['widgets']['duplicate']['id']='duplicate';","$first=array_key_first($d['widgets']);$d['widgets'][$first]['template']='../secret';"]){assert.equal(inspect(context,boot+"$d=$w->read()['data'];"+mutation+"try{DBLESSCMS\\WidgetsStore::validate($d);echo '200';}catch(DBLESSCMS\\HttpError $e){echo $e->status;}"),422,mutation);}
      const resolved=inspect(context,boot+"$d=$w->read();$w->mutate($d['revision'],static function($d){$d['widgets']['essencial-social-website']['value']='javascript:alert(1)';return $d;});$view=new DBLESSCMS\\Widgets($s,'essencial');echo json_encode([$view->value('social-website'),$view->link('social-website'),$view->value('missing','fallback')]);");assert.deepEqual(resolved,['javascript:alert(1)','','fallback']);
    }finally{context.close();}
  });
  test(`${application}: widget CAS, trash, recovery and history preserve previous snapshots`,()=>{
    const context=fixture(application);try{
      const out=inspect(context,boot+"$before=$w->read();$after=$w->mutate($before['revision'],static function($d){$d['widgets']['essencial-footer-note']['deleted']=true;return $d;});$code=0;try{$w->mutate($before['revision'],static fn($d)=>$d);}catch(DBLESSCMS\\HttpError $e){$code=$e->status;}$hidden=(new DBLESSCMS\\Widgets($s,'essencial'))->value('footer-note','fallback');$restored=$w->restore($w->history()[0],$after['revision']);$value=(new DBLESSCMS\\Widgets($s,'essencial'))->value('footer-note');echo json_encode([$code,$hidden,$value,count($w->history())]);");assert.equal(out[0],409);assert.equal(out[1],'fallback');assert.match(out[2],/Widgets/);assert.equal(out[3],2);
      const unsafe=path.join(context.contents,'widgets/widgets.json');fs.unlinkSync(unsafe);fs.symlinkSync('/etc/passwd',unsafe);assert.equal(inspect(context,boot+"try{$w->read();echo '200';}catch(DBLESSCMS\\HttpError $e){echo $e->status;}"),503);
    }finally{context.close();}
  });
  test(`${application}: migration is explicit, idempotent, backed up and imports old social records`,()=>{
    const context=fixture(application);try{
      const file=path.join(context.contents,'site.json'),old=legacy(JSON.parse(fs.readFileSync(file))),raw=JSON.stringify(old,null,2)+'\n';fs.writeFileSync(file,raw);
      fs.rmSync(path.join(context.contents,'widgets'),{recursive:true,force:true});
      const plugins=path.join(context.contents,'plugins.json'),registry=JSON.parse(fs.readFileSync(plugins));delete registry.plugins.widgets;fs.writeFileSync(plugins,JSON.stringify(registry));
      const preview=migrate(context,'--check');assert.equal(preview.widgetImports,2);assert.equal(fs.readFileSync(file,'utf8'),raw);assert.ok(!fs.existsSync(path.join(context.contents,'widgets')));
      const result=migrate(context,'--apply');assert.equal(result.widgetImports,2);assert.equal(JSON.parse(fs.readFileSync(file)).schema_version,2);
      const backup=fs.readdirSync(path.join(context.temporary,'storage/backups/site'));assert.equal(backup.length,1);assert.equal(fs.readFileSync(path.join(context.temporary,'storage/backups/site',backup[0]),'utf8'),raw);
      const imported=JSON.parse(fs.readFileSync(path.join(context.contents,'widgets/widgets.json')));assert.equal(Object.values(imported.widgets).find(v=>v.key==='social-github').value,'https://example.test/profile');assert.equal(Object.values(imported.widgets).find(v=>v.key==='contact-address').value,'Example address');
      const bytes=fs.readFileSync(file),widgetBytes=fs.readFileSync(path.join(context.contents,'widgets/widgets.json'));assert.equal(migrate(context,'--apply').widgetImports,0);assert.deepEqual(fs.readFileSync(file),bytes);assert.deepEqual(fs.readFileSync(path.join(context.contents,'widgets/widgets.json')),widgetBytes);
    }finally{context.close();}
  });
  test(`${application}: template switches preserve orphan widgets and disabled plugins return a safe fallback`,()=>{
    const context=fixture(application);try{
      const out=inspect(context,boot+"$before=$w->read();$w->mutate($before['revision'],static function($d){$d['widgets']['orphan']=['id'=>'orphan','template'=>'essencial','key'=>'retired-position','value'=>'Keep me','description'=>'Old binding','deleted'=>false];return $d;});$site=$s->read('site');$c=(new DBLESSCMS\\Plugins($s))->context('widgets','https://example.test','en-us','overview',true);$html=$p->render($c);$reg=new DBLESSCMS\\Plugins($s);$reg->save('widgets',false,[],$reg->document()['revision']);echo json_encode([str_contains($html,'retired-position'),(new DBLESSCMS\\Widgets($s,'essencial'))->value('footer-note','fallback'),$w->read()['data']['widgets']['orphan']['value']]);");assert.deepEqual(out,[true,'fallback','Keep me']);
    }finally{context.close();}
  });
  test(`${application}: authenticated widget API enforces CSRF, routes, active-template keys and site revision`,async()=>{
    const sandbox=await server(application);let cookie='';
    /** Request this fixture only; receives route/options, returns a manual-redirect response while persisting the synthetic session cookie. */
    async function request(route,options={}){const response=await fetch(sandbox.base+route,{...options,redirect:'manual',headers:{...(cookie?{Cookie:cookie}:{}),...options.headers}});for(const item of response.headers.getSetCookie())if(item.startsWith('DBLESSCMSSESSID='))cookie=item.split(';')[0];return response;}
    /** Extract a bounded opaque hidden revision/token; receives HTML/name, returns raw hex without entity decoding. */
    function field(html,name){const m=html.match(new RegExp('name="'+name+'"[^>]*value="([a-f0-9]{64})"'));assert.ok(m,name);return m[1];}
    try{
      let html=await (await request('/cms-admin/')).text();assert.equal((await request('/cms-admin/',{method:'POST',body:new URLSearchParams({action:'login',username:'editor',password,csrf_token:field(html,'csrf_token')})})).status,303);
      html=await (await request('/cms-admin/?view=plugin-panel&plugin=widgets&route=editor')).text();assert.match(html,/social-website/);const data={id:'',original:'',revision:field(html,'revision'),site_revision:field(html,'site_revision'),csrf_token:field(html,'csrf_token'),key:'social-linkedin',value:'https://example.test/profile',description:'Example profile'};const api='/cms-admin/?view=plugin-api&plugin=widgets&route=editor&operation=save';
      assert.equal((await request(api,{method:'POST',body:new URLSearchParams({...data,csrf_token:'forged'})})).status,403);
      assert.equal((await request(api,{method:'POST',body:new URLSearchParams({...data,key:'undeclared'})})).status,422);
      assert.equal((await request(api,{method:'POST',body:new URLSearchParams({...data,site_revision:'0'.repeat(64)})})).status,409);
      assert.equal((await request(api.replace('route=editor','route=overview'),{method:'POST',body:new URLSearchParams(data)})).status,404);
      assert.equal((await request(api,{method:'POST',body:new URLSearchParams(data)})).status,200);
      html=await (await request('/cms-admin/?view=plugin-panel&plugin=widgets&route=editor')).text();assert.equal((await request(api,{method:'POST',body:new URLSearchParams({...data,revision:field(html,'revision')})})).status,422);
      assert.equal((await request('/plugins/widgets/overview')).status,404);assert.doesNotMatch(sandbox.logs(),/PHP (Warning|Fatal|Parse)/);
    }finally{await sandbox.close();}
  });
}
