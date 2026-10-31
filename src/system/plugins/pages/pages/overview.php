<?php
/** Catálogo de páginas no padrão de extensões: tabela responsiva, ações acessíveis e remoção recuperável revisionada. */
declare(strict_types=1);
use function DBLESSCMS\e;
use DBLESSCMS\Icons;
$homepageRegistry=new \DBLESSCMS\Plugins($context->store);$homepageEnabled=isset($homepageRegistry->all()['homepage'])&&$homepageRegistry->state('homepage')['enabled'];
?>
<section class="panel extension-panel">
<div class="table-scroll"><table class="extension-table"><caption class="sr-only"><?= e($t('nav.pages')) ?></caption>
<thead><tr><?php foreach (['name','description','status','actions'] as $column): ?><th scope="col"><?= e($t('catalog.'.$column)) ?></th><?php endforeach; ?></tr></thead>
<tbody><?php foreach ($pages as $slug=>$item): $data=$item['data']; $name=$data['meta']['title']; $protected=in_array((string)$slug,['home','404'],true); ?>
<tr data-page-id="<?= e($slug) ?>">
<td data-label="<?= e($t('catalog.name')) ?>"><strong><?= e($name) ?></strong><small class="extension-id">/<?= $slug==='home'?'':e($slug) ?></small></td>
<td data-label="<?= e($t('catalog.description')) ?>"><?= e($data['meta']['description']) ?></td>
<td data-label="<?= e($t('catalog.status')) ?>"><span class="status <?= e($data['status']) ?>"><?= e($t('status.'.$data['status'])) ?></span></td>
<td data-label="<?= e($t('catalog.actions')) ?>"><div class="row-actions">
<?php foreach (['preview','history','edit'] as $action): $label=$t('pages.'.$action.'_action',['name'=>$name]); ?>
<a class="icon-button" href="<?= e($action==='edit'&&$slug==='home'&&$homepageEnabled?$context->base.'/cms-admin/?view=plugin-panel&plugin=homepage&route=overview':$link($action==='edit'?'editor':$action,(string)$slug)) ?>" title="<?= e($label) ?>" aria-label="<?= e($label) ?>" <?= $action==='preview'?'target="_blank" rel="noopener"':'' ?>><?= Icons::svg($action,$context->base) ?></a>
<?php endforeach; ?>
<form method="post" action="<?= e($context->url('remove')) ?>" data-pages-form data-confirm="<?= e($t('editor.confirm_remove')) ?>">
<input type="hidden" name="action" value="remove"><input type="hidden" name="key" value="<?= e($slug) ?>"><input type="hidden" name="revision" value="<?= e($item['revision']) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
<button type="submit" class="icon-button danger" title="<?= e($protected?$t('pages.required'):$t('pages.remove_action',['name'=>$name])) ?>" aria-label="<?= e($t('pages.remove_action',['name'=>$name])) ?>" <?= $protected?'disabled':'' ?>><?= Icons::svg('uninstall',$context->base) ?></button>
</form></div></td></tr>
<?php endforeach; ?></tbody></table></div>
</section>
