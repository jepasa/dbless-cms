<?php
/** Extensão opcional API 1 para páginas públicas; não reutiliza operações administrativas anônimas. */
declare(strict_types=1);
namespace DBLESSCMS;
interface PublicPluginInterface {
  /** Atende rota pública validada; recebe contexto público mínimo e retorna corpo; valida seus dados e lança HttpError em falhas esperadas. */
  public function publicPage(PublicPluginContext $context): string;
}
