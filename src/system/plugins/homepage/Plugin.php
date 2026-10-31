<?php
/** Homepage API 1: private section/content authoring, separate drafts, authenticated preview and revisioned publication/history. */
declare(strict_types=1);
namespace DBLESSCMS;
require_once __DIR__.'/Store.php';
require_once __DIR__.'/Catalog.php';
require_once __DIR__.'/Router.php';
final class HomepagePlugin implements PluginInterface {
  /** Validate the intentionally empty operational settings; receive settings/config, reject unknown keys, perform no mutation. */
  public function validateSettings(array $settings,Config $config): void {Schema::validate($settings,['type'=>'object','additionalProperties'=>false,'properties'=>[]]);}
  /** Render a declared private page/preview; receive authorized context, return trusted fragment or document and propagate typed errors. */
  public function render(PluginContext $context): string {return HomepageRouter::render($context);}
  /** Read a bounded scalar POST field; receive key/limit, return text or 422 without altering inputs. */
  private function input(string $key,int $limit=128): string {$value=$_POST[$key]??'';if(!is_string($value)||strlen($value)>$limit)throw new HttpError(422,'Invalid Homepage input.','homepage.error.contract');return $value;}
  /** Handle catalog-only private operations with closed routes and existing session/CSRF; receive context/operation, return local redirect, reject stale or invalid drafts without replacing the published snapshot. */
  private function catalog(PluginContext $context,string $operation): array {
    $routes=['catalog-save'=>['new','editor'],'catalog-activate'=>['overview'],'catalog-deactivate'=>['overview'],'catalog-remove'=>['overview'],'catalog-recover'=>['trash'],'catalog-restore'=>['history']];
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'||!in_array($context->route,$routes[$operation]??[],true)||!(new Plugins($context->store))->state('homepage')['enabled'])throw new HttpError(404,'Homepage operation unavailable.','error.not_found');$catalog=new HomepageCatalog($context->store);$revision=$this->input('revision');$siteRevision=$this->input('site_revision');$id=$this->input('id');$route='overview';
    if($operation==='catalog-save'){
      try{$bindings=json_decode($this->input('bindings',32768),true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(422,'Invalid bindings JSON.','markup.error');}if(!is_array($bindings))throw new HttpError(422,'Invalid bindings.','markup.error');
      $date=$this->input('publicationAt',30);$timezone=$this->input('timezone',20);if(isset($_POST['publicationLocal'])&&$timezone!=='local'){$local=$this->input('publicationLocal',19);$date=$local===''?'':$local.(strlen($local)===16?':00':'').'Z';}
      $body=['mode'=>$this->input('mode',20),'html'=>$this->input('html',131072),'bindings'=>$bindings,'source'=>['plugin'=>$this->input('source_plugin',64),'id'=>$this->input('source_id',64)],'publicationAt'=>$date];
      $result=$catalog->save($id,$this->input('name',200),$this->input('description',2000),$body,$revision,$siteRevision,$this->input('publish',1)==='1');$id=$result['id'];$route='editor';
    }elseif($operation==='catalog-restore')$catalog->restore($this->input('backup',80),$revision,$siteRevision);
    else{$action=substr($operation,8);$catalog->action($id,$action,$revision,$siteRevision);$route=$action==='recover'?'trash':'overview';}
    return ['redirect'=>$context->base.'/cms-admin/?'.http_build_query(['view'=>'plugin-panel','plugin'=>'homepage','route'=>$route]+($route==='editor'?['id'=>$id]:[]))];
  }
  /** Dispatch private POST after core session/CSRF checks; receive context/operation, return local redirect, reject wrong route/inactive plugin or stale data without writes. */
  public function api(PluginContext $context,string $operation): ?array {
    if(str_starts_with($operation,'catalog-'))return $this->catalog($context,$operation);
    $routes=['save'=>'editor','publish'=>'overview','reset'=>'editor','restore'=>'history'];
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')throw new HttpError(405,'POST required.','error.method');
    if(!isset($routes[$operation])||$context->route!==$routes[$operation]||!(new Plugins($context->store))->state('homepage')['enabled'])throw new HttpError(404,'Unavailable operation.','error.plugin');
    $repository=new HomepageStore($context->store);$revision=$this->input('revision');
    if($operation==='save'){
      $raw=$this->input('document',524288);
      if(isset($_POST['composition'])){
        try{$data=json_decode($raw,true,32,JSON_THROW_ON_ERROR);$composition=json_decode($this->input('composition',262144),true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(422,'Invalid editor JSON.','homepage.error.contract');}
        if(!is_array($data)||!is_array($composition))throw new HttpError(422,'Invalid editor data.','homepage.error.contract');$data['homepage']=$composition;$raw=JsonStore::encode($data);
      }
      $repository->save($raw,$revision,$this->input('site_revision'),$this->input('page_revision'));
    }elseif($operation==='publish')$repository->publish($revision);
    elseif($operation==='reset')$repository->reset($revision,$this->input('site_revision'),$this->input('page_revision'));
    else $repository->restore($this->input('backup'),$this->input('site_revision'),$this->input('page_revision'));
    return ['redirect'=>$context->base.'/cms-admin/?view=plugin-panel&plugin=homepage&route='.(in_array($operation,['reset','save'],true)?'editor':'overview')];
  }
}
