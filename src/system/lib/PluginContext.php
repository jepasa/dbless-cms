<?php
/** Contexto explícito de plugin: serviços privados e tradução própria sem expor credenciais no cliente. */
declare(strict_types=1);
namespace CMS2JSON;
final class PluginContext {
  /** Armazena serviços, configuração validada e modo de layout integrado; sem escrita; falhas são propagadas pelo chamador. */
  public function __construct(public readonly Config $config, public readonly JsonStore $store, public readonly array $manifest, public readonly array $settings, public readonly string $revision, public readonly string $base, public readonly Language $language, public readonly string $route='', public readonly bool $integrated=false) {}
  /** Resolve rota interna; recebe operação opcional e retorna URL sem acesso ao filesystem. */
  public function url(string $operation = ''): string {
    return $this->base.'/cms-admin/?view='.($operation === '' ? 'plugin' : 'plugin-api').'&plugin='.rawurlencode($this->manifest['id']).($this->route===''?'':'&route='.rawurlencode($this->route)).($operation === '' ? '' : '&operation='.rawurlencode($operation));
  }
}
