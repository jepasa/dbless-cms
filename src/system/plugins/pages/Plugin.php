<?php
/** Plugin Pages: domínio editorial reutiliza JsonStore, com rotas próprias e adaptador dos endereços administrativos anteriores. */
declare(strict_types=1);
namespace DBLESSCMS;
require_once __DIR__.'/Router.php';
final class PagesPlugin implements PluginInterface,ContentProviderInterface,ContentOptionsInterface,ContentDocumentInterface {
  /** Return no dynamic choices for the public list query; receive service/context, return empty metadata without writes. */
  public function contentOptions(string $service,ContentContext $context): array {return [];}
  /** Project only published ordinary pages in stable slug order; receive service/typed parameters/context, return public cards, never include home/404/drafts or metadata secrets. */
  public function content(string $service,array $parameters,ContentContext $context): array {
    if($service!=='list')throw new HttpError(404,'Unknown service.','components.error');$items=[];foreach($context->store->pages() as $id=>$snapshot){$page=$snapshot['data'];if(in_array((string)$id,['home','404'],true)||$page['status']!=='published')continue;$items[]=['title'=>$page['meta']['title'],'text'=>$page['meta']['description'],'href'=>$context->base.'/'.rawurlencode((string)$id),'image'=>$page['meta']['image']===''?'':(preg_match('~^https?://~i',$page['meta']['image'])?$page['meta']['image']:$context->base.'/'.ltrim($page['meta']['image'],'/'))];if(count($items)>=$parameters['limit'])break;}return ['items'=>$items];
  }
  /** List stable published page IDs for reference selection; receive context, return bounded options without home/404 or draft bodies. */
  public function documents(ContentContext $context): array {
    $items=[];foreach($context->store->pages() as $id=>$snapshot)if(!in_array((string)$id,['home','404'],true)&&$snapshot['data']['status']==='published'&&count($items)<100)$items[]=['id'=>(string)$id,'name'=>$snapshot['data']['meta']['title']];return $items;
  }
  /** Read one ordinary published page body through the shared public markup adapter; receive stable ID/context, return projection or null, reject configuration IDs and never render the site frame or home recursively. */
  public function document(string $id,ContentContext $context): ?array {
    if(in_array((string)$id,['home','404','site','plugins','templates'],true)||!ContentDocuments::identifier($id))return null;try{$page=$context->store->read($id)['data'];}catch(HttpError $error){if($error->status===404)return null;throw $error;}if($page['status']!=='published')return null;return ['name'=>$page['meta']['title'],'description'=>$page['meta']['description'],'html'=>ContentDocuments::page($page,new ContentContext($context->store,$context->template,$context->base,$id))];
  }
  /** Valida compatibilidade das opções legadas, sem aplicá-las; recebe settings/config; contrato inválido gera 422 sem escrita. */
  public function validateSettings(array $settings,Config $config): void {
    Schema::validate($settings,json_decode((string)file_get_contents(__DIR__.'/settings.schema.json'),true,8,JSON_THROW_ON_ERROR));
    $model=$settings['creation']['model']??'';
    if ($model!==''&&(!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$model)||in_array($model,['404','site','plugins','templates'],true))) throw new HttpError(422,'Modelo inválido.','error.admin_field');
  }
  /** Renderiza rota administrativa ou prévia autenticada; recebe contexto, retorna documento/fragmento sem escrita editorial. */
  public function render(PluginContext $context): string { return PagesRouter::render($context); }
  /** Extrai campo limitado do payload; recebe payload/nome/limite e retorna string ou 422 sem efeitos. */
  private function input(array $post,string $key,int $limit=128): string {
    $value=$post[$key]??'';if (!is_string($value)||strlen($value)>$limit) throw new HttpError(422,'Campo inválido.','error.admin_field');return $value;
  }
  /** Executa mutação editorial após sessão/CSRF do chamador; recebe contexto/ação/payload, retorna redirecionamento/notice e propaga erros de revisão/contrato. */
  public function change(PluginContext $context,string $operation,array $post): array {
    if (!(new Plugins($context->store))->state('pages')['enabled']) throw new HttpError(404,'Plugin desativado.','error.plugin');
    $key=$this->input($post,'key');$revision=$this->input($post,'revision');$route='editor';$notice='notice.content';
    if (in_array($key,['site','plugins','templates'],true)) throw new HttpError(422,'Documento reservado.','error.reserved');
    switch ($operation) {
      case 'create':$key=$this->input($post,'slug');$context->store->create($key,$this->input($post,'model'));$notice='notice.created';break;
      case 'save':$context->store->save($key,$this->input($post,'document',524288),$revision);break;
      case 'remove':$context->store->remove($key,$revision);$route='history';$notice='notice.removed';break;
      case 'restore':$context->store->restore($key,$this->input($post,'backup'),$revision);$notice='notice.restored';break;
      default:throw new HttpError(404,'Operação inexistente.','error.not_found');
    }
    return ['redirect'=>$context->base.'/cms-admin/?view=plugin-panel&plugin=pages&route='.$route.'&key='.rawurlencode($key),'notice'=>$notice];
  }
  /** Despacha API privada POST com rota compatível; recebe contexto/operação, retorna redirecionamento JSON ou HttpError sem escrita indevida. */
  public function api(PluginContext $context,string $operation): ?array {
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') throw new HttpError(405,'Método inválido.','error.method');
    $routes=['markup-save'=>['editor'],'create'=>['new'],'save'=>['editor'],'remove'=>['overview','editor'],'restore'=>['history']];
    if (!in_array($context->route,$routes[$operation]??[],true)) throw new HttpError(404,'Rota incompatível.','error.not_found');
    if($operation==='markup-save'){
      if($context->route!=='editor'||!(new Plugins($context->store))->state('pages')['enabled'])throw new HttpError(404,'Unavailable page.','error.not_found');$key=$this->input($_POST,'key');if(in_array($key,['site','plugins','templates','home','404'],true))throw new HttpError(422,'Reserved page.','error.reserved');$current=$context->store->read($key);$document=$current['data'];
      return $context->store->transaction(function()use($context,$key,$document):array{
        if(!hash_equals($context->store->read('site')['revision'],$this->input($_POST,'site_revision')))throw new HttpError(409,'Template changed.','error.conflict');
        if($this->input($_POST,'markup_enabled',1)==='1'){try{$bindings=json_decode($this->input($_POST,'bindings',32768),true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(422,'Invalid bindings JSON.','markup.error');}if(!is_array($bindings))throw new HttpError(422,'Invalid bindings.','markup.error');$document['markup']=ContentMarkup::definition(['html'=>$this->input($_POST,'html',131072),'bindings'=>$bindings]);}else unset($document['markup']);
        $context->store->save($key,JsonStore::encode($document),$this->input($_POST,'revision'));return ['redirect'=>$context->base.'/cms-admin/?view=plugin-panel&plugin=pages&route=editor&key='.rawurlencode($key)];
      });
    }
    $result=$this->change($context,$operation,$_POST);$_SESSION['notice_key']=$result['notice'];return $result;
  }
}
