<?php
/** Private Pages history: named actions and readable before/current comparison; restoring affects only the working snapshot. */
declare(strict_types=1);
use DBLESSCMS\{PagePublications,JsonStore,Icons};
use function DBLESSCMS\e;
$reader=new PagePublications($context->store);$entries=$reader->history($key);$record=$reader->record($key);
?>
<section class="panel"><h2><?= e($record['draft']['document']['meta']['title']) ?></h2><p><?= e($t('pages.restoreDraftHelp')) ?></p>
<?php foreach($entries as $backup=>$entry): ?><details><summary><?= e($entry['at'].' · '.$t('pages.action.'.$entry['action'])) ?></summary><pre class="json-editor"><?= e(JsonStore::encode(['before'=>$entry['record']['draft'],'current'=>$record['draft']])) ?></pre><form method="post" action="<?= e($context->url('editorial-restore')) ?>" data-pages-form><?php foreach(['key'=>$key,'backup'=>$backup,'csrf_token'=>$csrf,'catalog_revision'=>$publicationCatalog['revision'],'site_revision'=>$context->store->read('site')['revision'],'source_revision'=>''] as $field=>$value): ?><input type="hidden" name="<?= e($field) ?>" value="<?= e($value) ?>"><?php endforeach; ?><button type="submit" class="icon-button" title="<?= e($t('pages.action.restore')) ?>" aria-label="<?= e($t('pages.action.restore')) ?>"><?= Icons::svg('restore',$context->base) ?></button></form></details><?php endforeach; ?>
</section>
