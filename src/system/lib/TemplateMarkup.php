<?php
/** Template-owned Homepage conversion and declared live fragments: editable HTML keeps native classes; trusted dynamic partials are mounted per request, never persisted as rendered output. */
declare(strict_types=1);
namespace DBLESSCMS;
final class TemplateMarkup {
  private const PATTERN='/\{\{template:fragment id="([a-z][a-z0-9-]{0,47})"(?: key="([a-z][a-z0-9-]{0,47})")?\}\}/';
  /** Validate optional manifest partial IDs; receive manifest, return void or 422, without execution, storage writes or user-controlled paths. */
  public static function manifest(array $manifest): void {
    if(isset($manifest['homepageMarkup'])&&(!is_string($manifest['homepageMarkup'])||!isset($manifest['partials'][$manifest['homepageMarkup']])))throw new HttpError(422,'Invalid Homepage markup adapter.','error.manifest');
    $fragments=$manifest['markupFragments']??[];if(!is_array($fragments)||count($fragments)>20||($fragments!==[]&&array_is_list($fragments)))throw new HttpError(422,'Invalid template fragments.','error.manifest');
    foreach($fragments as $key=>$partial)if(!is_string($key)||!preg_match('/^[a-z][a-z0-9-]{0,47}$/D',$key)||!is_string($partial)||!isset($manifest['partials'][$partial]))throw new HttpError(422,'Invalid template fragment.','error.manifest');
  }
  /** Render one explicitly registered trusted template partial in a public view scope; receive partial/context/conversion flag, return HTML or propagate errors with buffer cleanup, never writes content. */
  private static function partial(string $partial,ContentContext $context,bool $homepageConversion=false,string $fragmentKey=''): string {
    $store=$context->store;$id=$context->template;$base_url=$homepageConversion?'{{cms.base}}':$context->base;$manifest=$store->templates->get($id);$site=$store->read('site')['data'];$settings=$site['settings'];$page=$store->read('home',$id)['data'];$data=$page['content'];$currentPage='home';
    $siteContent=GlobalSettings::presentation($site,$store,$id)+['home'=>$data,'details'=>['home'=>$data]];$publicLocale=SiteLocale::resolve($settings);$templateSettings=isset($manifest['configuration'])?(new Extensions($store))->options($id)['values']:[];$navigation=new Navigation($store,$id,$base_url);$slides=new Slides($store,$id);$widgets=new Widgets($store,$id);$components=new Components(new ContentContext($store,$id,$base_url,'home',$context->preview));
    /** Resolve a validated public media reference without writes or network; receive reference, return addressed URL. */
    $mediaUrl=static fn(string $reference):string=>preg_match('~^https?://~i',$reference)?$reference:($reference===''?'':$base_url.'/'.ltrim($reference,'/'));
    /** Resolve a validated editorial target without writes; receive reference, return site-relative or absolute URL. */
    $pageUrl=static fn(string $reference):string=>str_starts_with($reference,'/')?$base_url.$reference:$reference;
    $csrf=$homepageConversion?'':Security::token();
    ob_start();try{require $store->templates->path($id,'partials',$manifest['partials'][$partial]);return (string)ob_get_clean();}catch(\Throwable $error){ob_end_clean();throw $error;}
  }
  /** Materialize native editorial markup through the active template's opt-in adapter; receive context, return validated HTML body, reject unsupported conversion without an empty replacement or session tokens. */
  public static function homepage(ContentContext $context): array {
    $manifest=$context->store->templates->get($context->template);$partial=$manifest['homepageMarkup']??null;if($partial===null)throw new HttpError(422,'This template needs a Homepage markup adapter.','homepage.conversionUnavailable');
    $body=['mode'=>'html','html'=>self::partial($partial,$context,true),'bindings'=>[],'source'=>['plugin'=>'','id'=>''],'publicationAt'=>''];Homepages::body($body);if(str_contains($body['html'],'csrf_token'))throw new HttpError(422,'Conversion cannot persist form tokens.','homepage.error.contract');return $body;
  }
  /** Reserve opaque fragment slots before ordinary HTML/Mustache parsing; receive source, return source/slots or 422 for forged attributes or excessive markers, no PHP execution. */
  public static function prepare(string $source): array {
    if(preg_match('/data-cms-template-slot\s*=/i',$source))throw new HttpError(422,'Reserved template slot.','markup.error');$slots=[];
    $html=preg_replace_callback(self::PATTERN,static function(array $match)use(&$slots):string{if(count($slots)>=20)throw new HttpError(422,'Template fragment budget exceeded.','markup.error');$token=bin2hex(random_bytes(16));$slots[$token]=['id'=>$match[1],'key'=>$match[2]??''];return '<div data-cms-template-slot="'.$token.'"></div>';},$source);return ['html'=>$html,'slots'=>$slots];
  }
  /** Render a declared public fragment from the current template only; receive key/context, return trusted per-request HTML or a visible missing-reference warning; private previews never run interactive fragments. */
  public static function fragment(string $key,ContentContext $context,string $fragmentKey=''): string {
    $manifest=$context->store->templates->get($context->template);$partial=$manifest['markupFragments'][$key]??null;
    if($partial===null)return '<p class="cms-template-unavailable" role="status">Template fragment unavailable: '.e($key).'.</p>';
    if($context->preview)return '<p class="cms-template-preview" role="status">Live template fragment: '.e($key).'.</p>';
    return self::partial($partial,$context,false,$fragmentKey);
  }
  /** Mount only known slots after authored markup validation; receive HTML/slots/context, return fragment-composed HTML, reject interactive nesting and loop amplification, no recursive interpretation or content writes. */
  public static function mount(string $html,array $slots,ContentContext $context): string {
    if($slots===[])return $html;$previous=libxml_use_internal_errors(true);$dom=new \DOMDocument('1.0','UTF-8');
    try{$dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xpath=new \DOMXPath($dom);$count=0;
      foreach($slots as $token=>$key){$nodes=$xpath->query('//*[@data-cms-template-slot="'.$token.'"]');$count+=$nodes->length;if($count>20)throw new HttpError(422,'Template fragment budget exceeded.','markup.error');foreach(iterator_to_array($nodes) as $node){foreach(['a','button','form','label','pre','code'] as $tag)if($xpath->query('ancestor::'.$tag,$node)->length)throw new HttpError(422,'Invalid template fragment nesting.','markup.error');$fragment=self::fragment($key['id'],$context,$key['key']);if(strlen($fragment)>524288)throw new HttpError(422,'Template fragment too large.','markup.error');$source=new \DOMDocument('1.0','UTF-8');$source->loadHTML('<?xml encoding="UTF-8"><html><body>'.$fragment.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);foreach(iterator_to_array($source->getElementsByTagName('body')->item(0)->childNodes) as $child)$node->parentNode->insertBefore($dom->importNode($child,true),$node);$node->parentNode->removeChild($node);}}
      $result='';foreach($dom->getElementsByTagName('body')->item(0)->childNodes as $node)$result.=$dom->saveHTML($node);return $result;
    }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
  }
}
