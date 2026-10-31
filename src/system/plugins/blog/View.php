<?php
/** Blog public queries/rendering: published-only projections, active-template integration, canonical metadata, XML discovery and moderated public input. */
declare(strict_types=1);
namespace DBLESSCMS;
final class BlogView {
  /** Build an addressed plugin URL under a supplied base; takes base/declared route/query, returns URL with RFC3986 parameters, no writes. */
  public static function url(string $base,string $route='list',array $query=[]): string {
    return rtrim($base,'/').'/plugins/blog/'.$route.($query===[]?'':'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986));
  }
  /** Resolve local editorial raster URL; takes base/reference, returns empty or URL without remote requests or reads. */
  public static function media(string $base,string $reference): string {return $reference===''?'':rtrim($base,'/').'/'.implode('/',array_map('rawurlencode',explode('/',$reference)));}
  /** Read a typed bounded query parameter; takes key/maximum/default, returns string or raises 400/422 without rendering it. */
  private static function parameter(string $key,int $max=64,string $default=''): string {
    $value=$_GET[$key]??$default;if (!is_string($value)||strlen($value)>$max) throw new HttpError(400,'Invalid blog query.','error.parameter');
    BlogModel::text($value,$max,true);return $value;
  }
  /** Project a filtered page with bounded pagination; takes catalog/options/query/clock, returns visible-only results or 404 for missing filters/pages. */
  public static function listing(array $catalog,array $settings,array $query,?int $now=null): array {
    foreach (['q'=>128,'category'=>64,'tag'=>64,'author'=>64,'page'=>6] as $key=>$max) {BlogModel::expect(is_string($query[$key]??null));BlogModel::text($query[$key],$max,true);}
    $items=BlogModel::published($catalog,$now);$categories=[];
    foreach (['category'=>'categories','tag'=>'tags','author'=>'authors'] as $filter=>$kind) if ($query[$filter]!=='') {
      if (!BlogModel::identifier($query[$filter])||!isset($catalog[$kind][$query[$filter]])||$catalog[$kind][$query[$filter]]['deleted']) throw new HttpError(404,'Archive unavailable.','blog.error.missing');
    }
    if ($query['category']!=='') {
      $categories=[$query['category']];
      for ($depth=0;$depth<5;$depth++) foreach ($catalog['categories'] as $entry) if (!$entry['deleted']&&in_array($entry['parent'],$categories,true)&&!in_array($entry['id'],$categories,true)) $categories[]=$entry['id'];
    }
    $items=array_filter($items,static function(array $article) use($query,$categories): bool {
      if ($categories!==[]&&array_intersect($article['categories'],$categories)===[]) return false;
      if ($query['tag']!==''&&!in_array($query['tag'],$article['tags'],true)) return false;
      if ($query['author']!==''&&$article['author']!==$query['author']) return false;
      return $query['q']===''||preg_match('/'.preg_quote($query['q'],'/').'/iu',$article['title'].' '.$article['excerpt'].' '.BlogModel::bodyText($article))===1;
    });
    if ($query['q']===''&&$query['category']===''&&$query['tag']===''&&$query['author']==='') uasort($items,static fn(array $a,array $b): int=>($b['featured']<=>$a['featured'])?:strcmp($b['publishedAt'],$a['publishedAt'])?:strcmp($a['id'],$b['id']));
    if (!preg_match('/^[1-9][0-9]{0,4}$/D',$query['page'])) throw new HttpError(400,'Invalid page number.','error.parameter');
    $number=(int)$query['page'];$size=$settings['publication']['pageSize'];$total=count($items);$pages=max(1,(int)ceil($total/$size));
    if ($number>$pages) throw new HttpError(404,'Page unavailable.','blog.error.missing');
    $publicCatalog=$catalog;$publicCatalog['articles']=BlogModel::published($catalog,$now);
    return ['kind'=>'list','articles'=>array_values(array_slice($items,($number-1)*$size,$size,true)),'catalog'=>$publicCatalog,'query'=>$query,'number'=>$number,'pages'=>$pages,'total'=>$total,'preview'=>false];
  }
  /** Render declared public route only; takes minimal context, returns escaped HTML/XML or empty redirect, checks activation/method before any public mutation. */
  public static function publicPage(PublicPluginContext $context): string {
    $store=new JsonStore(new Config());$registry=new Plugins($store);$state=$registry->state('blog');
    if (!$state['enabled']) throw new HttpError(404,'Blog unavailable.','error.not_found');
    $catalog=(new BlogStore($store))->read()['data'];$settings=$state['settings'];
    if ($context->route==='comment') return self::comment($store,$context,$catalog,$settings);
    if ($context->method!=='GET') throw new HttpError(405,'Method not allowed.','error.method');
    if (in_array($context->route,['feed','sitemap'],true)) return self::xml($store,$catalog,$settings,$context->route);
    if ($context->route==='article') {
      $slug=self::parameter('slug');if (!BlogModel::identifier($slug)) throw new HttpError(404,'Article unavailable.','blog.error.missing');
      foreach (BlogModel::published($catalog) as $article) if ($article['slug']===$slug||in_array($slug,$article['aliases'],true)) {
        if ($article['slug']!==$slug) {header('Location: '.self::url($context->base,'article',['slug'=>$article['slug']]),true,301);return '';}
        return self::document($store,$context->base,['kind'=>'article','article'=>$article,'catalog'=>$catalog,'preview'=>false],$settings);
      }
      throw new HttpError(404,'Article unavailable.','blog.error.missing');
    }
    if ($context->route!=='list') throw new HttpError(404,'Blog route unavailable.','error.not_found');
    $query=[];foreach (['q'=>128,'category'=>64,'tag'=>64,'author'=>64,'page'=>6] as $key=>$max) $query[$key]=self::parameter($key,$max,$key==='page'?'1':'');
    return self::document($store,$context->base,self::listing($catalog,$settings,$query),$settings);
  }
  /** Add an anonymous comment as pending under lock; takes private services/public context/catalog/settings, returns PRG redirect, rejects disabled/stale/private posts and never stores email/IP. */
  private static function comment(JsonStore $store,PublicPluginContext $context,array $catalog,array $settings): string {
    if ($context->method!=='POST') throw new HttpError(405,'Method not allowed.','error.method');
    if (!$settings['discussion']['enabled']) throw new HttpError(404,'Comments are closed.','blog.error.closed');
    $id=$_POST['article']??null;$name=$_POST['name']??null;$body=$_POST['body']??null;$trap=$_POST['website']??'';
    BlogModel::expect(BlogModel::identifier($id));BlogModel::text($name,100);BlogModel::text($body,$settings['discussion']['maxLength'],false,true);BlogModel::text($trap,200,true);
    BlogModel::expect(($_POST['consent']??null)==='1','blog.error.consent');
    Security::limit($store->config,'blog-comment',5,600);
    $slug=$store->transaction(function() use($store,$id,$name,$body,$trap): string {
      $state=(new Plugins($store))->state('blog');$current=(new BlogStore($store))->read()['data'];
      if (!$state['enabled']||!$state['settings']['discussion']['enabled']||!isset($current['articles'][$id])||!BlogModel::visible($current['articles'][$id])) throw new HttpError(404,'Article unavailable.','blog.error.missing');
      BlogModel::text($body,$state['settings']['discussion']['maxLength'],false,true);
      if ($trap!=='') return $current['articles'][$id]['slug'];
      $comments=new BlogStore($store,'comments');$snapshot=$comments->read();
      $comments->mutate($snapshot['revision'],static function(array $data) use($id,$name,$body): array {
        foreach ($data['comments'] as $entry) if ($entry['article']===$id&&$entry['name']===$name&&$entry['body']===$body&&in_array($entry['status'],['pending','approved'],true)&&strtotime($entry['createdAt'])>time()-86400) throw new HttpError(409,'Duplicate comment.','blog.error.duplicate');
        $key='comment-'.bin2hex(random_bytes(12));$data['comments'][$key]=['id'=>$key,'article'=>$id,'name'=>$name,'body'=>$body,'status'=>'pending','createdAt'=>gmdate('Y-m-d\TH:i:s\Z')];return $data;
      });
      return $current['articles'][$id]['slug'];
    });
    header('Location: '.self::url($context->base,'article',['slug'=>$slug,'submitted'=>'1']).'#comments',true,303);return '';
  }
  /** Serialize RSS or sitemap from only visible indexable articles; takes store/catalog/settings/route, returns XML with context-safe escaping and content type. */
  private static function xml(JsonStore $store,array $catalog,array $settings,string $route): string {
    $site=$store->read('site')['data'];$base=rtrim($site['settings']['canonical'],'/');
    $articles=array_filter(BlogModel::published($catalog),static fn(array $item): bool=>!$item['noindex']);
    /** Escape XML values; takes text and returns escaped UTF-8 without I/O. */
    $x=static fn(string $value): string=>htmlspecialchars($value,ENT_XML1|ENT_QUOTES,'UTF-8');
    header('Content-Type: '.($route==='feed'?'application/rss+xml':'application/xml').'; charset=UTF-8');
    $xml='<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    if ($route==='sitemap') {
      $xml.='<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>'.$x(self::url($base)).'</loc></url>';
      foreach ($articles as $article) $xml.='<url><loc>'.$x(self::url($base,'article',['slug'=>$article['slug']])).'</loc><lastmod>'.$x(max($article['updatedAt'],$article['publishedAt'])).'</lastmod></url>';
      return $xml.'</urlset>';
    }
    $xml.='<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel><title>'.$x($settings['publication']['title']).'</title><description>'.$x($settings['publication']['description']).'</description><link>'.$x(self::url($base)).'</link><atom:link href="'.$x(self::url($base,'feed')).'" rel="self" type="application/rss+xml" />';
    foreach (array_slice($articles,0,$settings['publication']['feedLimit'],true) as $article) {
      $url=self::url($base,'article',['slug'=>$article['slug']]);$xml.='<item><title>'.$x($article['title']).'</title><link>'.$x($url).'</link><guid isPermaLink="false">'.$x($base.'#blog-'.$article['id']).'</guid><pubDate>'.gmdate(DATE_RSS,(int)strtotime($article['publishedAt'])).'</pubDate><description>'.$x($article['excerpt']).'</description>';
      foreach ($article['categories'] as $category) $xml.='<category>'.$x($catalog['categories'][$category]['name']).'</category>';$xml.='</item>';
    }
    return $xml.'</channel></rss>';
  }
  /** Render a public or authenticated saved article within the active template; takes store/base/model/options, returns full HTML without content mutation, rejecting anonymous previews with 403 and non-visible public articles with 404. */
  public static function document(JsonStore $store,string $base,array $model,array $options): string {
    if ($model['preview']&&!Security::authenticated($store->config)) throw new HttpError(403,'Authentication required.','error.authentication');
    if (!$model['preview']) {
      if ($model['kind']==='article'&&!BlogModel::visible($model['article'])) throw new HttpError(404,'Article unavailable.','blog.error.missing');
      $model['catalog']['articles']=BlogModel::published($model['catalog']);
      if ($model['kind']==='list') $model['articles']=array_values(array_filter($model['articles'],static fn(array $item): bool=>BlogModel::visible($item)));
    }
    $site=$store->read('site')['data'];$id=$site['template'];$manifest=$store->templates->get($id);$base_url=$base;$currentPage='blog';$settings=$site['settings'];
    $publicLocale=SiteLocale::resolve($settings);header('Content-Language: '.$publicLocale['tag']);
    $siteContent=GlobalSettings::presentation($site,$store,$id)+['home'=>[],'details'=>['blog'=>[]]];
    $widgets=new Widgets($store,$id);
    $templateSettings=isset($manifest['configuration'])?(new Extensions($store))->options($id)['values']:[];
    $navigation=new Navigation($store,$id,$base);$slides=new Slides($store,$id);$blogModel=$model;$blogSettings=$options;
    $title=$options['publication']['title'];$description=$options['publication']['description'];$cover='';$coverAlt='';$canonical=self::url(rtrim($settings['canonical'],'/'));$robots='index, follow';
    if ($model['kind']==='article') {
      $article=$model['article'];$title=$article['seoTitle']?:$article['title'];$description=$article['seoDescription']?:$article['excerpt'];$cover=$article['cover'];$coverAlt=$article['coverAlt'];
      $canonical=self::url(rtrim($settings['canonical'],'/'),'article',['slug'=>$article['slug']]);
      if ($model['preview']||$article['noindex']) $robots='noindex, nofollow';
      $published=BlogModel::published($model['catalog']);unset($published[$article['id']]);
      uasort($published,static function(array $a,array $b) use($article): int {
        $aScore=count(array_intersect($article['categories'],$a['categories']))+count(array_intersect($article['tags'],$a['tags']));$bScore=count(array_intersect($article['categories'],$b['categories']))+count(array_intersect($article['tags'],$b['tags']));return ($bScore<=>$aScore)?:strcmp($b['publishedAt'],$a['publishedAt']);
      });
      $blogModel['related']=array_slice($published,0,3);$blogModel['comments']=[];
      if (!$model['preview']&&$options['discussion']['enabled']) foreach ((new BlogStore($store,'comments'))->read()['data']['comments'] as $comment) if ($comment['article']===$article['id']&&$comment['status']==='approved') $blogModel['comments'][]=$comment;
    } else {
      $params=array_filter($model['query'],static fn(string $value,string $key): bool=>$value!==''&&($key!=='page'||$value!=='1'),ARRAY_FILTER_USE_BOTH);
      $canonical=self::url(rtrim($settings['canonical'],'/'),'list',$params);
      if ($model['query']['q']!=='') $robots='noindex, follow';
      foreach (['category'=>'categories','tag'=>'tags','author'=>'authors'] as $key=>$kind) if ($model['query'][$key]!=='') {$entry=$model['catalog'][$kind][$model['query'][$key]];$title=$entry['name'].' · '.$title;$description=$entry[$kind==='authors'?'bio':'description']?:$description;}
      if ($model['number']>1) $title.=' · '.$model['number'];
    }
    if (str_starts_with($robots,'noindex')) header('X-Robots-Tag: '.$robots);
    $contentMetadata=['canonical'=>$canonical,'robots'=>$robots,'type'=>$model['kind']==='article'?'article':'website','publishedAt'=>$model['kind']==='article'?$model['article']['publishedAt']:'','updatedAt'=>$model['kind']==='article'?max($model['article']['updatedAt'],$model['article']['publishedAt']):'','feed'=>self::url($base,'feed')];
    $page=['slug'=>'blog','status'=>'published','page'=>'starter-page','meta'=>['title'=>$title,'description'=>$description,'image'=>$cover,'imageAlt'=>$coverAlt],'content'=>[]];$route=['meta'=>$page['meta']];
    $contentFile=isset($manifest['partials']['blog'])?$store->templates->path($id,'partials',$manifest['partials']['blog']):__DIR__.'/pages/public.php';
    ob_start();try {require $store->templates->path($id,'partials',$manifest['partials']['layout']);return (string)ob_get_clean();}catch (\Throwable $error) {ob_end_clean();throw $error;}
  }
  /** Obtain public homepage examples when the extension is installed/enabled; takes store/limit, returns visible articles or empty, propagating corrupt content rather than hiding it. */
  public static function latest(JsonStore $store,int $limit=3): array {
    $registry=new Plugins($store);if (!isset($registry->all()['blog'])||!$registry->state('blog')['enabled']) return [];
    return array_slice(BlogModel::published((new BlogStore($store))->read()['data']),0,$limit);
  }
}
