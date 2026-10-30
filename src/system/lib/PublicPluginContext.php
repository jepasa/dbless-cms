<?php
/** Contexto público de plugin sem Config/JsonStore/credenciais; somente campos explicitamente publicáveis. */
declare(strict_types=1);
namespace DBLESSCMS;
final class PublicPluginContext {
  /** Armazena identidade/rota/origem/idioma/dados públicos/CSRF/método já validados; não realiza I/O. */
  public function __construct(public readonly string $id,public readonly string $route,public readonly string $base,public readonly Language $language,public readonly array $settings,public readonly string $csrfToken,public readonly string $method) {}
  /** Resolve URL pública própria; recebe rota opcional, retorna URL local; rejeita segmentos externos ou inválidos. */
  public function url(?string $route=null): string {
    $route??=$this->route;
    if (!Plugins::identifier($route)) throw new \InvalidArgumentException('Identificador de rota pública inválido.');
    $locale=$this->language->locale();
    return $this->base.'/plugins/'.rawurlencode($this->id).'/'.rawurlencode($route).($locale==='pt-br'?'':'?locale='.rawurlencode($locale));
  }
}
