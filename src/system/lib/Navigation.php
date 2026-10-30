<?php
/** Contrato público de navegação: localizações estáticas e resolução de itens privados sem executar o plugin. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Navigation {
  public const TYPES=['navbar','sidebar','list','dropdown'];
  private ?array $document=null;
  private bool $loaded=false;
  /** Recebe serviços/template/base pública; não escreve e adia a leitura de menus até a localização solicitada. */
  public function __construct(private readonly JsonStore $store,private readonly string $template,private readonly string $base) {}

  /** Valida locais estáticos do manifesto; recebe manifesto e retorna mapa ou 422; itens dinâmicos são proibidos. */
  public static function locations(array $manifest): array {
    $locations=$manifest['menuLocations']??[];
    if (!is_array($locations) || (array_is_list($locations)&&$locations!==[]) || count($locations)>20) throw new HttpError(422,'Localizações inválidas.','error.manifest');
    foreach ($locations as $key=>$location) {
      if (!is_string($key)||!MenuStore::identifier($key)||!is_array($location)||count(array_diff(array_keys($location),['name','description','types','defaultType','maxDepth']))!==0) throw new HttpError(422,'Localização inválida.','error.manifest');
      foreach (['name','description'] as $field) if (!is_string($location[$field]??null)||trim($location[$field])===''||strlen($location[$field])>500||preg_match('/[\x00-\x1F\x7F<>]/',$location[$field])) throw new HttpError(422,'Texto de localização inválido.','error.manifest');
      $types=$location['types']??null;
      if (!is_array($types)||!array_is_list($types)||$types===[]||count($types)>4||count(array_filter($types,'is_string'))!==count($types)||array_diff($types,self::TYPES)!==[]||count(array_unique($types))!==count($types)||!in_array($location['defaultType']??null,$types,true)||!is_int($location['maxDepth']??null)||$location['maxDepth']<1||$location['maxDepth']>5) throw new HttpError(422,'Formatação de menu inválida.','error.manifest');
    }
    if ($locations!==[]&&!isset($manifest['partials']['menu'])) throw new HttpError(422,'Partial de menu ausente.','error.manifest');
    return $locations;
  }

  /** Confere associação contra template instalado; recebe store/documento/vínculo e lança 422 para destino/tipo/profundidade inválidos, sem escrita. */
  public static function binding(JsonStore $store,array $data,array $binding): void {
    $locations=self::locations($store->templates->get($binding['template']));$location=$locations[$binding['location']]??null;
    $menu=$data['menus'][$binding['menu']]??null;
    if ($location===null||$menu===null||$menu['deleted']||!in_array($binding['type'],$location['types'],true)||MenuStore::items($menu['items'])>$location['maxDepth']) throw new HttpError(422,'Associação incompatível.','menu.error.binding');
  }

  /** Resolve uma localização ativa para árvore pública ou null para navegação nativa; recebe chave e propaga erros reais de leitura/contrato. */
  public function location(string $key): ?array {
    $locations=self::locations($this->store->templates->get($this->template));
    if (!isset($locations[$key])) return null;
    if (!$this->loaded) {
      $this->loaded=true;$registry=new Plugins($this->store);
      if (isset($registry->all()['menu'])&&$registry->state('menu')['enabled']) $this->document=(new MenuStore($this->store))->read()['data'];
    }
    if ($this->document===null) return null;
    foreach ($this->document['assignments'] as $binding) if ($binding['template']===$this->template&&$binding['location']===$key) {
      $menu=$this->document['menus'][$binding['menu']];
      if ($menu['deleted']||!in_array($binding['type'],$locations[$key]['types'],true)||MenuStore::items($menu['items'])>$locations[$key]['maxDepth']) return null;
      return ['type'=>$binding['type'],'items'=>$this->tree($menu['items'])];
    }
    return null;
  }

  /** Resolve árvore plana para dados escapáveis; recebe itens e parent interno; omite páginas ausentes/rascunhos e grupos vazios; não altera dados. */
  private function tree(array $items,string $parent=''): array {
    $result=[];
    foreach ($items as $item) {
      if ($item['parent']!==$parent||!$item['enabled']) continue;
      $href='';$slug='';
      if (in_array($item['kind'],['page','anchor'],true)) {
        try { $page=$this->store->read($item['page'],$this->template)['data']; }
        catch (HttpError $error) { if ($error->status===404) continue;throw $error; }
        if ($page['status']!=='published') continue;
        $slug=$item['page'];$href=$this->base.($slug==='home'?'/':'/'.$slug).($item['kind']==='anchor'?'#'.$item['anchor']:'');
      } elseif ($item['kind']==='url') $href=$item['url'];
      $children=$this->tree($items,$item['id']);
      if ($href===''&&$children===[]) continue;
      $result[]=['label'=>$item['label'],'href'=>$href,'slug'=>$slug,'anchor'=>$item['anchor'],'newTab'=>$item['newTab'],'children'=>$children];
    }
    return $result;
  }
}
