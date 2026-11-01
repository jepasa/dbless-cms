<?php
/** CLI-only read-only privacy preflight; aggregate technical findings, no user payloads or automatic legal certification. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/bootstrap.php';
$arguments=array_slice($argv,1);if(count($arguments)>1||isset($arguments[0])&&$arguments[0]!=='--strict'){fwrite(STDERR,"Usage: php privacy-audit.php [--strict]\n");exit(1);}
$operation=null;$status=0;
try{$config=new \DBLESSCMS\Config();$operation=\DBLESSCMS\Lifecycle::enter($config);$result=\DBLESSCMS\PrivacyAudit::report(new \DBLESSCMS\JsonStore($config));echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";if(($arguments[0]??'')==='--strict'&&$result['technicalIssues']!==[])$status=1;}
catch(\Throwable $error){fwrite(STDERR,"Privacy preflight failed; inspect private configuration and data contracts.\n");$status=1;}
finally{\DBLESSCMS\Lifecycle::leave($operation);}
exit($status);
