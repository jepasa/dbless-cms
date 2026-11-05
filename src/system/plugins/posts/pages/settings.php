<?php
/** Publication settings and bounded type catalog; Core tables and a native modal share the existing CSRF/CAS save-and-redirect contract. */
declare(strict_types=1);
use DBLESSCMS\{AdminTable,Icons,PostTypes,PostsRouter};
use function DBLESSCMS\e;
$active=(new \DBLESSCMS\Plugins($context->store))->state('posts')['enabled'];if($id!==''&&!isset($data['types'][$id]))throw new \DBLESSCMS\HttpError(404,'Type unavailable.','posts.error.missing');
$columns=['type'=>['label'=>$t('type'),'value'=>static fn(array $type):string=>PostsRouter::label($type,$context->language,true)],'id'=>['label'=>$t('identifier'),'value'=>static fn(array $type):string=>$type['id']],'route'=>['label'=>$t('routeMode'),'value'=>static fn(array $type):string=>PostTypes::route([$type['id']=>$type],['type'=>$type['id'],'slug'=>'{slug}'])],'actions'=>['label'=>$t('actions')]];
$typeTable=new AdminTable($data['types'],$columns,['view'=>'plugin-panel','plugin'=>'posts','route'=>'settings'],$context->language->locale(),$context->base,['pageSize'=>PostTypes::MAX_COUNT]);
$canCreate=$active&&count($data['types'])<PostTypes::MAX_COUNT;
?>
<section class="panel stack"><h2><?= e($t('publicationOptions')) ?></h2>
<?= \DBLESSCMS\PluginOptions::render($context,'options-save','data-pages-form','posts.optionsHelp',[],true,['creation']) ?>
</section>
<?php if(!$active): ?><p class="notice"><?= e($t('activationHelp')) ?></p><?php endif; ?>
<?= AdminTable::assets($context->base) ?>
<section class="panel overview-catalog" data-post-types aria-labelledby="posts-types-title">
<div class="posts-types-heading"><h2 id="posts-types-title"><?= e($t('publicationTypes')) ?></h2><button type="button" class="button-primary" data-post-type-new aria-haspopup="dialog" aria-controls="posts-type-dialog" aria-describedby="posts-type-limit" <?= !$canCreate?'disabled':'' ?>><?= e($t('typeNew')) ?></button></div>
<p class="muted"><?= e($t('routeHelp')) ?></p><p class="muted" id="posts-type-limit"><?= e($t('typeLimitHelp',['limit'=>(string)PostTypes::MAX_COUNT])) ?></p>
<div class="table-scroll"><table class="extension-table"><caption class="sr-only"><?= e($t('publicationTypes')) ?></caption><thead><tr><?= $typeTable->headings() ?></tr></thead><tbody>
<?php foreach($typeTable->rows as $key=>$type):$prefix=PostTypes::prefix($type); ?><tr><td><?= e(PostsRouter::label($type,$context->language,true)) ?></td><td><?= e($key) ?></td><td><code><?= e($prefix===null?'/plugins/blog/article?slug={slug}':('/'.($prefix===''?'':$prefix.'/').'{slug}')) ?></code></td><td><div class="row-actions"><button type="button" class="icon-button" data-post-type-edit="<?= e($key) ?>" aria-haspopup="dialog" aria-controls="posts-type-dialog" title="<?= e($t('edit')) ?>" aria-label="<?= e($t('edit')) ?>" <?= !$active?'disabled':'' ?>><?= Icons::svg('edit',$context->base) ?></button><?php if(!in_array($key,['page','article'],true)): ?><form method="post" action="<?= e($context->url('type-remove')) ?>" data-pages-form data-confirm="<?= e($t('confirm')) ?>"><?php $hidden($key); ?><button class="icon-button" title="<?= e($t('typeRemove')) ?>" aria-label="<?= e($t('typeRemove')) ?>" <?= !$active?'disabled':'' ?>><?= Icons::svg('uninstall',$context->base) ?></button></form><?php endif; ?></div></td></tr><?php endforeach; ?></tbody></table></div>
<div class="posts-types-footer"><?= $typeTable->footer() ?><p class="catalog-summary" data-post-type-count role="status"><?= e($t('typeCount',['count'=>(string)count($data['types']),'limit'=>(string)PostTypes::MAX_COUNT])) ?></p></div>
</section>
<?php require __DIR__.'/type-dialog.php'; ?>
<form class="panel stack" method="post" action="<?= e($context->url('settings')) ?>" data-pages-form><?php $hidden(); ?><label><?= e($t('searchPath')) ?><input name="searchPath" maxlength="128" required value="<?= e($data['searchPath']) ?>"></label><p class="muted"><?= e($t('searchHelp')) ?></p><a class="text-link" href="<?= e($context->base.'/'.$repository->publicSearchPath($data)) ?>" target="_blank" rel="noopener noreferrer"><?= e($t('publicSearch')) ?></a><div class="savebar"><button class="button-primary"><?= e($t('saveSettings')) ?></button></div></form>
