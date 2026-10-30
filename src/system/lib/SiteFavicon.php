<?php
/** Global favicon paths are relative to the public hosting root; resolve regular ICO files without following symbolic links. */
declare(strict_types=1);
namespace DBLESSCMS;
final class SiteFavicon {
  /** Validate a root-relative ICO reference; receives path, returns void or localized 422 without touching files. */
  public static function reference(string $relative): void {
    if (strlen($relative)>240 || !preg_match('~^[\pL\pN][\pL\pN _().-]*(?:/[\pL\pN][\pL\pN _().-]*)*\.ico$~uD',$relative) || str_contains($relative,'..')) throw new HttpError(422,'Caminho de favicon inválido.','error.favicon');
  }
  /** Resolve a regular ICO within public_html; receives store/reference, returns absolute path or 422 without disclosing internal paths or changing data. */
  public static function path(JsonStore $store,string $relative): string {
    self::reference($relative);$root=dirname($store->config->root).'/public_html';$path=$root;
    $cursor='';foreach (explode('/',ltrim($root,'/')) as $part) {$cursor.='/'.$part;if (is_link($cursor)) throw new HttpError(422,'Favicon inseguro.','error.favicon');}
    foreach (explode('/',$relative) as $part) {$path.='/'.$part;if (is_link($path)) throw new HttpError(422,'Favicon inseguro.','error.favicon');}
    $real=realpath($path);$public=realpath($root);
    if ($real===false || $public===false || !is_file($real) || !str_starts_with($real,$public.'/')) throw new HttpError(422,'Favicon indisponível.','error.favicon');
    try {ExtensionMedia::ico($real);} catch (\RuntimeException) {throw new HttpError(422,'Arquivo ICO inválido.','error.favicon');}
    return $real;
  }
  /** Resolve chosen favicon or a template default if it was deleted; receives store, returns relative/absolute paths without modifying old settings. */
  public static function resolve(JsonStore $store): array {
    $site=$store->read('site')['data'];$fallback='templates/'.$site['template'].'/'.$store->templates->get($site['template'])['assets']['files']['favicon'];
    $relative=$site['settings']['favicon']??$fallback;
    try {$file=self::path($store,$relative);} catch (HttpError) {$relative=$fallback;$file=self::path($store,$relative);}
    return ['relative'=>$relative,'file'=>$file];
  }
  /** Return addressed favicon URL; receives store/base, encodes path segments and propagates missing defaults without writing. */
  public static function url(JsonStore $store,string $base=''): string {
    $resolved=self::resolve($store);return asset(implode('/',array_map('rawurlencode',explode('/',$resolved['relative']))),$base);
  }
  /** Copy only the referenced favicon into a production stage; receives store/stage, propagates I/O errors and never scans arbitrary uploads. */
  public static function export(JsonStore $store,string $target): void {
    $resolved=self::resolve($store);$destination=$target.'/public_html/'.$resolved['relative'];
    if (!is_dir(dirname($destination)) && !mkdir(dirname($destination),0770,true)) throw new \RuntimeException('Favicon export directory unavailable.');
    if (!copy($resolved['file'],$destination)) throw new \RuntimeException('Favicon export failed.');
  }
}
