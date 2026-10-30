<?php
/** Posicionamento Slides no editor: chaves do manifesto ativo ou referências existentes em páginas, com revisão do site. */
declare(strict_types=1);
namespace CMS2JSON;
final class SlidePlacement {
  /** Consulta manifesto, páginas/referências e vínculos do conjunto; recebe contexto/documento/ID, retorna estado sem modificar conteúdo. */
  public static function state(PluginContext $context,array $data,string $id): array {
    $site=$context->store->read('site');$template=$site['data']['template'];$manifest=$context->store->templates->get($template);
    $locations=Slides::locations($manifest);$pages=[];$selected=[];$orphaned=[];
    foreach ($context->store->pages($template) as $slug=>$page) if ((string)$slug!=='404') $pages[$slug]=['title'=>$page['data']['meta']['title'],'keys'=>Slides::references($page['data']['content']),'revision'=>$page['revision']];
    foreach ($data['assignments'] as $binding) if ($binding['collection']===$id) {
      if ($binding['template']===$template&&isset($locations[$binding['location']])) $selected[]=$binding['location'];
      else $orphaned[]=$binding['template'].' / '.$binding['location'];
    }
    return ['template'=>$template,'name'=>$manifest['name'],'revision'=>$site['revision'],'locations'=>$locations,'pages'=>$pages,'selected'=>$selected,'orphaned'=>$orphaned];
  }
  /** Grava vínculos escolhidos junto do conjunto; recebe contexto/documento/ID/POST, retorna documento; rejeita template/página obsoletos e locais inválidos sem escrita parcial. */
  public static function apply(PluginContext $context,array $data,string $id,array $post): array {
    if (!isset($post['placement_editor'])) return $data;
    if ($post['placement_editor']!=='1') throw new HttpError(422,'Seleção inválida.','slides.error.binding');
    $state=self::state($context,$data,$id);
    if (($post['placement_template']??null)!==$state['template']||($post['site_revision']??null)!==$state['revision']) throw new HttpError(409,'Template alterado.','slides.error.templateChanged');
    $mode=$post['placementMode']??null;$scope=array_keys($state['locations']);
    if ($mode==='page') {
      $page=$post['placementPage']??null;
      if (!is_string($page)||!isset($state['pages'][$page])) throw new HttpError(422,'Página inválida.','slides.error.binding');
      if (($post['page_revision']??null)!==$state['pages'][$page]['revision']) throw new HttpError(409,'Página alterada.','slides.error.pageChanged');
      $scope=array_values(array_intersect($scope,$state['pages'][$page]['keys']));
    } elseif ($mode!=='template') throw new HttpError(422,'Destino inválido.','slides.error.binding');
    $selected=$post['locations']??[];
    if (!is_array($selected)||!array_is_list($selected)||count($selected)>30) throw new HttpError(422,'Seleção inválida.','slides.error.binding');
    $bindings=[];
    foreach ($selected as $location) {
      if (!is_string($location)||!in_array($location,$scope,true)||isset($bindings[$location])) throw new HttpError(422,'Local inválido.','slides.error.binding');
      $binding=['template'=>$state['template'],'location'=>$location,'collection'=>$id];
      Slides::binding($context->store,$data,$binding);$bindings[$location]=$binding;
    }
    $data['assignments']=array_values(array_filter($data['assignments'],static fn(array $binding): bool=>
      $binding['template']!==$state['template']||(!isset($bindings[$binding['location']])&&($binding['collection']!==$id||!in_array($binding['location'],$scope,true)))));
    foreach ($bindings as $binding) $data['assignments'][]=$binding;
    return $data;
  }
}
