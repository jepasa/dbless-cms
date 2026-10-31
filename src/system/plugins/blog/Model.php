<?php
/** Blog contracts and pure editorial queries: bounded typed content, immutable references and publication evaluated against an explicit UTC clock. */
declare(strict_types=1);
namespace DBLESSCMS;
final class BlogModel {
  public const LIMIT=4194304;
  /** Reject a contract violation; takes a condition and error key, returns nothing or raises HTTP 422 without echoing submitted content. */
  public static function expect(bool $valid,string $key='blog.error.contract'): void {
    if (!$valid) throw new HttpError(422,'Invalid blog document.',$key);
  }
  /** Validate opaque lowercase IDs/slugs; takes text and returns a boolean without I/O. */
  public static function identifier(mixed $value): bool {return is_string($value)&&preg_match('/^[a-z][a-z0-9-]{0,63}$/D',$value)===1;}
  /** Check exact object keys; takes a decoded object and expected fields, raises 422 for missing/additional/list fields. */
  public static function fields(mixed $value,array $keys): void {
    self::expect(is_array($value)&&!array_is_list($value)&&count($value)===count($keys)&&array_diff(array_keys($value),$keys)===[]);
  }
  /** Validate literal UTF-8 with byte bounds; takes value/limit/empty/multiline/code flags, returns text or raises 422, with no sanitizing data loss. */
  public static function text(mixed $value,int $max,bool $empty=false,bool $multiline=false,bool $code=false): string {
    self::expect(is_string($value));
    self::expect(strlen($value)<=$max&&($empty||trim($value)!=='')&&preg_match('//u',$value)===1);
    self::expect(!preg_match($multiline?'/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/':'/[\x00-\x1F\x7F]/',$value));
    self::expect($code||!preg_match('/[<>]/',$value));return $value;
  }
  /** Validate an exact UTC second timestamp; takes value and empty allowance, returns text or HTTP 422 for invalid calendars/timezones. */
  public static function date(mixed $value,bool $empty=false): string {
    self::expect(is_string($value));if ($empty&&$value==='') return '';
    self::expect(preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$value)===1);
    $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z',$value,new \DateTimeZone('UTC'));
    self::expect($date!==false&&$date->format('Y-m-d\TH:i:s\Z')===$value);return $value;
  }
  /** Validate a local addressed raster reference; takes path or empty, returns path, rejecting URLs, traversal, encoding, SVG and executable extensions. */
  public static function media(mixed $value): string {
    self::text($value,512,true);if ($value==='') return '';
    self::expect(substr_count($value,'/')<=16&&preg_match('/\.(?:png|jpe?g|webp|gif|avif)$/Di',$value)===1);
    foreach (explode('/',$value) as $part) self::expect(strlen($part)<=120&&preg_match('/^[\pL\pN][\pL\pN _().-]*$/uD',$part)===1&&!str_contains($part,'..')&&trim($part)===$part&&!str_ends_with($part,'.'));
    return $value;
  }
  /** Validate an absolute HTTP(S) link or local root-relative destination; takes URL, returns it or 422, without fetching it. */
  public static function link(mixed $url): string {
    self::text($url,2048);self::expect(!preg_match('/\\s/u',$url)&&!str_contains($url,chr(92)));
    if (str_starts_with($url,'/')) self::expect(!str_starts_with($url,'//')&&!str_contains($url,'..'));
    else self::expect(filter_var($url,FILTER_VALIDATE_URL)!==false&&in_array(parse_url($url,PHP_URL_SCHEME),['https','http'],true)&&parse_url($url,PHP_URL_USER)===null&&parse_url($url,PHP_URL_PASS)===null);
    return $url;
  }
  /** Validate ordered unique ID references; takes list and maximum, returns unchanged list or raises HTTP 422. */
  private static function references(mixed $items,int $limit): array {
    self::expect(is_array($items)&&array_is_list($items)&&count($items)<=$limit);
    foreach ($items as $id) self::expect(self::identifier($id));
    self::expect(count(array_unique($items))===count($items));return $items;
  }
  /** Validate closed typed blocks; takes an ordered list, returns it or 422; code stays literal and is escaped at rendering. */
  public static function blocks(mixed $blocks): array {
    self::expect(is_array($blocks)&&array_is_list($blocks)&&count($blocks)<=100);
    foreach ($blocks as $block) {
      self::expect(is_array($block)&&is_string($block['type']??null));
      switch ($block['type']) {
        case 'markup':
          self::fields($block,['type','html','bindings']);self::expect(is_string($block['html'])&&trim($block['html'])!=='');ContentMarkup::definition(['html'=>$block['html'],'bindings'=>$block['bindings']]);break;
        case 'paragraph': case 'quote': case 'code':
          self::fields($block,['type','text']);self::text($block['text'],12000,false,true,$block['type']==='code');break;
        case 'heading':
          self::fields($block,['type','text','level']);self::text($block['text'],200);self::expect(in_array($block['level'],[2,3],true));break;
        case 'link':
          self::fields($block,['type','text','url']);self::text($block['text'],500);self::link($block['url']);break;
        case 'image':
          self::fields($block,['type','src','alt','caption']);self::media($block['src']);self::expect($block['src']!=='');self::text($block['alt'],300);self::text($block['caption'],500,true);break;
        case 'list':
          self::fields($block,['type','items','ordered']);self::expect(is_bool($block['ordered'])&&is_array($block['items'])&&array_is_list($block['items'])&&count($block['items'])>=1&&count($block['items'])<=50);
          foreach ($block['items'] as $item) self::text($item,1000);break;
        default: self::expect(false);
      }
    }
    self::expect(strlen(json_encode($blocks,JSON_THROW_ON_ERROR))<=65536);return $blocks;
  }
  /** Build the empty catalog without I/O; no inputs, returns schema with four maps. */
  public static function emptyCatalog(): array {return ['schema_version'=>1,'articles'=>[],'categories'=>[],'tags'=>[],'authors'=>[]];}
  /** Validate the complete bounded catalog and cross references; takes decoded data, returns validated data or HTTP 422. */
  public static function catalog(array $data): array {
    self::fields($data,['schema_version','articles','categories','tags','authors']);self::expect($data['schema_version']===1);
    foreach (['articles'=>500,'categories'=>100,'tags'=>200,'authors'=>50] as $key=>$limit) self::expect(is_array($data[$key])&&count($data[$key])<=$limit);
    foreach (['categories','tags','authors'] as $kind) foreach ($data[$kind] as $id=>$entry) {
      self::fields($entry,$kind==='authors'?['id','name','bio','image','url','deleted']:['id','name','description','parent','deleted']);
      self::expect(self::identifier($id)&&$entry['id']===$id&&is_bool($entry['deleted']));self::text($entry['name'],160);
      if ($kind==='authors') {
        self::text($entry['bio'],2000,true,true);self::media($entry['image']);self::text($entry['url'],2048,true);
        if ($entry['url']!=='') self::expect(filter_var($entry['url'],FILTER_VALIDATE_URL)!==false&&in_array(parse_url($entry['url'],PHP_URL_SCHEME),['https','http'],true)&&parse_url($entry['url'],PHP_URL_USER)===null&&parse_url($entry['url'],PHP_URL_PASS)===null&&!preg_match('/[\s\\\\]/u',$entry['url']));
      } else {
        self::text($entry['description'],1000,true,true);self::expect(is_string($entry['parent']));
        self::expect($entry['parent']===''||($kind==='categories'&&isset($data['categories'][$entry['parent']])));
        $seen=[$id=>true];$parent=$entry['parent'];$depth=1;
        while ($parent!=='') {self::expect(!isset($seen[$parent])&&++$depth<=5);$seen[$parent]=true;self::expect(isset($data['categories'][$parent])&&is_array($data['categories'][$parent])&&is_string($data['categories'][$parent]['parent']??null));$parent=$data['categories'][$parent]['parent'];}
      }
    }
    $slugs=[];
    foreach ($data['articles'] as $id=>$article) {
      self::fields($article,['id','slug','aliases','title','excerpt','cover','coverAlt','author','categories','tags','status','publishedAt','createdAt','updatedAt','deletedAt','featured','noindex','seoTitle','seoDescription','blocks']);
      self::expect(self::identifier($id)&&$article['id']===$id&&self::identifier($article['slug']));
      $aliases=self::references($article['aliases'],10);
      foreach (array_merge([$article['slug']],$aliases) as $slug) {self::expect(!isset($slugs[$slug]),'blog.error.slug');$slugs[$slug]=$id;}
      self::text($article['title'],200);self::text($article['excerpt'],1000,true,true);self::media($article['cover']);self::text($article['coverAlt'],300,$article['cover']==='');
      self::text($article['seoTitle'],200,true);self::text($article['seoDescription'],500,true);
      self::expect(is_bool($article['featured'])&&is_bool($article['noindex'])&&in_array($article['status'],['draft','published'],true));
      foreach (['createdAt','updatedAt'] as $key) self::date($article[$key]);
      self::date($article['publishedAt'],true);self::date($article['deletedAt'],true);
      self::expect(is_string($article['author'])&&($article['author']===''||isset($data['authors'][$article['author']])));
      foreach (['categories','tags'] as $kind) foreach (self::references($article[$kind],20) as $reference) self::expect(isset($data[$kind][$reference]));
      self::blocks($article['blocks']);
      if ($article['status']==='published') {
        self::expect($article['publishedAt']!==''&&$article['excerpt']!==''&&$article['blocks']!==[]&&$article['author']!==''&&!$data['authors'][$article['author']]['deleted']);
        foreach (['categories','tags'] as $kind) foreach ($article[$kind] as $reference) self::expect(!$data[$kind][$reference]['deleted']);
      }
    }
    return $data;
  }
  /** Validate a separate bounded moderation document; takes data and returns it or 422, without looking up articles or exposing identities. */
  public static function comments(array $data): array {
    self::fields($data,['schema_version','comments']);self::expect($data['schema_version']===1&&is_array($data['comments'])&&count($data['comments'])<=1000);
    foreach ($data['comments'] as $id=>$item) {
      self::fields($item,['id','article','name','body','status','createdAt']);
      self::expect(self::identifier($id)&&$item['id']===$id&&self::identifier($item['article'])&&in_array($item['status'],['pending','approved','spam','deleted'],true));
      self::text($item['name'],100);self::text($item['body'],4000,false,true);self::date($item['createdAt']);
    }
    return $data;
  }
  /** Evaluate visibility without mutating publication state; takes article and optional epoch, returns bool. */
  public static function visible(array $article,?int $now=null): bool {
    return $article['deletedAt']===''&&$article['status']==='published'&&$article['publishedAt']!==''&&strtotime($article['publishedAt'])<=($now??time());
  }
  /** Derive draft/scheduled/published/deleted for the admin; takes article/clock, returns a state without writes. */
  public static function status(array $article,?int $now=null): string {
    return $article['deletedAt']!==''?'deleted':($article['status']==='draft'?'draft':(self::visible($article,$now)?'published':'scheduled'));
  }
  /** Read text suitable for search/excerpts/reading time; takes article, returns plain text and never interprets markup. */
  public static function bodyText(array $article): string {
    $text=[];foreach ($article['blocks'] as $block) $text[]=isset($block['text'])?$block['text']:($block['type']==='list'?implode(' ',$block['items']):($block['type']==='markup'?strip_tags($block['html']):($block['caption']??'')));return implode("\n",$text);
  }
  /** Count Unicode words at the configured speed; takes article/words per minute, returns at least one minute. */
  public static function readingTime(array $article,int $speed): int {preg_match_all('/[\p{L}\p{N}]+/u',self::bodyText($article),$words);return max(1,(int)ceil(count($words[0])/$speed));}
  /** Select only visible articles in stable newest-first order; takes catalog/clock, returns map retaining IDs without changing input. */
  public static function published(array $data,?int $now=null): array {
    $items=array_filter($data['articles'],static fn(array $item): bool=>self::visible($item,$now));
    uasort($items,static fn(array $a,array $b): int=>strcmp($b['publishedAt'],$a['publishedAt'])?:strcmp($a['id'],$b['id']));return $items;
  }
}
