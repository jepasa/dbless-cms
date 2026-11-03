<?php
/** Plugin Pages: domínio editorial reutiliza JsonStore, com rotas próprias e adaptador dos endereços administrativos anteriores. */
declare(strict_types=1);
namespace DBLESSCMS;
require_once __DIR__.'/Router.php';
final class PagesPlugin implements PluginInterface,ContentProviderInterface,ContentOptionsInterface,ContentDocumentInterface {
  /** Return no dynamic choices for the public list query; receive service/context, return empty metadata without writes. */
  public function contentOptions(string $service,ContentContext $context): array {if(!in_array($service,['details','children','breadcrumbs'],true))return [];$choices=[];foreach($context->store->pages() as $id=>$snapshot)if(PagePublications::identifier((string)$id)&&$snapshot['data']['status']==='published'&&count($choices)<100)$choices[]=['value'=>(string)$id,'label'=>$snapshot['data']['meta']['title']];return ['id'=>$choices];}
  /** Project only published ordinary pages in stable slug order; receive service/typed parameters/context, return public cards, never include home/404/drafts or metadata secrets. */
  public function content(string $service,array $parameters,ContentContext $context): array {
    if(in_array($service,['records','details','children','breadcrumbs'],true))return $this->records($service,$parameters,$context);
    if($service!=='list')throw new HttpError(404,'Unknown service.','components.error');$items=[];foreach($context->store->pages() as $id=>$snapshot){$page=$snapshot['data'];if(in_array((string)$id,['home','404'],true)||$page['status']!=='published')continue;$items[]=['title'=>$page['meta']['title'],'text'=>$page['meta']['description'],'href'=>(new PagePublications($context->store))->url((string)$id,$context->base),'image'=>$page['meta']['image']===''?'':(preg_match('~^https?://~i',$page['meta']['image'])?$page['meta']['image']:$context->base.'/'.ltrim($page['meta']['image'],'/'))];if(count($items)>=$parameters['limit'])break;}return ['items'=>$items];
  }
  /** Return bounded public editorial projections, never HTML or private working snapshots; receive registered service/typed parameters/context, return paginated data and propagate invalid contracts. */
  private function records(string $service,array $parameters,ContentContext $context): array {
    $repository=new PagePublications($context->store);$catalog=$repository->read()['data']['records'];$items=[];
    foreach($context->store->pages() as $id=>$snapshot){$page=$snapshot['data'];if(!PagePublications::identifier((string)$id)||$page['status']!=='published')continue;$row=$catalog[$id]??null;$live=$row===null?null:PagePublications::effective($row);$image=$page['meta']['image'];$items[(string)$id]=['id'=>(string)$id,'title'=>$page['meta']['title'],'summary'=>$page['meta']['description'],'url'=>$repository->url((string)$id,$context->base),'image'=>['url'=>$image===''?'':(preg_match('~^https?://~i',$image)?$image:$context->base.'/'.ltrim($image,'/')),'alt'=>$page['meta']['imageAlt']],'parentId'=>$live['parent']??'','createdAt'=>$row['createdAt']??'','updatedAt'=>$live['updatedAt']??'','isCurrent'=>(string)$id===$context->page];}
    if($service==='details')$items=isset($items[$parameters['id']])?[$items[$parameters['id']]]:[];
    elseif($service==='children')$items=array_filter($items,static fn(array $item):bool=>$item['parentId']===$parameters['id']);
    elseif($service==='breadcrumbs'){$chain=[];$current=$parameters['id'];while(isset($items[$current])&&count($chain)<12){$chain[]=$items[$current];$current=$items[$current]['parentId'];}$items=array_reverse($chain);}
    if(in_array($service,['records','children'],true)){usort($items,static fn(array $a,array $b):int=>(strnatcasecmp($a[$parameters['sort']],$b[$parameters['sort']])?:strcmp($a['id'],$b['id']))*($parameters['direction']==='asc'?1:-1));}
    $total=count($items);$limit=$parameters['limit']??50;$pages=max(1,(int)ceil($total/$limit));$page=min($pages,$parameters['page']??1);return ['items'=>array_values(array_slice($items,($page-1)*$limit,$limit)),'total'=>$total,'page'=>$page,'pages'=>$pages];
  }
  /** List stable published page IDs for reference selection; receive context, return bounded options without home/404 or draft bodies. */
  public function documents(ContentContext $context): array {
    $items=[];foreach($context->store->pages() as $id=>$snapshot)if(!in_array((string)$id,['home','404'],true)&&$snapshot['data']['status']==='published'&&count($items)<100)$items[]=['id'=>(string)$id,'name'=>$snapshot['data']['meta']['title']];return $items;
  }
  /** Read one ordinary published page body through the shared public markup adapter; receive stable ID/context, return projection or null, reject configuration IDs and never render the site frame or home recursively. */
  public function document(string $id,ContentContext $context): ?array {
    if(in_array((string)$id,['home','404','site','plugins','templates'],true)||!ContentDocuments::identifier($id))return null;try{$page=$context->store->read($id)['data'];}catch(HttpError $error){if($error->status===404)return null;throw $error;}if($page['status']!=='published')return null;return ['name'=>$page['meta']['title'],'description'=>$page['meta']['description'],'html'=>ContentDocuments::page($page,new ContentContext($context->store,$context->template,$context->base,$id))];
  }
  /** Valida compatibilidade das opções legadas, sem aplicá-las; recebe settings/config; contrato inválido gera 422 sem escrita. */
  public function validateSettings(array $settings,Config $config): void {
    Schema::validate($settings,json_decode((string)file_get_contents(__DIR__.'/settings.schema.json'),true,8,JSON_THROW_ON_ERROR));
    if(!in_array($settings['browser']['pageSize']??25,[25,50,100],true))throw new HttpError(422,'Invalid pagination.','error.admin_field');
    $model=$settings['creation']['model']??'';
    if ($model!==''&&(!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$model)||in_array($model,['404','site','plugins','templates'],true))) throw new HttpError(422,'Modelo inválido.','error.admin_field');
  }
  /** Renderiza rota administrativa ou prévia autenticada; recebe contexto, retorna documento/fragmento sem escrita editorial. */
  public function render(PluginContext $context): string { return PagesRouter::render($context); }
  /** Extrai campo limitado do payload; recebe payload/nome/limite e retorna string ou 422 sem efeitos. */
  private function input(array $post,string $key,int $limit=128): string {
    $value=$post[$key]??'';if (!is_string($value)||strlen($value)>$limit) throw new HttpError(422,'Campo inválido.','error.admin_field');return $value;
  }
  /** Executa mutação editorial após sessão/CSRF do chamador; recebe contexto/ação/payload, retorna redirecionamento/notice e propaga erros de revisão/contrato. */
  public function change(PluginContext $context,string $operation,array $post): array {
    if (!(new Plugins($context->store))->state('pages')['enabled']) throw new HttpError(404,'Plugin desativado.','error.plugin');
    $key=$this->input($post,'key');$revision=$this->input($post,'revision');$route='editor';$notice='notice.content';
    if (in_array($key,['site','plugins','templates'],true)) throw new HttpError(422,'Documento reservado.','error.reserved');
    switch ($operation) {
      case 'create':$key=$this->input($post,'slug');$context->store->create($key,$this->input($post,'model'));$notice='notice.created';break;
      case 'save':$context->store->save($key,$this->input($post,'document',524288),$revision);break;
      case 'remove':$context->store->remove($key,$revision);$route='history';$notice='notice.removed';break;
      case 'restore':$context->store->restore($key,$this->input($post,'backup'),$revision);$notice='notice.restored';break;
      default:throw new HttpError(404,'Operação inexistente.','error.not_found');
    }
    return ['redirect'=>$context->base.'/cms-admin/?view=plugin-panel&plugin=pages&route='.$route.'&key='.rawurlencode($key),'notice'=>$notice];
  }
  /** Parse one submitted authored page atomically; receive payload, return metadata/status/shared markup, raise 422 on JSON/type/binding contract errors without writes. */
  private function authored(array $post): array {
    try{$bindings=json_decode($this->input($post,'bindings',32768),true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(422,'Invalid bindings.','markup.error');}if(!is_array($bindings))throw new HttpError(422,'Invalid bindings.','markup.error');
    $meta=[];foreach(['title'=>200,'description'=>500,'keywords'=>2048,'image'=>2048,'imageAlt'=>2048] as $field=>$limit)$meta[$field]=$this->input($post,'meta_'.$field,$limit);
    $authored=['meta'=>$meta,'status'=>$this->input($post,'page_status',20)];if($this->input($post,'markup_enabled',1)==='1')$authored['markup']=ContentMarkup::definition(['html'=>$this->input($post,'html',131072),'bindings'=>$bindings]);return $authored;
  }
  /** Execute the Pages working/publication lifecycle; receives trusted context/operation and validates bounded submitted fields, propagating CAS/contract errors without partial publication. */
  private function editorial(PluginContext $context,string $operation): array {
    $action=substr($operation,10);$allowed=['create','save','publish','schedule','unpublish','trash','recover','restore','duplicate'];
    if(!in_array($action,$allowed,true)||!in_array($context->route,['new','editor','overview','history','trash'],true))throw new HttpError(404,'Unknown page operation.','error.not_found');
    $repository=new PagePublications($context->store);$id=$this->input($_POST,'key');$input=['sourceRevision'=>$this->input($_POST,'source_revision')];
    if($action==='create'){$input['model']=$this->input($_POST,'model');$input['snapshot']=PageBlueprints::create($input['model'],$this->input($_POST,'title',200),$this->input($_POST,'slug',64));$input['parent']=$this->input($_POST,'parent',64);}
    elseif($action==='save'){
      $row=$repository->record($id);$document=$row['draft']['document'];$document['meta']=[];
      foreach(['title'=>200,'description'=>500,'image'=>2048,'imageAlt'=>500] as $field=>$limit)$document['meta'][$field]=$this->input($_POST,'meta_'.$field,$limit);
      $document['meta']['keywords']=$row['draft']['document']['meta']['keywords'];if($document['meta']['description']==='')$document['meta']['description']=$document['meta']['title'];
      if($this->input($_POST,'markup_enabled',1)==='1'){$authored=$this->authored($_POST);$document['markup']=$authored['markup'];}
      else{unset($document['markup']);$native=$this->input($_POST,'native_content',131072);if($native!==''){try{$document['content']=json_decode($native,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(422,'Invalid native content.','pages.editorial.invalid');}}}
      $seo=[];foreach(PagePublications::seo() as $field=>$default)$seo[$field]=$field==='noindex'?$this->input($_POST,'seo_noindex',1)==='1':$this->input($_POST,'seo_'.$field,2048);
      $input+=['snapshot'=>['url'=>$this->input($_POST,'slug',64),'document'=>$document,'seo'=>$seo],'parent'=>$this->input($_POST,'parent',64),'model'=>$row['model']];
    }elseif($action==='schedule'){$value=$this->input($_POST,'publish_at',30);$input['publishAt']=strlen($value)===16?$value.':00Z':$value;}
    elseif($action==='restore')$input['backup']=$this->input($_POST,'backup',64);
    elseif($action==='duplicate')$input['url']=$this->input($_POST,'slug',64);
    $result=$repository->change($id,$action,$input,$this->input($_POST,'catalog_revision'),$this->input($_POST,'site_revision'));
    return ['redirect'=>$context->base.'/cms-admin/?view=plugin-panel&plugin=pages&route='.($action==='trash'?'trash':'editor').'&key='.rawurlencode($result['id'])];
  }
  /** Despacha API privada POST com rota compatível; recebe contexto/operação, retorna redirecionamento JSON ou HttpError sem escrita indevida. */
  public function api(PluginContext $context,string $operation): ?array {
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') throw new HttpError(405,'Método inválido.','error.method');
    if($operation==='settings-save'&&$context->route==='settings'){$settings=PluginOptions::input($_POST,[]);if(isset($context->settings['creation']))$settings['creation']=$context->settings['creation'];(new Plugins($context->store))->saveOptions('pages',$settings,$this->input($_POST,'revision'));return ['redirect'=>$context->base.'/cms-admin/?view=plugin-panel&plugin=pages&route=settings'];}
    if(str_starts_with($operation,'editorial-')){if(!(new Plugins($context->store))->state('pages')['enabled'])throw new HttpError(404,'Unavailable page.','error.not_found');return $this->editorial($context,$operation);}
    $routes=['compose-create'=>['new'],'markup-save'=>['editor'],'create'=>['new'],'save'=>['editor'],'remove'=>['overview','editor'],'restore'=>['history']];
    if (!in_array($context->route,$routes[$operation]??[],true)) throw new HttpError(404,'Rota incompatível.','error.not_found');
    if(!(new Plugins($context->store))->state('pages')['enabled'])throw new HttpError(404,'Unavailable page.','error.not_found');
    if($operation==='compose-create'){
      return $context->store->transaction(function()use($context):array{
        if(!hash_equals($context->store->read('site')['revision'],$this->input($_POST,'site_revision')))throw new HttpError(409,'Template changed.','error.conflict');
        $key=$this->input($_POST,'slug');$authored=$this->authored($_POST);$context->store->create($key,$this->input($_POST,'model'),$authored);
        return ['redirect'=>$context->base.'/cms-admin/?view=plugin-panel&plugin=pages&route=editor&key='.rawurlencode($key)];
      });
    }
    if($operation==='markup-save'){
      if($context->route!=='editor'||!(new Plugins($context->store))->state('pages')['enabled'])throw new HttpError(404,'Unavailable page.','error.not_found');$key=$this->input($_POST,'key');if(in_array($key,['site','plugins','templates','home','404'],true))throw new HttpError(422,'Reserved page.','error.reserved');$current=$context->store->read($key);$document=$current['data'];
      return $context->store->transaction(function()use($context,$key,$document):array{
        if(!hash_equals($context->store->read('site')['revision'],$this->input($_POST,'site_revision')))throw new HttpError(409,'Template changed.','error.conflict');
        if($this->input($_POST,'compose',1)==='1'){$authored=$this->authored($_POST);$document=array_replace($document,$authored);if(!isset($authored['markup']))unset($document['markup']);}
        elseif($this->input($_POST,'markup_enabled',1)==='1'){try{$bindings=json_decode($this->input($_POST,'bindings',32768),true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(422,'Invalid bindings JSON.','markup.error');}if(!is_array($bindings))throw new HttpError(422,'Invalid bindings.','markup.error');$document['markup']=ContentMarkup::definition(['html'=>$this->input($_POST,'html',131072),'bindings'=>$bindings]);}else unset($document['markup']);
        $context->store->save($key,JsonStore::encode($document),$this->input($_POST,'revision'));return ['redirect'=>$context->base.'/cms-admin/?view=plugin-panel&plugin=pages&route=editor&key='.rawurlencode($key)];
      });
    }
    $result=$this->change($context,$operation,$_POST);$_SESSION['notice_key']=$result['notice'];return $result;
  }
}
