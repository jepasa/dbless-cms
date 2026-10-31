<?php
/** Reusable Widgets/set editors inside the fixed admin shell; escaped content, stable keys, ordered member IDs and recoverable deletion. */
declare(strict_types=1);
use DBLESSCMS\{WidgetsStore,Security,HttpError,Icons};
use function DBLESSCMS\e;
/** Translate plugin-owned controls; receive key/parameters, return literal text for escaping without writes. */
$t=static fn(string $key,array $args=[]):string=>$context->language->text('widgets.'.$key,$args);
/** Build a declared private route; receive route/id, return same-origin address without effects. */
$url=static fn(string $route,string $id=''):string=>$context->base.'/cms-admin/?view=plugin-panel&plugin=widgets&route='.$route.($id!==''?'&id='.rawurlencode($id):'');
$snapshot=(new WidgetsStore($context->store))->read();$data=$snapshot['data'];$revision=$snapshot['revision'];$csrf=Security::token();$route=$context->route;$isSet=in_array($route,['sets','set'],true);$map=$isSet?'sets':'reusable';$id=$_GET['id']??'';
if(!is_string($id)||($id!==''&&!WidgetsStore::identifier($id)))throw new HttpError(404,'Unavailable record.','widgets.error.missing');
$editing=($data[$map]??[])[$id]??['id'=>'','key'=>'','value'=>'','name'=>'','description'=>'','widgets'=>[],'deleted'=>false];
if($id!==''&&(!isset($data[$map][$id])||$editing['deleted']))throw new HttpError(404,'Unavailable record.','widgets.error.missing');
?>
<div class="widgets-plugin" data-widgets-plugin data-network-error="<?= e($t('network')) ?>"><p class="notice error" role="alert" data-widget-status hidden></p>
<?php if(in_array($route,['library','sets'],true)): ?>
<p><a class="button-primary" href="<?= e($url($isSet?'set':'record')) ?>"><?= e($t($isSet?'newSet':'newReusable')) ?></a></p>
<section class="panel extension-panel"><div class="table-scroll"><table class="extension-table"><thead><tr><th><?= e($t('key')) ?></th><th><?= e($t($isSet?'nameField':'value')) ?></th><th><?= e($t('description')) ?></th><th><?= e($t('actions')) ?></th></tr></thead><tbody>
<?php foreach($data[$map]??[] as $record):if($record['deleted'])continue; ?><tr data-library-id="<?= e($record['id']) ?>"><td data-label="<?= e($t('key')) ?>"><strong><?= e($record['key']) ?></strong></td><td data-label="<?= e($t($isSet?'nameField':'value')) ?>"><?= e($isSet?$record['name']:$record['value']) ?></td><td data-label="<?= e($t('description')) ?>"><?= e($record['description']) ?></td><td data-label="<?= e($t('actions')) ?>"><div class="row-actions"><a class="icon-button" title="<?= e($t('edit')) ?>" aria-label="<?= e($t('edit')) ?>" href="<?= e($url($isSet?'set':'record',$record['id'])) ?>"><?= Icons::svg('edit',$context->base) ?></a><form method="post" action="<?= e($context->url($isSet?'set-trash':'library-trash')) ?>" data-widget-form data-confirm="<?= e($t('confirmDelete')) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="id" value="<?= e($record['id']) ?>"><button class="icon-button" title="<?= e($t('delete')) ?>" aria-label="<?= e($t('delete')) ?>"><?= Icons::svg('uninstall',$context->base) ?></button></form></div></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<?php else: ?>
<p class="muted"><?= e($t($isSet?'setHelp':'libraryHelp')) ?></p>
<form method="post" action="<?= e($context->url($isSet?'set-save':'library-save')) ?>" data-widget-form>
<input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="id" value="<?= e($id) ?>">
<section class="panel"><div class="field-grid"><label><?= e($t('key')) ?><input name="key" pattern="[a-z][a-z0-9\-]{0,47}" maxlength="48" required value="<?= e($editing['key']) ?>" <?= $id!==''?'readonly':'' ?>></label>
<?php if($isSet): ?><label><?= e($t('nameField')) ?><input name="name" maxlength="120" value="<?= e($editing['name']) ?>" required></label><?php else: ?><label class="wide"><?= e($t('value')) ?><textarea name="value" maxlength="12000" rows="4"><?= e($editing['value']) ?></textarea></label><?php endif; ?>
<label class="wide"><?= e($t('description')) ?><input name="description" maxlength="500" value="<?= e($editing['description']) ?>"></label></div></section>
<?php if($isSet): ?>
<section class="panel" data-widget-members data-messages="<?= e(json_encode(['up'=>$t('up'),'down'=>$t('down'),'remove'=>$t('removeMember')],JSON_THROW_ON_ERROR)) ?>"><h2><?= e($t('members')) ?></h2><p class="muted"><?= e($t('memberHelp')) ?></p>
<div class="field-grid"><label><?= e($t('chooseMember')) ?><select data-member-choice><option value="">—</option><?php foreach($data['reusable']??[] as $member):if($member['deleted'])continue; ?><option value="<?= e($member['id']) ?>"><?= e($member['key'].' — '.$member['description']) ?></option><?php endforeach; ?></select></label><button type="button" class="button-secondary" data-member-add><?= e($t('addMember')) ?></button></div>
<ol class="widget-members" data-member-list><?php foreach($editing['widgets'] as $memberId):$member=$data['reusable'][$memberId]; ?><li data-member="<?= e($memberId) ?>"><span><?= e($member['key'].($member['deleted']?' · '.$t('deleted'):'')) ?></span><input type="hidden" name="members[]" value="<?= e($memberId) ?>"><button type="button" class="button-secondary" data-member-up aria-label="<?= e($t('up')) ?>">↑</button><button type="button" class="button-secondary" data-member-down aria-label="<?= e($t('down')) ?>">↓</button><button type="button" class="button-secondary" data-member-remove aria-label="<?= e($t('removeMember')) ?>">×</button></li><?php endforeach; ?></ol></section>
<?php if($editing['key']!==''): ?><pre><code><?= e('{{widgets:set key="'.$editing['key'].'" layout="list"}}') ?></code></pre><?php endif; ?>
<?php endif; ?>
<div class="savebar"><a class="text-link" href="<?= e($url($isSet?'sets':'library')) ?>"><?= e($t('overview')) ?></a><button class="button-primary" type="submit"><?= e($t('save')) ?></button></div></form>
<?php endif; ?></div>
