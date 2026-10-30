<?php
/** Erro HTTP controlado; mensagens públicas não devem conter conteúdo ou segredos. */
declare(strict_types=1);
namespace CMS2JSON;
final class HttpError extends \RuntimeException {
  /** Recebe status, mensagem pública de compatibilidade, chave e parâmetros; inicializa erro traduzível sem efeitos. */
  public function __construct(public readonly int $status, string $message, public readonly string $messageKey = 'error.request', public readonly array $parameters = []) { parent::__construct($message); }
}
