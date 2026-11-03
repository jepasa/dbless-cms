<?php
/** Shared HTML/Mustache authoring: direct source references; legacy bindings are carried invisibly for lossless edits, with no insertion/configuration selectors. */
declare(strict_types=1);
use function DBLESSCMS\e;
$markupDefinition??=['html'=>'','bindings'=>[]];$markupHtmlName??='html';$markupBindingsName??='bindings';
$coreMarkupLanguage=new \DBLESSCMS\Language();$coreMarkupLanguage->select($context->language->locale());
/** Translate shared authoring labels; receive key, return plain localized text without effects. */
$mt=static fn(string $key):string=>$coreMarkupLanguage->text('markup.'.$key);
?>
<div class="markup-editor" data-markup-editor data-page-key="<?= e($markupPageKey??'home') ?>">
<label><?= e($markupLabel??$mt('content')) ?><textarea name="<?= e($markupHtmlName) ?>" rows="18" maxlength="131072" spellcheck="false" data-markup-html><?= e($markupDefinition['html']) ?></textarea></label>
<input type="hidden" name="<?= e($markupBindingsName) ?>" data-markup-bindings-json value="<?= e(json_encode($markupDefinition['bindings']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)) ?>">
<p class="muted"><?= e($mt('automaticHelp')) ?></p>
</div>
