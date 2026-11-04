<?php
/** Portable Menu delivery controls: global technical keys, localized compatibility notices and no template selector/token. */
declare(strict_types=1);
use function DBLESSCMS\e;
$placement=\DBLESSCMS\MenuPlacement::keys($context,$data,$id);
?>
<section class="panel" data-menu-placement>
<h2><?= e($t('placementTitle')) ?></h2><p><?= e($t('portableHelp')) ?></p>
<input type="hidden" name="placement_editor" value="2">
<label><?= e($t('placementMode')) ?><select name="placementMode" data-placement-mode aria-label="<?= e($t('placementMode')) ?>"><option value="template" <?= $placement['mode']==='template'?'selected':'' ?>><?= e($t('byKey')) ?></option><option value="mustache" <?= $placement['mode']==='mustache'?'selected':'' ?>><?= e($t('byMustache')) ?></option></select></label>
<p class="muted"><?= e($t('exclusiveHelp')) ?></p>
<?php if ($placement['legacy']): ?><p class="notice" role="status"><?= e($t('portableLegacy')) ?></p><?php endif; ?>
<div data-template-positions <?= $placement['mode']==='mustache'?'hidden':'' ?>>
<p class="notice" role="status" data-menu-keys-empty <?= $placement['selected']!==[]?'hidden':'' ?>><?= e($t('keysEmpty')) ?></p>
<?php foreach($placement['warnings'] as $warning): if($warning['key']==='keysEmpty')continue; ?><p class="notice" role="status" data-menu-key-warning><?= e($t($warning['key'],$warning['parameters'])) ?></p><?php endforeach; ?>
<div class="stack"><?php foreach($placement['choices'] as $key=>$choice): $type=$placement['selected'][$key]??(in_array($editing['type']??'list',$choice['types'],true)?($editing['type']??'list'):($choice['types'][0]??'list')); ?>
<fieldset class="field-group" data-menu-key="<?= e($key) ?>" <?= $placement['mode']==='mustache'?'disabled':'' ?>><legend><code>{{template.<?= e($key) ?>}}</code></legend>
<div class="field-grid"><label class="editor-checkbox"><input type="checkbox" name="keys[]" value="<?= e($key) ?>" <?= isset($placement['selected'][$key])?'checked':'' ?>><?= e($t('useKey')) ?></label>
<label><?= e($t('keyFormat')) ?><select name="keyTypes[<?= e($key) ?>]" aria-label="<?= e($t('keyFormatLabel',['key'=>$key])) ?>"><?php foreach(\DBLESSCMS\Navigation::TYPES as $format): ?><option value="<?= e($format) ?>" <?= $type===$format?'selected':'' ?>><?= e($t($format)) ?></option><?php endforeach; ?></select></label></div>
<p class="muted"><?= e($t('keyTemplates',['count'=>count($choice['templates'])])) ?></p>
<?php if(isset($placement['owners'][$key])): ?><p class="muted"><?= e($t('replaces',['name'=>$placement['owners'][$key]])) ?></p><?php endif; ?></fieldset><?php endforeach; ?></div>
<?php if($placement['choices']===[]): ?><p class="notice" role="status"><?= e($t('noKeysKnown')) ?></p><?php endif; ?></div></section>
