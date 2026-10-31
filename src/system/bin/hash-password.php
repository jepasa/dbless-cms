<?php
/** Provisiona hash Argon2id para app.json de produção; senha vem somente de STDIN, nunca de argumento. */
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/bootstrap.php';
fwrite(STDERR,"Senha (mínimo 16 caracteres; entrada não será exibida em terminal interativo): ");
$tty=function_exists('stream_isatty') && stream_isatty(STDIN);
if ($tty) system('stty -echo');
try { $password=rtrim((string)fgets(STDIN, 803),"\r\n"); }
finally { if ($tty) { system('stty echo'); fwrite(STDERR,"\n"); } }
try { DBLESSCMS\Passwords::provision($password); }
catch (InvalidArgumentException $error) { fwrite(STDERR,$error->getMessage()."\n"); exit(1); }
echo password_hash($password,PASSWORD_ARGON2ID,['memory_cost'=>65536,'time_cost'=>4,'threads'=>1])."\n";
