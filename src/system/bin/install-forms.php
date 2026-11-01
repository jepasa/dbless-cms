<?php
/** CLI-only additive Forms installation; preserves existing catalog/settings/content, optionally enables the plugin with explicit --enable, never creates a live collecting form. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/plugins/forms/Plugin.php';
$arguments=array_slice($argv,1);if(count($arguments)>1||isset($arguments[0])&&$arguments[0]!=='--enable'){fwrite(STDERR,"Usage: php install-forms.php [--enable]\n");exit(1);}
$operation=null;$status=0;
try{$config=new \DBLESSCMS\Config();$operation=\DBLESSCMS\Lifecycle::enter($config);$store=new \DBLESSCMS\JsonStore($config);$store->transaction(function()use($store,$arguments):void{$registry=new \DBLESSCMS\Plugins($store);$registry->get('forms');$state=$store->read('plugins');$next=$state['data'];if(!isset($next['plugins']['forms']))$next['plugins']['forms']=['enabled'=>false,'settings'=>[]];if(($arguments[0]??'')==='--enable')$next['plugins']['forms']['enabled']=true;if($next!==$state['data'])$store->save('plugins',\DBLESSCMS\JsonStore::encode($next),$state['revision']);});echo "Forms available. Existing content preserved; configure purposes/notices before collection.\n";}
catch(\Throwable $error){fwrite(STDERR,"Forms installation failed; review private configuration and plugin contracts.\n");$status=1;}
finally{\DBLESSCMS\Lifecycle::leave($operation);}
exit($status);
