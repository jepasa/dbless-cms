<?php
/** Coordenação operacional: requisições compartilham trava; operações CLI exclusivas preservam snapshots e manutenção. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Lifecycle {
  /** Rejeita caminhos relativos, segmentos especiais e links ancestrais; recebe absoluto e retorna caminho normalizado ou RuntimeException. */
  public static function path(string $path): string {
    if (!str_starts_with($path,'/') || str_contains($path,"\0") || preg_match('~(?:^|/)\.{1,2}(?:/|$)~',$path)) throw new \RuntimeException('Caminho operacional inválido.');
    $current='';
    foreach (explode('/',trim($path,'/')) as $part) {
      if ($part==='') continue;
      $current.='/'.$part;
      if (is_link($current)) throw new \RuntimeException('Links não são permitidos na operação.');
    }
    return $current===''?'/':$current;
  }
  /** Abre trava privada sem seguir links; recebe configuração e retorna recurso; falhas encerram a operação. */
  private static function lock(Config $config): mixed {
    $directory=self::path($config->data['storage']);Security::directory($directory);
    $file=self::path($directory.'/operations.lock');
    $stream=fopen($file,'c+');
    if ($stream===false || !chmod($file,0600)) throw new \RuntimeException('Trava operacional indisponível.');
    return $stream;
  }
  /** Reserva leitura durante toda requisição; recebe configuração e retorna recurso ou 503/Retry-After durante manutenção/operação. */
  public static function enter(Config $config): mixed {
    $stream=self::lock($config);
    if (!flock($stream,LOCK_SH|LOCK_NB)) {fclose($stream);header('Retry-After: 60');throw new HttpError(503,'Serviço temporariamente em manutenção.','error.maintenance');}
    $marker=self::path($config->data['storage'].'/maintenance.json');
    if (file_exists($marker)) {self::leave($stream);header('Retry-After: 60');throw new HttpError(503,'Serviço temporariamente em manutenção.','error.maintenance');}
    return $stream;
  }
  /** Libera trava de requisição, inclusive em falha; recebe recurso/nulo, sem retorno ou escrita de conteúdo. */
  public static function leave(mixed $stream): void {
    if (is_resource($stream)) {flock($stream,LOCK_UN);fclose($stream);}
  }
  /** Executa callback CLI exclusivo; recebe configuração/callback e retorna resultado; recusa concorrência sem mutação parcial. */
  public static function exclusive(Config $config,callable $operation): mixed {
    if (PHP_SAPI!=='cli') throw new \RuntimeException('Operação exclusiva do terminal.');
    $stream=self::lock($config);
    try {
      if (!flock($stream,LOCK_EX|LOCK_NB)) throw new \RuntimeException('Há requisições ou operações em andamento; tente novamente.');
      return $operation();
    } finally {self::leave($stream);}
  }
}
