<?php
/** Localização de menus no editor: manifesto ativo, revisão do site e vínculos preservados quando o template muda. */
declare(strict_types=1);
namespace CMS2JSON;
final class MenuPlacement {
  /** Consulta contexto de posicionamento; recebe contexto/documento/ID e retorna manifesto, locais, seleção e alertas sem escrever. */
  public static function state(PluginContext $context,array $data,string $id): array {
    $site=$context->store->read('site');$template=$site['data']['template'];$manifest=$context->store->templates->get($template);
    $locations=Navigation::locations($manifest);$selected=[];$orphaned=[];
    foreach ($data['assignments'] as $binding) if ($binding['menu']===$id) {
      if ($binding['template']===$template&&isset($locations[$binding['location']])) $selected[$binding['location']]=$binding['type'];
      else $orphaned[]=$binding['template'].' / '.$binding['location'];
    }
    return ['template'=>$template,'name'=>$manifest['name'],'revision'=>$site['revision'],'locations'=>$locations,'selected'=>$selected,'orphaned'=>$orphaned];
  }
  /** Atualiza locais escolhidos na mesma mutação editorial; recebe contexto/documento/ID/POST e retorna documento; seleção/revisão inválida gera 422/409 sem escrita. */
  public static function apply(PluginContext $context,array $data,string $id,array $post): array {
    if (!isset($post['placement_editor'])) return $data;
    if ($post['placement_editor']!=='1') throw new HttpError(422,'Seleção inválida.','menu.error.binding');
    $state=self::state($context,$data,$id);
    if (($post['placement_template']??null)!==$state['template']||($post['site_revision']??null)!==$state['revision']) throw new HttpError(409,'Template alterado.','menu.error.templateChanged');
    $selected=$post['locations']??[];$types=$post['locationTypes']??[];
    if (!is_array($selected)||!array_is_list($selected)||count($selected)>20||!is_array($types)) throw new HttpError(422,'Seleção inválida.','menu.error.binding');
    $bindings=[];
    foreach ($selected as $location) {
      if (!is_string($location)||!isset($state['locations'][$location])||isset($bindings[$location])||!is_string($types[$location]??null)) throw new HttpError(422,'Local inválido.','menu.error.binding');
      $binding=['template'=>$state['template'],'location'=>$location,'menu'=>$id,'type'=>$types[$location]];
      Navigation::binding($context->store,$data,$binding);$bindings[$location]=$binding;
    }
    $data['assignments']=array_values(array_filter($data['assignments'],static fn(array $binding): bool=>
      $binding['template']!==$state['template']||(!isset($bindings[$binding['location']])&&($binding['menu']!==$id||!isset($state['locations'][$binding['location']])))));
    foreach ($bindings as $binding) $data['assignments'][]=$binding;
    return $data;
  }
}
