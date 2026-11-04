<?php
/** Menu delivery editor: portable v2 keys and nonfatal compatibility, with isolated v1 template/revision compatibility. */
declare(strict_types=1);
namespace DBLESSCMS;
final class MenuPlacement {
  /** Build portable editor choices across installed templates plus preserved keys; receive context/document/ID, return technical choices and translated warning descriptors, no writes. */
  public static function keys(PluginContext $context,array $data,string $id): array {
    $record=$data['menus'][$id]??['id'=>'','name'=>'','type'=>'list','items'=>[],'deleted'=>false];$selected=[];$legacy=false;
    if(array_key_exists('templateKeys',$record))foreach($record['templateKeys'] as $binding)$selected[$binding['key']]=$binding['type'];
    else foreach($data['assignments'] as $binding)if($binding['menu']===$id){
      $legacy=true;
      try{$manifest=$context->store->templates->get($binding['template']);}catch(HttpError $error){if(!in_array($error->status,[404,422],true))throw $error;continue;}
      foreach(ContentReferences::slots($manifest) as $key=>$slot)if($slot['plugin']==='menu'&&$slot['location']===$binding['location'])$selected[$key]??=$binding['type'];
    }
    $choices=MenuKeys::catalogue($context->store);
    foreach($selected as $key=>$type){$choices[$key]??=['types'=>[],'templates'=>[]];if(!in_array($type,$choices[$key]['types'],true))$choices[$key]['types'][]=$type;}
    ksort($choices);$mode=$record['insertionMode']??($legacy?'template':'mustache');
    $preview=$record+['insertionMode'=>$mode];$preview['templateKeys']=array_map(static fn(string $key):array=>['key'=>$key,'type'=>$selected[$key]],array_keys($selected));
    $manifest=$context->store->templates->get($context->store->read('site')['data']['template']);
    $menus=$data['menus'];$menus[$id]=$preview;
    $owners=[];foreach($data['menus'] as $other)if(!$other['deleted']&&$other['id']!==$id&&($other['insertionMode']??null)==='template')foreach($other['templateKeys']??[] as $binding)$owners[$binding['key']]=$other['name'];
    return ['choices'=>$choices,'selected'=>$selected,'mode'=>$mode,'legacy'=>$legacy&&!array_key_exists('templateKeys',$record),'warnings'=>MenuKeys::warnings($preview,$manifest,$menus),'owners'=>$owners];
  }
  /** Apply validated portable keys in the enclosing menu CAS transaction; receive context/document/ID/POST, return document, preserve legacy/inactive keys and reject only malformed contracts. */
  private static function applyKeys(PluginContext $context,array $data,string $id,array $post): array {
    $mode=$post['placementMode']??null;if(!in_array($mode,['template','mustache'],true))throw new HttpError(422,'Invalid insertion mode.','menu.error.binding');
    $record=$data['menus'][$id];$data['menus'][$id]['insertionMode']=$mode;
    if($mode==='mustache'){
      if(($post['keys']??[])!==[])throw new HttpError(422,'Unexpected template keys.','menu.error.binding');
      if(!array_key_exists('templateKeys',$record)){$selected=self::keys($context,$data,$id)['selected'];$data['menus'][$id]['templateKeys']=array_map(static fn(string $key):array=>['key'=>$key,'type'=>$selected[$key]],array_keys($selected));}
      return $data;
    }
    $selected=$post['keys']??array_column($record['templateKeys']??[],'key');$types=$post['keyTypes']??array_column($record['templateKeys']??[],'type','key');
    if(!is_array($selected)||!array_is_list($selected)||count($selected)>20||!is_array($types))throw new HttpError(422,'Invalid keys.','menu.error.binding');
    $bindings=[];foreach($selected as $key)$bindings[]=['key'=>$key,'type'=>is_string($key)?($types[$key]??($record['type']??'list')):''];MenuKeys::validate($bindings);
    $data['menus'][$id]['templateKeys']=$bindings;
    foreach($data['menus'] as $otherId=>&$other)if($otherId!==$id&&!$other['deleted']&&($other['insertionMode']??null)==='template'&&isset($other['templateKeys']))$other['templateKeys']=array_values(array_filter($other['templateKeys'],static fn(array $b):bool=>!in_array($b['key'],$selected,true)));unset($other);
    return $data;
  }
  /** Read active positions/selection and infer legacy mode; receive context/document/ID, return editor state with no writes; propagate invalid manifests. */
  public static function state(PluginContext $context,array $data,string $id): array {
    $site=$context->store->read('site');$template=$site['data']['template'];$manifest=$context->store->templates->get($template);
    $locations=ContentPlacement::locations($manifest,'menu',Navigation::locations($manifest));$selected=[];$orphaned=[];$assigned=false;
    foreach($data['assignments'] as $binding)if($binding['menu']===$id){
      $assigned=true;
      if($binding['template']===$template&&isset($locations[$binding['location']]))$selected[$binding['location']]=$binding['type'];
      else $orphaned[]=$binding['template'].' / '.$binding['location'];
    }
    $record=$data['menus'][$id]??[];
    return ['template'=>$template,'name'=>$manifest['name'],'revision'=>$site['revision'],'locations'=>$locations,'selected'=>$selected,'orphaned'=>$orphaned,'mode'=>$record['insertionMode']??($assigned?'template':'mustache'),'legacy'=>$record!==[]&&!isset($record['insertionMode'])];
  }
  /** Dispatch portable v2 or legacy v1 placement inside menu CAS; receive context/document/ID/POST, return document; reject malformed/stale legacy requests and preserve inactive bindings. */
  public static function apply(PluginContext $context,array $data,string $id,array $post): array {
    if(!isset($post['placement_editor']))return $data;
    if($post['placement_editor']==='2')return self::applyKeys($context,$data,$id,$post);
    if($post['placement_editor']!=='1')throw new HttpError(422,'Invalid placement.','menu.error.binding');
    if(array_key_exists('templateKeys',$data['menus'][$id])){if(($post['locations']??[])!==[])throw new HttpError(422,'Use the portable key editor.','menu.error.binding');$post['placementMode']??=$data['menus'][$id]['insertionMode'];return self::applyKeys($context,$data,$id,$post);}
    $state=self::state($context,$data,$id);
    if(($post['placement_template']??null)!==$state['template']||($post['site_revision']??null)!==$state['revision'])throw new HttpError(409,'Template changed.','menu.error.templateChanged');
    $mode=$post['placementMode']??($data['menus'][$id]['insertionMode']??'template');
    if(!in_array($mode,['template','mustache'],true))throw new HttpError(422,'Invalid insertion mode.','menu.error.binding');
    $selected=$post['locations']??[];
    if(!is_array($selected)||!array_is_list($selected)||count($selected)>20||($mode==='mustache'&&$selected!==[]))throw new HttpError(422,'Invalid selection.','menu.error.binding');
    $data['menus'][$id]['insertionMode']=$mode;
    if($mode==='mustache')return $data;
    $existing=array_filter($data['assignments'],static fn(array $b):bool=>$b['menu']===$id);
    if($selected===[]&&$existing===[])throw new HttpError(422,'Select a template position.','menu.error.positionRequired');
    $bindings=[];
    $types=$post['locationTypes']??[];if(!is_array($types))throw new HttpError(422,'Invalid types.','menu.error.binding');
    foreach($selected as $location){
      if(!is_string($location)||!isset($state['locations'][$location])||isset($bindings[$location]))throw new HttpError(422,'Invalid position.','menu.error.binding');
      $binding=['template'=>$state['template'],'location'=>$location,'menu'=>$id,'type'=>$types[$location]??''];
      Navigation::binding($context->store,$data,$binding);$bindings[$location]=$binding;
    }
    $data['assignments']=array_values(array_filter($data['assignments'],static fn(array $b):bool=>
      $b['template']!==$state['template']||(!isset($bindings[$b['location']])&&($b['menu']!==$id||!isset($state['locations'][$b['location']])))));
    foreach($bindings as $binding)$data['assignments'][]=$binding;
    return $data;
  }
}
