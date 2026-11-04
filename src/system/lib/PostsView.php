<?php
/** Public publication dispatch and template integration: finite search/archives, current canonical routes and saved previews; never migrates storage or executes author code. */
declare(strict_types=1);
namespace DBLESSCMS;
final class PostsView {
  /** Serve owned friendly routes before legacy rendering; receive store/path/base, emit validated HTML/redirect and return true, false if unowned, 404 for unavailable or unreleased-only destinations. */
  public static function dispatch(JsonStore $store,string $path,string $base): bool {
    $repository=new Posts($store);$registry=new Plugins($store);if(!isset($registry->all()['posts']))return false;$data=$repository->read()['data'];$enabled=isset($registry->all()['posts'])&&$registry->state('posts')['enabled'];if(!$enabled&&$data['records']===[])return false;
    if(isset($data['routeAliases'][$path])){if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')throw new HttpError(405,'Method not allowed.','error.method');$alias=$data['routeAliases'][$path];$prefix=$alias['kind']==='search'?$data['searchPath']:PostTypes::prefix($data['types'][$alias['type']]);$destination=$prefix===null?'plugins/blog/list':($prefix===''?$data['searchPath'].'?type='.rawurlencode($alias['type']):$prefix);$query=[];foreach(['q','type','group','tags','page'] as $key)if(isset($_GET[$key])&&is_string($_GET[$key])&&strlen($_GET[$key])<=1024)$query[$key]=$_GET[$key];if($query!==[])$destination.=(str_contains($destination,'?')?'&':'?').http_build_query($query,'','&',PHP_QUERY_RFC3986);header('Location: '.rtrim($base,'/').'/'.$destination,true,301);return true;}
    $archive=null;$owned=$path===$data['searchPath'];foreach($data['types'] as $type){$prefix=PostTypes::prefix($type);if($prefix!==null&&$prefix!==''){if($path===$prefix){$archive=$type['id'];$owned=true;}elseif(str_starts_with($path,$prefix.'/'))$owned=true;}}
    if($path===$data['searchPath']||$archive!==null){if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')throw new HttpError(405,'Method not allowed.','error.method');echo self::listing($store,$base,$archive);return true;}
    foreach($repository->records($data) as $row){$live=Posts::effective($row);$match=false;
      foreach(['draft','published','scheduled'] as $which)if($row[$which]!==null&&$path===PostTypes::route($data['types'],$row[$which]))$match=true;
      if(in_array($path,$row['aliases'],true)||($row['source']['plugin']==='pages'&&$path===$row['source']['id']))$match=true;
      if(!$match)continue;if(($_SERVER['REQUEST_METHOD']??'GET')!=='GET')throw new HttpError(405,'Method not allowed.','error.method');if($live===null||!$repository->sourceEnabled($row,$data))PostTypes::fail('missing',404);
      $publicPaths=[$row['source']['plugin']==='pages'?$row['source']['id']:'',PostTypes::route($data['types'],$live),...$row['aliases']];if($row['published']!==null)$publicPaths[]=PostTypes::route($data['types'],$row['published']);if(!in_array($path,$publicPaths,true))PostTypes::fail('missing',404);
      $destination=$repository->url($row,$base,$live,$data);if($path!==PostTypes::route($data['types'],$live)){header('Location: '.$destination,true,301);return true;}
      echo self::document($store,$row,$live,$base);return true;
    }
    if($owned)PostTypes::fail('missing',404);return false;
  }
  /** Render one authorized snapshot through native template contracts; receive store/row/snapshot/base/preview, return HTML, enforce public authorization or private authentication with no writes. */
  public static function document(JsonStore $store,array $row,array $snapshot,string $base,bool $preview=false): string {
    if($preview&&!Security::authenticated($store->config))throw new HttpError(403,'Authentication required.','error.authentication');$repository=new Posts($store);$data=$repository->read()['data'];if(!$preview&&(Posts::effective($row)!==$snapshot||!$repository->sourceEnabled($row,$data)))PostTypes::fail('missing',404);
    if($data['types'][$snapshot['type']]['kind']==='article'){
      $registry=new Plugins($store);$registry->instance('blog');$catalog=(new BlogStore($store))->read()['data'];$article=Posts::article($row,$snapshot);$catalog['articles'][$article['id']]=$article;$options=$registry->state('blog')['settings'];$options['discussion']['enabled']=$options['discussion']['enabled']&&$registry->state('blog')['enabled'];
      return BlogView::document($store,$base,['kind'=>'article','article'=>$article,'catalog'=>$catalog,'preview'=>$preview,'postsCanonical'=>$repository->url($row,rtrim($store->read('site')['data']['settings']['canonical'],'/'),$snapshot,$data)],$options);
    }
    $key=$row['source']['plugin']==='pages'?$row['source']['id']:$row['id'];return App::render($store,$key,$preview,null,$base,pagePreview:$preview?$snapshot['document']:null);
  }
  /** Render public search or one type archive in the active layout; receive store/base/optional fixed type, return escaped HTML with only public projections and no admin dictionaries. */
  public static function listing(JsonStore $store,string $base,?string $type=null): string {
    $repository=new Posts($store);$data=$repository->read()['data'];$query=Posts::filters($_GET);if($type!==null)$query['type']=$type;
    $postsModel=$repository->search($query,$base);$postsTerms=$repository->terms($data);$postsTypes=$data['types'];$postsSearch=$data['searchPath'];$postsArchive=$type;
    $site=$store->read('site')['data'];$id=$site['template'];$manifest=$store->templates->get($id);$base_url=$base;$currentPage='posts';$settings=$site['settings'];$publicLocale=SiteLocale::resolve($settings);header('Content-Language: '.$publicLocale['tag']);
    $publicCopy=self::copy($publicLocale['tag']);$title=$type===null?$publicCopy['search']:($data['types'][$type]['plural']?:$publicCopy[$type==='page'?'pages':'articles']);$description=$publicCopy['lead'];
    $page=['slug'=>'posts','status'=>'published','page'=>'starter-page','meta'=>['title'=>$title,'description'=>$description,'image'=>'','imageAlt'=>''],'content'=>[]];$route=['meta'=>$page['meta']];$pageMetadata=['title'=>$title,'description'=>$description,'canonical'=>rtrim($settings['canonical'],'/').'/'.($type===null?$data['searchPath']:PostTypes::prefix($data['types'][$type])),'robots'=>$type===null||$query['q']!==''?'noindex, follow':'index, follow'];$contentMetadata=$pageMetadata+['type'=>'website','publishedAt'=>'','updatedAt'=>'','feed'=>$base.'/plugins/posts/feed'];
    if(str_starts_with($pageMetadata['robots'],'noindex'))header('X-Robots-Tag: '.$pageMetadata['robots']);
    $siteContent=GlobalSettings::presentation($site,$store,$id)+['home'=>[],'details'=>['posts'=>[]]];$widgets=new Widgets($store,$id);$templateSettings=isset($manifest['configuration'])?(new Extensions($store))->options($id)['values']:[];$navigation=new Navigation($store,$id,$base);$slides=new Slides($store,$id);
    $contentFile=isset($manifest['partials']['posts'])?$store->templates->path($id,'partials',$manifest['partials']['posts']):$store->config->root.'/plugins/posts/pages/public.php';
    ob_start();try{require $store->templates->path($id,'partials',$manifest['partials']['layout']);$html=(string)ob_get_clean();}catch(\Throwable $failure){ob_end_clean();throw $failure;}
    return (new Privacy($store))->decorate((new SiteCustomizations($store))->decorate($html,$base),$base);
  }
  /** Return public template copy independent from adminLocale; receive public tag, return pt/en fallback strings, no editorial translation or I/O. */
  public static function copy(string $tag): array {
    return str_starts_with(strtolower($tag),'pt')?['search'=>'Buscar publicações','lead'=>'Explore páginas, artigos e outros conteúdos publicados.','pages'=>'Páginas','articles'=>'Artigos','text'=>'Buscar por texto','type'=>'Tipo','all'=>'Todos','group'=>'Grupo','tags'=>'Tags','tagHelp'=>'Selecione uma ou mais tags','submit'=>'Buscar','empty'=>'Nenhuma publicação encontrada.','previous'=>'Anterior','next'=>'Próxima','results'=>'Resultados','clear'=>'Limpar filtros']:['search'=>'Search publications','lead'=>'Explore published pages, articles and other content.','pages'=>'Pages','articles'=>'Articles','text'=>'Search text','type'=>'Type','all'=>'All','group'=>'Group','tags'=>'Tags','tagHelp'=>'Choose one or more tags','submit'=>'Search','empty'=>'No publications found.','previous'=>'Previous','next'=>'Next','results'=>'Results','clear'=>'Clear filters'];
  }
  /** Serialize indexable released publications only; receive store/format, return bounded RSS/sitemap XML without comments/private content or mutations. */
  public static function xml(JsonStore $store,string $format): string {
    if(!in_array($format,['feed','sitemap'],true))PostTypes::fail('operation',404);$repository=new Posts($store);$data=$repository->read()['data'];$site=$store->read('site')['data'];$base=rtrim($site['settings']['canonical'],'/');$items=[];
    foreach($repository->records($data) as $row){$snap=Posts::effective($row);if($snap!==null&&!$snap['seo']['noindex']&&$repository->sourceEnabled($row,$data))$items[]=$repository->projection($row,$snap,$base,$data);}
    usort($items,static fn(array $a,array $b):int=>strcmp($b['publishedAt'],$a['publishedAt'])?:strcmp($a['id'],$b['id']));
    /** Escape XML text/attributes; receive plain scalar, return XML-safe bytes without effects. */
    $x=static fn(string $value):string=>htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8');header('Content-Type: '.($format==='feed'?'application/rss+xml':'application/xml').'; charset=UTF-8');$xml='<?xml version="1.0" encoding="UTF-8"?>';
    if($format==='sitemap'){$xml.='<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';foreach($items as $item)$xml.='<url><loc>'.$x($item['url']).'</loc></url>';return $xml.'</urlset>';}
    $xml.='<rss version="2.0"><channel><title>'.$x($site['settings']['name']).'</title><description>'.$x($site['settings']['description']).'</description><link>'.$x($base).'</link>';foreach(array_slice($items,0,100) as $item)$xml.='<item><title>'.$x($item['title']).'</title><link>'.$x($item['url']).'</link><guid isPermaLink="false">'.$x($item['id']).'</guid><description>'.$x($item['summary']).'</description>'.($item['publishedAt']===''?'':'<pubDate>'.gmdate(DATE_RSS,(int)strtotime($item['publishedAt'])).'</pubDate>').'</item>';return $xml.'</channel></rss>';
  }
}
