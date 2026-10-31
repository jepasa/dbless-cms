<?php
/** CLI-only additive Homepage activation for existing installations; preserves all existing plugin settings/editorial content and uses lifecycle/shared revision locks. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/bootstrap.php';
if(($argv[1]??'')!=='--enable'||count($argv)!==2)throw new InvalidArgumentException('Usage: php src/system/bin/install-homepage.php --enable');
$config=new \DBLESSCMS\Config();
/** Enable Homepage under exclusive lifecycle coordination; no arguments/return, writes only the revisioned plugin registry and core backup/audit if not already active. */
\DBLESSCMS\Lifecycle::exclusive($config,static function()use($config):void{
  $store=new \DBLESSCMS\JsonStore($config);
  /** Atomically preserve other plugin states while activating Homepage; no arguments/return, errors propagate without copying examples or credentials. */
  $store->transaction(static function()use($store):void{
    $plugins=new \DBLESSCMS\Plugins($store);$state=$plugins->state('homepage');if($state['enabled']){echo "Homepage already active; existing content preserved.\n";return;}
    $plugins->save('homepage',true,$state['settings'],$plugins->document()['revision']);echo "Homepage activated; existing content and plugin settings preserved.\n";
  });
});
