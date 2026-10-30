<?php
/** Configuração declarativa legada com links integrados, dicionários próprios e revisão; ativação pertence ao catálogo. */
declare(strict_types=1);
use function DBLESSCMS\e;
$pluginDocument=$plugins->document();
?>
<p class="muted"><?= e($t('plugins.trust')) ?></p>
<?php if ($plugins->invalid()!==[]): ?><div class="notice error"><?= e($t('plugins.invalid',['files'=>implode(', ',array_keys($plugins->invalid()))])) ?></div><?php endif; ?>
<?php foreach ([$settingsPlugin=>$plugins->get($settingsPlugin)] as $pluginId=>$manifest): $state=$plugins->state($pluginId); $catalog=$plugins->language($pluginId,$language->locale()); ?>
<section class="panel stack"><div class="section-heading"><h2><?= e($manifest['name']) ?></h2><span class="version-tag"><?= e($manifest['version']) ?></span></div>
<form method="post" class="stack"><input type="hidden" name="action" value="plugin-settings"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="plugin" value="<?= e($pluginId) ?>"><input type="hidden" name="revision" value="<?= e($pluginDocument['revision']) ?>">
<?php echo \DBLESSCMS\OptionFields::html($plugins->schema($pluginId),$state['settings'],$catalog,'settings'); ?>
<p class="muted"><?= $catalog->has('setting.help')?e($catalog->text('setting.help')):'' ?></p>
<div class="template-actions"><button class="button-primary" type="submit"><?= e($t('plugins.save')) ?></button><?php if ($state['enabled']): ?><a class="button-secondary" href="<?= e($base.'/cms-admin/?view='.(isset($manifest['routes'])?'plugin-panel':'plugin').'&plugin='.rawurlencode($pluginId)) ?>"><?= e($t('plugins.open')) ?></a><?php endif; ?></div>
</form></section>
<?php endforeach; ?>
<section class="panel"><h2><?= e($t('nav.history')) ?></h2>
<?php $pluginHistory=$this->store->history('plugins'); if ($pluginHistory===[]): ?><p class="muted"><?= e($t('editor.no_history')) ?></p><?php endif; ?>
<?php foreach ($pluginHistory as $backup): ?><form method="post" class="history-row" data-confirm="<?= e($t('editor.confirm_restore')) ?>"><span><?= e(substr($backup,0,15)) ?></span><input type="hidden" name="action" value="restore"><input type="hidden" name="key" value="plugins"><input type="hidden" name="revision" value="<?= e($pluginDocument['revision']) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="backup" value="<?= e($backup) ?>"><button type="submit" class="button-secondary"><?= e($t('editor.restore')) ?></button></form><?php endforeach; ?>
</section>
