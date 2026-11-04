<?php
/** Explicit CLI-only Posts enablement under lifecycle/CAS locks; preserves existing source catalogs, history, other extensions and credentials. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/bootstrap.php';
if(($argv[1]??'')!=='--enable'||count($argv)!==2)throw new InvalidArgumentException('Usage: php src/system/bin/install-posts.php --enable');
$config=new \DBLESSCMS\Config();
/** Enable the consolidated owner in the selected trusted installation; receive config, write only revisioned plugin configuration, propagate validation/conflict failures. */
\DBLESSCMS\Lifecycle::exclusive($config,static function()use($config):void{
  $store=new \DBLESSCMS\JsonStore($config);
  /** Apply additive owner defaults atomically; no input/return value, preserve all source data and fail before mutation on invalid routes/settings. */
  $store->transaction(static function()use($store):void{
    $registry=new \DBLESSCMS\Plugins($store);$state=$registry->state('posts');
    if($state['enabled']){echo "Posts already enabled; existing content preserved.\n";return;}
    $registry->save('posts',true,$state['settings'],$registry->document()['revision']);
    echo "Posts enabled; existing content and plugin settings preserved.\n";
  });
});
