<?php
/** Roteador privado do File Manager; o plugin despacha suas páginas declaradas sem rotas de negócio no núcleo. */
declare(strict_types=1);
namespace CMS2JSON;
final class FilesManagerRouter {
  /** Renderiza rota própria autorizada no contexto; recebe contexto e retorna fragmento integrado ou documento compatível conforme contexto; falha sem resolver caminhos externos. */
  public static function render(PluginContext $context): string {
    $route=$context->manifest['routes'][$context->route]??null;
    if (!is_array($route) || !preg_match('~^pages/[a-z][a-z0-9-]*\.php$~D',$route['file']??'')) throw new HttpError(404,'Página não encontrada.','error.not_found');
    $file=__DIR__.'/'.$route['file'];
    if (is_link(__DIR__.'/pages') || is_link($file) || !is_file($file)) throw new HttpError(404,'Página indisponível.','error.not_found');
    ob_start();
    try { require $file; return (string)ob_get_clean(); }
    catch (\Throwable $failure) { ob_end_clean(); throw $failure; }
  }
}
