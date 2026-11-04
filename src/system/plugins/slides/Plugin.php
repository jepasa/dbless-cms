<?php
/** Plugin Slides API 1: coleções, associações, publicação, lixeira e histórico sob segurança central do painel. */
declare(strict_types=1);
namespace DBLESSCMS;
require_once __DIR__.'/Router.php';
require_once __DIR__.'/Placement.php';
final class SlidesPlugin implements PluginInterface,ContentProviderInterface,ContentOptionsInterface,ContentReferenceInterface {
  /** Resolve a published set through its allowed direct/template channel; receive selector/parameters/context, return enabled data/transition or null, no writes. */
  public function reference(string $selector,array $parameters,ContentContext $context): ?array {
    $slides=new Slides($context->store,$context->template);$id=preg_replace('/^(?:id|slug):/','',$selector);$data=$selector==='@location'?$slides->location($parameters['key']):((new SlidesStore($context->store))->read()['data']['collections'][$id]??null);
    if($data===null||$data['deleted']||$data['status']!=='published'||!ContentPlacement::allows($data,$selector==='@location'?'template':'mustache'))return null;$transition=$data['transition']??['effect'=>'slide','duration'=>500,'orientation'=>'horizontal'];$items=[];
    foreach($data['items'] as $item){if(!$item['enabled'])continue;unset($item['enabled']);if(!$slides->imageExists($item['image']))$item['image']='';elseif($item['image']!=='')$item['image']=$context->base.'/'.ltrim($item['image'],'/');if(str_starts_with($item['href'],'/'))$item['href']=$context->base.$item['href'];$items[]=$item;if(count($items)>=($data['maxItems']??50))break;}
    return ['id'=>$data['id'],'slug'=>$data['id'],'name'=>$data['name'],'contentType'=>$data['contentType'],'autoplay'=>$data['autoplay'],'interval'=>$data['interval'],'transition'=>$transition,'count'=>count($items),'items'=>$items];
  }

  /** List active template's declared slide locations for the private picker; receive service/context, return bounded key/description pairs, no collection mutation. */
  public function contentOptions(string $service,ContentContext $context): array {
    $choices=[];foreach(Slides::locations($context->store->templates->get($context->template)) as $key=>$location)$choices[]=['value'=>$key,'label'=>$location['description']];return ['key'=>$choices];
  }

  /** Project visible slides from a template-declared location; receive service/parameters/context, return public card data with safe media and no storage mutation. */
  public function content(string $service,array $parameters,ContentContext $context): array {
    if(!in_array($service,['location','data'],true))throw new HttpError(404,'Unknown service.','components.error');$data=(new Slides($context->store,$context->template))->location($parameters['key']);
    if($service==='data'){if($data===null)return ['name'=>'','contentType'=>'','type'=>'','autoplay'=>false,'interval'=>3000,'items'=>[]];$items=[];foreach($data['items'] as $item){unset($item['enabled']);foreach(['image','href'] as $key)if($item[$key]!==''&&!preg_match('~^(?:https?://|mailto:|tel:|#)~i',$item[$key]))$item[$key]=$context->base.'/'.ltrim($item[$key],'/');$items[]=$item;}return ['name'=>$data['name'],'contentType'=>$data['contentType'],'type'=>$data['type'],'autoplay'=>$data['autoplay'],'interval'=>$data['interval'],'items'=>$items];}
    return ['items'=>array_map(static fn(array $item):array=>['title'=>$item['title'],'text'=>$item['text'],'href'=>$item['href'],'image'=>$item['image']],array_slice($data['items']??[],0,20))];
  }
  /** Valida somente opções legadas para compatibilidade, sem aplicá-las ou gravar; recebe settings/config e propaga 422. */
  public function validateSettings(array $settings,Config $config): void {
    Schema::validate($settings,json_decode((string)file_get_contents(__DIR__.'/settings.schema.json'),true,8,JSON_THROW_ON_ERROR));
  }
  /** Delega rota privada validada; recebe contexto e retorna fragmento escapado ou erro seguro. */
  public function render(PluginContext $context): string { return SlidesRouter::render($context); }
  /** Extrai string limitada do POST; recebe nome/limite e retorna valor ou 422, sem mutar entrada. */
  private function input(string $key,int $limit=160): string {
    $value=$_POST[$key]??'';if(!is_string($value)||strlen($value)>$limit)throw new HttpError(422,'Campo inválido.','slides.error.contract');return $value;
  }
  /** Executa somente POST/rota compatível após sessão/CSRF do núcleo; recebe contexto/operação e retorna redirect/revisão ou erro sem perda de dados. */
  public function api(PluginContext $context,string $operation): ?array {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')throw new HttpError(405,'Método inválido.','error.method');
    $revision=$this->input('revision',128);$repository=new SlidesStore($context->store);
    if($operation==='history-restore'){
      if($context->route!=='history')throw new HttpError(403,'Rota incompatível.','error.plugin');
      if (!(new Plugins($context->store))->state('slides')['enabled']) throw new HttpError(404,'Plugin desativado.','error.plugin');
      $repository->restore($this->input('backup',80),$revision);
      return ['redirect'=>$context->base.'/cms-admin/?view=plugin-panel&plugin=slides&route=history'];
    }
    $routes=['save'=>'editor','assign'=>'locations','trash'=>'overview','recover'=>'trash'];
    if(($routes[$operation]??null)!==$context->route)throw new HttpError(404,'Operação incompatível.','error.not_found');
    $id=$this->input('id',64);
    $result=$repository->mutate($revision,function(array $data) use($context,$operation,$id): array {
      if($operation==='save'){
        $original=$this->input('original',64);
        if(!SlidesStore::identifier($id))throw new HttpError(422,'Identificador inválido.','slides.error.contract');
        if(($original===''&&isset($data['collections'][$id]))||($original!==''&&$original!==$id))throw new HttpError(409,'Identificador utilizado.','slides.error.exists');
        if($original!==''&&(!isset($data['collections'][$id])||$data['collections'][$id]['deleted']))throw new HttpError(404,'Conjunto indisponível.','slides.error.missing');
        try{$items=json_decode($this->input('items',262144),true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(422,'Itens inválidos.','slides.error.contract');}
        $interval=$this->input('interval',8);if(!preg_match('/^[0-9]{4,5}$/D',$interval))throw new HttpError(422,'Intervalo inválido.','slides.error.contract');
        $settings=array_intersect_key($data['collections'][$id]??[],array_flip(['maxItems','transition','insertionMode']));
        $data['collections'][$id]=['id'=>$id,'name'=>$this->input('name'),'contentType'=>$this->input('contentType',24),'status'=>$this->input('status',20),'deleted'=>false,'autoplay'=>isset($_POST['autoplay']),'interval'=>(int)$interval,'items'=>$items]+$settings;
        if(isset($_POST['maxItems'])){$max=$this->input('maxItems',2);$duration=$this->input('transitionDuration',4);if(!ctype_digit($max)||!ctype_digit($duration))throw new HttpError(422,'Invalid transition.','slides.error.contract');$data['collections'][$id]['maxItems']=(int)$max;$data['collections'][$id]['transition']=['effect'=>$this->input('transitionEffect',12),'duration'=>(int)$duration,'orientation'=>$this->input('transitionOrientation',12)];}
        SlidesStore::validate($data);
        $data=SlidePlacement::apply($context,$data,$id,$_POST);
      }elseif($operation==='assign'){
        $template=$this->input('template',64);$location=$this->input('location',64);
        $manifest=$context->store->templates->get($template);if(!isset(ContentPlacement::locations($manifest,'slides',Slides::locations($manifest))[$location]))throw new HttpError(422,'Local indisponível.','slides.error.binding');
        $data['assignments']=array_values(array_filter($data['assignments'],static fn(array $item): bool=>$item['template']!==$template||$item['location']!==$location));
        if($id!==''){$binding=['template'=>$template,'location'=>$location,'collection'=>$id];Slides::binding($context->store,$data,$binding);$data['assignments'][]=$binding;}
      }else{
        if(!isset($data['collections'][$id]))throw new HttpError(404,'Conjunto ausente.','slides.error.missing');
        $data['collections'][$id]['deleted']=$operation==='trash';
      }
      return $data;
    });
    $route=match($operation){'save'=>'editor','assign'=>'locations','recover'=>'trash',default=>'overview'};
    return ['revision'=>$result['revision'],'redirect'=>$context->base.'/cms-admin/?view=plugin-panel&plugin=slides&route='.$route.($operation==='save'?'&id='.rawurlencode($id):'')];
  }
}
