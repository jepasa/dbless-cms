<?php
/** Shared component registry/parser/resolver: manifest discovery, typed read-only providers, template-owned presentation and bounded nonrecursive markers. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Components {
  private ?Plugins $plugins=null;
  private int $resolved=0;
  private array $warnings=[];
  private array $data=[];
  /** Receive read context; defer registry/provider loading and never persist component output. */
  public function __construct(private readonly ContentContext $context) {}
  /** Validate a closed bounded data schema using only the supported subset; receive schema/depth, throw 422 on unknown keywords/types or unsafe limits, no I/O. */
  public static function schema(array $schema,int $depth=0): void {
    $allowed=['type','properties','required','additionalProperties','items','maxItems','maxLength','minimum','maximum','enum','default'];
    if($depth>8||array_diff(array_keys($schema),$allowed)!==[]||!in_array($schema['type']??null,['object','array','string','integer','boolean'],true))throw new HttpError(422,'Invalid component schema.','components.error');
    if($schema['type']==='object'){
      if(($schema['additionalProperties']??null)!==false||!is_array($schema['properties']??null)||count($schema['properties'])>20||!is_array($schema['required']??[])||!array_is_list($schema['required']??[]))throw new HttpError(422,'Invalid component object.','components.error');
      foreach($schema['required']??[] as $key)if(!is_string($key)||!isset($schema['properties'][$key]))throw new HttpError(422,'Invalid required parameter.','components.error');
      foreach($schema['properties'] as $key=>$child){if(!is_string($key)||!preg_match('/^[a-z][a-zA-Z0-9]{0,47}$/D',$key)||!is_array($child))throw new HttpError(422,'Invalid component field.','components.error');self::schema($child,$depth+1);}
    }elseif($schema['type']==='array'){
      if(!is_int($schema['maxItems']??null)||$schema['maxItems']<1||$schema['maxItems']>100||!is_array($schema['items']??null))throw new HttpError(422,'Invalid component list.','components.error');self::schema($schema['items'],$depth+1);
    }elseif($schema['type']==='string'){
      if(!is_int($schema['maxLength']??null)||$schema['maxLength']<1||$schema['maxLength']>12000)throw new HttpError(422,'Invalid component text.','components.error');
    }elseif($schema['type']==='integer'&&(!is_int($schema['minimum']??null)||!is_int($schema['maximum']??null)||$schema['minimum']>$schema['maximum']))throw new HttpError(422,'Invalid component range.','components.error');
    if(isset($schema['enum'])&&(!is_array($schema['enum'])||!array_is_list($schema['enum'])||$schema['enum']===[]||count($schema['enum'])>30))throw new HttpError(422,'Invalid component choices.','components.error');
    if(array_key_exists('default',$schema))Schema::validate($schema['default'],$schema);
  }
  /** Validate service declarations without executing plugin PHP; receive manifest, return definitions or 422 on malformed metadata. */
  public static function definitions(array $manifest): array {
    $services=$manifest['contentServices']??[];
    if(!is_array($services)||(array_is_list($services)&&$services!==[])||count($services)>20)throw new HttpError(422,'Invalid content services.','components.error');
    foreach($services as $name=>$service){
      if(!is_string($name)||!Plugins::identifier($name)||!is_array($service)||count($service)!==6||array_diff(array_keys($service),['api','description','context','parameters','result','formats'])!==[]||($service['api']??null)!==1||!is_string($service['description']??null)||trim($service['description'])===''||strlen($service['description'])>500||preg_match('/[\x00-\x1F\x7F<>]/',$service['description'])||!in_array($service['context']??null,['block','text'],true)||!is_array($service['parameters']??null)||!is_array($service['result']??null)||($service['parameters']['type']??null)!=='object'||($service['result']['type']??null)!=='object')throw new HttpError(422,'Invalid content service.','components.error');
      self::schema($service['parameters']);self::schema($service['result']);
      foreach($service['parameters']['properties'] as $key=>$field)if($key==='layout'||!in_array($field['type'],['string','integer','boolean'],true))throw new HttpError(422,'Unsupported marker parameter.','components.error');
      if(!is_array($service['formats'])||!array_is_list($service['formats'])||$service['formats']===[]||count($service['formats'])>10||count(array_unique($service['formats'],SORT_REGULAR))!==count($service['formats']))throw new HttpError(422,'Invalid component formats.','components.error');
      foreach($service['formats'] as $format)if(!is_string($format)||!Plugins::identifier($format))throw new HttpError(422,'Invalid component format.','components.error');
      if($service['context']==='text'&&(($service['result']['properties']['value']['type']??null)!=='string'||!in_array('value',$service['result']['required']??[],true)||$service['formats']!==['text']))throw new HttpError(422,'Invalid text component.','components.error');
    }
    return $services;
  }
  /** Validate registered template formats as partial identifiers; receive manifest, return mappings or 422; no plugin or user path is executed. */
  public static function layouts(array $manifest): array {
    $layouts=$manifest['componentLayouts']??[];
    if(!is_array($layouts)||(array_is_list($layouts)&&$layouts!==[])||count($layouts)>50)throw new HttpError(422,'Invalid component layouts.','components.error');
    foreach($layouts as $code=>$formats){
      if(!is_string($code)||!preg_match('/^[a-z][a-z0-9-]{0,63}:[a-z][a-z0-9-]{0,63}$/D',$code)||!is_array($formats)||$formats===[]||array_is_list($formats)||count($formats)>10)throw new HttpError(422,'Invalid component binding.','components.error');
      foreach($formats as $format=>$partial)if(!is_string($format)||!Plugins::identifier($format)||!is_string($partial)||!isset($manifest['partials'][$partial]))throw new HttpError(422,'Invalid component partial.','components.error');
    }
    return $layouts;
  }
  /** Parse one complete marker with unique double-quoted scalar parameters; return null for plain text, normalize legacy Slides, reject malformed syntax/length with 422. */
  public static function parse(string $text): ?array {
    $text=trim($text);if(!str_starts_with($text,'{{')||!preg_match('/^\{\{[^{}\r\n]*:/',$text))return null;
    $closing=strpos($text,'}}');if($closing!==false&&$closing!==strlen($text)-2)return null;
    if(strlen($text)>2048||!preg_match('/^\{\{([a-z][a-z0-9-]{0,63}):([a-z][a-z0-9-]{0,63})((?: [a-z][a-zA-Z0-9]{0,47}="[^"{}\r\n]{0,512}")*)\}\}$/D',$text,$match))throw new HttpError(422,'Invalid component marker.','components.error');
    $parameters=[];preg_match_all('/ ([a-z][a-zA-Z0-9]{0,47})="([^"{}\r\n]{0,512})"/',$match[3],$pairs,PREG_SET_ORDER);
    foreach($pairs as $pair){if(isset($parameters[$pair[1]])||count($parameters)>=10)throw new HttpError(422,'Duplicate or excessive component parameter.','components.error');$parameters[$pair[1]]=$pair[2];}
    if($match[1]==='slides'&&$match[2]!=='location'&&$parameters===[])return ['plugin'=>'slides','service'=>'location','parameters'=>['key'=>$match[2]],'legacy'=>true];
    return ['plugin'=>$match[1],'service'=>$match[2],'parameters'=>$parameters,'legacy'=>false];
  }
  /** Validate/coerce marker parameters and registered layout; receive parsed marker/definition, return typed parameters/format or 422 with no provider call. */
  private static function parameters(array $marker,array $definition): array {
    $input=$marker['parameters'];$format=$input['layout']??$definition['formats'][0];unset($input['layout']);
    if(!in_array($format,$definition['formats'],true))throw new HttpError(422,'Unsupported component format.','components.error');
    $result=[];
    foreach($definition['parameters']['properties'] as $key=>$field){
      if(!array_key_exists($key,$input)){if(array_key_exists('default',$field))$result[$key]=$field['default'];continue;}
      $value=$input[$key];
      if($field['type']==='integer'){
        if(!preg_match('/^-?(?:0|[1-9][0-9]{0,8})$/D',$value))throw new HttpError(422,'Invalid component integer.','components.error');$value=(int)$value;
      }elseif($field['type']==='boolean'){
        if(!in_array($value,['true','false'],true))throw new HttpError(422,'Invalid component boolean.','components.error');$value=$value==='true';
      }
      $result[$key]=$value;unset($input[$key]);
    }
    if($input!==[])throw new HttpError(422,'Unknown component parameter.','components.error');
    Schema::validate($result,$definition['parameters']);return [$result,$format];
  }
  /** Lazily obtain the manifest-only registry; return registry without mutations. */
  private function registry(): Plugins {return $this->plugins??=new Plugins($this->context->store);}
  /** List enabled declared services with template-supported formats; return metadata for the insertion UI without executing PHP providers. */
  public function catalog(): array {
    $result=[];$layouts=self::layouts($this->context->store->templates->get($this->context->template));
    foreach($this->registry()->all() as $id=>$manifest)if($this->registry()->state($id)['enabled'])foreach(self::definitions($manifest) as $service=>$definition){
      $formats=$definition['context']==='text'?['text']:array_values(array_intersect($definition['formats'],array_keys($layouts[$id.':'.$service]??[])));
      $result[$id.':'.$service]=['plugin'=>$id,'service'=>$service,'name'=>$manifest['name'],'description'=>$definition['description'],'parameters'=>$definition['parameters'],'formats'=>$formats,'context'=>$definition['context']];
    }
    return $result;
  }
  /** Enrich insertion metadata with optional provider choices only after authentication; no arguments, return bounded choices/diagnostics and isolate optional choice failures without writes. */
  public function editorCatalog(): array {
    if(!Security::authenticated($this->context->store->config))throw new HttpError(403,'Authentication required.','error.authentication');$catalog=$this->catalog();
    foreach($catalog as &$entry){
      try{
        $provider=$this->registry()->instance($entry['plugin']);if(!$provider instanceof ContentOptionsInterface)continue;$choices=$provider->contentOptions($entry['service'],$this->context);
        if(count($choices)>10)throw new \UnexpectedValueException('Too many component choices.');
        foreach($choices as $parameter=>$items){
          if(!is_string($parameter)||!isset($entry['parameters']['properties'][$parameter])||!is_array($items)||!array_is_list($items)||count($items)>100)throw new \UnexpectedValueException('Invalid parameter choices.');
          foreach($items as $item)if(!is_array($item)||count($item)!==2||!is_string($item['value']??null)||!is_string($item['label']??null)||strlen($item['value'])>512||strlen($item['label'])>500||preg_match('/[\x00-\x1F\x7F<>]/',$item['value'].$item['label']))throw new \UnexpectedValueException('Invalid component choice.');
        }
        $entry['choices']=$choices;
      }catch(\Throwable $error){$entry['choices']=[];$entry['choiceWarning']=true;error_log('DBLESS-CMS component choices '.$entry['plugin'].':'.$entry['service'].' '.$error::class);}
    }
    unset($entry);return $catalog;
  }
  /** Validate known marker parameters even for inactive providers; return diagnostic code for unknown/disabled/unsupported references, never load a provider. */
  public function inspect(string $text): ?string {
    $marker=self::parse($text);if($marker===null)return null;$id=$marker['plugin'];$service=$marker['service'];$manifest=$this->registry()->all()[$id]??null;
    if($manifest===null||!isset($manifest['contentServices'][$service]))return $id.':'.$service.' (unavailable)';
    if($marker['legacy']){
      if(!$this->registry()->state($id)['enabled'])return 'slides:location (inactive)';
      $template=$this->context->store->templates->get($this->context->template);return isset(Slides::locations($template)[$marker['parameters']['key']])?null:'slides:location (layout)';
    }
    $definition=$manifest['contentServices'][$service];[, $format]=self::parameters($marker,$definition);
    if(!$this->registry()->state($id)['enabled'])return $id.':'.$service.' (inactive)';
    if($definition['context']==='block'&&!isset(self::layouts($this->context->store->templates->get($this->context->template))[$id.':'.$service][$format]))return $id.':'.$service.' (layout)';
    return null;
  }
  /** Collect editorial marker strings from page-owned body fields; receive document, return list, no interpretation of widget/provider values. */
  public static function markers(array $document): array {
    $result=[];
    foreach(array_merge($document['content']['sections']??[],$document['homepage']['sections']??[]) as $section)foreach($section['body']??[] as $text){
      if(!is_string($text))throw new HttpError(422,'Invalid component body.','components.error');
      if(preg_match('/\{\{[^{}\r\n]*:/',$text)){
        if(self::parse($text)!==null)$result[]=['marker'=>$text,'inline'=>false];
        else {preg_match_all('/\{\{[^{}\r\n]{1,2048}\}\}/',$text,$matches);if($matches[0]===[])throw new HttpError(422,'Invalid inline marker.','components.error');foreach($matches[0] as $token){if(self::parse($token)!==null)$result[]=['marker'=>$token,'inline'=>true];}if(preg_match('/\{\{[^{}\r\n]*:/',str_replace($matches[0],'',$text)))throw new HttpError(422,'Invalid inline marker.','components.error');}
      }
    }
    return $result;
  }
  /** Validate page-wide limits and known parameters without fetching dynamic output; receive document, return diagnostic list or throw 422, no writes. */
  public function validate(array $document): array {
    $markers=self::markers($document);if(count($markers)+count($document['content']['blocks']??[])>20)throw new HttpError(422,'Too many components.','components.error');$warnings=[];
    foreach($markers as $entry){
      $marker=$entry['marker'];$parsed=self::parse($marker);$definition=$this->registry()->all()[$parsed['plugin']]['contentServices'][$parsed['service']]??null;
      if($entry['inline']&&$definition!==null&&$definition['context']!=='text')throw new HttpError(422,'Block marker requires its own paragraph.','components.error');
      if(($warning=$this->inspect($marker))!==null)$warnings[]=$warning;
    }
    return array_values(array_unique($warnings));
  }
  /** Resolve one allowlisted read-only provider and render a trusted template partial; receive marker, return safe HTML/text or empty with warning; isolate optional provider errors in bounded logs. */
  public function render(string $text,bool $inline=false): string {
    $marker=self::parse($text);if($marker===null)return e($text);
    if(++$this->resolved>20){$this->warnings[]='limit';return '';}
    if($marker['legacy'])return $this->legacy($marker['parameters']['key']);
    $code=$marker['plugin'].':'.$marker['service'];
    try{
      if(($warning=$this->inspect($text))!==null){$this->warnings[]=$warning;return '';}
      $definition=$this->registry()->get($marker['plugin'])['contentServices'][$marker['service']];
      if($inline&&$definition['context']!=='text')throw new HttpError(422,'Block marker requires its own paragraph.','components.error');
      [$parameters,$format]=self::parameters($marker,$definition);$cacheKey=$code.':'.json_encode($parameters,JSON_THROW_ON_ERROR);
      if(!array_key_exists($cacheKey,$this->data)){
        $provider=$this->registry()->instance($marker['plugin']);if(!$provider instanceof ContentProviderInterface)throw new \UnexpectedValueException('Provider interface missing.');
        $value=$provider->content($marker['service'],$parameters,$this->context);if(strlen(json_encode($value,JSON_THROW_ON_ERROR))>131072)throw new \UnexpectedValueException('Component output too large.');Schema::validate($value,$definition['result']);$this->data[$cacheKey]=Schema::sanitize($value);
      }
      $componentData=$this->data[$cacheKey];$componentParameters=$parameters;
      if($definition['context']==='text')return e($componentData['value']);
      if(($componentData['items']??null)===[])return '';
      $store=$this->context->store;$id=$this->context->template;$base_url=$this->context->base;$manifest=$store->templates->get($id);$partial=self::layouts($manifest)[$code][$format];
      $slides=new Slides($store,$id);$slideData=$componentData['slides']??null;
      ob_start();try{require $store->templates->path($id,'partials',$manifest['partials'][$partial]);return (string)ob_get_clean();}catch(\Throwable $error){ob_end_clean();throw $error;}
    }catch(\Throwable $error){$this->warnings[]=$code.' (unavailable)';error_log('DBLESS-CMS component '.$code.' '.$error::class);return '';}
  }
  /** Preserve the preexisting Slides marker renderer without executing its PHP entrypoint; receive location key, return trusted template HTML or empty, propagate real catalog/storage errors instead of hiding corrupt legacy state. */
  private function legacy(string $key): string {
    $store=$this->context->store;$id=$this->context->template;$base_url=$this->context->base;$manifest=$store->templates->get($id);$slides=new Slides($store,$id);$slideData=$slides->location($key);if($slideData===null)return '';
    /** Resolve already validated slide media against this public base; receive reference, return a URL without network access or writes. */
    $mediaUrl=static fn(string $reference):string=>preg_match('~^https?://~i',$reference)?$reference:($reference===''?'':$base_url.'/'.ltrim($reference,'/'));
    /** Resolve already validated slide links for the legacy partial; receive reference, return same-site or allowed absolute address without effects. */
    $pageUrl=static fn(string $reference):string=>str_starts_with($reference,'/')?$base_url.$reference:$reference;
    ob_start();try{require $store->templates->path($id,'partials',$manifest['partials']['slides']);return (string)ob_get_clean();}catch(\Throwable $error){ob_end_clean();throw $error;}
  }
  /** Render a paragraph or full block marker; receive plain body text, return escaped nonrecursive HTML, permitting inline text providers only. */
  public function paragraph(string $text): string {
    if(self::parse($text)!==null)return $this->render($text);
    $parts=preg_split('/(\{\{[^{}\r\n]{1,2048}\}\})/',$text,-1,PREG_SPLIT_DELIM_CAPTURE);$html='';
    foreach($parts as $part)$html.=str_starts_with($part,'{{')?$this->render($part,true):e($part);
    return '<p>'.$html.'</p>';
  }
  /** Return unique resolver diagnostics after rendering; no arguments, no writes or public disclosure of exception contents. */
  public function warnings(): array {return array_values(array_unique($this->warnings));}
}
