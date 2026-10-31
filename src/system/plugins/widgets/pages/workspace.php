<?php
/** Widgets workspace: template-discovered keys, literal values, soft-delete catalog and revision history in the fixed admin theme. */
declare(strict_types=1);
use DBLESSCMS\{WidgetsStore,Widgets,Icons,Security,HttpError};
use function DBLESSCMS\{e,asset};
/** Translate hardcoded plugin controls; receives key/parameters, returns text for contextual escaping, no effects. */
$t=static fn(string $key,array $args=[]):string=>$context->language->text('widgets.'.$key,$args);
/** Construct a fixed private route URL; receives route/id, returns local URL without mutation. */
$url=static fn(string $route,string $id=''):string=>$context->base.'/cms-admin/?view=plugin-panel&plugin=widgets&route='.$route.($id!==''?'&id='.rawurlencode($id):'');
$repository=new WidgetsStore($context->store);$snapshot=$repository->read();$data=$snapshot['data'];$revision=$snapshot['revision'];$csrf=Security::token();$route=$context->route;
$site=$context->store->read('site');$template=$site['data']['template'];$manifest=$context->store->templates->get($template);$locations=Widgets::locations($manifest);
$id=$_GET['id']??'';if(!is_string($id)||($id!==''&&!preg_match('/^[a-z][a-z0-9-]{0,63}$/D',$id)))throw new HttpError(404,'Unavailable widget.','widgets.error.missing');
$editing=$data['widgets'][$id]??['id'=>'','template'=>$template,'key'=>'','value'=>'','description'=>'','deleted'=>false];
if($route==='editor'&&$id!==''&&(!isset($data['widgets'][$id])||$editing['deleted']))throw new HttpError(404,'Unavailable widget.','widgets.error.missing');
$issues=[];foreach($data['widgets'] as $record)if(!$record['deleted']&&($record['template']!==$template||!isset($locations[$record['key']])))$issues[]=$record['template'].' / '.$record['key'];
?>
<?php if(!$context->integrated): ?><!doctype html><html lang="<?= e($context->language->locale()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= e($t('name')) ?></title><link rel="icon" href="<?= e(asset('cms-admin/assets/favicon.ico',$context->base)) ?>"><link rel="stylesheet" href="<?= e(asset('cms-admin/assets/admin.css',$context->base)) ?>"><link rel="stylesheet" href="<?= e(asset('plugins/widgets/css/widgets.css',$context->base)) ?>"><script defer src="<?= e(asset('plugins/widgets/js/widgets.js',$context->base)) ?>"></script></head><body class="cms-admin plugin-standalone plugin-content"><?php endif; ?>
<div class="widgets-plugin" data-widgets-plugin data-network-error="<?= e($t('network')) ?>">
<p class="notice error" role="alert" data-widget-status hidden></p>
<?php if($issues!==[]): ?><p class="notice" role="status"><?= e($t('placementWarning',['items'=>implode(', ',$issues)])) ?></p><?php endif; ?>
<?php if(in_array($route,['overview','trash'],true)): $rows=array_filter($data['widgets'],static fn(array $record):bool=>$record['deleted']===($route==='trash')); ?>
<?php if($route==='overview'): ?><p><a class="button-primary" href="<?= e($url('editor')) ?>"><?= e($t('new')) ?></a></p><?php endif; ?>
<section class="panel extension-panel"><div class="table-scroll"><table class="extension-table"><thead><tr><?php foreach(['key','value','description','template','actions'] as $column): ?><th scope="col"><?= e($t($column)) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach($rows as $record): ?><tr data-widget-id="<?= e($record['id']) ?>"><td data-label="<?= e($t('key')) ?>"><strong><?= e($record['key']) ?></strong></td><td class="widget-value" data-label="<?= e($t('value')) ?>"><?= e($record['value']) ?></td><td data-label="<?= e($t('description')) ?>"><?= e($record['description']) ?></td><td data-label="<?= e($t('template')) ?>"><?= e($record['template']) ?></td><td data-label="<?= e($t('actions')) ?>"><div class="row-actions">
<?php if(!$record['deleted']): ?><a class="icon-button" href="<?= e($url('editor',$record['id'])) ?>" title="<?= e($t('edit')) ?>" aria-label="<?= e($t('edit')) ?>"><?= Icons::svg('edit',$context->base) ?></a><?php endif; ?>
<form method="post" action="<?= e($context->url($record['deleted']?'recover':'trash')) ?>" data-widget-form <?= !$record['deleted']?'data-confirm="'.e($t('confirmDelete')).'"':'' ?>><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= e($record['id']) ?>"><button class="icon-button" type="submit" title="<?= e($t($record['deleted']?'recover':'delete')) ?>" aria-label="<?= e($t($record['deleted']?'recover':'delete')) ?>"><?= Icons::svg($record['deleted']?'history':'uninstall',$context->base) ?></button></form>
</div></td></tr><?php endforeach; ?></tbody></table></div><?php if($rows===[]): ?><p class="empty-state"><?= e($t($route==='trash'?'emptyTrash':'empty')) ?></p><?php endif; ?></section>
<?php elseif($route==='editor'): ?>
<p class="muted"><?= e($t('help')) ?></p>
<?php if($locations===[]): ?><p class="notice" role="status"><?= e($t('noLocations')) ?></p><?php endif; ?>
<form method="post" action="<?= e($context->url('save')) ?>" data-widget-form>
<input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="site_revision" value="<?= e($site['revision']) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= e($id) ?>"><input type="hidden" name="original" value="<?= e($id) ?>">
<section class="panel"><div class="field-grid"><label><?= e($t('template')) ?><input value="<?= e($manifest['name']) ?>" readonly></label><label><?= e($t('key')) ?><select name="key" aria-label="<?= e($t('key')) ?>" required><option value=""><?= e($t('choose')) ?></option><?php foreach($locations as $key=>$description): ?><option value="<?= e($key) ?>" <?= $editing['template']===$template&&$editing['key']===$key?'selected':'' ?>><?= e($key.' — '.$description) ?></option><?php endforeach; ?></select></label><label class="wide"><?= e($t('value')) ?><textarea name="value" aria-label="<?= e($t('value')) ?>" maxlength="12000" rows="4"><?= e($editing['value']) ?></textarea></label><label class="wide"><?= e($t('description')) ?><input name="description" aria-label="<?= e($t('description')) ?>" maxlength="500" value="<?= e($editing['description']) ?>"></label></div></section>
<div class="savebar"><a class="text-link" href="<?= e($url('overview')) ?>"><?= e($t('overview')) ?></a><button type="submit" class="button-primary" <?= $locations===[]?'disabled':'' ?>><?= e($t('save')) ?></button></div></form>
<?php elseif($route==='history'): ?>
<section class="panel"><?php $history=$repository->history();if($history===[]): ?><p class="empty-state"><?= e($t('noHistory')) ?></p><?php endif; ?>
<?php foreach($history as $backup): ?><form class="history-row" method="post" action="<?= e($context->url('history-restore')) ?>" data-widget-form data-confirm="<?= e($t('confirmHistory')) ?>"><span><?= e(substr($backup,0,22)) ?></span><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="backup" value="<?= e($backup) ?>"><button type="submit" class="button-secondary" <?= !(new \DBLESSCMS\Plugins($context->store))->state('widgets')['enabled']?'disabled':'' ?>><?= e($t('restoreHistory')) ?></button></form><?php endforeach; ?></section>
<?php endif; ?></div>
<?php if(!$context->integrated): ?></body></html><?php endif; ?>
