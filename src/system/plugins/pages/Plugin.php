<?php
/** Plugin Pages: domínio editorial reutiliza JsonStore, com rotas próprias e adaptador dos endereços administrativos anteriores. */
declare(strict_types=1);
namespace DBLESSCMS;
require_once __DIR__.'/Router.php';
final class PagesPlugin implements PluginInterface {
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
    $routes=['create'=>['new'],'save'=>['editor'],'remove'=>['overview','editor'],'restore'=>['history']];
    if (!in_array($context->route,$routes[$operation]??[],true)) throw new HttpError(404,'Rota incompatível.','error.not_found');
    $result=$this->change($context,$operation,$_POST);$_SESSION['notice_key']=$result['notice'];return $result;
  }
}
