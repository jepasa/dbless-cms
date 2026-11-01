<?php
/** CLI-only explicit native Homepage conversion with three expected revisions; preserves existing HTML/source snapshots, creates private history and never runs on HTTP/render. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/bootstrap.php';
if(count($argv)!==4)throw new InvalidArgumentException('Usage: php convert-homepage.php <catalog-sha256> <site-sha256> <home-sha256>');
foreach(array_slice($argv,1) as $revision)if(!preg_match('/^[a-f0-9]{64}$/D',$revision))throw new InvalidArgumentException('Expected SHA-256 revisions.');
$config=new \DBLESSCMS\Config();
/** Execute only the authorized migration under the lifecycle lock; no arguments/return, propagate conflicts, I/O and adapter validation errors without partially replacing content. */
\DBLESSCMS\Lifecycle::exclusive($config,static function()use($config,$argv):void{
  $store=new \DBLESSCMS\JsonStore($config);(new \DBLESSCMS\Plugins($store))->instance('homepage');$result=(new \DBLESSCMS\HomepageCatalog($store))->convert($argv[1],$argv[2],$argv[3]);echo json_encode(['revision'=>$result['revision'],'mode'=>$result['data']['homepages']['legacy-home']['published']['mode']],JSON_THROW_ON_ERROR)."\n";
});
