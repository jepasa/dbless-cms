<?php
/** Interface interna Files Manager; atributos JSON escapados, componentes do painel e textos do dicionário privado. */
declare(strict_types=1);
use function CMS2JSON\e;
use function CMS2JSON\asset;
/** Traduz chave do plugin sem efeitos; consumidor aplica escape de saída. */
$t=static fn(string $key): string => $context->language->text('fm.'.$key);
?>
<?php if (!$context->integrated): ?>
<!doctype html><html lang="<?= e($context->language->locale()) ?>" dir="<?= e($context->language->direction()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title><?= e($t('title')) ?> · CMS2JSON</title>
<link rel="stylesheet" href="<?= e(asset('cms-admin/assets/admin.css',$context->base)) ?>">
<link rel="stylesheet" href="<?= e(asset('plugins/files-manager/'.$context->manifest['assets']['css'],$context->base)) ?>"><script defer src="<?= e(asset('plugins/files-manager/'.$context->manifest['assets']['js'],$context->base)) ?>"></script></head>
<body class="plugin-standalone plugin-content"><?php endif; ?>
<section class="fm" aria-label="<?= e($t('title')) ?>" data-fm data-screen="<?= e($screen) ?>" data-api="<?= e($context->url('')) ?>" data-csrf="<?= e(\CMS2JSON\Security::token()) ?>" data-messages="<?= e(json_encode($context->language->messages(),JSON_THROW_ON_ERROR)) ?>">
<header class="fm-header"><?php if (!$context->integrated): ?><h1><?= e($t('title')) ?></h1><?php endif; ?><nav aria-label="<?= e($t('title')) ?>"><button type="button" data-scope="public" aria-pressed="true"><?= e($t('public')) ?></button><button type="button" data-scope="private" aria-pressed="false"><?= e($t('private')) ?></button></nav></header>
<div class="fm-layout"><aside class="fm-sidebar"><h2><?= e($t('folders')) ?></h2><nav data-tree aria-label="<?= e($t('folders')) ?>"></nav><button class="fm-trash-button" type="button" data-action="trash">♲ <?= e($t('trash')) ?></button></aside>
<div class="fm-main"><div class="fm-toolbar" aria-label="<?= e($t('files')) ?>">
<button type="button" data-action="up" title="<?= e($t('up')) ?>" aria-label="<?= e($t('up')) ?>">↑</button>
<button type="button" data-action="refresh" title="<?= e($t('refresh')) ?>" aria-label="<?= e($t('refresh')) ?>">↻</button>
<button type="button" class="button-primary" data-action="upload">↑ <?= e($t('upload')) ?></button><input type="file" data-upload multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt" hidden aria-label="<?= e($t('upload')) ?>">
<button type="button" data-action="mkdir">＋ <?= e($t('newFolder')) ?></button>
<label class="fm-filter"><span class="fm-sr"><?= e($t('search')) ?></span><input type="search" data-filter placeholder="<?= e($t('search')) ?>"></label>
<button type="button" data-action="grid" aria-pressed="false" aria-label="<?= e($t('grid')) ?>">▦</button><button type="button" data-action="list" aria-pressed="true" aria-label="<?= e($t('list')) ?>">☰</button>
</div><nav class="fm-breadcrumb" data-breadcrumb aria-label="<?= e($t('root')) ?>"></nav>
<p class="fm-scope-help" data-scope-help></p><p class="fm-status" data-status role="status" aria-live="polite"></p><progress data-progress max="100" value="0" hidden aria-label="<?= e($t('upload')) ?>"></progress>
<div class="fm-selection" data-selection><span data-selected><?= e($t('files')) ?></span><div>
<?php foreach (['rename'=>'✎','move'=>'↪','download'=>'↓','preview'=>'◉','delete'=>'♲','restore'=>'↶','purge'=>'×'] as $action=>$icon): ?><button type="button" data-action="<?= e($action) ?>" disabled><?= e($icon.' '.$t($action)) ?></button><?php endforeach; ?>
</div></div>
<section class="fm-dropzone" data-dropzone aria-label="<?= e($t('files')) ?>"><div class="fm-items" data-items></div><p class="fm-empty" data-empty hidden></p></section>
<footer class="fm-footer"><p><?= e($t('drop')) ?></p><small><?= e($t('help')) ?></small></footer>
</div></div>
<dialog class="fm-dialog" data-prompt aria-labelledby="fm-prompt-title"><form method="dialog" data-prompt-form><h2 id="fm-prompt-title"></h2><p data-prompt-help></p><label data-prompt-label><span data-prompt-text></span><input data-prompt-input maxlength="240" autocomplete="off"></label><div class="fm-dialog-actions"><button type="button" data-prompt-cancel><?= e($t('cancel')) ?></button><button class="button-primary" type="submit"><?= e($t('confirm')) ?></button></div></form></dialog>
<dialog class="fm-preview" data-preview aria-label="<?= e($t('preview')) ?>"><button type="button" data-close-preview><?= e($t('close')) ?></button><img alt="" data-preview-image></dialog>
</section>
<?php if (!$context->integrated): ?></body></html><?php endif; ?>
