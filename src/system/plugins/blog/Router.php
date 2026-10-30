<?php
/** Private Blog router: declared workspace routes, isolated saved preview and local URL generation without arbitrary file resolution. */
declare(strict_types=1);
namespace DBLESSCMS;
final class BlogRouter {
  /** Build an internal administration URL; takes context/route/query, returns a local URL without changing state. */
  public static function url(PluginContext $context,string $route,array $query=[]): string {
    return $context->base.'/cms-admin/?'.http_build_query(['view'=>'plugin-panel','plugin'=>'blog','route'=>$route]+$query);
  }
  /** Render declared private workspace or authenticated saved preview; takes context, returns HTML, propagates errors and never resolves user filenames. */
  public static function render(PluginContext $context): string {
    if (!isset($context->manifest['routes'][$context->route])||($context->manifest['routes'][$context->route]['visibility']??'private')!=='private') throw new HttpError(404,'Route unavailable.','error.not_found');
    if ($context->route==='preview') {
      $id=$_GET['id']??'';if (!BlogModel::identifier($id)) throw new HttpError(404,'Article unavailable.','blog.error.missing');
      if ($context->integrated) {
        header('Location: '.$context->base.'/cms-admin/?'.http_build_query(['view'=>'plugin','plugin'=>'blog','route'=>'preview','id'=>$id]),true,303);return '';
      }
      $catalog=(new BlogStore($context->store))->read()['data'];$article=$catalog['articles'][$id]??null;
      if ($article===null||$article['deletedAt']!=='') throw new HttpError(404,'Article unavailable.','blog.error.missing');
      header('X-Robots-Tag: noindex, nofollow, noarchive');
      return BlogView::document($context->store,$context->base,['kind'=>'article','article'=>$article,'catalog'=>$catalog,'preview'=>true],$context->settings);
    }
    if (is_link(__DIR__.'/pages')||is_link(__DIR__.'/pages/workspace.php')) throw new HttpError(503,'Blog view unavailable.','blog.error.storage');
    ob_start();try {require __DIR__.'/pages/workspace.php';return (string)ob_get_clean();}catch (\Throwable $error) {ob_end_clean();throw $error;}
  }
}
