<?php
/** Seleção de destino no editor Slides: manifesto ativo, marcadores/blocos de páginas e alertas, sem editar outra página. */
declare(strict_types=1);
use function CMS2JSON\e;
$placement=\CMS2JSON\SlidePlacement::state($context,$data,$id);
$placementMode=($_GET['placement']??'template')==='page'?'page':'template';
$placementPage=is_string($_GET['target']??null)&&isset($placement['pages'][$_GET['target']])?$_GET['target']:(string)(array_key_first($placement['pages'])??'');
?>
<section class="panel" data-slides-placement data-placement-state="<?= e(json_encode($placement,JSON_THROW_ON_ERROR)) ?>" data-page-editor="<?= e($context->base.'/cms-admin/?view=plugin-panel&plugin=pages&route=editor&key=') ?>">
<h2><?= e($t('placementTitle')) ?></h2><p class="muted"><?= e($placement['name'].' · '.$placement['template']) ?></p><p><?= e($t('placementHelp')) ?></p>
<input type="hidden" name="placement_editor" value="1"><input type="hidden" name="placement_template" value="<?= e($placement['template']) ?>"><input type="hidden" name="site_revision" value="<?= e($placement['revision']) ?>"><input type="hidden" name="page_revision" value="">
<div class="field-grid"><label><?= e($t('placementMode')) ?><select name="placementMode" data-placement-mode aria-label="<?= e($t('placementMode')) ?>"><option value="template" <?= $placementMode==='template'?'selected':'' ?>><?= e($t('byKey')) ?></option><option value="page" <?= $placementMode==='page'?'selected':'' ?>><?= e($t('byPage')) ?></option></select></label>
<label data-placement-page-label><?= e($t('placementPage')) ?><select name="placementPage" data-placement-page aria-label="<?= e($t('placementPage')) ?>"><?php foreach ($placement['pages'] as $slug=>$page): ?><option value="<?= e($slug) ?>" <?= (string)$slug===$placementPage?'selected':'' ?>><?= e($page['title'].' · '.$slug) ?></option><?php endforeach; ?></select></label></div>
<p data-placement-page-help><a class="text-link" data-placement-edit-page target="_blank" rel="noopener"><?= e($t('editPageMarkers')) ?></a></p>
<p class="notice" role="status" data-placement-empty hidden><?= e($t('noPageMarkers')) ?></p>
<?php if ($placement['orphaned']!==[]): ?><p class="notice" role="status"><?= e($t('placementWarning',['items'=>implode(', ',$placement['orphaned'])])) ?></p><?php endif; ?>
<?php if ($placement['locations']===[]): ?><p class="notice" role="status"><?= e($t('noLocations')) ?></p><?php endif; ?>
<div class="stack"><?php foreach ($placement['locations'] as $locationId=>$location): $assigned='';foreach ($data['assignments'] as $binding) if ($binding['template']===$placement['template']&&$binding['location']===$locationId&&$binding['collection']!==$id)$assigned=$data['collections'][$binding['collection']]['name']; ?>
<fieldset class="field-group" data-placement-location="<?= e($locationId) ?>" data-content-type="<?= e($location['contentType']) ?>"><legend><?= e($location['description']) ?></legend><label class="editor-checkbox"><input type="checkbox" name="locations[]" value="<?= e($locationId) ?>" <?= in_array($locationId,$placement['selected'],true)?'checked':'' ?>><?= e($t('useLocation')) ?></label>
<p class="muted"><?= e($locationId.' · '.$t($location['type']).' · '.$t($location['contentType'])) ?></p><code>{{slides:<?= e($locationId) ?>}}</code>
<?php if ($assigned!==''): ?><p class="muted"><?= e($t('replaces',['name'=>$assigned])) ?></p><?php endif; ?></fieldset><?php endforeach; ?></div></section>
