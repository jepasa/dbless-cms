<?php
/** Histórico privado de operações descritas e cópias restauráveis; leitura limitada, traduções e escaping preservam os contratos de segurança. */
declare(strict_types=1);
use function DBLESSCMS\e;
use function DBLESSCMS\asset;
/** Traduz rótulo da configuração sem escrita; consumidor escapa no contexto HTML. */
$t=static fn(string $key): string => $context->language->text($key);
$historyError=null;
try {$events=\DBLESSCMS\ManagedFiles::history($context);}
catch (\DBLESSCMS\HttpError $failure) {http_response_code($failure->status);$historyError=$context->language->error($failure);$events=[];}
$notice=$_SESSION['files-manager-notice']??null;unset($_SESSION['files-manager-notice']);
?>
<?php if (!$context->integrated): ?>
<!doctype html><html lang="<?= e($context->language->locale()) ?>" dir="<?= e($context->language->direction()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($t('fm.history')) ?></title>
<link rel="stylesheet" href="<?= e(asset('cms-admin/assets/admin.css',$context->base)) ?>"><script defer src="<?= e(asset('cms-admin/assets/admin.js',$context->base)) ?>"></script>
<link rel="stylesheet" href="<?= e(asset('plugins/files-manager/'.$context->manifest['assets']['css'],$context->base)) ?>"><script defer src="<?= e(asset('plugins/files-manager/'.$context->manifest['assets']['js'],$context->base)) ?>"></script></head>
<body class="plugin-standalone plugin-content"><?php endif; ?>
<section class="fm fm-settings" data-fm-settings data-network-error="<?= e($t('fm.error.network')) ?>"><div><?php if (!$context->integrated): ?><h1><?= e($t('fm.history')) ?></h1><?php endif; ?><p data-settings-status role="status" aria-live="polite"><?= $notice==='fm.saved'?e($t('fm.saved')):'' ?></p>
<section class="panel" data-file-history><h2><?= e($t('fm.activity')) ?></h2><p><?= e($t('fm.activityHelp')) ?></p>
<?php if ($historyError!==null): ?><p class="notice error" role="alert"><?= e($historyError) ?></p><?php elseif ($events===[]): ?><p class="empty-state"><?= e($t('fm.noHistory')) ?></p><?php else: ?>
<div class="table-scroll"><table class="extension-table"><thead><tr><?php foreach (['historyDate','historyAction','historyScope'] as $label): ?><th scope="col"><?= e($t('fm.'.$label)) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach ($events as $event): ?><tr><td data-label="<?= e($t('fm.historyDate')) ?>"><time datetime="<?= e($event['at']) ?>"><?= e(str_replace(['T','+00:00'],[' ',' UTC'],$event['at'])) ?></time></td><td data-label="<?= e($t('fm.historyAction')) ?>"><?= e($t('fm.event.'.$event['event'])) ?></td><td data-label="<?= e($t('fm.historyScope')) ?>"><?= e($t('fm.'.($event['scope']==='unknown'?'unknownScope':$event['scope']))) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section>
<?= \DBLESSCMS\PluginOptions::history($context,'data-settings-form',true,$t('fm.snapshotDescription')) ?>
</div></section>
<?php if (!$context->integrated): ?></body></html><?php endif; ?>
