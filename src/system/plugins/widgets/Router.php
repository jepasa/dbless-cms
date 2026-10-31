<?php
/** Private route allowlist for the independent Widgets workspace, integrated into the fixed admin shell. */
declare(strict_types=1);
namespace DBLESSCMS;
final class WidgetsRouter {
  /** Render only declared trusted workspace routes; receives context, returns fragment/document, propagates rendering errors after cleaning buffer. */
  public static function render(PluginContext $context): string {
    if(!in_array($context->route,['overview','editor','trash','history'],true)||is_link(__DIR__.'/pages')||is_link(__DIR__.'/pages/workspace.php'))throw new HttpError(404,'Unavailable route.','error.not_found');
    ob_start();try{require __DIR__.'/pages/workspace.php';return (string)ob_get_clean();}catch(\Throwable $error){ob_end_clean();throw $error;}
  }
}
