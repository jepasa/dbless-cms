<?php
/** Public document using only the allowlisted public context and local addressed assets. */
declare(strict_types=1);
use function CMS2JSON\e;
use function CMS2JSON\asset;
?>
<!doctype html><html lang="<?= e($context->language->locale()) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= e($context->language->text('hello.welcome')) ?></title><link rel="stylesheet" href="<?= e(asset('plugins/hello/css/hello.css',$context->base)) ?>"></head><body class="hello-public"><main><h1><?= e($context->language->text('hello.welcome')) ?></h1><p><?= e($context->settings['message']) ?></p></main></body></html>
