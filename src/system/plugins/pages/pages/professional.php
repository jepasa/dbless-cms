<?php
/** Unified Pages creation/editing: Core editorial shell and WYSIWYG, opt-in properties, immutable publication contracts and no writes during render. */
declare(strict_types=1);
use DBLESSCMS\{PagePublications,PageBlueprints,JsonStore,Icons,Slides};
use function DBLESSCMS\e;
$repository=new PagePublications($context->store);$catalog=$repository->read();$isNew=$route==='new';$row=$isNew?null:$repository->record($key);$siteRevision=$context->store->read('site')['revision'];$sourceRevision=$isNew||isset($catalog['data']['records'][$key])?'':$context->store->read($key,null,true)['revision'];
$snapshot=$isNew?PageBlueprints::create('blank',$t('pages.titlePlaceholder'),'new-page'):$row['draft'];$pageDocument=$snapshot['document'];
if($isNew){$snapshot['url']='';foreach(['title','description','image','imageAlt'] as $field)$pageDocument['meta'][$field]='';}
$markupDefinition=$pageDocument['markup']??['html'=>'','bindings'=>[]];$markupPageKey=$isNew?'home':$key;$markupAdvanced=true;$markupLabel=$t('pages.content');$authored=isset($pageDocument['markup']);
/** Emit escaped CSRF/catalog/site/source fields; no parameters, outputs hidden controls without mutations. */
$fields=static function()use($csrf,$catalog,$siteRevision,$sourceRevision,$key):void{foreach(['csrf_token'=>$csrf,'catalog_revision'=>$catalog['revision'],'site_revision'=>$siteRevision,'source_revision'=>$sourceRevision,'key'=>$key] as $name=>$value)echo '<input type="hidden" name="'.e($name).'" value="'.e($value).'">';};
/** Produce one canonical translated icon command; receives icon/label and trusted static attributes, returns escaped control markup without effects. */
$button=static fn(string $icon,string $label,string $attributes=''):string=>'<button class="icon-button" title="'.e($label).'" aria-label="'.e($label).'" '.$attributes.'>'.Icons::svg($icon,$context->base).'</button>';
ob_start(); ?>
<div class="row-actions"><a class="icon-button" href="<?= e($link('overview')) ?>" title="<?= e($t('pages.backToPages')) ?>" aria-label="<?= e($t('pages.backToPages')) ?>"><?= Icons::svg('pages',$context->base) ?></a><span class="page-work-state" data-page-work-state data-saved="<?= e($t('pages.saved')) ?>" data-unsaved="<?= e($t('pages.unsavedShort')) ?>" role="status"><?= e($isNew?$t('pages.newDraft'):$t('pages.state.'.PagePublications::status($row))) ?></span></div>
<div class="row-actions">
<?= $button('confirm',$t('pages.action.save'),'type="submit"') ?>
<?php if(!$isNew): ?><a class="icon-button" href="<?= e($link('preview',$key)) ?>" target="_blank" rel="noopener noreferrer" title="<?= e($t('pages.savedPreview')) ?>" aria-label="<?= e($t('pages.savedPreview')) ?>"><?= Icons::svg('preview',$context->base) ?></a><?= $button('activate',$t('pages.action.publish'),'type="submit" form="page-publication" data-page-publish') ?><?php endif; ?>
<?= $button('settings',$t('pages.properties'),'type="button" data-editorial-properties-toggle aria-controls="editorial-properties" aria-expanded="true"') ?>
</div>
<?php $editorialToolbar=(string)ob_get_clean();ob_start(); ?>
<section class="panel pages-authoring">
<label class="page-title-label"><span class="sr-only"><?= e($t('pages.editorialTitle')) ?></span><input class="page-title-input" name="<?= $isNew?'title':'meta_title' ?>" maxlength="200" required placeholder="<?= e($t('pages.titlePlaceholder')) ?>" value="<?= e($pageDocument['meta']['title']) ?>" <?= $isNew?'data-pages-title':'' ?>></label>
<input type="checkbox" name="markup_enabled" value="1" data-page-mode hidden <?= $authored?'checked':'' ?>>
<div class="page-format-tabs" role="tablist" aria-label="<?= e($t('pages.contentFormat')) ?>">
<?php foreach(['json'=>'pages.formatJSON','markup'=>'pages.formatMarkup'] as $format=>$label): $selected=($format==='markup')===$authored; ?>
<button type="button" role="tab" id="page-format-<?= e($format) ?>" data-page-format="<?= e($format) ?>" aria-controls="page-panel-<?= e($format) ?>" aria-selected="<?= $selected?'true':'false' ?>" tabindex="<?= $selected?'0':'-1' ?>" title="<?= e($t($label)) ?>"><?= e($t($label)) ?></button>
<?php endforeach; ?></div>
<div id="page-panel-markup" role="tabpanel" aria-labelledby="page-format-markup" data-page-authored <?= !$authored?'hidden':'' ?>><?php require $context->config->root.'/admin/markup-editor.php'; ?></div>
<div id="page-panel-json" role="tabpanel" aria-labelledby="page-format-json" data-page-native <?= $authored?'hidden':'' ?>><p class="muted"><?= e($t('pages.nativePreserved')) ?></p><?php $slideLocations=Slides::locations($context->store->templates->get($context->store->read('site')['data']['template']));require __DIR__.'/native-slides.php'; ?><label><?= e($t('pages.native')) ?><textarea data-native-content name="native_content" rows="18" spellcheck="false"><?= e(JsonStore::encode($pageDocument['content'])) ?></textarea></label></div>
</section>
<?php $editorialContent=(string)ob_get_clean();ob_start(); ?>
<details open><summary><?= e($t('pages.publication')) ?></summary>
<?php if($isNew): ?><p class="muted"><?= e($t('pages.firstSaveHelp')) ?></p><?php else: ?>
<p data-page-publication-help class="muted" data-normal="<?= e($t('pages.publishSaved')) ?>"><?= e($t('pages.publishSaved')) ?></p>
<label><?= e($t('pages.scheduleUTC')) ?><input type="datetime-local" name="publish_at" form="page-schedule" required></label>
<div class="row-actions"><?= $button('history',$t('pages.action.schedule'),'type="submit" form="page-schedule" data-page-publish') ?><?php if($row['published']!==null||$row['scheduled']!==null): ?><?= $button('deactivate',$t('pages.action.unpublish'),'type="submit" form="page-unpublish"') ?><?php endif; ?></div>
<?php endif; ?></details>
<details <?= $isNew?'open':'' ?>><summary><?= e($t('pages.structure')) ?></summary>
<label><?= e($t('pages.slug')) ?><input name="slug" maxlength="64" pattern="[a-z0-9][a-z0-9\-]{0,63}" value="<?= e($snapshot['url']) ?>" required <?= $isNew?'data-pages-slug':'' ?>></label>
<label><?= e($t('pages.parent')) ?><select name="parent"><option value=""><?= e($t('pages.noParent')) ?></option><?php foreach($pages as $id=>$item): if((string)$id===$key||!PagePublications::identifier((string)$id))continue; ?><option value="<?= e($id) ?>" <?= ($row['parent']??'')===(string)$id?'selected':'' ?>><?= e($item['data']['meta']['title']) ?></option><?php endforeach; ?></select></label><p class="muted"><?= e($t('pages.urlHelp')) ?></p>
</details>
<details><summary><?= e($t('pages.featuredImage')) ?></summary><label><?= e($t('pages.meta_image')) ?><input name="meta_image" maxlength="2048" data-media-field="image" value="<?= e($pageDocument['meta']['image']) ?>"></label><label><?= e($t('pages.meta_imageAlt')) ?><input name="meta_imageAlt" maxlength="500" value="<?= e($pageDocument['meta']['imageAlt']) ?>"></label></details>
<details><summary><?= e($t('pages.summary')) ?></summary><label><?= e($t('pages.meta_description')) ?><textarea name="meta_description" maxlength="500" rows="4"><?= e($pageDocument['meta']['description']) ?></textarea></label></details>
<details><summary><?= e($t('pages.seo')) ?></summary><p class="muted"><?= e($t('pages.inheritance')) ?></p><?php foreach($snapshot['seo'] as $field=>$value): ?><label <?= $field==='noindex'?'class="editor-checkbox"':'' ?>><?= e($t('pages.seo.'.$field)) ?><input name="seo_<?= e($field) ?>" <?= $field==='noindex'?'type="checkbox" value="1" '.($value?'checked':''):'maxlength="2048" value="'.e($value).'"' ?> <?= $field==='socialImage'?'data-media-field="image"':'' ?>></label><?php endforeach; ?></details>
<details><summary><?= e($t('pages.advanced')) ?></summary>
<?php if($isNew): $models=[];foreach(PageBlueprints::names() as $model)$models[$model]=PageBlueprints::create($model,$t('pages.titlePlaceholder'),'new-page')['document']['markup']['html']; ?>
<label><?= e($t('pages.model')) ?><select name="model"><?php foreach(PageBlueprints::names() as $model): ?><option value="<?= e($model) ?>"><?= e($t('pages.model.'.$model)) ?></option><?php endforeach; ?></select></label><p class="muted"><?= e($t('pages.modelHelp')) ?></p><?= $button('pages',$t('pages.applyModel'),'type="button" data-page-apply-model data-models="'.e(json_encode($models,JSON_THROW_ON_ERROR)).'" data-confirm="'.e($t('pages.replaceModelConfirm')).'"') ?>
<?php else: ?>
<div class="row-actions"><a class="icon-button" href="<?= e($link('history',$key)) ?>" title="<?= e($t('nav.history')) ?>" aria-label="<?= e($t('nav.history')) ?>"><?= Icons::svg('history',$context->base) ?></a></div>
<label><?= e($t('pages.duplicateAddress')) ?><input name="slug" form="page-duplicate" maxlength="64" pattern="[a-z0-9][a-z0-9\-]{0,63}" required></label><?= $button('pages',$t('pages.action.duplicate'),'type="submit" form="page-duplicate"') ?>
<?php endif; ?></details>
<?php $editorialProperties=(string)ob_get_clean();$editorialPropertiesLabel=$t('pages.properties'); ?>
<form id="page-working" method="post" action="<?= e($context->url($isNew?'editorial-create':'editorial-save')) ?>" data-pages-form data-page-markup-form data-page-workspace data-unsaved="<?= e($t('pages.unsaved')) ?>">
<?php $fields(); ?><input type="hidden" name="page_status" value="draft"><input type="hidden" name="meta_keywords" value="<?= e($pageDocument['meta']['keywords']) ?>"><?php if($isNew): ?><input type="hidden" name="compose" value="1"><?php endif; ?>
<?php require $context->config->root.'/admin/editorial-workspace.php'; ?>
</form>
<?php if(!$isNew): foreach(['publication'=>'publish','schedule'=>'schedule','unpublish'=>'unpublish','duplicate'=>'duplicate'] as $formId=>$operation): ?>
<form id="page-<?= e($formId) ?>" method="post" action="<?= e($context->url('editorial-'.$operation)) ?>" data-pages-form><?php $fields(); ?></form>
<?php endforeach; endif; ?>
