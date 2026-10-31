<?php
/** Shared accessible HTML/Mustache authoring control; safe service metadata, typed bindings, manual snippets and a real submitted textarea with local enhancement. */
declare(strict_types=1);
use function DBLESSCMS\e;
$markupDefinition??=['html'=>'','bindings'=>[]];$markupHtmlName??='html';$markupBindingsName??='bindings';
$coreMarkupLanguage=new \DBLESSCMS\Language();$coreMarkupLanguage->select($context->language->locale());
/** Translate the shared authoring controls; receive key, return plain text without effects. */
$mt=static fn(string $key):string=>$coreMarkupLanguage->text('markup.'.$key);
$markupContext=new \DBLESSCMS\ContentContext($context->store,$context->store->read('site')['data']['template'],$context->base,'home');
$markupCatalog=(new \DBLESSCMS\Components($markupContext))->editorCatalog(true);
$markupLabels=array_filter($coreMarkupLanguage->messages(),static fn(string $key):bool=>str_starts_with($key,'markup.'),ARRAY_FILTER_USE_KEY);
?>
<div class="markup-editor" data-markup-editor data-catalog="<?= e(json_encode($markupCatalog,JSON_THROW_ON_ERROR)) ?>" data-labels="<?= e(json_encode($markupLabels,JSON_THROW_ON_ERROR)) ?>">
<label><?= e($mt('content')) ?><textarea name="<?= e($markupHtmlName) ?>" rows="18" maxlength="131072" spellcheck="false" data-markup-html><?= e($markupDefinition['html']) ?></textarea></label>
<p class="muted"><?= e($mt('htmlHelp')) ?></p>
<div data-markup-bindings></div>
<details><summary><?= e($mt('bindingsJson')) ?></summary><label><?= e($mt('bindingsJson')) ?><textarea name="<?= e($markupBindingsName) ?>" rows="5" spellcheck="false" data-markup-bindings-json><?= e(json_encode($markupDefinition['bindings'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)) ?></textarea></label></details>
</div>
