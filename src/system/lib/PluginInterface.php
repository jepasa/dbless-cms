<?php
/** Contrato API 1 de extensões locais confiáveis; autenticação e CSRF pertencem ao núcleo. */
declare(strict_types=1);
namespace CMS2JSON;
interface PluginInterface {
  /** Valida configurações sem gravar; recebe dados/config; lança HttpError em contrato inválido. */
  public function validateSettings(array $settings, Config $config): void;
  /** Renderiza interface escapada; contexto integrado exige fragmento sem documento/moldura; retorna HTML sem escrita. */
  public function render(PluginContext $context): string;
  /** Executa operação autenticada; recebe contexto/nome, retorna JSON ou null após streaming; lança HttpError. */
  public function api(PluginContext $context, string $operation): ?array;
}
