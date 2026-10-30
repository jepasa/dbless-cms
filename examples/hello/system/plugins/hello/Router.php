<?php
/** Independent plugin router: fixed private/public maps and buffered page fragments, without arbitrary path input. */
declare(strict_types=1);
namespace CMS2JSON;
final class HelloRouter {
  /** Render one fixed local script; receives a context/file, returns HTML, performs no writes and propagates rendering errors. */
  private static function page(PluginContext|PublicPluginContext $context,string $file): string {
    ob_start();
    try {require __DIR__.'/pages/'.$file;return (string)ob_get_clean();}
    catch(\Throwable $failure){ob_end_clean();throw $failure;}
  }
  /** Return an authorized admin fragment; receives private context, no writes, throws 404 for unknown route IDs. */
  public static function admin(PluginContext $context): string {
    if($context->route!=='overview')throw new HttpError(404,'Page not found.');
    return self::page($context,'overview.php');
  }
  /** Return a complete public document; receives restricted context, no writes, throws 404 for unknown route IDs. */
  public static function publicPage(PublicPluginContext $context): string {
    if($context->route!=='welcome')throw new HttpError(404,'Page not found.');
    return self::page($context,'welcome.php');
  }
}
