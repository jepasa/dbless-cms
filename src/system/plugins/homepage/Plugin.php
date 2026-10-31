<?php
/** Homepage API 1: private section/content authoring, separate drafts, authenticated preview and revisioned publication/history. */
declare(strict_types=1);
namespace DBLESSCMS;
require_once __DIR__.'/Store.php';
require_once __DIR__.'/Router.php';
final class HomepagePlugin implements PluginInterface {
  /** Validate the intentionally empty operational settings; receive settings/config, reject unknown keys, perform no mutation. */
  public function validateSettings(array $settings,Config $config): void {Schema::validate($settings,['type'=>'object','additionalProperties'=>false,'properties'=>[]]);}
  /** Render a declared private page/preview; receive authorized context, return trusted fragment or document and propagate typed errors. */
  public function render(PluginContext $context): string {return HomepageRouter::render($context);}
  /** Read a bounded scalar POST field; receive key/limit, return text or 422 without altering inputs. */
  private function input(string $key,int $limit=128): string {$value=$_POST[$key]??'';if(!is_string($value)||strlen($value)>$limit)throw new HttpError(422,'Invalid Homepage input.','homepage.error.contract');return $value;}
  /** Dispatch private POST after core session/CSRF checks; receive context/operation, return local redirect, reject wrong route/inactive plugin or stale data without writes. */
  public function api(PluginContext $context,string $operation): ?array {
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
    return ['redirect'=>$context->base.'/cms-admin/?view=plugin-panel&plugin=homepage&route='.($operation==='reset'?'editor':'overview')];
  }
}
