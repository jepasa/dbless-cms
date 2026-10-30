<?php
/** Opções administrativas: leitura JSON limitada e fragmento reutilizável do editor global, sem rotas de negócio próprias. */
declare(strict_types=1);
namespace DBLESSCMS;
final class PluginOptions {
  /** Lê documento de opções; recebe POST/fallback legado, retorna objeto tipado; JSON inválido/excessivo gera 422 sem escrita. */
  public static function input(array $post,array $fallback): array {
    if (!array_key_exists('document',$post)) return $fallback;
    $raw=$post['document'];
    if (!is_string($raw)||strlen($raw)>65536) throw new HttpError(422,'Configuração inválida.','error.plugin');
    try { $value=json_decode($raw,false,16,JSON_THROW_ON_ERROR); }
    catch (\JsonException) { throw new HttpError(422,'JSON inválido.','editor.invalid_json'); }
    if (!$value instanceof \stdClass) throw new HttpError(422,'Configuração deve ser um objeto.','error.plugin');
    return json_decode($raw,true,16,JSON_THROW_ON_ERROR);
  }
  /** Renderiza Campos/JSON; recebe contexto/operação/atributo/help/opções dinâmicas e retorna HTML; propaga falhas de leitura. */
  public static function render(PluginContext $context,string $operation,string $attribute,string $help,array $choices=[]): string {
    if (!preg_match('/^data-[a-z-]+$/D',$attribute)) throw new \LogicException('Atributo de formulário inválido.');
    $registry=new Plugins($context->store);$schema=$registry->schema($context->manifest['id']);
    $ui=new Language();$ui->select($context->language->locale());
    ob_start();
    try {require dirname(__DIR__).'/admin/plugin-options.php';return (string)ob_get_clean();}
    catch (\Throwable $error) {ob_end_clean();throw $error;}
  }
  /** Renderiza histórico individual de opções; recebe contexto/atributo do formulário, retorna HTML e propaga falhas de leitura. */
  public static function history(PluginContext $context,string $attribute): string {
    if (!preg_match('/^data-[a-z-]+$/D',$attribute)) throw new \LogicException('Atributo de formulário inválido.');
    $ui=new Language();$ui->select($context->language->locale());
    ob_start();
    try {require dirname(__DIR__).'/admin/plugin-options-history.php';return (string)ob_get_clean();}
    catch (\Throwable $error) {ob_end_clean();throw $error;}
  }
}
