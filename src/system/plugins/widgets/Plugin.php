<?php
/** Widgets API 1: authenticated CRUD, optimistic revisions, template-aware binding, recoverable trash and history. */
declare(strict_types=1);
namespace DBLESSCMS;
require_once __DIR__.'/Store.php';
require_once __DIR__.'/Router.php';
final class WidgetsPlugin implements PluginInterface,ContentProviderInterface,ContentOptionsInterface {

  /** List existing reusable keys or set keys for the authorized insertion UI; receive service/context, return plain labels without exposing values or writing state. */
  public function contentOptions(string $service,ContentContext $context): array {
    $choices=[];$data=(new WidgetsStore($context->store))->read()['data'];foreach($data[$service==='set'?'sets':'reusable']??[] as $record)if(!$record['deleted']&&count($choices)<100)$choices[]=['value'=>$record['key'],'label'=>$record['key'].' — '.($record['name']??$record['description'])];return ['key'=>$choices];
  }

  /** Resolve reusable widgets/set members by stable key; receive service/parameters/context, return literal active values in stored order, omit trash, perform no writes and never interpret value markers. */
  public function content(string $service,array $parameters,ContentContext $context): array {
    $data=(new WidgetsStore($context->store))->read()['data'];$records=$data['reusable']??[];
    if($service==='value'){foreach($records as $record)if(!$record['deleted']&&$record['key']===$parameters['key'])return ['value'=>$record['value']];return ['value'=>''];}
    if($service!=='set')throw new HttpError(404,'Unknown service.','components.error');$items=[];
    foreach($data['sets']??[] as $set)if(!$set['deleted']&&$set['key']===$parameters['key'])foreach($set['widgets'] as $id){$record=$records[$id];if(!$record['deleted'])$items[]=['title'=>$record['description']?:$record['key'],'text'=>$record['value'],'href'=>'','image'=>''];}
    return ['items'=>$items];
  }
  /** Validate the empty settings contract; receives values/config, throws 422 for unknown options, never toggles activation. */
  public function validateSettings(array $settings,Config $config): void {Schema::validate($settings,['type'=>'object','additionalProperties'=>false,'properties'=>[]]);}
  /** Render a declared private route; receives authorized context, returns escaped HTML or propagates contract/I/O errors. */
  public function render(PluginContext $context): string {return WidgetsRouter::render($context);}
  /** Read a bounded scalar POST field; receives name/byte limit, returns text or throws 422 without modifying input. */
  private function input(string $name,int $limit=64): string {
    $value=$_POST[$name]??'';if(!is_string($value)||strlen($value)>$limit)throw new HttpError(422,'Invalid input.','widgets.error.contract');return $value;
  }
  /** Dispatch an authenticated, CSRF-checked POST; receives context/operation, returns local redirect and revision; route, CAS, duplicate or binding errors leave data intact. */
  public function api(PluginContext $context,string $operation): ?array {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')throw new HttpError(405,'Invalid method.','error.method');
    if(in_array($operation,['library-save','set-save','library-trash','set-trash','library-recover','set-recover'],true))return $this->library($context,$operation);
    $allowed=['save'=>'editor','trash'=>'overview','recover'=>'trash','history-restore'=>'history'];
    if(!isset($allowed[$operation])||$context->route!==$allowed[$operation])throw new HttpError(404,'Invalid route.','error.not_found');
    if(!(new Plugins($context->store))->state('widgets')['enabled'])throw new HttpError(404,'Inactive plugin.','error.plugin');
    $repository=new WidgetsStore($context->store);$revision=$this->input('revision',128);
    if($operation==='history-restore')$result=$repository->restore($this->input('backup',80),$revision);
    else $result=$repository->mutate($revision,function(array $data)use($context,$operation):array{
      $id=$this->input('id');
      if($operation==='save'){
        $original=$this->input('original');
        if($original==='')$id='widget-'.bin2hex(random_bytes(12));
        elseif($id!==$original||!isset($data['widgets'][$id])||$data['widgets'][$id]['deleted'])throw new HttpError(404,'Widget unavailable.','widgets.error.missing');
        $site=$context->store->read('site');
        if(!hash_equals($site['revision'],$this->input('site_revision',128)))throw new HttpError(409,'Template changed.','widgets.error.templateChanged');
        $template=$site['data']['template'];$key=$this->input('key');$locations=Widgets::locations($context->store->templates->get($template));
        if(!isset($locations[$key]))throw new HttpError(422,'Unknown position.','widgets.error.binding');
        $data['widgets'][$id]=['id'=>$id,'template'=>$template,'key'=>$key,'value'=>$this->input('value',12000),'description'=>$this->input('description',500),'deleted'=>false];
      }else{
        if(!isset($data['widgets'][$id]))throw new HttpError(404,'Widget unavailable.','widgets.error.missing');
        $data['widgets'][$id]['deleted']=$operation==='trash';
      }
      return $data;
    });
    return ['revision'=>$result['revision'],'redirect'=>$context->base.'/cms-admin/?view=plugin-panel&plugin=widgets&route='.($operation==='save'?'overview':$context->route)];
  }
  /** Mutate reusable widgets or sets through their declared private route; receive context/operation, return local redirect; enforce CAS, stable keys, closed members and recoverable deletion without template bindings. */
  private function library(PluginContext $context,string $operation): array {
    $isSet=str_starts_with($operation,'set-');$map=$isSet?'sets':'reusable';$save=str_ends_with($operation,'save');$recover=str_ends_with($operation,'recover');$route=$save?($isSet?'set':'record'):($recover?'trash':($isSet?'sets':'library'));
    if($context->route!==$route||!(new Plugins($context->store))->state('widgets')['enabled'])throw new HttpError(404,'Invalid route.','error.plugin');
    $repository=new WidgetsStore($context->store);$result=$repository->mutate($this->input('revision',128),function(array $data)use($map,$save,$recover,$isSet):array{
      $data[$map]??=[];$id=$this->input('id');
      if($save){
        $key=$this->input('key',48);if(!WidgetsStore::identifier($key))throw new HttpError(422,'Invalid key.','widgets.error.contract');
        if($id==='')$id=($isSet?'set-':'widget-').bin2hex(random_bytes(12));
        elseif(!isset($data[$map][$id])||$data[$map][$id]['deleted']||$data[$map][$id]['key']!==$key)throw new HttpError(409,'Stable key changed.','widgets.error.conflict');
        $record=['id'=>$id,'key'=>$key,'description'=>$this->input('description',500),'deleted'=>false];
        if($isSet){$members=$_POST['members']??[];if(!is_array($members)||!array_is_list($members)||count($members)>20)throw new HttpError(422,'Invalid members.','widgets.error.contract');$record['name']=$this->input('name',120);$record['widgets']=$members;}
        else $record['value']=$this->input('value',12000);
        $data[$map][$id]=$record;
      }else{if(!isset($data[$map][$id]))throw new HttpError(404,'Unavailable record.','widgets.error.missing');$data[$map][$id]['deleted']=!$recover;}
      return $data;
    });
    return ['revision'=>$result['revision'],'redirect'=>$context->base.'/cms-admin/?view=plugin-panel&plugin=widgets&route='.($recover?'trash':($isSet?'sets':'library'))];
  }

}
