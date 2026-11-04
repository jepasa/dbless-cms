<?php
/** Active template positions and exclusive persisted delivery mode; legacy bindings stay visible as preservation notices. */
declare(strict_types=1);
use function DBLESSCMS\e;
$placement=\DBLESSCMS\MenuPlacement::state($context,$data,$id);
?>
<section class="panel" data-menu-placement>
<h2><?= e($t('placementTitle')) ?></h2><p class="muted" data-active-template><?= e($placement['name'].' · '.$placement['template']) ?></p><p><?= e($t('placementHelp')) ?></p>
<input type="hidden" name="placement_editor" value="1"><input type="hidden" name="placement_template" value="<?= e($placement['template']) ?>"><input type="hidden" name="site_revision" value="<?= e($placement['revision']) ?>">
<label><?= e($t('placementMode')) ?><select name="placementMode" data-placement-mode aria-label="<?= e($t('placementMode')) ?>"><option value="template" <?= $placement['mode']==='template'?'selected':'' ?>><?= e($t('byKey')) ?></option><option value="mustache" <?= $placement['mode']==='mustache'?'selected':'' ?>><?= e($t('byMustache')) ?></option></select></label>
<p class="muted"><?= e($t('exclusiveHelp')) ?></p>
<?php if ($placement['legacy']): ?><p class="notice" role="status"><?= e($t('legacyPlacement')) ?></p><?php endif; ?>
<?php if ($placement['orphaned']!==[]): ?><p class="notice" role="status"><?= e($t('placementWarning',['items'=>implode(', ',$placement['orphaned'])])) ?></p><?php endif; ?>
<div data-template-positions <?= $placement['mode']==='mustache'?'hidden':'' ?>>
<?php if ($placement['locations']===[]): ?><p class="notice" role="status"><?= e($t('noLocations')) ?></p><?php endif; ?>
<div class="stack"><?php foreach ($placement['locations'] as $locationId=>$location): $assigned='';foreach ($data['assignments'] as $binding) if ($binding['template']===$placement['template']&&$binding['location']===$locationId&&$binding['menu']!==$id)$assigned=$data['menus'][$binding['menu']]['name']; ?>
<fieldset class="field-group" data-placement-location="<?= e($locationId) ?>" data-location="<?= e($locationId) ?>"  <?= $placement['mode']==='mustache'?'disabled':'' ?>><legend><?= e($location['name']) ?></legend><div class="field-grid"><label class="editor-checkbox"><input type="checkbox" name="locations[]" value="<?= e($locationId) ?>" <?= isset($placement['selected'][$locationId])?'checked':'' ?>><?= e($t('useLocation')) ?></label><label><?= e($t('type')) ?><select name="locationTypes[<?= e($locationId) ?>]" aria-label="<?= e($t('type').' · '.$location['name']) ?>"><?php foreach ($location['types'] as $type): ?><option value="<?= e($type) ?>" <?= ($placement['selected'][$locationId]??$location['defaultType'])===$type?'selected':'' ?>><?= e($t($type)) ?></option><?php endforeach; ?></select></label></div>
<?php foreach ($location['keys'] as $slot=>$description): ?><p><code>{{template.<?= e($slot) ?>}}</code> · <?= e($description) ?></p><?php endforeach; ?>
<?php if ($assigned!==''): ?><p class="muted"><?= e($t('replaces',['name'=>$assigned])) ?></p><?php endif; ?></fieldset><?php endforeach; ?></div></div></section>
