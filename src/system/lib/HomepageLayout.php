<?php
/** Optional homepage composition contract shared by renderers and editors; template metadata stays static, editorial order/visibility stays in the page. */
declare(strict_types=1);
namespace DBLESSCMS;
final class HomepageLayout {
  /** Validate static native section declarations and trusted partial identifiers; receive manifest, return section definitions or 422 without executing files. */
  public static function definition(array $manifest): array {
    if(!isset($manifest['homepage']))return [];$definition=$manifest['homepage'];
    if(!is_array($definition)||count($definition)!==2||!is_array($definition['sections']??null)||count($definition['sections'])>20||!is_string($definition['sectionPartial']??null)||!isset($manifest['partials'][$definition['sectionPartial']]))throw new HttpError(422,'Invalid homepage manifest.','components.error');
    foreach($definition['sections'] as $key=>$section){
      if(!is_string($key)||!preg_match('/^[a-z][a-z0-9-]{0,47}$/D',$key)||!is_array($section)||count($section)!==3||!is_string($section['name']??null)||trim($section['name'])===''||strlen($section['name'])>200||preg_match('/[\x00-\x1F\x7F<>]/',$section['name'])||!is_bool($section['required']??null)||!is_string($section['partial']??null)||!isset($manifest['partials'][$section['partial']]))throw new HttpError(422,'Invalid homepage section.','components.error');
    }
    return $definition['sections'];
  }
  /** Validate the optional portable page composition and required native sections; receive document/manifest, throw 422 for collisions, unsafe IDs or hiding mandatory content, no writes. */
  public static function validate(array $document,array $manifest): void {
    if(!isset($document['homepage']))return;
    if(($document['slug']??null)!=='home'||($document['page']??null)!=='home')throw new HttpError(422,'Homepage composition is reserved.','components.error');
    $layout=$document['homepage'];$definitions=self::definition($manifest);$seen=[];
    foreach(['order','hidden'] as $field){$keys=[];foreach($layout[$field] as $key){if(!preg_match('/^[a-z][a-z0-9-]{0,47}$/D',$key)||isset($keys[$key]))throw new HttpError(422,'Invalid homepage ordering.','components.error');$keys[$key]=true;}}
    foreach($layout['sections'] as $section){$key=$section['key'];if(!preg_match('/^section-[a-z0-9-]{1,39}$/D',$key)||isset($seen[$key])||isset($definitions[$key]))throw new HttpError(422,'Invalid custom section ID.','components.error');$seen[$key]=true;}
    foreach($definitions as $key=>$definition)if($definition['required']&&in_array($key,$layout['hidden'],true))throw new HttpError(422,'Required section cannot be hidden.','components.error');
  }
  /** Build ordered native/custom section rows, preserving unsupported saved IDs as diagnostics; receive manifest/document/visibility flag, return bounded read-only entries. */
  public static function entries(array $manifest,array $document,bool $visibleOnly=true): array {
    $definitions=self::definition($manifest);$layout=$document['homepage']??['order'=>[],'hidden'=>[],'sections'=>[]];$entries=[];
    foreach($definitions as $key=>$definition)$entries[$key]=['key'=>$key,'name'=>$definition['name'],'required'=>$definition['required'],'visible'=>$definition['required']||!in_array($key,$layout['hidden'],true),'partial'=>$definition['partial'],'section'=>null];
    foreach($layout['sections'] as $section)$entries[$section['key']]=['key'=>$section['key'],'name'=>$section['title']?:$section['key'],'required'=>false,'visible'=>$section['visible'],'partial'=>null,'section'=>$section];
    $result=[];foreach(array_unique(array_merge($layout['order'],array_keys($entries))) as $key)if(isset($entries[$key])&&(!$visibleOnly||$entries[$key]['visible']))$result[]=$entries[$key];return $result;
  }
  /** Report unsupported saved native section references without deleting data; receive manifest/document, return opaque IDs for translated admin diagnostics, no writes. */
  public static function warnings(array $manifest,array $document): array {
    $known=array_column(self::entries($manifest,$document,false),'key');$layout=$document['homepage']??[];
    return array_values(array_diff(array_unique(array_merge($layout['order']??[],$layout['hidden']??[])),$known));
  }
}
