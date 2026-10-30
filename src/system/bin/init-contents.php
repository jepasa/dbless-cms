<?php
/** Inicialização CLI explícita: instala conteúdo de exemplo uma única vez, sem substituir conteúdo editorial persistente. */
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once dirname(__DIR__).'/bootstrap.php';
$config=new \DBLESSCMS\Config();$target=$config->data['contents'];$source=$config->root.'/resources/contents';
if (is_link($target)) throw new RuntimeException('Destino de conteúdo inseguro.');
if (is_file($target.'/site.json')) {echo "Conteúdo existente preservado.\n";exit;}
if (is_dir($target)) throw new RuntimeException('Conteúdo parcial existente; revisar antes da inicialização.');
\DBLESSCMS\Security::directory(dirname($target));
$temporary=dirname($target).'/.contents-'.bin2hex(random_bytes(8));
if (!mkdir($temporary,0700)) throw new RuntimeException('Inicialização indisponível.');
try {
  $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
  foreach ($iterator as $entry) {
    if ($entry->isLink()) throw new RuntimeException('Link proibido na distribuição inicial.');
    $relative=substr($entry->getPathname(),strlen($source)+1);$destination=$temporary.'/'.$relative;
    if ($entry->isDir()) {if(!mkdir($destination,0700))throw new RuntimeException('Diretório indisponível.');continue;}
    if (!$entry->isFile()||!str_ends_with($relative,'.json')) throw new RuntimeException('Recurso inicial inválido.');
    $raw=file_get_contents($entry->getPathname());if($raw===false)throw new RuntimeException('Leitura indisponível.');json_decode($raw,true,64,JSON_THROW_ON_ERROR);
    if (file_put_contents($destination,$raw)!==strlen($raw)||!chmod($destination,0660)) throw new RuntimeException('Gravação incompleta.');
  }
  if (file_exists($target)||is_link($target)||!rename($temporary,$target)) throw new RuntimeException('Destino mudou durante a inicialização.');
  echo "Conteúdo inicial instalado em storage/contents.\n";
} finally {
  if (is_dir($temporary)) {
    $cleanup=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($cleanup as $entry) {if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($temporary);
  }
}
