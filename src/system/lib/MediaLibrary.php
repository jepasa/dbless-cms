<?php
/** Core read-only media roots and session-scoped picker. Public consumers never receive private references; uploads belong to Images. */
declare(strict_types=1);
/** Trusted read-only media services. */
namespace DBLESSCMS;
/** Resolve validated roots and enforce per-request media capabilities. */
final class MediaLibrary {
  /** Resolve canonical roots with legacy read-only fallback; receives store, returns relative public/private paths without migration or directory creation. */
  public static function roots(JsonStore $store,?array $legacy=null): array {
    $media=$store->read('site')['data']['settings']['media']??null;
    if($media===null)$media=$legacy??$store->read('plugins')['data']['plugins']['files-manager']['settings']['uploads']??['public'=>'media','private'=>'media'];
    self::validate($media);return $media;
  }
  /** Validate both relative roots and deny public code locations; receives roots, throws 422 without filesystem changes. */
  public static function validate(array $roots): void {
    if(array_diff(array_keys($roots),['public','private'])||count($roots)!==2)self::fail();
    foreach(['public','private'] as $scope){if(!is_string($roots[$scope]??null))self::fail();self::relative($roots[$scope]);}
    if(in_array(strtolower(explode('/',$roots['public'])[0]),['cms-admin','cms-assets','plugins','templates','customizations','tracking'],true))self::fail(403);
  }
  /** Validate configured roots against filesystem ancestors without creating or moving files; receives store/proposed roots, rejects links and nondirectories before settings commit. */
  public static function validatePaths(JsonStore $store,array $roots): void {
    self::validate($roots);foreach(['public'=>dirname($store->config->root).'/public_html','private'=>$store->config->data['storage'].'/files'] as $scope=>$anchor){$file=$anchor.($roots[$scope]===''?'':'/'.$roots[$scope]);try{Lifecycle::path($file);}catch(\RuntimeException){self::fail();}if(file_exists($file)&&!is_dir($file))self::fail();}
  }
  /** Validate bounded relative segments; receives path including empty root, throws 422 for traversal, links-by-name or ambiguous encodings. */
  public static function relative(string $path): void {
    if(strlen($path)>240||substr_count($path,'/')>11||($path!==''&&!preg_match('~^[\pL\pN][\pL\pN _()./-]*$~uD',$path)))self::fail();
    foreach($path===''?[]:explode('/',$path) as $part)if($part===''||strlen($part)>120||str_starts_with($part,'.')||str_contains($part,'..')||trim($part)!==$part||str_ends_with($part,'.'))self::fail();
  }
  /** Fail with one translated, path-free error; receives status, never discloses private filesystem information. */
  private static function fail(int $status=422): never {throw new HttpError($status,'Media unavailable.','media.error');}
  /** Issue a selector capability from a trusted PHP consumer; receives visibility, returns session-bound config without exposing paths or credentials. Only private callers may opt in to private scope. */
  public static function configuration(JsonStore $store,Language $language,string $base='',string $visibility='public'): array {
    if(!in_array($visibility,['public','private'],true)||!Security::authenticated($store->config))self::fail(403);
    Access::require($store->config,'media.read');if($visibility==='private')Access::require($store->config,'media.private');
    $_SESSION['media-secret']??=bin2hex(random_bytes(32));$roots=self::roots($store);$signature=hash_hmac('sha256',$visibility.'|'.json_encode($roots),$_SESSION['media-secret']);
    $labels=[];foreach($language->messages() as $key=>$value)if(str_starts_with($key,'media.'))$labels[substr($key,6)]=$value;
    $icons=[];foreach(['up','grid','list','sort-up','arrow-right','close','folder','video','image'] as $icon)$icons[$icon]=Icons::url($icon,$base);
    return ['icons'=>$icons,'endpoint'=>$base.'/cms-admin/?view=media-library','token'=>$visibility.'.'.$signature,'visibility'=>$visibility,'csrf'=>Security::token(),'labels'=>$labels];
  }
  /** Verify a session-scoped capability and requested area; receives store/token/scope, rejects changed roots and public-to-private escalation without writes. */
  private static function authorize(JsonStore $store,string $token,string $scope): void {
    Access::require($store->config,'media.read');if($scope==='private')Access::require($store->config,'media.private');
    [$visibility,$signature]=array_pad(explode('.',$token,2),2,'');
    if(!isset($_SESSION['media-secret'])||!in_array($visibility,['public','private'],true)||!in_array($scope,['public','private'],true)||($scope==='private'&&$visibility!=='private')||!hash_equals(hash_hmac('sha256',$visibility.'|'.json_encode(self::roots($store)),$_SESSION['media-secret']),$signature))self::fail(403);
  }
  /** Resolve a contained existing path, rejecting symbolic ancestors and special nodes; receives store/scope/path and required-existence flag, returns absolute path only to trusted server code. */
  private static function path(JsonStore $store,string $scope,string $path,bool $required=true): string {
    self::relative($path);$roots=self::roots($store);$anchor=$scope==='public'?dirname($store->config->root).'/public_html':$store->config->data['storage'].'/files';
    $relative=trim($roots[$scope].'/'.$path,'/');if($scope==='public'&&in_array(strtolower(explode('/',$relative)[0]),['cms-admin','cms-assets','plugins','templates','customizations','tracking','system'],true))self::fail(403);$file=$anchor.($relative===''?'':'/'.$relative);$cursor='';foreach(explode('/',ltrim($file,'/')) as $part){$cursor.='/'.$part;if(is_link($cursor))self::fail();}
    if($required&&!file_exists($file))self::fail(404);return $file;
  }
  /** Identify only image/video bytes; receives regular file, returns MIME or null without network access or metadata interpretation. */
  public static function mime(string $file): ?string {
    $extension=strtolower(pathinfo($file,PATHINFO_EXTENSION));$expected=['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','avif'=>'image/avif','mp4'=>'video/mp4','webm'=>'video/webm'];
    if(!isset($expected[$extension])||filesize($file)>12582912)return null;$mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file);if($mime!==$expected[$extension])return null;
    try{$bytes=file_get_contents($file);if($bytes===false)return null;$payload=$mime==='image/jpeg'?ExtensionMedia::jpegScan($bytes):$bytes;if(preg_match('~<\?(?:php|=)|<\s*(?:script|iframe|object|embed|html)\b~i',$payload))return null;$scan=$mime==='image/png'?ExtensionMedia::pngScan($bytes):$payload;if(preg_match('~<svg\b~i',$scan))return null;if($mime==='video/mp4')ExtensionMedia::mp4($bytes);}catch(\RuntimeException){return null;}
    if(str_starts_with($mime,'image/')){$size=@getimagesize($file);if($size===false||($size['mime']??'')!==$mime||$size[0]*$size[1]>40000000)return null;}return $mime;
  }
  /** List one bounded directory as paginated typed references; receives trusted context and query, returns only permitted media/folders, never scans recursively or writes. */
  public static function listing(JsonStore $store,string $base,array $query): array {
    foreach(['token','scope','path','search','type','sort','direction','page'] as $key)if(isset($query[$key])&&!is_string($query[$key]))self::fail();
    $scope=$query['scope']??'public';self::authorize($store,$query['token']??'',$scope);$path=$query['path']??'';$directory=self::path($store,$scope,$path,$path!=='');if(!file_exists($directory))return ['items'=>[],'page'=>1,'pages'=>1,'total'=>0,'path'=>'','scope'=>$scope];if(!is_dir($directory))self::fail();
    $search=$query['search']??'';$type=$query['type']??'all';$sort=$query['sort']??'name';$direction=$query['direction']??'asc';$page=$query['page']??'1';
    if(strlen($search)>120||!in_array($type,['all','image','video'],true)||!in_array($sort,['name','type','size','modified'],true)||!in_array($direction,['asc','desc'],true)||!preg_match('/^[1-9][0-9]{0,4}$/D',$page))self::fail();
    $items=[];$count=0;$roots=self::roots($store);
    foreach(new \DirectoryIterator($directory) as $entry){if($entry->isDot()||str_starts_with($entry->getFilename(),'.'))continue;if(++$count>10000)self::fail(413);if($entry->isLink())continue;$name=$entry->getFilename();try{self::relative($name);}catch(HttpError){continue;}
      if($search!==''&&preg_match('~'.preg_quote($search,'~').'~ui',$name)!==1)continue;$folder=$entry->isDir();if($scope==='public'&&$roots['public']===''&&$path===''&&in_array(strtolower($name),['cms-admin','cms-assets','plugins','templates','customizations','tracking','system'],true))continue;if(!$folder&&!$entry->isFile())continue;$mime=$folder?'directory':self::mime($entry->getPathname());if($mime===null||(!$folder&&$type!=='all'&&!str_starts_with($mime,$type.'/')))continue;
      $relative=($path===''?'':$path.'/').$name;$public=trim($roots['public'].'/'.$relative,'/');$url=$scope==='public'?$base.'/'.implode('/',array_map('rawurlencode',explode('/',$public))):$base.'/cms-admin/?'.http_build_query(['view'=>'media-library','operation'=>'stream','scope'=>'private','path'=>$relative,'token'=>$query['token']]);
      $items[]=['name'=>$name,'path'=>$relative,'type'=>$mime,'folder'=>$folder,'size'=>$folder?0:$entry->getSize(),'modified'=>$entry->getMTime(),'url'=>$folder?'':$url,'reference'=>['scope'=>$scope,'path'=>$relative],'value'=>$scope==='public'?$public:null];
    }
    usort($items,static function(array $a,array $b)use($sort,$direction):int{if($a['folder']!==$b['folder'])return $a['folder']?-1:1;$order=$sort==='name'?strnatcasecmp($a['name'],$b['name']):($a[$sort]<=>$b[$sort]);return ($order?:strcmp($a['path'],$b['path']))*($direction==='asc'?1:-1);});
    $total=count($items);$pages=max(1,(int)ceil($total/25));$current=min((int)$page,$pages);return ['items'=>array_slice($items,($current-1)*25,25),'page'=>$current,'pages'=>$pages,'total'=>$total,'path'=>$path,'scope'=>$scope];
  }
  /** Serve the authenticated read-only protocol; receives services/base, returns JSON or streams private media, rejects mutation methods/scopes and leaks no filesystem paths. */
  public static function response(Config $config,JsonStore $store,string $base): string {
    header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');
    try{
      if(!Security::authenticated($config))self::fail(403);$operation=$_GET['operation']??'list';
      if($operation==='list'){if(($_SERVER['REQUEST_METHOD']??'')!=='POST')self::fail(405);Security::csrf($_POST['csrf_token']??null);Security::limit($config,'media-library',120,60);return json_encode(self::listing($store,$base,$_POST),JSON_THROW_ON_ERROR);}
      if($operation!=='stream'||($_SERVER['REQUEST_METHOD']??'')!=='GET')self::fail(405);
      foreach(['token','scope','path'] as $key)if(!is_string($_GET[$key]??null))self::fail();self::authorize($store,$_GET['token'],$_GET['scope']);if($_GET['scope']!=='private')self::fail();$file=self::path($store,'private',$_GET['path']);if(!is_file($file)||($mime=self::mime($file))===null)self::fail();
      header('Content-Type: '.$mime);header('Content-Length: '.filesize($file));header('Content-Disposition: inline');if(readfile($file)===false)self::fail(503);return '';
    }catch(HttpError $error){http_response_code($error->status);return json_encode(['error'=>$error->messageKey],JSON_THROW_ON_ERROR);}
  }
}
