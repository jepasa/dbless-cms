<?php
/** Provisiona hash Argon2id para app.json de produção; senha vem somente de STDIN, nunca de argumento. */
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
fwrite(STDERR,"Senha (mínimo 16 caracteres; entrada não será exibida em terminal interativo): ");
$tty=function_exists('stream_isatty') && stream_isatty(STDIN);
if ($tty) system('stty -echo');
try { $password=rtrim((string)fgets(STDIN),"\r\n"); }
finally { if ($tty) { system('stty echo'); fwrite(STDERR,"\n"); } }
if (strlen($password)<16 || strlen($password)>200) { fwrite(STDERR,"Senha deve conter entre 16 e 200 bytes.\n"); exit(1); }
echo password_hash($password,PASSWORD_ARGON2ID)."\n";
