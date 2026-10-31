<?php
/** Essencial native hero section; consumes validated Homepage data and keeps the template presentation contract. */
declare(strict_types=1);
use function DBLESSCMS\e;
?>
<section id="hero" class="hero section"><div class="container hero-grid"><div class="hero-copy text-flow">
  <p class="eyebrow"><span class="accent-line" aria-hidden="true"></span><?= e($siteContent['brand']['role']) ?></p>
  <h1><?= e($siteContent['brand']['name']) ?></h1><p class="lead"><?= e($data['intro']) ?></p>
  <?php if ($data['typed']!==[]): ?><ul class="tag-list"><?php foreach ($data['typed'] as $tag): ?><li><?= e($tag) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <div class="hero-actions"><a class="button" href="#portfolio"><?= e('Conhecer projetos') ?><span aria-hidden="true">↗</span></a><a class="button button-outline" href="#contact"><?= e('Entrar em contato') ?></a></div>
</div><div class="hero-visual"><?php if ($siteContent['media']['hero']!==''): ?><img class="hero-image" src="<?= e($mediaUrl($siteContent['media']['hero'])) ?>" alt="<?= e($data['feature_alt']) ?>" width="960" height="1000" fetchpriority="high"><?php else: ?><div class="hero-fallback" aria-hidden="true"></div><?php endif; ?><div class="hero-caption"><span class="caption-rule" aria-hidden="true"></span><p><?= e($siteContent['brand']['tagline']) ?></p></div></div></div></section>
