<?php
/** Read-only Homepage publication contract: one effective scheduled snapshot, original native fallback, typed HTML/source bodies and no rendering writes. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Homepages {
  public const LIMIT=2097152;
  /** Bind store without I/O; receive shared store for the catalog and public context. */
  public function __construct(public readonly JsonStore $store) {}
  /** Validate exact UTC seconds or an empty historic value; receive timestamp/empty flag, return it or 422 without normalization/data loss. */
  public static function date(mixed $value,bool $empty=false): string {
    if($empty&&$value==='')return '';if(!is_string($value)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$value))throw new HttpError(422,'Invalid publication timestamp.','homepage.error.contract');$date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new \DateTimeZone('UTC'));if($date===false||$date->format('Y-m-d\TH:i:s\Z')!==$value)throw new HttpError(422,'Invalid publication calendar.','homepage.error.contract');return $value;
  }
  /** Validate one portable publication body; receive definition/legacy allowance, return unchanged typed content or 422, no providers executed. */
  public static function body(array $body,bool $legacy=false): array {
    if(count($body)!==5||array_diff(array_keys($body),['mode','html','bindings','source','publicationAt'])!==[]||!in_array($body['mode']??null,$legacy?['legacy','html','source']:['html','source'],true)||!is_string($body['html']??null)||!is_array($body['bindings']??null)||!is_array($body['source']??null)||count($body['source'])!==2||!is_string($body['source']['plugin']??null)||!is_string($body['source']['id']??null))throw new HttpError(422,'Invalid Homepage body.','homepage.error.contract');self::date($body['publicationAt'],true);ContentMarkup::definition(['html'=>$body['html'],'bindings'=>$body['bindings']]);
    if($body['mode']==='html'&&trim($body['html'])==='')throw new HttpError(422,'Homepage HTML is empty.','homepage.error.contract');
    if($body['mode']==='source'&&(!Plugins::identifier($body['source']['plugin'])||!ContentDocuments::identifier($body['source']['id'])))throw new HttpError(422,'Invalid document reference.','homepage.error.contract');return $body;
  }
  /** Derive the preserved baseline only when no catalog exists; no writes or invented dates, receive no arguments, return protected original homepage metadata/body. */
  public function initial(): array {
    $home=$this->store->read('home')['data'];$body=['mode'=>'legacy','html'=>'','bindings'=>[],'source'=>['plugin'=>'','id'=>''],'publicationAt'=>''];return ['schema_version'=>1,'homepages'=>['legacy-home'=>['id'=>'legacy-home','name'=>$home['meta']['title'],'description'=>$home['meta']['description'],'createdAt'=>null,'updatedAt'=>null,'deletedAt'=>null,'protected'=>true,'enabled'=>true,'publishAt'=>'','draft'=>$body,'published'=>$body]]];
  }
  /** Validate a closed publication catalog; receive data, return unchanged document or 422; only the migrated baseline may be protected/legacy and historic timestamps may be null. */
  public static function validate(array $data): array {
    if(count($data)!==2||($data['schema_version']??null)!==1||!is_array($data['homepages']??null)||count($data['homepages'])>100||!isset($data['homepages']['legacy-home']))throw new HttpError(422,'Invalid Homepage catalog.','homepage.error.contract');
    foreach($data['homepages'] as $id=>$record){
      if(!is_string($id)||!Plugins::identifier($id)||!is_array($record)||count($record)!==11||array_diff(array_keys($record),['id','name','description','createdAt','updatedAt','deletedAt','protected','enabled','publishAt','draft','published'])!==[]||($record['id']??null)!==$id||!is_string($record['name']??null)||trim($record['name'])===''||strlen($record['name'])>200||!is_string($record['description']??null)||strlen($record['description'])>2000||preg_match('/[\x00-\x1F\x7F<>]/',$record['name'].$record['description'])||!is_bool($record['enabled']??null)||!is_bool($record['protected']??null)||$record['protected']!==($id==='legacy-home')||!is_array($record['draft']??null))throw new HttpError(422,'Invalid Homepage entry.','homepage.error.contract');
      foreach(['createdAt','updatedAt','deletedAt'] as $key){if(!array_key_exists($key,$record))throw new HttpError(422,'Missing Homepage timestamp.','homepage.error.contract');if($record[$key]!==null)self::date($record[$key]);}
      self::date($record['publishAt'],true);self::body($record['draft'],$record['protected']);if($record['published']!==null){if(!is_array($record['published']))throw new HttpError(422,'Invalid published snapshot.','homepage.error.contract');self::body($record['published'],$record['protected']);}
      if($id==='legacy-home'&&(!$record['enabled']||$record['deletedAt']!==null||$record['published']===null))throw new HttpError(422,'Protected fallback cannot be disabled or removed.','homepage.error.contract');
    }return $data;
  }
  /** Resolve safe catalog/history directories and ancestors; receive history flag, return path without creating it or following links. */
  public function directory(bool $history=false): string {
    $path=rtrim($this->store->config->data[$history?'storage':'contents'],'/').($history?'/homepage-catalog-history':'/homepage/publications');try{Lifecycle::path($path);}catch(\RuntimeException){throw new HttpError(503,'Unsafe Homepage storage.','homepage.error.storage');}if(file_exists($path)&&!is_dir($path))throw new HttpError(503,'Unsafe Homepage storage.','homepage.error.storage');return $path;
  }
  /** Decode bounded catalog bytes; receive JSON, return validated structure or 422, no writes or silent repair. */
  public static function decode(string $raw): array {
    if(strlen($raw)>self::LIMIT)throw new HttpError(422,'Homepage catalog exceeds limit.','homepage.error.contract');try{$data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(422,'Invalid Homepage JSON.','homepage.error.contract');}if(!is_array($data))throw new HttpError(422,'Invalid Homepage document.','homepage.error.contract');return self::validate($data);
  }
  /** Read one regular bounded fixed catalog path; no arguments/writes, absent file yields a virtual migrated baseline with the empty-file revision. */
  public function read(): array {
    $file=$this->directory().'/catalog.json';if(is_link($file))throw new HttpError(503,'Unsafe Homepage catalog.','homepage.error.storage');if(!file_exists($file))return ['data'=>$this->initial(),'revision'=>hash('sha256','')];if(!is_file($file))throw new HttpError(503,'Homepage catalog unavailable.','homepage.error.storage');$raw=file_get_contents($file,false,null,0,self::LIMIT+1);if($raw===false)throw new HttpError(503,'Homepage catalog unreadable.','homepage.error.storage');return ['data'=>self::decode($raw),'revision'=>hash('sha256',$raw)];
  }
  /** Export published snapshots only to a fresh production staging tree; receive target, omit private drafts/deleted/unpublished records and retain schedules, throw on unsafe I/O. */
  public function export(string $target): void {
    $source=$this->directory().'/catalog.json';if(!file_exists($source)&&!is_link($source))return;$data=$this->read()['data'];
    $data['homepages']=array_filter($data['homepages'],static fn(array $row):bool=>$row['deletedAt']===null&&$row['published']!==null);
    foreach($data['homepages'] as &$record)$record['draft']=$record['published'];unset($record);self::validate($data);
    $directory=$target.'/system/storage/contents/homepage/publications';Lifecycle::path($directory);if(!mkdir($directory,0770,true)&&!is_dir($directory))throw new \RuntimeException('Homepage export directory unavailable.');
    $file=$directory.'/catalog.json';$bytes=JsonStore::encode($data);if(file_exists($file)||is_link($file)||file_put_contents($file,$bytes,LOCK_EX)!==strlen($bytes))throw new \RuntimeException('Homepage export failed.');
  }
  /** Rank authorized due snapshots by effective date then immutable ID; receive catalog/clock, return records, no writes or dependence on last edit. */
  public static function candidates(array $data,?int $now=null): array {
    $now??=time();$records=array_filter($data['homepages'],static fn(array $row):bool=>$row['deletedAt']===null&&$row['enabled']&&$row['published']!==null&&($row['publishAt']===''||strtotime($row['publishAt'])<=$now));uasort($records,static fn(array $a,array $b):int=>strcmp($b['publishAt'],$a['publishAt'])?:strcmp($a['id'],$b['id']));return $records;
  }
  /** Render one body or resolve its public document; receive body/context, return null for preserved native rendering, safe HTML for new content or throw on unavailable source, no mutations. */
  public static function renderBody(array $body,ContentContext $context): ?string {
    self::body($body,$body['mode']==='legacy');if($body['mode']==='legacy')return null;if($body['mode']==='html')return ContentMarkup::render(['html'=>$body['html'],'bindings'=>$body['bindings']],$context);$source=ContentDocuments::resolve($body['source'],$context);if($source===null)throw new HttpError(422,'Document source is unavailable.','homepage.sourceUnavailable');return $source['html'];
  }
  /** Select the latest valid effective homepage, isolating lost sources; receive context/optional clock, return ID/body/rendered HTML or native fallback, never persist automatic transitions. */
  public function resolve(ContentContext $context,?int $now=null): array {
    foreach(self::candidates($this->read()['data'],$now) as $record)try{return ['id'=>$record['id'],'record'=>$record,'html'=>self::renderBody($record['published'],$context)];}catch(\Throwable $error){error_log('DBLESS-CMS homepage '.$record['id'].' '.$error::class);}
    return ['id'=>'legacy-home','record'=>null,'html'=>null];
  }
  /** Derive a translated-status key without writes; receive entry/effective ID/clock, return draft/scheduled/active/inactive/deleted. */
  public static function status(array $record,string $active,?int $now=null): string {return $record['deletedAt']!==null?'deleted':($active===$record['id']?'active':(!$record['enabled']||$record['published']===null?($record['published']===null?'draft':'inactive'):($record['publishAt']!==''&&strtotime($record['publishAt'])>($now??time())?'scheduled':'inactive')));}
}
