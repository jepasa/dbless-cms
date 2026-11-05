<?php
/** Accessible type creation/editing dialog; only validated declarative records are exposed, with local errors and the shared authenticated CSRF/CAS submission runtime. */
declare(strict_types=1);
use function DBLESSCMS\e;
?>
<dialog class="posts-type-dialog" id="posts-type-dialog" data-post-type-dialog aria-labelledby="posts-type-title" data-records="<?= e(json_encode($data['types'],JSON_THROW_ON_ERROR)) ?>" data-initial="<?= e($id) ?>" data-can-create="<?= $canCreate?'true':'false' ?>" data-new-title="<?= e($t('typeNew')) ?>" data-edit-title="<?= e($t('typeEdit')) ?>">
<form method="post" action="<?= e($context->url('type-save')) ?>" data-pages-form><?php $hidden(); ?>
<h2 id="posts-type-title"><?= e($t('typeNew')) ?></h2><p class="notice error" role="alert" data-pages-form-status hidden></p>
<label><?= e($t('identifier')) ?><input name="typeId" maxlength="64" required pattern="[a-z][a-z0-9\-]{0,63}"></label>
<div class="field-grid"><?php foreach(['singular','plural'] as $key): ?><label><?= e($t($key)) ?><input name="<?= e($key) ?>" maxlength="100" required></label><?php endforeach; ?></div>
<label><?= e($t('kind')) ?><select name="kind" aria-label="<?= e($t('kind')) ?>"><?php foreach(['page','article'] as $kind): ?><option value="<?= e($kind) ?>"><?= e($t('kind.'.$kind)) ?></option><?php endforeach; ?></select></label>
<label><?= e($t('routeMode')) ?><select name="routeMode" aria-label="<?= e($t('routeMode')) ?>"><?php foreach(['default','root','custom'] as $mode): ?><option value="<?= e($mode) ?>"><?= e($t('route.'.$mode)) ?></option><?php endforeach; ?></select></label><label><?= e($t('prefix')) ?><input name="prefix" maxlength="128"></label>
<?php foreach(['groups','tags','comments','hierarchical'] as $key): ?><label class="editor-checkbox"><input name="supports_<?= e($key) ?>" type="checkbox" value="1"><?= e($t($key==='comments'?'allowComments':$key)) ?></label><?php endforeach; ?>
<div class="savebar"><button type="button" class="button-secondary" data-post-type-cancel><?= e($t('cancel')) ?></button><button type="submit" class="button-primary" <?= !$active?'disabled':'' ?>><?= e($t('typeSave')) ?></button></div>
</form></dialog>
