<?php
/** Explicit CLI-only additive Blog enablement: preserves existing editorial data and other plugins, applies defaults under the CMS lifecycle/content locks. */
declare(strict_types=1);
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/bootstrap.php';
if (($argv[1]??'')!=='--enable'||count($argv)!==2) throw new InvalidArgumentException('Usage: php src/system/plugins/blog/install.php --enable');
$config=new \DBLESSCMS\Config();
\DBLESSCMS\Lifecycle::exclusive($config,static function() use($config): void {
  $store=new \DBLESSCMS\JsonStore($config);
  $store->transaction(static function() use($store): void {
    $registry=new \DBLESSCMS\Plugins($store);$state=$registry->state('blog');
    if ($state['enabled']) {echo "Blog already enabled; existing content preserved.\n";return;}
    $registry->save('blog',true,$state['settings'],$registry->document()['revision']);
    echo "Blog enabled; existing content and plugin settings preserved.\n";
  });
});
