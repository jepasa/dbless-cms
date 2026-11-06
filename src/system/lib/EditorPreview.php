<?php
/** Read-only unsaved HTML/Mustache preview in the active public layout. Published programs run only in a disposable opaque sandbox, never in the editing canvas. */
declare(strict_types=1);
/** Isolated private preview services. */
namespace DBLESSCMS;
/** Serve authorized editor buffers without exporting session material. */
final class EditorPreview {
  /** Authenticate and validate one bounded preview POST; receive services/base, return JSON without persistence or real session material; expected failures retain HTTP status. */
  public static function response(Config $config,JsonStore $store,string $base): string {
    header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
    try {
      if(!Security::authenticated($config))throw new HttpError(403,'Authentication required.','error.authentication');
      Access::require($config,'posts.edit');
      if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){header('Allow: POST');throw new HttpError(405,'POST required.','error.method');}
      Security::csrf($_POST['csrf_token']??null);Security::limit($config,'editor-preview',60,60);
      $raw=$_POST['document']??null;if(!is_string($raw)||strlen($raw)>262144)throw new HttpError(422,'Invalid preview document.','markup.error');
      try{$input=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(422,'Invalid preview document.','markup.error');}
      if(!is_array($input)||array_diff(array_keys($input),['html','bindings','page','scope'])||!is_string($input['page']??null)||!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$input['page']))throw new HttpError(422,'Invalid preview context.','markup.error');
      $scope=$input['scope']??'content';if(!is_string($scope)||!in_array($scope,['content','page'],true))throw new HttpError(422,'Invalid preview scope.','markup.error');
      $definition=ContentMarkup::definition(['html'=>$input['html']??null,'bindings'=>$input['bindings']??null]);
      return json_encode(['html'=>self::render($store,$definition,$input['page'],$base,$scope),'diagnostics'=>ContentMarkup::diagnostics($store)],JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
    }catch(HttpError $error){http_response_code($error->status);return json_encode(['error'=>$error->messageKey],JSON_THROW_ON_ERROR);}
  }
  /** Render a validated buffer in the current template with published complements; receive definition/context, return isolated document, restore session even on failure and never save content. */
  public static function render(JsonStore $store,array $definition,string $page,string $base,string $scope='content'): string {
    if(!in_array($scope,['content','page'],true))throw new HttpError(422,'Invalid preview scope.','markup.error');
    $definition=ContentMarkup::definition($definition);$marker=bin2hex(random_bytes(16));$saved=$_SESSION??[];$_SESSION=[];
    try {
      try{$store->read($page,$store->read('site')['data']['template']);}catch(HttpError $error){if($error->status!==404)throw $error;$page='home';}
      $html=App::render($store,$page,true,null,$base,null,null,$definition,$marker);
      $html=(new SiteCustomizations($store))->decorate($html,$base);
    }finally{$_SESSION=$saved;}
    return self::document($html,$base,$scope,$marker,MediaLibrary::roots($store)['public']);
  }
  /** Normalize a trusted public layout for srcdoc; receive HTML/public base, return a document whose resources are public-only, forms/tokens inert and CSP denies connections/navigation. No fetching or code evaluation. */
  public static function document(string $html,string $base,string $scope='page',?string $marker=null,string $mediaRoot='media'): string {
    $parts=parse_url($base);if(!is_array($parts)||!isset($parts['scheme'],$parts['host'])||!in_array($parts['scheme'],['http','https'],true)||(isset($parts['user'])||isset($parts['pass'])))throw new HttpError(422,'Invalid public base.','markup.error');
    $origin=$parts['scheme'].'://'.$parts['host'].(isset($parts['port'])?':'.$parts['port']:'');$prefix=rtrim($parts['path']??'','/');
    $previous=libxml_use_internal_errors(true);$doc=new \DOMDocument('1.0','UTF-8');
    try{if(!$doc->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING))throw new HttpError(422,'Invalid preview layout.','markup.error');}
    finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
    if($marker!==null){
      if(!preg_match('/^[a-f0-9]{32}$/D',$marker))throw new HttpError(422,'Invalid preview marker.','markup.error');
      $xpath=new \DOMXPath($doc);$start=$xpath->query('//comment()[.="editor-start-'.$marker.'"]')->item(0);$end=$xpath->query('//comment()[.="editor-end-'.$marker.'"]')->item(0);
      if($start===null||$end===null||$start->parentNode!==$end->parentNode)throw new HttpError(422,'Template content slot unavailable.','markup.error');
      if($scope==='content'){
        $body=$doc->getElementsByTagName('body')->item(0);if($body===null)throw new HttpError(422,'Preview body unavailable.','markup.error');
        $scripts=[];foreach($body->getElementsByTagName('script') as $script)$scripts[]=$script->cloneNode(true);
        $container=$start->parentNode;$inside=false;
        foreach(iterator_to_array($container->childNodes) as $child){if($child===$start){$inside=true;continue;}if($child===$end){$inside=false;continue;}if(!$inside)$container->removeChild($child);}
        $keep=$container;$body=$doc->getElementsByTagName('body')->item(0);
        while($keep!==$body&&$keep->parentNode!==null){$parent=$keep->parentNode;foreach(iterator_to_array($parent->childNodes) as $child)if($child!==$keep)$parent->removeChild($child);$keep=$parent;}
        if($body===null)throw new HttpError(422,'Preview body unavailable.','markup.error');
        foreach($scripts as $script)$body->appendChild($script);$body->setAttribute('data-editor-preview-scope','content');
      }
      $start->parentNode?->removeChild($start);$end->parentNode?->removeChild($end);
    }
    // Resources are addressed from the public hosting base, not cms-admin or about:srcdoc.
    foreach(iterator_to_array($doc->getElementsByTagName('*')) as $node){
      $tag=strtolower($node->tagName);
      if(in_array($tag,['base','iframe','object','embed'],true)||($tag==='meta'&&$node->hasAttribute('http-equiv'))){$node->parentNode?->removeChild($node);continue;}
      foreach(iterator_to_array($node->attributes) as $attribute)if(str_starts_with(strtolower($attribute->name),'on')||in_array(strtolower($attribute->name),['autofocus','srcdoc','integrity'],true))$node->removeAttribute($attribute->name);
      if(in_array($tag,['input','textarea','select'],true)){$node->setAttribute('disabled','disabled');if($tag==='input')$node->removeAttribute('value');if($tag==='textarea')$node->textContent='';}
      if($tag==='form'){$node->removeAttribute('action');$node->setAttribute('inert','');}
      foreach(['src','href','poster'] as $attribute){
        if(!$node->hasAttribute($attribute))continue;$value=$node->getAttribute($attribute);
        if($attribute==='href'&&$tag!=='link'){if(str_starts_with($value,'#'))continue;$node->setAttribute('href','#');$node->removeAttribute('target');continue;}
        $url=str_starts_with($value,'/')?$origin.$value:(preg_match('~^https?://~i',$value)?$value:rtrim($base,'/').'/'.$value);
        $urlParts=parse_url($url);$path=$urlParts['path']??'';$local=is_array($urlParts)&&($urlParts['scheme']??'')===$parts['scheme']&&($urlParts['host']??'')===$parts['host']&&($urlParts['port']??null)===($parts['port']??null)&&!isset($urlParts['user'])&&!isset($urlParts['pass'])&&!isset($urlParts['query']);
        $mediaTag=in_array($tag,['img','video','source'],true);$sameOrigin=is_array($urlParts)&&($urlParts['host']??'')===$parts['host']&&($urlParts['port']??null)===($parts['port']??null);$remoteMedia=$mediaTag&&!$sameOrigin&&($urlParts['scheme']??'')==='https'&&!isset($urlParts['user'])&&!isset($urlParts['pass']);
        $allowed=$remoteMedia||($local&&$mediaTag&&str_starts_with($path,$prefix.'/'.$mediaRoot.'/')&&!str_contains(rawurldecode($path),'..'))||$local&&str_starts_with($path,$prefix.'/')&&!str_contains(rawurldecode($path),'..')&&!str_contains($path,'\\')&&preg_match('~^'.preg_quote($prefix,'~').'/(?:templates|media|customizations|cms-assets|plugins)/~D',$path);
        if(!$allowed){$node->removeAttribute($attribute);if($tag==='script'||$tag==='link')$node->parentNode?->removeChild($node);continue;}
        if($tag==='script'&&!preg_match('/\.js$/D',$path)){$node->parentNode?->removeChild($node);continue;}
        if($tag==='link'&&strtolower($node->getAttribute('rel'))==='stylesheet')$node->setAttribute('data-template-style','');
        $node->setAttribute($attribute,$url);
      }
      // Inline programs remain outside the public CSP contract; only addressed published assets execute.
      if($tag==='script'&&!$node->hasAttribute('src'))$node->parentNode?->removeChild($node);
      if($node->hasAttribute('srcset'))$node->removeAttribute('srcset');
    }
    $public=$origin.$prefix;$previewStyle=asset('cms-admin/assets/editor-preview.css',$base);
    $policy="default-src 'none'; script-src $public/templates/ $public/customizations/ $public/cms-assets/ $public/plugins/; style-src $previewStyle $public/templates/ $public/customizations/ $public/cms-assets/ $public/plugins/; img-src $public https: data:; media-src $public https:; font-src $public/templates/ $public/media/ $public/plugins/; connect-src 'none'; frame-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'";
    $head=$doc->getElementsByTagName('head')->item(0);if($head===null)throw new HttpError(422,'Preview head unavailable.','markup.error');
    $meta=$doc->createElement('meta');$meta->setAttribute('http-equiv','Content-Security-Policy');$meta->setAttribute('content',$policy);$head->insertBefore($meta,$head->firstChild);
    $meta=$doc->createElement('meta');$meta->setAttribute('name','referrer');$meta->setAttribute('content','no-referrer');$head->appendChild($meta);
    if($scope==='content'){$link=$doc->createElement('link');$link->setAttribute('rel','stylesheet');$link->setAttribute('href',$previewStyle);$head->appendChild($link);}
    foreach(iterator_to_array($doc->childNodes) as $child)if($child->nodeType===XML_PI_NODE)$doc->removeChild($child);
    return $doc->saveHTML();
  }
}
