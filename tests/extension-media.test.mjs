/** Registry regressions for manifest-owned icons/previews, custom filenames, missing media, traversal, symlinks and corrupt/non-widescreen PNGs. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {createHash} from 'node:crypto';
import {applications,fixture,inspect} from './helpers/extension-media.mjs';

/** Read or replace a fixture manifest; receives context/kind/id/mutator, returns original bytes and propagates filesystem errors. */
function manifest(context,kind,id,mutate){const file=path.join(context.app,'system',kind,id,kind==='plugins'?'plugin.json':'template.json'),raw=fs.readFileSync(file);if(mutate){const data=JSON.parse(raw);mutate(data);fs.writeFileSync(file,JSON.stringify(data));}return raw;}

for(const application of applications){
  test(`${application}: favicon paths stay public, persist across templates and export only the referenced upload`,()=>{
    const context=fixture(application);
    try{
      const folder=path.join(context.app,'public_html/media/brand');fs.mkdirSync(folder,{recursive:true});fs.copyFileSync(path.join(context.app,'public_html/cms-admin/assets/favicon.ico'),path.join(folder,'site.ico'));fs.writeFileSync(path.join(folder,'unrelated.txt'),'PRIVATE SYNTHETIC UPLOAD');
      const output=inspect(context,"$d=$s->read('site');$d['data']['settings']['favicon']='media/brand/site.ico';$s->save('site',DBLESSCMS\\JsonStore::encode($d['data']),$d['revision']);$a=DBLESSCMS\\SiteFavicon::url($s,'/subdir');$d=$s->read('site');$d['data']['template']='essencial';$s->save('site',DBLESSCMS\\JsonStore::encode($d['data']),$d['revision']);DBLESSCMS\\SiteFavicon::export($s,dirname($argv[1]).'/export');echo json_encode([$a,DBLESSCMS\\SiteFavicon::url($s,'/subdir')]);");
      assert.deepEqual(output,['/subdir/media/brand/site.ico','/subdir/media/brand/site.ico']);assert.ok(fs.existsSync(path.join(context.temporary,'export/public_html/media/brand/site.ico')));assert.ok(!fs.existsSync(path.join(context.temporary,'export/public_html/media/brand/unrelated.txt')));
      fs.unlinkSync(path.join(folder,'site.ico'));assert.match(inspect(context,"echo json_encode(DBLESSCMS\\SiteFavicon::url($s));"),/\/templates\/essencial\/favicon\.ico$/);
      assert.equal(inspect(context,"$d=$s->read('site');(new DBLESSCMS\\Extensions($s))->toggle('templates','essencial',true,$d['revision']);echo json_encode($s->read('site')['data']['template']);"),'essencial','Deleted user favicon must not block the protected template fallback.');
      for(const invalid of ['../private.ico','/absolute.ico','https://example.test/favicon.ico','media/%2e%2e/icon.ico','media/not.png']){
        assert.equal(inspect(context,"try{DBLESSCMS\\SiteFavicon::reference("+JSON.stringify(invalid)+");echo '200';}catch(DBLESSCMS\\HttpError $e){echo $e->status;}"),422);
      }
      const image=path.join(folder,'site.ico');fs.copyFileSync(path.join(context.app,'public_html/cms-admin/assets/favicon.ico'),image);fs.appendFileSync(image,'TRAILING');assert.equal(inspect(context,"try{DBLESSCMS\\SiteFavicon::path($s,'media/brand/site.ico');echo '200';}catch(DBLESSCMS\\HttpError $e){echo $e->status;}"),422);
      fs.unlinkSync(image);fs.symlinkSync(path.join(context.app,'public_html/cms-admin/assets/favicon.ico'),image);assert.equal(inspect(context,"try{DBLESSCMS\\SiteFavicon::path($s,'media/brand/site.ico');echo '200';}catch(DBLESSCMS\\HttpError $e){echo $e->status;}"),422);
    }finally{context.close();}
  });
  test(`${application}: distinct own plugin icons and valid declared 16:9 previews`,()=>{
    const context=fixture(application);
    try{
      const data=inspect(context,"$p=new DBLESSCMS\\Plugins($s);echo json_encode([$p->invalid(),$p->navigation('en-us'),$p->icon('slides','/nested'),(new DBLESSCMS\\Extensions($s))->catalog('templates','en-us')]);");
      assert.deepEqual(data[0],[]);assert.equal(data[1].slides.icon,'slides.svg');assert.equal(data[1]['files-manager'].icon,'files-manager.svg');assert.equal(data[2],'/nested/plugins/slides/slides.svg');
      const hashes=new Set(['blog','files-manager','menu','pages','slides'].map(id=>createHash('sha256').update(fs.readFileSync(path.join(context.app,'public_html/plugins',id,id+'.svg'))).digest('hex')));assert.equal(hashes.size,5);
      for(const row of Object.values(data[3])){assert.notEqual(row.status,'invalid');assert.equal(row.media,`templates/${row.id}/${row.id}.png`);}
    }finally{context.close();}
  });
  test(`${application}: manifest overrides icon and thumbnail filenames`,()=>{
    const context=fixture(application);
    try{
      const icon=path.join(context.app,'public_html/plugins/slides/custom-preview.svg');fs.copyFileSync(path.join(path.dirname(icon),'slides.svg'),icon);manifest(context,'plugins','slides',data=>{data.assets.icon='custom-preview.svg';data.navigation.icon='image';});
      const thumb=path.join(context.app,'public_html/templates/essencial/custom-wide.png');fs.copyFileSync(path.join(path.dirname(thumb),'essencial.png'),thumb);manifest(context,'templates','essencial',data=>{data.assets.files.thumbnail='custom-wide.png';});
      const result=inspect(context,"$p=new DBLESSCMS\\Plugins($s);echo json_encode([$p->icon('slides'),(new DBLESSCMS\\Extensions($s))->catalog('templates','en-us')['essencial']['media']]);");
      assert.deepEqual(result,['/plugins/slides/custom-preview.svg','templates/essencial/custom-wide.png']);
    }finally{context.close();}
  });
  test(`${application}: invalid plugin icons are rejected without fallback to a shared icon`,()=>{
    const context=fixture(application),file=path.join(context.app,'system/plugins/slides/plugin.json'),original=fs.readFileSync(file),icon=path.join(context.app,'public_html/plugins/slides/slides.svg'),bytes=fs.readFileSync(icon);
    try{
      for(const invalid of ['../menu/menu.svg','/outside.svg','https://example.test/icon.svg','missing.svg','wrong.png',null]){
        fs.writeFileSync(file,original);manifest(context,'plugins','slides',data=>{if(invalid===null)delete data.assets.icon;else data.assets.icon=invalid;});
        assert.ok(inspect(context,"echo json_encode((new DBLESSCMS\\Plugins($s))->invalid());").slides);
      }
      fs.writeFileSync(file,original);fs.writeFileSync(icon,'<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');assert.ok(inspect(context,"echo json_encode((new DBLESSCMS\\Plugins($s))->invalid());").slides);
      fs.unlinkSync(icon);fs.symlinkSync(path.join(context.app,'public_html/plugins/files-manager/files-manager.svg'),icon);assert.ok(inspect(context,"echo json_encode((new DBLESSCMS\\Plugins($s))->invalid());").slides);
      fs.unlinkSync(icon);fs.writeFileSync(icon,bytes);
    }finally{context.close();}
  });
  test(`${application}: absent, corrupt, non-16:9 or linked thumbnails are rejected`,()=>{
    const context=fixture(application),file=path.join(context.app,'system/templates/essencial/template.json'),original=fs.readFileSync(file),image=path.join(context.app,'public_html/templates/essencial/essencial.png'),bytes=fs.readFileSync(image);
    /** Query rejected templates without invoking their renderer; no inputs, returns rejection map and never writes runtime content. */
    const rejected=()=>inspect(context,"$s->templates->all();echo json_encode($s->templates->invalid());");
    try{
      for(const invalid of [null,'missing.png','../essencial.png','essencial.svg']){fs.writeFileSync(file,original);manifest(context,'templates','essencial',data=>{if(invalid===null)delete data.assets.files.thumbnail;else data.assets.files.thumbnail=invalid;});assert.ok(rejected().essencial);}
      fs.writeFileSync(file,original);
      const corrupt=Buffer.from(bytes);corrupt[29]^=1;
      for(const invalid of [corrupt,Buffer.concat([bytes,Buffer.from('TRAILING')]),Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aRpsAAAAASUVORK5CYII=','base64')]){fs.writeFileSync(image,invalid);assert.ok(rejected().essencial);}
      fs.unlinkSync(image);fs.symlinkSync(path.join(context.app,'public_html/templates/essencial/images/examples/studio.png'),image);assert.ok(rejected().essencial);
    }finally{context.close();}
  });
}
