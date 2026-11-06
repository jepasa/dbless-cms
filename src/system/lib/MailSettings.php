<?php
/** Private Core mail configuration with independent CAS/lock, encrypted SMTP credentials and no editorial history/export integration. */
declare(strict_types=1);
/** Trusted deployment mail settings, separated from public site documents. */
namespace DBLESSCMS;

/** Persist only closed mail options; expose a redacted snapshot and decrypt credentials solely for the trusted transport. */
final class MailSettings {
  /** @var Config Application paths and read-only legacy sender/origin fallback. */
  private readonly Config $config;
  /** @var string Fixed private configuration file. */
  private readonly string $file;
  /** @var string Independent SMTP encryption key; never reuses the Users migration marker. */
  private readonly string $keyFile;

  /** Resolve fixed private paths without creating files or directories; reject symlink ancestors. */
  public function __construct(Config $config) {
    $this->config=$config;
    try{$this->file=Lifecycle::path(rtrim($config->data['storage'],'/').'/mail/settings.json');$this->keyFile=Lifecycle::path(rtrim($config->data['storage'],'/').'/keys/mail.key');}
    catch(\RuntimeException){self::failure();}
  }

  /** Fail closed without including host, username, ciphertext, message or password in an error. */
  private static function failure(): never {throw new HttpError(503,'Private mail configuration unavailable.','mail.error.storage');}

  /** Validate an HTTPS base origin with no credentials/query/fragment; HTTP is limited to local loopback qualification. */
  public static function origin(string $value): bool {
    $parts=parse_url($value);
    return filter_var($value,FILTER_VALIDATE_URL)!==false&&is_array($parts)&&isset($parts['scheme'],$parts['host'])&&!isset($parts['query'])&&!isset($parts['fragment'])&&!isset($parts['user'])&&!isset($parts['pass'])&&($parts['scheme']==='https'||($parts['scheme']==='http'&&in_array($parts['host'],['127.0.0.1','localhost','[::1]'],true)))&&preg_match('~^(?:/[A-Za-z0-9_-]+)*/?$~D',$parts['path']??'')===1;
  }

  /** Validate a literal DNS name or IP without scheme, port, path or SMTP command bytes. */
  public static function host(string $value): bool {return filter_var($value,FILTER_VALIDATE_IP)!==false||preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$/D',$value)===1;}

  /** Build compatible defaults in memory; never import the legacy application file into editorial content. */
  private function defaults(): array {return ['schema_version'=>1,'method'=>'mail','from'=>$this->config->data['users']['from'],'name'=>'','origin'=>$this->config->data['users']['origin'],'smtp'=>['host'=>'','port'=>587,'security'=>'starttls','username'=>'','password'=>'']];}

  /** Require an exact JSON object key set; no coercion or mutation. */
  private static function shape(array $value,array $keys): bool {return count($value)===count($keys)&&array_diff(array_keys($value),$keys)===[];}

  /** Validate a bounded UTF-8 single-line field, allowing empty values for inactive options. */
  private static function text(mixed $value,int $maximum): bool {return is_string($value)&&strlen($value)<=$maximum&&preg_match('//u',$value)===1&&!preg_match('/[\x00-\x1f\x7f]/',$value);}

  /** Validate the closed private schema; SMTP requires an authenticated TLS configuration, while inactive values remain preserved. */
  private static function valid(array $data,bool $passwordProvided=false): bool {
    if(!self::shape($data,['schema_version','method','from','name','origin','smtp'])||$data['schema_version']!==1||!in_array($data['method'],['mail','smtp'],true)||!self::text($data['from'],254)||($data['from']!==''&&filter_var($data['from'],FILTER_VALIDATE_EMAIL)===false)||!self::text($data['name'],100)||!self::text($data['origin'],2048)||($data['origin']!==''&&!self::origin($data['origin']))||!is_array($data['smtp']))return false;
    $smtp=$data['smtp'];
    if(!self::shape($smtp,['host','port','security','username','password'])||!self::text($smtp['host'],253)||($smtp['host']!==''&&!self::host($smtp['host']))||!is_int($smtp['port'])||$smtp['port']<1||$smtp['port']>65535||!in_array($smtp['security'],['starttls','tls'],true)||!self::text($smtp['username'],256)||!is_string($smtp['password'])||strlen($smtp['password'])>2048||($smtp['password']!==''&&base64_decode($smtp['password'],true)===false))return false;
    return $data['method']!=='smtp'||($data['from']!==''&&$smtp['host']!==''&&$smtp['username']!==''&&($smtp['password']!==''||$passwordProvided));
  }

  /** Read an existing 256-bit key; explicit saves alone may create a new key, never a GET or delivery call. */
  private function key(bool $create=false): string {
    if(!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt'))self::failure();
    if(!file_exists($this->keyFile)&&$create){Security::directory(dirname($this->keyFile));$handle=@fopen($this->keyFile,'x+b');if($handle===false)self::failure();try{if(!chmod($this->keyFile,0600)||fwrite($handle,random_bytes(32))!==32||!fflush($handle))self::failure();if(function_exists('fsync')&&!fsync($handle))self::failure();}finally{fclose($handle);}}
    clearstatcache(true,$this->keyFile);if(is_link($this->keyFile)||!is_file($this->keyFile)||filesize($this->keyFile)!==32)self::failure();
    $key=@file_get_contents($this->keyFile,false,null,0,33);if(!is_string($key)||strlen($key)!==32)self::failure();return $key;
  }

  /** Encrypt a credential using an independent nonce and purpose-bound authenticated encryption; returns Base64 only. */
  private function seal(string $password): string {$key=$this->key(true);$nonce=random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);return base64_encode($nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($password,'dbless-core-smtp-v1',$nonce,$key));}

  /** Authenticate/decrypt a stored credential only for integrity checking or transport; missing/damaged state fails closed. */
  private function unseal(string $cipher): string {
    $key=$this->key();$raw=base64_decode($cipher,true);$size=SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;if($raw===false||strlen($raw)<$size+16)self::failure();
    $password=sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($raw,$size),'dbless-core-smtp-v1',substr($raw,0,$size),$key);if(!self::text($password,1024)||$password==='')self::failure();return $password;
  }

  /** Read bounded regular JSON and its byte revision; absent state uses compatible in-memory defaults, with no writes. */
  private function read(): array {
    clearstatcache(true,$this->file);if(is_link($this->file))self::failure();if(!file_exists($this->file))return ['data'=>$this->defaults(),'revision'=>hash('sha256','')];
    if(!is_file($this->file)||filesize($this->file)>16384)self::failure();$raw=@file_get_contents($this->file,false,null,0,16385);if(!is_string($raw)||strlen($raw)>16384)self::failure();
    try{$data=json_decode($raw,true,8,JSON_THROW_ON_ERROR);}catch(\JsonException){self::failure();}
    if(!is_array($data)||!self::valid($data))self::failure();if($data['smtp']['password']!=='')$this->unseal($data['smtp']['password']);return ['data'=>$data,'revision'=>hash('sha256',$raw)];
  }

  /** Return only nonsecret settings, password-presence flag and CAS revision to the administrative form. */
  public function snapshot(): array {$opened=$this->read();$opened['data']['smtp']['passwordSet']=$opened['data']['smtp']['password']!=='';unset($opened['data']['smtp']['password']);return $opened;}

  /** Return effective options with the SMTP password transiently decrypted for trusted PHP delivery code only. */
  public function delivery(): array {$data=$this->read()['data'];if($data['smtp']['password']!=='')$data['smtp']['password']=$this->unseal($data['smtp']['password']);return $data;}

  /** Save a complete private form with CAS/lock/atomic replacement; blank password preserves it, explicit removal clears it, and no credential history is created. */
  public function save(array $input,string $revision): void {
    $allowed=['method','from','name','origin','host','port','security','username','password','clear_password'];if(array_diff(array_keys($input),$allowed))throw new HttpError(422,'Invalid mail options.','mail.error.invalid');
    foreach($allowed as $key)if(!is_string($input[$key]??null)||strlen($input[$key])>2048)throw new HttpError(422,'Invalid mail field.','mail.error.invalid');
    if(!ctype_digit($input['port'])||strlen($input['port'])>5||!in_array($input['clear_password'],['0','1'],true)||!self::text($input['password'],1024)||($input['clear_password']==='1'&&$input['password']!==''))throw new HttpError(422,'Invalid mail credentials.','mail.error.invalid');
    Security::directory(dirname($this->file));$lock=dirname($this->file).'/write.lock';try{Lifecycle::path($lock);}catch(\RuntimeException){self::failure();}$handle=@fopen($lock,'c+b');if($handle===false)self::failure();
    try{if(!chmod($lock,0600)||!flock($handle,LOCK_EX))self::failure();$opened=$this->read();if(!hash_equals($opened['revision'],$revision))throw new HttpError(409,'Mail settings changed.','mail.error.conflict');
      $cipher=$input['clear_password']==='1'?'':$opened['data']['smtp']['password'];
      // Validate all nonsensitive fields before creating an encryption key for a replacement credential.
      $data=['schema_version'=>1,'method'=>$input['method'],'from'=>$input['from'],'name'=>$input['name'],'origin'=>$input['origin'],'smtp'=>['host'=>$input['host'],'port'=>(int)$input['port'],'security'=>$input['security'],'username'=>$input['username'],'password'=>$cipher]];
      if(!self::valid($data,$input['password']!==''))throw new HttpError(422,'Incomplete or invalid mail settings.','mail.error.invalid');
      if($input['password']!=='')$cipher=$this->seal($input['password']);$data['smtp']['password']=$cipher;
      ExtensionDocument::atomic($this->file,json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
    }finally{flock($handle,LOCK_UN);fclose($handle);}
  }
}
