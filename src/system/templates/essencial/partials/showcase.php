<?php
/** Essencial é o demonstrador oficial: conteúdo publicado dos plugins em composições locais, sem operações administrativas públicas. */
declare(strict_types=1);
use function CMS2JSON\e;
?>
<section id="recursos" class="section section-tint"><div class="container"><div class="section-heading text-flow"><p class="eyebrow">Explore o CMS2JSON</p><h2>Recursos em ação.</h2><p class="section-lead">Este template apresenta exemplos editáveis de páginas, menus, arquivos e slides. Conheça as possibilidades e adapte o conteúdo à sua organização.</p><a class="text-link" href="<?= e($base_url.'/recursos') ?>">Conhecer todos os exemplos <span aria-hidden="true">↗</span></a></div>
<?php foreach(['home-announcements','home-hero','home-products','home-testimonials','home-logos'] as $slideKey): $slideData=$slides->location($slideKey);if($slideData!==null)require $store->templates->path($id,'partials',$manifest['partials']['slides']);endforeach; ?>
</div></section>
