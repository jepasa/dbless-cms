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
      const hashes=new Set(['blog','files-manager','forms','homepage','menu','pages','slides','widgets'].map(id=>createHash('sha256').update(fs.readFileSync(path.join(context.app,'public_html/plugins',id,id+'.svg'))).digest('hex')));assert.equal(hashes.size,8);
      for(const row of Object.values(data[3])){assert.notEqual(row.status,'invalid');const declared=JSON.parse(fs.readFileSync(path.join(context.app,'system/templates',row.id,'template.json'))).assets.files.thumbnail;assert.equal(row.media,declared.startsWith('/media/')?declared.slice(1):`templates/${row.id}/${declared}`);assert.ok(fs.existsSync(path.join(context.app,'public_html',row.media)));}
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
  test(`${application}: JPEG entropy signatures remain pixels while active metadata, appended payloads and truncation are rejected`,()=>{
    const f=fixture(application);try{
      // Original synthetic RGB fixture; neither installation photos nor external artwork are used.
      const original=Buffer.from('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAACAAIDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD0GiiitD5E/9k=','base64'),entropy=Buffer.concat([original.subarray(0,-2),Buffer.from([60,63,61,152,1]),original.subarray(-2)]),directory=path.join(f.app,'public_html/media');fs.mkdirSync(directory,{recursive:true});const file=path.join(directory,'entropy.jpg');fs.writeFileSync(file,entropy);
      const siteFile=path.join(f.contents,'site.json'),site=JSON.parse(fs.readFileSync(siteFile));for(const key of ['logo','featuredImage','image'])site.settings[key]='media/entropy.jpg';fs.writeFileSync(siteFile,JSON.stringify(site));
      const good=inspect(f,`$file=$argv[1].'/public_html/media/entropy.jpg';$scan=DBLESSCMS\\ExtensionMedia::jpegScan(file_get_contents($file));$target=dirname($argv[1]).'/jpeg-export';DBLESSCMS\\GlobalSettings::export($s,$target);$ctx=(new DBLESSCMS\\Plugins($s))->context('files-manager','','en-us','overview',true);$managed=new DBLESSCMS\\ManagedFiles($ctx,'public');$description=$managed->describe('entropy.jpg');echo json_encode([!str_contains($scan,'<?='),hash_file('sha256',$file)===hash_file('sha256',$target.'/public_html/media/entropy.jpg'),$description['path']]);`);assert.deepEqual(good,[true,true,'entropy.jpg']);
      const text=Buffer.from('<?php echo "NEVER-EXECUTED"; ?><script>unsafe()</script>'),length=Buffer.alloc(2);length.writeUInt16BE(text.length+2);const metadata=Buffer.concat([original.subarray(0,2),Buffer.from([255,254]),length,text,original.subarray(2)]);
      for(const bytes of [metadata,Buffer.concat([original,text]),original.subarray(0,-2),Buffer.from([255,216,255,225,255,255,255,217])]){fs.writeFileSync(file,bytes);const denied=inspect(f,`$codes=[];try{DBLESSCMS\\GlobalSettings::export($s,dirname($argv[1]).'/denied');$codes[]=200;}catch(RuntimeException $e){$codes[]=422;}$ctx=(new DBLESSCMS\\Plugins($s))->context('files-manager','','en-us','overview',true);try{(new DBLESSCMS\\ManagedFiles($ctx,'public'))->describe('entropy.jpg');$codes[]=200;}catch(DBLESSCMS\\HttpError $e){$codes[]=$e->status;}echo json_encode($codes);`);assert.deepEqual(denied,[422,422]);}
    }finally{f.close();}
  });

}
