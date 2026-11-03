<?php
/** Adaptador Files Manager API 1: interface própria e serviço privado, sem endpoints públicos independentes. */
declare(strict_types=1);
namespace DBLESSCMS;
require_once __DIR__.'/Files.php';
require_once __DIR__.'/Router.php';
final class FilesManager implements PluginInterface,ContentProviderInterface,ContentOptionsInterface {
  /** Return approved public provenance data only; receive service/ID/context, never fetch source URLs or expose private evidence or images. */
  public function content(string $service,array $parameters,ContentContext $context): array {if($service!=='asset')throw new HttpError(404,'Unknown media service.','components.error');return (new MediaRights($context->store))->asset($parameters['id'],$context->base);}
  /** List bounded public metadata IDs for the authorized content picker; receive service/context, return labels without private metadata or writes. */
  public function contentOptions(string $service,ContentContext $context): array {$items=[];$rights=new MediaRights($context->store);foreach($rights->repository()->read()['data']['assets'] as $id=>$record){try{$rights->asset($id,$context->base);$items[]=['value'=>$id,'label'=>$record['path']];}catch(HttpError $error){if($error->status!==404)throw $error;}if(count($items)>=100)break;}return ['id'=>$items];}
  /** Valida raízes sem criar diretórios; recebe settings/config; lança erro seguro para caminhos/links/proteções. */
  public function validateSettings(array $settings,Config $config): void {
    Schema::validate($settings,json_decode((string)file_get_contents(__DIR__.'/settings.schema.json'),true,16,JSON_THROW_ON_ERROR));
    if (!in_array($settings['browser']['pageSize']??25,[25,50,100],true)) throw new HttpError(422,'Use 25, 50 or 100 items per page.','fm.error.pageSize');
    $context=new PluginContext($config,new JsonStore($config),[], $settings,'','',new Language(__DIR__.'/languages'));
    foreach (['public','private'] as $scope) new ManagedFiles($context,$scope);
  }
  /** Delega página ao roteador privado; recebe contexto/rota e retorna HTML escapado, podendo consumir flash de sessão. */
  public function render(PluginContext $context): string {
    return FilesManagerRouter::render($context);
  }

  /** Despacha configuração/arquivos em rotas próprias autenticadas; recebe contexto/operação; retorna JSON/stream ou HttpError sem expor dados privados. */
  public function api(PluginContext $context,string $operation): ?array {
    if(in_array($operation,['rights-save','rights-copy'],true)){
      if($context->route!=='rights'||($_SERVER['REQUEST_METHOD']??'GET')!=='POST')throw new HttpError(404,'Media operation unavailable.','error.not_found');
      $scope=$_POST['scope']??null;$path=$_POST['path']??null;$revision=$_POST['rights_revision']??null;$fileRevision=$_POST['file_revision']??null;$registryRevision=$_POST['registry_revision']??null;
      if(!is_string($scope)||!is_string($path)||!is_string($revision)||!is_string($fileRevision)||!is_string($registryRevision))throw new HttpError(422,'Invalid media metadata input.','fm.error.rights');
      $context->store->transaction(function()use($context,$scope,$path,$revision,$fileRevision,$registryRevision,$operation):void{if(!hash_equals($context->store->read('plugins')['revision'],$registryRevision))throw new HttpError(409,'Upload scope changed.','fm.error.revision');$file=(new ManagedFiles($context,$scope))->describe($path);if(!hash_equals($file['sha256'],$fileRevision))throw new HttpError(409,'Image bytes changed.','fm.error.revision');$fields=[];if($operation==='rights-copy'){$name=$_POST['name']??null;if(!is_string($name))throw new HttpError(422,'Invalid copy name.','fm.error.rights');(new ManagedFiles($context,$scope))->publishCopy($path,$name,$revision);return;}foreach(['author','source','license','licenseUrl','credit','evidence','altSuggestion','review'] as $key){$value=$_POST[$key]??'';if(!is_string($value))throw new HttpError(422,'Invalid metadata value.','fm.error.rights');$fields[$key]=$value;}$fields['derivativesAllowed']=($_POST['derivativesAllowed']??'')==='1';(new MediaRights($context->store))->save($file,$fields,$revision);});
      return ['redirect'=>$context->base.'/cms-admin/?view=plugin-panel&plugin=files-manager&route=rights'];
    }
    if (in_array($operation,['settings-save','settings-restore','options-restore'],true)) {
      if (!in_array($context->route,['settings','history'],true)||($operation==='settings-save'&&$context->route!=='settings')) throw new HttpError(403,'Rota incompatível.','error.plugin');
      if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') throw new HttpError(405,'Método inválido.','error.method');
      $revision=$_POST['revision']??null;
      if (!is_string($revision) || strlen($revision)>128) throw new HttpError(422,'Revisão inválida.','error.revision');
      if ($operation==='settings-save') {
        $settings=PluginOptions::input($_POST,['uploads'=>['public'=>$_POST['public']??null,'private'=>$_POST['private']??null]]);
        // Legacy clients omit browser options: preserve the effective current preference.
        if (!array_key_exists('browser',$settings)) $settings['browser']=$context->settings['browser']??['pageSize'=>25];
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
