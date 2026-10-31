<?php
/** Exporta conteúdo sanitizado/indentado e valida idiomas; CLI recebe staging confiável, sem credenciais/estado. */
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require_once dirname(__DIR__).'/bootstrap.php';
$target=$argv[1]??'';
if (!is_string($target) || !is_dir($target) || is_link($target)) throw new RuntimeException('Diretório staging inválido.');
$config=new \DBLESSCMS\Config();
// Checkout novo exporta a distribuição inicial sem criar estado editorial na árvore de desenvolvimento.
if (!is_file($config->data['contents'].'/site.json')&&!getenv('DBLESS_CMS_CONTENTS_ROOT')) {
  putenv('DBLESS_CMS_CONTENTS_ROOT='.$config->root.'/resources/contents');$config=new \DBLESSCMS\Config();
}
$store=new \DBLESSCMS\JsonStore($config);
$pluginDocument=$store->read('plugins')['data'];
$registry=new \DBLESSCMS\Plugins($store);
if ($registry->invalid()!==[]) throw new RuntimeException('Manifesto de plugin inválido.');
foreach ($pluginDocument['plugins'] as $id=>$state) {
  if (!isset($registry->all()[$id]) && !$state['enabled']) { unset($pluginDocument['plugins'][$id]); continue; }
  $registry->get($id); \DBLESSCMS\Schema::validate($state['settings'],$registry->schema($id));
}
$templateDocument=$store->read('templates')['data'];
foreach ($templateDocument['templates'] as $id=>$options) {
  if (!isset($store->templates->all()[$id])) { unset($templateDocument['templates'][$id]); continue; }
  \DBLESSCMS\Schema::validate($options,$store->templates->configuration($id)['schema']);
}
$site=$store->read('site')['data']; $pages=$store->pages();
$languages=new \DBLESSCMS\Language();
if ($languages->invalid()!==[] || (isset($site['settings']['adminLocale']) && !$languages->available($site['settings']['adminLocale']))) throw new RuntimeException('Dicionários ou idioma padrão inválidos; exportação interrompida.');
foreach ($store->templates->all() as $manifest) foreach ($pages as $key=>$page) $store->document((string)$key,\DBLESSCMS\JsonStore::encode($page['data']),$manifest['id']);
$directory=$target.'/system/storage/contents/pages';
if (!mkdir($directory,0770,true) && !is_dir($directory)) throw new RuntimeException('Destino de conteúdo indisponível.');
foreach (['site'=>$site,'plugins'=>$pluginDocument,'templates'=>$templateDocument]+array_map(static fn($item)=>$item['data'],$pages) as $key=>$data) {
 $file=$target.'/system/storage/contents/'.(in_array($key,['site','plugins','templates'],true)?$key:'pages/'.$key).'.json';
 $bytes=\DBLESSCMS\JsonStore::encode($data);
 if (file_put_contents($file,$bytes)!==strlen($bytes)) throw new RuntimeException('Exportação incompleta.');
}
$menus=(new \DBLESSCMS\MenuStore($store))->read()['data'];
$menus['menus']=array_filter($menus['menus'],static fn(array $menu): bool=>!$menu['deleted']);
$menus['assignments']=array_values(array_filter($menus['assignments'],static fn(array $binding): bool=>isset($menus['menus'][$binding['menu']])));
$menuDirectory=$target.'/system/storage/contents/menu';
if (!mkdir($menuDirectory,0770,true)&&!is_dir($menuDirectory)) throw new RuntimeException('Destino de menu indisponível.');
$menuBytes=\DBLESSCMS\MenuStore::encode(\DBLESSCMS\MenuStore::validate($menus));
if (file_put_contents($menuDirectory.'/menus.json',$menuBytes)!==strlen($menuBytes)) throw new RuntimeException('Exportação de menu incompleta.');
$slides=(new \DBLESSCMS\SlidesStore($store))->read()['data'];
$slides['collections']=array_filter($slides['collections'],static fn(array $set): bool=>!$set['deleted']&&$set['status']==='published');
foreach($slides['collections'] as &$set)$set['items']=array_values(array_filter($set['items'],static fn(array $item): bool=>$item['enabled']));unset($set);
$slides['assignments']=array_values(array_filter($slides['assignments'],static fn(array $binding): bool=>isset($slides['collections'][$binding['collection']])));
$slides['assignments']=array_values(array_filter($slides['assignments'],static function(array $binding) use($store,$slides): bool {
  try{\DBLESSCMS\Slides::binding($store,$slides,$binding);return true;}
  catch(\DBLESSCMS\HttpError $error){if(in_array($error->status,[404,422],true))return false;throw $error;}
}));
$slideDirectory=$target.'/system/storage/contents/slides';
if(!mkdir($slideDirectory,0770,true)&&!is_dir($slideDirectory))throw new RuntimeException('Destino de slides indisponível.');
$slideBytes=\DBLESSCMS\SlidesStore::encode(\DBLESSCMS\SlidesStore::validate($slides));
if(file_put_contents($slideDirectory.'/slides.json',$slideBytes)!==strlen($slideBytes))throw new RuntimeException('Exportação de slides incompleta.');
if (isset($registry->all()['blog'])) {
  require_once $config->root.'/plugins/blog/Plugin.php';
  require_once $config->root.'/plugins/blog/Export.php';
  \DBLESSCMS\BlogExport::run($store,$target);
}
if (isset($registry->all()['widgets'])) {
  require_once $config->root.'/plugins/widgets/Plugin.php';require_once $config->root.'/plugins/widgets/Export.php';
  \DBLESSCMS\WidgetsExport::run($store,$target);
}
\DBLESSCMS\GlobalSettings::export($store,$target);
\DBLESSCMS\SiteFavicon::export($store,$target);
echo count($pages)." páginas sanitizadas.\n";
