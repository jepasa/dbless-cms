<?php
/** Blog persistence: fixed private paths, bounded JSON, shared CMS lock, compare-and-swap, atomic publication and recoverable history. */
declare(strict_types=1);
namespace DBLESSCMS;
final class BlogStore {
  /** Bind a shared JsonStore and fixed document kind; takes services/catalog|comments, performs no I/O, rejects programmer errors. */
  public function __construct(private readonly JsonStore $store,private readonly string $kind='catalog') {
    if (!in_array($kind,['catalog','comments'],true)) throw new \InvalidArgumentException('Unknown blog document.');
  }
  /** Validate fixed paths and every ancestor without following links; takes history flag, returns directory or HTTP 503. */
  private function directory(bool $history=false): string {
    $base=$history?$this->store->config->data['storage']:$this->store->config->data['contents'];
    $path=rtrim($base,'/').($history?'/blog-history/'.$this->kind:'/blog');
    try {Lifecycle::path($path);}catch (\RuntimeException) {throw new HttpError(503,'Blog storage unavailable.','blog.error.storage');}
    if (file_exists($path)&&!is_dir($path)) throw new HttpError(503,'Blog storage unavailable.','blog.error.storage');return $path;
  }
  /** Validate the selected document; takes decoded data, returns contract or raises 422 with no writes. */
  private function validate(array $data): array {return $this->kind==='catalog'?BlogModel::catalog($data):BlogModel::comments($data);}
  /** Serialize bounded maps as pretty JSON; takes validated document, returns bytes or throws on encoding/size errors. */
  public static function encode(array $data): string {
    foreach (['articles','categories','tags','authors','comments'] as $key) if (isset($data[$key])&&$data[$key]===[]) $data[$key]=(object)[];
    $raw=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
    BlogModel::expect(strlen($raw)<=BlogModel::LIMIT);return $raw;
  }
  /** Decode a bounded private document; takes bytes, returns validated data, rejecting corruption with 422. */
  private function decode(string $raw): array {
    BlogModel::expect(strlen($raw)<=BlogModel::LIMIT);
    try {$data=json_decode($raw,true,24,JSON_THROW_ON_ERROR);}catch (\JsonException) {throw new HttpError(422,'Invalid blog JSON.','blog.error.contract');}
    BlogModel::expect(is_array($data));return $this->validate($data);
  }
  /** Read a regular bounded file without links; takes fixed path, returns bytes or HTTP 503, never creates content. */
  private function bytes(string $file): string {
    if (is_link($file)||!is_file($file)) throw new HttpError(503,'Blog storage unavailable.','blog.error.storage');
    $raw=file_get_contents($file,false,null,0,BlogModel::LIMIT+1);
    if ($raw===false) throw new HttpError(503,'Blog read failed.','blog.error.storage');return $raw;
  }
  /** Read catalog/revision; no arguments or writes, absent document means an empty installation, corruption remains an explicit error. */
  public function read(): array {
    $file=$this->directory().'/'.$this->kind.'.json';
    if (is_link($file)) throw new HttpError(503,'Unsafe blog storage.','blog.error.storage');
    if (!file_exists($file)) return ['data'=>$this->kind==='catalog'?BlogModel::emptyCatalog():['schema_version'=>1,'comments'=>[]],'revision'=>hash('sha256','')];
    $raw=$this->bytes($file);return ['data'=>$this->decode($raw),'revision'=>hash('sha256',$raw)];
  }
  /** Publish bytes using same-directory secure temporary file and rename; takes fixed path/bytes, cleans temporary on all failures, raises 503. */
  private function atomic(string $file,string $raw): void {
    if (is_link($file)) throw new HttpError(503,'Unsafe blog storage.','blog.error.storage');
    $temporary=tempnam(dirname($file),'.blog-');if ($temporary===false) throw new HttpError(503,'Blog write failed.','blog.error.storage');
    try {
      $stream=fopen($temporary,'wb');if ($stream===false) throw new HttpError(503,'Blog write failed.','blog.error.storage');
      try {if (fwrite($stream,$raw)!==strlen($raw)||!fflush($stream)||(function_exists('fsync')&&!fsync($stream))) throw new HttpError(503,'Incomplete blog write.','blog.error.storage');}finally {fclose($stream);}
      if (!chmod($temporary,0600)||!rename($temporary,$file)) throw new HttpError(503,'Blog publication failed.','blog.error.storage');
    }finally {if (is_file($temporary)) unlink($temporary);}
  }
  /** Apply a CAS mutation under the CMS lock; takes expected revision/callback, returns snapshot, preserves prior bytes on validation/conflict and records private revision-only audit. */
  public function mutate(string $revision,callable $change): array {
    return $this->store->transaction(function() use($revision,$change): array {
      $current=$this->read();if (!hash_equals($current['revision'],$revision)) throw new HttpError(409,'Blog revision conflict.','blog.error.conflict');
      $data=$this->validate($change($current['data']));$raw=self::encode($data);
      $directory=$this->directory();Security::directory($directory);$history=$this->directory(true);Security::directory($history);
      $audit=$this->store->config->data['storage'].'/audit.jsonl';
      if (is_link($audit)) throw new HttpError(503,'Unsafe blog audit.','blog.error.storage');
      $this->atomic($history.'/'.(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Ymd-His-u').'-'.bin2hex(random_bytes(8)).'.json',self::encode($current['data']));
      $this->atomic($directory.'/'.$this->kind.'.json',$raw);
      foreach (array_slice($this->history(),20) as $backup) if (!unlink($history.'/'.$backup)) throw new HttpError(503,'Blog retention failed.','blog.error.storage');
      if (file_put_contents($audit,json_encode(['at'=>gmdate(DATE_ATOM),'event'=>'blog-'.$this->kind.'-save','revision'=>hash('sha256',$raw)],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX)===false) throw new HttpError(503,'Blog audit failed.','blog.error.storage');
      return ['data'=>$data,'revision'=>hash('sha256',$raw)];
    });
  }
  /** List regular backup IDs newest first; no arguments or writes, refuses unsafe ancestors and ignores unrelated files. */
  public function history(): array {
    $directory=$this->directory(true);$ids=[];
    foreach (glob($directory.'/*.json')?:[] as $file) if (!is_link($file)&&is_file($file)&&preg_match('/^\d{8}-\d{6}-\d{6}-[a-f0-9]{16}\.json$/D',basename($file))) $ids[]=basename($file);
    rsort($ids,SORT_STRING);return $ids;
  }
  /** Restore a validated backup with current CAS and new backup; takes opaque ID/revision, returns snapshot or 422/409/503, never accepts arbitrary paths. */
  public function restore(string $id,string $revision): array {
    if (!in_array($id,$this->history(),true)) throw new HttpError(422,'Invalid blog backup.','blog.error.contract');
    $data=$this->decode($this->bytes($this->directory(true).'/'.$id));return $this->mutate($revision,static fn(array $current): array=>$data);
  }
}
