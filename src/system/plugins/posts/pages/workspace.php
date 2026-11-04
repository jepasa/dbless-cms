<?php
/** Unified Posts workspace fragments: manifest-owned screens and shared Core form/editor interactions, with all fixed administrative copy localized. */
declare(strict_types=1);
use DBLESSCMS\{AdminTable,Icons,Posts,PostTypes,PostsRouter,PageBlueprints,PageEditorDocument,PostsMarkup,JsonStore};
use function DBLESSCMS\e;
?>
<link rel="stylesheet" href="<?= e(\DBLESSCMS\asset('plugins/posts/css/page-editor.css',$context->base)) ?>">
<script defer src="<?= e(\DBLESSCMS\asset('plugins/posts/js/page-editor.js',$context->base)) ?>"></script>
<div class="posts-plugin pages-plugin" data-posts-plugin data-pages-plugin data-network-error="<?= e($t('network')) ?>"><div class="notice error" role="alert" data-pages-status hidden></div>
<?php if(in_array($route,['overview','trash'],true)):require __DIR__.'/overview.php';
elseif(in_array($route,['new','editor'],true)):require __DIR__.'/editor.php';
elseif(in_array($route,['groups','tags','authors'],true)):require __DIR__.'/terms.php';
elseif($route==='settings'):require __DIR__.'/settings.php';
elseif($route==='history'):require __DIR__.'/history.php';
elseif($route==='special'):
$key=$_GET['key']??'404';if(!is_string($key)||!in_array($key,['home','404'],true))throw new \DBLESSCMS\HttpError(422,'Reserved page selection.','error.reserved');
$registry=new \DBLESSCMS\Plugins($context->store);$legacyContext=$registry->context('pages',$context->base,$context->language->locale(),'editor',true); ?>
<nav class="tabs" aria-label="<?= e($t('specialPages')) ?>"><a class="button-secondary" href="<?= e($url('special',['key'=>'home'])) ?>">home</a><a class="button-secondary" href="<?= e($url('special',['key'=>'404'])) ?>">404</a></nav>
<?= $registry->instance('pages')->render($legacyContext) ?>
<?php
elseif($route==='comments'):
$registry=new \DBLESSCMS\Plugins($context->store); ?>
<p class="muted"><?= e($t('discussionHelp')) ?> <a href="<?= e($url('settings')) ?>"><?= e($t('commentSettings')) ?></a></p>
<link rel="stylesheet" href="<?= e(\DBLESSCMS\asset('plugins/posts/css/article.css',$context->base)) ?>"><script defer src="<?= e(\DBLESSCMS\asset('plugins/posts/js/article.js',$context->base)) ?>"></script>
<?= $registry->instance('blog')->render($context) ?>
<?php endif; ?>
</div>
