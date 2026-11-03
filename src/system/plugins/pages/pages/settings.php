<?php
/** Pages catalog options reuse the revisioned core options editor; legacy creation fields remain hidden and preserved. */
declare(strict_types=1);
?>
<div class="pages-plugin" data-pages-plugin data-network-error="<?= \DBLESSCMS\e($context->language->text('pages.network')) ?>"><p data-pages-status role="status" hidden></p>
<?= \DBLESSCMS\PluginOptions::render($context,'settings-save','data-pages-form','pages.settingsHelp',[],true,['creation']) ?>
</div>
