<?php
/** Roteador Pages: catálogo, editor, histórico e prévia com serviços privados e nenhuma rota de negócio na moldura. */
declare(strict_types=1);
namespace CMS2JSON;
final class PagesRouter {
  /** Prepara dados da rota autorizada e renderiza HTML; recebe contexto, propaga erros seguros e não grava conteúdo. */
  public static function render(PluginContext $context): string {
    $route=$context->route;$key=$_GET['key']??'home';
    if (!is_string($key)) throw new HttpError(400,'Chave inválida.','error.admin_route');
    if (in_array($key,['site','plugins','templates'],true)) throw new HttpError(422,'Documento reservado.','error.reserved');
    if ($route==='preview') {
      $template=$_GET['template']??null;if($template!==null&&!is_string($template))throw new HttpError(400,'Template inválido.','error.template');
      App::headers(false);return App::render($context->store,$key,true,$template,$context->base);
    }
    $site=$context->store->read('site');$templates=$context->store->compatibleTemplates();
    $effectiveTemplate=isset($templates[$site['data']['template']])?$site['data']['template']:array_key_first($templates);
    $pages=$effectiveTemplate!==null?$context->store->pages($effectiveTemplate):[];$document=null;$revision='';$history=[];$removed=[];$historyPages=[];$historyEnabled=(new Plugins($context->store))->state('pages')['enabled'];
    if ($route==='editor') {
      $opened=$context->store->read($key,$effectiveTemplate);$document=JsonStore::encode($opened['data']);$revision=$opened['revision'];
      if (($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&($_POST['action']??'')==='save'&&is_string($_POST['document']??null)) $document=$_POST['document'];
      if (($_SERVER['REQUEST_METHOD']??'GET')==='POST'&&is_string($_POST['revision']??null)) $revision=$_POST['revision'];
    }
    if ($route==='history') {
      $history=$context->store->history($key);
      try {$revision=$context->store->read($key)['revision'];} catch(HttpError $error){if($error->status!==404)throw $error;}
      if (!isset($_GET['key'])) foreach (glob($context->config->data['storage'].'/backups/*',GLOB_ONLYDIR)?:[] as $folder) {
        $slug=basename($folder);
        if (!is_link($folder)&&preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$slug)&&!in_array($slug,['site','plugins','templates'],true)&&$context->store->history($slug)!==[]) $historyPages[]=$slug;
      }
    }
    if ($route==='trash') foreach (glob($context->config->data['storage'].'/backups/*',GLOB_ONLYDIR)?:[] as $folder) {
      $slug=basename($folder);
      if (!is_link($folder)&&preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$slug)&&!in_array($slug,['site','plugins','templates','home','404'],true)&&!is_file($context->config->data['contents'].'/pages/'.$slug.'.json')&&$context->store->history($slug)!==[]) $removed[]=$slug;
    }
    if (!isset($context->manifest['routes'][$route])||is_link(__DIR__.'/pages')||is_link(__DIR__.'/pages/workspace.php')) throw new HttpError(404,'Página indisponível.','error.not_found');
    ob_start();
    try {require __DIR__.'/pages/workspace.php';return (string)ob_get_clean();}
    catch(\Throwable $error){ob_end_clean();throw $error;}
  }
}
