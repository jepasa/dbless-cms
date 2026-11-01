<?php
/** Forms API 1: independent private/public routing, versioned declarative definitions, safe Mustache data and request-scoped form composition. */
declare(strict_types=1);
namespace DBLESSCMS;
require_once __DIR__.'/Model.php';
require_once __DIR__.'/Delivery.php';
require_once __DIR__.'/View.php';
require_once __DIR__.'/Router.php';
final class FormsPlugin implements PluginInterface,PublicPluginInterface,ContentProviderInterface,ContentOptionsInterface,ContentFormProviderInterface {
  /** Validate the intentionally empty settings contract; per-form purpose/retention live in definitions, never toggle plugin activation here. */
  public function validateSettings(array $settings,Config $config): void {Schema::validate($settings,['type'=>'object','additionalProperties'=>false,'properties'=>[]]);}
  /** Render declared integrated private route; receive authorized context, return escaped fragment or propagate typed errors. */
  public function render(PluginContext $context): string {return FormsRouter::render($context);}
  /** Return stable published IDs for the authorized Mustache picker; receive service/context, return labels only without reading submissions. */
  public function contentOptions(string $service,ContentContext $context): array {$items=[];foreach(FormsModel::repository($context->store)->read()['data']['forms'] as $form)if(!$form['deleted']&&$form['status']==='published')$items[]=['value'=>$form['id'],'label'=>$form['name']];return ['id'=>$items];}
  /** Resolve public definition data only; receive allowlisted service/parameters/context, return schema-shaped values or 404, never issue tokens or deliver. */
  public function content(string $service,array $parameters,ContentContext $context): array {if($service!=='definition')throw new HttpError(404,'Unknown form data service.','components.error');return FormsModel::definition(FormsModel::published($context->store,$parameters['id']));}
  /** Render one declared published form with visitor-session tickets; receive ID/context, return escaped fragment, never submit or persist answers. */
  public function renderForm(string $id,ContentContext $context): string {return FormsView::fragment(FormsModel::published($context->store,$id),$context->base);}
  /** Read a bounded administrative scalar; receive key/limit, return text or 422 without storing raw request data. */
  private static function input(string $key,int $limit=128): string {$value=$_POST[$key]??'';if(!is_string($value)||strlen($value)>$limit)throw new HttpError(422,'Invalid Forms input.','forms.error.contract');return $value;}
  /** Read the native visual editor into a closed definition; receive stable ID, return model input or 422, never accepts executable field validators. */
  private static function editor(string $id): array {
    $form=FormsModel::empty($id);
    foreach(['name','description','status','purpose','legalBasis','noticeVersion','noticeText','privacyPage','destination','consentText','submitLabel','successText'] as $key)$form[$key]=self::input($key,3000);
    $form['demo']=self::input('demo',1)==='1';$days=self::input('retainDays',3);if(!ctype_digit($days))throw new HttpError(422,'Invalid retention.','forms.error.contract');$form['retainDays']=(int)$days;
    $fields=$_POST['fields']??null;if(!is_array($fields)||count($fields)<1||count($fields)>20)throw new HttpError(422,'Invalid form fields.','forms.error.contract');$form['fields']=[];
    foreach($fields as $field){if(!is_array($field)||array_diff(array_keys($field),['name','label','type','required','maxLength','options','autocomplete']))throw new HttpError(422,'Invalid editor field.','forms.error.contract');foreach(['name','label','type','maxLength','options','autocomplete'] as $key)if(!is_string($field[$key]??null))throw new HttpError(422,'Invalid editor input.','forms.error.contract');if(!ctype_digit($field['maxLength'])||strlen($field['maxLength'])>5)throw new HttpError(422,'Invalid field length.','forms.error.contract');$field['maxLength']=(int)$field['maxLength'];$field['required']=($field['required']??'')==='1';$field['options']=trim($field['options'])===''?[]:array_map('trim',explode('|',$field['options']));$form['fields'][]=$field;}return $form;
  }
  /** Dispatch route-bound authenticated CRUD/history; receive context/operation, return local redirect, enforce activation/revision and published privacy notice before enabling collection. */
  public function api(PluginContext $context,string $operation): ?array {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')throw new HttpError(405,'POST required.','error.method');$routes=['save'=>['new','editor'],'delete'=>['overview'],'recover'=>['trash'],'restore'=>['history'],'erase'=>['submissions']];
    if(!in_array($context->route,$routes[$operation]??[],true)||!(new Plugins($context->store))->state('forms')['enabled'])throw new HttpError(404,'Form operation unavailable.','error.plugin');$repo=FormsModel::repository($context->store);$revision=self::input('revision');
    if($operation==='erase'){$receipt=self::input('id',32);if(!preg_match('/^[a-f0-9]{32}$/D',$receipt))throw new HttpError(404,'Receipt unavailable.','forms.error.missing');$context->store->transaction(function()use($context,$receipt):void{$file=FormsDelivery::directory($context->store).'/'.$receipt.'.json';$record=FormsDelivery::receipt($file);if(!hash_equals(hash_file('sha256',$file),self::input('revision')))throw new HttpError(409,'Receipt changed.','error.conflict');ErasureLedger::mark($context->store,$receipt);$record['values']=[];$record['retainUntil']=time();ExtensionDocument::atomic($file,ExtensionDocument::encode($record));});}
    elseif($operation==='restore')$repo->restore(self::input('backup',80),$revision);
    else $repo->change($revision,function(array $data)use($context,$operation):array{$id=self::input('id',48);if(!FormsModel::id($id))throw new HttpError(422,'Invalid form ID.','forms.error.contract');
      if($operation==='save'){try{$form=self::input('editor',1)==='1'?self::editor($id):json_decode(self::input('document',131072),true,24,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(422,'Invalid form JSON.','forms.error.contract');}if(!is_array($form)||($form['id']??null)!==$id)throw new HttpError(422,'Invalid form document.','forms.error.contract');$original=self::input('original',48);if($original!==''&&($id!==$original||!isset($data['forms'][$id])||$data['forms'][$id]['deleted'])||$original===''&&isset($data['forms'][$id]))throw new HttpError(409,'Form ID already exists or changed.','error.conflict');FormsModel::form($form);
        if(!$form['demo']&&$form['status']==='published'){if($form['privacyPage']==='')throw new HttpError(422,'Select a published privacy page before collection.','forms.error.notice');$policy=$context->store->read($form['privacyPage'])['data'];if($policy['status']!=='published')throw new HttpError(422,'Publish the privacy page first.','forms.error.notice');}
        $form['deleted']=false;$data['forms'][$id]=$form;
      }else{if(!isset($data['forms'][$id]))throw new HttpError(404,'Form unavailable.','forms.error.missing');$data['forms'][$id]['deleted']=$operation==='delete';}return $data;});
    return ['redirect'=>$context->base.'/cms-admin/?view=plugin-panel&plugin=forms&route='.($operation==='recover'?'trash':($operation==='erase'?'submissions':'overview'))];
  }
  /** Handle public GET or core-protected POST; receive minimal context, return HTML/JSON, preserve fields on failure and report transport acceptance only after actual success. */
  public function publicPage(PublicPluginContext $context): string {
    $store=new JsonStore(new Config());$id=$context->method==='POST'?($_POST['id']??null):($_GET['id']??null);if(!is_string($id))throw new HttpError(404,'Form unavailable.','forms.error.missing');$form=FormsModel::published($store,$id);$json=$context->method==='POST'&&str_contains($_SERVER['HTTP_ACCEPT']??'','application/json');
    if($context->route==='form'&&$context->method==='GET'){$result=$_SESSION['forms-result']??null;if(($_GET['submitted']??'')==='1'&&is_array($result)&&$result['id']===$id&&$result['at']>time()-300){unset($_SESSION['forms-result']);return FormsView::page($store,$form,$context->base,[],'Reference: '.$result['receipt'],true);}return FormsView::page($store,$form,$context->base);}
    if($context->route!=='submit'||$context->method!=='POST')throw new HttpError(405,'Method unavailable.','error.method');
    try{$receipt=$store->transaction(function()use($store,$form,$id):string{if(!(new Plugins($store))->state('forms')['enabled']||FormsModel::published($store,$id)!==$form)throw new HttpError(409,'Form changed before delivery.','forms.error.ticket');return (new FormsDelivery($store))->submit($form,$_POST);});if($json){header('Content-Type: application/json; charset=UTF-8');return json_encode(['message'=>$form['successText'],'reference'=>$receipt],JSON_THROW_ON_ERROR);}$_SESSION['forms-result']=['id'=>$id,'receipt'=>$receipt,'at'=>time()];header('Location: '.$context->base.'/plugins/forms/form?'.http_build_query(['id'=>$id,'submitted'=>'1']),true,303);return '';}
    catch(HttpError $error){http_response_code($error->status);if($json){header('Content-Type: application/json; charset=UTF-8');return json_encode(['error'=>$error->getMessage()],JSON_THROW_ON_ERROR);}return FormsView::page($store,$form,$context->base,$_POST,$error->getMessage());}
  }
}
