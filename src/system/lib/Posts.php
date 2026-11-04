<?php
/** Unified publication repository: read-only legacy projections, explicit CAS adoption, bounded snapshots/taxonomies, route reservation and private recoverable history. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Posts {
  private ?Plugins $registry=null;
  /** Bind trusted private store without I/O or mutation. */
  public function __construct(private readonly JsonStore $store) {}
  /** Resolve the existing trusted Blog adapter after validating package discovery; no input, return availability, never install or enable a plugin. */
  private function blog(): bool {
    if(class_exists(BlogStore::class,false)&&class_exists(BlogModel::class,false))return true;$registry=$this->registry??=new Plugins($this->store);if(!isset($registry->all()['blog']))return false;$registry->instance('blog');return true;
  }
  /** Build the bounded private catalog primitive; no inputs, return repository with trusted 8 MiB limit and complete validation, no writes. */
  public function repository(): ExtensionDocument {
    return new ExtensionDocument($this->store,'posts/catalog.json',['schema_version'=>1,'searchPath'=>'search','routeAliases'=>[],'types'=>PostTypes::defaults(),'groups'=>[],'tags'=>[],'authors'=>[],'records'=>[]],fn(array $data):array=>$this->validate($data),true,8388608);
  }
  /** Read private catalog/revision; no inputs/writes, absence uses additive defaults and corruption propagates. */
  public function read(): array {return $this->repository()->read();}
  /** Return a deterministic unified identity without changing original identity; receive source plugin/ID, return stable opaque key, no I/O. */
  public static function identity(string $plugin,string $id): string {return 'pub-'.substr(hash('sha256',$plugin.':'.$id),0,24);}
  /** Validate source mappings/snapshots and taxonomy overrides on every read; receive catalog, return validated value or 422, never mutate content. */
  private function validate(array $data): array {
    if(array_diff(array_keys($data),['schema_version','searchPath','routeAliases','types','groups','tags','authors','records'])||count($data)!==8||($data['schema_version']??null)!==1)PostTypes::fail();
    foreach(['routeAliases','types','groups','tags','authors','records'] as $key)if(!is_array($data[$key]??null))PostTypes::fail();
    PostTypes::path($data['searchPath']);if(count($data['routeAliases'])>50)PostTypes::fail();foreach($data['routeAliases'] as $path=>$target){PostTypes::path($path);if(!is_array($target)||count($target)!==2||!in_array($target['kind']??null,['search','archive'],true)||!is_string($target['type']??null)||($target['kind']==='archive'&&!isset($data['types'][$target['type']])))PostTypes::fail();}PostTypes::validate($data['types']);if(count($data['records'])>700)PostTypes::fail();
    foreach(['groups'=>100,'tags'=>200,'authors'=>50] as $kind=>$limit){if(count($data[$kind])>$limit)PostTypes::fail();foreach($data[$kind] as $id=>$term){
      $keys=$kind==='authors'?['id','name','bio','image','url','deleted']:['id','name','description','parent','deleted'];
      if(!PostTypes::identifier($id)||!is_array($term)||count($term)!==count($keys)||array_diff(array_keys($term),$keys)||($term['id']??null)!==$id||!is_bool($term['deleted']??null))PostTypes::fail();
      foreach($keys as $key)if(!in_array($key,['id','deleted'],true))PostTypes::text($term[$key],$key==='bio'?2000:2048,true,in_array($key,['bio','description'],true));PostTypes::text($term['name'],160,false);
      if($kind!=='authors'&&$term['parent']!==''&&($kind!=='groups'||!PostTypes::identifier($term['parent'])))PostTypes::fail();
      if($kind==='authors'){if($term['url']!==''&&(!filter_var($term['url'],FILTER_VALIDATE_URL)||!in_array(parse_url($term['url'],PHP_URL_SCHEME),['https','http'],true)||parse_url($term['url'],PHP_URL_USER)!==null||parse_url($term['url'],PHP_URL_PASS)!==null))PostTypes::fail();if($term['image']!=='')ContentMarkup::html('<img src="'.e($term['image']).'" alt="">');}
    }}
    foreach($data['records'] as $id=>$row){
      if(!PostTypes::identifier($id)||!is_array($row)||count($row)!==10||array_diff(array_keys($row),['id','source','createdAt','updatedAt','deletedAt','aliases','draft','published','scheduled','sourceRevision'])||($row['id']??null)!==$id||!is_array($row['source']??null)||(count($row['source'])!==2||array_diff(array_keys($row['source']),['plugin','id']))||!in_array($row['source']['plugin'],['posts','pages','blog'],true)||(!is_string($row['source']['id'])||!PagePublications::identifier($row['source']['id']))||!is_array($row['aliases'])||!array_is_list($row['aliases'])||count($row['aliases'])>50||!is_string($row['sourceRevision']))PostTypes::fail();
      if($row['source']['plugin']==='posts'?$row['id']!==$row['source']['id']:$row['id']!==self::identity($row['source']['plugin'],$row['source']['id']))PostTypes::fail();
      foreach(['createdAt','updatedAt','deletedAt'] as $field)if($row[$field]!==null)PagePublications::date($row[$field]);
      foreach($row['aliases'] as $alias)if(!is_string($alias)||strlen($alias)>196||!preg_match('~^[a-z0-9][a-z0-9-]{0,63}(?:/[a-z0-9][a-z0-9-]{0,63}){0,3}$~D',$alias))PostTypes::fail();
      if(!is_array($row['draft']))PostTypes::fail();foreach(['draft','published','scheduled'] as $field)if($row[$field]!==null)$this->snapshot($row[$field],$row['source']['id'],$data['types']);
      if($row['scheduled']!==null&&$row['scheduled']['publishedAt']==='')PostTypes::fail();
    }return $data;
  }
  /** Validate one closed working/public snapshot; receive snapshot/native ID/types, return void or 422 without provider execution or writes. */
  private function snapshot(array $value,string $sourceId,array $types): void {
    $keys=['type','slug','document','seo','author','authorId','order','parent','groups','tags','featured','allowComments','publishedAt','blocks','authored','slugAliases','review'];
    if(count($value)!==count($keys)||array_diff(array_keys($value),$keys)||!isset($types[$value['type']??''])||(!is_string($value['slug']??null)||!PagePublications::identifier($value['slug']))||!is_array($value['document']??null)||!is_array($value['seo']??null)||!is_int($value['order'])||abs($value['order'])>999999||!is_bool($value['featured'])||!is_bool($value['allowComments'])||!is_bool($value['authored'])||!is_bool($value['review']))PostTypes::fail();
    if($types[$value['type']]['kind']==='page'&&$value['allowComments'])PostTypes::fail();
    PostTypes::text($value['author']);foreach(['authorId','parent'] as $key)if(!is_string($value[$key])||($value[$key]!==''&&!PagePublications::identifier($value[$key])))PostTypes::fail();
    foreach(['groups','tags','slugAliases'] as $key){if(!is_array($value[$key])||!array_is_list($value[$key])||count($value[$key])>50)PostTypes::fail();foreach($value[$key] as $id)if(!is_string($id)||!PagePublications::identifier($id))PostTypes::fail();if(count(array_unique($value[$key]))!==count($value[$key]))PostTypes::fail();}
    foreach([$value['document']['meta']['image']??'',$value['seo']['socialImage']??''] as $image)if(is_string($image)&&$image!=='')ContentMarkup::html('<img src="'.e(str_replace(' ','%20',$image)).'" alt="">');
    PagePublications::date($value['publishedAt'],true);$doc=$value['document'];Schema::validate($doc,json_decode((string)file_get_contents($this->store->config->root.'/config/schemas/page.schema.json'),true,64,JSON_THROW_ON_ERROR));if(($doc['slug']??null)!==$sourceId)PostTypes::fail();PostTypes::text($doc['meta']['title'],200,false);PostTypes::text($doc['meta']['description'],$types[$value['type']]['kind']==='article'?1000:500,false,true);foreach(['image','imageAlt'] as $field)PostTypes::text($doc['meta'][$field],2048);if(isset($doc['markup']))ContentMarkup::definition($doc['markup']);$template=$this->store->read('site')['data']['template'];$manifest=$this->store->templates->get($template);if(!isset($manifest['pages'][$doc['page']]))PostTypes::fail();Schema::validate($doc['content'],$this->store->templates->schema($template)['pages'][$doc['page']]);
    if((count($value['seo'])!==count(PagePublications::seo())||array_diff(array_keys($value['seo']),array_keys(PagePublications::seo())))||!is_bool($value['seo']['noindex']))PostTypes::fail();foreach($value['seo'] as $field=>$text)if($field!=='noindex')PostTypes::text($text,2048);
    $url=$value['seo']['canonical'];if($url!==''&&(!filter_var($url,FILTER_VALIDATE_URL)||!in_array(parse_url($url,PHP_URL_SCHEME),['https','http'],true)||parse_url($url,PHP_URL_USER)!==null||parse_url($url,PHP_URL_PASS)!==null||parse_url($url,PHP_URL_FRAGMENT)!==null))PostTypes::fail();
    if($value['blocks']!==null){if(!$this->blog())PostTypes::fail();BlogModel::blocks($value['blocks']);}
  }
  /** Apply the current rendering contract before an explicit write; receive snapshot/source/types, return void or current schema/markup errors without publication. */
  private function validateDocument(array $snapshot,string $sourceId,array $types): void {
    $this->snapshot($snapshot,$sourceId,$types);if($types[$snapshot['type']]['kind']==='page'){$this->store->document($sourceId,JsonStore::encode($snapshot['document']));return;}
    if(!$this->blog())PostTypes::fail();BlogModel::media($snapshot['document']['meta']['image']);BlogModel::blocks($snapshot['blocks']!==null&&!$snapshot['authored']?$snapshot['blocks']:(trim($snapshot['document']['markup']['html']??'')===''?[]:[['type'=>'markup']+$snapshot['document']['markup']]));if(isset($snapshot['document']['markup']))(new Components(new ContentContext($this->store,$this->store->read('site')['data']['template'],'',$sourceId)))->validateBindings($snapshot['document']['markup']['bindings']);
  }
  /** Prevent native Blog mutations from bypassing adopted authority under the same writer lock; receive before/after native catalogs, throw 409 on owned changes or term removal, no writes. */
  public function guardBlog(array $before,array $after): void {
    $data=$this->read()['data'];foreach($data['records'] as $row){$key=$row['source']['plugin']==='blog'?$row['source']['id']:$row['id'];if(($before['articles'][$key]??null)!==($after['articles'][$key]??null))PostTypes::fail('managed',409);
      foreach(['draft','published','scheduled'] as $which){$snap=$row[$which];if($snap===null)continue;foreach(['groups'=>'categories','tags'=>'tags'] as $ours=>$native)foreach($snap[$ours] as $term)if(isset($before[$native][$term])&&(!isset($after[$native][$term])||$after[$native][$term]['deleted']))PostTypes::fail('inUse',409);if($snap['authorId']!==''&&isset($before['authors'][$snap['authorId']])&&(!isset($after['authors'][$snap['authorId']])||$after['authors'][$snap['authorId']]['deleted']))PostTypes::fail('inUse',409);}
    }
    foreach(['groups'=>'categories','tags'=>'tags','authors'=>'authors'] as $ours=>$native)foreach($data[$ours] as $id=>$term)if(($before[$native][$id]??null)!==($after[$native][$id]??null))PostTypes::fail('managed',409);
  }
  /** Read the unoverlaid legacy Blog catalog with its actual revision; no inputs/writes, absent adapter yields empty maps. */
  public function legacyBlog(): array {return $this->blog()?(new BlogStore($this->store))->read(true):['data'=>['articles'=>[],'categories'=>[],'tags'=>[],'authors'=>[]],'revision'=>hash('sha256','')];}
  /** Merge legacy category/tag/author metadata with explicit overrides; receive optional private catalog, return maps without mutating original sources. */
  public function terms(?array $data=null): array {
    $data??=$this->read()['data'];$blog=$this->legacyBlog()['data'];return ['groups'=>array_replace($blog['categories'],$data['groups']),'tags'=>array_replace($blog['tags'],$data['tags']),'authors'=>array_replace($blog['authors'],$data['authors'])];
  }
  /** Convert a native Page snapshot without changing its source bytes or inventing dates; receive snapshot, return unified working data without provider execution. */
  private static function pageSnapshot(array $snapshot): array {
    return ['type'=>'page','slug'=>$snapshot['url'],'document'=>$snapshot['document'],'seo'=>$snapshot['seo'],'author'=>$snapshot['editorial']['author']??'','authorId'=>'','order'=>$snapshot['editorial']['order']??0,'parent'=>$snapshot['parent']??'','groups'=>[],'tags'=>[],'featured'=>false,'allowComments'=>false,'publishedAt'=>$snapshot['publishedAt']??'','blocks'=>null,'authored'=>true,'slugAliases'=>[],'review'=>$snapshot['editorial']['review']??false];
  }
  /** Convert a native Blog article into inert editor data while retaining its complete typed blocks; receive validated article, return snapshot, no writes. */
  private static function articleSnapshot(array $article): array {
    $document=PageBlueprints::create('blank',$article['title'],$article['slug'])['document'];$document['slug']=$article['id'];$document['meta']=array_replace($document['meta'],['description'=>$article['excerpt'],'image'=>$article['cover'],'imageAlt'=>$article['coverAlt']]);
    $seo=PagePublications::seo();$seo['title']=$article['seoTitle'];$seo['description']=$article['seoDescription'];$seo['noindex']=$article['noindex'];
    return ['type'=>'article','slug'=>$article['slug'],'document'=>$document,'seo'=>$seo,'author'=>'','authorId'=>$article['author'],'order'=>0,'parent'=>'','groups'=>$article['categories'],'tags'=>$article['tags'],'featured'=>$article['featured'],'allowComments'=>$article['allowComments']??true,'publishedAt'=>$article['publishedAt'],'blocks'=>$article['blocks'],'authored'=>false,'slugAliases'=>$article['aliases'],'review'=>$article['status']==='pending'];
  }
  /** Project all ordinary legacy and adopted publications; no inputs, return immutable keyed rows, never migrate or hide malformed sources. */
  public function records(?array $data=null): array {
    $data??=$this->read()['data'];$rows=[];$pageRepository=new PagePublications($this->store);$pageCatalog=$pageRepository->read(true);$pages=$this->store->pages(null,false);
    foreach(array_unique([...array_keys($pages),...array_keys($pageCatalog['data']['records'])]) as $key){$key=(string)$key;if(!PagePublications::identifier($key))continue;$id=self::identity('pages',$key);if(isset($data['records'][$id]))continue;
      $row=$pageRepository->record($key,true);$rows[$id]=['id'=>$id,'source'=>['plugin'=>'pages','id'=>$key],'createdAt'=>$row['createdAt'],'updatedAt'=>$row['updatedAt'],'deletedAt'=>$row['deletedAt'],'aliases'=>$row['aliases'],'draft'=>self::pageSnapshot($row['draft']),'published'=>$row['published']===null?null:self::pageSnapshot($row['published']),'scheduled'=>$row['scheduled']===null?null:self::pageSnapshot($row['scheduled']),'sourceRevision'=>$pageCatalog['revision'].':'.($pages[$key]['revision']??'')];
    }
    $blog=$this->legacyBlog();foreach($blog['data']['articles'] as $article){$id=self::identity('blog',$article['id']);if(isset($data['records'][$id]))continue;$snapshot=self::articleSnapshot($article);$published=$article['status']==='published';$future=$published&&strtotime($article['publishedAt'])>time();$snapshot['document']['status']='draft';
      $rows[$id]=['id'=>$id,'source'=>['plugin'=>'blog','id'=>$article['id']],'createdAt'=>$article['createdAt'],'updatedAt'=>$article['updatedAt'],'deletedAt'=>$article['deletedAt']===''?null:$article['deletedAt'],'aliases'=>[],'draft'=>$snapshot,'published'=>$published&&!$future?$snapshot:null,'scheduled'=>$future?$snapshot:null,'sourceRevision'=>$blog['revision']];
    }return array_replace($rows,$data['records']);
  }
  /** List validated native history for one source mapping; receive row, return finite opaque backup IDs and working snapshots for authenticated recovery only, never mutate the source. */
  public function nativeHistory(array $row): array {
    $entries=[];$id=$row['source']['id'];if($row['source']['plugin']==='pages'){
      foreach((new PagePublications($this->store))->history($id) as $backup=>$entry)$entries['editorial:'.$backup]=self::pageSnapshot($entry['record']['draft']);
      foreach($this->store->history($id) as $backup){$doc=$this->store->backupDocument($id,$backup);$entries['page:'.$backup]=self::pageSnapshot(['url'=>$id,'document'=>$doc,'seo'=>PagePublications::seo(),'parent'=>'','editorial'=>PagePublications::editorial(),'publishedAt'=>'']);}
    }elseif($row['source']['plugin']==='blog'&&$this->blog()){
      $store=new BlogStore($this->store);foreach($store->history() as $backup){$native=$store->snapshot($backup);if(isset($native['articles'][$id]))$entries['blog:'.$backup]=self::articleSnapshot($native['articles'][$id]);}
    }return $entries;
  }
  /** Compute the actual legacy source digest for stale-editor protection; no inputs, return SHA-256 without changing content. */
  public function sourceRevision(): string {
    $sources=[];$contents=$this->store->config->data['contents'];foreach(glob($contents.'/pages/*.json')?:[] as $file){Lifecycle::path($file);if(!is_file($file))PostTypes::fail();$sources[basename($file)]=hash_file('sha256',$file);}
    foreach(['page-publications/catalog.json','blog/catalog.json','plugins.json'] as $relative){$file=Lifecycle::path($contents.'/'.$relative);$sources[$relative]=is_file($file)?hash_file('sha256',$file):'';}ksort($sources);return hash('sha256',JsonStore::encode($sources));
  }
  /** Select a due published snapshot; receive record/optional UTC clock, return authorized snapshot or null without writing a transition. */
  public static function effective(array $row,?int $now=null): ?array {if($row['deletedAt']!==null)return null;return $row['scheduled']!==null&&strtotime($row['scheduled']['publishedAt'])<=($now??time())?$row['scheduled']:$row['published'];}
  /** Describe private editorial status; receive row, return closed state key without effects. */
  public static function status(array $row): string {return $row['deletedAt']!==null?'deleted':($row['scheduled']!==null&&strtotime($row['scheduled']['publishedAt'])>time()?'scheduled':(self::effective($row)!==null?'published':(($row['draft']['review']??false)?'pending':'draft')));}
  /** Find an existing projected record by immutable identity; receive ID, return row or 404, no writes. */
  public function record(string $id): array {return $this->records()[$id]??throw new HttpError(404,'Publication unavailable.','posts.error.missing');}
  /** Resolve an adopted native source only; receive plugin/ID, return own record or null, avoiding legacy projection recursion. */
  public function adopted(string $plugin,string $id): ?array {return $this->read()['data']['records'][self::identity($plugin,$id)]??null;}
  /** Build a canonical local URL; receive row/base/optional authorized snapshot, return URL using current type configuration, no writes. */
  public function url(array $row,string $base='',?array $snapshot=null,?array $data=null): string {$data??=$this->read()['data'];return rtrim($base,'/').'/'.PostTypes::route($data['types'],$snapshot??self::effective($row)??$row['draft']);}
  /** Produce public-safe metadata for one snapshot; receive row/snapshot/base/config, return plain fields/source, never private states/history/comments. */
  public function projection(array $row,array $snapshot,string $base='',?array $data=null,?array $terms=null): array {
    $data??=$this->read()['data'];$terms??=$this->terms($data);$doc=$snapshot['document'];$author=$snapshot['authorId']!==''?($terms['authors'][$snapshot['authorId']]['name']??''):$snapshot['author'];
    return ['id'=>$row['id'],'slug'=>$snapshot['slug'],'type'=>$snapshot['type'],'title'=>$doc['meta']['title'],'summary'=>$doc['meta']['description'],'url'=>$this->url($row,$base,$snapshot,$data),'image'=>$doc['meta']['image'],'imageAlt'=>$doc['meta']['imageAlt'],'author'=>$author,'publishedAt'=>$snapshot['publishedAt'],'order'=>$snapshot['order'],'parentId'=>$snapshot['parent'],'groups'=>$snapshot['groups'],'tags'=>$snapshot['tags']];
  }
  /** Normalize public/admin filters without exposing storage identities as a user entry requirement; receive untrusted query, return bounded scalar filters, accepting a checked tag list or canonical CSV; no writes. */
  public static function filters(array $query): array {
    $q=[];foreach(['q'=>128,'type'=>64,'group'=>64,'page'=>6] as $key=>$limit){$value=$query[$key]??($key==='page'?'1':'');if(!is_string($value))PostTypes::fail('query',400);$q[$key]=PostTypes::text($value,$limit);}
    $tags=$query['tags']??'';if(is_array($tags)){if(!array_is_list($tags)||count($tags)>20)PostTypes::fail('query',400);foreach($tags as $tag)if(!PostTypes::identifier($tag))PostTypes::fail('query',400);$tags=implode(',',array_unique($tags));}if(!is_string($tags))PostTypes::fail('query',400);$q['tags']=PostTypes::text($tags,1024);return $q;
  }
  /** Validate finite public/admin filters and project matching released snapshots; receive query/base/private flag, return sorted items and pagination, reject malformed/missing terms. */
  public function search(array $query,string $base='',bool $private=false,bool $trash=false): array {
    if($trash&&!$private)PostTypes::fail('query',400);$data=$this->read()['data'];$terms=$this->terms($data);$q=self::filters($query);
    if(!preg_match('/^[1-9][0-9]{0,4}$/D',$q['page'])||($q['type']!==''&&!isset($data['types'][$q['type']])))PostTypes::fail('query',400);
    $tags=$q['tags']===''?[]:explode(',',$q['tags']);if(count($tags)>20)PostTypes::fail('query',400);foreach($tags as $id)if(!isset($terms['tags'][$id])||$terms['tags'][$id]['deleted'])PostTypes::fail('query',400);
    $groups=[];if($q['group']!==''){if(!isset($terms['groups'][$q['group']])||$terms['groups'][$q['group']]['deleted'])PostTypes::fail('query',400);$groups=[$q['group']];for($depth=0;$depth<5;$depth++)foreach($terms['groups'] as $entry)if(!$entry['deleted']&&in_array($entry['parent'],$groups,true)&&!in_array($entry['id'],$groups,true))$groups[]=$entry['id'];}
    $items=[];foreach($this->records($data) as $row){$snapshot=$private?$row['draft']:self::effective($row);if($snapshot===null||($trash?$row['deletedAt']===null:$row['deletedAt']!==null)||(!$private&&!$this->sourceEnabled($row,$data)))continue;
      if(($q['type']!==''&&$snapshot['type']!==$q['type'])||($groups!==[]&&array_intersect($groups,$snapshot['groups'])===[])||($tags!==[]&&array_intersect($tags,$snapshot['tags'])===[]))continue;
      $item=$this->projection($row,$snapshot,$base,$data,$terms);$body=$snapshot['blocks']!==null&&!$snapshot['authored']?BlogModel::bodyText(['blocks'=>$snapshot['blocks']]):strip_tags($snapshot['document']['markup']['html']??PageEditorDocument::open($snapshot['document'])['html']);$body=preg_replace('/\{\{.*?\}\}/s','',$body);
      if($q['q']!==''&&preg_match('/'.preg_quote($q['q'],'/').'/iu',$item['title'].' '.$item['summary'].' '.html_entity_decode($body,ENT_QUOTES|ENT_HTML5,'UTF-8'))!==1)continue;$items[]=$private?$item+['state'=>self::status($row)]:$item;
    }
    usort($items,static fn(array $a,array $b):int=>strcmp($b['publishedAt'],$a['publishedAt'])?:strcmp($a['id'],$b['id']));$size=$this->store->read('site')['data']['settings']['contentPageSize']??12;$limit=$query['limit']??10000;if(!is_int($limit)||$limit<1||$limit>10000)PostTypes::fail('query',400);$items=array_slice($items,0,$limit);$total=count($items);$page=(int)$q['page'];$pages=(int)ceil($total/$size);
    return ['items'=>$private?$items:array_slice($items,($page-1)*$size,$size),'total'=>$total,'page'=>$page,'pages'=>$pages,'pageSize'=>$size,'hasNext'=>$page<$pages,'hasPrevious'=>$page>1&&$pages>0,'query'=>$q];
  }
  /** Preserve explicit source activation for unadopted content; receive row, return visibility policy without mutations; adopted content belongs to Posts. */
  public function sourceEnabled(array $row,?array $data=null): bool {$data??=$this->read()['data'];if(isset($data['records'][$row['id']]))return true;$registry=$this->registry??=new Plugins($this->store);return isset($registry->all()[$row['source']['plugin']])&&$registry->state($row['source']['plugin'])['enabled'];}
  /** Reserve canonical, pending and alias destinations, plus legacy/static namespaces; receive proposed data, throw 409 on collisions before any write. */
  private function relationships(array $data): void {
    $terms=$this->terms($data);foreach(['groups'=>100,'tags'=>200,'authors'=>50] as $kind=>$maximum)if(count($terms[$kind])>$maximum)PostTypes::fail('term');foreach($terms['groups'] as $id=>$group){$seen=[$id];$parent=$group['parent'];while($parent!==''){if(in_array($parent,$seen,true)||!isset($terms['groups'][$parent])||$terms['groups'][$parent]['deleted']||count($seen)>=5)PostTypes::fail('term');$seen[]=$parent;$parent=$terms['groups'][$parent]['parent'];}}
    $owners=[$data['searchPath']=>'search'];foreach($data['routeAliases'] as $path=>$target)$owners[$path]=$target['kind']==='search'?'search':'archive:'.$target['type'];$prefixes=[];foreach($data['types'] as $type){$prefix=PostTypes::prefix($type);if($prefix!==null&&$prefix!==''){if(isset($owners[$prefix])&&$owners[$prefix]!=='archive:'.$type['id'])PostTypes::fail('collision',409);$owners[$prefix]='archive:'.$type['id'];$prefixes[$prefix]=$type['id'];if($data['searchPath']===$prefix||str_starts_with($data['searchPath'],$prefix.'/')||str_starts_with($prefix,$data['searchPath'].'/'))PostTypes::fail('collision',409);}}
    $public=dirname($this->store->config->root).'/public_html';foreach(array_keys($owners) as $path){try{Lifecycle::path($public.'/'.$path);}catch(\RuntimeException){PostTypes::fail('collision',409);}if(file_exists($public.'/'.$path)||is_link($public.'/'.$path))PostTypes::fail('collision',409);}
    $all=$this->records($data);$sourceOwners=[];
    foreach($all as $id=>$row){$source=$row['source']['plugin'].':'.$row['source']['id'];if(isset($sourceOwners[$source])&&$sourceOwners[$source]!==$id)PostTypes::fail();$sourceOwners[$source]=$id;$urls=$row['aliases'];
      foreach(['draft','published','scheduled'] as $which){$snap=$row[$which];if($snap===null)continue;$this->snapshot($snap,$row['source']['id'],$data['types']);$urls[]=PostTypes::route($data['types'],$snap);foreach($snap['slugAliases'] as $alias){$aliasSnapshot=$snap;$aliasSnapshot['slug']=$alias;$urls[]=PostTypes::route($data['types'],$aliasSnapshot);}
        foreach(['groups','tags'] as $kind)foreach($snap[$kind] as $term)if(!isset($terms[$kind][$term]))PostTypes::fail('term');if($snap['authorId']!==''&&!isset($terms['authors'][$snap['authorId']]))PostTypes::fail('term');
        if($snap['parent']!==''){if(!$data['types'][$snap['type']]['hierarchical'])PostTypes::fail('parent');$next=$snap['parent'];$seen=[$id];while($next!==''){$parentId=isset($all[$next])?$next:self::identity('pages',$next);if(!isset($all[$parentId])||in_array($parentId,$seen,true)||count($seen)>12||$all[$parentId]['deletedAt']!==null||$data['types'][$all[$parentId]['draft']['type']]['kind']!=='page')PostTypes::fail('parent');$seen[]=$parentId;$next=$all[$parentId]['draft']['parent'];}}
      }
      if($row['source']['plugin']==='pages')$urls[]=$row['source']['id'];
      foreach(array_unique($urls) as $url){if(isset($owners[$url])&&$owners[$url]!==$id)PostTypes::fail('collision',409);$owners[$url]=$id;if(!str_contains($url,'?'))try{Lifecycle::path($public.'/'.$url);}catch(\RuntimeException){PostTypes::fail('collision',409);}if(!str_contains($url,'?')&&(file_exists($public.'/'.$url)||is_link($public.'/'.$url)))PostTypes::fail('collision',409);
        foreach($prefixes as $prefix=>$type)if(($url===$prefix||str_starts_with($url,$prefix.'/'))&&$row['draft']['type']!==$type)PostTypes::fail('collision',409);
      }
    }
  }
  /** Return the current URL for an adopted legacy/native source; receive source/ID/base, return URL or null, including new compatibility identities without projection recursion. */
  public function nativeUrl(string $plugin,string $id,string $base=''): ?string {
    $data=$this->read()['data'];$rows=$data['records'];$type=$plugin==='pages'?'page':'article';if(isset($data['types'][$type])&&$data['types'][$type]['routeMode']!=='default')$rows=$this->records($data);foreach($rows as $row)if(($row['source']['plugin']===$plugin&&$row['source']['id']===$id)||$row['id']===$id){$live=self::effective($row);return $live===null?null:$this->url($row,$base,$live,$data);}return null;
  }
  /** Overlay published/working Page metadata for legacy providers; receive raw records, return compatible records with original IDs and current snapshot editorial fields, no recursive source reads. */
  public function pageCatalog(array $records): array {
    $data=$this->read()['data'];$terms=$data['records']===[]?[]:$this->terms($data);foreach($data['records'] as $row){if($row['source']['plugin']==='blog')continue;$key=$row['source']['plugin']==='pages'?$row['source']['id']:$row['id'];$snap=self::effective($row)??$row['draft'];if($data['types'][$snap['type']]['kind']!=='page'){unset($records[$key]);continue;}$snapshots=[];
      foreach(['draft','published','scheduled'] as $which){$value=$row[$which];$snapshots[$which]=$value===null?null:['url'=>$value['slug'],'document'=>$value['document'],'seo'=>$value['seo'],'parent'=>$value['parent'],'publishedAt'=>$value['publishedAt'],'updatedAt'=>$row['updatedAt'],'editorial'=>['author'=>$value['authorId']!==''?($terms['authors'][$value['authorId']]['name']??''):$value['author'],'order'=>$value['order'],'review'=>$value['review']]];}
      $records[$key]=['id'=>$key,'parent'=>$row['draft']['parent'],'model'=>'legacy','createdAt'=>$row['createdAt'],'updatedAt'=>$row['updatedAt'],'deletedAt'=>$row['deletedAt'],'publishAt'=>$row['scheduled']['publishedAt']??$row['published']['publishedAt']??'','aliases'=>array_values(array_filter($row['aliases'],static fn(string $path):bool=>!str_contains($path,'/'))),...$snapshots];
    }return $records;
  }
  /** Reserve active unified namespaces and stored identities for native writers; receive literal path and optional same-source exemption, return bool without projecting native sources or writing state. */
  public function reserved(string $path,string $plugin='',string $source=''): bool {
    $data=$this->read()['data'];$registry=$this->registry??=new Plugins($this->store);$active=isset($registry->all()['posts'])&&$registry->state('posts')['enabled'];if($active||$data['records']!==[]){if($path===$data['searchPath']||isset($data['routeAliases'][$path]))return true;foreach($data['types'] as $type){$prefix=PostTypes::prefix($type);if($prefix!==null&&$prefix!==''&&($path===$prefix||str_starts_with($path,$prefix.'/')))return true;}}
    foreach($data['records'] as $row){if($row['source']['plugin']===$plugin&&$row['source']['id']===$source)continue;if($path===$row['id']||in_array($path,$row['aliases'],true))return true;foreach(['draft','published','scheduled'] as $which)if($row[$which]!==null&&PostTypes::route($data['types'],$row[$which])===$path)return true;}return false;
  }
  /** Translate a unified snapshot into the preserved Blog model; receive row/snapshot, return native typed article, with immutable compatibility ID and no storage changes. */
  public static function article(array $row,array $snap): array {
    $id=$row['source']['plugin']==='blog'?$row['source']['id']:$row['id'];$doc=$snap['document'];
    $blocks=$snap['blocks']!==null&&!$snap['authored']?$snap['blocks']:(trim($doc['markup']['html']??'')===''?[]:[['type'=>'markup']+$doc['markup']]);
    return ['id'=>$id,'slug'=>$snap['slug'],'aliases'=>$snap['slugAliases'],'title'=>$doc['meta']['title'],'excerpt'=>$doc['meta']['description'],'cover'=>$doc['meta']['image'],'coverAlt'=>$doc['meta']['imageAlt'],'author'=>$snap['authorId'],'categories'=>$snap['groups'],'tags'=>$snap['tags'],'status'=>self::effective($row)===null?'draft':'published','publishedAt'=>$snap['publishedAt'],'createdAt'=>$row['createdAt']??'','updatedAt'=>$row['updatedAt']??'','deletedAt'=>$row['deletedAt']??'','featured'=>$snap['featured'],'allowComments'=>$snap['allowComments'],'noindex'=>$snap['seo']['noindex'],'seoTitle'=>$snap['seo']['title'],'seoDescription'=>$snap['seo']['description'],'blocks'=>$blocks];
  }
  /** Overlay adopted/new article snapshots onto a raw Blog catalog; receive validated raw data, return compatible public/private projection, never write it back to the source. */
  public function blogCatalog(array $catalog): array {
    $data=$this->read()['data'];foreach(['groups'=>'categories','tags'=>'tags','authors'=>'authors'] as $ours=>$native)$catalog[$native]=array_replace($catalog[$native],$data[$ours]);
    foreach($data['records'] as $row){if($row['source']['plugin']==='blog')unset($catalog['articles'][$row['source']['id']]);$live=self::effective($row);$snap=$live??$row['draft'];if($data['types'][$snap['type']]['kind']!=='article')continue;$article=self::article($row,$snap);if($snap['type']!=='article'){$article['slug']=$row['id'];$article['aliases']=[];}$catalog['articles'][$article['id']]=$article;}
    return $catalog;
  }
  /** Return current native Page metadata when unified authority or route configuration applies; receive native ID/site/preview, return metadata or null without exposing draft fields publicly. */
  public function metadata(string $key,array $settings,bool $preview=false): ?array {
    $data=$this->read()['data'];$rows=$data['records'];if($data['types']['page']['routeMode']!=='default')$rows=$this->records($data);
    foreach($rows as $row){if(!(($row['source']['plugin']==='pages'&&$row['source']['id']===$key)||$row['id']===$key))continue;$snap=$preview?$row['draft']:self::effective($row);if($snap===null)return null;$seo=$snap['seo'];$meta=$snap['document']['meta'];
      return ['title'=>$seo['title']?:$meta['title'],'description'=>$seo['description']?:$meta['description'],'canonical'=>$seo['canonical']?:$this->url($row,rtrim($settings['canonical'],'/'),$snap,$data),'robots'=>$preview?'noindex, nofollow':($seo['noindex']?'noindex, follow':'index, follow'),'socialTitle'=>$seo['socialTitle']?:($seo['title']?:$meta['title']),'socialDescription'=>$seo['socialDescription']?:($seo['description']?:$meta['description']),'image'=>$seo['socialImage']?:($meta['image']?:$settings['image']),'imageAlt'=>$seo['socialImage']!==''?$seo['socialImageAlt']:($meta['image']!==''?$meta['imageAlt']:$settings['imageAlt'])];
    }return null;
  }
  /** Export only authorized own snapshots to a new package; receive trusted target, write sanitized catalog, omit drafts/trash/history and never copy original source files or comments. */
  public function export(string $target): void {
    $data=$this->read()['data'];$data['records']=array_filter($data['records'],static fn(array $row):bool=>$row['deletedAt']===null&&($row['published']!==null||$row['scheduled']!==null));
    foreach($data['records'] as &$row){foreach(['published','scheduled'] as $which)if($row[$which]!==null&&$row[$which]['authored'])$row[$which]['blocks']=null;$row['draft']=$row['scheduled']??$row['published'];}unset($row);if($data['records']===[]&&$data['types']===PostTypes::defaults()&&$data['groups']===[]&&$data['tags']===[]&&$data['authors']===[]&&$data['searchPath']==='search'&&$data['routeAliases']===[])return;
    ExtensionDocument::atomic($target.'/system/storage/contents/posts/catalog.json',ExtensionDocument::encode($data,8388608));
  }
  /** Guard obsolete native writers after explicit adoption; receive native plugin/ID, throw localized conflict when Posts owns the identity, no writes. */
  public function guard(string $plugin,string $id): void {
    foreach($this->read()['data']['records'] as $row)if(($row['source']['plugin']===$plugin&&$row['source']['id']===$id)||$row['id']===$id)PostTypes::fail('managed',409);
  }
  /** Validate the current route configuration before activation; no input/output or writes, collision errors propagate. */
  public function validateRoutes(): void {$this->relationships($this->read()['data']);}
  /** Commit one explicit lifecycle/configuration/term operation with own/site/legacy CAS; receive action/ID/input/revisions, return ID/revision or 409/422/503, preserving original files. */
  public function change(string $action,string $id,array $input,string $revision,string $siteRevision,string $sourceRevision): array {
    return $this->store->transaction(function()use($action,&$id,$input,$revision,$siteRevision,$sourceRevision):array{
      if(!hash_equals($this->store->read('site')['revision'],$siteRevision)||!hash_equals($this->sourceRevision(),$sourceRevision))PostTypes::fail('conflict',409);
      $result=$this->repository()->change($revision,function(array $data)use($action,&$id,$input):array{
        $old=$data;$rows=$this->records($data);$now=gmdate('Y-m-d\TH:i:s\Z');
        if($action==='type-save'){$type=$input['type'];$id=$type['id'];$data['types'][$id]=$type;if(isset($old['types'][$id])){$prior=PostTypes::prefix($old['types'][$id]);$after=PostTypes::prefix($type);if($prior!==null&&$prior!==''&&$prior!==$after)$data['routeAliases'][$prior]=['kind'=>'archive','type'=>$id];if($after!==null)unset($data['routeAliases'][$after]);}if(isset($old['types'][$id])&&$old['types'][$id]['kind']!==$type['kind'])foreach($rows as $row)foreach(['draft','published','scheduled'] as $which)if(($row[$which]['type']??'')===$id)PostTypes::fail('inUse',409);PostTypes::validate($data['types']);foreach($rows as $key=>$row){$live=self::effective($row);if($live!==null){$before=PostTypes::route($old['types'],$live);$after=PostTypes::route($data['types'],$live);if($before!==$after&&!str_contains($before,'?')){$row['aliases']=array_values(array_unique([...$row['aliases'],$before]));$data['records'][$key]=$row;}}}}
        elseif($action==='type-remove'){if(in_array($id,['page','article'],true)||!isset($data['types'][$id]))PostTypes::fail('inUse',409);foreach($rows as $row)foreach(['draft','published','scheduled'] as $which)if(($row[$which]['type']??'')===$id)PostTypes::fail('inUse',409);foreach($data['routeAliases'] as $path=>$target)if($target['kind']==='archive'&&$target['type']===$id)unset($data['routeAliases'][$path]);unset($data['types'][$id]);}
        elseif($action==='settings'){$next=PostTypes::path($input['searchPath']);if($next!==$data['searchPath']&&($data['records']!==[]||(new Plugins($this->store))->state('posts')['enabled']))$data['routeAliases'][$data['searchPath']]=['kind'=>'search','type'=>''];unset($data['routeAliases'][$next]);$data['searchPath']=$next;}
        elseif(in_array($action,['term-save','term-remove'],true)){$kind=$input['kind'];if(!in_array($kind,['groups','tags','authors'],true)||!PostTypes::identifier($id))PostTypes::fail();$terms=$this->terms($data);
          if($action==='term-save'){$entry=$input['term'];if(($input['original']??'')===''&&isset($terms[$kind][$id]))PostTypes::fail('collision',409);$data[$kind][$id]=$entry;}
          else{if(!isset($terms[$kind][$id]))PostTypes::fail('missing',404);foreach($rows as $row)foreach(['draft','published','scheduled'] as $which){$snap=$row[$which];if($snap!==null&&($kind==='authors'?$snap['authorId']===$id:in_array($id,$snap[$kind],true)))PostTypes::fail('inUse',409);}if($kind==='groups')foreach($terms['groups'] as $entry)if($entry['parent']===$id)PostTypes::fail('inUse',409);$data[$kind][$id]=$terms[$kind][$id];$data[$kind][$id]['deleted']=true;}
        }else{
          if($action==='create'){$id='pub-'.bin2hex(random_bytes(12));$row=['id'=>$id,'source'=>['plugin'=>'posts','id'=>$id],'createdAt'=>$now,'updatedAt'=>$now,'deletedAt'=>null,'aliases'=>[],'draft'=>$input['snapshot'],'published'=>null,'scheduled'=>null,'sourceRevision'=>''];}
          else{$row=$rows[$id]??throw new HttpError(404,'Publication unavailable.','posts.error.missing');if($row['deletedAt']!==null&&!in_array($action,['recover','restore'],true))PostTypes::fail('missing',404);}
          if($action==='duplicate'){$id='pub-'.bin2hex(random_bytes(12));$row['id']=$id;$row['source']=['plugin'=>'posts','id'=>$id];$row['draft']['document']['slug']=$id;$row['draft']['slug']=$input['slug'];$row['draft']['publishedAt']='';$row['aliases']=[];$row['published']=$row['scheduled']=null;$row['createdAt']=$now;$row['deletedAt']=null;}
          elseif(in_array($action,['create','save'],true)){$row['draft']=$input['snapshot'];$row['draft']['document']['slug']=$row['source']['id'];$row['draft']['document']['status']='draft';$row['draft']['publishedAt']='';}
          elseif(in_array($action,['publish','schedule'],true)){$snap=$row['draft'];$snap['review']=false;$row['draft']['review']=false;$type=$data['types'][$snap['type']];$terms=$this->terms($data);foreach(['groups','tags'] as $kind)foreach($snap[$kind] as $term)if($terms[$kind][$term]['deleted'])PostTypes::fail('term');
            if($type['kind']==='article'&&(($snap['authored']&&trim($snap['document']['markup']['html']??'')==='')||(!$snap['authored']&&$snap['blocks']===[])))PostTypes::fail();
            if($type['kind']==='article'&&($snap['authorId']===''||($terms['authors'][$snap['authorId']]['deleted']??true)))PostTypes::fail('author');
            (new Components(new ContentContext($this->store,$this->store->read('site')['data']['template'],'',$row['source']['id'])))->validateBindings($snap['document']['markup']['bindings']??[]);
            $date=$input['publishAt']??$now;PagePublications::date($date);if($action==='schedule'&&strtotime($date)<=time())PostTypes::fail('date');$snap['publishedAt']=$date;if($snap['authored'])$snap['blocks']=null;
            $previous=self::effective($row);if($previous!==null){$row['published']=$previous;$path=PostTypes::route($data['types'],$previous);if($path!==PostTypes::route($data['types'],$snap)&&!str_contains($path,'?'))$row['aliases']=array_values(array_unique([...$row['aliases'],$path]));if($previous['slug']!==$snap['slug'])$snap['slugAliases']=array_values(array_unique([...$previous['slugAliases'],$previous['slug']]));}
            if(strtotime($date)>time())$row['scheduled']=$snap;else{$row['published']=$snap;$row['scheduled']=null;}
          }
          elseif($action==='unpublish')$row['published']=$row['scheduled']=null;
          elseif($action==='trash')$row['deletedAt']=$now;
          elseif($action==='recover'){$row['deletedAt']=null;$row['published']=$row['scheduled']=null;}
          elseif($action==='restore-native'){$snapshot=$this->nativeHistory($row)[$input['backup']]??null;if($snapshot===null)PostTypes::fail('missing',404);if($row['deletedAt']!==null)$row['published']=$row['scheduled']=null;$snapshot['type']=$row['draft']['type'];$snapshot['publishedAt']='';$row['draft']=$snapshot;$row['deletedAt']=null;}
          elseif($action==='restore-source'){$original=$data;unset($original['records'][$id]);$source=$this->records($original)[$id]??null;if($source===null||$row['source']['plugin']==='posts')PostTypes::fail('missing',404);if($row['deletedAt']!==null)$row['published']=$row['scheduled']=null;$row['draft']=$source['draft'];$row['draft']['publishedAt']='';$row['deletedAt']=null;}
          elseif($action==='restore'){$history=$this->repository()->snapshot($input['backup']);$saved=$history['records'][$id]??null;if($saved===null)PostTypes::fail('missing',404);if($row['deletedAt']!==null)$row['published']=$row['scheduled']=null;$row['draft']=$saved['draft'];$row['draft']['publishedAt']='';$row['deletedAt']=null;}
          else PostTypes::fail('operation',404);
          $row['updatedAt']=$now;foreach(['draft','published','scheduled'] as $which)if($row[$which]!==null)$this->validateDocument($row[$which],$row['source']['id'],$data['types']);$data['records'][$id]=$row;
        }
        $this->validate($data);$this->relationships($data);return $data;
      });return ['id'=>$id,'revision'=>$result['revision']];
    });
  }
}
