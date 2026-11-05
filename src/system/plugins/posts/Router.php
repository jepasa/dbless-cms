<?php
/** Posts private routing and localized fragment preparation; no arbitrary script resolution, migration during GET or duplicate editor runtime. */
declare(strict_types=1);
/** Trusted Posts implementation and retained internal contracts.
 * @package DBLESSCMS
 */
namespace DBLESSCMS;
/** Prepare declared Posts administration fragments and authenticated previews using shared Core editor services. */
final class PostsRouter {
  /** Build local administration navigation; receive context/declared route/query, return URL without effects. */
  public static function url(PluginContext $context,string $route,array $query=[]): string {return $context->base.'/cms-admin/?'.http_build_query(['view'=>$route==='preview'?'plugin':'plugin-panel','plugin'=>'posts','route'=>$route]+$query);}
  /** Resolve a user-owned or localized built-in type label; receive type/language/plural flag, return escaped-at-use plain text without effects. */
  public static function label(array $type,Language $language,bool $plural=false): string {return $type[$plural?'plural':'singular']!==''?$type[$plural?'plural':'singular']:$language->text(($plural?'posts.types.':'posts.type.').$type['id']);}
  /** Render the declared route using shared Core/editor assets; receive trusted context, return fragment or saved private preview, propagate validation/I/O failures. */
  public static function render(PluginContext $context): string {
    $route=$context->route;if(!isset($context->manifest['routes'][$route]))PostTypes::fail('operation',404);$repository=new Posts($context->store);$catalog=$repository->read();$data=$catalog['data'];$terms=$repository->terms($data);$rows=Access::records($context->config,$repository->records($data));$id=$_GET['id']??'';if(!is_string($id)||($id!==''&&!PostTypes::identifier($id)))PostTypes::fail('query',400);
    if($route==='preview'){$row=$rows[$id]??throw new HttpError(404,'Publication unavailable.','posts.error.missing');header('X-Robots-Tag: noindex, nofollow, noarchive');return PostsView::document($context->store,$row,$row['draft'],$context->base,true);}
    /** Translate fixed plugin text; receive key/parameters, return plain localized text without I/O. */
    $t=static fn(string $key,array $parameters=[]):string=>$context->language->text('posts.'.$key,$parameters);
    /** Build a fixed plugin destination; receive declared route/query, return local URL without writes. */
    $url=static fn(string $target,array $query=[]):string=>self::url($context,$target,$query);
    $revision=$catalog['revision'];$siteRevision=$context->store->read('site')['revision'];$sourceRevision=$repository->sourceRevision();$csrf=Security::token();
    /** Emit independent CSRF/catalog/site/source identity fields; receive optional ID, output escaped inputs with no mutations. */
    $hidden=static function(string $key='')use($revision,$siteRevision,$sourceRevision,$csrf):void{foreach(['id'=>$key,'csrf_token'=>$csrf,'revision'=>$revision,'site_revision'=>$siteRevision,'source_revision'=>$sourceRevision] as $field=>$value)echo '<input type="hidden" name="'.e($field).'" value="'.e($value).'">';};
    ob_start();try{require __DIR__.'/pages/workspace.php';return (string)ob_get_clean();}catch(\Throwable $failure){ob_end_clean();throw $failure;}
  }
}
