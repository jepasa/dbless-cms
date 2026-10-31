<?php
/** Blog API 1 adapter: authenticated editorial operations and a separate declared public surface; business logic remains inside the plugin. */
declare(strict_types=1);
namespace DBLESSCMS;
require_once __DIR__.'/Model.php';
require_once __DIR__.'/Store.php';
require_once __DIR__.'/Router.php';
require_once __DIR__.'/View.php';
final class BlogPlugin implements PluginInterface,PublicPluginInterface,DashboardProviderInterface {
  /** Supply actual published article and pending comment counts to the authenticated core dashboard; receive private context, return dictionary keys/integer values, no writes or comment bodies. */
  public function dashboard(PluginContext $context): array {
    $catalog=(new BlogStore($context->store))->read()['data'];$comments=(new BlogStore($context->store,'comments'))->read()['data'];
    $published=0;$pending=0;
    foreach ($catalog['articles'] as $article) if (BlogModel::visible($article)) $published++;
    foreach ($comments['comments'] as $comment) if ($comment['status']==='pending') $pending++;
    return [['label'=>'blog.dashboard.published','value'=>$published],['label'=>'blog.dashboard.pending','value'=>$pending]];
  }
  /** Validate functional settings without writing; takes configuration/config services and raises a schema error on invalid values. */
  public function validateSettings(array $settings,Config $config): void {
    Schema::validate($settings,json_decode((string)file_get_contents(__DIR__.'/settings.schema.json'),true,16,JSON_THROW_ON_ERROR));
    foreach (['title'=>160,'description'=>500] as $key=>$limit) BlogModel::text($settings['publication'][$key],$limit);
  }
  /** Render a declared private route; takes authorized context, returns escaped HTML and propagates controlled failures. */
  public function render(PluginContext $context): string {return BlogRouter::render($context);}
  /** Handle only the declared public interface; takes minimal context, returns HTML/XML or empty response after redirect, without exposing admin services. */
  public function publicPage(PublicPluginContext $context): string {return BlogView::publicPage($context);}
  /** Extract a bounded POST string; takes name/limit/default, returns text or raises 422 for arrays, invalid UTF-8 or excess bytes. */
  private function input(string $key,int $limit=200,string $default=''): string {
    $value=$_POST[$key]??$default;BlogModel::expect(is_string($value)&&strlen($value)<=$limit&&preg_match('//u',$value)===1);return $value;
  }
  /** Extract a bounded list of opaque POST references; takes name, returns list or raises 422, without changing POST. */
  private function list(string $key): array {
    $value=$_POST[$key]??[];BlogModel::expect(is_array($value)&&array_is_list($value)&&count($value)<=20);
    foreach ($value as $id) BlogModel::expect(BlogModel::identifier($id));return $value;
  }
  /** Normalize a UTC form time; takes submitted string, returns exact UTC seconds or empty, raises 422 on unsupported representations. */
  private function publicationTime(string $value): string {
    if ($value==='') return '';
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/D',$value)) $value.=(strlen($value)===16?':00':'').'Z';
    return BlogModel::date($value);
  }
  /** Assemble a changed article while preserving immutable identity/history; takes catalog/id, returns article, raises contract/slug errors before mutation. */
  private function article(array $data,string $id): array {
    $existing=$id===''?null:($data['articles'][$id]??throw new HttpError(404,'Article unavailable.','blog.error.missing'));
    if ($existing!==null&&$existing['deletedAt']!=='') throw new HttpError(404,'Article unavailable.','blog.error.missing');
    $slug=$this->input('slug',64);BlogModel::expect(BlogModel::identifier($slug));
    $aliases=$existing['aliases']??[];
    if ($existing!==null&&$slug!==$existing['slug']) {$aliases=array_values(array_diff($aliases,[$slug]));$aliases[]=$existing['slug'];}
    try {$blocks=json_decode($this->input('blocks',65536,'[]'),true,16,JSON_THROW_ON_ERROR);}catch (\JsonException) {throw new HttpError(422,'Invalid blocks.','blog.error.contract');}
    BlogModel::blocks($blocks);$now=gmdate('Y-m-d\TH:i:s\Z');$status=$this->input('status',20,'draft');
    $date=$this->publicationTime($this->input('publishedAt',30));if ($status==='published'&&$date==='') $date=$now;
    return ['id'=>$existing['id']??'post-'.bin2hex(random_bytes(8)),'slug'=>$slug,'aliases'=>$aliases,'title'=>$this->input('title'),'excerpt'=>$this->input('excerpt',1000),'cover'=>$this->input('cover',512),'coverAlt'=>$this->input('coverAlt',300),'author'=>$this->input('author',64),'categories'=>$this->list('categories'),'tags'=>$this->list('tags'),'status'=>$status,'publishedAt'=>$date,'createdAt'=>$existing['createdAt']??$now,'updatedAt'=>$now,'deletedAt'=>'','featured'=>$this->input('featured',1)==='1','noindex'=>$this->input('noindex',1)==='1','seoTitle'=>$this->input('seoTitle'),'seoDescription'=>$this->input('seoDescription',500),'blocks'=>$blocks];
  }
  /** Apply a taxonomy/byline operation; takes catalog and save/remove action, returns changed catalog, rejecting deletion while referenced or a deleted parent. */
  private function taxonomy(array $data,string $operation): array {
    $kind=$this->input('kind',20);BlogModel::expect(in_array($kind,['categories','tags','authors'],true));$id=$this->input('id',64);BlogModel::expect(BlogModel::identifier($id));
    if ($operation==='taxonomy-remove') {
      if (!isset($data[$kind][$id])) throw new HttpError(404,'Entry unavailable.','blog.error.missing');
      foreach ($data['articles'] as $article) if (($kind==='authors'&&$article['author']===$id)||($kind!=='authors'&&in_array($id,$article[$kind],true))) throw new HttpError(409,'Entry is in use.','blog.error.inUse');
      if ($kind==='categories') foreach ($data['categories'] as $category) if ($category['parent']===$id) throw new HttpError(409,'Entry is in use.','blog.error.inUse');
      $data[$kind][$id]['deleted']=true;return $data;
    }
    $original=$this->input('original',64);
    if (($original===''&&isset($data[$kind][$id]))||($original!==''&&($original!==$id||!isset($data[$kind][$id])))) throw new HttpError(409,'Identifier already in use.','blog.error.slug');
    $name=$this->input('name',160);
    if ($kind==='authors') $entry=['id'=>$id,'name'=>$name,'bio'=>$this->input('bio',2000),'image'=>$this->input('image',512),'url'=>$this->input('url',2048),'deleted'=>false];
    else {
      $parent=$kind==='categories'?$this->input('parent',64):'';
      BlogModel::expect($parent===''||(isset($data['categories'][$parent])&&!$data['categories'][$parent]['deleted']));
      $entry=['id'=>$id,'name'=>$name,'description'=>$this->input('description',1000),'parent'=>$parent,'deleted'=>false];
    }
    $data[$kind][$id]=$entry;return $data;
  }
  /** Dispatch POST operations only on their declared private routes; takes context/operation, returns new revision/local redirect or raises 403/404/409/422. */
  public function api(PluginContext $context,string $operation): ?array {
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') throw new HttpError(405,'Method not allowed.','error.method');
    $routes=['save'=>'editor','duplicate'=>'overview','trash'=>'overview','recover'=>'trash','taxonomy-save'=>'taxonomies','taxonomy-remove'=>'taxonomies','moderate'=>'comments','settings-save'=>'settings','history-restore'=>'history','options-restore'=>'history'];
    if (!isset($routes[$operation])||$routes[$operation]!==$context->route) throw new HttpError(404,'Operation unavailable.','error.not_found');
    $revision=$this->input('revision',128);$registry=new Plugins($context->store);
    if ($operation==='settings-save'||$operation==='options-restore') {
      if ($operation==='settings-save') $registry->saveOptions('blog',PluginOptions::input($_POST,[]),$revision);
      else $registry->restore('blog',$this->input('backup',80),$revision,true);
      return ['revision'=>$context->store->read('plugins')['revision'],'redirect'=>BlogRouter::url($context,$context->route)];
    }
    if (!$registry->state('blog')['enabled']) throw new HttpError(404,'Blog is disabled.','error.plugin');
    if ($operation==='history-restore') {
      $kind=$this->input('documentKind',16,'catalog');BlogModel::expect(in_array($kind,['catalog','comments'],true));
      $result=(new BlogStore($context->store,$kind))->restore($this->input('backup',80),$revision);
      return ['revision'=>$result['revision'],'redirect'=>BlogRouter::url($context,'history')];
    }
    if ($operation==='moderate') {
      $result=(new BlogStore($context->store,'comments'))->mutate($revision,function(array $data): array {
        $id=$this->input('id',64);$status=$this->input('status',20);
        BlogModel::expect(in_array($status,['pending','approved','spam','deleted'],true));
        if (!isset($data['comments'][$id])) throw new HttpError(404,'Comment unavailable.','blog.error.missing');$data['comments'][$id]['status']=$status;return $data;
      });
      return ['revision'=>$result['revision'],'redirect'=>BlogRouter::url($context,'comments')];
    }
    $id=$this->input('id',64);$result=(new BlogStore($context->store))->mutate($revision,function(array $data) use($operation,&$id): array {
      if (str_starts_with($operation,'taxonomy-')) return $this->taxonomy($data,$operation);
      if ($operation==='save') {$article=$this->article($data,$id);$id=$article['id'];$data['articles'][$id]=$article;return $data;}
      if (!isset($data['articles'][$id])) throw new HttpError(404,'Article unavailable.','blog.error.missing');
      if ($operation==='duplicate') {
        $article=$data['articles'][$id];$id='post-'.bin2hex(random_bytes(8));$article['id']=$id;
        $article['slug']=substr($article['slug'],0,42).'-copy-'.bin2hex(random_bytes(4));$article['aliases']=[];$article['status']='draft';$article['publishedAt']='';$article['deletedAt']='';$article['featured']=false;$article['createdAt']=$article['updatedAt']=gmdate('Y-m-d\TH:i:s\Z');
        $data['articles'][$id]=$article;
      } else {$data['articles'][$id]['deletedAt']=$operation==='trash'?gmdate('Y-m-d\TH:i:s\Z'):'';$data['articles'][$id]['updatedAt']=gmdate('Y-m-d\TH:i:s\Z');}
      return $data;
    });
    $route=in_array($operation,['save','duplicate'],true)?'editor':$context->route;
    $query=in_array($operation,['save','duplicate'],true)?['id'=>$id]:(str_starts_with($operation,'taxonomy-')?['kind'=>$this->input('kind',20)]:[]);
    return ['revision'=>$result['revision'],'redirect'=>BlogRouter::url($context,$route,$query)];
  }
}
