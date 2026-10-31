<?php
/** Resolução pública Slides: contratos estáticos e dados publicados, sem executar plugins nem produzir HTML. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Slides {
  private static int $instances=0;
  /** Return a unique request-local accessible carousel identifier; receive a validated location key, increment only memory and never persist state. */
  public static function instanceId(string $location): string {return 'slides-'.$location.'-'.(++self::$instances);}
  private ?array $document=null;
  private bool $loaded=false;
  private int $resolved=0;
  /** Recebe store/template; não grava dados; carregamento de conteúdo é adiado. */
  public function __construct(private readonly JsonStore $store,private readonly string $template) {}
  /** Valida localizações estáticas; recebe manifesto e retorna mapa ou 422, sem acesso ao filesystem. */
  public static function locations(array $manifest): array {
    $locations=$manifest['slideLocations']??[];
    if (!is_array($locations)||(array_is_list($locations)&&$locations!==[])||count($locations)>30) throw new HttpError(422,'Locais inválidos.','slides.error.binding');
    foreach($locations as $key=>$location) {
      if (!is_string($key)||!SlidesStore::identifier($key)||!is_array($location)||count($location)!==3||array_diff(array_keys($location),['type','contentType','description'])!==[]||!in_array($location['type']??null,['slider','carousel','rotator'],true)||!in_array($location['contentType']??null,SlidesStore::CONTENT_TYPES,true)||!is_string($location['description']??null)||trim($location['description'])===''||strlen($location['description'])>500||preg_match('/[\x00-\x1F\x7F<>]/',$location['description'])) throw new HttpError(422,'Local inválido.','slides.error.binding');
    }
    if ($locations!==[]&&!isset($manifest['partials']['slides'])) throw new HttpError(422,'Partial slides ausente.','slides.error.binding');
    return $locations;
  }
  /** Valida associação instalada e tipagem; recebe store/documento/vínculo e lança 422/404 sem escrita. */
  public static function binding(JsonStore $store,array $data,array $binding): void {
    $location=self::locations($store->templates->get($binding['template']))[$binding['location']]??null;
    $set=$data['collections'][$binding['collection']]??null;
    if ($location===null||$set===null||$set['deleted']||$set['contentType']!==$location['contentType']) throw new HttpError(422,'Associação incompatível.','slides.error.binding');
  }
  /** Reconhece somente marcador completo autorizado, inspirado em Mustache; recebe texto e retorna chave ou null. */
  public static function marker(string $text): ?string {
    return preg_match('/^\{\{slides:([a-z][a-z0-9-]{0,47})\}\}$/D',trim($text),$match)===1?$match[1]:null;
  }
  /** Identifica chaves em marcadores completos e blocos tipados; recebe conteúdo e retorna lista única, sem escrita ou interpretação de código. */
  public static function references(array $content): array {
    $keys=[];
    foreach ($content['blocks']??[] as $block) if (is_array($block)&&($block['type']??null)==='slides'&&is_string($block['location']??null)) $keys[$block['location']]=true;
    foreach ($content['sections']??[] as $section) foreach ($section['body']??[] as $paragraph) if (is_string($paragraph)&&($key=self::marker($paragraph))!==null) $keys[$key]=true;
    return array_keys($keys);
  }
  /** Valida blocos estruturados opcionais; recebe conteúdo e lança 422 para tipo/chave/campos desconhecidos. */
  public static function page(array $content): void {
    $blocks=$content['blocks']??[];
    if (!is_array($blocks)||!array_is_list($blocks)||count($blocks)>20) throw new HttpError(422,'Blocos inválidos.','slides.error.contract');
    foreach($blocks as $block) if (!is_array($block)||count($block)!==2||array_diff(array_keys($block),['type','location'])!==[]||($block['type']??null)!=='slides'||!is_string($block['location']??null)||!SlidesStore::identifier($block['location'])) throw new HttpError(422,'Bloco inválido.','slides.error.contract');
    $count=count($blocks);
    $sections=$content['sections']??[];
    if(!is_array($sections))throw new HttpError(422,'Seções inválidas.','slides.error.contract');
    foreach($sections as $section){
      if(!is_array($section)||!is_array($section['body']??[]))throw new HttpError(422,'Seção inválida.','slides.error.contract');
      foreach($section['body']??[] as $paragraph)if(is_string($paragraph)&&self::marker($paragraph)!==null)$count++;
    }
    if($count>20)throw new HttpError(422,'Componentes excessivos.','slides.error.contract');
  }
  /** Confere imagem existente dentro da raiz pública, sem links em componentes; recebe relativo e retorna bool sem escrita. */
  public function imageExists(string $image): bool {
    if ($image===''||!SlidesStore::image($image)) return false;
    $path=dirname($this->store->config->root).'/public_html';
    if(is_link($path)) return false;
    foreach(explode('/',$image) as $part){$path.='/'.$part;if(is_link($path))return false;}
    return is_file($path);
  }
  /** Resolve conjunto publicado/compatível por chave; retorna dados com mídia segura ou null; corrupção propaga erro explícito. */
  public function location(string $key): ?array {
    if($this->resolved>=20)return null;
    $location=self::locations($this->store->templates->get($this->template))[$key]??null;
    if ($location===null) return null;
    if (!$this->loaded) {
      $this->loaded=true;$registry=new Plugins($this->store);
      if(isset($registry->all()['slides'])&&$registry->state('slides')['enabled'])$this->document=(new SlidesStore($this->store))->read()['data'];
    }
    if($this->document===null)return null;
    foreach($this->document['assignments'] as $binding)if($binding['template']===$this->template&&$binding['location']===$key){
      $set=$this->document['collections'][$binding['collection']];
      if($set['deleted']||$set['status']!=='published'||$set['contentType']!==$location['contentType'])return null;
      $set['items']=array_values(array_filter($set['items'],static fn(array $item): bool=>$item['enabled']));
      if($set['items']===[])return null;
      foreach($set['items'] as &$item)if(!$this->imageExists($item['image']))$item['image']='';unset($item);
      $this->resolved++;
      return $set+['location'=>$key,'type'=>$location['type']];
    }
    return null;
  }
}
