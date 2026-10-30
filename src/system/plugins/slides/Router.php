<?php
/** Roteador do plugin Slides: somente página privada declarada, com contexto e serviços compartilhados. */
declare(strict_types=1);
namespace CMS2JSON;
final class SlidesRouter {
  /** Renderiza página permitida; recebe contexto, retorna fragmento/documento e propaga falhas sem expor caminhos. */
  public static function render(PluginContext $context): string {
    if ($context->route==='locations') {header('Location: '.$context->base.'/cms-admin/?view=plugin-panel&plugin=slides&route=overview',true,303);return '';}
    if (!isset($context->manifest['routes'][$context->route])||is_link(__DIR__.'/pages')||is_link(__DIR__.'/pages/workspace.php')) throw new HttpError(404,'Página indisponível.','error.not_found');
    ob_start();
    try { require __DIR__.'/pages/workspace.php';return (string)ob_get_clean(); }
    catch (\Throwable $error) { ob_end_clean();throw $error; }
  }
}
