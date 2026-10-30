<?php
/** Fragmento de opções: editor global, ajuda do plugin, revisão/CSRF e link para a rota própria de histórico. */
declare(strict_types=1);
use CMS2JSON\{Security,JsonStore};
use function CMS2JSON\e;
/** Traduz controles comuns do editor; recebe chave/parâmetros, retorna texto sem efeitos. */
$label=static fn(string $key,array $parameters=[]): string=>$ui->text($key,$parameters);
$settings=$context->settings;
?>
<div class="editor-toolbar"><div class="tabs" aria-label="<?= e($label('editor.mode')) ?>"><button type="button" data-mode="fields" aria-pressed="true"><?= e($label('editor.fields')) ?></button><button type="button" data-mode="json" aria-pressed="false"><?= e($label('editor.json')) ?></button></div></div>
<form method="post" action="<?= e($context->url($operation)) ?>" class="document-form" <?= $attribute ?>>
<input type="hidden" name="csrf_token" value="<?= e(Security::token()) ?>"><input type="hidden" name="revision" value="<?= e($context->revision) ?>">
<section class="panel"><p class="muted"><?= e($context->language->text($help)) ?></p></section>
<div class="notice error" data-editor-error role="alert" hidden></div>
<section class="panel editor-panel"><div data-fields hidden></div><label data-json-label><?= e($label('editor.document')) ?><textarea name="document" data-document data-editor-schema="<?= e(json_encode($schema,JSON_THROW_ON_ERROR)) ?>" data-editor-common-messages="<?= e(json_encode($ui->clientMessages(),JSON_THROW_ON_ERROR)) ?>" data-editor-messages="<?= e(json_encode($context->language->messages(),JSON_THROW_ON_ERROR)) ?>" data-editor-choices="<?= e(json_encode($choices,JSON_THROW_ON_ERROR)) ?>" class="json-editor" rows="18" spellcheck="false" required><?= e(JsonStore::encode($settings)) ?></textarea></label></section>
<div class="savebar"><span class="muted"><?= e($label('editor.revision',['key'=>$context->manifest['id'],'revision'=>substr($context->revision,0,10)])) ?></span><button type="submit" class="button-primary"><?= e($label('editor.save')) ?></button></div></form>
