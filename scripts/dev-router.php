<?php
/** Router opcional do servidor PHP local; restringe arquivos ao DocumentRoot e preserva URLs amigáveis. */
declare(strict_types=1);
$public=realpath(dirname(__DIR__).'/src/public_html');
$path=rawurldecode((string)parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH));
if (preg_match('~(?:^|/)\.[^/]+|^/(?!(?:index\.php|cms-admin/index\.php|plugins/index\.php)$).*\.(?:php[0-9]*|phtml|phar)(?:/|$)~i',$path)) { http_response_code(403); exit; }
$candidate=$public;
foreach (explode('/',ltrim($path,'/')) as $segment) { $candidate.='/'.$segment; if (is_link($candidate)) { http_response_code(403); exit; } }
$file=realpath($public.$path);
if ($file!==false && str_starts_with($file,$public.'/') && is_file($file) && !str_ends_with($file,'.php')) {
  // Opaque preview masks/fonts require anonymous CORS, limited to regular public presentation files, never PHP, JSON or APIs.
  $extension=strtolower(pathinfo($file,PATHINFO_EXTENSION));$types=['svg'=>'image/svg+xml','woff'=>'font/woff','woff2'=>'font/woff2','ttf'=>'font/ttf','otf'=>'font/otf'];
  if(isset($types[$extension])&&preg_match('~^/(?:templates|media|cms-assets|plugins)/~D',$path)){
    $method=$_SERVER['REQUEST_METHOD']??'GET';if(!in_array($method,['GET','HEAD'],true)){header('Allow: GET, HEAD');http_response_code(405);return;}
    header('Content-Type: '.$types[$extension]);header('Access-Control-Allow-Origin: *');header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store');
    if($method==='GET'&&readfile($file)===false)throw new RuntimeException('Public presentation resource unavailable.');return;
  }
  return false;
}
if (str_starts_with($path,'/plugins/')) {
 $_SERVER['SCRIPT_NAME']='/plugins/index.php';require $public.'/plugins/index.php';return;
}
if ($path==='/cms-admin' || str_starts_with($path,'/cms-admin/')) {
 if (!in_array($path,['/cms-admin','/cms-admin/','/cms-admin/index.php'],true)) { http_response_code(404); exit; }
 $_SERVER['SCRIPT_NAME']='/cms-admin/index.php'; require $public.'/cms-admin/index.php'; return;
}
$_SERVER['SCRIPT_NAME']='/index.php';
if ($path!=='/' && $path!=='/index.php') $_GET['content_path']=trim($path,'/');
require $public.'/index.php';
