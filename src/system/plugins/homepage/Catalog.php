<?php
/** Homepage catalog mutations: shared CAS/site revision, separate draft/published snapshots, atomic JSON, private bounded history and soft deletion. */
declare(strict_types=1);
namespace DBLESSCMS;
final class HomepageCatalog {
  private Homepages $reader;
  /** Receive shared store; create a read-only catalog adapter without I/O. */
  public function __construct(private readonly JsonStore $store) {$this->reader=new Homepages($store);}
  /** Read current catalog/revision without writes; no arguments, return validated snapshot or explicit storage/contract error. */
  public function read(): array {return $this->reader->read();}
  /** Encode human-editable bounded JSON; receive validated document, return bytes or 422 without file writes. */
  public static function encode(array $data): string {$raw=JsonStore::encode($data);if(strlen($raw)>Homepages::LIMIT)throw new HttpError(422,'Homepage catalog exceeds limit.','homepage.error.contract');return $raw;}
  /** Atomically replace one fixed regular file; receive path/bytes, fsync/rename with cleanup, throw 503 on I/O errors and never follow links. */
  private function atomic(string $file,string $raw): void {
    if(is_link($file))throw new HttpError(503,'Unsafe Homepage file.','homepage.error.storage');Security::directory(dirname($file));$temporary=tempnam(dirname($file),'.homepage-catalog-');if($temporary===false)throw new HttpError(503,'Homepage write unavailable.','homepage.error.storage');
    try{$stream=fopen($temporary,'wb');if($stream===false)throw new HttpError(503,'Homepage stream unavailable.','homepage.error.storage');try{if(fwrite($stream,$raw)!==strlen($raw)||!fflush($stream)||(function_exists('fsync')&&!fsync($stream)))throw new HttpError(503,'Incomplete Homepage write.','homepage.error.storage');}finally{fclose($stream);}if(!chmod($temporary,0660)||!rename($temporary,$file))throw new HttpError(503,'Homepage commit failed.','homepage.error.storage');}finally{if(is_file($temporary))unlink($temporary);}
  }
  /** Apply a validated callback under catalog/site CAS and global lock; receive revisions/change, return new snapshot; backup first, never silently rebase stale edits. */
  private function mutate(string $revision,string $siteRevision,callable $change): array {
    return $this->store->transaction(function()use($revision,$siteRevision,$change):array{
      $current=$this->read();if(!hash_equals($current['revision'],$revision)||!hash_equals($this->store->read('site')['revision'],$siteRevision))throw new HttpError(409,'Stale Homepage/site revision.','homepage.error.conflict');$data=Homepages::validate($change($current['data']));$raw=self::encode($data);$history=$this->reader->directory(true);Security::directory($history);$backup=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Ymd-His-u').'-'.bin2hex(random_bytes(8)).'.json';$this->atomic($history.'/'.$backup,self::encode($current['data']));$this->atomic($this->reader->directory().'/catalog.json',$raw);
      foreach(array_slice($this->history(),20) as $id)if(!unlink($history.'/'.$id))throw new HttpError(503,'Homepage history retention failed.','homepage.error.storage');return $this->read();
    });
  }
  /** Validate bindings against current service schemas; receive body, return nothing, preserve unknown references as diagnostics without executing providers. */
  private function bindings(array $body): void {$site=$this->store->read('site')['data'];(new Components(new ContentContext($this->store,$site['template'],'','home')))->validateBindings($body['bindings']);}
  /** Authorize a published snapshot for immediate/future display; receive record/manual-now flag, return changed entry; immediate lost sources fail without replacing the current publication. */
  private function authorize(array $record,bool $now): array {
    $body=$record['draft'];$at=$now||$body['publicationAt']===''?gmdate('Y-m-d\TH:i:s\Z'):$body['publicationAt'];$this->bindings($body);
    if($body['mode']==='source'&&strtotime($at)<=time()){$site=$this->store->read('site')['data'];if(ContentDocuments::resolve($body['source'],new ContentContext($this->store,$site['template'],'','home'))===null)throw new HttpError(422,'Document source unavailable.','homepage.sourceUnavailable');}
    $record['enabled']=true;$record['publishAt']=$at;$body['publicationAt']=$at;$record['draft']=$body;$record['published']=$body;return $record;
  }
  /** Save a draft or explicitly publish/schedule it; receive ID/name/description/body/revisions/publish flag, return snapshot plus immutable ID; validation/CAS failures preserve public content. */
  public function save(string $id,string $name,string $description,array $body,string $revision,string $siteRevision,bool $publish=false): array {
    $assigned=$id===''?'home-'.bin2hex(random_bytes(10)):$id;$result=$this->mutate($revision,$siteRevision,function(array $data)use($id,$assigned,$name,$description,$body,$publish):array{
      $now=gmdate('Y-m-d\TH:i:s\Z');$existing=$id===''?null:($data['homepages'][$id]??throw new HttpError(404,'Homepage unavailable.','error.not_found'));if($existing!==null&&$existing['deletedAt']!==null)throw new HttpError(404,'Homepage is removed.','error.not_found');Homepages::body($body,$assigned==='legacy-home');$this->bindings($body);
      $record=$existing??['id'=>$assigned,'createdAt'=>$now,'deletedAt'=>null,'protected'=>false,'enabled'=>false,'publishAt'=>'','published'=>null];$record['name']=$name;$record['description']=$description;$record['updatedAt']=$now;$record['draft']=$body;if($publish)$record=$this->authorize($record,false);$data['homepages'][$assigned]=$record;return $data;
    });$result['id']=$assigned;return $result;
  }
  /** Activate now, deactivate, soft-delete or recover one entry; receive ID/operation/revisions, return snapshot, protect baseline and preserve scheduled snapshots/history. */
  public function action(string $id,string $operation,string $revision,string $siteRevision): array {
    return $this->mutate($revision,$siteRevision,function(array $data)use($id,$operation):array{
      $record=$data['homepages'][$id]??throw new HttpError(404,'Homepage unavailable.','error.not_found');$now=gmdate('Y-m-d\TH:i:s\Z');
      if($operation==='recover'){if($record['deletedAt']===null)throw new HttpError(409,'Homepage is not removed.','homepage.error.conflict');$record['deletedAt']=null;$record['enabled']=false;}
      else{if($record['deletedAt']!==null)throw new HttpError(404,'Homepage is removed.','error.not_found');
        if($operation==='activate')$record=$this->authorize($record,true);
        elseif(in_array($operation,['deactivate','remove'],true)){if($record['protected'])throw new HttpError(422,'Protected Homepage.','homepage.protected');$record['enabled']=false;if($operation==='remove')$record['deletedAt']=$now;}
        else throw new HttpError(404,'Unknown action.','error.not_found');
      }$record['updatedAt']=$now;$data['homepages'][$id]=$record;return $data;
    });
  }
  /** List only owned regular revision files newest first; no arguments/writes, return closed IDs without following links. */
  public function history(): array {
    $directory=$this->reader->directory(true);$result=[];foreach(glob($directory.'/*.json')?:[] as $file)if(!is_link($file)&&is_file($file)&&preg_match('/^[0-9]{8}-[0-9]{6}-[0-9]{6}-[a-f0-9]{16}\.json$/D',basename($file)))$result[]=basename($file);rsort($result,SORT_STRING);return $result;
  }
  /** Restore a whole revision under current CAS/site revision; receive opaque backup/revisions, return snapshot or 422/409, no arbitrary file paths. */
  public function restore(string $id,string $revision,string $siteRevision): array {
    if(!in_array($id,$this->history(),true))throw new HttpError(422,'Unknown Homepage revision.','homepage.error.contract');$file=$this->reader->directory(true).'/'.$id;$raw=file_get_contents($file,false,null,0,Homepages::LIMIT+1);if($raw===false)throw new HttpError(503,'Homepage backup unreadable.','homepage.error.storage');$data=Homepages::decode($raw);return $this->mutate($revision,$siteRevision,static fn(array $current):array=>$data);
  }
}
