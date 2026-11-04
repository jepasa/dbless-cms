<?php
/** Automatic dotted references: manifest discovery, bounded read-only queries, typed parameters, safe HTML slots and private diagnostics. */
declare(strict_types=1);
namespace DBLESSCMS;
final class ContentReferences {
  private Plugins $registry;
  private array $cache=[];
  private array $warnings=[];
  private array $missing=[];
  private static ?\WeakMap $budgets=null;
  /** Receive immutable render context, discover trusted manifests without executing providers or writing state. */
  public function __construct(private readonly ContentContext $context) {$this->registry=new Plugins($context->store);}
  /** Validate optional plugin reference metadata; receive manifest, return void or 422, no provider execution. */
  public static function definitions(array $manifest): void {
    if(!isset($manifest['mustache']))return;$m=$manifest['mustache'];
    if(!is_array($m)||count($m)!==4||array_diff(array_keys($m),['api','kind','queries','fields'])||$m['api']!==1||!in_array($m['kind'],['data','form','widget'],true)||!is_array($m['queries'])||!is_array($m['fields'])||count($m['queries'])>10||count($m['fields'])>40)throw new HttpError(422,'Invalid reference manifest.','markup.error');
    foreach($m['fields'] as $field)if(!is_string($field)||!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D',$field))throw new HttpError(422,'Invalid reference field.','markup.error');
    foreach($m['queries'] as $name=>$schema){if(!Plugins::identifier($name)||in_array($name,['id','slug'],true)||!is_array($schema))throw new HttpError(422,'Invalid reference query.','markup.error');Components::schema($schema);if($schema['type']!=='object')throw new HttpError(422,'Invalid query parameters.','markup.error');}
  }
  /** Validate declared template positions and their existing typed locations; receive manifest, return mapping or 422 without I/O. */
  public static function slots(array $manifest): array {
    $slots=$manifest['mustacheSlots']??[];if(!is_array($slots)||count($slots)>50)throw new HttpError(422,'Invalid Mustache slots.','markup.error');
    foreach($slots as $key=>$slot){if(!is_array($slot))throw new HttpError(422,'Invalid Mustache slot.','markup.error');$locations=match($slot['plugin']??''){'menu'=>'menuLocations','slides'=>'slideLocations','widgets'=>'widgetLocations',default=>''};if(!is_string($key)||!Plugins::identifier($key)||!is_array($slot)||count($slot)!==3||array_diff(array_keys($slot),['plugin','location','description'])||$locations===''||!is_string($slot['location']??null)||!isset($manifest[$locations][$slot['location']])||!is_string($slot['description']??null)||trim($slot['description'])===''||strlen($slot['description'])>500)throw new HttpError(422,'Invalid Mustache slot declaration.','markup.error');}return $slots;
  }
  /** Validate bounded JSON values including arbitrary widget keys; receive value/depth, throw 422 for objects, callables or excessive structures; no effects. */
  public static function json(mixed $value,int $depth=0): void {
    if($depth>10)throw new HttpError(422,'Reference depth exceeded.','markup.error');
    if(is_array($value)){if(count($value)>100)throw new HttpError(422,'Reference collection exceeded.','markup.error');foreach($value as $key=>$child){if(is_string($key)&&(!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D',$key)||strlen($key)>64))throw new HttpError(422,'Invalid JSON field.','markup.error');self::json($child,$depth+1);}return;}
    if($value===null||is_bool($value)||is_int($value)||is_float($value)&&is_finite($value))return;if(is_string($value)&&strlen($value)<=131072&&preg_match('//u',$value))return;throw new HttpError(422,'Invalid reference data.','markup.error');
  }
  /** Slice an already sorted public collection; receive items/parameters/site, return finite items and global pagination metadata without writes. */
  public static function paginate(array $items,array $parameters,array $site): array {
    $size=$site['settings']['contentPageSize']??12;$total=min(count($items),$parameters['limit']??10000);$page=$parameters['page']??1;$pages=(int)ceil($total/$size);$offset=($page-1)*$size;
    return ['items'=>$offset>=$total?[]:array_values(array_slice($items,$offset,min($size,$total-$offset))),'total'=>$total,'page'=>$page,'pages'=>$pages,'pageSize'=>$size,'hasNext'=>$page<$pages,'hasPrevious'=>$page>1&&$pages>0];
  }
  /** Parse a qualified reference using only declared named parameters; receive path, return normalized provider/query/field path or null for lexical data; reject malformed parameters. */
  private function parse(string $path): ?array {
    $parts=explode('.',$path);$plugin=array_shift($parts);$selector=array_shift($parts);$manifest=$this->registry->all()[$plugin]??null;
    if($plugin==='template'){$slots=self::slots($this->context->store->templates->get($this->context->template));$slot=$slots[$selector??'']??null;if($slot===null)throw new HttpError(422,'Undeclared template position.','markup.error');return [$slot['plugin'],'@location',['key'=>$slot['location']],$parts];}
    if($manifest===null||!isset($manifest['mustache'])){if($selector!==null&&$plugin!=='cms')$this->warnings[]=$plugin.' (unavailable)';return null;}if($selector===null)throw new HttpError(422,'Missing reference selector.','markup.error');
    if(in_array($selector,['id','slug'],true)){$identity=array_shift($parts);if($identity===null)throw new HttpError(422,'Missing reference identity.','markup.error');return [$plugin,$selector.':'.$identity,[],$parts];}
    $query=$manifest['mustache']['queries'][$selector]??null;$parameters=[];
    if($query!==null){while($parts!==[]&&isset($query['properties'][$parts[0]])){$key=array_shift($parts);$value=array_shift($parts);$type=$query['properties'][$key]['type'];if($value===null||array_key_exists($key,$parameters)||$type==='integer'&&!preg_match('/^[1-9][0-9]{0,4}$/D',$value))throw new HttpError(422,'Invalid reference parameter.','markup.error');$parameters[$key]=$type==='integer'?(int)$value:$value;}foreach($query['properties'] as $key=>$field)if(!array_key_exists($key,$parameters)&&array_key_exists('default',$field))$parameters[$key]=$field['default'];Schema::validate($parameters,$query);ksort($parameters);if($parts!==[]&&!in_array($parts[0],$manifest['mustache']['fields'],true))throw new HttpError(422,'Unknown query parameter or field.','markup.error');}
    return [$plugin,$selector,$parameters,$parts];
  }
  /** Fetch one public projection once per render, charging request budgets even on misses; receive parsed identity, return data or null, propagate corrupt contracts. */
  private function fetch(string $plugin,string $selector,array $parameters): ?array {
    $key=$plugin.':'.$selector.':'.json_encode($parameters,JSON_THROW_ON_ERROR);if(array_key_exists($key,$this->cache))return $this->cache[$key];
    $manifest=$this->registry->all()[$plugin]??null;if($manifest===null||!isset($manifest['mustache'])||!$this->registry->state($plugin)['enabled']){$this->warnings[]=$plugin.' (inactive)';return $this->cache[$key]=null;}
    self::$budgets??=new \WeakMap();$used=self::$budgets[$this->context->store]??0;if($used>=20)throw new HttpError(422,'Reference request limit exceeded.','markup.error');self::$budgets[$this->context->store]=$used+1;
    $provider=$this->registry->instance($plugin);if(!$provider instanceof ContentReferenceInterface)throw new HttpError(503,'Reference provider contract unavailable.','markup.error');$data=$provider->reference($selector,$parameters,$this->context);self::json($data);if(strlen(json_encode($data,JSON_THROW_ON_ERROR))>131072)throw new HttpError(422,'Reference output exceeded.','markup.error');if($data===null)$this->warnings[]=$plugin.'.'.$selector.' (unavailable)';return $this->cache[$key]=$data;
  }
  /** Resolve a qualified path lazily; receive path, return JSON/null and track genuine absence separately from valid null fields; private diagnostics only, no reparsing or writes. */
  public function value(string $path): mixed {
    $parsed=$this->parse($path);if($parsed===null){if(str_contains($path,'.')&&!str_starts_with($path,'cms.'))$this->missing[$path]=true;return null;}[$plugin,$selector,$parameters,$fields]=$parsed;$value=$this->fetch($plugin,$selector,$parameters);if($value===null){$this->missing[$path]=true;return null;}
    foreach($fields as $field){if(!is_array($value)||!array_key_exists($field,$value)){$this->warnings[]=$path.' (field unavailable)';$this->missing[$path]=true;return null;}$value=$value[$field];}return $value;
  }
  /** Report whether an automatically resolved path was absent; receive path, return bool, preserving valid null/falsy fields without I/O. */
  public function missing(string $path): bool {return isset($this->missing[$path]);}
  /** Prepare typed standalone HTML references as opaque slots; receive source/legacy roots, return source/slots without rendering forms or evaluating provider HTML as Mustache. */
  public function prepare(string $source,array $legacyRoots=[]): array {
    if(preg_match('/data-cms-reference-slot\s*=/i',$source))throw new HttpError(422,'Reserved reference slot.','markup.error');$slots=[];
    $html=preg_replace_callback('/\{\{\s*([a-zA-Z][a-zA-Z0-9_-]*(?:\.[a-zA-Z0-9_-]+)+)\s*\}\}/',function(array $match)use(&$slots,$legacyRoots):string{
      $root=explode('.',$match[1])[0];if(in_array($root,$legacyRoots,true))return $match[0];if($root!=='template'&&!isset($this->registry->all()[$root]['mustache']))return $match[0];$parsed=$this->parse($match[1]);if($parsed===null)return $match[0];[$plugin,$selector,$parameters,$fields]=$parsed;$kind=$this->registry->all()[$plugin]['mustache']['kind']??'data';if($fields!==[]||!in_array($kind,['form','widget'],true))return $match[0];$data=$this->fetch($plugin,$selector,$parameters);if($data===null)return $match[0];
      if($kind==='widget'&&($data['type']??'text')==='text')return '{{'.$match[1].'.value}}';if($kind==='widget'&&$data['type']!=='html')return $match[0];if(count($slots)>=10)throw new HttpError(422,'HTML reference limit exceeded.','markup.error');$token=bin2hex(random_bytes(16));$slots[$token]=$kind==='form'?['plugin'=>$plugin,'id'=>$data['id']]:['html'=>ContentMarkup::html($data['html'],false)];return '<div data-cms-reference-slot="'.$token.'"></div>';
    },$source);return ['html'=>$html,'slots'=>$slots];
  }
  /** Mount validated typed HTML after data rendering; receive HTML/slots, return bytes, reject unsafe placement and bound repeated slots; forms remain request-scoped. */
  public function mount(string $html,array $slots): string {
    if($slots===[])return $html;$dom=new \DOMDocument('1.0','UTF-8');$previous=libxml_use_internal_errors(true);
    try{$dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xpath=new \DOMXPath($dom);$count=0;
      foreach($slots as $token=>$slot)foreach(iterator_to_array($xpath->query('//*[@data-cms-reference-slot="'.$token.'"]')) as $node){if(++$count>10||$node->tagName!=='div'||$node->childNodes->length||$xpath->query('ancestor::a|ancestor::button|ancestor::form|ancestor::label|ancestor::pre|ancestor::code',$node)->length)throw new HttpError(422,'Unsafe reference placement.','markup.error');$fragment=$slot['html']??ContentForms::render($slot,$this->context);$child=new \DOMDocument('1.0','UTF-8');$child->loadHTML('<?xml encoding="UTF-8"><html><body>'.$fragment.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);foreach(iterator_to_array($child->getElementsByTagName('body')->item(0)->childNodes) as $element)$node->parentNode->insertBefore($dom->importNode($element,true),$node);$node->parentNode->removeChild($node);}
      $result='';foreach($dom->getElementsByTagName('body')->item(0)->childNodes as $node)$result.=$dom->saveHTML($node);return $result;
    }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
  }
  /** Return deduplicated private reference diagnostics, no state changes. */
  public function warnings(): array {return array_values(array_unique($this->warnings));}
}
