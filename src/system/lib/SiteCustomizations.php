<?php
/** Core global CSS/JavaScript: private CAS drafts/history, immutable published assets and sandbox-only draft previews; never modifies template sources. */
declare(strict_types=1);
namespace DBLESSCMS;
final class SiteCustomizations {
  public const LIMIT=131072;
  /** Bind an installation store; no I/O or mutations, caller handles authentication/CSRF. */
  public function __construct(public readonly JsonStore $store) {}
  /** Return disabled empty typed sources; no arguments, effects or errors. */
  public static function emptySource(): array {return ['css'=>['enabled'=>false,'code'=>''],'js'=>['enabled'=>false,'code'=>'']];}
  /** Validate both closed source records, UTF-8 and size; receive sources, return them or 422 without evaluating JavaScript. */
  public static function source(array $source): array {
    if(count($source)!==2||array_diff(array_keys($source),['css','js']))throw new HttpError(422,'Invalid customization source.','custom.error');
    foreach(['css','js'] as $kind){$item=$source[$kind]??null;if(!is_array($item)||count($item)!==2||!is_bool($item['enabled']??null)||!is_string($item['code']??null)||strlen($item['code'])>self::LIMIT||!preg_match('//u',$item['code'])||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|<\?(?:php|=)/i',$item['code']))throw new HttpError(422,'Invalid customization source.','custom.error');}
    self::css($source['css']['code']);return $source;
  }
  /** Check CSS delimiter/string/comment balance without executing it; receive CSS, return void or 422; semantic browser compatibility is checked in preview. */
  public static function css(string $css): void {
    $stack=[];$quote='';$comment=false;$length=strlen($css);
    for($i=0;$i<$length;$i++){$c=$css[$i];$next=$css[$i+1]??'';
      if($comment){if($c==='*'&&$next==='/'){$comment=false;$i++;}continue;}
      if($c==='\\'){$i++;continue;}
      if($quote!==''){if($c===$quote)$quote='';continue;}
      if($c==='/'&&$next==='*'){$comment=true;$i++;continue;}
      if($c==='"'||$c==="'"){$quote=$c;continue;}
      if(str_contains('({[',$c))$stack[]=$c;
      elseif(str_contains(')}]',$c)&&array_pop($stack)!==[')'=>'(',']'=>'[','}'=>'{'][$c])throw new HttpError(422,'Unbalanced CSS.','custom.error');
    }
    if($stack||$quote!==''||$comment)throw new HttpError(422,'Unfinished CSS.','custom.error');
  }
  /** Resolve a fixed private or public directory without following links; receive public flag/create flag, return path or 503 on unsafe I/O. */
  private function directory(bool $public=false,bool $create=false): string {
    $path=$public?dirname($this->store->config->root).'/public_html/customizations':$this->store->config->data['storage'].'/customizations';
    return self::directoryAt($path,$create,$public?0755:0700);
  }
  /** Validate/create a known directory; receive absolute path/create/mode, return path, never accept symlinks; throws 503 on I/O. */
  private static function directoryAt(string $path,bool $create,int $mode=0700): string {
    try{Lifecycle::path($path);}catch(\RuntimeException){throw new HttpError(503,'Unsafe customization storage.','custom.error');}
    if(file_exists($path)&&!is_dir($path))throw new HttpError(503,'Unavailable customization directory.','custom.error');
    if($create&&!is_dir($path)&&!mkdir($path,$mode,true)&&!is_dir($path))throw new HttpError(503,'Unavailable customization directory.','custom.error');return $path;
  }
  /** Decode one closed catalog with bounded data; receive JSON bytes, return validated state or 422, no fallback or I/O. */
  public static function decode(string $raw): array {
    if(strlen($raw)>1048576)throw new HttpError(422,'Customization catalog too large.','custom.error');
    try{$data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(422,'Invalid customization JSON.','custom.error');}
    if(!is_array($data)||count($data)!==4||array_diff(array_keys($data),['schema_version','draft','published','updatedAt'])||($data['schema_version']??null)!==1||!is_array($data['draft']??null)||!array_key_exists('published',$data)||!is_string($data['updatedAt']??null))throw new HttpError(422,'Invalid customization catalog.','custom.error');
    self::source($data['draft']);$published=$data['published'];
    if($published!==null){if(!is_array($published)||count($published)!==2||!is_array($published['source']??null)||!is_array($published['files']??null)||count($published['files'])!==2||array_diff(array_keys($published['files']),['css','js']))throw new HttpError(422,'Invalid publication.','custom.error');self::source($published['source']);
      foreach(['css','js'] as $kind){$item=$published['source'][$kind];$file=$published['files'][$kind]??null;$enabled=$item['enabled']&&trim($item['code'])!=='';if(!$enabled&&$file!==null||$enabled&&(!is_array($file)||count($file)!==2||!is_string($file['sha256']??null)||!preg_match('/^[a-f0-9]{64}$/D',$file['sha256'])||!is_int($file['bytes']??null)||$file['bytes']<1||$file['bytes']>self::LIMIT))throw new HttpError(422,'Invalid published asset.','custom.error');}
    }return $data;
  }
  /** Read bounded regular bytes from a fixed known path; receive filename/limit, return bytes or 503, never follow links. */
  private static function bytes(string $file,int $limit=1048576): string {
    try{Lifecycle::path($file);}catch(\RuntimeException){throw new HttpError(503,'Unsafe customization file.','custom.error');}
    if(!is_file($file)||is_link($file))throw new HttpError(503,'Customization file unavailable.','custom.error');$raw=file_get_contents($file,false,null,0,$limit+1);if($raw===false||strlen($raw)>$limit)throw new HttpError(503,'Customization read failed.','custom.error');return $raw;
  }
  /** Read catalog without creating anything; absent state yields disabled defaults and empty revision; corrupt state fails explicitly. */
  public function read(): array {
    $file=$this->directory().'/catalog.json';if(!file_exists($file)&&!is_link($file))return ['data'=>['schema_version'=>1,'draft'=>self::emptySource(),'published'=>null,'updatedAt'=>''],'revision'=>hash('sha256','')];$raw=self::bytes($file);return ['data'=>self::decode($raw),'revision'=>hash('sha256',$raw)];
  }
  /** Atomically replace fixed file in same directory with fsync/permissions; receive file/bytes/mode, return void or 503; never follows destination links. */
  private static function atomic(string $file,string $bytes,int $mode=0600): void {
    if(is_link($file)||file_exists($file)&&!is_file($file))throw new HttpError(503,'Unsafe customization destination.','custom.error');
    $temporary=tempnam(dirname($file),'.dbless-custom-');if($temporary===false)throw new HttpError(503,'Customization write failed.','custom.error');
    try{$stream=fopen($temporary,'wb');if($stream===false)throw new HttpError(503,'Customization write failed.','custom.error');try{if(fwrite($stream,$bytes)!==strlen($bytes)||!fflush($stream)||function_exists('fsync')&&!fsync($stream))throw new HttpError(503,'Customization write incomplete.','custom.error');}finally{fclose($stream);}if(!chmod($temporary,$mode)||!rename($temporary,$file))throw new HttpError(503,'Customization publish failed.','custom.error');}finally{if(is_file($temporary))unlink($temporary);}
  }
  /** Return fixed content-addressed filename from a validated descriptor/kind; no I/O or user path accepted. */
  public static function filename(string $kind,array $file): string {if(!in_array($kind,['css','js'],true)||!preg_match('/^[a-f0-9]{64}$/D',$file['sha256']??''))throw new HttpError(422,'Invalid asset descriptor.','custom.error');return 'site-'.$file['sha256'].'.'.$kind;}
  /** Check and read a published immutable artifact; receive kind/descriptor, return verified bytes or 503, never executes code. */
  public function artifact(string $kind,array $file): string {$bytes=self::bytes($this->directory(true).'/'.self::filename($kind,$file),self::LIMIT);if(strlen($bytes)!==$file['bytes']||!hash_equals($file['sha256'],hash('sha256',$bytes)))throw new HttpError(503,'Customization integrity failed.','custom.error');return $bytes;}
  /** List safe history IDs newest first; no mutations; unsafe history paths fail rather than traverse links. */
  public function history(): array {$directory=self::directoryAt($this->directory().'/history',false);$ids=[];foreach(glob($directory.'/*.json')?:[] as $file){if(is_link($file))throw new HttpError(503,'Unsafe customization history.','custom.error');if(preg_match('/^[0-9]{8}-[0-9]{6}-[0-9]{6}-[a-f0-9]{16}\.json$/D',basename($file)))$ids[]=basename($file);}rsort($ids,SORT_STRING);return $ids;}
  /** Save/publish draft or restore snapshot to draft under CAS/site CAS; receives operation/source/revisions/backup ID, returns new snapshot; errors preserve existing catalog. */
  public function change(string $operation,array $source,string $revision,string $siteRevision,string $backup=''): array {
    if(!in_array($operation,['save','publish','restore'],true))throw new HttpError(422,'Invalid customization operation.','custom.error');
    return $this->store->transaction(function()use($operation,$source,$revision,$siteRevision,$backup):array{
      $current=$this->read();if(!hash_equals($current['revision'],$revision)||!hash_equals($this->store->read('site')['revision'],$siteRevision))throw new HttpError(409,'Customization revision changed.','error.conflict');$data=$current['data'];
      if($operation==='restore'){if(!in_array($backup,$this->history(),true))throw new HttpError(404,'History unavailable.','custom.error');$old=self::decode(self::bytes($this->directory().'/history/'.$backup));$source=$old['draft'];}
      $data['draft']=self::source($source);$data['updatedAt']=gmdate('Y-m-d\TH:i:s\Z');$directory=$this->directory(false,true);$history=self::directoryAt($directory.'/history',true);$created=[];
      try{
        if($operation==='publish'){$files=['css'=>null,'js'=>null];foreach(['css','js'] as $kind){$item=$source[$kind];if(!$item['enabled']||trim($item['code'])==='')continue;$bytes=$item['code'];$files[$kind]=['sha256'=>hash('sha256',$bytes),'bytes'=>strlen($bytes)];$file=$this->directory(true,true).'/'.self::filename($kind,$files[$kind]);if(file_exists($file)||is_link($file))$this->artifact($kind,$files[$kind]);else{self::atomic($file,$bytes,0644);$created[]=$file;}}$data['published']=['source'=>$source,'files'=>$files];}
        self::decode(JsonStore::encode($data));self::atomic($history.'/'.(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Ymd-His-u').'-'.bin2hex(random_bytes(8)).'.json',JsonStore::encode($current['data']));self::atomic($directory.'/catalog.json',JsonStore::encode($data));
      }catch(\Throwable $error){foreach($created as $file)if(is_file($file)&&!is_link($file))unlink($file);throw $error;}
      foreach(array_slice($this->history(),20) as $id)if(!unlink($history.'/'.$id))error_log('DBLESS-CMS customization history retention unavailable');return $this->read();
    });
  }
  /** Add addressed published assets only to complete HTML documents; receives HTML/base/optional draft, returns original for fragments or inactive state, uses DOM with network disabled. */
  public function decorate(string $html,string $base,?array $draft=null): string {
    if(!preg_match('~\A\s*(?:(?:<!--[\s\S]*?-->|<!doctype\s+html[^>]*>)\s*)*<html\b~i',$html)||!preg_match('~</head\s*>~i',$html)||!preg_match('~</body\s*>~i',$html))return $html;
    if($draft!==null){$sources=self::source($draft);$urls=[];foreach(['css','js'] as $kind)if($sources[$kind]['enabled']&&trim($sources[$kind]['code'])!=='')$urls[$kind]='data:text/'.($kind==='css'?'css':'javascript').';base64,'.base64_encode($sources[$kind]['code']);}
    else{try{$published=$this->read()['data']['published'];if($published===null)return $html;$urls=[];foreach(['css','js'] as $kind)if($published['files'][$kind]!==null){$this->artifact($kind,$published['files'][$kind]);$urls[$kind]=$base.'/customizations/'.self::filename($kind,$published['files'][$kind]);}}catch(HttpError){error_log('DBLESS-CMS customization unavailable; native document retained');return $html;}}
    if(!$urls)return $html;if(!class_exists(\DOMDocument::class)){error_log('DBLESS-CMS customization requires ext-dom; native document retained');return $html;}$previous=libxml_use_internal_errors(true);
    try{$dom=new \DOMDocument('1.0','UTF-8');if(!$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET))return $html;$head=$dom->getElementsByTagName('head')->item(0);$body=$dom->getElementsByTagName('body')->item(0);if(!$head||!$body)return $html;if((new \DOMXPath($dom))->query('//*[@data-site-customization]')->length)return $html;
      $body->setAttribute('data-template',$this->store->read('site')['data']['template']);
      if(isset($urls['css'])){$link=$dom->createElement('link');$link->setAttribute('rel','stylesheet');$link->setAttribute('href',$urls['css']);$link->setAttribute('data-site-customization','css');$head->appendChild($link);}
      if(isset($urls['js'])){$script=$dom->createElement('script');$script->setAttribute('src',$urls['js']);$script->setAttribute('defer','defer');$script->setAttribute('data-site-customization','js');$body->appendChild($script);}
      foreach(iterator_to_array($dom->childNodes) as $child)if($child->nodeType===XML_PI_NODE)$dom->removeChild($child);return (string)$dom->saveHTML();
    }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
  }
  /** Decorate a public-plugin response only when HTTP semantics and content type permit full HTML; receives body/base, returns safe unmodified non-HTML output. */
  public function publicResponse(string $html,string $base): string {
    if(http_response_code()>=300&&http_response_code()<400)return $html;foreach(headers_list() as $header){if(str_starts_with(strtolower($header),'content-disposition:'))return $html;if(str_starts_with(strtolower($header),'content-type:')&&!preg_match('~^content-type:\s*text/html(?:;|$)~i',$header))return $html;}return $this->decorate($html,$base);
  }
  /** Produce an authenticated opaque-origin sandbox preview of saved draft; receives base/expected revision, returns public Homepage without real session values or public draft files; throws conflict when stale. */
  public function preview(string $base,string $revision): string {
    $current=$this->read();if(!hash_equals($current['revision'],$revision))throw new HttpError(409,'Preview draft changed.','error.conflict');
    $session=$_SESSION;$_SESSION=[];try{$html=App::render($this->store,'home',true,null,$base);}finally{$_SESSION=$session;}
    header("Content-Security-Policy: sandbox allow-scripts; default-src 'none'; script-src 'self' data:; style-src 'self' data:; img-src 'self' data:; font-src 'self'; connect-src 'none'; object-src 'none'; frame-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');return $this->decorate($html,$base,$current['data']['draft']);
  }
  /** Export only published sources and verified artifacts into a fresh staging installation; receives target, never exports draft/history or writes source. */
  public function export(string $target): void {
    $data=$this->read()['data'];if($data['published']===null)return;$data['draft']=$data['published']['source'];$directory=self::directoryAt($target.'/system/storage/customizations',true);
    foreach(['css','js'] as $kind)if($data['published']['files'][$kind]!==null){$file=$data['published']['files'][$kind];$public=self::directoryAt($target.'/public_html/customizations',true,0755);self::atomic($public.'/'.self::filename($kind,$file),$this->artifact($kind,$file),0644);}self::atomic($directory.'/catalog.json',JsonStore::encode($data));
  }
}
