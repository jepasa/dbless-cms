<?php
/** Breadcrumb Essencial com idioma do site e título editorial escapado. */
declare(strict_types=1);
use function DBLESSCMS\e;
?>
<nav class="container breadcrumbs" aria-label="<?= e('Caminho da página') ?>"><a href="<?= e($base_url.'/') ?>"><?= e('Início') ?></a><span aria-hidden="true">/</span><span aria-current="page"><?= e($page['meta']['title']) ?></span></nav>
