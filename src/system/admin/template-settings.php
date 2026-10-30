<?php
/** Opções privadas do template, geradas pelo manifesto com componentes fixos, tradução local e revisão otimista. */
declare(strict_types=1);
use function DBLESSCMS\e;
use DBLESSCMS\OptionFields;
?>
<form method="post" class="panel stack theme-settings" data-theme-settings="<?= e($themeId) ?>">
<input type="hidden" name="action" value="template-options"><input type="hidden" name="template" value="<?= e($themeId) ?>"><input type="hidden" name="revision" value="<?= e($themeOptions['revision']) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
<?= OptionFields::html($themeOptions['schema'],$themeOptions['values'],$themeLanguage) ?>
<div class="template-actions"><a class="text-link" href="?view=templates"><?= e($t('nav.templates')) ?></a><button class="button-primary" type="submit"><?= e($t('editor.save')) ?></button></div>
</form>
