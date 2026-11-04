<?php
/** Shared delivery channels: explicit template/Mustache exclusivity and manifest positions, with unchanged legacy reads. */
declare(strict_types=1);
namespace DBLESSCMS;
final class ContentPlacement {
  /** Check a record's delivery channel; receive validated record/channel, return bool; absent mode retains legacy access, without writes. */
  public static function allows(array $record,string $channel): bool {
    return !isset($record['insertionMode'])||$record['insertionMode']===$channel;
  }
  /** Project only declared positions for a plugin; receive manifest/plugin/typed locations, return locations with their slot keys; invalid declarations raise 422. */
  public static function locations(array $manifest,string $plugin,array $locations): array {
    $result=[];
    foreach(ContentReferences::slots($manifest) as $key=>$slot) {
      if($slot['plugin']!==$plugin)continue;
      $location=$slot['location'];
      if(!isset($result[$location]))$result[$location]=$locations[$location]+['keys'=>[]];
      $result[$location]['keys'][$key]=$slot['description'];
    }
    return $result;
  }
}
