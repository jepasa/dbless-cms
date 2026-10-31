<?php
/** Widgets production export: private validated live records only, no trash/history or executable rendering. */
declare(strict_types=1);
namespace DBLESSCMS;
final class WidgetsExport {
  /** Export non-deleted records; receives store/staging, validates and writes fixed JSON path, throws on I/O errors. */
  public static function run(JsonStore $store,string $target): void {
    $data=(new WidgetsStore($store))->read()['data'];$data['widgets']=array_filter($data['widgets'],static fn(array $record):bool=>!$record['deleted']);
    $directory=$target.'/system/storage/contents/widgets';if(!is_dir($directory)&&!mkdir($directory,0750,true))throw new \RuntimeException('Widget export unavailable.');
    $bytes=WidgetsStore::encode(WidgetsStore::validate($data));if(file_put_contents($directory.'/widgets.json',$bytes)!==strlen($bytes))throw new \RuntimeException('Incomplete widget export.');
  }
}
