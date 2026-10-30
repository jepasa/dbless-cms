<?php
/** Navegação Essencial: marca configurada, âncoras reais e disclosure móvel nativo, sem misturar o tema administrativo. */
declare(strict_types=1);
use function DBLESSCMS\e;
$links=['about'=>'Sobre','resume'=>'Trajetória','portfolio'=>'Projetos','services'=>'Serviços','contact'=>'Contato'];
$menuData=$navigation->location('header-primary');
?>
<header class="site-header">
  <div class="container header-inner">
    <a class="brand" href="<?= e($base_url.'/') ?>"><img class="brand-symbol" src="<?= e($store->templates->asset($id,$manifest['assets']['files']['brandSymbol'],$base_url)) ?>" alt="" aria-hidden="true" width="32" height="32"><span><?= e($siteContent['brand']['name']) ?></span></a>
    <nav id="navmenu" class="desktop-nav" aria-label="<?= e('Navegação principal') ?>"><?php if ($menuData!==null): require $store->templates->path($id,'partials',$manifest['partials']['menu']);else:foreach ($links as $anchor=>$label): ?><a href="<?= e($base_url.'/#'.$anchor) ?>"><?= e($label) ?></a><?php endforeach;endif; ?></nav>
    <details class="mobile-menu" data-site-menu><summary aria-label="<?= e('Abrir navegação') ?>" title="<?= e('Abrir navegação') ?>"><?= \DBLESSCMS\Icons::site('list',$base_url,'site-menu-icon',$id) ?></summary><nav aria-label="<?= e('Navegação principal') ?>"><?php if ($menuData!==null): require $store->templates->path($id,'partials',$manifest['partials']['menu']);else:foreach ($links as $anchor=>$label): ?><a href="<?= e($base_url.'/#'.$anchor) ?>"><?= e($label) ?></a><?php endforeach;endif; ?></nav></details>
  </div>
</header>
