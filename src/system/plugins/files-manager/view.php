<?php
/** Authenticated media explorer: canonical icons, accessible tree/table/grid, localizable dialogs and shared action dispatch. */
declare(strict_types=1);
use DBLESSCMS\Icons;
use function DBLESSCMS\e;
use function DBLESSCMS\asset;
/** Translate a plugin key without effects; callers escape text/attributes. */
$t=static fn(string $key): string => $context->language->text('fm.'.$key);
$actions=['up','refresh','upload','mkdir','grid','list','rename','move','download','preview','metadata','replace','delete','restore','purge','trash','more','public','private','cancel','confirm','close'];
$icons=[];foreach(array_merge($actions,['folder','image','video','file-earmark-text','sort-up','sort-down','chevron-right','chevron-down','arrow-right']) as $action)$icons[$action]=Icons::url($action,$context->base);
/** Render one icon-only action with translated tooltip/name; receives action, optional label/class, no storage effects. */
$button=static function(string $action,?string $label=null,string $class='')use($t,$context):string{
  $label??=$action==='mkdir'?'newFolder':$action;
  return '<button type="button" class="fm-action '.e($class).'" data-action="'.e($action).'" title="'.e($t($label)).'" aria-label="'.e($t($label)).'">'.Icons::svg($action,$context->base).'</button>';
};
?>
<?php if (!$context->integrated): ?>
<!doctype html><html lang="<?= e($context->language->locale()) ?>" dir="<?= e($context->language->direction()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= e($t('title')) ?> · DBLESS-CMS</title>
<link rel="stylesheet" href="<?= e(asset('cms-admin/assets/admin.css',$context->base)) ?>"><link rel="stylesheet" href="<?= e(asset('plugins/files-manager/'.$context->manifest['assets']['css'],$context->base)) ?>"><script defer src="<?= e(asset('plugins/files-manager/'.$context->manifest['assets']['js'],$context->base)) ?>"></script></head><body class="plugin-standalone plugin-content">
<?php endif; ?>
<section class="fm" aria-label="<?= e($t('title')) ?>" data-fm data-screen="<?= e($screen) ?>" data-api="<?= e($context->url('')) ?>" data-csrf="<?= e(\DBLESSCMS\Security::token()) ?>" data-page-size="<?= e((string)($context->settings['browser']['pageSize']??25)) ?>" data-icons="<?= e(json_encode($icons,JSON_THROW_ON_ERROR)) ?>" data-messages="<?= e(json_encode($context->language->messages(),JSON_THROW_ON_ERROR)) ?>">
<header class="fm-header"><?php if (!$context->integrated): ?><h1><?= e($t('title')) ?></h1><?php endif; ?><nav aria-label="<?= e($t('title')) ?>"><?php foreach(['public','private'] as $scope): ?><button type="button" class="fm-action" data-scope="<?= e($scope) ?>" aria-pressed="<?= $scope==='public'?'true':'false' ?>" title="<?= e($t($scope)) ?>" aria-label="<?= e($t($scope)) ?>"><?= Icons::svg($scope,$context->base) ?></button><?php endforeach; ?></nav><p data-scope-help></p></header>
<div class="fm-layout"><aside class="fm-sidebar"><h2><?= e($t('folders')) ?></h2><nav data-tree aria-label="<?= e($t('folders')) ?>"></nav><?= $button('trash') ?></aside>
<div class="fm-main"><div class="fm-toolbar" role="toolbar" aria-label="<?= e($t('files')) ?>">
<?php foreach(['up','refresh','upload','mkdir'] as $action)echo $button($action,null,$action==='upload'?'button-primary':''); ?>
<input type="file" data-upload multiple accept=".jpg,.jpeg,.png,.gif,.webp,.ico,.pdf,.txt,.mp4" hidden aria-label="<?= e($t('upload')) ?>"><input type="file" data-replace hidden aria-label="<?= e($t('replace')) ?>">
<span class="fm-toolbar-separator" aria-hidden="true"></span><?= $button('grid') ?><?= $button('list') ?>
</div><nav class="fm-breadcrumb" data-breadcrumb aria-label="<?= e($t('root')) ?>"></nav>
<div class="fm-filters"><label class="fm-search"><span class="fm-sr"><?= e($t('search')) ?></span><input type="search" data-filter placeholder="<?= e($t('search')) ?>"></label><label><span class="fm-sr"><?= e($t('filterType')) ?></span><select data-type-filter aria-label="<?= e($t('filterType')) ?>"><?php foreach(['allTypes','images','videos','documents','folders'] as $key): ?><option value="<?= e($key) ?>"><?= e($t($key)) ?></option><?php endforeach; ?></select></label><label><span class="fm-sr"><?= e($t('sort')) ?></span><select data-sort aria-label="<?= e($t('sort')) ?>"><?php foreach(['name','type','size','added'] as $key): ?><option value="<?= e($key) ?>"><?= e($t($key)) ?></option><?php endforeach; ?></select></label><button type="button" class="fm-action" data-direction title="<?= e($t('ascending')) ?>" aria-label="<?= e($t('ascending')) ?>"><?= Icons::svg('sort-up',$context->base) ?></button></div>
<p class="fm-status" data-status role="status" aria-live="polite"></p><progress data-progress max="100" value="0" hidden aria-label="<?= e($t('upload')) ?>"></progress>
<div class="fm-selection"><span data-selected><?= e($t('files')) ?></span><div role="toolbar" aria-label="<?= e($t('actions')) ?>"><?php foreach(['rename','move','download','preview','metadata','replace','delete','restore','purge'] as $action)echo $button($action); ?></div></div>
<section class="fm-dropzone" data-dropzone aria-label="<?= e($t('files')) ?>"><div class="fm-table-scroll" data-table><table class="fm-table"><thead><tr><?php foreach(['name','type','size','added'] as $key): ?><th scope="col" data-column="<?= e($key) ?>"><button type="button" data-column-sort="<?= e($key) ?>" title="<?= e($t($key)) ?>"><?= e($t($key)) ?><span data-sort-icon aria-hidden="true"></span></button></th><?php endforeach; ?></tr></thead><tbody data-list-items></tbody></table></div><div class="fm-grid" data-grid-items hidden></div><p class="fm-empty" data-empty hidden></p></section>
<footer class="fm-footer"><nav class="fm-pagination" aria-label="<?= e($t('files')) ?>"><button type="button" class="fm-action" data-page="previous" title="<?= e($t('previous')) ?>" aria-label="<?= e($t('previous')) ?>"><?= Icons::svg('arrow-right',$context->base,'admin-icon fm-back') ?></button><span data-page-info role="status"></span><button type="button" class="fm-action" data-page="next" title="<?= e($t('next')) ?>" aria-label="<?= e($t('next')) ?>"><?= Icons::svg('arrow-right',$context->base) ?></button></nav><small><?= e($t('drop')) ?> <?= e($t('help')) ?></small></footer>
</div></div>
<div class="fm-context" data-context role="menu" popover="manual" aria-label="<?= e($t('actions')) ?>"></div>
<dialog class="fm-dialog" data-prompt aria-labelledby="fm-prompt-title"><form method="dialog" data-prompt-form><h2 id="fm-prompt-title"></h2><p data-prompt-help></p><label data-prompt-label><span data-prompt-text></span><input data-prompt-input maxlength="240" autocomplete="off"></label><div class="fm-dialog-actions"><?= $button('cancel') ?><button class="fm-action button-primary" type="submit" title="<?= e($t('confirm')) ?>" aria-label="<?= e($t('confirm')) ?>"><?= Icons::svg('confirm',$context->base) ?></button></div></form></dialog>
<dialog class="fm-preview" data-preview aria-label="<?= e($t('preview')) ?>"><?= $button('close') ?><img alt="" data-preview-image hidden><video data-preview-video controls playsinline preload="metadata" hidden></video><p data-preview-error hidden><?= e($t('videoUnavailable')) ?></p></dialog>
</section>
<?php if (!$context->integrated): ?></body></html><?php endif; ?>
