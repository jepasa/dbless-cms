<?php
/** Core Users HTTP controller and escaped integrated fragments; public recovery never exposes account existence or private catalog envelopes. */
declare(strict_types=1);
/** Identity administration and authentication forms. */
namespace DBLESSCMS;

/** Render canonical Core components and dispatch bounded session/CSRF-protected identity operations. */
final class UsersAdmin {
  /** @var Users Private identity domain service. */
  private readonly Users $users;
  /** @var Config Trusted deployment settings and legacy bootstrap credential. */
  private readonly Config $config;
  /** @var Language Administrative labels and transactional e-mail messages. */
  private readonly Language $language;
  /** @var string Trusted application base for local administrative navigation. */
  private readonly string $base;

  /** Receive trusted application services and resolve identity state without creating catalog files. */
  public function __construct(Config $config,Language $language,string $base) {$this->config=$config;$this->language=$language;$this->base=$base;$this->users=new Users($config);}
  /** Translate one closed Users key and parameters; returns plain text for contextual escaping. */
  private function t(string $key,array $parameters=[]): string {return $this->language->text('users.'.$key,$parameters);}
  /** Read a bounded scalar form value; rejects arrays, invalid UTF-8 and oversized inputs before dispatch. */
  private function input(string $key,int $maximum=160): string {$value=$_POST[$key]??'';if(!is_string($value)||strlen($value)>$maximum||preg_match('//u',$value)!==1)throw new HttpError(400,'Invalid identity field.','users.error.invalid');return $value;}
  /** Build a local Core URL from trusted route parameters; never forwards arbitrary request query strings. */
  private function url(string $screen='users',array $parameters=[]): string {return $this->base.'/cms-admin/?'.http_build_query(['view'=>'users','screen'=>$screen]+$parameters,'','&',PHP_QUERY_RFC3986);}
  /** Redirect after a confirmed operation, carrying only a translated notice key in the session. */
  private function redirect(string $screen='users',string $message='users.saved'): never {$_SESSION['notice_key']=$message;header('Location: '.$this->url($screen),true,303);exit;}
  /** Return escaped standard POST security fields; receives a closed action and optional current byte revision. */
  private function hidden(string $action,?string $revision=null): string {return '<input type="hidden" name="action" value="'.e($action).'"><input type="hidden" name="csrf_token" value="'.e(Security::token()).'">'.($revision===null?'':'<input type="hidden" name="revision" value="'.e($revision).'">');}
  /** Keep a bounded enrollment seed in a server-side session only, bound to actor and exact security revision; GET never writes accounts. */
  private function seed(string $actor,string $fingerprint,bool $new=false): string {
    $entry=$_SESSION['users_enrollment']??null;
    $policy=$this->users->policy();
    if($new||!is_array($entry)||($entry['actor']??null)!==$actor||($entry['fingerprint']??null)!==$fingerprint||!UserPolicy::challenge($entry,$policy,time()))$_SESSION['users_enrollment']=['actor'=>$actor,'fingerprint'=>$fingerprint,'secret'=>UserCrypto::seed(),'at'=>time(),'expires'=>time()+$policy['challengeMinutes']*60];
    return $_SESSION['users_enrollment']['secret'];
  }
  /** Render local QR/manual enrollment instructions; the URI is bounded and contains no password or remote asset URL. */
  private function enrollment(string $secret,string $label,string $action): string {
    // A bounded display label keeps the fixed QR capacity independent of long legacy login names.
    $label=substr($label,0,32);$uri='otpauth://totp/'.rawurlencode('DBLess-CMS:'.$label).'?secret='.$secret.'&issuer=DBLess-CMS&algorithm=SHA1&digits=6&period=30';
    return '<div class="users-enrollment"><p>'.e($this->t('enroll_help',['minutes'=>$this->users->policy()['challengeMinutes']])).'</p><canvas class="users-qr" width="260" height="260" data-users-qr="'.e($uri).'" data-qr-error="'.e($this->t('qr_error')).'" aria-label="'.e($this->t('qr_label')).'"></canvas><p class="muted" data-qr-status role="status"></p><label>'.e($this->t('manual_key')).'<code class="users-key">'.e($secret).'</code></label><form method="post" class="stack">'.$this->hidden($action).'<label>'.e($this->t('otp')).'<input name="code" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required></label><button class="button-primary" type="submit">'.e($this->t('confirm_mfa')).'</button></form></div>';
  }

  /** Notify after a committed change; preserve the security mutation and expose transport failure once in the current session. */
  private function notify(array $user,string $kind): void {if(!UserMail::notification($this->config,$this->language,$user,$kind))$_SESSION['users_notification_failed']=true;}
  /** Render and consume a private best-effort notification warning without claiming delivery. */
  private function notificationWarning(): string {if(($_SESSION['users_notification_failed']??false)!==true)return '';unset($_SESSION['users_notification_failed']);return '<p class="notice" role="status">'.e($this->t('notification_failed')).'</p>';}

  /** Dispatch Users-prefixed actions before the generic Core controller; returns false for unrelated actions and redirects on success. */
  public function post(string $action): bool {
    if(!str_starts_with($action,'users-'))return false;header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');Security::csrf($_POST['csrf_token']??null);$policy=$this->users->policy();
    if($action==='users-forgot'){
      $start=microtime(true);$email=$this->input('email',254);Security::limit($this->config,'users-forgot',5,900);Security::limit($this->config,'users-reset-'.hash('sha256',strtolower($email)),3,900,false);
      try{$recipient=$this->users->requestReset($email);if($recipient!==null){try{UserMail::recovery($this->config,$this->language,$recipient);}catch(HttpError $failure){if($failure->messageKey!=='users.error.mail')throw $failure;}}}finally{$remaining=500000-(int)((microtime(true)-$start)*1000000);if($remaining>0)usleep($remaining);}
      $_SESSION['notice_key']='users.reset_requested';header('Location: '.$this->base.'/cms-admin/?view=forgot',true,303);exit;
    }
    if($action==='users-reset'){
      Security::limit($this->config,'users-reset-consume',5,900);$password=$this->input('password',800);if(!hash_equals($password,$this->input('password_confirmation',800)))throw new HttpError(422,'Password confirmation differs.','users.error.password');
      try{$recipient=$this->users->reset($this->input('token',100),$password);}catch(\InvalidArgumentException){throw new HttpError(422,'Password policy failed.','users.error.password');}$this->notify($recipient,'password');$_SESSION['notice_key']='users.reset_done';header('Location: '.$this->base.'/cms-admin/?view=login',true,303);exit;
    }
    if($action==='users-factor'){
      Security::limit($this->config,'users-factor',$policy['factorAttempts'],$policy['factorWindowMinutes']*60);$pending=$this->users->pending();if($pending===null)throw new HttpError(401,'Challenge expired.','users.error.challenge');Security::limit($this->config,'users-factor-'.$pending['id'],$policy['factorAttempts'],$policy['factorWindowMinutes']*60,false);$this->users->authenticateFactor($this->input('code',40));$_SESSION['notice_key']='notice.login';header('Location: '.$this->base.'/cms-admin/?view=dashboard',true,303);exit;
    }
    if($action==='users-enroll'){
      Security::limit($this->config,'users-enroll',$policy['factorAttempts'],$policy['factorWindowMinutes']*60);$entry=$_SESSION['users_enrollment']??null;$current=$this->users->current();$pending=$this->users->pending();$user=$current??$pending;
      if(!is_array($entry)||$user===null||$entry['actor']!==$user['id']||!UserPolicy::challenge($entry,$policy,time())||($current===null&&$user['mfa']['secret']!==''))throw new HttpError(401,'Enrollment expired.','users.error.challenge');
      $data=$this->users->snapshot()['data'];if(!hash_equals($entry['fingerprint'],Users::fingerprint($user,$data['groups'][$user['group']])))throw new HttpError(401,'Enrollment revoked.','users.error.challenge');
      Security::limit($this->config,'users-enroll-'.$user['id'],$policy['factorAttempts'],$policy['factorWindowMinutes']*60,false);$result=$this->users->enroll($user['id'],$entry['secret'],$this->input('code',6),$entry['fingerprint']);$this->users->establish($result['user'],'totp',$result['fingerprint']);$_SESSION['users_recovery']=$result['recovery'];$this->notify($result['user'],'mfa');$this->redirect('profile','users.mfa_saved');
    }
    if(!Security::authenticated($this->config))throw new HttpError(403,'Authentication required.','error.authentication');
    if($action==='users-setup'){
      Security::limit($this->config,'users-setup',8,300);
      if($this->users->enabled())throw new HttpError(409,'Users already initialized.','users.error.initialized');$entry=$_SESSION['users_enrollment']??null;$fingerprint=hash('sha256',json_encode($this->config->credentials,JSON_THROW_ON_ERROR));
      if(!is_array($entry)||$entry['actor']!=='legacy'||!UserPolicy::challenge($entry,$policy,time())||!hash_equals($fingerprint,$entry['fingerprint']))throw new HttpError(401,'Setup expired.','users.error.challenge');
      $result=$this->users->initialize($this->input('name'),$this->input('email',254),$this->input('password',800),$entry['secret'],$this->input('code',6),$this->input('revision'));$this->users->establish($result['user'],'totp',$result['fingerprint']);$_SESSION['users_recovery']=$result['recovery'];$this->redirect('profile','users.initialized');
    }
    $user=$this->users->current();if($user===null)throw new HttpError(403,'Initialize Users first.','users.error.setup');$actor=$user['id'];
    if($action==='users-policy-save'){Access::require($this->config,'system.manage');$this->users->savePolicy(UserPolicy::input($_POST['policy']??null),$this->input('revision'),$actor);$this->redirect('settings','users.policy_saved');}
    if($action==='users-profile'){$this->users->profile($this->input('name'),$this->input('revision'),$actor);$this->redirect('profile');}
    if(in_array($action,['users-factor-start','users-factor-disable'],true)){
      Security::limit($this->config,'users-sensitive',5,300);Security::limit($this->config,'users-sensitive-'.$actor,5,300,false);$this->users->reauthenticate($this->input('password',800),$this->input('code',40),$actor);
      if($action==='users-factor-disable'){$changed=$this->users->disableFactor($actor);$this->users->establish($changed['user'],'password',$changed['fingerprint']);$this->notify($changed['user'],'mfa');$this->redirect('profile','users.mfa_disabled_notice');}
      $data=$this->users->snapshot()['data'];$this->seed($actor,Users::fingerprint($data['users'][$actor],$data['groups'][$user['group']]),true);$this->redirect('profile','users.enroll_pending');
    }
    if(in_array($action,['users-group-save','users-group-remove'],true)){
      Access::require($this->config,'groups.manage');$id=$this->input('id',48);$revision=$this->input('revision');
      if($action==='users-group-remove')$this->users->removeGroup($id,$revision,$actor);
      else{$permissions=$_POST['permissions']??[];if(!is_array($permissions)||!array_is_list($permissions)||count($permissions)>count(Access::CAPABILITIES))throw new HttpError(422,'Invalid permissions.','users.error.invalid');$this->users->saveGroup(['id'=>$id,'original'=>$this->input('original',48),'name'=>$this->input('name',100),'description'=>$this->input('description',1000),'permissions'=>$permissions,'mfaRequired'=>$this->input('mfaRequired',1)==='1'],$revision,$actor);}
      $this->redirect('groups');
    }
    Access::require($this->config,'users.manage');
    if($action==='users-user-save'){
      $input=[];foreach(['id'=>32,'username'=>100,'name'=>160,'email'=>254,'group'=>48,'status'=>16] as $key=>$maximum)$input[$key]=$this->input($key,$maximum);$new=$input['id']==='';$id=$this->users->saveUser($input,$this->input('revision'),$actor);
      if($new){$recipient=$this->users->requestReset($input['email']);if($recipient===null)$this->redirect();try{UserMail::recovery($this->config,$this->language,$recipient);}catch(HttpError $failure){if($failure->messageKey!=='users.error.mail')throw $failure;$this->redirect('users','users.saved_mail_failed');}$this->redirect('users','users.invited');}$this->redirect();
    }
    if($action==='users-user-remove'){$this->users->removeUser($this->input('id',32),$this->input('revision'),$actor);$this->redirect();}
    if($action==='users-user-restore'){$this->users->restoreUser($this->input('id',32),$this->input('group',48),$this->input('revision'),$actor);$this->redirect('trash','users.restored');}
    if($action==='users-send-reset'){
      $ids=$_POST['ids']??[];if(!is_array($ids)||!array_is_list($ids)||count($ids)<1||count($ids)>25)throw new HttpError(422,'Select users.','users.error.selection');$data=$this->users->snapshot()['data'];foreach($ids as $id)if(!is_string($id)||!isset($data['users'][$id])||!in_array($data['users'][$id]['status'],['active','pending'],true))throw new HttpError(422,'User unavailable.','users.error.selection');
      foreach(array_unique($ids) as $id){Security::limit($this->config,'users-manager-reset-'.$id,3,900,false);$recipient=$this->users->requestReset($data['users'][$id]['email']);if($recipient===null)throw new HttpError(422,'Account eligibility changed.','users.error.selection');UserMail::recovery($this->config,$this->language,$recipient);}$this->redirect('users','users.links_sent');
    }
    throw new HttpError(400,'Unknown Users action.','users.error.invalid');
  }

  /** Render an unauthenticated forgot/reset/factor fragment; catalog state and secret fields are never dumped into HTML. */
  public function auth(string $view): string {
    header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');$login=$this->base.'/cms-admin/?view=login';$html='<div class="users-panel">'.$this->notificationWarning().'<h2>'.e($this->t($view==='twofactor'?'twofactor':$view)).'</h2>';
    if($view==='forgot')$html.='<p>'.e($this->t('forgot_help')).'</p><form method="post" class="stack">'.$this->hidden('users-forgot').'<label>'.e($this->t('email')).'<input name="email" type="email" maxlength="254" autocomplete="email" required></label><button class="button-primary" type="submit">'.e($this->t('send_link')).'</button></form>';
    elseif($view==='reset'){$token=$_GET['token']??'';if(!is_string($token)||strlen($token)>100)$token='';$html.='<form method="post" class="stack">'.$this->hidden('users-reset').'<input type="hidden" name="token" value="'.e($token).'"><p>'.e($this->t('password_help',['min'=>$this->users->policy()['passwordMinLength'],'max'=>200])).'</p><label>'.e($this->t('new_password')).'<input name="password" type="password" autocomplete="new-password" maxlength="800" required></label><label>'.e($this->t('confirm_password')).'<input name="password_confirmation" type="password" autocomplete="new-password" maxlength="800" required></label><button class="button-primary" type="submit">'.e($this->t('set_password')).'</button></form>';}
    else{$pending=$this->users->enabled()?$this->users->pending():null;if($pending===null)$html.='<p role="alert">'.e($this->t('error.challenge')).'</p>';elseif($pending['mfa']['secret']===''){$data=$this->users->snapshot()['data'];$secret=$this->seed($pending['id'],Users::fingerprint($pending,$data['groups'][$pending['group']]));$html.=$this->enrollment($secret,$pending['username'],'users-enroll');}else $html.='<p>'.e($this->t('factor_help')).'</p><form method="post" class="stack">'.$this->hidden('users-factor').'<label>'.e($this->t('otp_or_recovery')).'<input name="code" autocomplete="one-time-code" maxlength="40" required autofocus></label><button class="button-primary" type="submit">'.e($this->t('authenticate')).'</button></form>';}
    return $html.'<p><a class="text-link" href="'.e($login).'">'.e($this->t('back_login')).'</a></p></div>';
  }

  /** Resolve canonical group titles through dictionaries only while their built-in names remain unchanged; custom editorial names remain literal. */
  private function groupName(array $group): string {return in_array($group['id'],['administrator','editor','contributor'],true)&&$group['name']===ucfirst($group['id'])?$this->t('group.'.$group['id']):$group['name'];}

  /**
   * Project the fixed Core dropdown in the same item/separator order as Posts.
   * @return array<int,array> Authorized local screens or separators; hidden links never replace server authorization.
   * @throws HttpError On corrupt identity state; no catalog or key is created.
   */
  public function navigation(): array {
    $items=[];$manage=Access::allows($this->config,'users.manage');$groups=Access::allows($this->config,'groups.manage');$system=Access::allows($this->config,'system.manage');
    foreach([['users','overview',$manage],['user-edit','new_user',$manage],['groups','groups',$groups],null,['history','history',$manage],null,['settings','settings',$system],null,['presentation','presentation',true],null,['trash','trash',$manage]] as $item){
      if($item===null){if($items!==[]&&!isset($items[array_key_last($items)]['separator']))$items[]=['separator'=>true];}
      elseif($item[2])$items[]=['screen'=>$item[0],'label'=>$this->t($item[1]),'url'=>$this->url($item[0])];
    }
    if($items!==[]&&isset($items[array_key_last($items)]['separator']))array_pop($items);return $items;
  }

  /** Render an integrated authenticated Users page using Core table/form classes and no extra global frame or h1. */
  public function render(): string {
    header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');$opened=$this->users->snapshot();$data=$opened['data'];$screen=$_GET['screen']??'';if(!is_string($screen)||!in_array($screen,['','users','groups','profile','user-edit','group-edit','history','settings','presentation','trash'],true))throw new HttpError(400,'Invalid Users screen.','users.error.invalid');
    if($screen==='presentation')return $this->presentation();
    if($data===null)return $this->setup($opened['revision']);$current=$this->users->current();if($current===null)throw new HttpError(403,'Authentication required.','error.authentication');if($screen==='')$screen=Access::allows($this->config,'users.manage')?'users':'profile';
    if(in_array($screen,['users','user-edit','history','trash'],true))Access::require($this->config,'users.manage');if(in_array($screen,['groups','group-edit'],true))Access::require($this->config,'groups.manage');if($screen==='settings')Access::require($this->config,'system.manage');
    $html='<div class="users-panel" data-users>';
    $html.=$this->notificationWarning();
    if(!UserMail::configured($this->config))$html.='<p class="notice" role="status">'.e($this->t('mail_unconfigured')).'</p>';
    if($screen==='profile')$html.=$this->profileForm($data,$opened['revision'],$current);
    elseif($screen==='user-edit')$html.=$this->userForm($data,$opened['revision']);
    elseif($screen==='group-edit')$html.=$this->groupForm($data,$opened['revision']);
    elseif($screen==='history')$html.=$this->history($data);
    elseif($screen==='settings')$html.=$this->settings($data,$opened['revision']);
    elseif($screen==='trash')$html.=$this->trash($data,$opened['revision']);
    else $html.=$this->table($data,$opened['revision'],$screen);
    return $html.'</div>';
  }

  /**
   * Render a secret-free projection of the existing bounded audit, using the canonical sortable/paginated Core table.
   * @param array $data Validated catalog already protected by users.manage.
   * @return string Escaped read-only fragment; no credential history or restore action is exposed.
   */
  private function history(array $data): string {
    $actions=['initialize','host-initialize','login','user-save','user-remove','user-restore','group-save','group-remove','profile-save','mfa-enroll','mfa-disable','reset-request','password-reset','policy-save'];
    $columns=['at'=>['label'=>$this->t('audit.date'),'type'=>'number','value'=>static fn(array $row):int=>$row['at']],
      'actor'=>['label'=>$this->t('audit.actor'),'value'=>fn(array $row):string=>$data['users'][$row['actor']]['name']??$this->t('audit.public')],
      'action'=>['label'=>$this->t('audit.action'),'value'=>fn(array $row):string=>$this->t('audit.'.(in_array($row['action'],$actions,true)?str_replace('-','_',$row['action']):'other'))],
      'target'=>['label'=>$this->t('audit.target'),'value'=>static fn(array $row):string=>$row['target']]];
    $table=new AdminTable($data['audit'],$columns,['view'=>'users','screen'=>'history'],$this->language->locale(),$this->base,['sort'=>'at','direction'=>'desc']);
    $html='<section class="panel overview-catalog"><h2>'.e($this->t('history')).'</h2><p class="muted">'.e($this->t('history_help')).'</p>'.$table->toolbar().'<div class="table-scroll"><table class="extension-table"><caption class="sr-only">'.e($this->t('history')).'</caption><thead><tr>'.$table->headings().'</tr></thead><tbody>';
    foreach($table->rows as $row)$html.='<tr><td><time datetime="'.e(gmdate('c',$row['at'])).'">'.e(gmdate('Y-m-d H:i:s',$row['at'])).' UTC</time></td><td>'.e($data['users'][$row['actor']]['name']??$this->t('audit.public')).'</td><td>'.e($this->t('audit.'.(in_array($row['action'],$actions,true)?str_replace('-','_',$row['action']):'other'))).'</td><td><code>'.e($row['target']).'</code></td></tr>';
    if($table->rows===[])$html.='<tr><td colspan="4">'.e($this->t('audit.empty')).'</td></tr>';
    return $html.'</tbody></table></div>'.$table->footer().'</section>';
  }

  /**
   * Render the complete bounded policy form with actual effective values, revision and translated constraints.
   * @param array $data Validated private catalog; defaults are resolved without writes.
   * @param string $revision Current byte revision, retained on invalid submissions to preserve CAS.
   * @return string Integrated form; no regex, credential, arbitrary algorithm or MFA-disable input is offered.
   */
  private function settings(array $data,string $revision): string {
    $policy=UserPolicy::from($data);
    if(($_POST['action']??'')==='users-policy-save'&&is_string($_POST['revision']??null)){
      $revision=$_POST['revision'];$submitted=$_POST['policy']??null;
      if(is_array($submitted))foreach(UserPolicy::FIELDS as $key=>$definition){$value=$submitted[$key]??null;if(is_string($value)&&preg_match('/^[0-9]{1,4}$/D',$value))$policy[$key]=$value;}
    }
    $html='<section class="panel"><h2>'.e($this->t('settings')).'</h2><p>'.e($this->t('policy_help')).'</p><form method="post" class="stack" data-users-policy>'.$this->hidden('users-policy-save',$revision);
    $sections=['login'=>['loginAttempts','accountAttempts','loginWindowMinutes'],'session'=>['sessionIdleMinutes','sessionAbsoluteMinutes'],'password'=>['passwordMinLength','passwordMaxAgeDays'],'reset'=>['resetLifetimeMinutes'],'factor'=>['factorAttempts','factorWindowMinutes','challengeMinutes']];
    foreach($sections as $section=>$keys){$html.='<fieldset class="stack"><legend>'.e($this->t('policy.section.'.$section)).'</legend><p class="muted">'.e($this->t('policy.help.'.$section)).'</p>';
      foreach($keys as $key){[, $minimum,$maximum]=UserPolicy::FIELDS[$key];$html.='<label>'.e($this->t('policy.'.$key)).'<input type="number" name="policy['.$key.']" min="'.$minimum.'" max="'.$maximum.'" step="1" value="'.e($policy[$key]).'" aria-describedby="users-policy-'.$key.'" required></label><p class="muted" id="users-policy-'.$key.'">'.e($this->t('policy.range',['min'=>$minimum,'max'=>$maximum])).'</p>';}
      $html.='</fieldset>';
    }
    return $html.'<div class="savebar"><button type="submit" class="button-primary">'.e($this->t('save')).'</button></div></form></section>';
  }

  /**
   * List removed accounts without their erased secrets and offer only explicit, revisioned pending restoration.
   * @param array $data Validated catalog after users.manage authorization.
   * @param string $revision Catalog CAS token.
   * @return string Canonical table; restore requires a chosen group and sends no mail automatically.
   */
  private function trash(array $data,string $revision): string {
    $rows=array_filter($data['users'],static fn(array $user):bool=>$user['status']==='deleted');$columns=[];
    foreach(['name','username','email'] as $key)$columns[$key]=['label'=>$this->t($key),'value'=>static fn(array $row):string=>$row[$key]];
    $columns['actions']=['label'=>$this->t('actions')];$table=new AdminTable($rows,$columns,['view'=>'users','screen'=>'trash'],$this->language->locale(),$this->base);
    $html='<section class="panel overview-catalog"><h2>'.e($this->t('trash')).'</h2><p class="muted">'.e($this->t('trash_help')).'</p>'.$table->toolbar().'<div class="table-scroll"><table class="extension-table"><caption class="sr-only">'.e($this->t('trash')).'</caption><thead><tr>'.$table->headings().'</tr></thead><tbody>';
    foreach($table->rows as $id=>$row){$html.='<tr>';foreach(['name','username','email'] as $key)$html.='<td data-label="'.e($this->t($key)).'">'.e($row[$key]).'</td>';
      $html.='<td><form method="post" class="row-actions users-restore" data-confirm="'.e($this->t('confirm_restore')).'">'.$this->hidden('users-user-restore',$revision).'<input type="hidden" name="id" value="'.e($id).'"><select name="group" aria-label="'.e($this->t('restore_group',['name'=>$row['name']])).'" required><option value="" selected disabled>'.e($this->t('choose_group')).'</option>';
      foreach($data['groups'] as $group)$html.='<option value="'.e($group['id']).'">'.e($this->groupName($group)).'</option>';
      $html.='</select><button class="icon-button" type="submit" title="'.e($this->t('restore')).'" aria-label="'.e($this->t('restore')).'">'.Icons::svg('restore',$this->base).'</button></form></td></tr>';
    }
    if($table->rows===[])$html.='<tr><td colspan="4">'.e($this->t('trash_empty')).'</td></tr>';
    return $html.'</tbody></table></div>'.$table->footer().'</section>';
  }

  /**
   * Render the Core tutorial through the existing presentation typography using the selected administrative dictionary.
   * @return string Escaped localized introduction, linked outline and operational steps; never includes secrets or executes a plugin.
   */
  private function presentation(): string {
    $sections=['accounts','groups','policy','security','history','trash'];$html='<article class="panel plugin-presentation text-flow" data-users-presentation><h2>'.e($this->t('presentation')).'</h2><p>'.e($this->t('tutorial.intro')).'</p><nav aria-label="'.e($this->t('tutorial.outline')).'"><ol>';
    foreach($sections as $section)$html.='<li><a href="#users-guide-'.$section.'">'.e($this->t('tutorial.'.$section.'.title')).'</a></li>';$html.='</ol></nav>';
    foreach($sections as $section)$html.='<section id="users-guide-'.$section.'"><h3>'.e($this->t('tutorial.'.$section.'.title')).'</h3><ol><li>'.e($this->t('tutorial.'.$section.'.step1')).'</li><li>'.e($this->t('tutorial.'.$section.'.step2')).'</li></ol></section>';
    return $html.'</article>';
  }

  /** Render explicit legacy initialization with a session-only enrollment key and current-password confirmation. */
  private function setup(string $revision): string {
    $fingerprint=hash('sha256',json_encode($this->config->credentials,JSON_THROW_ON_ERROR));$secret=$this->seed('legacy',$fingerprint);$enrollment=$this->enrollment($secret,$this->config->credentials['username'],'users-setup');
    $fields='<input type="hidden" name="revision" value="'.e($revision).'"><label>'.e($this->t('name')).'<input name="name" maxlength="160" required></label><label>'.e($this->t('email')).'<input name="email" type="email" maxlength="254" required></label><label>'.e($this->t('current_password')).'<input name="password" type="password" autocomplete="current-password" maxlength="800" required></label>';
    return '<section class="panel users-panel" data-users><h2>'.e($this->t('setup')).'</h2><p>'.e($this->t('setup_help')).'</p>'.str_replace($this->hidden('users-setup'),$this->hidden('users-setup').$fields,$enrollment).'</section>';
  }

  /** Render the actor's own profile and factor controls, with one-time recovery display held only in the current session. */
  private function profileForm(array $data,string $revision,array $user): string {
    $codes=$_SESSION['users_recovery']??null;unset($_SESSION['users_recovery']);$html='';if(is_array($codes)){$html.='<section class="panel"><h2>'.e($this->t('recovery_codes')).'</h2><p>'.e($this->t('recovery_help')).'</p><ul class="users-recovery">';foreach($codes as $code)$html.='<li><code>'.e($code).'</code></li>';$html.='</ul></section>';}
    $group=$data['groups'][$user['group']];$html.='<section class="panel"><h2>'.e($this->t('profile')).'</h2><form method="post" class="stack">'.$this->hidden('users-profile',$revision).'<label>'.e($this->t('name')).'<input name="name" maxlength="160" value="'.e($user['name']).'" required></label><dl><dt>'.e($this->t('username')).'</dt><dd>'.e($user['username']).'</dd><dt>'.e($this->t('email')).'</dt><dd>'.e($user['email']).'</dd><dt>'.e($this->t('group')).'</dt><dd>'.e($this->groupName($group)).'</dd></dl><button class="button-primary" type="submit">'.e($this->t('save')).'</button></form></section>';
    $entry=$_SESSION['users_enrollment']??null;$html.='<section class="panel"><h2>'.e($this->t('twofactor')).'</h2><p>'.e($this->t($group['mfaRequired']?'mfa_required':'mfa_optional')).'</p>';
    if(is_array($entry)&&$entry['actor']===$user['id']&&UserPolicy::challenge($entry,$this->users->policy(),time()))$html.=$this->enrollment($entry['secret'],$user['username'],'users-enroll');
    else{$enabled=$user['mfa']['secret']!=='';$html.='<p>'.e($this->t($enabled?'mfa_enabled':'mfa_disabled')).'</p><form method="post" class="stack">'.$this->hidden('users-factor-start').'<label>'.e($this->t('current_password')).'<input name="password" type="password" autocomplete="current-password" maxlength="800" required></label>'.($enabled?'<label>'.e($this->t('otp_or_recovery')).'<input name="code" autocomplete="one-time-code" maxlength="40" required></label>':'').'<div class="row-actions"><button class="button-primary" type="submit">'.e($this->t($enabled?'replace_factor':'enable_factor')).'</button>'.($enabled&&!$group['mfaRequired']?'<button class="button-secondary" name="action" value="users-factor-disable" type="submit">'.e($this->t('disable_factor')).'</button>':'').'</div></form>';}
    return $html.'</section>';
  }

  /** Render paginated user/group rows with module-owned projections and independent POST forms; secrets are excluded from every cell. */
  private function table(array $data,string $revision,string $screen): string {
    $groups=$screen==='groups';$rows=$groups?$data['groups']:array_filter($data['users'],static fn(array $user):bool=>$user['status']!=='deleted');
    $columns=['name'=>['label'=>$this->t('name'),'value'=>static fn(array $row):string=>$row['name']]];
    if($groups)$columns+=['members'=>['label'=>$this->t('members'),'type'=>'number','value'=>static fn(array $row):int=>count(array_filter($data['users'],static fn(array $user):bool=>$user['group']===$row['id']&&$user['status']!=='deleted'))],'mfa'=>['label'=>$this->t('twofactor'),'value'=>static fn(array $row):int=>$row['mfaRequired']?1:0]];
    else $columns+=['username'=>['label'=>$this->t('username'),'value'=>static fn(array $row):string=>$row['username']],'email'=>['label'=>$this->t('email'),'value'=>static fn(array $row):string=>$row['email']],'group'=>['label'=>$this->t('group'),'value'=>fn(array $row):string=>$this->groupName($data['groups'][$row['group']])],'status'=>['label'=>$this->t('status'),'value'=>static fn(array $row):string=>$row['status']]];
    $columns['actions']=['label'=>$this->t('actions')];$table=new AdminTable($rows,$columns,['view'=>'users','screen'=>$screen],$this->language->locale(),$this->base);
    $html='<section class="panel"><div class="users-card-heading"><h2>'.e($this->t($groups?'groups':'overview')).'</h2>'.(!$groups||count($data['groups'])<20?'<a class="button-primary" href="'.e($this->url($groups?'group-edit':'user-edit')).'">'.e($this->t($groups?'new_group':'new_user')).'</a>':'').'</div>'.$table->toolbar().'<div class="table-scroll"><table class="extension-table"><thead><tr>'.$table->headings().'</tr></thead><tbody>';
    foreach($table->rows as $id=>$row){$html.='<tr><td data-label="'.e($this->t('name')).'">'.(!$groups?'<input type="checkbox" form="users-bulk" name="ids[]" value="'.e($id).'" aria-label="'.e($this->t('select_user',['name'=>$row['name']])).'"> ':'').e($groups?$this->groupName($row):$row['name']).'</td>';
      if($groups){$members=count(array_filter($data['users'],static fn(array $user):bool=>$user['group']===$id&&$user['status']!=='deleted'));$html.='<td>'.e($members).'</td><td>'.e($this->t($row['mfaRequired']?'required':'optional')).'</td>';}
      else foreach(['username','email','group','status'] as $key)$html.='<td data-label="'.e($this->t($key)).'">'.e($key==='group'?$this->groupName($data['groups'][$row[$key]]):($key==='status'?$this->t('state.'.$row[$key]):$row[$key])).'</td>';
      $html.='<td><div class="row-actions">';if(!$groups||$id!=='administrator'){$html.=AdminTable::action($this->url($groups?'group-edit':'user-edit',['id'=>$id]),'edit',$this->t('edit'),$this->base);$html.='<form method="post" data-confirm="'.e($this->t('confirm_remove')).'">'.$this->hidden($groups?'users-group-remove':'users-user-remove',$revision).'<input type="hidden" name="id" value="'.e($id).'"><button class="icon-button danger" title="'.e($this->t('remove')).'" aria-label="'.e($this->t('remove')).'">'.Icons::svg('delete',$this->base).'</button></form>';}else $html.='<span class="muted">'.e($this->t('protected')).'</span>';$html.='</div></td></tr>';
    }
    $html.='</tbody></table></div>'.$table->footer();if($groups)$html.='<p class="muted">'.e($this->t('group_count',['count'=>count($data['groups']),'limit'=>20])).'</p>';else $html.='<form method="post" id="users-bulk" class="catalog-toolbar">'.$this->hidden('users-send-reset').'<button class="button-secondary" type="submit">'.e($this->t('send_selected')).'</button></form>';return $html.'</section>';
  }

  /** Render account creation/editing without a password field or any factor-reset shortcut. */
  private function userForm(array $data,string $revision): string {
    $id=$_GET['id']??'';if(!is_string($id)||($id!==''&&(!isset($data['users'][$id])||$data['users'][$id]['status']==='deleted')))throw new HttpError(404,'User not found.','users.error.invalid');$row=$data['users'][$id]??['username'=>'','name'=>'','email'=>'','group'=>isset($data['groups']['contributor'])?'contributor':'','status'=>'pending'];if(($_POST['action']??'')==='users-user-save'&&is_string($_POST['revision']??null))$revision=$_POST['revision'];
    if(($_POST['action']??'')==='users-user-save')foreach(['username'=>100,'name'=>160,'email'=>254,'group'=>48,'status'=>16] as $key=>$maximum){$value=$_POST[$key]??null;if(is_string($value)&&strlen($value)<=$maximum&&preg_match('//u',$value)===1)$row[$key]=$value;}
    $html='<section class="panel"><h2>'.e($this->t($id===''?'new_user':'edit_user')).'</h2><p>'.e($this->t('invite_help')).'</p><form method="post" class="stack">'.$this->hidden('users-user-save',$revision).'<input type="hidden" name="id" value="'.e($id).'">';
    foreach(['username'=>100,'name'=>160,'email'=>254] as $key=>$maximum)$html.='<label>'.e($this->t($key)).'<input name="'.$key.'" type="'.($key==='email'?'email':'text').'" maxlength="'.$maximum.'" value="'.e($row[$key]).'" required></label>';
    $html.='<label>'.e($this->t('group')).'<select name="group" aria-label="'.e($this->t('group')).'" required><option value="" '.($row['group']===''?'selected':'').' disabled>'.e($this->t('choose_group')).'</option>';foreach($data['groups'] as $group)$html.='<option value="'.e($group['id']).'" '.($row['group']===$group['id']?'selected':'').'>'.e($this->groupName($group)).'</option>';$html.='</select></label><label>'.e($this->t('status')).'<select name="status" aria-label="'.e($this->t('status')).'">';foreach(['pending','active','blocked'] as $status)$html.='<option value="'.$status.'" '.($row['status']===$status?'selected':'').'>'.e($this->t('state.'.$status)).'</option>';return $html.'</select></label><button class="button-primary" type="submit">'.e($this->t('save')).'</button></form></section>';
  }

  /**
   * Render group details, permissions and navigation/actions in three Core cards within one POST form.
   * @param array $data Validated private catalog; the protected Administrator group is not editable here.
   * @param string $revision Current catalog byte revision, retained from a rejected submission for conflict detection.
   * @return string Escaped integrated form; rendering and Back navigation do not save the catalog.
   * @throws HttpError When the requested identifier is invalid, protected or unavailable.
   */
  private function groupForm(array $data,string $revision): string {
    $id=$_GET['id']??'';if(!is_string($id)||$id==='administrator'||($id!==''&&!isset($data['groups'][$id])))throw new HttpError(404,'Group not found.','users.error.invalid');$row=$data['groups'][$id]??['id'=>'','name'=>'','description'=>'','permissions'=>[],'mfaRequired'=>false];if(($_POST['action']??'')==='users-group-save'&&is_string($_POST['revision']??null))$revision=$_POST['revision'];
    if(($_POST['action']??'')==='users-group-save'){foreach(['name'=>100,'description'=>1000] as $key=>$maximum){$value=$_POST[$key]??null;if(is_string($value)&&strlen($value)<=$maximum&&preg_match('//u',$value)===1)$row[$key]=$value;}$submitted=$_POST['permissions']??[];if(is_array($submitted))$row['permissions']=array_values(array_filter($submitted,static fn($value):bool=>is_string($value)&&in_array($value,Access::CAPABILITIES,true)));$row['mfaRequired']=($_POST['mfaRequired']??'')==='1';if($id===''&&is_string($_POST['id']??null)&&preg_match('/^[a-z][a-z0-9-]{0,47}$/D',$_POST['id']))$row['id']=$_POST['id'];}
    // All three cards share security fields and a submit target; Back is a local GET link independent of validation.
    $html='<form method="post" class="users-group-form">'.$this->hidden('users-group-save',$revision).'<input type="hidden" name="original" value="'.e($id).'">';
    $html.='<section class="panel"><h2>'.e($this->t($id===''?'new_group':'edit_group')).'</h2><div class="stack"><label>'.e($this->t('identifier')).'<input name="id" maxlength="48" pattern="[a-z][a-z0-9\-]{0,47}" value="'.e($row['id']).'" '.($id!==''?'readonly':'').' required></label><label>'.e($this->t('name')).'<input name="name" maxlength="100" value="'.e($row['name']).'" required></label><label>'.e($this->t('responsibilities')).'<input name="description" maxlength="1000" value="'.e($row['description']).'"></label></div></section>';
    $html.='<section class="panel"><h2 id="users-group-permissions-title">'.e($this->t('permissions')).'</h2><div class="users-permissions" role="group" aria-labelledby="users-group-permissions-title">';
    foreach(Access::CAPABILITIES as $capability)$html.='<label class="editor-checkbox"><input name="permissions[]" type="checkbox" value="'.e($capability).'" '.(in_array($capability,$row['permissions'],true)?'checked':'').'> '.e($this->t('permission.'.$capability)).'</label>';
    $html.='</div><label class="editor-checkbox"><input type="checkbox" name="mfaRequired" value="1" '.($row['mfaRequired']?'checked':'').'> '.e($this->t('require_mfa')).'</label><p class="muted">'.e($this->t('security_mfa_help')).'</p></section>';
    return $html.'<div class="panel"><div class="row-actions"><a class="button-secondary" href="'.e($this->url('groups')).'">'.e($this->t('back_groups')).'</a><button class="button-primary" type="submit">'.e($this->t('save')).'</button></div></div></form>';
  }
}
