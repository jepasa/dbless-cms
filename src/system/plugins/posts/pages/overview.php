<?php
/** Unified publication catalog using AdminTable sorting/pagination and typed module-owned facet controls; no public/draft state is changed by rendering. */
declare(strict_types=1);
use function DBLESSCMS\e;
$query=\DBLESSCMS\Posts::filters($_GET);unset($query['page']);
$canEdit=\DBLESSCMS\Access::allows($context->config,'posts.edit')||\DBLESSCMS\Access::allows($context->config,'posts.own');
$result=$repository->search($query,$context->base,$canEdit,$route==='trash');$filtered=[];foreach($result['items'] as $item)if(isset($rows[$item['id']]))$filtered[$item['id']]=$item;

$columns=['title'=>['label'=>$t('title'),'value'=>static fn(array $item):string=>$item['title']],'type'=>['label'=>$t('type'),'value'=>static fn(array $item):string=>$item['type']],'state'=>['label'=>$t('publication'),'value'=>static fn(array $item):string=>$item['state']],'date'=>['label'=>$t('date'),'value'=>static fn(array $item):string=>$item['publishedAt']],'actions'=>['label'=>$t('actions')]];
$table=new \DBLESSCMS\AdminTable($filtered,$columns,['view'=>'plugin-panel','plugin'=>'posts','route'=>$route]+$query,$context->language->locale(),$context->base,['pageSize'=>$context->settings['browser']['pageSize'],'names'=>['search'=>'table_search']]);
?>
<?= \DBLESSCMS\AdminTable::assets($context->base) ?>
<form method="get" class="catalog-toolbar posts-filters"><input type="hidden" name="view" value="plugin-panel"><input type="hidden" name="plugin" value="posts"><input type="hidden" name="route" value="<?= e($route) ?>">
<?php if(\DBLESSCMS\Access::allows($context->config,'posts.create')): ?><a class="button-primary" href="<?= e($url('new')) ?>"><?= e($t('new')) ?></a><?php endif; ?>
<label><?= e($t('title')) ?><input type="search" name="q" maxlength="128" value="<?= e($query['q']) ?>"></label>
<label><?= e($t('type')) ?><select name="type" aria-label="<?= e($t('type')) ?>"><option value=""><?= e($t('allTypes')) ?></option><?php foreach($data['types'] as $key=>$type): ?><option value="<?= e($key) ?>" <?= $query['type']===$key?'selected':'' ?>><?= e(\DBLESSCMS\PostsRouter::label($type,$context->language)) ?></option><?php endforeach; ?></select></label>
<label><?= e($t('groups')) ?><select name="group" aria-label="<?= e($t('groups')) ?>"><option value=""><?= e($t('allGroups')) ?></option><?php foreach($terms['groups'] as $key=>$term):if($term['deleted'])continue; ?><option value="<?= e($key) ?>" <?= $query['group']===$key?'selected':'' ?>><?= e($term['name']) ?></option><?php endforeach; ?></select></label>
<label><?= e($t('tags')) ?><select name="tags[]" multiple size="3" aria-label="<?= e($t('tags')) ?>"><?php foreach($terms['tags'] as $key=>$term):if($term['deleted'])continue; ?><option value="<?= e($key) ?>" <?= in_array($key,explode(',',$query['tags']),true)?'selected':'' ?>><?= e($term['name']) ?></option><?php endforeach; ?></select></label><button type="submit" class="button-secondary"><?= e($t('filter')) ?></button>
<a class="text-link" target="_blank" rel="noopener noreferrer" href="<?= e($context->base.'/'.$data['searchPath']) ?>"><?= e($t('publicSearch')) ?></a>
</form>
<section class="panel"><div class="table-scroll"><table class="extension-table"><caption class="sr-only"><?= e($t($route)) ?></caption><thead><tr><?= $table->headings() ?></tr></thead><tbody>
<?php foreach($table->rows as $item): ?><tr><td data-label="<?= e($t('title')) ?>"><a href="<?= e($canEdit?$url('editor',['id'=>$item['id']]):$item['url']) ?>"><?= e($item['title']) ?></a></td><td data-label="<?= e($t('type')) ?>"><?= e(\DBLESSCMS\PostsRouter::label($data['types'][$item['type']],$context->language)) ?></td><td data-label="<?= e($t('publication')) ?>"><?= e($t('state.'.$item['state'])) ?></td><td data-label="<?= e($t('date')) ?>"><?= e($item['publishedAt']) ?></td><td data-label="<?= e($t('actions')) ?>"><div class="row-actions">
<?php if($canEdit&&$route!=='trash'): ?><?= \DBLESSCMS\AdminTable::action($url('editor',['id'=>$item['id']]),'edit',$t('edit'),$context->base) ?><?= \DBLESSCMS\AdminTable::action($url('preview',['id'=>$item['id']]),'preview',$t('preview'),$context->base,true) ?><?php endif; ?>
<?php if($canEdit): ?><form method="post" action="<?= e($context->url($route==='trash'?'recover':'trash')) ?>" data-pages-form data-confirm="<?= e($t('confirm')) ?>"><?php $hidden($item['id']); ?><button class="icon-button" title="<?= e($t($route==='trash'?'recover':'remove')) ?>" aria-label="<?= e($t($route==='trash'?'recover':'remove')) ?>"><?= \DBLESSCMS\Icons::svg($route==='trash'?'restore':'uninstall',$context->base) ?></button></form><?php endif; ?>
</div></td></tr><?php endforeach; ?></tbody></table></div><?php if($table->rows===[]): ?><p class="empty-state"><?= e($t('empty')) ?></p><?php endif; ?><?= $table->footer() ?></section>
