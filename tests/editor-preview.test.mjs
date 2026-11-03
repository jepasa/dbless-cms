/** Rendered preview and declared media regressions against synthetic source/export installations. Never reads installation state. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {applications,fixture,inspect,server,password} from './helpers/extension-media.mjs';
for(const application of applications){
  test(`${application}: preview renders unsaved Mustache in the active shell, restores session and uses only published complements`,()=>{
    const f=fixture(application);try{
      const result=inspect(f,`$_SESSION=['csrf_token'=>'REAL-SESSION-SECRET','sentinel'=>'keep'];$before=$_SESSION;$c=new DBLESSCMS\\SiteCustomizations($s);$source=['css'=>['enabled'=>true,'code'=>'.preview-test{color:rgb(18,52,86)}'],'js'=>['enabled'=>true,'code'=>'document.body.dataset.previewProgram="published";']];$r=$c->change('publish',$source,$c->read()['revision'],$s->read('site')['revision']);$source['js']['code']='PRIVATE-UNPUBLISHED-DRAFT';$c->change('save',$source,$r['revision'],$s->read('site')['revision']);$html=DBLESSCMS\\EditorPreview::render($s,['html'=>'<section class="preview-test"><h2>Unsaved preview</h2><img src="{{cms.base}}/templates/essencial/images/examples/hero.png" alt="Example"></section>','bindings'=>[]],'home','http://localhost/sub');echo json_encode([str_contains($html,'Unsaved preview'),str_contains($html,'http://localhost/sub/templates/'),str_contains($html,'data-site-customization="css"'),str_contains($html,'data-site-customization="js"'),!str_contains($html,'PRIVATE-UNPUBLISHED-DRAFT'),!str_contains($html,'REAL-SESSION-SECRET'),$_SESSION===$before,str_contains($html,"connect-src 'none'"),!str_contains($html,'contenteditable')]);`);
      assert.deepEqual(result,Array(9).fill(true));
    }finally{f.close();}
  });
  test(`${application}: preview normalization denies admin resources, navigation, inline programs and live form values`,()=>{
    const f=fixture(application);try{
      const result=inspect(f,`$html=DBLESSCMS\\EditorPreview::document('<!doctype html><html><head><base href="https://example.invalid/"><link rel="stylesheet" href="templates/essencial/css/style.css"><meta http-equiv="refresh" content="0;url=/cms-admin/"></head><body><script src="/cms-admin/private.js"></script><script>alert(1)</script><script src="customizations/site-example.js" defer></script><iframe src="/cms-admin/"></iframe><img src="/cms-admin/?action=logout"><a href="/cms-admin/" target="_top">Leave</a><form action="/cms-admin/"><input name="csrf_token" value="SECRET"><textarea>SECRET</textarea></form><img src="media/example.png" alt="Example"></body></html>','http://localhost/sub');echo json_encode([!str_contains($html,'SECRET'),!str_contains($html,'alert(1)'),!str_contains(str_replace(DBLESSCMS\\asset('cms-admin/assets/editor-preview.css','http://localhost/sub'),'',$html),'/cms-admin/'),!str_contains($html,'<base'),!str_contains($html,'<iframe'),!str_contains($html,'http-equiv="refresh"'),str_contains($html,'http://localhost/sub/media/example.png'),str_contains($html,'http://localhost/sub/customizations/site-example.js'),str_contains($html,'disabled'),str_contains($html,'form-action')]);`);assert.deepEqual(result,Array(10).fill(true));
    }finally{f.close();}
  });
  test(`${application}: manifest media is explicit, contained, exported by reference and used for icon/favicon/thumbnail`,()=>{
    const f=fixture(application);try{
      const file=path.join(f.app,'system/templates/essencial/template.json'),manifest=JSON.parse(fs.readFileSync(file)),media=path.join(f.app,'public_html/media/bundled');fs.mkdirSync(media,{recursive:true});
      for(const key of ['thumbnail','favicon','iconHouse']){const relative=manifest.assets.files[key],name=path.basename(relative);fs.copyFileSync(path.join(f.app,'public_html/templates/essencial',relative),path.join(media,name));manifest.assets.files[key]='/media/bundled/'+name;}
      fs.unlinkSync(path.join(f.app,'public_html/templates/essencial/favicon.ico'));
      fs.writeFileSync(path.join(media,'unreferenced.png'),'NOT-AN-EXPORT');fs.writeFileSync(file,JSON.stringify(manifest));
      const result=inspect(f,`$m=$s->templates->get('essencial');$icon=DBLESSCMS\\Icons::url('house','http://localhost','essencial',$s->templates);$favicon=DBLESSCMS\\SiteFavicon::resolve($s);$s->templates->exportMedia(dirname($argv[1]).'/export');$catalog=(new DBLESSCMS\\Extensions($s))->catalog('templates','en-us');$codes=[];foreach(['/media/../outside.png','/media/bundled/code.js','/media/bundled/encoded%2fimage.png'] as $bad){try{$s->templates->publicPath('essencial',$bad);$codes[]=200;}catch(DBLESSCMS\\HttpError $e){$codes[]=$e->status;}}echo json_encode([$icon,str_starts_with($s->templates->asset('essencial',$m['assets']['files']['thumbnail']),'/media/'),$codes,$catalog['essencial']['media'],$favicon['relative']]);`);assert.equal(result[0],'http://localhost/media/bundled/house.svg');assert.equal(result[1],true);assert.deepEqual(result[2],[422,422,422]);assert.equal(result[3],'media/bundled/essencial.png');assert.equal(result[4],'media/bundled/favicon.ico');assert.deepEqual(fs.readdirSync(path.join(f.temporary,'export/public_html/media/bundled')).sort(),['essencial.png','favicon.ico','house.svg']);
      fs.symlinkSync(path.join(f.app,'public_html/templates/essencial/icons/house.svg'),path.join(media,'linked.svg'));assert.equal(inspect(f,`try{$s->templates->publicPath('essencial','/media/bundled/linked.svg');echo '200';}catch(DBLESSCMS\\HttpError $e){echo $e->status;}`),422);
      manifest.assets.scripts.push('/media/bundled/code.js');fs.writeFileSync(file,JSON.stringify(manifest));assert.equal(inspect(f,`try{$s->templates->get('essencial');echo '200';}catch(DBLESSCMS\\HttpError $e){echo $e->status;}`),422);
    }finally{f.close();}
  });
  test(`${application}: preview endpoint requires session, POST and CSRF; invalid input never becomes a login document`,async()=>{
    const f=await server(application);try{
      const endpoint=f.base+'/cms-admin/?view=editor-preview';let response=await fetch(endpoint);assert.equal(response.status,403);assert.match(response.headers.get('content-type'),/json/);
      const login=await fetch(f.base+'/cms-admin/'),cookie=login.headers.get('set-cookie').split(';')[0],html=await login.text(),csrf=html.match(/name="csrf_token" value="([^"]+)"/)[1];
      const auth=await fetch(f.base+'/cms-admin/',{method:'POST',redirect:'manual',headers:{Cookie:cookie},body:new URLSearchParams({action:'login',csrf_token:csrf,username:'editor',password})});assert.equal(auth.status,303);const session=auth.headers.get('set-cookie')?.split(';')[0]||cookie;
      response=await fetch(endpoint,{headers:{Cookie:session}});assert.equal(response.status,405);
      response=await fetch(endpoint,{method:'POST',headers:{Cookie:session},body:new URLSearchParams({document:'{}'})});assert.equal(response.status,403);
      const panel=await fetch(f.base+'/cms-admin/',{headers:{Cookie:session}}),panelHtml=await panel.text(),token=panelHtml.match(/name="csrf_token" value="([^"]+)"/)[1];
      for(const [document,status] of [[{html:'<p>Unsaved API preview</p>',bindings:[],page:'home'},200],[{html:'<p>Full page</p>',bindings:[],page:'home',scope:'page'},200],[{html:'<p>Invalid scope</p>',bindings:[],page:'home',scope:'unsafe'},422],[{html:'<script>alert(1)</script>',bindings:[],page:'home'},422],[{html:'<p>Unbound</p>',bindings:[],page:'../private'},422]]){response=await fetch(endpoint,{method:'POST',headers:{Cookie:session},body:new URLSearchParams({csrf_token:token,document:JSON.stringify(document)})});assert.equal(response.status,status);assert.match(response.headers.get('cache-control'),/no-store/);const body=await response.json();if(status===200){assert.match(body.html,/Unsaved API preview|Full page/);if(document.scope==='page')assert.match(body.html,/<header/);else assert.doesNotMatch(body.html,/<header/);assert.ok(!body.html.includes(token));}else assert.ok(body.error);}
    }finally{await f.close();}
  });
}
