<?php
/** Interface administrativa Menu: catálogo, editor, associações, histórico e lixeira no tema fixo do CMS. */
declare(strict_types=1);
use CMS2JSON\{MenuStore,Navigation,Icons,Security,HttpError};
use function CMS2JSON\{e,asset};
/** Traduz controles administrativos; recebe chave/parâmetros e retorna texto sem escape, sem efeitos. */
$t=static fn(string $key,array $parameters=[]): string=>$context->language->text('menu.'.$key,$parameters);
/** Resolve página privada do plugin; recebe rota/ID e retorna URL local escapável, sem escrita. */
$url=static fn(string $route,string $id=''): string=>$context->base.'/cms-admin/?view=plugin-panel&plugin=menu&route='.$route.($id!==''?'&id='.rawurlencode($id):'');
$repository=new MenuStore($context->store);$snapshot=$repository->read();$data=$snapshot['data'];$revision=$snapshot['revision'];$csrf=Security::token();$route=$context->route;
$pages=$context->store->pages();$templates=$context->store->templates->all();$id=$_GET['id']??'';
if (!is_string($id)||($id!==''&&!MenuStore::identifier($id))) throw new HttpError(404,'Menu inválido.','menu.error.missing');
$editing=$data['menus'][$id]??['id'=>'','name'=>'','deleted'=>false,'items'=>[]];
if ($route==='editor'&&$id!==''&&(!isset($data['menus'][$id])||$editing['deleted'])) throw new HttpError(404,'Menu indisponível.','menu.error.missing');
$issues=[];$unavailable=[];
foreach ($data['menus'] as $menu) if (!$menu['deleted']) foreach ($menu['items'] as $item) if (in_array($item['kind'],['page','anchor'],true)&&(!isset($pages[$item['page']])||$pages[$item['page']]['data']['status']!=='published')) $issues[]=$menu['name'].' / '.$item['label'];
foreach ($data['assignments'] as $binding) {
  try { Navigation::binding($context->store,$data,$binding); }
  catch (HttpError $error) { if (!in_array($error->status,[404,422],true)) throw $error;$unavailable[]=$binding['template'].' / '.$binding['location']; }
}
?>
<?php if (!$context->integrated): ?><!doctype html><html lang="<?= e($context->language->locale()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($t('name')) ?></title><link rel="stylesheet" href="<?= e(asset('cms-admin/assets/admin.css',$context->base)) ?>"><script defer src="<?= e(asset('cms-admin/assets/admin.js',$context->base)) ?>"></script><link rel="stylesheet" href="<?= e(asset('plugins/menu/css/menu.css',$context->base)) ?>"><script src="<?= e(asset('plugins/menu/js/menu.js',$context->base)) ?>" defer></script></head><body class="plugin-standalone plugin-content"><?php endif; ?>
<div class="menu-plugin" data-menu-plugin data-network-error="<?= e($t('network')) ?>">
<p class="notice error" role="alert" data-menu-status hidden></p>
<?php if ($issues!==[]): ?><p class="notice" role="status"><?= e($t('issues',['items'=>implode(', ',array_unique($issues))])) ?></p><?php endif; ?>
<?php if ($unavailable!==[]): ?><p class="notice" role="status"><?= e($t('unavailable',['items'=>implode(', ',array_unique($unavailable))])) ?></p><?php endif; ?>
<?php if ($route==='overview'): $activeId=$context->store->read('site')['data']['template'];$activeLocations=Navigation::locations($context->store->templates->get($activeId));$stale=[];foreach($data['assignments'] as $binding)if(!isset($activeLocations[$binding['location']]))$stale[]=$binding['template'].' / '.$binding['location'];if($stale!==[]): ?><p class="notice" role="status"><?= e($t('placementWarning',['items'=>implode(', ',array_unique($stale))])) ?></p><?php endif;endif; ?>
<?php if (in_array($route,['overview','trash'],true)): $rows=array_filter($data['menus'],static fn(array $menu): bool=>$menu['deleted']===($route==='trash')); ?>
<?php if ($route==='overview'): ?><p><a class="button-primary" href="<?= e($url('editor')) ?>"><?= e($t('new')) ?></a></p><?php endif; ?>
<section class="panel extension-panel"><div class="table-scroll"><table class="extension-table"><caption class="sr-only"><?= e($t($route)) ?></caption><thead><tr><?php foreach (['nameColumn','items','status','actions'] as $column): ?><th scope="col"><?= e($t($column)) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($rows as $menu): ?><tr data-menu-id="<?= e($menu['id']) ?>"><td data-label="<?= e($t('nameColumn')) ?>"><strong><?= e($menu['name']) ?></strong><small class="extension-id"><?= e($menu['id']) ?></small></td><td data-label="<?= e($t('items')) ?>"><?= count($menu['items']) ?></td><td data-label="<?= e($t('status')) ?>"><span class="status <?= $menu['deleted']?'draft':'published' ?>"><?= e($t($menu['deleted']?'deleted':'active')) ?></span></td><td data-label="<?= e($t('actions')) ?>"><div class="row-actions">
<?php if (!$menu['deleted']): ?><a class="icon-button" href="<?= e($url('editor',$menu['id'])) ?>" title="<?= e($t('edit')) ?>" aria-label="<?= e($t('edit')) ?>"><?= Icons::svg('edit',$context->base) ?></a><?php endif; ?>
<form method="post" action="<?= e($context->url($menu['deleted']?'recover':'trash')) ?>" data-menu-form <?= !$menu['deleted']?'data-confirm="'.e($t('confirmDelete')).'"':'' ?>><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= e($menu['id']) ?>"><button class="icon-button <?= !$menu['deleted']?'danger':'' ?>" type="submit" title="<?= e($t($menu['deleted']?'recover':'delete')) ?>" aria-label="<?= e($t($menu['deleted']?'recover':'delete')) ?>"><?= Icons::svg($menu['deleted']?'history':'uninstall',$context->base) ?></button></form>
</div></td></tr><?php endforeach; ?></tbody></table></div><?php if ($rows===[]): ?><p class="empty-state"><?= e($t($route==='trash'?'emptyTrash':'empty')) ?></p><?php endif; ?></section>
<?php elseif ($route==='editor'): ?>
<form method="post" action="<?= e($context->url('save')) ?>" data-menu-form data-menu-editor data-state="<?= e(json_encode($editing['items'],JSON_THROW_ON_ERROR)) ?>" data-pages="<?= e(json_encode(array_map(static fn(array $item): string=>$item['data']['meta']['title'],$pages),JSON_THROW_ON_ERROR)) ?>" data-messages="<?= e(json_encode($context->language->messages(),JSON_THROW_ON_ERROR)) ?>">
<input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="original" value="<?= e($id) ?>"><input type="hidden" name="items" value="<?= e(json_encode($editing['items'],JSON_THROW_ON_ERROR)) ?>">
<section class="panel"><div class="field-grid"><label><?= e($t('menuName')) ?><input name="name" maxlength="160" value="<?= e($editing['name']) ?>" required></label><label><?= e($t('id')) ?><input name="id" pattern="[a-z][a-z0-9\-]{0,47}" maxlength="48" value="<?= e($id) ?>" required <?= $id!==''?'readonly':'' ?>></label></div></section>
<?php require __DIR__.'/placement.php'; ?>
<section class="panel"><div class="section-heading"><h2><?= e($t('items')) ?></h2><button type="button" class="button-secondary" data-add-item><?= e($t('add')) ?></button></div><div data-menu-items></div><noscript><?= e($t('requiresJS')) ?></noscript></section>
<div class="savebar"><a class="text-link" href="<?= e($url('overview')) ?>"><?= e($t('overview')) ?></a><button type="submit" class="button-primary"><?= e($t('save')) ?></button></div></form>
<?php elseif ($route==='history'): ?>
<?php $historyEnabled=(new \CMS2JSON\Plugins($context->store))->state('menu')['enabled']; ?>
<?php if (!$historyEnabled): ?><p class="notice" role="status"><?= e($t('historyInactive')) ?></p><?php endif; ?>
<section class="panel"><h2><?= e($t('contentHistory')) ?></h2><?php $history=$repository->history();if ($history===[]): ?><p class="empty-state"><?= e($t('noHistory')) ?></p><?php endif; ?>
<?php foreach ($history as $backup): ?><form class="history-row" method="post" action="<?= e($context->url('history-restore')) ?>" data-menu-form data-confirm="<?= e($t('confirmHistory')) ?>"><span><?= e(substr($backup,0,15)) ?></span><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="backup" value="<?= e($backup) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><button class="button-secondary" type="submit" <?= !$historyEnabled?'disabled':'' ?>><?= e($t('restoreHistory')) ?></button></form><?php endforeach; ?></section>
<?php endif; ?></div>
<?php if (!$context->integrated): ?></body></html><?php endif; ?>
