<?php
/** Core privacy configuration and consent-managed resources; published HTML only, never admin, private previews, submissions or rendered cache. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Privacy {
  public const DEFAULTS=['controller'=>'','registration'=>'','address'=>'','email'=>'','contactNotice'=>'','privacyPage'=>'','termsPage'=>'','refundPage'=>'','accessibilityPage'=>''];
  /** Receive private store; no I/O until the requested operation. */
  public function __construct(private readonly JsonStore $store) {}
  /** Validate additive site identity/legal references; receive values, return complete safe settings or 422, never writes. */
  public static function settings(array $values): array {
    if(array_diff(array_keys($values),array_keys(self::DEFAULTS)))throw new HttpError(422,'Unknown privacy field.','privacy.error');$values=array_replace(self::DEFAULTS,$values);
    foreach($values as $key=>$value)if(!is_string($value)||strlen($value)>($key==='contactNotice'?2000:500)||!preg_match('//u',$value)||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F<>]/u',$value))throw new HttpError(422,'Invalid privacy field.','privacy.error');
    foreach(['privacyPage','termsPage','refundPage','accessibilityPage'] as $key)if($values[$key]!==''&&!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$values[$key]))throw new HttpError(422,'Invalid legal page slug.','privacy.error');
    if($values['email']!==''&&!filter_var($values['email'],FILTER_VALIDATE_EMAIL))throw new HttpError(422,'Invalid privacy email.','privacy.error');return $values;
  }
  /** Return initial consent catalog with editable public copy; no arguments or effects; no trackers or banner enabled by default. */
  public static function defaults(): array {return ['schema_version'=>1,'consentDays'=>180,'notice'=>['title'=>'Your privacy choices','description'=>'Optional resources remain disabled until you choose. Necessary session features remain available.','accept'=>'Accept optional resources','reject'=>'Reject optional resources','manage'=>'Privacy preferences','save'=>'Save choices','analytics'=>'Analytics','marketing'=>'Marketing','media'=>'External media','privacy'=>'Privacy policy','terms'=>'Terms of use','refund'=>'Cancellation and refunds','accessibility'=>'Accessibility'],'resources'=>[]];}
  /** Validate an addressed public resource without network access; receive URL/type, return void or 422; remote scripts are explicitly trusted configuration. */
  private static function url(string $url,string $type): void {
    if(strlen($url)>2048||preg_match('/[\x00-\x20\x7F\\\\]/',$url)||str_starts_with($url,'//')||str_contains(rawurldecode(rawurldecode($url)),'..'))throw new HttpError(422,'Unsafe optional resource.','privacy.error');
    if(str_starts_with($url,'/')){if(!preg_match('~^/tracking/[A-Za-z0-9_-][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*$~D',$url)||$type==='embed'||$type==='image'&&!preg_match('/\.(?:png|jpe?g|webp|gif)$/iD',$url))throw new HttpError(422,'Local optional resources must use the dedicated /tracking/ namespace; local embeds are not supported.','privacy.error');}
    elseif(!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_SCHEME)!=='https'||parse_url($url,PHP_URL_USER)!==null||parse_url($url,PHP_URL_PASS)!==null)throw new HttpError(422,'Optional resources require HTTPS or a public-root path.','privacy.error');
    if($type==='script'&&str_starts_with($url,'/')&&!preg_match('/\.js$/D',$url))throw new HttpError(422,'Local script must be JavaScript.','privacy.error');
  }
  /** Validate closed consent/resource catalog; receive data, return unchanged safe data or 422 without I/O or automatic activation. */
  public static function validate(array $data): array {
    Schema::validate($data,['type'=>'object','additionalProperties'=>false,'required'=>['schema_version','consentDays','notice','resources'],'properties'=>['schema_version'=>['enum'=>[1]],'consentDays'=>['type'=>'integer','minimum'=>1,'maximum'=>365],'notice'=>['type'=>'object'],'resources'=>['type'=>'array','maxItems'=>20]]]);
    $copy=self::defaults()['notice'];if(count($data['notice'])!==count($copy)||array_diff(array_keys($data['notice']),array_keys($copy)))throw new HttpError(422,'Invalid privacy notice.','privacy.error');
    foreach($data['notice'] as $text)if(!is_string($text)||trim($text)===''||strlen($text)>2000||!preg_match('//u',$text)||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F<>]/',$text))throw new HttpError(422,'Invalid privacy text.','privacy.error');
    $seen=[];foreach($data['resources'] as $resource){Schema::validate($resource,['type'=>'object','additionalProperties'=>false,'required'=>['id','name','category','type','url','origins'],'properties'=>['id'=>['type'=>'string','maxLength'=>48],'name'=>['type'=>'string','maxLength'=>150],'category'=>['enum'=>['analytics','marketing','media']],'type'=>['enum'=>['script','image','embed']],'url'=>['type'=>'string','maxLength'=>2048],'origins'=>['type'=>'object','additionalProperties'=>false,'properties'=>['script'=>['type'=>'array','maxItems'=>10],'connect'=>['type'=>'array','maxItems'=>10],'img'=>['type'=>'array','maxItems'=>10],'frame'=>['type'=>'array','maxItems'=>10]]]]]);
      if(!preg_match('/^[a-z][a-z0-9-]{0,47}$/D',$resource['id'])||isset($seen[$resource['id']])||trim($resource['name'])===''||strip_tags($resource['name'])!==$resource['name'])throw new HttpError(422,'Invalid resource identity.','privacy.error');$seen[$resource['id']]=true;self::url($resource['url'],$resource['type']);
      foreach($resource['origins'] as $origins)foreach($origins as $origin)if(!is_string($origin)||!preg_match('~^https://[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?(?::[0-9]{1,5})?$~D',$origin)||!filter_var($origin,FILTER_VALIDATE_URL))throw new HttpError(422,'CSP origins must be explicit HTTPS origins.','privacy.error');
    }return $data;
  }
  /** Return revisioned core catalog repository; fixed path, bounded history contains configuration only, never visitor identifiers. */
  public function repository(): ExtensionDocument {return new ExtensionDocument($this->store,'privacy/catalog.json',self::defaults(),self::validate(...));}
  /** Resolve published legal pages only; receive base/settings, return public labels/URLs without creating or publishing generic legal text. */
  public function links(string $base,array $settings): array {
    $result=[];foreach(['privacyPage'=>'privacy','termsPage'=>'terms','refundPage'=>'refund','accessibilityPage'=>'accessibility'] as $field=>$label){if($settings[$field]==='')continue;try{$page=$this->store->read($settings[$field])['data'];if($page['status']==='published')$result[$label]=$base.'/'.$settings[$field];}catch(HttpError $error){if(!in_array($error->status,[404,422],true))throw $error;}}return $result;
  }
  /** Add privacy UI and strict resource-specific CSP to a complete public HTML document; receive bytes/base, return unchanged non-HTML or configured decorated HTML, never persists choices. */
  public function decorate(string $html,string $base): string {
    if(http_response_code()>=300&&http_response_code()<400||!preg_match('~\A\s*(?:(?:<!--[\s\S]*?-->|<!doctype\s+html[^>]*>)\s*)*<html\b~i',$html)||!preg_match('~</body\s*>~i',$html))return $html;
    foreach(headers_list() as $header)if(str_starts_with(strtolower($header),'content-disposition:')||str_starts_with(strtolower($header),'content-type:')&&!preg_match('~^content-type:\s*text/html(?:;|$)~i',$header))return $html;
    $siteSnapshot=$this->store->read('site');$settings=self::settings($siteSnapshot['data']['settings']['privacy']??[]);$catalog=$this->repository()->read()['data'];$links=$this->links($base,$settings);$resources=$catalog['resources'];if($resources!==[]&&($settings['controller']===''||$settings['email']===''||!isset($links['privacy']))){$resources=[];error_log('DBLESS-CMS optional resources blocked: privacy identity or published notice missing');}
    if($links===[]&&$resources===[]&&$settings['controller']===''&&$settings['registration']===''&&$settings['address']===''&&$settings['email']==='')return $html;
    $copy=$catalog['notice'];$footer='<aside class="cms-legal" aria-label="'.e($copy['manage']).'">';
    foreach(['controller','registration','address'] as $key)if($settings[$key]!=='')$footer.='<span>'.e($settings[$key]).'</span> ';
    if($settings['email']!=='')$footer.='<a href="'.e('mailto:'.$settings['email']).'">'.e($settings['email']).'</a> ';
    foreach($links as $key=>$url)$footer.='<a href="'.e($url).'">'.e($copy[$key]).'</a> ';
    if($resources!==[]){$policies=[];foreach(['privacyPage','termsPage'] as $field)if($settings[$field]!==''){try{$policies[$field]=$this->store->read($settings[$field])['revision'];}catch(HttpError $error){if(!in_array($error->status,[404,422],true))throw $error;}}$public=['key'=>'dbless-cms-privacy-'.hash('sha256',$base),'version'=>hash('sha256',ExtensionDocument::encode($catalog).ExtensionDocument::encode($settings).json_encode($policies,JSON_THROW_ON_ERROR)),'days'=>$catalog['consentDays'],'resources'=>array_map(static function(array $resource)use($base):array{if(str_starts_with($resource['url'],'/'))$resource['url']=$base.$resource['url'];return $resource;},$resources)];$footer.='<button type="button" data-privacy-open>'.e($copy['manage']).'</button><section class="cms-consent" data-cms-consent hidden aria-labelledby="cms-consent-title" data-options="'.e(json_encode($public,JSON_THROW_ON_ERROR)).'"><h2 id="cms-consent-title">'.e($copy['title']).'</h2><p>'.e($copy['description']).'</p><form data-privacy-form>';
      foreach(['analytics','marketing','media'] as $category)if(array_filter($resources,static fn(array $r):bool=>$r['category']===$category)){$footer.='<label><input type="checkbox" name="'.$category.'"> '.e($copy[$category]).'</label><ul>';foreach($resources as $r)if($r['category']===$category)$footer.='<li>'.e($r['name']).'</li>';$footer.='</ul>';}
      $footer.='<div class="cms-consent-actions"><button type="button" data-privacy-reject>'.e($copy['reject']).'</button><button type="submit">'.e($copy['save']).'</button><button type="button" data-privacy-accept>'.e($copy['accept']).'</button></div></form></section>';
    }$footer.='</aside>';
    $assets='<link rel="stylesheet" href="'.e(asset('cms-assets/privacy.css',$base)).'">';if($resources!==[])$footer.='<script defer src="'.e(asset('cms-assets/privacy.js',$base)).'"></script>';
    $html=preg_replace_callback('~</head\s*>~i',static fn(array $match):string=>$assets.'</head>',$html,1);$html=preg_replace_callback('~</body\s*>~i',static fn(array $match):string=>$footer.'</body>',$html,1);
    if($resources!==[]&&!headers_sent()){$directives=['media'=>["'self'",'https:'],'script'=>["'self'"],'connect'=>["'self'"],'img'=>["'self'",'https:','data:'],'frame'=>["'self'"]];foreach($resources as $r){foreach($r['origins'] as $key=>$origins)$directives[$key]=array_merge($directives[$key],$origins);if(str_starts_with($r['url'],'https://')){$parts=parse_url($r['url']);$origin='https://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');$key=['script'=>'script','image'=>'img','embed'=>'frame'][$r['type']];$directives[$key][]=$origin;}}
      $policy="default-src 'self'; style-src 'self'; font-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'; ";foreach($directives as $key=>$origins)$policy.=$key.'-src '.implode(' ',array_unique($origins)).'; ';header('Content-Security-Policy: '.$policy);
    }return $html;
  }
  /** Export only consent configuration; receive a new package root, preserve readable configuration but never visitor state/history; copy validated local resource files explicitly. */
  public function export(string $target): void {
    $data=$this->repository()->read()['data'];ExtensionDocument::atomic($target.'/system/storage/contents/privacy/catalog.json',ExtensionDocument::encode($data));
    $copied=[];foreach($data['resources'] as $resource)if(str_starts_with($resource['url'],'/')&&!isset($copied[$resource['url']])){$copied[$resource['url']]=true;$relative=ltrim($resource['url'],'/');if(!preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)*$~D',$relative))throw new \RuntimeException('Unsafe optional asset export.');$file=Lifecycle::path(dirname($this->store->config->root).'/public_html/'.$relative);if(!is_file($file)||filesize($file)>1048576)throw new \RuntimeException('Optional asset unavailable.');$bytes=file_get_contents($file);if($bytes===false||preg_match('~<\?(?:php|=)|<script|<html~i',$bytes))throw new \RuntimeException('Unsafe optional asset.');if($resource['type']==='image'&&@getimagesize($file)===false)throw new \RuntimeException('Invalid optional image.');$output=Lifecycle::path($target.'/public_html/'.$relative);if(file_exists($output))throw new \RuntimeException('Optional asset must not overwrite exported code.');Security::directory(dirname($output));if(file_put_contents($output,$bytes)!==strlen($bytes))throw new \RuntimeException('Optional asset export failed.');}
  }
}
