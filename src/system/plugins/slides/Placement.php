<?php
/** Slides delivery editor: active declared positions, persisted channels and preserved legacy bindings under site revision. */
declare(strict_types=1);
namespace DBLESSCMS;
final class SlidePlacement {
  /** Read active positions/selection and infer legacy mode; receive context/document/ID, return editor state with no writes; propagate invalid manifests. */
  public static function state(PluginContext $context,array $data,string $id): array {
    $site=$context->store->read('site');$template=$site['data']['template'];$manifest=$context->store->templates->get($template);
    $locations=ContentPlacement::locations($manifest,'slides',Slides::locations($manifest));$selected=[];$orphaned=[];$assigned=false;
    foreach($data['assignments'] as $binding)if($binding['collection']===$id){
      $assigned=true;
      if($binding['template']===$template&&isset($locations[$binding['location']]))$selected[$binding['location']]=true;
      else $orphaned[]=$binding['template'].' / '.$binding['location'];
    }
    $record=$data['collections'][$id]??[];
    return ['template'=>$template,'name'=>$manifest['name'],'revision'=>$site['revision'],'locations'=>$locations,'selected'=>$selected,'orphaned'=>$orphaned,'mode'=>$record['insertionMode']??($assigned?'template':'mustache'),'legacy'=>$record!==[]&&!isset($record['insertionMode'])];
  }
  /** Save explicit channel and current declared bindings atomically; receive context/document/ID/POST, return document; reject stale/forged/retired selections, preserve other bindings. */
  public static function apply(PluginContext $context,array $data,string $id,array $post): array {
    if(!isset($post['placement_editor']))return $data;
    if($post['placement_editor']!=='1')throw new HttpError(422,'Invalid placement.','slides.error.binding');
    $state=self::state($context,$data,$id);
    if(($post['placement_template']??null)!==$state['template']||($post['site_revision']??null)!==$state['revision'])throw new HttpError(409,'Template changed.','slides.error.templateChanged');
    $mode=$post['placementMode']??null;
    if(!in_array($mode,['template','mustache'],true))throw new HttpError(422,'Invalid insertion mode.','slides.error.binding');
    $selected=$post['locations']??[];
    if(!is_array($selected)||!array_is_list($selected)||count($selected)>30||($mode==='mustache'&&$selected!==[]))throw new HttpError(422,'Invalid selection.','slides.error.binding');
    $data['collections'][$id]['insertionMode']=$mode;
    if($mode==='mustache')return $data;
    $existing=array_filter($data['assignments'],static fn(array $b):bool=>$b['collection']===$id);
    if($selected===[]&&$existing===[])throw new HttpError(422,'Select a template position.','slides.error.positionRequired');
    $bindings=[];

    foreach($selected as $location){
      if(!is_string($location)||!isset($state['locations'][$location])||isset($bindings[$location]))throw new HttpError(422,'Invalid position.','slides.error.binding');
      $binding=['template'=>$state['template'],'location'=>$location,'collection'=>$id];
      Slides::binding($context->store,$data,$binding);$bindings[$location]=$binding;
    }
    $data['assignments']=array_values(array_filter($data['assignments'],static fn(array $b):bool=>
      $b['template']!==$state['template']||(!isset($bindings[$b['location']])&&($b['collection']!==$id||!isset($state['locations'][$b['location']])))));
    foreach($bindings as $binding)$data['assignments'][]=$binding;
    return $data;
  }
}
