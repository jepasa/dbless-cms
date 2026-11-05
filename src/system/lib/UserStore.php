<?php
/** Private Users catalog persistence: bounded JSON, exclusive transactions, optimistic revisions and atomic replacement; never exported as content. */
declare(strict_types=1);
/** Identity persistence namespace; storage errors fail closed. */
namespace DBLESSCMS;

/** Own the single transactional identity catalog and its separate installation encryption key. */
final class UserStore {
  /** Maximum decoded catalog bytes; bounds memory use before parsing untrusted/corrupt private data. */
  private const LIMIT = 8388608;
  /** @var string Private identity directory outside editorial contents. */
  private readonly string $directory;
  /** @var string Separate private key path; its presence also prevents legacy fallback after migration. */
  private readonly string $keyFile;

  /** Resolve trusted storage paths without creating accounts or files; receives application configuration. */
  public function __construct(Config $config) {
    $this->directory=rtrim($config->data['storage'],'/').'/users';$this->keyFile=rtrim($config->data['storage'],'/').'/keys/users.key';
    foreach([$this->directory,$this->keyFile] as $path){$cursor='';foreach(explode('/',ltrim($path,'/')) as $part){$cursor.='/'.$part;if(is_link($cursor))self::failure();}}
  }

  /** Raise a localized non-disclosing persistence failure; never returns. */
  private static function failure(): never {throw new HttpError(503,'Identity storage unavailable.','users.error.storage');}

  /** Report migrated identity state even if catalog bytes were removed; no fallback is permitted when its key/marker survives. */
  public function exists(): bool {return file_exists($this->directory.'/catalog.json')||file_exists($this->keyFile);}

  /** Read a regular bounded catalog and its byte revision, or an explicit empty pre-migration state; never writes. */
  public function read(): array {
    $file=$this->directory.'/catalog.json';clearstatcache(true,$file);if(is_link($file))self::failure();
    if(!file_exists($file)){if(file_exists($this->keyFile))self::failure();return ['data'=>null,'revision'=>hash('sha256','')];}
    // An existing catalog requires its existing key. This path cannot create a key or migration marker.
    $this->storedKey();
    if(!is_file($file)||filesize($file)>self::LIMIT)self::failure();$raw=@file_get_contents($file,false,null,0,self::LIMIT+1);if(!is_string($raw)||strlen($raw)>self::LIMIT)self::failure();
    try{$data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){self::failure();}
    if(!is_array($data))self::failure();self::validate($data);return ['data'=>$data,'revision'=>hash('sha256',$raw)];
  }

  /** Read exactly one already-existing key without directory creation, file creation or writes; missing/damaged state raises 503. */
  private function storedKey(): string {
    clearstatcache(true,$this->keyFile);
    if(is_link($this->keyFile)||!is_file($this->keyFile)||filesize($this->keyFile)!==32)self::failure();
    $key=@file_get_contents($this->keyFile,false,null,0,33);
    if(!is_string($key)||strlen($key)!==32)self::failure();return $key;
  }

  /** Return the already-existing installation key without creating files; missing state raises 503. */
  public function key(): string {return $this->storedKey();}

  /** Explicitly create the private key only for initial catalog creation; never regenerate a lost key for an existing catalog. */
  public function initializeKey(): string {
    if(!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt'))throw new HttpError(503,'Identity cryptography unavailable.','users.error.crypto');
    if(is_link($this->keyFile))self::failure();
    if(!file_exists($this->keyFile)){
      if(file_exists($this->directory.'/catalog.json'))self::failure();
      Security::directory(dirname($this->keyFile));$handle=fopen($this->keyFile,'x+b');if($handle===false)self::failure();try{$key=random_bytes(32);if(!chmod($this->keyFile,0600)||fwrite($handle,$key)!==32||!fflush($handle))self::failure();}finally{fclose($handle);}
    }
    return $this->storedKey();
  }

  /**
   * Run one identity mutation under an exclusive lock and atomically replace JSON.
   * @param callable $callback Receives nullable current data by reference; returns the operation result.
   * @param ?string $revision Optional exact byte revision for administrative CAS.
   * @return mixed Callback result after the validated catalog is committed.
   * @throws HttpError For conflicts, invalid storage, validation or failed writes.
   */
  public function change(callable $callback,?string $revision=null): mixed {
    Security::directory($this->directory);$lock=$this->directory.'/write.lock';if(is_link($lock))self::failure();$handle=fopen($lock,'c+b');if($handle===false)self::failure();
    try{
      if(!flock($handle,LOCK_EX))self::failure();$opened=$this->read();if($revision!==null&&!hash_equals($opened['revision'],$revision))throw new HttpError(409,'Identity revision changed.','users.error.conflict');
      $data=$opened['data'];$result=$callback($data);if(!is_array($data))self::failure();self::validate($data);
      $raw=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";if(strlen($raw)>self::LIMIT)throw new HttpError(413,'Identity catalog capacity exceeded.','users.error.capacity');
      // No previous password/hash snapshots are retained by the editorial history system.
      $temporary=$this->directory.'/.catalog-'.bin2hex(random_bytes(12));$stream=fopen($temporary,'x+b');if($stream===false)self::failure();
      try{
        try{if(!chmod($temporary,0600)||fwrite($stream,$raw)!==strlen($raw)||!fflush($stream))self::failure();if(function_exists('fsync')&&!fsync($stream))self::failure();}finally{fclose($stream);}
        if(!rename($temporary,$this->directory.'/catalog.json'))self::failure();
      }finally{if(is_file($temporary))unlink($temporary);}
      return $result;
    }finally{flock($handle,LOCK_UN);fclose($handle);}
  }

  /** Validate the closed catalog, group/user references and bounded secret envelopes; throws 503 for corruption, never repairs on read. */
  private static function validate(array $data): void {
    if(!self::shape($data,['schema_version','groups','users','ownership','audit'])||$data['schema_version']!==1||!is_array($data['groups'])||count($data['groups'])<1||count($data['groups'])>20||!is_array($data['users'])||count($data['users'])<1||!is_array($data['ownership'])||!is_array($data['audit'])||count($data['audit'])>500)self::failure();
    foreach($data['groups'] as $id=>$group){
      // Reject composite permission values before set operations can coerce them or emit warnings.
      if(!is_array($group)||!is_array($group['permissions']??null))self::failure();
      foreach($group['permissions'] as $permission)if(!is_string($permission))self::failure();
      if(!is_string($id)||!preg_match('/^[a-z][a-z0-9-]{0,47}$/D',$id)||!is_array($group)||!self::shape($group,['id','name','description','permissions','mfaRequired','version'])||$group['id']!==$id||!self::text($group['name'],100)||!self::text($group['description'],1000,true)||!is_bool($group['mfaRequired'])||!is_int($group['version'])||$group['version']<1||!is_array($group['permissions'])||!array_is_list($group['permissions'])||count(array_unique($group['permissions'],SORT_REGULAR))!==count($group['permissions'])||array_diff($group['permissions'],Access::CAPABILITIES))self::failure();
      if(Access::sensitive($group['permissions'])&&!$group['mfaRequired'])self::failure();
    }
    $administrator=$data['groups']['administrator']??null;if(!is_array($administrator)||$administrator['permissions']!==Access::CAPABILITIES||!$administrator['mfaRequired'])self::failure();
    $logins=[];$emails=[];
    foreach($data['users'] as $id=>$user){
      if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id)||!is_array($user)||!self::shape($user,['id','username','name','email','group','status','passwordHash','authVersion','mfa','reset','createdAt','updatedAt'])||$user['id']!==$id||!self::text($user['name'],160)||!is_string($user['username'])||!preg_match('/^[A-Za-z0-9_.@-]{3,100}$/D',$user['username'])||!is_string($user['email'])||strlen($user['email'])>254||filter_var($user['email'],FILTER_VALIDATE_EMAIL)===false||!is_string($user['group'])||!isset($data['groups'][$user['group']])||!in_array($user['status'],['pending','active','blocked','deleted'],true)||!is_string($user['passwordHash'])||strlen($user['passwordHash'])>512||($user['passwordHash']!==''&&password_get_info($user['passwordHash'])['algoName']==='unknown')||($user['status']==='active'&&$user['passwordHash']==='')||!is_int($user['authVersion'])||$user['authVersion']<1||!is_int($user['createdAt'])||!is_int($user['updatedAt']))self::failure();
      $mfa=$user['mfa'];if(!is_array($mfa)||!self::shape($mfa,['secret','lastStep','recovery'])||!is_string($mfa['secret'])||strlen($mfa['secret'])>512||($mfa['secret']!==''&&base64_decode($mfa['secret'],true)===false)||!is_int($mfa['lastStep'])||$mfa['lastStep']< -1||!is_array($mfa['recovery'])||!array_is_list($mfa['recovery'])||count($mfa['recovery'])>10)self::failure();
      foreach($mfa['recovery'] as $hash)if(!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))self::failure();
      $reset=$user['reset'];if($reset!==null&&(!is_array($reset)||!self::shape($reset,['hash','expires'])||!is_string($reset['hash'])||!preg_match('/^[a-f0-9]{64}$/D',$reset['hash'])||!is_int($reset['expires'])))self::failure();
      if($user['status']!=='deleted'){foreach([strtolower($user['username']),strtolower($user['email'])] as $login){if(isset($logins[$login])&&$logins[$login]!==$id)self::failure();$logins[$login]=$id;}if(isset($emails[strtolower($user['email'])]))self::failure();$emails[strtolower($user['email'])]=$id;}
    }
    foreach($data['ownership'] as $record=>$id)if(!is_string($record)||strlen($record)>160||!is_string($id)||!isset($data['users'][$id]))self::failure();
    foreach($data['audit'] as $entry)if(!is_array($entry)||!self::shape($entry,['at','actor','action','target'])||!is_int($entry['at'])||!is_string($entry['actor'])||strlen($entry['actor'])>32||!is_string($entry['action'])||!preg_match('/^[a-z-]{1,40}$/D',$entry['action'])||!is_string($entry['target'])||strlen($entry['target'])>160)self::failure();
  }

  /** Require an exact object key set independent of JSON member order; never coerces values or changes decoded data. */
  private static function shape(array $value,array $keys): bool {return count($value)===count($keys)&&array_diff(array_keys($value),$keys)===[];}

  /** Validate a bounded human label without controls; receives optional-empty policy and returns bool without normalization. */
  private static function text(mixed $value,int $maximum,bool $empty=false): bool {return is_string($value)&&($empty||trim($value)!=='')&&strlen($value)<=$maximum&&preg_match('//u',$value)===1&&!preg_match('/[\x00-\x1F\x7F]/',$value);}
}
