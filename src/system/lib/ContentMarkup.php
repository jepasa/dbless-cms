<?php
/** Shared authored HTML contract: DOM allowlists, validated URL attributes, preserved source formatting and bounded data-only Mustache. */
declare(strict_types=1);
namespace DBLESSCMS;
final class ContentMarkup {
  private static ?\WeakMap $budgets=null;
  private static ?\WeakMap $diagnostics=null;
  /** Return the canonical editorial allowlist for PHP validation and the shared client editor; no parameters/I/O or executable options. */
  public static function policy(): array {
    return ['tags'=>explode(' ','section main article aside header footer nav div span p a h1 h2 h3 h4 h5 h6 em strong b i small code pre ul ol li dl dt dd img video source figure figcaption blockquote cite abbr time br hr table thead tbody tfoot tr th td caption button details summary'),'global'=>['id','class','title','role','lang','dir','tabindex','hidden'],'specific'=>['a'=>['href','rel','target'],'img'=>['src','alt','width','height','loading','decoding','fetchpriority','referrerpolicy'],'video'=>['src','poster','controls','preload','muted','loop','playsinline','width','height'],'source'=>['src','type'],'time'=>['datetime'],'button'=>['type','disabled'],'details'=>['open'],'td'=>['colspan','rowspan'],'th'=>['colspan','rowspan','scope']]];
  }
  /** Return the portable optional markup schema for page/other plugin fields; no parameters/I/O, structured metadata only. */
  public static function schema(): array {
    return ['type'=>'object','additionalProperties'=>false,'required'=>['html'],'properties'=>['html'=>['type'=>'string','maxLength'=>131072],'bindings'=>['type'=>'array','maxItems'=>20,'items'=>['type'=>'object','additionalProperties'=>false,'required'=>['plugin','service','alias','parameters'],'properties'=>['plugin'=>['type'=>'string','maxLength'=>64],'service'=>['type'=>'string','maxLength'=>64],'alias'=>['type'=>'string','maxLength'=>100],'parameters'=>['type'=>'object']]]]]];
  }
  /** Validate an addressed link/image URL, including a template placeholder before interpolation; receive URL/attribute/template flag, throw 422 on active schemes, control characters or traversal, never fetch. */
  private static function url(string $value,string $attribute,bool $template): void {
    if($template)$value=preg_replace('/\{\{\s*[a-zA-Z][a-zA-Z0-9_.-]*\s*\}\}/','safe',$value);
    $decoded=rawurldecode(rawurldecode($value));
    if(preg_match('~/cms-admin(?:/|[?]|$)~i',$decoded)||preg_match('~(?:^|/)system(?:/|$)~i',$decoded))throw new HttpError(422,'Private media cannot be embedded in public content.','markup.error');
    if(preg_match('/[\x00-\x20\x7F]/',$value)||preg_match('/[\x00-\x1F\x7F\\\\]/',$decoded)||str_starts_with($decoded,'//')||str_contains($decoded,'..'))throw new HttpError(422,'Unsafe markup URL.','markup.error');
    if(str_contains($decoded,':')&&!preg_match($attribute==='href'?'~^(?:https?://|mailto:|tel:)~i':'~^https?://~i',$decoded))throw new HttpError(422,'Unsupported markup URL.','markup.error');
    if(preg_match('~^https?://~i',$decoded)&&(filter_var($value,FILTER_VALIDATE_URL)===false||parse_url($value,PHP_URL_USER)!==null||parse_url($value,PHP_URL_PASS)!==null))throw new HttpError(422,'Invalid markup URL.','markup.error');
  }
  /** Inspect permitted HTML without rewriting human formatting; receive fragment/template flag, return original safe bytes or 422/503, no external entity loading or network access. */
  public static function html(string $html,bool $template=true): string {
    if(($template&&substr_count($html,"\n")>4096)||strlen($html)>524288||preg_match('//u',$html)!==1||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$html)||str_contains($html,'<?')||preg_match('~</?\s*(?:html|head|body)(?:\s|/?>)~i',$html)||preg_match('/<!\s*(?:doctype|entity|\[cdata)/i',$html))throw new HttpError(422,'Unsafe markup.','markup.error');
    if(!class_exists(\DOMDocument::class))throw new HttpError(503,'PHP ext-dom is required for authored HTML.','markup.unavailableCore');
    $policy=self::policy();$tags=$policy['tags'];$global=$policy['global'];$specific=$policy['specific'];
    $previous=libxml_use_internal_errors(true);$document=new \DOMDocument('1.0','UTF-8');
    try{$loaded=$document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);if(!$loaded)throw new HttpError(422,'Invalid HTML.','markup.error');
      foreach($document->getElementsByTagName('*') as $node){$tag=strtolower($node->tagName);if(in_array($tag,['html','body'],true))continue;if(!in_array($tag,$tags,true))throw new HttpError(422,'Unsupported HTML element: '.$tag,'markup.error');
        foreach($node->attributes as $attribute){$key=strtolower($attribute->name);if(str_starts_with($key,'data-dbless-'))throw new HttpError(422,'Reserved editor attribute.','markup.error');if(!in_array($key,$global,true)&&!in_array($key,$specific[$tag]??[],true)&&!preg_match('/^(?:aria|data)-[a-z][a-z0-9-]*$/D',$key))throw new HttpError(422,'Unsupported HTML attribute.','markup.error');if(in_array($key,['href','src','poster'],true))self::url($attribute->value,$key,$template);if($key==='target'&&!in_array($attribute->value,['_self','_blank'],true))throw new HttpError(422,'Invalid HTML target.','markup.error');}
      }
      if(!$template){foreach($document->getElementsByTagName('a') as $link)if(preg_match('~^https?://~i',$link->getAttribute('href'))){$rel=preg_split('/\s+/',trim($link->getAttribute('rel')),-1,PREG_SPLIT_NO_EMPTY);$link->setAttribute('rel',implode(' ',array_unique([...$rel,'noopener','noreferrer'])));}foreach($document->getElementsByTagName('img') as $image)$image->setAttribute('referrerpolicy','no-referrer');$html='';$body=$document->getElementsByTagName('body')->item(0);if($body!==null)foreach($body->childNodes as $child)$html.=$document->saveHTML($child);}
    }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    return $html;
  }
  /** Validate one authored template and binding declarations; receive decoded definition, return original safe HTML/bindings or 422 without provider execution or writes. */
  public static function definition(array $definition): array {$definition += ['bindings'=>[]];Schema::validate($definition,self::schema());$template=TemplateMarkup::prepare($definition['html']);$forms=ContentForms::prepare($template['html']);$prepared=ContentLegacyComponents::prepare($forms['html']);Mustache::parse($prepared['html']);self::html($prepared['html']);Components::bindingShape($definition['bindings']);return $definition;}
  /** Return private request diagnostics for an authenticated preview caller; receives store, returns bounded provider identifiers only, no content or persistence. */
  public static function diagnostics(JsonStore $store): array {return self::$diagnostics[$store]??[];}
  /** Resolve declared public data and render/validate HTML; receive definition/context, return safe HTML or typed contract error, isolate provider errors through Components, never mutate content. */
  public static function render(array $definition,ContentContext $context): string {
    $definition=self::definition($definition);$references=new ContentReferences($context);$components=new Components($context);$environment=$components->environment($definition['bindings']);$data=['cms'=>['base'=>$context->base,'sources'=>$components->states()]]+$environment;self::$diagnostics??=new \WeakMap();self::$diagnostics[$context->store]=array_values(array_unique([...(self::$diagnostics[$context->store]??[]),...$components->warnings()]));$template=TemplateMarkup::prepare($definition['html']);$automatic=$references->prepare($template['html'],array_keys($environment));$prepared=ContentForms::prepare($automatic['html']);$legacy=ContentLegacyComponents::prepare($prepared['html']);$html=self::html(Mustache::render($legacy['html'],$data,$references->value(...)),false);$html=ContentLegacyComponents::mount($html,$legacy['slots'],$context);$html=$references->mount($html,$automatic['slots']);self::$diagnostics[$context->store]=array_values(array_unique([...self::$diagnostics[$context->store],...$references->warnings()]));$html=ContentForms::mount($html,$prepared['slots'],$context);$html=TemplateMarkup::mount($html,$template['slots'],$context);self::$budgets??=new \WeakMap();$bytes=(self::$budgets[$context->store]??0)+strlen($html);if($bytes>1048576)throw new HttpError(422,'Request markup budget exceeded.','markup.error');self::$budgets[$context->store]=$bytes;return $html;
  }
}
