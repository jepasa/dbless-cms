<?php
/** Widgets API 1: authenticated CRUD, optimistic revisions, template-aware binding, recoverable trash and history. */
declare(strict_types=1);
namespace DBLESSCMS;
require_once __DIR__.'/Store.php';
require_once __DIR__.'/Router.php';
final class WidgetsPlugin implements PluginInterface {
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
}
