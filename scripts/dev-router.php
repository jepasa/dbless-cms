<?php
/** Router opcional do servidor PHP local; restringe arquivos ao DocumentRoot e preserva URLs amigáveis. */
declare(strict_types=1);
$public=realpath(dirname(__DIR__).'/src/public_html');
$path=rawurldecode((string)parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH));
if (preg_match('~(?:^|/)\.[^/]+|^/(?!(?:index\.php|cms-admin/index\.php|plugins/index\.php)$).*\.(?:php[0-9]*|phtml|phar)(?:/|$)~i',$path)) { http_response_code(403); exit; }
$candidate=$public;
foreach (explode('/',ltrim($path,'/')) as $segment) { $candidate.='/'.$segment; if (is_link($candidate)) { http_response_code(403); exit; } }
$file=realpath($public.$path);
if ($file!==false && str_starts_with($file,$public.'/') && is_file($file) && !str_ends_with($file,'.php')) return false;
if (str_starts_with($path,'/plugins/')) {
 $_SERVER['SCRIPT_NAME']='/plugins/index.php';require $public.'/plugins/index.php';return;
}
if ($path==='/cms-admin' || str_starts_with($path,'/cms-admin/')) {
 if (!in_array($path,['/cms-admin','/cms-admin/','/cms-admin/index.php'],true)) { http_response_code(404); exit; }
 $_SERVER['SCRIPT_NAME']='/cms-admin/index.php'; require $public.'/cms-admin/index.php'; return;
}
$_SERVER['SCRIPT_NAME']='/index.php';
if ($path!=='/' && $path!=='/index.php') $_GET['page']=trim($path,'/');
require $public.'/index.php';
