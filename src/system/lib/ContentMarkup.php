<?php
/** Shared authored HTML contract: DOM allowlists, validated URL attributes, preserved source formatting and bounded data-only Mustache. */
declare(strict_types=1);
namespace DBLESSCMS;
final class ContentMarkup {
  private static ?\WeakMap $budgets=null;
  /** Return the portable optional markup schema for page/other plugin fields; no parameters/I/O, structured metadata only. */
  public static function schema(): array {
    return ['type'=>'object','additionalProperties'=>false,'required'=>['html','bindings'],'properties'=>['html'=>['type'=>'string','maxLength'=>131072],'bindings'=>['type'=>'array','maxItems'=>20,'items'=>['type'=>'object','additionalProperties'=>false,'required'=>['plugin','service','alias','parameters'],'properties'=>['plugin'=>['type'=>'string','maxLength'=>64],'service'=>['type'=>'string','maxLength'=>64],'alias'=>['type'=>'string','maxLength'=>100],'parameters'=>['type'=>'object']]]]]];
  }
  /** Validate an addressed link/image URL, including a template placeholder before interpolation; receive URL/attribute/template flag, throw 422 on active schemes, control characters or traversal, never fetch. */
  private static function url(string $value,string $attribute,bool $template): void {
    if($template)$value=preg_replace('/\{\{\s*[a-zA-Z][a-zA-Z0-9_.-]*\s*\}\}/','safe',$value);
    $decoded=rawurldecode(rawurldecode($value));
    if(preg_match('/[\x00-\x20\x7F]/',$value)||preg_match('/[\x00-\x1F\x7F\\\\]/',$decoded)||str_starts_with($decoded,'//')||str_contains($decoded,'..'))throw new HttpError(422,'Unsafe markup URL.','markup.error');
    if(str_contains($decoded,':')&&!preg_match($attribute==='href'?'~^(?:https?://|mailto:|tel:)~i':'~^https?://~i',$decoded))throw new HttpError(422,'Unsupported markup URL.','markup.error');
    if(preg_match('~^https?://~i',$decoded)&&(filter_var($value,FILTER_VALIDATE_URL)===false||parse_url($value,PHP_URL_USER)!==null||parse_url($value,PHP_URL_PASS)!==null))throw new HttpError(422,'Invalid markup URL.','markup.error');
  }
  /** Inspect permitted HTML without rewriting human formatting; receive fragment/template flag, return original safe bytes or 422/503, no external entity loading or network access. */
  public static function html(string $html,bool $template=true): string {
    if(($template&&substr_count($html,"\n")>4096)||strlen($html)>524288||preg_match('//u',$html)!==1||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$html)||str_contains($html,'<?')||preg_match('~</?\s*(?:html|head|body)(?:\s|/?>)~i',$html)||preg_match('/<!\s*(?:doctype|entity|\[cdata)/i',$html))throw new HttpError(422,'Unsafe markup.','markup.error');
    if(!class_exists(\DOMDocument::class))throw new HttpError(503,'PHP ext-dom is required for authored HTML.','markup.unavailableCore');
    $tags=explode(' ','section main article aside header footer nav div span p a h1 h2 h3 h4 h5 h6 em strong b i small code pre ul ol li dl dt dd img figure figcaption blockquote cite abbr time br hr table thead tbody tfoot tr th td caption button details summary');
    $global=['id','class','title','role','lang','dir','tabindex','hidden'];$specific=['a'=>['href','rel','target'],'img'=>['src','alt','width','height','loading','decoding'],'time'=>['datetime'],'button'=>['type','disabled'],'details'=>['open'],'td'=>['colspan','rowspan'],'th'=>['colspan','rowspan','scope']];
    $previous=libxml_use_internal_errors(true);$document=new \DOMDocument('1.0','UTF-8');
    try{$loaded=$document->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);if(!$loaded)throw new HttpError(422,'Invalid HTML.','markup.error');
      foreach($document->getElementsByTagName('*') as $node){$tag=strtolower($node->tagName);if(in_array($tag,['html','body'],true))continue;if(!in_array($tag,$tags,true))throw new HttpError(422,'Unsupported HTML element: '.$tag,'markup.error');
        foreach($node->attributes as $attribute){$key=strtolower($attribute->name);if(!in_array($key,$global,true)&&!in_array($key,$specific[$tag]??[],true)&&!preg_match('/^(?:aria|data)-[a-z][a-z0-9-]*$/D',$key))throw new HttpError(422,'Unsupported HTML attribute.','markup.error');if(in_array($key,['href','src'],true))self::url($attribute->value,$key,$template);if($key==='target'&&!in_array($attribute->value,['_self','_blank'],true))throw new HttpError(422,'Invalid HTML target.','markup.error');}
      }
      if(!$template){$html='';$body=$document->getElementsByTagName('body')->item(0);if($body!==null)foreach($body->childNodes as $child)$html.=$document->saveHTML($child);}
    }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    return $html;
  }
  /** Validate one authored template and binding declarations; receive decoded definition, return original safe HTML/bindings or 422 without provider execution or writes. */
  public static function definition(array $definition): array {Schema::validate($definition,self::schema());Mustache::parse($definition['html']);self::html($definition['html']);Components::bindingShape($definition['bindings']);return $definition;}
  /** Resolve declared public data and render/validate HTML; receive definition/context, return safe HTML or typed contract error, isolate provider errors through Components, never mutate content. */
  public static function render(array $definition,ContentContext $context): string {
    self::definition($definition);$components=new Components($context);$data=$components->environment($definition['bindings']);$html=self::html(Mustache::render($definition['html'],$data),false);self::$budgets??=new \WeakMap();$bytes=(self::$budgets[$context->store]??0)+strlen($html);if($bytes>1048576)throw new HttpError(422,'Request markup budget exceeded.','markup.error');self::$budgets[$context->store]=$bytes;return $html;
  }
}
