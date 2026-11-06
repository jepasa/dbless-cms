<?php
/** Core Users domain: one group per account, password setup/reset, mandatory/optional MFA, revocable sessions and private audit. */
declare(strict_types=1);
/** Private identity domain; no public content provider is registered. */
namespace DBLESSCMS;

/** Coordinate identity mutations through the single private UserStore transaction boundary. */
final class Users {
  /** @var UserStore Private catalog and encryption key owner. */
  private readonly UserStore $store;
  /** @var Config Trusted application/transport configuration, never serialized to clients. */
  private readonly Config $config;

  /** Receive trusted configuration and resolve the private store without creating files. */
  public function __construct(Config $config) {$this->config=$config;$this->store=new UserStore($config);}
  /** Report whether explicit migration has initialized identity state; never writes or silently falls back on corruption. */
  public function enabled(): bool {return $this->store->exists();}
  /** Return the private validated catalog/revision to trusted callers; never expose this envelope directly to clients. */
  public function snapshot(): array {return $this->store->read();}
  /** Return effective policy without persisting defaults or initializing Users; corruption propagates as a closed storage error. */
  public function policy(): array {return UserPolicy::from($this->snapshot()['data']);}
  /** Raise a bounded translated domain error; accepts closed suffix and status and never returns. */
  private static function fail(string $key='invalid',int $status=422): never {throw new HttpError($status,'Users operation unavailable.','users.error.'.$key);}

  /** Validate a live actor and capability inside the catalog lock; prevents stale sessions from mutating security state. */
  private static function actor(array $data,string $id,?string $capability=null): array {
    $auth=$_SESSION['users_auth']??null;$user=$data['users'][$id]??null;
    $policy=UserPolicy::from($data);
    if(!is_array($auth)||!is_array($user)||($auth['id']??null)!==$id||$user['status']!=='active'||!UserPolicy::session($auth,$policy,time())||UserPolicy::passwordExpired($user,$policy,time())||!is_string($auth['fingerprint']??null)||!hash_equals(self::fingerprint($user,$data['groups'][$user['group']]),$auth['fingerprint'])){unset($_SESSION['users_auth']);self::fail('credentials',401);}
    $group=$data['groups'][$user['group']];
    if(($group['mfaRequired']||$user['mfa']['secret']!=='')&&!in_array($auth['factor']??null,['totp','recovery'],true))self::fail('otp',401);
    if($capability!==null&&$user['group']!=='administrator'&&!in_array($capability,$group['permissions'],true))self::fail('forbidden',403);
    return $user;
  }

  /** Require the short-lived password/factor proof before replacing or disabling an existing authenticator. */
  private static function proof(array $data,string $actor): void {
    $user=self::actor($data,$actor);$proof=$_SESSION['users_reauthenticated']??null;
    if(!is_array($proof)||($proof['id']??null)!==$actor||($proof['expires']??0)<time()||!hash_equals(self::fingerprint($user,$data['groups'][$user['group']]),$proof['fingerprint']??''))self::fail('challenge',401);
  }

  /** Build the three initial groups, including immutable full Administrator permissions; no persistence occurs. */
  private static function groups(): array {
    $groups=[];foreach(['administrator'=>Access::CAPABILITIES,'editor'=>['posts.read','posts.create','posts.edit','posts.publish','posts.taxonomies','comments.moderate','media.read','media.manage'],'contributor'=>['posts.read','posts.create','posts.own','media.read']] as $id=>$permissions)$groups[$id]=['id'=>$id,'name'=>ucfirst($id),'description'=>'','permissions'=>$permissions,'mfaRequired'=>$id==='administrator','version'=>1];return $groups;
  }

  /** Append a secret-free bounded audit event to the same transaction; receives actor/action/target, returns void. */
  private static function audit(array &$data,string $actor,string $action,string $target): void {$data['audit'][]=['at'=>time(),'actor'=>$actor,'action'=>$action,'target'=>$target];$data['audit']=array_slice($data['audit'],-500);}

  /** Validate bounded account fields and cross-login/e-mail uniqueness; receives current catalog and optional existing ID. */
  private static function identity(array $data,array $input,string $id=''): array {
    foreach(['username'=>100,'name'=>160,'email'=>254,'group'=>48,'status'=>16] as $key=>$maximum){$value=$input[$key]??null;if(!is_string($value)||strlen($value)>$maximum||trim($value)===''||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1F\x7F]/',$value))self::fail();}
    if(!preg_match('/^[A-Za-z0-9_.@-]{3,100}$/D',$input['username'])||filter_var($input['email'],FILTER_VALIDATE_EMAIL)===false||!isset($data['groups'][$input['group']])||!in_array($input['status'],['pending','active','blocked'],true))self::fail();
    foreach($data['users'] as $other){if($other['id']===$id||$other['status']==='deleted')continue;foreach([$other['username'],$other['email']] as $old)foreach([$input['username'],$input['email']] as $new)if(strcasecmp($old,$new)===0)self::fail('duplicate');}
    return array_intersect_key($input,array_flip(['username','name','email','group','status']));
  }

  /** Create an account envelope with no generated password; receives validated fields and optional preserved legacy hash. */
  private static function user(array $fields,string $hash=''): array {
    return ['id'=>bin2hex(random_bytes(16)),'username'=>$fields['username'],'name'=>$fields['name'],'email'=>$fields['email'],'group'=>$fields['group'],'status'=>$hash===''&&$fields['status']!=='blocked'?'pending':$fields['status'],'passwordHash'=>$hash,'authVersion'=>1,'mfa'=>['secret'=>'','lastStep'=>-1,'recovery'=>[]],'reset'=>null,'createdAt'=>time(),'updatedAt'=>time()];
  }

  /** Generate ten one-time high-entropy recovery strings and store only their hashes; returns display-once values. */
  private static function recovery(array &$user): array {$values=[];for($i=0;$i<10;$i++)$values[]=bin2hex(random_bytes(10));$user['mfa']['recovery']=array_map(static fn(string $value):string=>hash('sha256',$value),$values);return $values;}

  /** Preserve at least one enrolled active administrator after destructive/security mutations; throws 422 within the transaction. */
  private static function lastAdministrator(array $data): void {foreach($data['users'] as $user)if($user['group']==='administrator'&&$user['status']==='active'&&$user['passwordHash']!==''&&$user['mfa']['secret']!=='')return;self::fail('last_admin');}

  /** Explicitly migrate the authenticated legacy owner after password and TOTP confirmation; returns the user and display-once recovery codes. */
  public function initialize(string $name,string $email,string $password,string $secret,string $code,string $revision): array {
    if($this->enabled()||!$this->config->configured())self::fail('initialized',409);
    $credentials=$this->config->credentials;$valid=isset($credentials['password_plaintext'])?hash_equals($credentials['password_plaintext'],$password):Passwords::verify($password,$credentials['password_hash']);if(!$valid)self::fail('credentials',401);
    $step=UserCrypto::counter($secret,$code,time());if($step===null)self::fail('otp',401);
    try{$hash=isset($credentials['password_plaintext'])?UserCrypto::password($password):$credentials['password_hash'];}catch(\InvalidArgumentException){self::fail('password');}
    return $this->store->change(function(?array &$data)use($name,$email,$hash,$secret,$step):array{
      if($data!==null)self::fail('initialized',409);$data=['schema_version'=>1,'groups'=>self::groups(),'users'=>[],'ownership'=>[],'audit'=>[]];
      $fields=self::identity($data,['username'=>$this->config->credentials['username'],'name'=>$name,'email'=>$email,'group'=>'administrator','status'=>'active']);$user=self::user($fields,$hash);$user['mfa']['secret']=UserCrypto::seal($secret,$this->store->initializeKey(),$user['id']);$user['mfa']['lastStep']=$step;$codes=self::recovery($user);$data['users'][$user['id']]=$user;self::audit($data,$user['id'],'initialize',$user['id']);return ['user'=>$user,'recovery'=>$codes,'fingerprint'=>self::fingerprint($user,$data['groups'][$user['group']])];
    },$revision);
  }

  /** Return the current fully authenticated account, revalidating identity/group revisions and expiry on every request; never grants partial sessions. */
  public function current(): ?array {
    $auth=$_SESSION['users_auth']??null;if(!is_array($auth)||!is_string($auth['id']??null)||!is_string($auth['fingerprint']??null))return null;
    $data=$this->snapshot()['data'];$policy=UserPolicy::from($data);$user=$data['users'][$auth['id']]??null;if(!is_array($user)||$user['status']!=='active'||!UserPolicy::session($auth,$policy,time())||UserPolicy::passwordExpired($user,$policy,time())||!hash_equals(self::fingerprint($user,$data['groups'][$user['group']]),$auth['fingerprint'])){unset($_SESSION['users_auth'],$_SESSION['users_reauthenticated'],$_SESSION['users_enrollment']);return null;}
    if(($data['groups'][$user['group']]['mfaRequired']||$user['mfa']['secret']!=='')&&($auth['factor']??'')!=='totp'&&($auth['factor']??'')!=='recovery')return null;
    $_SESSION['users_auth']['last_seen']=time();return $user;
  }

  /** Derive a session revision from security state only; OTP counters, audit and display-name updates do not revoke unrelated sessions. */
  public static function fingerprint(array $user,array $group): string {return hash('sha256',json_encode([$user['id'],$user['passwordHash'],$user['group'],$user['status'],$user['authVersion'],$group['version']],JSON_THROW_ON_ERROR));}

  /** Complete authentication after required factors; receives a current account and factor, regenerates session and removes legacy/partial state. */
  public function establish(array $user,string $factor,string $expected): void {
    $data=$this->snapshot()['data'];$current=$data['users'][$user['id']]??null;if(!is_array($current)||$current['status']!=='active'||!hash_equals($expected,self::fingerprint($current,$data['groups'][$current['group']])))self::fail('credentials',401);
    if(UserPolicy::passwordExpired($current,UserPolicy::from($data),time()))self::fail('password_expired',401);
    if(($data['groups'][$current['group']]['mfaRequired']||$current['mfa']['secret']!=='')&&!in_array($factor,['totp','recovery'],true))self::fail('otp',401);
    if(!session_regenerate_id(true))self::fail('storage',503);unset($_SESSION['auth'],$_SESSION['users_pending'],$_SESSION['users_enrollment'],$_SESSION['users_reauthenticated']);$_SESSION['users_auth']=['id'=>$current['id'],'fingerprint'=>self::fingerprint($current,$data['groups'][$current['group']]),'last_seen'=>time(),'factor'=>$factor,'at'=>time()];
  }

  /** Verify a password without issuing full access before MFA; returns false for all unknown/disabled identities and creates only a short-lived partial session. */
  public function login(string $login,string $password): bool {
    // The latency floor also covers preserved legacy hashes with a lower work factor.
    $started=microtime(true);try{
    unset($_SESSION['users_pending'],$_SESSION['users_enrollment'],$_SESSION['users_reauthenticated'],$_SESSION['users_auth'],$_SESSION['auth']);
    $data=$this->snapshot()['data'];$policy=UserPolicy::from($data);$user=null;foreach($data['users'] as $candidate)if($candidate['status']==='active'&&(strcasecmp($candidate['username'],$login)===0||strcasecmp($candidate['email'],$login)===0)){$user=$candidate;break;}
    Security::limit($this->config,'users-password-'.($user['id']??hash('sha256',strtolower($login))),$policy['accountAttempts'],$policy['loginWindowMinutes']*60,false);
    // Keep unknown identity work comparable without persisting an artificial account.
    $hash=$user['passwordHash']??'$argon2id$v=19$m=65536,t=4,p=1$ZHVtbXktdXNlcnMtc2FsdA$NJbMvvFKpns33Q2VLCWa7FcxSgHf6aMcJmRmcYDnU4s';$valid=Passwords::verify($password,$hash);
    if($user===null||!$valid)return false;
    if(UserPolicy::passwordExpired($user,$policy,time()))self::fail('password_expired',401);
    if($data['groups'][$user['group']]['mfaRequired']||$user['mfa']['secret']!==''){
      if(!session_regenerate_id(true))self::fail('storage',503);unset($_SESSION['users_auth'],$_SESSION['auth']);$_SESSION['users_pending']=['id'=>$user['id'],'fingerprint'=>self::fingerprint($user,$data['groups'][$user['group']]),'at'=>time(),'expires'=>time()+$policy['challengeMinutes']*60];return true;
    }
    $this->establish($user,'password',self::fingerprint($user,$data['groups'][$user['group']]));return true;
    }finally{$remaining=500000-(int)((microtime(true)-$started)*1000000);if($remaining>0)usleep($remaining);}
  }

  /** Resolve a valid pending login account; returns null for expired/revoked challenges without changing catalog state. */
  public function pending(): ?array {
    $pending=$_SESSION['users_pending']??null;if(!is_array($pending)||!is_string($pending['id']??null)||!is_int($pending['expires']??null)||$pending['expires']<=time())return null;
    $data=$this->snapshot()['data'];$policy=UserPolicy::from($data);$user=$data['users'][$pending['id']]??null;
    if(!UserPolicy::challenge($pending,$policy,time())||!is_array($user)||$user['status']!=='active'||UserPolicy::passwordExpired($user,$policy,time())||!is_string($pending['fingerprint']??null)||!hash_equals(self::fingerprint($user,$data['groups'][$user['group']]),$pending['fingerprint'])){unset($_SESSION['users_pending'],$_SESSION['users_enrollment']);return null;}return $user;
  }

  /** Consume one TOTP/recovery code under the catalog lock; returns factor name/null and never accepts a reused code or counter. */
  private function factor(array &$user,string $code): ?string {
    if($user['mfa']['secret']==='')return null;
    $normalized=strtolower(str_replace('-','',$code));if(preg_match('/^[a-f0-9]{20}$/D',$normalized)){foreach($user['mfa']['recovery'] as $index=>$hash)if(hash_equals($hash,hash('sha256',$normalized))){array_splice($user['mfa']['recovery'],$index,1);return 'recovery';}return null;}
    $secret=UserCrypto::unseal($user['mfa']['secret'],$this->store->key(),$user['id']);$step=UserCrypto::counter($secret,$code,time(),$user['mfa']['lastStep']);if($step===null)return null;$user['mfa']['lastStep']=$step;return 'totp';
  }

  /** Finish one pending MFA login atomically, or raise 401; no full session is issued on invalid/expired factors. */
  public function authenticateFactor(string $code): void {
    $pending=$this->pending();if($pending===null)self::fail('challenge',401);
    $expected=$_SESSION['users_pending']['fingerprint'];
    $result=$this->store->change(function(array &$data)use($pending,$code,$expected):?array{$user=&$data['users'][$pending['id']];if(!hash_equals($expected,self::fingerprint($user,$data['groups'][$user['group']])))return null;$factor=$this->factor($user,$code);if($factor===null)return null;self::audit($data,$user['id'],'login',$user['id']);return ['user'=>$user,'factor'=>$factor,'fingerprint'=>self::fingerprint($user,$data['groups'][$user['group']])];});
    if($result===null)self::fail('otp',401);$this->establish($result['user'],$result['factor'],$result['fingerprint']);
  }

  /** Create/edit a group under CAS, enforcing the 20-group ceiling and mandatory MFA for security capabilities. */
  public function saveGroup(array $input,string $revision,string $actor): string {
    return $this->store->change(function(array &$data)use($input,$actor):string{
      self::actor($data,$actor,'groups.manage');$id=$input['id']??'';if(!is_string($id)||!preg_match('/^[a-z][a-z0-9-]{0,47}$/D',$id)||$id==='administrator'||!is_string($input['name']??null)||trim($input['name'])===''||strlen($input['name'])>100||!is_string($input['description']??null)||strlen($input['description'])>1000||!is_array($input['permissions']??null)||!array_is_list($input['permissions'])||!is_bool($input['mfaRequired']??null))self::fail();
      // Explicit creation must never overwrite an existing role when its identifier is reused.
      $original=$input['original']??(isset($data['groups'][$id])?$id:'');
      if(!is_string($original)||($original!==''&&($original!==$id||!isset($data['groups'][$original]))))self::fail();
      if($original===''&&isset($data['groups'][$id]))self::fail('group_duplicate',409);
      foreach(['name','description'] as $key)if(preg_match('//u',$input[$key])!==1||preg_match('/[\x00-\x1F\x7F]/',$input[$key]))self::fail();
      foreach($input['permissions'] as $permission)if(!is_string($permission)||!in_array($permission,Access::CAPABILITIES,true))self::fail();$permissions=array_values(array_unique($input['permissions']));
      if(!isset($data['groups'][$id])&&count($data['groups'])>=20)self::fail('group_limit');$data['groups'][$id]=['id'=>$id,'name'=>$input['name'],'description'=>$input['description'],'permissions'=>$permissions,'mfaRequired'=>$input['mfaRequired']||Access::sensitive($permissions),'version'=>($data['groups'][$id]['version']??0)+1];self::audit($data,$actor,'group-save',$id);return $id;
    },$revision);
  }

  /** Delete an empty non-protected group under CAS; occupied groups must be explicitly reassigned before deletion. */
  public function removeGroup(string $id,string $revision,string $actor): void {$this->store->change(function(array &$data)use($id,$actor):void{self::actor($data,$actor,'groups.manage');if($id==='administrator'||!isset($data['groups'][$id]))self::fail();foreach($data['users'] as &$user){if($user['group']!==$id)continue;if($user['status']!=='deleted')self::fail('group_used');/* Deleted identities retain only immutable references; their obsolete group grants no access. */$user['group']='administrator';}unset($user,$data['groups'][$id]);self::audit($data,$actor,'group-remove',$id);},$revision);}

  /** Create/edit an identity without accepting any password/factor fields; return immutable ID, preserve factors and protect the last enrolled administrator. */
  public function saveUser(array $input,string $revision,string $actor): string {
    return $this->store->change(function(array &$data)use($input,$actor):string{
      self::actor($data,$actor,'users.manage');$id=$input['id']??'';if(!is_string($id)||($id!==''&&(!isset($data['users'][$id])||$data['users'][$id]['status']==='deleted')))self::fail();$fields=self::identity($data,$input,$id);
      if($id===''){$user=self::user($fields);$id=$user['id'];$data['users'][$id]=$user;}
      else{$user=&$data['users'][$id];$sensitive=$user['email']!==$fields['email']||$user['group']!==$fields['group']||$user['status']!==$fields['status']||$user['username']!==$fields['username'];foreach($fields as $key=>$value)$user[$key]=$value;if($user['passwordHash']===''&&$user['status']==='active')$user['status']='pending';if($sensitive){$user['authVersion']++;$user['reset']=null;}$user['updatedAt']=time();}
      self::lastAdministrator($data);self::audit($data,$actor,'user-save',$id);return $id;
    },$revision);
  }

  /** Soft-remove an account and erase its reusable authenticators/reset state, preserving immutable ownership/audit references. */
  public function removeUser(string $id,string $revision,string $actor): void {$this->store->change(function(array &$data)use($id,$actor):void{self::actor($data,$actor,'users.manage');if(!isset($data['users'][$id]))self::fail();$user=&$data['users'][$id];$user['status']='deleted';$user['passwordHash']='';$user['mfa']=['secret'=>'','lastStep'=>-1,'recovery'=>[]];$user['reset']=null;$user['authVersion']++;self::lastAdministrator($data);self::audit($data,$actor,'user-remove',$id);},$revision);}

  /**
   * Restore a removed identity as pending under CAS, preserving its immutable ID and publication ownership.
   * @param string $id Removed account ID.
   * @param string $group Explicitly selected existing group; no previous or Administrator fallback is inferred.
   * @param string $revision Current catalog byte revision.
   * @param string $actor Fully authenticated users.manage actor.
   * @return void No password, factor, token or e-mail is generated by restoration.
   * @throws HttpError On authorization, conflicts, duplicate identity or invalid group/state.
   */
  public function restoreUser(string $id,string $group,string $revision,string $actor): void {
    $this->store->change(function(array &$data)use($id,$group,$actor):void{
      self::actor($data,$actor,'users.manage');$user=$data['users'][$id]??null;if(!is_array($user)||$user['status']!=='deleted')self::fail();
      $fields=self::identity($data,['username'=>$user['username'],'name'=>$user['name'],'email'=>$user['email'],'group'=>$group,'status'=>'pending'],$id);
      $user=array_replace($user,$fields);$user['passwordHash']='';$user['mfa']=['secret'=>'','lastStep'=>-1,'recovery'=>[]];$user['reset']=null;$user['authVersion']++;$user['updatedAt']=time();unset($user['passwordChangedAt']);$data['users'][$id]=$user;self::audit($data,$actor,'user-restore',$id);
    },$revision);
  }

  /**
   * Commit a complete validated policy inside the same authorization/CAS boundary as account state.
   * @param array $policy Exact typed policy; unsafe minima and unknown fields are rejected.
   * @param string $revision Current catalog byte revision.
   * @param string $actor Fully authenticated system.manage actor, with mandatory group MFA.
   * @return void Appends a secret-free audit event; no credential/session is rewritten or e-mail sent.
   * @throws HttpError On conflicts, expired/unauthorized sessions or invalid policy.
   */
  public function savePolicy(array $policy,string $revision,string $actor): void {
    UserPolicy::validate($policy);
    $this->store->change(function(array &$data)use($policy,$actor):void{self::actor($data,$actor,'system.manage');$data['policy']=$policy;self::audit($data,$actor,'policy-save','policy');},$revision);
  }

  /** Edit only the current profile display name under CAS; group/status/e-mail/password are intentionally outside this contract. */
  public function profile(string $name,string $revision,string $actor): void {$this->store->change(function(array &$data)use($name,$actor):void{self::actor($data,$actor);if(!isset($data['users'][$actor])||trim($name)===''||strlen($name)>160||preg_match('//u',$name)!==1||preg_match('/[\x00-\x1F\x7F]/',$name))self::fail();$data['users'][$actor]['name']=$name;$data['users'][$actor]['updatedAt']=time();self::audit($data,$actor,'profile-save',$actor);},$revision);}

  /** Bind a newly created canonical publication to its creator; refuses ownership replacement and stores no editorial data. */
  public function claim(string $record,string $actor): void {$this->store->change(function(array &$data)use($record,$actor):void{self::actor($data,$actor,'posts.create');if($record===''||strlen($record)>160||!isset($data['users'][$actor])||isset($data['ownership'][$record]))self::fail();$data['ownership'][$record]=$actor;},null);}

  /** Reauthenticate a sensitive profile operation with password and an existing factor, atomically consuming that factor; throws 401 on failure. */
  public function reauthenticate(string $password,string $code,string $actor): void {
    $proof=$this->store->change(function(array &$data)use($password,$code,$actor):?array{
      self::actor($data,$actor);$user=&$data['users'][$actor];if(!Passwords::verify($password,$user['passwordHash']))return null;
      if($user['mfa']['secret']!==''&&$this->factor($user,$code)===null)return null;
      return ['id'=>$actor,'fingerprint'=>self::fingerprint($user,$data['groups'][$user['group']]),'expires'=>time()+300];
    });if($proof===null)self::fail('credentials',401);$_SESSION['users_reauthenticated']=$proof;
  }

  /** Enroll/replace the caller's seed after a verified new code; pending login fingerprint or current authentication must have been verified by the controller. */
  public function enroll(string $actor,string $secret,string $code,string $expected): array {
    $step=UserCrypto::counter($secret,$code,time());if($step===null)self::fail('otp',401);
    return $this->store->change(function(array &$data)use($actor,$secret,$step,$expected):array{if(!isset($data['users'][$actor])||$data['users'][$actor]['status']!=='active')self::fail('credentials',401);$user=&$data['users'][$actor];if(!isset($_SESSION['users_auth'])){$pending=$_SESSION['users_pending']??[];if(($pending['id']??null)!==$actor||($pending['expires']??0)<time()||!hash_equals($expected,$pending['fingerprint']??''))self::fail('challenge',401);}if($user['mfa']['secret']!==''||isset($_SESSION['users_auth']))self::proof($data,$actor);if(!hash_equals($expected,self::fingerprint($user,$data['groups'][$user['group']])))self::fail('challenge',401);$user['mfa']['secret']=UserCrypto::seal($secret,$this->store->key(),$actor);$user['mfa']['lastStep']=$step;$user['authVersion']++;$codes=self::recovery($user);self::audit($data,$actor,'mfa-enroll',$actor);return ['user'=>$user,'recovery'=>$codes,'fingerprint'=>self::fingerprint($user,$data['groups'][$user['group']])];});
  }

  /** Disable optional MFA only after controller reauthentication; mandatory group/security policies cannot be disabled. */
  public function disableFactor(string $actor): array {return $this->store->change(function(array &$data)use($actor):array{self::proof($data,$actor);$user=&$data['users'][$actor];if($data['groups'][$user['group']]['mfaRequired'])self::fail('mfa_required');$user['mfa']=['secret'=>'','lastStep'=>-1,'recovery'=>[]];$user['authVersion']++;self::audit($data,$actor,'mfa-disable',$actor);return ['user'=>$user,'fingerprint'=>self::fingerprint($user,$data['groups'][$user['group']])];});}

  /** Issue a hashed 256-bit reset/initial-password token for an eligible address; returns private delivery data/null, never a public account-existence response. */
  public function requestReset(string $email): ?array {
    if(!$this->enabled())return null;$token=bin2hex(random_bytes(32));
    return $this->store->change(function(array &$data)use($email,$token):?array{$policy=UserPolicy::from($data);foreach($data['users'] as &$user){if(!in_array($user['status'],['pending','active'],true)||strcasecmp($user['email'],$email)!==0)continue;$user['reset']=['hash'=>hash('sha256',$token),'issuedAt'=>time(),'expires'=>time()+$policy['resetLifetimeMinutes']*60];self::audit($data,'','reset-request',$user['id']);return ['id'=>$user['id'],'email'=>$user['email'],'name'=>$user['name'],'token'=>$user['id'].'.'.$token,'lifetimeMinutes'=>$policy['resetLifetimeMinutes']];}return null;});
  }

  /** Validate the current password policy and consume a live reset token once under lock; return notification fields, update credential age, revoke sessions and preserve MFA; throws on policy/token failure. */
  public function reset(string $token,string $password): array {
    if(!$this->enabled())self::fail('reset');if(!preg_match('/^([a-f0-9]{32})\.([a-f0-9]{64})$/D',$token,$matches))self::fail('reset');$hash=UserCrypto::password($password);
    return $this->store->change(function(array &$data)use($matches,$hash,$password):array{
      $policy=UserPolicy::from($data);$user=$data['users'][$matches[1]]??null;
      if(!is_array($user)||!in_array($user['status'],['pending','active'],true)||$user['reset']===null||$user['reset']['expires']<=time()||($user['reset']['issuedAt']??($user['reset']['expires']-1800))+$policy['resetLifetimeMinutes']*60<=time()||!hash_equals($user['reset']['hash'],hash('sha256',$matches[2])))self::fail('reset');
      // Validate again under the catalog lock so a concurrently raised minimum cannot accept an obsolete password policy.
      UserPolicy::password($password,$policy);$user['passwordHash']=$hash;$user['passwordChangedAt']=time();$user['status']='active';$user['reset']=null;$user['authVersion']++;$user['updatedAt']=time();$data['users'][$user['id']]=$user;self::audit($data,$user['id'],'password-reset',$user['id']);return ['id'=>$user['id'],'email'=>$user['email'],'name'=>$user['name']];
    });
  }
}
