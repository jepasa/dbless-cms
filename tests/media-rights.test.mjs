/** Synthetic media-rights regressions: private evidence isolation, contextual alt, identity across moves/trash and explicitly licensed sanitized derivatives. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {applications,fixture,inspect} from './helpers/extension-media.mjs';

/** Create one contained image from versioned generic artwork; receive fixture/scope/name, return relative managed name, no live media is read. */
function image(c,scope,name='original.png') {
  const root=scope==='public'?path.join(c.app,'public_html/media'):path.join(c.temporary,'storage/files/media');fs.mkdirSync(root,{recursive:true});fs.copyFileSync(path.join(c.app,'public_html/templates/essencial/essencial.png'),path.join(root,name));return name;
}
const fields="['author'=>'Synthetic author','source'=>'https://source.example.test/image','license'=>'owned','licenseUrl'=>'','credit'=>'Original example','evidence'=>'CONFIDENTIAL-PERMISSION-CANARY','altSuggestion'=>'A suggested description','review'=>'approved','derivativesAllowed'=>true]";
for(const app of applications){
  test(`${path.basename(app)}: approved public image data never contains private evidence and does not overwrite contextual alt`,()=>{
    const c=fixture(app);try{image(c,'public');
      const result=inspect(c,`$ctx=(new DBLESSCMS\\Plugins($s))->context('files-manager','http://localhost.test','en-us','overview',true);$files=new DBLESSCMS\\ManagedFiles($ctx,'public');$rights=new DBLESSCMS\\MediaRights($s);$saved=$rights->save($files->describe('original.png'),${fields},$rights->repository()->read()['revision']);$id=array_key_first($saved['data']['assets']);$data=$rights->asset($id,'http://localhost.test');$html=$rights->decorate('<!doctype html><html><head><title>Fixture</title></head><body><figure><a href="/project"><picture><img src="http://localhost.test/media/original.png" alt="Specific contextual alternative"></picture></a></figure></body></html>','http://localhost.test');echo json_encode(['data'=>$data,'html'=>$html]);`);
      assert.equal(result.data.author,'Synthetic author');assert.match(result.data.src,/\/media\/original.png$/);assert.match(result.html,/data-image-credit=/);assert.match(result.html,/Specific contextual alternative/);assert.match(result.html,/Synthetic author/);assert.doesNotMatch(JSON.stringify(result),/CONFIDENTIAL-PERMISSION-CANARY|"evidence"|"parent"/);assert.doesNotMatch(result.html,/<a[^>]*><span[^>]*data-image-credit/);
      fs.appendFileSync(path.join(c.app,'public_html/media/original.png'),'changed');
      assert.equal(inspect(c,`$rights=new DBLESSCMS\\MediaRights($s);$id=array_key_first($rights->repository()->read()['data']['assets']);try{$rights->asset($id,'');echo '0';}catch(DBLESSCMS\\HttpError $e){echo json_encode($e->status);}`),404);
    }finally{c.close();}
  });
  test(`${path.basename(app)}: stable rights identity follows rename, delete and restore, with stale revisions refused`,()=>{
    const c=fixture(app);try{image(c,'public');
      const result=inspect(c,`$ctx=(new DBLESSCMS\\Plugins($s))->context('files-manager','','en-us','overview',true);$files=new DBLESSCMS\\ManagedFiles($ctx,'public');$rights=new DBLESSCMS\\MediaRights($s);$saved=$rights->save($files->describe('original.png'),${fields},$rights->repository()->read()['revision']);$id=array_key_first($saved['data']['assets']);$list=$files->listing('');$files->mutate('rename',['path'=>'','target'=>'original.png','name'=>'renamed.png','revision'=>$list['revision']]);$renamed=$rights->repository()->read()['data']['assets'][$id];try{$files->mutate('rename',['path'=>'','target'=>'renamed.png','name'=>'stale.png','revision'=>$list['revision']]);$stale=0;}catch(DBLESSCMS\\HttpError $e){$stale=$e->status;}$files->mutate('delete',['path'=>'','target'=>'renamed.png','name'=>'','revision'=>$files->listing('')['revision']]);$trash=$files->trash();$deleted=$rights->repository()->read()['data']['assets'][$id];$files->mutate('restore',['path'=>'','target'=>$trash['entries'][0]['id'],'name'=>'','revision'=>$trash['revision']]);$restored=$rights->repository()->read()['data']['assets'][$id];echo json_encode(['id'=>$id,'renamed'=>$renamed['path'],'deleted'=>$deleted['trash']!=='','restored'=>$restored['id']===$id&&$restored['trash']===''&&$restored['path']==='renamed.png','stale'=>$stale]);`);
      assert.equal(result.renamed,'renamed.png');assert.equal(result.deleted,true);assert.equal(result.restored,true);assert.equal(result.stale,409);
    }finally{c.close();}
  });
  test(`${path.basename(app)}: explicit private derivative removes technical metadata while keeping original and public credits`,()=>{
    const c=fixture(app);try{image(c,'private','private.png');
      const result=inspect(c,`$ctx=(new DBLESSCMS\\Plugins($s))->context('files-manager','','en-us','overview',true);$files=new DBLESSCMS\\ManagedFiles($ctx,'private');$rights=new DBLESSCMS\\MediaRights($s);$source=$s->config->data['storage'].'/files/media/private.png';$png=file_get_contents($source);$type='tEXt';$payload="Comment\\0GPS-METADATA-CANARY";$chunk=pack('N',strlen($payload)).$type.$payload.hex2bin(hash('crc32b',$type.$payload));file_put_contents($source,substr($png,0,-12).$chunk.substr($png,-12));$hash=hash_file('sha256',$source);$saved=$rights->save($files->describe('private.png'),${fields},$rights->repository()->read()['revision']);$parent=array_key_first($saved['data']['assets']);if(!function_exists('imagecreatefromstring')){try{$files->publishCopy('private.png','public.png',$saved['revision']);$status=0;}catch(DBLESSCMS\\HttpError $e){$status=$e->status;}echo json_encode(['gd'=>false,'status'=>$status]);}else{$out=$files->publishCopy('private.png','public.png',$saved['revision']);$asset=$rights->find('public','media','public.png');$bytes=file_get_contents(dirname($s->config->root).'/public_html/media/public.png');$data=$rights->asset($asset['id'],'');echo json_encode(['gd'=>true,'originalUnchanged'=>hash_file('sha256',$source)===$hash,'removed'=>!str_contains($bytes,'GPS-METADATA-CANARY'),'linked'=>$asset['parent']===$parent,'data'=>$data]);}`);
      if(result.gd){assert.equal(result.originalUnchanged,true);assert.equal(result.removed,true);assert.equal(result.linked,true);assert.equal(result.data.author,'Synthetic author');assert.doesNotMatch(JSON.stringify(result.data),/CONFIDENTIAL|private\.png|"evidence"|"parent"/);}else assert.equal(result.status,503,'Unavailable optional GD must fail explicitly, never pretend to sanitize.');
    }finally{c.close();}
  });
  test(`${path.basename(app)}: approval requires a rights reference and new core public assets are protected from file operations`,()=>{
    const c=fixture(app);try{image(c,'public');
      const result=inspect(c,`$ctx=(new DBLESSCMS\\Plugins($s))->context('files-manager','','en-us','overview',true);$files=new DBLESSCMS\\ManagedFiles($ctx,'public');$rights=new DBLESSCMS\\MediaRights($s);$fields=${fields};$fields['license']='permission';$fields['evidence']='';try{$rights->save($files->describe('original.png'),$fields,$rights->repository()->read()['revision']);$approval=0;}catch(DBLESSCMS\\HttpError $e){$approval=$e->status;}$settings=$ctx->settings;$settings['uploads']['public']='';$rootCtx=new DBLESSCMS\\PluginContext($ctx->config,$ctx->store,$ctx->manifest,$settings,$ctx->revision,$ctx->base,$ctx->language);$root=new DBLESSCMS\\ManagedFiles($rootCtx,'public');try{$root->listing('cms-assets');$protected=0;}catch(DBLESSCMS\\HttpError $e){$protected=$e->status;}echo json_encode(['approval'=>$approval,'protected'=>$protected,'records'=>count($rights->repository()->read()['data']['assets'])]);`);
      assert.deepEqual(result,{approval:422,protected:403,records:0});
    }finally{c.close();}
  });
}
