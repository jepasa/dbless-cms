<?php
/** Forms delivery and private expiring receipts: no visitor IDs/IP, bounded idempotence and optional explicitly enabled content retention; transport success is not inbox delivery. */
declare(strict_types=1);
namespace DBLESSCMS;
final class FormsDelivery {
  /** Receive private store and optional trusted transport for isolated tests; runtime defaults to mail, no public transport override or network configuration. */
  public function __construct(private readonly JsonStore $store,private readonly ?\Closure $transport=null) {}
  /** Issue bounded session-only form ticket bound to the exact form version; receive definition, return random token without storing user fields. */
  public static function ticket(array $form): string {
    $tickets=$_SESSION['forms-tickets']??[];$tickets=array_filter($tickets,static fn(array $record):bool=>$record['at']>time()-900);if(count($tickets)>=40)$tickets=array_slice($tickets,-39,null,true);$token=bin2hex(random_bytes(24));$tickets[$token]=['id'=>$form['id'],'revision'=>hash('sha256',ExtensionDocument::encode($form)),'at'=>time(),'done'=>false];$_SESSION['forms-tickets']=$tickets;return $token;
  }
  /** Resolve private receipt directory without symlinks; receive store, return path without creation, 503 for unsafe ancestor. */
  public static function directory(JsonStore $store): string {try{return Lifecycle::path($store->config->data['storage'].'/plugins/forms/receipts');}catch(\RuntimeException){throw new HttpError(503,'Unsafe submission storage.','forms.error.storage');}}
  /** Deliver one session-bound submission at most once per ticket; receive form/input, return receipt ID, reject unsafe/stale/inactive/unavailable requests before transport; no input appears in logs. */
  public function submit(array $form,array $input): string {
    $values=FormsModel::values($form,$input);$token=$input['ticket']??null;$ticket=is_string($token)?($_SESSION['forms-tickets'][$token]??null):null;$version=hash('sha256',ExtensionDocument::encode($form));
    if(!is_string($token)||!preg_match('/^[a-f0-9]{48}$/D',$token)||!is_array($ticket)||$ticket['id']!==$form['id']||!hash_equals($ticket['revision'],$version)||$ticket['at']<time()-900)throw new HttpError(409,'Form version or submission ticket expired. Reload before sending.','forms.error.ticket');
    if(is_string($ticket['done'])){$prior=self::receipt(self::directory($this->store).'/'.$ticket['done'].'.json');if($prior['status']==='accepted')return $ticket['done'];throw new HttpError(503,'Delivery is uncertain. Contact the site using the existing reference; do not resend automatically.','forms.error.delivery');}
    Security::limit($this->store->config,'forms-submit',5,600);
    if($form['demo']||!$this->store->config->data['contact_enabled'])throw new HttpError(503,'This form is not accepting messages. Use the published contact channel.','forms.error.delivery');
    $site=$this->store->read('site')['data'];$privacy=Privacy::settings($site['settings']['privacy']??[]);$to=$form['destination']==='privacy'?$privacy['email']:$site['settings']['contact']['email'];
    if(!filter_var($to,FILTER_VALIDATE_EMAIL)||preg_match('/[\r\n]/',$to))throw new HttpError(503,'Delivery channel unavailable.','forms.error.delivery');
    if($form['privacyPage']==='')throw new HttpError(503,'Privacy information must be configured before collection.','forms.error.notice');$policySnapshot=$this->store->read($form['privacyPage']);$policy=$policySnapshot['data'];if($policy['status']!=='published')throw new HttpError(503,'Privacy information unavailable.','forms.error.notice');
    $receipt=bin2hex(random_bytes(16));$body="Form: {$form['id']}\nReference: {$receipt}\nPurpose: {$form['purpose']}\nNotice version: {$form['noticeVersion']}\n";$reply='';foreach($form['fields'] as $field){$body.="\n{$field['label']}: {$values[$field['name']]}";if($reply===''&&$field['type']==='email'&&$values[$field['name']]!=='')$reply=$values[$field['name']];}$body.="\n\nNotice: {$form['noticeText']}\nConsent text: {$form['consentText']}\nConsent supplied: ".($form['legalBasis']==='consent'?'yes':'not requested')."\n";
    $headers=['From'=>$to];if($reply!=='')$headers['Reply-To']=$reply;
    $directory=self::directory($this->store);Security::directory($directory);if(count(glob($directory.'/*.json')?:[])>=2000)throw new HttpError(503,'Submission receipt quota reached.','forms.error.storage');
    $record=['id'=>$receipt,'form'=>$form['id'],'createdAt'=>time(),'expiresAt'=>time()+max(7,$form['retainDays'])*86400,'retainUntil'=>time()+$form['retainDays']*86400,'noticeVersion'=>$form['noticeVersion'],'formRevision'=>$version,'policyRevision'=>$policySnapshot['revision'],'noticeText'=>$form['noticeText'],'consentText'=>$form['legalBasis']==='consent'?$form['consentText']:'','legalBasis'=>$form['legalBasis'],'noticeHash'=>hash('sha256',$form['noticeText']),'consented'=>$form['legalBasis']==='consent','status'=>'pending','values'=>$form['retainDays']>0?$values:[]];
    // Save pending receipt before transport; after transport uncertainty must never trigger an automatic replay.
    ExtensionDocument::atomic($directory.'/'.$receipt.'.json',ExtensionDocument::encode($record));$_SESSION['forms-tickets'][$token]['done']=$receipt;
    try{$sent=$this->transport!==null?($this->transport)($to,$form['name'],$body,$headers):(function_exists('mail')&&@mail($to,$form['name'],$body,$headers));}
    catch(\Throwable $error){$record['status']='uncertain';ExtensionDocument::atomic($directory.'/'.$receipt.'.json',ExtensionDocument::encode($record));throw new HttpError(503,'Delivery could not be confirmed. Contact the site with the reference.','forms.error.delivery');}
    $record['status']=$sent?'accepted':'failed';ExtensionDocument::atomic($directory.'/'.$receipt.'.json',ExtensionDocument::encode($record));if(!$sent){$_SESSION['forms-tickets'][$token]['done']=false;throw new HttpError(503,'Delivery unavailable; fields have not been cleared.','forms.error.delivery');}return $receipt;
  }
  /** Read a closed bounded private receipt; receive file/mask (false only for locked cleanup), return record or 503; ordinary reads mask expired/erased answers, no public provider exposure. */
  public static function receipt(string $file,bool $mask=true): array {
    if(is_link($file)||!is_file($file)||filesize($file)>131072)throw new HttpError(503,'Receipt unavailable.','forms.error.storage');try{$data=json_decode((string)file_get_contents($file),true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(503,'Invalid receipt.','forms.error.storage');}
    if(!is_array($data)||!preg_match('/^[a-f0-9]{32}$/D',$data['id']??'')||basename($file)!==$data['id'].'.json'||!FormsModel::id($data['form']??null)||!is_int($data['createdAt']??null)||!is_int($data['expiresAt']??null)||!is_int($data['retainUntil']??null)||!in_array($data['status']??null,['pending','uncertain','failed','accepted'],true)||!is_array($data['values']??null))throw new HttpError(503,'Invalid receipt schema.','forms.error.storage');$storage=dirname($file,4);if($mask&&(isset(ErasureLedger::read($storage)[$data['id']])||$data['retainUntil']<=time()))$data['values']=[];return $data;
  }
  /** Plan or apply only expired form receipt/content removal under the shared lock; receive store/apply/clock, return aggregate counts without PII and never touch sessions/legal logs/backups. */
  public static function cleanup(JsonStore $store,bool $apply=false,?int $now=null): array {
    return $store->transaction(function()use($store,$apply,$now):array{$now??=time();if($apply)ErasureLedger::reconcile($store->config->data['storage'],$store->config->data['storage']);$result=['expired'=>0,'redacted'=>0,'applied'=>$apply];$directory=self::directory($store);$files=glob($directory.'/*.json')?:[];if(count($files)>2000)throw new HttpError(503,'Receipt quota requires operator review.','forms.error.storage');foreach($files as $file){$record=self::receipt($file,false);if($record['expiresAt']<=$now){$result['expired']++;if($apply&&!unlink($file))throw new HttpError(503,'Receipt cleanup failed.','forms.error.storage');}elseif($record['values']!==[]&&$record['retainUntil']<=$now){$result['redacted']++;if($apply){$record['values']=[];ExtensionDocument::atomic($file,ExtensionDocument::encode($record));}}}return $result;});
  }
}
