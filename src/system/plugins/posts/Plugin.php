<?php
/** Posts API 1: unified editorial commands and published-only data/document adapters; all persistence and route policies belong to the shared repository. */
declare(strict_types=1);
/** Trusted Posts implementation and retained internal contracts.
 * @package DBLESSCMS
 */
namespace DBLESSCMS;
require_once __DIR__.'/Router.php';
require_once __DIR__.'/compatibility/blog/View.php';
/** Expose the consolidated editorial owner through API 1, public routes and published-data contracts. */
final class PostsPlugin implements PluginInterface,PluginCompatibilityInterface,PublicPluginInterface,ContentProviderInterface,ContentReferenceInterface,ContentDocumentInterface,DashboardProviderInterface {
  /** Resolve a retired declared identifier inside this owner; receive alias, return trusted adapter or 404 without installing or activating packages. */
  public function compatibility(string $id): PluginInterface {
    if($id==='pages'){require_once __DIR__.'/compatibility/pages/Adapter.php';return new PostsPagesAdapter();}
    if($id==='blog'){require_once __DIR__.'/compatibility/blog/Adapter.php';return new PostsArticleAdapter();}
    throw new HttpError(404,'Compatibility identifier unavailable.','error.not_found');
  }
  /** Validate typed plugin options and proposed route activation; receive settings/config, throw on invalid schema/collisions without writes. */
  public function validateSettings(array $settings,Config $config): void {if(!in_array($settings['browser']['pageSize']??25,[25,50,100],true))PostTypes::fail();Schema::validate($settings,json_decode((string)file_get_contents(__DIR__.'/settings.schema.json'),true,16,JSON_THROW_ON_ERROR));$this->compatibility('blog')->validateSettings(array_intersect_key($settings,['publication'=>true,'discussion'=>true]),$config);$this->compatibility('pages')->validateSettings(array_intersect_key($settings,['browser'=>true,'creation'=>true]),$config);(new Posts(new JsonStore($config)))->validateRoutes();}
  /** Render one manifest-declared private page; receive trusted context, return fragment or authenticated preview, propagate failures. */
  public function render(PluginContext $context): string {return PostsRouter::render($context);}
  /** Extract bounded submitted text; receive payload/key/limit/default, return string or localized 422 without writes. */
  private static function input(array $post,string $key,int $limit=128,string $default=''): string {$value=$post[$key]??$default;if(!is_string($value)||strlen($value)>$limit||preg_match('//u',$value)!==1||str_contains($value,"\0"))PostTypes::fail();return $value;}
  /** Read a bounded ID array without silently dropping malformed input; receive payload/key/default, return unique IDs or 422. */
  private static function ids(array $post,string $key,array $default=[]): array {
    $value=$post[$key]??$default;if(!is_array($value)||!array_is_list($value)||count($value)>20||count(array_unique($value,SORT_REGULAR))!==count($value))PostTypes::fail();foreach($value as $id)if(!PostTypes::identifier($id))PostTypes::fail();return $value;
  }
  /** Create/update one canonical working snapshot; receive trusted context/repository/optional row, return independently validated source with preserved native data, never publish. */
  private function snapshot(PluginContext $context,Posts $repository,?array $row): array {
    $post=$_POST;$data=$repository->read()['data'];$typeId=self::input($post,'type',64,'page');$type=$data['types'][$typeId]??throw new HttpError(422,'Unknown type.','posts.error.invalid');
    if($row!==null&&$data['types'][$row['draft']['type']]['kind']!==$type['kind'])PostTypes::fail();
    if($row===null){$doc=PageBlueprints::create('blank',self::input($post,'title',200),self::input($post,'slug',64))['document'];$snap=['type'=>$typeId,'slug'=>self::input($post,'slug',64),'document'=>$doc,'seo'=>PagePublications::seo(),'author'=>'','authorId'=>'','order'=>0,'parent'=>'','groups'=>[],'tags'=>[],'featured'=>false,'allowComments'=>false,'publishedAt'=>'','blocks'=>null,'authored'=>true,'slugAliases'=>[],'review'=>false];}
    else $snap=$row['draft'];
    $snap['type']=$typeId;$snap['slug']=self::input($post,'slug',64);$snap['document']['meta']['title']=self::input($post,'title',200);$snap['document']['meta']['description']=self::input($post,'summary',1000);if($snap['document']['meta']['description']==='')$snap['document']['meta']['description']=$snap['document']['meta']['title'];
    foreach(['image'=>2048,'imageAlt'=>500] as $key=>$limit)$snap['document']['meta'][$key]=self::input($post,$key,$limit);
    $before=$snap['blocks']!==null&&!$snap['authored']?PostsMarkup::blocks($snap['blocks']):PageEditorDocument::open($snap['document']);
    $snap['document']=PageEditorDocument::apply($snap['document'],self::input($post,'editor_document',524288));if($snap['document']['markup']['html']!==$before['html']||$snap['document']['markup']['bindings']!==$before['bindings'])$snap['authored']=true;
    $snap['review']=self::input($post,'review',1)==='1';$snap['author']=self::input($post,'author',200);$snap['authorId']=self::input($post,'authorId',64);
    $order=self::input($post,'order',7,(string)$snap['order']);if(!preg_match('/^-?(?:0|[1-9][0-9]{0,5})$/D',$order))PostTypes::fail();$snap['order']=(int)$order;$snap['parent']=self::input($post,'parent',64);
    foreach(['groups','tags'] as $key){$value=self::ids($post,$key,$type[$key]?[]:$snap[$key]);if(!$type[$key]&&$value!==$snap[$key])PostTypes::fail();$snap[$key]=$value;}
    $snap['featured']=self::input($post,'featured',1)==='1';$snap['allowComments']=$type['comments']&&self::input($post,'allowComments',1)==='1';
    foreach(PagePublications::seo() as $key=>$default)$snap['seo'][$key]=$key==='noindex'?self::input($post,'seo_noindex',1)==='1':self::input($post,'seo_'.$key,2048);
    $layout=self::input($post,'layout',64,$snap['document']['page']);if(in_array($layout,['home','404'],true))PostTypes::fail();$snap['document']['page']=$layout;return $snap;
  }
  /** Dispatch declared private POST operations after Core session/CSRF checks; receive context/operation, return server-confirmed local navigation, preserving buffers on errors. */
  public function api(PluginContext $context,string $operation): ?array {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')throw new HttpError(405,'Method unavailable.','error.method');
    if($operation==='options-save'&&$context->route==='settings'){$registry=new Plugins($context->store);$settings=PluginOptions::input($_POST,[]);$settings['creation']=$context->settings['creation']??[];$registry->saveOptions('posts',$settings,self::input($_POST,'revision'));return ['redirect'=>PostsRouter::url($context,'settings')];}
    if($operation==='options-restore'&&$context->route==='history'){$registry=new Plugins($context->store);$registry->restore('posts',self::input($_POST,'backup',80),self::input($_POST,'revision'),true);return ['redirect'=>PostsRouter::url($context,'history')];}
    if($operation==='comments-restore'&&$context->route==='history'){$registry=new Plugins($context->store);if(!$registry->state('posts')['enabled'])throw new HttpError(404,'Plugin unavailable.','error.plugin');(new BlogStore($context->store,'comments'))->restore(self::input($_POST,'backup',80),self::input($_POST,'revision'));return ['redirect'=>PostsRouter::url($context,'history')];}
    if($operation==='moderate'&&$context->route==='comments')return $this->compatibility('blog')->api($context,$operation);
    $routes=['create'=>['new'],'save'=>['editor'],'publish'=>['editor'],'schedule'=>['editor'],'unpublish'=>['editor'],'trash'=>['overview','editor'],'recover'=>['trash'],'duplicate'=>['editor'],'restore'=>['history'],'restore-native'=>['history'],'restore-source'=>['history'],'type-save'=>['settings'],'type-remove'=>['settings'],'settings'=>['settings'],'term-save'=>['groups','tags','authors'],'term-remove'=>['groups','tags','authors']];
    if(!in_array($context->route,$routes[$operation]??[],true))PostTypes::fail('operation',404);
    if($operation!=='settings'&&!(new Plugins($context->store))->state('posts')['enabled'])throw new HttpError(404,'Plugin unavailable.','error.plugin');
    $repository=new Posts($context->store);$id=self::input($_POST,'id',64);$input=[];
    if(in_array($operation,['create','save'],true))$input['snapshot']=$this->snapshot($context,$repository,$operation==='create'?null:$repository->record($id));
    elseif(in_array($operation,['publish','schedule'],true)){$date=self::input($_POST,'publishAt',30);if($date!=='')$input['publishAt']=strlen($date)===16?$date.':00Z':(strlen($date)===19?$date.'Z':$date);}
    elseif($operation==='duplicate')$input['slug']=self::input($_POST,'slug',64);
    elseif(in_array($operation,['restore','restore-native'],true))$input['backup']=self::input($_POST,'backup',80);
    elseif($operation==='settings')$input['searchPath']=self::input($_POST,'searchPath',128);
    elseif($operation==='type-save'){$type=['id'=>self::input($_POST,'typeId',64),'singular'=>self::input($_POST,'singular',100),'plural'=>self::input($_POST,'plural',100),'kind'=>self::input($_POST,'kind',16),'routeMode'=>self::input($_POST,'routeMode',16),'prefix'=>self::input($_POST,'prefix',128)];foreach(['groups','tags','comments','hierarchical'] as $key)$type[$key]=self::input($_POST,'supports_'.$key,1)==='1';$input['type']=$type;}
    elseif(in_array($operation,['term-save','term-remove'],true)){$kind=$context->route;$input['kind']=$kind;if($operation==='term-save'){$id=self::input($_POST,'termId',64);$input['original']=self::input($_POST,'original',64);if($input['original']!==''&&$input['original']!==$id)PostTypes::fail();$entry=['id'=>$id,'name'=>self::input($_POST,'name',160)];if($kind==='authors')$entry+=['bio'=>self::input($_POST,'bio',2000),'image'=>self::input($_POST,'profileImage',512),'url'=>self::input($_POST,'profileUrl',2048),'deleted'=>false];else $entry+=['description'=>self::input($_POST,'description',1000),'parent'=>$kind==='groups'?self::input($_POST,'parent',64):'','deleted'=>false];$input['term']=$entry;}}
    $result=$repository->change($operation,$id,$input,self::input($_POST,'revision'),self::input($_POST,'site_revision'),self::input($_POST,'source_revision'));
    if(in_array($operation,['create','duplicate'],true))Access::claim($context->config,$result['id']);
    $target=in_array($operation,['type-save','type-remove','settings'],true)?'settings':(str_starts_with($operation,'term-')?$context->route:($operation==='trash'?'trash':'editor'));
    return ['revision'=>$result['revision'],'redirect'=>PostsRouter::url($context,$target,in_array($target,['settings','groups','tags','authors','trash'],true)?[]:['id'=>$result['id']])];
  }
  /** Render a declared public read-only endpoint; receive minimal public context, return public HTML/XML without credentials, mutations or admin dictionaries. */
  public function publicPage(PublicPluginContext $context): string {if(in_array($context->route,['list','article','comment'],true))return $this->compatibility('blog')->publicPage($context);if($context->method!=='GET')throw new HttpError(405,'Method unavailable.','error.method');$store=new JsonStore(new Config());return $context->route==='search'?PostsView::listing($store,$context->base):PostsView::xml($store,$context->route);}
  /** Project declared data service only; receive service/typed parameters/context, return released finite fields without drafts/comments/history or writes. */
  public function content(string $service,array $parameters,ContentContext $context): array {if($service!=='records')PostTypes::fail('operation',404);$parameters['page']=(string)($parameters['page']??1);$result=(new Posts($context->store))->search($parameters,$context->base);unset($result['query']);return $result;}
  /** Resolve one public identity/slug or named finite query; receive selector/parameters/context, return public literal data or null, no provider recursion. */
  public function reference(string $selector,array $parameters,ContentContext $context): ?array {
    if($selector==='latest')return $this->content('records',$parameters,$context);$repository=new Posts($context->store);$mode=str_contains($selector,':')?explode(':',$selector,2):['',$selector];$found=[];
    foreach($repository->records() as $row){$snap=Posts::effective($row);if($snap===null||!$repository->sourceEnabled($row))continue;if(($mode[0]!=='slug'&&$row['id']===$mode[1])||($mode[0]!=='id'&&$snap['slug']===$mode[1]))$found[]=$repository->projection($row,$snap,$context->base)+['content'=>$snap['document']['content'],'body'=>$snap['document']['markup']['html']??''];}
    return count($found)===1?$found[0]:null;
  }
  /** List released document identities; receive context, return at most 100 plain options without private data or writes. */
  public function documents(ContentContext $context): array {$items=[];$repository=new Posts($context->store);foreach($repository->records() as $row){$snap=Posts::effective($row);if($snap!==null&&$repository->sourceEnabled($row))$items[]=['id'=>$row['id'],'name'=>$snap['document']['meta']['title']];if(count($items)>=100)break;}return $items;}
  /** Return one safe released body; receive immutable ID/context, return shared markup projection or null without frames/metadata/private content. */
  public function document(string $id,ContentContext $context): ?array {
    $repository=new Posts($context->store);try{$row=$repository->record($id);}catch(HttpError $error){if($error->status===404)return null;throw $error;}$snap=Posts::effective($row);if($snap===null||!$repository->sourceEnabled($row))return null;
    $doc=$snap['document'];if($snap['blocks']!==null&&!$snap['authored'])$doc['markup']=PostsMarkup::blocks($snap['blocks']);return ['name'=>$doc['meta']['title'],'description'=>$doc['meta']['description'],'html'=>ContentDocuments::page($doc,$context)];
  }
  /** Supply real grouped metrics; receive private context, return dictionary keys/counts only without writes. */
  public function dashboard(PluginContext $context): array {$published=$drafts=$pending=0;foreach((new Posts($context->store))->records() as $row){if($row['deletedAt']!==null)continue;if(Posts::effective($row)!==null)$published++;else $drafts++;}foreach((new BlogStore($context->store,'comments'))->read()['data']['comments'] as $comment)if($comment['status']==='pending')$pending++;return [['label'=>'posts.state.published','value'=>$published],['label'=>'posts.state.draft','value'=>$drafts],['label'=>'blog.dashboard.pending','value'=>$pending]];}
}
