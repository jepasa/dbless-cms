<?php
/** Shared authenticated insertion UI; manifest-only catalog plus explicitly authorized optional choices, no hardcoded plugin dispatch or executable user markup. */
declare(strict_types=1);
$pickerLanguage=new \DBLESSCMS\Language();$pickerLanguage->select($context->language->locale());
/** Translate hardcoded component picker labels; receive key, return plain text without mutation. */
$ct=static fn(string $key):string=>$pickerLanguage->text('components.'.$key);
$picker=$components->editorCatalog();
?>
<section class="panel components-picker" data-components-picker data-catalog="<?= \DBLESSCMS\e(json_encode($picker,JSON_THROW_ON_ERROR)) ?>" data-labels="<?= \DBLESSCMS\e(json_encode(array_filter($pickerLanguage->messages(),static fn(string $key):bool=>str_starts_with($key,'components.'),ARRAY_FILTER_USE_KEY),JSON_THROW_ON_ERROR)) ?>">
<h2><?= \DBLESSCMS\e($ct('insert')) ?></h2><p class="muted"><?= \DBLESSCMS\e($ct('target')) ?></p>
<div class="field-grid"><label><?= \DBLESSCMS\e($ct('plugin')) ?><select data-component-plugin></select></label><label><?= \DBLESSCMS\e($ct('service')) ?><select data-component-service></select></label><label><?= \DBLESSCMS\e($ct('layout')) ?><select data-component-layout></select></label></div>
<p class="muted" data-component-description></p><div class="field-grid" data-component-parameters></div><p class="notice" role="status" data-component-status hidden></p><p><button type="button" class="button-secondary" data-component-insert><?= \DBLESSCMS\e($ct('add')) ?></button></p>
<label><?= \DBLESSCMS\e($ct('marker')) ?><input readonly data-component-marker></label>
</section>
