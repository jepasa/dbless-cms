<?php
/** Authenticated core dashboard read model; actual page/plugin/template state, bounded trusted-plugin indicators and private cache metrics. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Dashboard {
  /** Receive store/base/locale, return measured state with localized plugin labels; no editorial writes. Isolate optional provider errors without inventing values or hiding core data errors. */
  public static function snapshot(JsonStore $store,string $base,string $locale): array {
    $site=$store->read('site')['data'];$plugins=new Plugins($store);$active=0;$indicators=[];$warnings=[];
    foreach ($plugins->all() as $id=>$manifest) {
      if (!$plugins->state($id)['enabled']) continue;$active++;
      try {
        $instance=$plugins->instance($id);if (!$instance instanceof DashboardProviderInterface) continue;
        $context=$plugins->context($id,$base,$locale,'',true);$items=$instance->dashboard($context);
        if (count($items)>8) throw new \UnexpectedValueException('Too many indicators.');
        $language=$plugins->language($id,$locale);
        foreach ($items as $item) {
          if (!is_array($item)||count($item)!==2||array_diff_key($item,['label'=>true,'value'=>true])!==[]||!is_string($item['label'])||!$language->has($item['label'])||!is_int($item['value'])||$item['value']<0) throw new \UnexpectedValueException('Invalid dashboard indicator.');
        }
        foreach ($items as $item) $indicators[]=['plugin'=>$manifest['name'],'label'=>$language->text($item['label']),'value'=>$item['value']];
      } catch (\Throwable $error) {$warnings[]=$manifest['name'];error_log('DBLESS-CMS dashboard provider '.$id.' '.$error::class);}
    }
    $published=0;$drafts=0;
    foreach ($store->pages($site['template']) as $slug=>$page) {if ($slug==='404') continue;if ($page['data']['status']==='published') $published++;else $drafts++;}
    return ['published'=>$published,'drafts'=>$drafts,'plugins'=>$active,'template'=>$store->templates->get($site['template'])['name'],'indicators'=>$indicators,'warnings'=>$warnings,'cache'=>(new Cache($store->config,$site['settings']['cache']??[]))->snapshot()];
  }
}
