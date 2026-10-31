<?php
/** Homepage draft repository: one published page, separate per-template private drafts, shared lock/CAS, recoverable reset and atomic public commit. */
declare(strict_types=1);
namespace DBLESSCMS;
final class HomepageStore {
  /** Keep shared store for revisioned page access; receive store, no reads or writes until requested. */
  public function __construct(private readonly JsonStore $store) {}
  /** Resolve a fixed private directory/file after checking every ancestor; receive template/history flag, return path or 503, never create a directory. */
  private function path(string $template,bool $history=false): string {
    if(!Templates::identifier($template))throw new HttpError(422,'Invalid template.','homepage.error.contract');
    $base=$history?$this->store->config->data['storage'].'/homepage-history':$this->store->config->data['contents'].'/homepage';$cursor='';
    foreach(explode('/',ltrim($base,'/')) as $part){$cursor.='/'.$part;if(is_link($cursor)||(file_exists($cursor)&&!is_dir($cursor)))throw new HttpError(503,'Unsafe draft storage.','homepage.error.storage');}
    $file=$base.'/'.$template.'.json';if(is_link($file))throw new HttpError(503,'Unsafe draft file.','homepage.error.storage');return $file;
  }
  /** Read a bounded regular JSON envelope; receive file, return data or throw 422/503 without disclosing its contents. */
  private function decode(string $file): array {
    if(is_link($file)||!is_file($file))throw new HttpError(503,'Draft unavailable.','homepage.error.storage');$raw=file_get_contents($file,false,null,0,1048577);
    if($raw===false||strlen($raw)>1048576)throw new HttpError(503,'Draft exceeds limit.','homepage.error.storage');
    try{$data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(422,'Invalid draft JSON.','homepage.error.contract');}
    if(!is_array($data)||count($data)!==5||($data['schema_version']??null)!==1||!is_string($data['template']??null)||!Templates::identifier($data['template'])||!is_string($data['siteRevision']??null)||!preg_match('/^[a-f0-9]{64}$/D',$data['siteRevision'])||!is_string($data['pageRevision']??null)||!preg_match('/^[a-f0-9]{64}$/D',$data['pageRevision'])||!is_array($data['document']??null))throw new HttpError(422,'Invalid draft envelope.','homepage.error.contract');
    return ['data'=>$data,'revision'=>hash('sha256',$raw)];
  }
  /** Read a template's saved draft or an empty snapshot; receive template, return data/revision without creating storage, errors remain explicit. */
  public function read(string $template): array {
    $file=$this->path($template);if(!file_exists($file))return ['data'=>null,'revision'=>hash('sha256','')];$snapshot=$this->decode($file);
    if($snapshot['data']['template']!==$template)throw new HttpError(422,'Draft template mismatch.','homepage.error.contract');return $snapshot;
  }
  /** Validate public document identity/content/active and fallback contracts; receive raw JSON/template, return normalized published home without writes, reject invalid component parameters. */
  public function document(string $raw,string $template): array {
    $document=$this->store->document('home',$raw,$template);if($document['page']!=='home')throw new HttpError(422,'Homepage layout required.','homepage.error.contract');
    $this->store->document('home',JsonStore::encode($document),Templates::DEFAULT_ID);return $document;
  }
  /** Publish bytes with same-directory rename and fsync; receive fixed file/raw bytes, return nothing; propagate failure and remove only this temporary file. */
  private function atomic(string $file,string $raw): void {
    if(is_link($file)||strlen($raw)>1048576)throw new HttpError(503,'Unsafe draft write.','homepage.error.storage');Security::directory(dirname($file));$temporary=tempnam(dirname($file),'.homepage-');
    if($temporary===false)throw new HttpError(503,'Draft write unavailable.','homepage.error.storage');
    try{$stream=fopen($temporary,'wb');if($stream===false)throw new HttpError(503,'Draft stream unavailable.','homepage.error.storage');
      try{if(fwrite($stream,$raw)!==strlen($raw)||!fflush($stream)||(function_exists('fsync')&&!fsync($stream)))throw new HttpError(503,'Incomplete draft write.','homepage.error.storage');}finally{fclose($stream);}
      if(!chmod($temporary,0660)||!rename($temporary,$file))throw new HttpError(503,'Draft commit unavailable.','homepage.error.storage');
    }finally{if(is_file($temporary))unlink($temporary);}
  }
  /** Save only a private draft under shared CAS; receive document and draft/site/page revisions, return new snapshot; stale source/template never rebases silently or changes the public homepage. */
  public function save(string $raw,string $revision,string $siteRevision,string $pageRevision): array {
    return $this->store->transaction(function()use($raw,$revision,$siteRevision,$pageRevision):array{
      $site=$this->store->read('site');$template=$site['data']['template'];$home=$this->store->read('home');$draft=$this->read($template);
      if(!hash_equals($site['revision'],$siteRevision)||!hash_equals($home['revision'],$pageRevision)||!hash_equals($draft['revision'],$revision))throw new HttpError(409,'Stale draft or source.','homepage.error.conflict');
      $document=$this->document($raw,$template);$data=['schema_version'=>1,'template'=>$template,'siteRevision'=>$siteRevision,'pageRevision'=>$pageRevision,'document'=>$document];$this->atomic($this->path($template),JsonStore::encode($data));return $this->read($template);
    });
  }
  /** Publish an already saved draft under draft/site/home revisions; receive draft revision, return published page; one JsonStore atomic commit preserves public history, failed validation never replaces public bytes. */
  public function publish(string $revision): array {
    return $this->store->transaction(function()use($revision):array{
      $site=$this->store->read('site');$template=$site['data']['template'];$draft=$this->read($template);
      if($draft['data']===null||!hash_equals($draft['revision'],$revision))throw new HttpError(409,'Draft changed.','homepage.error.conflict');$data=$draft['data'];$home=$this->store->read('home');
      if(!hash_equals($site['revision'],$data['siteRevision'])||!hash_equals($home['revision'],$data['pageRevision']))throw new HttpError(409,'Published source or template changed.','homepage.error.conflict');
      $document=$this->document(JsonStore::encode($data['document']),$template);$this->store->save('home',JsonStore::encode($document),$home['revision']);return $this->store->read('home');
    });
  }
  /** Determine whether saved draft already equals the published bytes after normalization; receive snapshot/home, return bool without writes or automatic rebasing. */
  public function published(array $draft,array $home): bool {return $draft['data']!==null&&hash_equals(hash('sha256',JsonStore::encode($draft['data']['document'])),$home['revision']);}
  /** Replace a draft with current published content after explicit reset; receive current draft/site/page revisions, archive previous draft before replacement, return new draft or 409/503 without public mutation. */
  public function reset(string $revision,string $siteRevision,string $pageRevision): array {
    return $this->store->transaction(function()use($revision,$siteRevision,$pageRevision):array{
      $site=$this->store->read('site');$template=$site['data']['template'];$draft=$this->read($template);$home=$this->store->read('home');
      if(!hash_equals($draft['revision'],$revision)||!hash_equals($site['revision'],$siteRevision)||!hash_equals($home['revision'],$pageRevision))throw new HttpError(409,'Draft changed.','homepage.error.conflict');
      if($draft['data']!==null){$history=dirname($this->path($template,true));$this->atomic($history.'/'.$template.'-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(8)).'.json',JsonStore::encode($draft['data']));$archives=array_values(array_filter(glob($history.'/'.$template.'-*.json')?:[],static fn(string $file):bool=>!is_link($file)&&is_file($file)));rsort($archives,SORT_STRING);foreach(array_slice($archives,20) as $file)if(!unlink($file))throw new HttpError(503,'Draft archive retention failed.','homepage.error.storage');}
      return $this->save(JsonStore::encode($home['data']),$revision,$siteRevision,$pageRevision);
    });
  }
  /** Restore a published revision under site/page CAS using core recovery; receive backup/revisions, return restored public page; errors preserve the current page and draft. */
  public function restore(string $backup,string $siteRevision,string $pageRevision): array {
    return $this->store->transaction(function()use($backup,$siteRevision,$pageRevision):array{
      if(!hash_equals($this->store->read('site')['revision'],$siteRevision))throw new HttpError(409,'Template changed.','homepage.error.conflict');$this->store->restore('home',$backup,$pageRevision);return $this->store->read('home');
    });
  }
}
