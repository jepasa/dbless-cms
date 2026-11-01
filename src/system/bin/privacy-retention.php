<?php
/** CLI-only Forms receipt/content retention: defaults to simulation; scoped fixed private state, synthetic tests, no deletion of legal logs/mailboxes/backups. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/plugins/forms/Plugin.php';
$arguments=array_slice($argv,1);
if(count($arguments)>1||isset($arguments[0])&&!in_array($arguments[0],['--dry-run','--apply'],true)){fwrite(STDERR,"Usage: php privacy-retention.php [--dry-run|--apply]\n");exit(1);}
$operation=null;$status=0;
try{$config=new \DBLESSCMS\Config();$operation=\DBLESSCMS\Lifecycle::enter($config);$store=new \DBLESSCMS\JsonStore($config);echo json_encode(\DBLESSCMS\FormsDelivery::cleanup($store,($arguments[0]??'--dry-run')==='--apply'),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";}
catch(\Throwable $error){fwrite(STDERR,"Privacy retention failed; inspect private storage and operational configuration.\n");$status=1;}
finally{\DBLESSCMS\Lifecycle::leave($operation);}
exit($status);
