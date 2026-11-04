/** Synthetic-only overview fixtures: published/draft parented Pages and enabled bundled modules; no live state or network targets. */
import fs from 'node:fs';
import path from 'node:path';
import {inspect} from './extension-media.mjs';
/** Seed one disposable fixture with bounded independently pageable records; receives fixture, writes only its cloned catalog/registry, returns count and propagates validation failures. */
export function seed(context,count=12){
  if(!Number.isInteger(count)||count<2||count>100)throw new TypeError('Invalid synthetic count.');
  const file=path.join(context.contents,'plugins.json'),registry=JSON.parse(fs.readFileSync(file));for(const row of Object.values(registry.plugins))row.enabled=true;fs.writeFileSync(file,JSON.stringify(registry));
  return inspect(context,`$records=[];for($i=1;$i<=${count};$i++){$id='sample-'.str_pad((string)$i,3,'0',STR_PAD_LEFT);$snapshot=DBLESSCMS\\PageBlueprints::create('blank','Table sample '.str_pad((string)$i,3,'0',STR_PAD_LEFT),$id);$parent=$i>${Math.floor(count/2)}?'sample-001':'';$snapshot['parent']=$parent;$snapshot['updatedAt']='2020-01-01T00:00:00Z';$snapshot['editorial']=['author'=>'Synthetic','order'=>101-$i,'review'=>false];$published=$snapshot;$published['document']['status']='published';$records[$id]=['id'=>$id,'parent'=>$parent,'model'=>'blank','createdAt'=>'2020-01-01T00:00:00Z','updatedAt'=>'2020-01-01T00:00:00Z','deletedAt'=>null,'publishAt'=>$i>${count-2}?'':'2020-01-01T00:00:00Z','aliases'=>[],'draft'=>$snapshot,'published'=>$i>${count-2}?null:$published,'scheduled'=>null];}$directory=$s->config->data['contents'].'/page-publications';if(!is_dir($directory))mkdir($directory,0700,true);file_put_contents($directory.'/catalog.json',DBLESSCMS\\JsonStore::encode(['schema_version'=>1,'records'=>$records]));echo json_encode(count((new DBLESSCMS\\PagePublications($s))->read()['data']['records']));`);
}
