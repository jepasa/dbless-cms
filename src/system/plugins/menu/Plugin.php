<?php
/** Plugin Menu API 1: operações privadas de conteúdo, associações, lixeira e recuperação, sem HTML público próprio. */
declare(strict_types=1);
namespace DBLESSCMS;
require_once __DIR__.'/Router.php';
require_once __DIR__.'/Placement.php';
final class MenuPlugin implements PluginInterface,ContentProviderInterface,ContentOptionsInterface {

  /** List active template menu locations for the authorized picker; receive service/context, return plain key/description pairs without changing assignments. */
  public function contentOptions(string $service,ContentContext $context): array {
    $choices=[];foreach(Navigation::locations($context->store->templates->get($context->template)) as $key=>$location)$choices[]=['value'=>$key,'label'=>$location['description']];return ['key'=>$choices];
  }

  /** Flatten released menu links for the shared list component; receive service/parameters/context, return at most twenty public links, no writes or hidden-page disclosure. */
  public function content(string $service,array $parameters,ContentContext $context): array {
    if(!in_array($service,['location','tree'],true))throw new HttpError(404,'Unknown service.','components.error');$menu=(new Navigation($context->store,$context->template,$context->base))->location($parameters['key']);$items=[];if($service==='tree')return ['name'=>$menu['name']??'','type'=>$menu['type']??'','items'=>$menu['items']??[]];
    /** Walk a validated bounded public tree; receive nodes/depth, append safe links to this projection, no I/O or recursion beyond five levels. */
    $walk=static function(array $nodes,int $depth=0)use(&$walk,&$items):void{if($depth>5)return;foreach($nodes as $node){if(count($items)>=20)return;if($node['href']!=='')$items[]=['title'=>$node['label'],'text'=>'','href'=>$node['href'],'image'=>''];$walk($node['children'],$depth+1);}};
    $walk($menu['items']??[]);return ['items'=>$items];
  }
  /** Valida somente o contrato legado de opções, sem aplicá-las ou gravar; recebe settings/config e lança 422 em contrato inválido. */
  public function validateSettings(array $settings,Config $config): void {
    Schema::validate($settings,json_decode((string)file_get_contents(__DIR__.'/settings.schema.json'),true,16,JSON_THROW_ON_ERROR));
  }
  /** Despacha a página privada do plugin; recebe contexto autorizado e retorna HTML escapado ou erro seguro. */
  public function render(PluginContext $context): string { return MenuRouter::render($context); }
  /** Extrai campo textual limitado; recebe nome/limite, retorna string ou 422; não altera POST. */
  private function input(string $key,int $limit=160): string {
    $value=$_POST[$key]??'';
    if (!is_string($value)||strlen($value)>$limit) throw new HttpError(422,'Campo inválido.','menu.error.contract');
    return $value;
  }
  /** Executa mutação POST autorizada pelo núcleo; recebe contexto/operação, retorna revisão/destino local; falhas preservam revisão e conteúdo. */
  public function api(PluginContext $context,string $operation): ?array {
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') throw new HttpError(405,'Método inválido.','error.method');
    $revision=$this->input('revision',128);$menus=new MenuStore($context->store);$route=$context->route;
    if ($operation==='history-restore') {
      if ($route!=='history') throw new HttpError(403,'Rota incompatível.','error.plugin');
      if (!(new Plugins($context->store))->state('menu')['enabled']) throw new HttpError(404,'Plugin desativado.','error.plugin');
      $result=$menus->restore($this->input('backup',80),$revision);
      return ['revision'=>$result['revision'],'redirect'=>$context->base.'/cms-admin/?view=plugin-panel&plugin=menu&route='.$context->route];
    }
    $allowed=['save'=>'editor','assign'=>'locations','trash'=>'overview','recover'=>'trash'];
    if (!isset($allowed[$operation])||$route!==$allowed[$operation]) throw new HttpError(404,'Operação incompatível.','error.not_found');
    $id=$this->input('id',64);
    $result=$menus->mutate($revision,function(array $data) use($context,$operation,$id): array {
      if ($operation==='save') {
        if (!MenuStore::identifier($id)) throw new HttpError(422,'Identificador inválido.','menu.error.contract');
        $original=$this->input('original',64);
        if (($original===''&&isset($data['menus'][$id]))||($original!==''&&$original!==$id)) throw new HttpError(409,'Identificador utilizado.','menu.error.exists');
        if ($original!==''&&(!isset($data['menus'][$id])||$data['menus'][$id]['deleted'])) throw new HttpError(404,'Menu indisponível.','menu.error.missing');
        try { $items=json_decode($this->input('items',262144),true,24,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new HttpError(422,'Itens inválidos.','menu.error.contract'); }
        MenuStore::items($items);
        foreach ($items as $item) if (in_array($item['kind'],['page','anchor'],true)) {
          try { $context->store->read($item['page']); }
          catch (HttpError $error) { if ($error->status===404) throw new HttpError(422,'Página indisponível.','menu.error.page');throw $error; }
        }
        $data['menus'][$id]=['id'=>$id,'name'=>$this->input('name'),'deleted'=>false,'items'=>$items];
        $data=MenuPlacement::apply($context,$data,$id,$_POST);
      } elseif ($operation==='assign') {
        $template=$this->input('template',64);$location=$this->input('location',64);
        $locations=Navigation::locations($context->store->templates->get($template));
        if (!isset($locations[$location])) throw new HttpError(422,'Localização inválida.','menu.error.binding');
        $data['assignments']=array_values(array_filter($data['assignments'],static fn(array $item): bool=>$item['template']!==$template||$item['location']!==$location));
        if ($id!=='') {
          $binding=['template'=>$template,'location'=>$location,'menu'=>$id,'type'=>$this->input('type',20)];
          Navigation::binding($context->store,$data,$binding);$data['assignments'][]=$binding;
        }
      } else {
        if (!isset($data['menus'][$id])) throw new HttpError(404,'Menu indisponível.','menu.error.missing');
        $data['menus'][$id]['deleted']=$operation==='trash';
      }
      return $data;
    });
    $destination=$operation==='save'?'editor':($operation==='recover'?'trash':($operation==='assign'?'locations':'overview'));
    return ['revision'=>$result['revision'],'redirect'=>$context->base.'/cms-admin/?view=plugin-panel&plugin=menu&route='.$destination.($operation==='save'?'&id='.rawurlencode($id):'')];
  }
}
