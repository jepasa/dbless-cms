<?php
/** Entrada operacional exclusiva de PHP CLI: diagnóstico, manutenção, retenção, snapshot/recuperação e preparação de atualização. */
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require dirname(__DIR__).'/bootstrap.php';
use DBLESSCMS\{Config,Operations};
/** Valida inteiro decimal de opção CLI; recebe valor/default, retorna inteiro ou falha sem coerção ambígua. */
function operationInteger(?string $value,int $default): int {
  if ($value===null) return $default;
  if (!preg_match('/^[1-9][0-9]{0,9}$/D',$value)) throw new RuntimeException('Opção numérica inválida.');
  return (int)$value;
}
try {
  $arguments=array_slice($argv,1);$command=array_shift($arguments)??'';$operations=new Operations(new Config());
  $result=match($command) {
    'doctor'=>count($arguments)===0?$operations->doctor():throw new RuntimeException('doctor não recebe argumentos.'),
    'verify-package'=>count($arguments)===1?['verified_files'=>count(Operations::verifyPackage($arguments[0]))]:throw new RuntimeException('Uso: verify-package /pacote'),
    'maintenance'=>count($arguments)===1&&in_array($arguments[0],['on','off'],true)?$operations->maintenance($arguments[0]==='on'):throw new RuntimeException('Uso: maintenance on|off'),
    'cleanup'=>count($arguments)<=3?$operations->cleanup(operationInteger($arguments[0]??null,7),operationInteger($arguments[1]??null,10485760),operationInteger($arguments[2]??null,5)):throw new RuntimeException('Uso: cleanup [dias] [bytes-auditoria] [arquivos-auditoria]'),
    'backup'=>count($arguments)===1?$operations->backup($arguments[0]):throw new RuntimeException('Uso: backup /destino-novo-privado'),
    'restore'=>count($arguments)===2?$operations->restore($arguments[0],$arguments[1]):throw new RuntimeException('Uso: restore /snapshot /instalacao-nova'),
    'upgrade'=>count($arguments)===2?$operations->upgrade($arguments[0],$arguments[1]):throw new RuntimeException('Uso: upgrade /pacote /instalacao-nova'),
    default=>throw new RuntimeException('Comandos: doctor, verify-package, maintenance, cleanup, backup, restore, upgrade.'),
  };
  echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
  if ($command==='doctor'&&!$result['ready']) exit(1);
} catch (Throwable $failure) {
  // Mensagens do contrato operacional não incluem dados; erros de bibliotecas recebem diagnóstico limitado.
  fwrite(STDERR,($failure instanceof RuntimeException?$failure->getMessage():'Falha operacional: '.$failure::class)."\n");exit(1);
}
