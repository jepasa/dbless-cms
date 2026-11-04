<?php
/** Pages editorial snapshots and unified Posts compatibility: immutable IDs, independent publication states, aliases and recoverable history under the common writer lock. */
declare(strict_types=1);
namespace DBLESSCMS;
final class PagePublications {
  public const LIMIT=8388608;
  /** Bind private storage services; receives store, performs no reads or writes. */
  public function __construct(private readonly JsonStore $store) {}
  /** Raise a localized editorial contract/storage/conflict error without exposing paths; receives suffix/status, never mutates data. */
  private static function fail(string $key='invalid',int $status=422): never {throw new HttpError($status,'Page operation unavailable.','pages.editorial.'.$key);}
  /** Validate a nonreserved page identity/URL; receives string, returns bool without filesystem access. */
  public static function identifier(string $id): bool {return preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$id)===1&&!in_array($id,['home','404','site','plugins','templates','cms-admin','plugins','media','tracking'],true);}
  /** Resolve fixed catalog or private history location; receives optional ID, rejects symbolic ancestors, never creates directories. */
  private function file(?string $id=null): string {
    if($id!==null&&!self::identifier($id))self::fail();$path=$id===null?$this->store->config->data['contents'].'/page-publications/catalog.json':$this->store->config->data['storage'].'/page-history/'.$id;
    try{Lifecycle::path($path);}catch(\RuntimeException){self::fail('storage',503);}return $path;
  }
  /** Read a bounded schema versioned catalog; receive optional raw-source flag, return data/revision with optional unified metadata overlays, no writes, absence is an empty unpersisted baseline. */
  public function read(bool $original=false): array {
    $file=$this->file();if(!file_exists($file))return ['data'=>['schema_version'=>1,'records'=>$original?[]:(new Posts($this->store))->pageCatalog([])],'revision'=>hash('sha256','')];if(!is_file($file)||filesize($file)>self::LIMIT)self::fail('storage',503);$raw=file_get_contents($file);if($raw===false)self::fail('storage',503);
    try{$data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){self::fail();}
    if(!is_array($data)||count($data)!==2||($data['schema_version']??null)!==1||!is_array($data['records']??null)||count($data['records'])>200)self::fail();
    foreach($data['records'] as $id=>$row){$id=(string)$id;if(!is_string($id)||!self::identifier($id)||!is_array($row)||count($row)!==11||($row['id']??null)!==$id||array_diff(array_keys($row),['id','parent','model','createdAt','updatedAt','deletedAt','publishAt','aliases','draft','published','scheduled'])||!is_string($row['parent']??null)||!is_string($row['model']??null)||!is_array($row['aliases']??null)||!array_is_list($row['aliases'])||count($row['aliases'])>50)self::fail();foreach(['createdAt','updatedAt','deletedAt'] as $key)if(($row[$key]??null)!==null)self::date($row[$key]);self::date($row['publishAt']??null,true);foreach($row['aliases'] as $alias)if(!is_string($alias)||!self::identifier($alias))self::fail();foreach(['draft','published','scheduled'] as $key){if(!array_key_exists($key,$row)||($key==='draft'&&$row[$key]===null))self::fail();if($row[$key]!==null)$data['records'][$id][$key]=$this->snapshot($id,$row[$key]);}}
    if(!$original)$data['records']=(new Posts($this->store))->pageCatalog($data['records']);return ['data'=>$data,'revision'=>hash('sha256',$raw)];
  }
  /** Validate an exact UTC instant; receives timestamp/empty flag, returns text or 422 without normalizing invalid dates. */
  public static function date(mixed $value,bool $empty=false): string {
    if($empty&&$value==='')return '';if(!is_string($value))self::fail();$date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new \DateTimeZone('UTC'));if($date===false||$date->format('Y-m-d\TH:i:s\Z')!==$value)self::fail();return $value;
  }
  /** Validate an immutable-ID document and closed per-page presentation options; receives ID/snapshot, returns normalized snapshot, no writes/provider rendering. */
  private function snapshot(string $id,array $snapshot): array {
    $snapshot+=['publishedAt'=>'','parent'=>'','updatedAt'=>null,'editorial'=>self::editorial()];$snapshot['editorial']=self::editorial($snapshot['editorial']);self::date($snapshot['publishedAt'],true);if(count($snapshot)!==7||!is_string($snapshot['parent'])||($snapshot['parent']!==''&&!self::identifier($snapshot['parent']))||!is_string($snapshot['url']??null)||!self::identifier($snapshot['url'])||!is_array($snapshot['document']??null)||!is_array($snapshot['seo']??null))self::fail();
    if($snapshot['updatedAt']!==null)self::date($snapshot['updatedAt']);$snapshot['document']=$this->store->document($id,JsonStore::encode($snapshot['document']));$seo=$snapshot['seo'];$keys=['title','description','canonical','noindex','socialTitle','socialDescription','socialImage','socialImageAlt'];if(count($seo)!==count($keys)||array_diff(array_keys($seo),$keys)||!is_bool($seo['noindex']??null))self::fail();
    foreach(array_diff($keys,['noindex']) as $key)if(!is_string($seo[$key])||strlen($seo[$key])>2048||preg_match('/[<>\x00-\x1f]/',$seo[$key]))self::fail();
    if($seo['canonical']!==''&&(!filter_var($seo['canonical'],FILTER_VALIDATE_URL)||!in_array(parse_url($seo['canonical'],PHP_URL_SCHEME),['https','http'],true)||parse_url($seo['canonical'],PHP_URL_USER)!==null||parse_url($seo['canonical'],PHP_URL_PASS)!==null||parse_url($seo['canonical'],PHP_URL_FRAGMENT)!==null))self::fail();
    foreach([$snapshot['document']['meta']['image'],$seo['socialImage']] as $image)if($image!=='')ContentMarkup::html('<img src="'.e(str_replace(' ','%20',$image)).'" alt="">');return $snapshot;
  }
  /** Validate additive editorial defaults; receives optional plain metadata, returns author/order/review without writes or account lookup; invalid fields raise 422. */
  public static function editorial(mixed $value=[]): array {
    if(!is_array($value))self::fail();$value+=['author'=>'','order'=>0,'review'=>false];
    if(count($value)!==3||!is_string($value['author'])||strlen($value['author'])>200||preg_match('//u',$value['author'])!==1||preg_match('/[<>\x00-\x1f\x7f]/',$value['author'])||!is_int($value['order'])||abs($value['order'])>999999||!is_bool($value['review']))self::fail();
    return $value;
  }
  /** Return optional presentation defaults; no parameters or effects. */
  public static function seo(): array {return ['title'=>'','description'=>'','canonical'=>'','noindex'=>false,'socialTitle'=>'','socialDescription'=>'','socialImage'=>'','socialImageAlt'=>''];}
  /** Project a legacy page into an unsaved editable record; receives ID, never migrates its file or invents historic dates. */
  public function legacy(string $id): array {
    if(!self::identifier($id))self::fail();$page=$this->store->read($id,null,true)['data'];$snapshot=['url'=>$id,'document'=>$page,'seo'=>self::seo(),'parent'=>'','updatedAt'=>null,'editorial'=>self::editorial()];return ['id'=>$id,'parent'=>'','model'=>'legacy','createdAt'=>null,'updatedAt'=>null,'deletedAt'=>null,'publishAt'=>'','aliases'=>[],'draft'=>$snapshot,'published'=>$page['status']==='published'?$snapshot:null,'scheduled'=>null];
  }
  /** Resolve a stored ID without creating state; receives ID/optional raw-source flag, returns managed/legacy record or 404. */
  public function record(string $id,bool $original=false): array {return $this->read($original)['data']['records'][$id]??$this->legacy($id);}
  /** Resolve the effective authorized snapshot at the supplied clock; receives row/clock, returns published/scheduled or null without public writes. */
  public static function effective(array $row,?int $now=null): ?array {if($row['deletedAt']!==null)return null;return $row['scheduled']!==null&&strtotime($row['publishAt'])<=($now??time())?$row['scheduled']:$row['published'];}
  /** Resolve an ID/current URL/old alias; receives key/template, returns overlay or null for unmanaged pages, throws 404 for removed managed entries. */
  public function lookup(string $key,?string $template=null): ?array {
    $catalog=$this->read();foreach($catalog['data']['records'] as $id=>$row){$id=(string)$id;$snapshot=self::effective($row);$urls=[$id,$row['draft']['url'],...$row['aliases']];foreach(['published','scheduled'] as $kind)if($row[$kind]!==null)$urls[]=$row[$kind]['url'];if(!in_array($key,$urls,true))continue;if($row['deletedAt']!==null||($key!==$id&&!in_array($key,$row['aliases'],true)&&$key!==($snapshot['url']??'')))self::fail('missing',404);
      $document=($snapshot??$row['draft'])['document'];$document['status']=$snapshot===null?'draft':'published';$document=$this->store->document($id,JsonStore::encode($document),$template);return ['data'=>$document,'revision'=>$catalog['revision']];
    }return null;
  }
  /** Check all managed identities and reserved draft/published/alias URLs; receives identifier, returns bool without writes, preventing legacy creation from shadowing managed routes. */
  public function reserved(string $key): bool {foreach($this->read()['data']['records'] as $id=>$row)if(in_array($key,[$id,$row['draft']['url'],$row['published']['url']??'',$row['scheduled']['url']??'',...$row['aliases']],true))return true;return false;}
  /** Return the effective public URL for an immutable ID; receives ID/base, returns stable local address, preserving unmanaged routes. */
  public function url(string $id,string $base=''): string {$unified=(new Posts($this->store))->nativeUrl('pages',$id,$base);if($unified!==null)return $unified;$row=$this->read()['data']['records'][$id]??null;$slug=$row!==null?(self::effective($row)['url']??$row['draft']['url']):$id;return rtrim($base,'/').'/'.($slug==='home'?'':rawurlencode($slug));}
  /** Return managed snapshots for catalog merge; receives template/optional raw-source flag, excludes removed records, returns keyed read results without writes. */
  public function overlays(?string $template=null,bool $original=false): array {$items=[];$catalog=$this->read($original);foreach($catalog['data']['records'] as $id=>$row){$id=(string)$id;if($row['deletedAt']!==null){$items[$id]=null;continue;}$live=self::effective($row);$document=($live??$row['draft'])['document'];$document['status']=$live===null?'draft':'published';$items[$id]=['data'=>$this->store->document($id,JsonStore::encode($document),$template),'revision'=>$catalog['revision']];}return $items;}
  /** Ensure unique URLs and acyclic valid parents against catalog and legacy files; receives updated catalog, rejects collisions before commit. */
  private function relationships(array $data): void {
    $owners=[];foreach(glob($this->store->config->data['contents'].'/pages/*.json')?:[] as $file)$owners[basename($file,'.json')]=basename($file,'.json');
    foreach($data['records'] as $id=>$row){$id=(string)$id;foreach(array_unique([$id,$row['draft']['url'],...$row['aliases'],...array_map(static fn(array $s):string=>$s['url'],array_filter([$row['published'],$row['scheduled']]))]) as $url){if((new Posts($this->store))->reserved($url,'pages',$id))self::fail('collision',409);if(isset($owners[$url])&&$owners[$url]!==$id)self::fail('collision',409);$owners[$url]=$id;}}
    foreach($data['records'] as $id=>$row){$id=(string)$id;$seen=[$id];$parent=$row['parent'];while($parent!==''){if(in_array($parent,$seen,true)||!self::identifier($parent)||(!isset($data['records'][$parent])&&!is_file($this->store->config->data['contents'].'/pages/'.$parent.'.json'))||isset($data['records'][$parent]['deletedAt']))self::fail('parent');$seen[]=$parent;$parent=$data['records'][$parent]['parent']??'';if(count($seen)>12)self::fail('parent');}}
  }
  /** Atomically replace one regular JSON file under the shared transaction; receives contained file/data, creates only private parent directories, propagates storage failures. */
  private function atomic(string $file,array $data): void {
    $raw=JsonStore::encode($data);if(strlen($raw)>self::LIMIT)self::fail();ExtensionDocument::atomic($file,$raw);
  }
  /** Commit an editorial operation with catalog/site CAS and private recovery history; receives ID/action/input/revisions, returns new catalog and ID, never alters legacy source files. */
  public function change(string $id,string $action,array $input,string $revision,string $siteRevision): array {
    return $this->store->transaction(function()use($id,$action,$input,$revision,$siteRevision):array{
      (new Posts($this->store))->guard('pages',$id);
      $current=$this->read(true);if(!hash_equals($current['revision'],$revision)||!hash_equals($this->store->read('site')['revision'],$siteRevision))self::fail('conflict',409);$data=$current['data'];$now=gmdate('Y-m-d\TH:i:s\Z');
      if($action==='create'||$action==='duplicate'){$source=$action==='duplicate'?$this->record($id):null;$id='p-'.bin2hex(random_bytes(8));$row=['id'=>$id,'parent'=>'','model'=>$input['model']??'blank','createdAt'=>$now,'updatedAt'=>$now,'deletedAt'=>null,'publishAt'=>'','aliases'=>[],'draft'=>$source['draft']??$input['snapshot'],'published'=>null,'scheduled'=>null];$row['draft']['document']['slug']=$id;$row['draft']['url']=$input['url']??$row['draft']['url'];$row['draft']['seo']['canonical']='';}
      else {$row=$data['records'][$id]??$this->legacy($id);if($row['deletedAt']!==null&&!in_array($action,['recover','restore'],true))self::fail('missing',404);}
      if(!in_array($row['model'],['blank','institutional','content','landing','legacy'],true)||!is_string($input['parent']??''))self::fail();
      if(!isset($data['records'][$id])&&!in_array($action,['create','duplicate'],true)&&!hash_equals($this->store->read($id,null,true)['revision'],$input['sourceRevision']??''))self::fail('conflict',409);
      $previous=$data['records'][$id]??($action==='create'||$action==='duplicate'?null:$row);
      if(in_array($action,['save','create'],true)){if(!array_key_exists('editorial',$input['snapshot']))$input['snapshot']['editorial']=$row['draft']['editorial']??self::editorial();$row['draft']=$input['snapshot'];$row['draft']['document']['slug']=$id;$row['draft']['document']['status']='draft';$row['parent']=$input['parent']??'';$row['model']=$input['model']??$row['model'];}
      elseif($action==='publish'||$action==='schedule'){$warnings=(new Components(new ContentContext($this->store,$this->store->read('site')['data']['template'],'',$id)))->validateBindings($row['draft']['document']['markup']['bindings']??[]);if($warnings!==[])self::fail('dependencies');$row['draft']['editorial']['review']=false;$snapshot=$row['draft'];$snapshot['document']['status']='published';$date=isset($input['publishAt'])?self::date($input['publishAt']):$now;if($action==='schedule'&&strtotime($date)<=time())self::fail();$snapshot['publishedAt']=$date;$effective=self::effective($row);if($effective!==null)$row['published']=$effective;if($effective!==null&&$effective['url']!==$snapshot['url'])$row['aliases']=array_values(array_unique([...$row['aliases'],$effective['url']]));if($action==='schedule'||strtotime($date)>time()){$row['scheduled']=$snapshot;$row['publishAt']=$date;}else{$row['published']=$snapshot;$row['scheduled']=null;$row['publishAt']=$date;}}
      elseif($action==='unpublish'){$row['published']=null;$row['scheduled']=null;$row['publishAt']='';}
      elseif($action==='trash'){$row['deletedAt']=$now;}
      elseif($action==='recover'){$row['deletedAt']=null;$row['published']=null;$row['scheduled']=null;$row['publishAt']='';}
      elseif($action==='restore'){$history=$this->history($id);$backup=$history[$input['backup']??'']??null;if($backup===null)self::fail();$row['draft']=$backup['record']['draft'];if($row['deletedAt']!==null){$row['published']=null;$row['scheduled']=null;$row['publishAt']='';}$row['deletedAt']=null;}
      elseif($action!=='duplicate')self::fail();
      if(in_array($action,['create','save','duplicate','restore'],true)){$row['draft']['parent']=$action==='restore'?($row['draft']['parent']??''):$row['parent'];$row['parent']=$row['draft']['parent'];$row['draft']['updatedAt']=$now;}if(count($row['aliases'])>50)self::fail();$row['draft']['document']['status']='draft';$row['draft']=$this->snapshot($id,$row['draft']);$row['updatedAt']=$now;$data['records'][$id]=$row;if(count($data['records'])>200)self::fail();$this->relationships($data);
      if($previous!==null){$history=$this->history($id);$entry=gmdate('Ymd-His').'-'.bin2hex(random_bytes(6));$history=[$entry=>['at'=>$now,'action'=>$action,'record'=>$previous]]+$history;$history=array_slice($history,0,20,true);$this->atomic($this->file($id).'/history.json',['entries'=>$history]);}
      $this->atomic($this->file(),$data);return $this->read()+['id'=>$id];
    });
  }
  /** Read bounded private recovery entries for one ID; receives ID, returns entries with operation descriptions, never exposes them through public services. */
  public function history(string $id): array {$file=$this->file($id).'/history.json';Lifecycle::path($file);if(!file_exists($file))return [];if(!is_file($file)||filesize($file)>self::LIMIT)self::fail('storage',503);try{$data=json_decode((string)file_get_contents($file),true,64,JSON_THROW_ON_ERROR);}catch(\JsonException){self::fail();}if(!is_array($data['entries']??null)||count($data['entries'])>20)self::fail();foreach($data['entries'] as $key=>$entry){if(!preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{12}$/D',$key)||!is_string($entry['action']??null)||!is_array($entry['record']['draft']??null))self::fail();self::date($entry['at']??null);$this->snapshot($id,$entry['record']['draft']);}return $data['entries'];}
  /** Derive editorial state from content, excluding publication-only timestamps; receives row, returns a dictionary suffix without persistence. */
  public static function status(array $row): string {if($row['deletedAt']!==null)return 'deleted';if($row['scheduled']!==null&&strtotime($row['publishAt'])>time())return 'scheduled';$live=self::effective($row);if($live===null)return ($row['draft']['editorial']['review']??false)?'pending':'draft';$draft=$row['draft'];$draft['document']['status']='published';unset($live['publishedAt'],$draft['publishedAt']);return $live===$draft?'published':'modified';}
  /** Resolve current document and SEO/social fallback for public rendering; receives page/base/settings/preview flag, returns safe plain values only. */
  public function metadata(array $page,string $base,array $settings,bool $preview=false): array {
    $unified=(new Posts($this->store))->metadata($page['slug'],$settings,$preview);if($unified!==null)return $unified;
    $row=$this->read()['data']['records'][$page['slug']]??null;$snapshot=$row===null?null:($preview?$row['draft']:self::effective($row));$seo=$snapshot['seo']??self::seo();$meta=$page['meta'];$title=$seo['title']?:$meta['title'];$description=$seo['description']?:$meta['description'];$image=$seo['socialImage']?:($meta['image']?:$settings['image']);
    return ['title'=>$title,'description'=>$description,'canonical'=>$seo['canonical']?:rtrim($settings['canonical'],'/').'/'.($page['slug']==='home'?'':($snapshot['url']??$page['slug'])),'robots'=>$preview?'noindex, nofollow':($seo['noindex']?'noindex, follow':'index, follow'),'socialTitle'=>$seo['socialTitle']?:$title,'socialDescription'=>$seo['socialDescription']?:$description,'image'=>$image,'imageAlt'=>$seo['socialImage']!==''?$seo['socialImageAlt']:($meta['image']!==''?$meta['imageAlt']:$settings['imageAlt'])];
  }
  /** Export only published/scheduled records to fresh staging; receives target, excludes private drafts/history/trash while retaining future authorized transitions. */
  public function export(string $target): void {$data=$this->read(true)['data'];foreach((new Posts($this->store))->read()['data']['records'] as $owned)if($owned['source']['plugin']==='pages')unset($data['records'][$owned['source']['id']]);$data['records']=array_filter($data['records'],static fn(array $row):bool=>$row['deletedAt']===null&&($row['published']!==null||$row['scheduled']!==null));foreach($data['records'] as &$row)$row['draft']=$row['scheduled']??$row['published'];unset($row);if($data['records']===[])return;$this->atomic($target.'/system/storage/contents/page-publications/catalog.json',$data);}
}
