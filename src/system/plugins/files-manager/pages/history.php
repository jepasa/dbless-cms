<?php
/** Histórico próprio do File Manager; usa dicionário, componentes compartilhados e API do plugin com revisão/CSRF/histórico do CMS. */
declare(strict_types=1);
use function DBLESSCMS\e;
use function DBLESSCMS\asset;
/** Traduz rótulo da configuração sem escrita; consumidor escapa no contexto HTML. */
$t=static fn(string $key): string => $context->language->text($key);
$notice=$_SESSION['files-manager-notice']??null;unset($_SESSION['files-manager-notice']);
?>
<?php if (!$context->integrated): ?>
<!doctype html><html lang="<?= e($context->language->locale()) ?>" dir="<?= e($context->language->direction()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($t('fm.history')) ?></title>
<link rel="stylesheet" href="<?= e(asset('cms-admin/assets/admin.css',$context->base)) ?>"><script defer src="<?= e(asset('cms-admin/assets/admin.js',$context->base)) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('plugins/files-manager/'.$context->manifest['assets']['css'],$context->base)) ?>"><script defer src="<?= e(asset('plugins/files-manager/'.$context->manifest['assets']['js'],$context->base)) ?>"></script></head>
<body class="plugin-standalone plugin-content"><?php endif; ?>
<section class="fm fm-settings" data-fm-settings data-network-error="<?= e($t('fm.error.network')) ?>"><div><?php if (!$context->integrated): ?><h1><?= e($t('fm.history')) ?></h1><?php endif; ?><p data-settings-status role="status" aria-live="polite"><?= $notice==='fm.saved'?e($t('fm.saved')):'' ?></p>
<?= \DBLESSCMS\PluginOptions::history($context,'data-settings-form') ?>
</div></section>
<?php if (!$context->integrated): ?></body></html><?php endif; ?>
