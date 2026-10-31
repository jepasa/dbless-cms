<?php
/** Additional recoverable Widgets catalogs in the existing trash; no permanent deletions or template bindings. */
declare(strict_types=1);
?>
<?php foreach(['reusable'=>'library-recover','sets'=>'set-recover'] as $map=>$operation): ?><section class="panel"><h2><?= e($t($map==='sets'?'sets':'library')) ?></h2>
<?php foreach($data[$map]??[] as $record):if(!$record['deleted'])continue; ?><form class="history-row" method="post" action="<?= e($context->url($operation)) ?>" data-widget-form><span><?= e($record['key']) ?></span><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="id" value="<?= e($record['id']) ?>"><button class="button-secondary" type="submit"><?= e($t('recover')) ?></button></form><?php endforeach; ?></section><?php endforeach; ?>
