<?php
/** Bounded form-marker composition: reserved random slots are filled only after authored HTML/Mustache validation; no raw data HTML or widening of the editorial allowlist. */
declare(strict_types=1);
namespace DBLESSCMS;
final class ContentForms {
  private static ?\WeakMap $budgets=null;
  private const PATTERN='/\{\{\s*([a-z][a-z0-9-]{0,63}):form\s+id="([a-z][a-z0-9-]{0,47})"\s*\}\}/';
  /** Validate optional static form-service metadata without plugin execution; receive manifest, return void or 422, no I/O. */
  public static function definitions(array $manifest): void {
    if(!isset($manifest['formServices']))return;$services=$manifest['formServices'];if(!is_array($services)||array_keys($services)!==['form']||!is_array($services['form'])||count($services['form'])!==2||array_diff(array_keys($services['form']),['description','route'])||!is_string($services['form']['route']??null)||!Plugins::identifier($services['form']['route'])||!is_string($services['form']['description'])||strlen($services['form']['description'])>500||strip_tags($services['form']['description'])!==$services['form']['description'])throw new HttpError(422,'Invalid form service.','markup.error');
    $route=$manifest['routes'][$services['form']['route']]??null;if(!is_array($route)||($route['visibility']??'private')!=='public'||!in_array('POST',$route['methods']??[],true))throw new HttpError(422,'A declared public POST route is required for a form service.','markup.error');
  }
  /** Parse one exact marker into plugin/ID, or return null for ordinary text; no dispatch from user methods or filesystem paths. */
  public static function parse(string $text): ?array {if(!preg_match('~\A'.substr(self::PATTERN,1,-1).'\z~D',$text,$match))return null;return ['plugin'=>$match[1],'id'=>$match[2]];}
  /** Replace exact form markers with random opaque slots before the data-only Mustache parser; receive source, return source/slots, reject forged reserved attributes or excessive markers. */
  public static function prepare(string $source): array {
    if(preg_match('/data-cms-form-slot\s*=/i',$source))throw new HttpError(422,'Reserved form slot attribute.','markup.error');$slots=[];$html=preg_replace_callback(self::PATTERN,static function(array $match)use(&$slots):string{if(count($slots)>=10)throw new HttpError(422,'Form marker budget exceeded.','markup.error');$token=bin2hex(random_bytes(16));$slots[$token]=['plugin'=>$match[1],'id'=>$match[2]];return '<div data-cms-form-slot="'.$token.'"></div>';},$source);return ['html'=>$html,'slots'=>$slots];
  }
  /** Inspect activation/declaration only, returning a diagnostic code without executing provider or issuing tokens; receive marker/context. */
  public static function inspect(string $text,ContentContext $context): ?string {
    $marker=self::parse($text);if($marker===null)return 'form syntax';$registry=new Plugins($context->store);$manifest=$registry->all()[$marker['plugin']]??null;
    if($manifest===null||!isset($manifest['formServices']['form']))return 'form provider unavailable';if(!$registry->state($marker['plugin'])['enabled'])return 'form provider disabled';return null;
  }
  /** Validate a trusted plugin's generated form fragment against a narrow interactive contract; receive bytes/expected endpoint, return DOM body or 422, no external entities or network. */
  private static function fragment(string $html,string $action): \DOMElement {
    if(strlen($html)>131072||!preg_match('//u',$html)||preg_match('~<!|<\?|<\s*(?:html|head|body)\b~i',$html))throw new HttpError(422,'Invalid interactive fragment.','markup.error');
    $dom=new \DOMDocument('1.0','UTF-8');$previous=libxml_use_internal_errors(true);try{$dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    $tags=['section','div','p','h2','h3','form','label','input','textarea','select','option','button','fieldset','legend','small','span','a','ul','li'];$global=['id','class','role','hidden','tabindex','title'];$attributes=['form'=>['action','method'],'input'=>['type','name','value','required','maxlength','autocomplete','checked'],'textarea'=>['name','required','maxlength','rows','autocomplete'],'select'=>['name','required'],'option'=>['value','selected'],'button'=>['type','disabled'],'label'=>['for'],'a'=>['href']];$form=$dom->getElementsByTagName('form');
    if($form->length!==1||strtolower($form->item(0)->getAttribute('method'))!=='post'||$form->item(0)->getAttribute('action')!==$action)throw new HttpError(422,'Invalid interactive form endpoint.','markup.error');
    $csrf=0;foreach($dom->getElementsByTagName('*') as $node){$tag=strtolower($node->tagName);if(in_array($tag,['html','body'],true))continue;if(!in_array($tag,$tags,true))throw new HttpError(422,'Unsupported interactive element.','markup.error');foreach($node->attributes as $attribute){$name=strtolower($attribute->name);if(!in_array($name,$global,true)&&!in_array($name,$attributes[$tag]??[],true)&&!preg_match('/^(?:aria|data)-[a-z][a-z0-9-]*$/D',$name))throw new HttpError(422,'Unsupported interactive attribute.','markup.error');if($name==='href'&&(preg_match('/[\x00-\x20\x7F\\\\]/',$attribute->value)||str_starts_with($attribute->value,'//')||!str_starts_with($attribute->value,'/')&&!preg_match('~^https?://~',$attribute->value)))throw new HttpError(422,'Unsafe interactive link.','markup.error');}
      if($tag==='input'){if(!in_array($node->getAttribute('type'),['text','email','tel','hidden','checkbox','radio'],true))throw new HttpError(422,'Unsupported interactive input.','markup.error');if($node->getAttribute('name')==='csrf_token'){if($node->getAttribute('type')!=='hidden'||!hash_equals(Security::token(),$node->getAttribute('value')))throw new HttpError(422,'Invalid interactive CSRF.','markup.error');$csrf++;}}
    }if($csrf!==1)throw new HttpError(422,'Interactive CSRF is required.','markup.error');return $dom->getElementsByTagName('body')->item(0);
  }
  /** Render exactly one declared trusted published form with current request tokens; receive parsed marker/context, return validated fragment or visible unavailable fallback for optional missing provider. */
  public static function render(array $marker,ContentContext $context): string {
    if($context->preview||preg_match('~/cms-admin(?:/|$)~',$_SERVER['SCRIPT_NAME']??''))return '<p class="cms-form-unavailable" role="status">Interactive forms are disabled in private preview.</p>';
    $registry=new Plugins($context->store);$manifest=$registry->all()[$marker['plugin']]??null;if($manifest===null||!isset($manifest['formServices']['form'])||!$registry->state($marker['plugin'])['enabled'])return '<p class="cms-form-unavailable" role="status">Form unavailable.</p>';
    self::$budgets??=new \WeakMap();$used=self::$budgets[$context->store]??0;if($used>=10)throw new HttpError(422,'Request form budget exceeded.','markup.error');self::$budgets[$context->store]=$used+1;
    $plugin=$registry->instance($marker['plugin']);if(!$plugin instanceof ContentFormProviderInterface)throw new HttpError(503,'Form renderer contract unavailable.','markup.error');
    try{$fragment=$plugin->renderForm($marker['id'],$context);}catch(HttpError $error){if($error->status===404)return '<p class="cms-form-unavailable" role="status">Form unavailable.</p>';throw $error;}
    $route=$manifest['formServices']['form']['route'];$action=$context->base.'/plugins/'.$marker['plugin'].'/'.$route;$body=self::fragment($fragment,$action);$html='';foreach($body->childNodes as $node)$html.=$body->ownerDocument->saveHTML($node);return '<div data-cms-form-provider="'.e($marker['plugin']).'">'.$html.'</div>';
  }
  /** Mount generated fragments after editorial validation; receive safe HTML/slots/context, return composed HTML or 422 for missing/duplicate/unsafe nesting, never caches a rendered form. */
  public static function mount(string $html,array $slots,ContentContext $context): string {
    if($slots===[])return $html;$dom=new \DOMDocument('1.0','UTF-8');$previous=libxml_use_internal_errors(true);try{$dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xpath=new \DOMXPath($dom);
      $mounted=0;foreach($slots as $token=>$marker){$nodes=$xpath->query('//*[@data-cms-form-slot="'.$token.'"]');$mounted+=$nodes->length;if($mounted>10)throw new HttpError(422,'Repeated interactive form budget exceeded.','markup.error');foreach(iterator_to_array($nodes) as $node){foreach(['a','button','form','label','pre','code'] as $tag)if($xpath->query('ancestor::'.$tag,$node)->length)throw new HttpError(422,'Form cannot be nested inside an interactive element.','markup.error');$fragment=self::render($marker,$context);$source=new \DOMDocument('1.0','UTF-8');$source->loadHTML('<?xml encoding="UTF-8"><html><body>'.$fragment.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);foreach(iterator_to_array($source->getElementsByTagName('body')->item(0)->childNodes) as $child)$node->parentNode->insertBefore($dom->importNode($child,true),$node);$node->parentNode->removeChild($node);}}
      $result='';foreach($dom->getElementsByTagName('body')->item(0)->childNodes as $node)$result.=$dom->saveHTML($node);return $result;
    }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
  }
  /** Discover enabled form services and safe IDs for an authenticated editor; receive context, return metadata, never hardcode plugin names or expose private definitions. */
  public static function catalog(ContentContext $context): array {
    if(!Security::authenticated($context->store->config))throw new HttpError(403,'Authentication required.','error.authentication');$registry=new Plugins($context->store);$result=[];
    foreach($registry->all() as $id=>$manifest){if(!isset($manifest['formServices']['form'])||!$registry->state($id)['enabled'])continue;$provider=$registry->instance($id);$choices=$provider instanceof ContentOptionsInterface?$provider->contentOptions('form',$context)['id']??[]:[];if(!is_array($choices)||count($choices)>100)throw new HttpError(422,'Invalid form choices.','markup.error');foreach($choices as $choice)if(!is_array($choice)||!is_string($choice['value']??null)||!preg_match('/^[a-z][a-z0-9-]{0,47}$/D',$choice['value'])||!is_string($choice['label']??null)||strlen($choice['label'])>500)throw new HttpError(422,'Invalid form choice.','markup.error');$result[]=['plugin'=>$id,'description'=>$manifest['formServices']['form']['description'],'choices'=>$choices];}return $result;
  }
  /** Load addressed local public form enhancement only into complete public HTML containing a generated form; receive HTML/base, return decorated or unchanged bytes, never includes drafts or data APIs. */
  public static function assets(string $html,string $base,JsonStore $store): string {
    if(str_contains($html,'data-cms-form-assets')||!preg_match_all('/data-cms-form-provider="([a-z][a-z0-9-]{0,63})"/',$html,$matches)||!preg_match('~\A\s*(?:(?:<!--[\s\S]*?-->|<!doctype\s+html[^>]*>)\s*)*<html\b~i',$html)||!preg_match('~</head\s*>~i',$html))return $html;
    $registry=new Plugins($store);$manifests=$registry->all();$assets='';foreach(array_unique($matches[1]) as $id){$manifest=$manifests[$id]??null;if($manifest===null||!isset($manifest['formServices'])||!$registry->state($id)['enabled'])continue;foreach(['css','js'] as $kind){$file=$manifest['assets'][$kind]??null;if(!is_string($file)||$file==='')continue;$url=asset('plugins/'.$id.'/'.$file,$base);$assets.=$kind==='css'?'<link data-cms-form-assets="css" rel="stylesheet" href="'.e($url).'">':'<script data-cms-form-assets="js" defer src="'.e($url).'"></script>';}}
    return preg_replace_callback('~</head\s*>~i',static fn(array $match):string=>$assets.'</head>',$html,1);
  }
}
