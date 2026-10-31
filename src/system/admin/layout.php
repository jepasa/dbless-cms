<?php
/** Moldura administrativa responsiva com copyright no sidebar; plugins fornecem fragmentos, login mínimo e saída escapada. */
declare(strict_types=1);
use function DBLESSCMS\e;
use function DBLESSCMS\asset;
use DBLESSCMS\Icons;
/** Traduz chave e parâmetros do layout; retorna texto para escape contextual, sem efeitos externos. */
$t = static fn(string $key, array $parameters = []): string => $language->text($key, $parameters);
$labels=['site'=>$t('nav.site'),'templates'=>$t('nav.templates'),'history'=>$t('nav.history'),'login'=>$t('nav.login'),'plugins'=>$t('nav.plugins'),'plugin-settings'=>$t('catalog.plugin_settings'),'template-settings'=>$t('catalog.template_settings'),'plugin-panel'=>$panelLanguage?->text($panelRoute['title'])??$t('nav.plugins')];
?>
<!doctype html><html lang="<?= e($language->locale()) ?>" dir="<?= e($language->direction()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><title><?= e($labels[$view]) ?> · dbless-cms</title>
<link rel="icon" type="image/x-icon" href="<?= e(asset('cms-admin/assets/favicon.ico',$base)) ?>">
<link rel="stylesheet" href="<?= e(asset('cms-admin/assets/admin.css',$base)) ?>">
<script src="<?= e(asset('cms-admin/assets/admin.js',$base)) ?>" defer></script>
<?php if ($panelAssets!==null): ?>
<link rel="stylesheet" href="<?= e(asset('plugins/'.$panelId.'/'.$panelAssets['css'],$base)) ?>">
<script src="<?= e(asset('plugins/'.$panelId.'/'.$panelAssets['js'],$base)) ?>" defer></script>
<?php endif; ?></head>
<body data-admin-language="<?= e(json_encode($language->clientMessages(), JSON_THROW_ON_ERROR)) ?>" data-languages="<?= e(json_encode(array_map(static fn(array $item): string => $item['name'], $language->all()), JSON_THROW_ON_ERROR)) ?>" data-template-options="<?= e(json_encode(array_map(static fn(array $item): string => $item['name'], $templates), JSON_THROW_ON_ERROR)) ?>" data-locale="<?= e($language->locale()) ?>" class="cms-admin<?= !$authenticated?' auth-screen':'' ?>">
<a class="skip-link" href="#workspace"><?= e($t('common.skip')) ?></a>
<?php if ($authenticated): ?>
<button type="button" class="nav-backdrop" data-nav-backdrop tabindex="-1" aria-label="<?= e($t('nav.close')) ?>" hidden></button>
<aside id="admin-navigation" class="sidebar" aria-label="<?= e($t('nav.label')) ?>">
<a class="brand" href="<?= e($base.'/cms-admin/') ?>"><img class="brand-logo" src="<?= e(asset('cms-admin/assets/brand/dbless-cms-inverse.svg',$base)) ?>" width="444" height="96" alt="DBLess-CMS"><small><?= e($t('common.studio')) ?></small></a>
<button type="button" class="nav-close button-quiet" data-nav-close aria-label="<?= e($t('nav.close')) ?>">×</button>
<p class="nav-caption"><?= e($t('nav.workspace')) ?></p>
<nav>
<?php foreach ($pluginMenus as $menuId=>$menu): ?>
<?php if ($menu['dropdown']): ?>
<details class="sidebar-dropdown" data-plugin-menu="<?= e($menuId) ?>" <?= $view==='plugin-panel'&&$panelId===$menuId?'open':'' ?>><summary><img class="admin-icon plugin-icon" src="<?= e($plugins->icon($menuId,$base)) ?>" width="24" height="24" alt="" aria-hidden="true"><?= e($menu['label']) ?><span class="dropdown-chevron" aria-hidden="true">⌄</span></summary><div class="sidebar-submenu">
<?php endif; ?>
<?php foreach ($menu['items'] as $item): if (isset($item['separator'])): ?><hr><?php else: ?>
<a href="?view=plugin-panel&amp;plugin=<?= e($menuId) ?>&amp;route=<?= e($item['route']) ?>" <?= $view==='plugin-panel'&&$panelId===$menuId&&$panelRoute['id']===$item['route']?'aria-current="page"':'' ?>><?php if (!$menu['dropdown']): ?><img class="admin-icon plugin-icon" src="<?= e($plugins->icon($menuId,$base)) ?>" width="24" height="24" alt="" aria-hidden="true"><?php endif; ?><?= e($item['label']) ?></a>
<?php endif; endforeach; ?>
<?php if ($menu['dropdown']): ?></div></details><?php endif; ?>
<?php endforeach; ?></nav>
<div class="sidebar-footer"><small>Copyright © JEPASA</small></div>
</aside>
<div class="workspace"><header class="topbar"><div class="topbar-location"><button class="nav-toggle button-secondary" type="button" data-nav-toggle aria-controls="admin-navigation" aria-expanded="false" aria-label="<?= e($t('nav.open')) ?>"><?= Icons::svg('list',$base) ?></button><span><?= e($t('nav.admin')) ?> / <?= e($labels[$view]) ?></span></div><div class="top-actions"><?php foreach ($plugins->all() as $pluginId=>$pluginManifest): if (($pluginManifest['launcher']??'')!=='files' || !$plugins->state($pluginId)['enabled']) continue; ?>
<a class="button-secondary files-shortcut" href="<?= e($base.'/cms-admin/?view=plugin-panel&plugin='.rawurlencode($pluginId)) ?>"><span aria-hidden="true">▣</span> <?= e($t('plugins.files')) ?></a>
<?php break; endforeach; ?><a class="site-shortcut" href="<?= e($base.'/') ?>" target="_blank" rel="noopener" aria-label="<?= e($t('common.open_site')) ?>"><span><?= e($t('common.open_site')) ?></span><?= Icons::svg('external',$base) ?></a><details class="user-menu" data-user-menu><summary title="<?= e($t('nav.account')) ?>" aria-label="<?= e($t('nav.account')) ?>" aria-expanded="false"><?= Icons::svg('person-fill',$base) ?></summary>
<nav class="user-menu-items" aria-label="<?= e($t('nav.account')) ?>">
<a href="?view=site"><?= Icons::svg('configure',$base) ?><?= e($t('nav.settings')) ?></a>
<a href="?view=plugins"><?= Icons::svg('activate',$base) ?><?= e($t('nav.plugins')) ?></a>
<a href="?view=templates"><?= Icons::svg('configure',$base) ?><?= e($t('nav.templates')) ?></a><hr>
<form method="post"><input type="hidden" name="action" value="logout"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><button type="submit"><?= Icons::svg('logout',$base) ?><?= e($t('common.logout')) ?></button></form>
</nav></details></div></header>
<?php endif; ?>
<main id="workspace" tabindex="-1" class="<?= $authenticated?'main-workspace'.($view==='plugin-panel'?' plugin-workspace':''):'auth-card' ?>">
<?php if ($view==='login'): ?>
<div class="auth-avatar" aria-hidden="true">
  <?= Icons::svg('person-circle',$base,'icon-person-circle') ?>
</div>
<?php endif; ?>
<?php if ($error!==null): ?><div class="notice error" role="alert"><?= e($error) ?></div><?php endif; ?>
<?php if (is_string($notice) && $view!=='login'): ?><div class="notice success" role="status"><?= e($t($notice)) ?></div><?php endif; ?>
<?php if ($view==='login'): ?>
<?php if (!$configured): ?><div class="notice error" role="alert"><?= e($t('login.unconfigured')) ?></div><?php else: ?>
<form method="post" class="stack"><input type="hidden" name="action" value="login"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
<label><?= e($t('login.username')) ?><input name="username" autocomplete="username" maxlength="100" required autofocus></label>
<label><?= e($t('login.password')) ?><input name="password" type="password" autocomplete="current-password" minlength="16" maxlength="200" required></label>
<button type="submit" class="button-primary auth-submit" aria-label="<?= e($t('login.submit')) ?>">
  <?= Icons::svg('login',$base) ?>
</button></form>
<?php endif; ?>
<?php else: ?>
<div class="page-heading"><div><p class="eyebrow"><?= e($t('common.studio')) ?></p><h1><?= e($labels[$view]) ?></h1><p class="muted"><?php if ($view==='plugin-panel'): ?><?= isset($panelRoute['description'])?e($panelLanguage->text($panelRoute['description'])):'' ?><?php elseif ($view==='plugins'): ?><?= e($t('catalog.plugins_help')) ?><?php elseif ($view==='template-settings'): ?><?= e($this->store->templates->labels($themeId,$language->locale())['name']) ?><?php elseif ($view==='templates'): ?><?= e($t('heading.templates')) ?><?php elseif ($view==='history'): ?><?= e($t('heading.history')) ?><?php else: ?><?= e($t('heading.edit')) ?><?php endif; ?></p></div><span class="version-tag">v2.2.0</span></div>
<?php if ($view==='site'): ?>
<div class="editor-toolbar"><div class="tabs" aria-label="<?= e($t('editor.mode')) ?>"><button type="button" data-mode="fields" aria-pressed="true"><?= e($t('editor.fields')) ?></button><button type="button" data-mode="json" aria-pressed="false"><?= e($t('editor.json')) ?></button></div><div><a class="text-link" href="?view=history&amp;key=<?= e($key) ?>"><?= e($t('nav.history')) ?></a></div></div>
<form method="post" class="document-form"><input type="hidden" name="action" value="save"><input type="hidden" name="key" value="<?= e($key) ?>"><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
<?php if(!isset($templates[$templateChoice])): ?><p class="notice error" role="alert"><?= e($t('global.template_unavailable')) ?></p><?php endif; ?>
<?php if($language->fallback): ?><p class="notice" role="status"><?= e($t('global.fallback')) ?></p><?php endif; ?>
<?php if($language->invalid()!==[]): ?><p class="notice error" role="alert"><?= e($t('global.invalid',['files'=>implode(', ',$language->invalid())])) ?></p><?php endif; ?>
<div class="notice error" data-editor-error role="alert" hidden></div><section class="editor-panel global-editor"><div data-fields hidden></div><label data-json-label><?= e($t('editor.document')) ?><textarea class="json-editor" name="document" data-document data-global-groups="<?= e(json_encode(\DBLESSCMS\GlobalSettings::groups($this->store,$language),JSON_THROW_ON_ERROR)) ?>" data-global-template="<?= e($templates[$templateChoice]['name']??$templateChoice) ?>" data-global-theme="<?= e(isset($templates[$templateChoice])?\DBLESSCMS\GlobalSettings::theme($this->store,$templateChoice):'') ?>" spellcheck="false" rows="28" required><?= e($document) ?></textarea></label></section>
<div class="savebar"><span class="muted"><?= e($t('editor.revision', ['key'=>$key,'revision'=>substr($revision,0,10)])) ?></span><button class="button-primary" type="submit"><?= e($t('editor.save')) ?></button></div></form>
<?php elseif (in_array($view,['plugins','templates'],true)): require __DIR__.'/extensions.php'; ?>
<?php elseif ($view==='template-settings'): require __DIR__.'/template-settings.php'; ?>
<?php elseif ($view==='plugin-panel'): ?>
<section class="admin-plugin plugin-content" data-admin-plugin="<?= e($panelId) ?>" aria-label="<?= e($labels[$view]) ?>"><?= $panelContent ?></section>
<?php elseif ($view==='plugin-settings'): require __DIR__.'/plugins.php'; ?>
<?php elseif ($view==='history'): ?>
<section class="panel"><h2><?= e($t('editor.history_title', ['key'=>$key])) ?></h2><?php if ($revision===''): ?><p class="notice"><?= e($t('editor.deleted')) ?></p><?php endif; ?>
<?php if ($history===[]): ?><p class="empty-state"><?= e($t('editor.no_history')) ?></p><?php endif; ?>
<?php foreach ($history as $backup): ?><form method="post" class="history-row" data-confirm="<?= e($t('editor.confirm_restore')) ?>"><span><?= e(substr($backup,0,15)) ?></span><input type="hidden" name="action" value="restore"><input type="hidden" name="key" value="<?= e($key) ?>"><input type="hidden" name="revision" value="<?= e($revision) ?>"><input type="hidden" name="backup" value="<?= e($backup) ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><button type="submit" class="button-secondary"><?= e($t('editor.restore')) ?></button></form><?php endforeach; ?></section>
<?php endif; ?>
<?php endif; ?>
</main><?php if ($authenticated): ?></div><?php endif; ?></body></html>
