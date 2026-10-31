<?php
/** Páginas administrativas do plugin Pages: conteúdo, edição, criação, lixeira e histórico, sem moldura própria. */
declare(strict_types=1);
use DBLESSCMS\Security;
use function DBLESSCMS\e;
/** Traduz texto administrativo do plugin; recebe chave/parâmetros e retorna texto para escape. */
$t=static fn(string $key,array $parameters=[]): string=>$context->language->text($key,$parameters);
/** Resolve rota própria privada; recebe rota/chave e retorna URL local sem escrita. */
$link=static fn(string $target,string $key=''): string=>$context->base.'/cms-admin/?view='.($target==='preview'?'plugin':'plugin-panel').'&plugin=pages&route='.$target.($key!==''?'&key='.rawurlencode($key):'');
$csrf=Security::token();
?>
<div class="pages-plugin" data-pages-plugin data-network-error="<?= e($t('pages.network')) ?>"><div class="notice error" data-pages-status role="alert" hidden></div>
<?php if ($route==='overview'): require __DIR__.'/overview.php'; ?>
<?php elseif ($route==='new'): ?>
<section class="panel"><form method="post" action="<?= e($context->url('create')) ?>" data-pages-form class="create-form"><input type="hidden" name="action" value="create"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><label><?= e($t('pages.slug')) ?><input name="slug" pattern="[a-z0-9][a-z0-9\-]{0,63}" maxlength="64" required></label><label><?= e($t('pages.model')) ?><select name="model"><?php foreach ($pages as $slug=>$item): if ((string)$slug==='404') continue; ?><option value="<?= e($slug) ?>"><?= e($item['data']['meta']['title']) ?></option><?php endforeach; ?></select></label><button class="button-primary" type="submit"><?= e($t('pages.create')) ?></button></form></section>
<?php elseif ($route==='trash'): ?>
<section class="panel"><h2><?= e($t('pages.removed')) ?></h2>
<?php if ($removed===[]): ?><p class="empty-state"><?= e($t('pages.trash_empty')) ?></p><?php endif; ?>
<?php foreach ($removed as $slug): ?><div class="history-row"><span><?= e($slug) ?></span><a class="button-secondary" href="<?= e($link('history',(string)$slug)) ?>"><?= e($t('pages.recover')) ?></a></div><?php endforeach; ?></section>
<?php elseif ($route==='editor'): ?>
<?php if(!in_array($key,['home','404','site','plugins','templates'],true)):
$markupPage=$context->store->read($key)['data'];$markupDefinition=$markupPage['markup']??['html'=>'','bindings'=>[]];$markupCoreLanguage=new \DBLESSCMS\Language();$markupCoreLanguage->select($context->language->locale()); ?>
<details class="panel" data-page-markup-panel><summary><?= e($markupCoreLanguage->text('markup.content')) ?></summary><form method="post" action="<?= e($context->url('markup-save')) ?>" data-pages-form data-page-markup-form><input type="hidden" name="key" value="<?= e($key) ?>"><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="site_revision" value="<?= e($context->store->read('site')['revision']) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><label class="editor-checkbox"><input type="checkbox" name="markup_enabled" value="1" <?= isset($markupPage['markup'])?'checked':'' ?>><?= e($markupCoreLanguage->text('markup.pageEnable')) ?></label><p class="muted"><?= e($markupCoreLanguage->text('markup.native')) ?></p><?php require $context->config->root.'/admin/markup-editor.php'; ?><div class="savebar"><button class="button-primary" type="submit"><?= e($markupCoreLanguage->text('markup.pageSave')) ?></button></div></form></details>
<?php endif; ?>
<?php $slideManifest=$context->store->templates->get($context->store->read('site')['data']['template']);$slideLocations=\DBLESSCMS\Slides::locations($slideManifest);if(!in_array($key,['404','site'],true)&&$slideLocations!==[]): ?>
<section class="panel" data-page-slides><div class="field-grid"><label><?= e($t('pages.slidesLocation')) ?><select data-page-slide-location><?php foreach($slideLocations as $slideKey=>$slideLocation): ?><option value="<?= e($slideKey) ?>"><?= e($slideLocation['description'].' · '.$slideKey) ?></option><?php endforeach; ?></select></label><label><?= e($t('pages.slidesMethod')) ?><select data-page-slide-method><option value="block"><?= e($t('pages.slidesBlock')) ?></option><option value="marker"><?= e($t('pages.slidesMarker')) ?></option></select></label></div><p class="muted"><?= e($t('pages.slidesHelp')) ?></p><button type="button" class="button-secondary" data-insert-page-slide><?= e($t('pages.slidesInsert')) ?></button></section>
<?php endif; ?>
<?php $components=new \DBLESSCMS\Components(new \DBLESSCMS\ContentContext($context->store,$effectiveTemplate,$context->base,$key));require $context->config->root.'/admin/components-picker.php'; ?>
<div class="editor-toolbar"><div class="tabs" aria-label="<?= e($t('editor.mode')) ?>"><button type="button" data-mode="fields" aria-pressed="true"><?= e($t('editor.fields')) ?></button><button type="button" data-mode="json" aria-pressed="false"><?= e($t('editor.json')) ?></button></div><div><a class="text-link" href="<?= e($link('history',$key)) ?>"><?= e($t('nav.history')) ?></a><?php if ($key!=='site'): ?> · <a class="text-link" href="<?= e($link('preview',$key)) ?>" target="_blank" rel="noopener"><?= e($t('editor.preview')) ?> ↗</a><?php endif; ?></div></div>
<form method="post" action="<?= e($context->url('save')) ?>" data-pages-form class="document-form"><input type="hidden" name="action" value="save"><input type="hidden" name="key" value="<?= e($key) ?>"><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
<div class="notice error" data-editor-error role="alert" hidden></div><section class="panel editor-panel"><div data-fields hidden></div><label data-json-label><?= e($t('editor.document')) ?><textarea class="json-editor" name="document" data-document spellcheck="false" rows="28" required><?= e($document) ?></textarea></label></section>
<div class="savebar"><span class="muted"><?= e($t('editor.revision', ['key'=>$key,'revision'=>substr($revision,0,10)])) ?></span><button class="button-primary" type="submit"><?= e($t('editor.save')) ?></button></div></form>
<?php if (!in_array($key,['404','site'],true)): ?><form method="post" action="<?= e($context->url('remove')) ?>" data-pages-form class="delete-form" data-confirm="<?= e($t('editor.confirm_remove')) ?>"><input type="hidden" name="action" value="remove"><input type="hidden" name="key" value="<?= e($key) ?>"><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><button class="button-danger" type="submit"><?= e($t('editor.remove')) ?></button></form><?php endif; ?>
<?php elseif ($route==='history'&&!isset($_GET['key'])): ?>
<section class="panel"><h2><?= e($t('pages.contentHistory')) ?></h2><div class="stack"><?php foreach ($historyPages as $historyKey): ?><a class="text-link" href="<?= e($link('history',$historyKey)) ?>"><?= e($historyKey) ?></a><?php endforeach; ?><?php if ($historyPages===[]): ?><p class="empty-state"><?= e($t('editor.no_history')) ?></p><?php endif; ?></div></section>
<?php elseif ($route==='history'): ?>
<?php if (!$historyEnabled): ?><p class="notice" role="status"><?= e($t('pages.historyInactive')) ?></p><?php endif; ?>
<section class="panel"><h2><?= e($t('editor.history_title', ['key'=>$key])) ?></h2><?php if ($revision===''): ?><p class="notice"><?= e($t('editor.deleted')) ?></p><?php endif; ?>
<?php if ($history===[]): ?><p class="empty-state"><?= e($t('editor.no_history')) ?></p><?php endif; ?>
<?php foreach ($history as $backup): ?><form method="post" action="<?= e($context->url('restore')) ?>" data-pages-form class="history-row" data-confirm="<?= e($t('editor.confirm_restore')) ?>"><span><?= e(substr($backup,0,15)) ?></span><input type="hidden" name="action" value="restore"><input type="hidden" name="key" value="<?= e($key) ?>"><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="backup" value="<?= e($backup) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><button type="submit" class="button-secondary" <?= !$historyEnabled?'disabled':'' ?>><?= e($t('editor.restore')) ?></button></form><?php endforeach; ?></section>
<?php endif; ?>
</div>
