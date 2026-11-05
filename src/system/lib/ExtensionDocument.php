<?php
/** Bounded extension documents with private history, CAS and existing lifecycle/contents locks; no runtime public state discovery. */
declare(strict_types=1);
namespace DBLESSCMS;
final class ExtensionDocument {
  /** Receive trusted store/fixed path/default/validator, history policy and optional byte limit (at most 8 MiB); reject unsafe paths/limits, no I/O or writes. */
  public function __construct(private readonly JsonStore $store,private readonly string $relative,private readonly array $initial,private readonly \Closure $validate,private readonly bool $history=true,private readonly int $maximum=1048576) {
    if(!preg_match('~^[a-z][a-z0-9-]*(?:/[a-z][a-z0-9-]*)*\.json$~D',$relative))throw new \InvalidArgumentException('Invalid fixed extension path.');
    if($maximum<1||$maximum>8388608)throw new \InvalidArgumentException('Invalid trusted document limit.');
  }
  /** Resolve a fixed document/history path without links; receive optional history ID, return path or 503, never creates directories. */
  private function path(string $backup=''): string {
    $file=$this->store->config->data['contents'].'/'.$this->relative;
    if($backup!=='')$file=$this->store->config->data['storage'].'/extension-history/'.str_replace(['/','.json'],['-',''],$this->relative).'/'.$backup;
    try{return Lifecycle::path($file);}catch(\RuntimeException){throw new HttpError(503,'Unsafe extension document.','error.storage');}
  }
  /** Serialize bounded readable JSON without sanitizing away authorship or schema fields; receive validated object and trusted maximum (default 1 MiB, at most 8 MiB), return bytes or 422. */
  public static function encode(array $data,int $maximum=1048576): string {
    $raw=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    if($maximum<1||$maximum>8388608||strlen($raw)>$maximum)throw new HttpError(422,'Extension document too large.','error.document_size');return $raw;
  }
  /** Read bounded regular bytes from trusted path; receive filename and trusted byte maximum, return string or 503 without exposing contents in errors. */
  private static function bytes(string $file,int $maximum=1048576): string {
    if(!is_file($file)||is_link($file))throw new HttpError(503,'Extension document unavailable.','error.storage');
    $raw=file_get_contents($file,false,null,0,$maximum+1);if($raw===false||strlen($raw)>$maximum)throw new HttpError(503,'Extension document read failed.','error.storage');return $raw;
  }
  /** Decode and validate on every read; receive bytes, return validated document or 422; no cache or mutation. */
  private function decode(string $raw): array {
    try{$data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(422,'Invalid extension JSON.','error.json');}
    if(!is_array($data))throw new HttpError(422,'Invalid extension object.','error.json');return ($this->validate)($data);
  }
  /** Read document/revision; absent file yields validated additive defaults without creating state; malformed data fails explicitly. */
  public function read(): array {
    $file=$this->path();if(!file_exists($file))return ['data'=>($this->validate)($this->initial),'revision'=>hash('sha256','')];
    $raw=self::bytes($file,$this->maximum);return ['data'=>$this->decode($raw),'revision'=>hash('sha256',$raw)];
  }
  /** Atomically replace a fixed regular file with fsync and private permissions; receive file/bytes, return void or 503, remove incomplete temporary. */
  public static function atomic(string $file,string $raw): void {
    try{Lifecycle::path($file);}catch(\RuntimeException){throw new HttpError(503,'Unsafe document destination.','error.storage');}
    Security::directory(dirname($file));$temporary=tempnam(dirname($file),'.document-');if($temporary===false)throw new HttpError(503,'Document write unavailable.','error.storage');
    try{$handle=fopen($temporary,'wb');if($handle===false)throw new HttpError(503,'Document write failed.','error.storage');try{if(fwrite($handle,$raw)!==strlen($raw)||!fflush($handle)||function_exists('fsync')&&!fsync($handle))throw new HttpError(503,'Incomplete document write.','error.storage');}finally{fclose($handle);}if(!chmod($temporary,0600)||!rename($temporary,$file))throw new HttpError(503,'Document publication failed.','error.storage');}finally{if(is_file($temporary))unlink($temporary);}
  }
  /** Mutate trusted document under shared CAS lock and optional bounded non-PII history; receive revision/change, return fresh snapshot or propagate 409/validation/I/O errors. */
  public function change(string $revision,callable $change): array {
    return $this->store->transaction(function()use($revision,$change):array{$current=$this->read();if(!hash_equals($current['revision'],$revision))throw new HttpError(409,'Document changed.','error.conflict');$data=($this->validate)($change($current['data']));$raw=self::encode($data,$this->maximum);
      if($this->history){$id=(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Ymd-His-u').'-'.bin2hex(random_bytes(8)).'.json';self::atomic($this->path($id),self::encode($current['data'],$this->maximum));}
      self::atomic($this->path(),$raw);if($this->history)foreach(array_slice($this->history(),20) as $backup)if(!unlink($this->path($backup)))error_log('DBLESS-CMS extension history retention failed');return ['data'=>$data,'revision'=>hash('sha256',$raw)];});
  }
  /** Return newest-first safe history IDs without reading their data; symlinks fail closed, disabled history stays empty. */
  public function history(): array {
    if(!$this->history)return [];$directory=dirname($this->path('placeholder.json'));$ids=[];
    foreach(glob($directory.'/*.json')?:[] as $file){if(is_link($file))throw new HttpError(503,'Unsafe history file.','error.storage');if(preg_match('/^[0-9]{8}-[0-9]{6}-[0-9]{6}-[a-f0-9]{16}\.json$/D',basename($file)))$ids[]=basename($file);}rsort($ids,SORT_STRING);return $ids;
  }
  /** Restore only an existing validated history ID through CAS; receive ID/revision, return snapshot or 404/409 without accepting user paths. */
  public function restore(string $id,string $revision): array {
    $data=$this->snapshot($id);return $this->change($revision,static fn(array $current):array=>$data);
  }
  /** Read one validated existing history snapshot; receive opaque ID, return private data or 404/422/503, without restoring or accepting file paths. */
  public function snapshot(string $id): array {
    if(!in_array($id,$this->history(),true))throw new HttpError(404,'History unavailable.','error.backup');return $this->decode(self::bytes($this->path($id),$this->maximum));
  }
}
