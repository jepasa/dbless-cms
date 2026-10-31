<?php
/** Homepage private route adapter; preview uses the public template with a validated saved draft and no publication. */
declare(strict_types=1);
namespace DBLESSCMS;
final class HomepageRouter {
  /** Render only declared Homepage destinations; receive context, return integrated fragment or authenticated noindex draft preview, propagate I/O/contract errors with buffer cleanup. */
  public static function render(PluginContext $context): string {
    if(!in_array($context->route,['overview','new','editor','history','trash','preview'],true))throw new HttpError(404,'Unavailable page.','error.not_found');
    $id=$_GET['id']??'';if(!is_string($id)||($id!==''&&!Plugins::identifier($id)))throw new HttpError(404,'Invalid Homepage.','error.not_found');
    if($context->route==='preview'&&$id!==''){
      $entry=(new HomepageCatalog($context->store))->read()['data']['homepages'][$id]??null;if($entry===null||$entry['deletedAt']!==null)throw new HttpError(404,'Homepage unavailable.','error.not_found');App::headers(false);header('X-Robots-Tag: noindex, nofollow, noarchive');return App::render($context->store,'home',true,null,$context->base,null,$entry['draft']);
    }
    if($context->route==='preview'){
      $site=$context->store->read('site');$draft=(new HomepageStore($context->store))->read($site['data']['template']);if($draft['data']===null)throw new HttpError(404,'Save a draft first.','homepage.error.noDraft');
      $repository=new HomepageStore($context->store);$document=$repository->document(JsonStore::encode($draft['data']['document']),$site['data']['template']);App::headers(false);header('X-Robots-Tag: noindex, nofollow');return App::render($context->store,'home',true,$site['data']['template'],$context->base,$document);
    }
    ob_start();try{require __DIR__.($context->route==='editor'&&$id===''?'/pages/workspace.php':'/pages/catalog.php');return (string)ob_get_clean();}catch(\Throwable $error){ob_end_clean();throw $error;}
  }
}
