<?php
/** Adaptador Files Manager API 1: interface própria e serviço privado, sem endpoints públicos independentes. */
declare(strict_types=1);
namespace CMS2JSON;
require_once __DIR__.'/Files.php';
require_once __DIR__.'/Router.php';
final class FilesManager implements PluginInterface {
  /** Valida raízes sem criar diretórios; recebe settings/config; lança erro seguro para caminhos/links/proteções. */
  public function validateSettings(array $settings,Config $config): void {
    Schema::validate($settings,json_decode((string)file_get_contents(__DIR__.'/settings.schema.json'),true,16,JSON_THROW_ON_ERROR));
    $context=new PluginContext($config,new JsonStore($config),[], $settings,'','',new Language(__DIR__.'/languages'));
    foreach (['public','private'] as $scope) new ManagedFiles($context,$scope);
  }
  /** Delega página ao roteador privado; recebe contexto/rota e retorna HTML escapado, podendo consumir flash de sessão. */
  public function render(PluginContext $context): string {
    return FilesManagerRouter::render($context);
  }

  /** Despacha configuração/arquivos em rotas próprias autenticadas; recebe contexto/operação; retorna JSON/stream ou HttpError sem expor dados privados. */
  public function api(PluginContext $context,string $operation): ?array {
    if (in_array($operation,['settings-save','settings-restore','options-restore'],true)) {
      if (!in_array($context->route,['settings','history'],true)||($operation==='settings-save'&&$context->route!=='settings')) throw new HttpError(403,'Rota incompatível.','error.plugin');
      if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') throw new HttpError(405,'Método inválido.','error.method');
      $revision=$_POST['revision']??null;
      if (!is_string($revision) || strlen($revision)>128) throw new HttpError(422,'Revisão inválida.','error.revision');
      if ($operation==='settings-save') {
        $settings=PluginOptions::input($_POST,['uploads'=>['public'=>$_POST['public']??null,'private'=>$_POST['private']??null]]);
        (new Plugins($context->store))->saveOptions($context->manifest['id'],$settings,$revision);
      } else {
        $backup=$_POST['backup']??null;
        if (!is_string($backup)) throw new HttpError(422,'Backup inválido.','error.backup');
        (new Plugins($context->store))->restore($context->manifest['id'],$backup,$revision,true);
      }
      $_SESSION['files-manager-notice']='fm.saved';
      return ['revision'=>$context->store->read('plugins')['revision']];
    }
    if ($context->route==='settings') throw new HttpError(404,'Operação incompatível.','error.not_found');
    $input=($_SERVER['REQUEST_METHOD']??'GET')==='POST'?$_POST:$_GET;
    $scope=$input['scope']??'public'; $path=$input['path']??'';
    if (!is_string($scope) || !is_string($path)) throw new HttpError(422,'Caminho inválido.','fm.error.path');
    $files=new ManagedFiles($context,$scope);
    if (in_array($operation,['list','trash','download','preview'],true)) {
      if ($_SERVER['REQUEST_METHOD']!=='GET') throw new HttpError(405,'Método inválido.','error.method');
      if ($operation==='list') return $files->listing($path);
      if ($operation==='trash') return $files->trash();
      $files->download($path,$operation==='preview'); return null;
    }
    if ($_SERVER['REQUEST_METHOD']!=='POST') throw new HttpError(405,'Método inválido.','error.method');
    return $files->mutate($operation,$input);
  }
}
