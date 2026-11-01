<?php
/** Independent private Forms routes, integrated into the fixed administrative identity; public delivery never uses this router. */
declare(strict_types=1);
namespace DBLESSCMS;
final class FormsRouter {
  /** Render one declared private workspace route; receive authorized context, return fragment, clean buffer and propagate failures. */
  public static function render(PluginContext $context): string {
    if(!in_array($context->route,['overview','new','editor','history','trash','submissions'],true))throw new HttpError(404,'Form route unavailable.','error.not_found');
    ob_start();try{require __DIR__.'/pages/workspace.php';return (string)ob_get_clean();}catch(\Throwable $error){ob_end_clean();throw $error;}
  }
}
