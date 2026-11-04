<?php
/** Private publication history: whole-catalog snapshots are inspected only for the chosen immutable record and restored into its working draft. */
declare(strict_types=1);
use function DBLESSCMS\e;
?>
<section class="panel"><p class="muted"><?= e($t('historyHelp')) ?></p>
<?php if($id===''):foreach($rows as $key=>$row): ?><p><a href="<?= e($url('history',['id'=>$key])) ?>"><?= e($row['draft']['document']['meta']['title']) ?></a></p><?php endforeach;
else:$row=$rows[$id]??throw new \DBLESSCMS\HttpError(404,'Publication unavailable.','posts.error.missing');$found=false;
foreach($repository->repository()->history() as $backup):$prior=$repository->repository()->snapshot($backup);if(!isset($prior['records'][$id]))continue;$found=true; ?>
<form method="post" action="<?= e($context->url('restore')) ?>" class="history-row" data-pages-form data-confirm="<?= e($t('confirm')) ?>"><?php $hidden($id); ?><input type="hidden" name="backup" value="<?= e($backup) ?>"><span><?= e($backup) ?></span><button class="button-secondary"><?= e($t('restore')) ?></button></form>
<?php endforeach;if(!$found): ?><p class="empty-state"><?= e($t('noHistory')) ?></p><?php endif;if($row['source']['plugin']!=='posts'): ?>
<h2><?= e($t('nativeHistory')) ?></h2><?php foreach($repository->nativeHistory($row) as $backup=>$native): ?><form method="post" action="<?= e($context->url('restore-native')) ?>" data-pages-form class="history-row" data-confirm="<?= e($t('confirm')) ?>"><?php $hidden($id); ?><input type="hidden" name="backup" value="<?= e($backup) ?>"><span><?= e($backup) ?> · <?= e($native['document']['meta']['title']) ?></span><button class="button-secondary"><?= e($t('restore')) ?></button></form><?php endforeach; ?>
<form method="post" action="<?= e($context->url('restore-source')) ?>" data-pages-form data-confirm="<?= e($t('confirm')) ?>"><?php $hidden($id); ?><button class="button-secondary"><?= e($t('restore')) ?> · <?= e($t('source')) ?></button></form>
<a class="text-link" href="<?= e($context->base.'/cms-admin/?'.http_build_query(['view'=>'plugin-panel','plugin'=>$row['source']['plugin'],'route'=>'history']+($row['source']['plugin']==='pages'?['key'=>$row['source']['id']]:[]))) ?>"><?= e($t('nativeHistory')) ?></a>
<?php endif;endif; ?></section>
