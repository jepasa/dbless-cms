<?php
/** Portable Menu keys: typed assignments independent of template IDs, bounded discovery and nonfatal compatibility diagnostics. */
declare(strict_types=1);
namespace DBLESSCMS;
final class MenuKeys {
  /** Validate a bounded list of unique key/type pairs; receive value, throw 422 for malformed contracts, never consult templates or write. */
  public static function validate(mixed $value): void {
    if(!is_array($value)||!array_is_list($value)||count($value)>20)throw new HttpError(422,'Invalid menu keys.','menu.error.binding');
    $seen=[];
    foreach($value as $binding){
      if(!is_array($binding)||count($binding)!==2||array_diff(array_keys($binding),['key','type'])||!is_string($binding['key']??null)||!Plugins::identifier($binding['key'])||!in_array($binding['type']??null,Navigation::TYPES,true)||isset($seen[$binding['key']]))throw new HttpError(422,'Invalid menu key.','menu.error.binding');
      $seen[$binding['key']]=true;
    }
  }
  /** Discover keys from all valid installed manifests; receive store, return technical keys/types/template counts, without rendering literal metadata or writing. */
  public static function catalogue(JsonStore $store): array {
    $keys=[];
    foreach($store->templates->all() as $id=>$manifest)foreach(ContentReferences::slots($manifest) as $key=>$slot){
      if($slot['plugin']!=='menu')continue;$location=Navigation::locations($manifest)[$slot['location']];
      $keys[$key]??=['types'=>[],'templates'=>[]];$keys[$key]['types']=array_values(array_unique([...$keys[$key]['types'],...$location['types']]));$keys[$key]['templates'][]=$id;
    }
    ksort($keys);return $keys;
  }
  /** Recognize a saved portable key even when the current template lacks it; receive store/key, return bool, no provider execution or writes. */
  public static function known(JsonStore $store,string $key): bool {
    foreach((new MenuStore($store))->read()['data']['menus'] as $menu)foreach($menu['templateKeys']??[] as $binding)if($binding['key']===$key)return true;
    return false;
  }
  /** Check one record against the active manifest; receive record/manifest/optional menu catalogue, return localized message keys/parameters; missing/type/depth conflicts are notices, no writes. */
  public static function warnings(array $menu,array $manifest,array $menus=[]): array {
    if($menu['deleted']||($menu['insertionMode']??null)!=='template'||!array_key_exists('templateKeys',$menu))return [];
    if($menu['templateKeys']===[])return [['key'=>'keysEmpty','parameters'=>[]]];
    $warnings=[];$slots=ContentReferences::slots($manifest);$locations=Navigation::locations($manifest);
    foreach($menu['templateKeys'] as $binding){
      $slot=$slots[$binding['key']]??null;
      if($slot===null||$slot['plugin']!=='menu')$reason='keyMissing';
      else{$location=$locations[$slot['location']];$reason=!in_array($binding['type'],$location['types'],true)?'keyTypeMismatch':(MenuStore::items($menu['items'])>$location['maxDepth']?'keyDepthMismatch':'');}
      if($reason===''){
        $targets=[];
        foreach($menus as $other)if(!$other['deleted']&&($other['insertionMode']??null)==='template')foreach($other['templateKeys']??[] as $entry){$otherSlot=$slots[$entry['key']]??null;if(($otherSlot['plugin']??null)==='menu'&&$otherSlot['location']===$slot['location'])$targets[$other['id'].':'.$entry['type']]=true;}
        if(count($targets)>1)$reason='keyAmbiguous';
      }
      if($reason!=='')$warnings[]=['key'=>$reason,'parameters'=>['key'=>$binding['key']]];
    }
    return $warnings;
  }
}
