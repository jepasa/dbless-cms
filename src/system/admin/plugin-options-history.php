<?php
/** Histórico individual de configurações: revisão compartilhada, CSRF e restauração sem alterar ativação ou outras extensões. */
declare(strict_types=1);
use CMS2JSON\Security;
use function CMS2JSON\e;
$history=$context->store->history('plugins');
?>
<section class="panel" id="plugin-options-history"><h2><?= e($ui->text('catalog.plugin_settings')) ?></h2>
<?php if ($history===[]): ?><p class="empty-state"><?= e($ui->text('editor.no_history')) ?></p><?php endif; ?>
<?php foreach ($history as $backup): ?><form method="post" action="<?= e($context->url('options-restore')) ?>" class="history-row" <?= $attribute ?> data-confirm="<?= e($ui->text('editor.confirm_restore')) ?>"><span><?= e(substr($backup,0,15)) ?></span><input type="hidden" name="csrf_token" value="<?= e(Security::token()) ?>"><input type="hidden" name="revision" value="<?= e($context->revision) ?>"><input type="hidden" name="backup" value="<?= e($backup) ?>"><button type="submit" class="button-secondary"><?= e($ui->text('editor.restore')) ?></button></form><?php endforeach; ?></section>
