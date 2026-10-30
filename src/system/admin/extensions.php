<?php
/** Tabela administrativa de extensões: metadados traduzidos, diagnóstico e ações por ícones com sessão/CSRF/revisão. */
declare(strict_types=1);
use function CMS2JSON\e;
use CMS2JSON\Icons;
?>
<section class="panel extension-panel">
<div class="table-scroll"><table class="extension-table"><caption class="sr-only"><?= e($labels[$view]) ?></caption>
<thead><tr><?php foreach (['name','description','status','actions'] as $column): ?><th scope="col"><?= e($t('catalog.'.$column)) ?></th><?php endforeach; ?></tr></thead>
<tbody><?php foreach ($extensionRows as $row):
  $healthy=in_array($row['status'],['active','inactive'],true);
  $toggle=$row['enabled']?'deactivate':'activate';
  $toggleDisabled=!$healthy || ($row['protected']&&$row['enabled']);
  $reason=$healthy?$t('catalog.default_help'):$t('catalog.unavailable');
  $configure=$view==='plugins'?(is_string($row['configuration'])?'?view=plugin-panel&plugin='.rawurlencode($row['id']).'&route='.rawurlencode($row['configuration']):'?view=plugin-settings&plugin='.rawurlencode($row['id'])):'?view=template-settings&template='.rawurlencode($row['id']);
  $canConfigure=$healthy && $row['configuration']!==false && ($view==='plugins' || $row['configuration']!==null);
?>
<tr data-extension-id="<?= e($row['id']) ?>">
<td data-label="<?= e($t('catalog.name')) ?>"><strong><?= e($row['name']) ?></strong><small class="extension-id"><?= e($row['id'].($row['version']!==''?' · v'.$row['version']:'')) ?></small><?php if ($row['protected']): ?><span class="count-pill"><?= e($t('catalog.default')) ?></span><?php endif; ?></td>
<td data-label="<?= e($t('catalog.description')) ?>"><?= e($row['description']!==''?$row['description']:$t($healthy?'catalog.no_description':'catalog.unavailable')) ?></td>
<td data-label="<?= e($t('catalog.status')) ?>"><span class="status <?= $row['status']==='active'?'published':($healthy?'inactive':'draft') ?>"><?= e($t('catalog.'.$row['status'])) ?></span></td>
<td data-label="<?= e($t('catalog.actions')) ?>"><div class="row-actions">
<form method="post"><input type="hidden" name="action" value="extension-toggle"><input type="hidden" name="kind" value="<?= e($view) ?>"><input type="hidden" name="id" value="<?= e($row['id']) ?>"><input type="hidden" name="revision" value="<?= e($managementRevision) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="enabled" value="<?= $row['enabled']?'0':'1' ?>">
<button class="icon-button <?= $row['enabled']?'is-active':'' ?>" type="submit" title="<?= e($toggleDisabled?$reason:$t('catalog.'.$toggle,['name'=>$row['name']])) ?>" aria-label="<?= e($t('catalog.'.$toggle,['name'=>$row['name']])) ?>" aria-pressed="<?= $row['enabled']?'true':'false' ?>" <?= $toggleDisabled?'disabled':'' ?>><?= Icons::svg($toggle,$base) ?></button></form>
<form method="post" data-uninstall="<?= e($row['id']) ?>" data-confirm="<?= e($t('catalog.confirm_uninstall',['name'=>$row['name']])) ?>"><input type="hidden" name="action" value="extension-uninstall"><input type="hidden" name="kind" value="<?= e($view) ?>"><input type="hidden" name="id" value="<?= e($row['id']) ?>"><input type="hidden" name="confirmation" value="<?= e($row['id']) ?>"><input type="hidden" name="revision" value="<?= e($managementRevision) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
<button class="icon-button danger" type="submit" title="<?= e($row['protected']?$t('catalog.default_help'):$t('catalog.uninstall',['name'=>$row['name']])) ?>" aria-label="<?= e($t('catalog.uninstall',['name'=>$row['name']])) ?>" <?= $row['protected']?'disabled':'' ?>><?= Icons::svg('uninstall',$base) ?></button></form>
<?php if ($canConfigure): ?><a class="icon-button" href="<?= e($configure) ?>" title="<?= e($t('catalog.configure',['name'=>$row['name']])) ?>" aria-label="<?= e($t('catalog.configure',['name'=>$row['name']])) ?>"><?= Icons::svg('configure',$base) ?></a>
<?php else: ?><button class="icon-button" type="button" disabled title="<?= e($t($healthy?'catalog.no_configuration':'catalog.unavailable')) ?>" aria-label="<?= e($t('catalog.configure',['name'=>$row['name']])) ?>"><?= Icons::svg('configure',$base) ?></button><?php endif; ?>
</div></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php if ($extensionRows===[]): ?><p class="empty-state"><?= e($t('catalog.empty')) ?></p><?php endif; ?>
</section>
<p class="muted"><?= e($t($view==='templates'?'catalog.templates_policy':'catalog.plugins_policy')) ?></p>
