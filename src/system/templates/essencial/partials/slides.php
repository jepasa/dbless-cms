<?php
/** Componente público Essencial: HTML acessível de dados Slides, com controles progressivos e identidade do tema. */
declare(strict_types=1);
use function CMS2JSON\e;
$slideInstance=($slideInstance??0)+1;
$componentId='slides-'.$slideData['location'].'-'.$slideInstance;
?>
<section class="slides-component slides-<?= e($slideData['type']) ?> slides-content-<?= e($slideData['contentType']) ?>" data-slides-component data-location="<?= e($slideData['location']) ?>" data-mode="<?= e($slideData['type']) ?>" data-autoplay="<?= $slideData['autoplay']?'true':'false' ?>" data-interval="<?= e($slideData['interval']) ?>" role="region" aria-roledescription="carrossel" aria-labelledby="<?= e($componentId.'-title') ?>" tabindex="0">
  <div class="slides-heading"><h2 id="<?= e($componentId.'-title') ?>"><?= e($slideData['name']) ?></h2><div class="slides-controls" data-slides-controls hidden><button type="button" data-slide-prev aria-label="Anterior" aria-controls="<?= e($componentId.'-track') ?>"><span aria-hidden="true">←</span></button><button type="button" data-slide-next aria-label="Próximo" aria-controls="<?= e($componentId.'-track') ?>"><span aria-hidden="true">→</span></button><button type="button" data-slide-pause hidden>Pausar reprodução</button><span class="slides-status" role="status" aria-live="polite" dir="auto" data-slide-status></span></div></div>
  <ul class="slide-track" id="<?= e($componentId.'-track') ?>">
    <?php foreach($slideData['items'] as $index=>$item): ?><li data-slide-item><article class="slide-card">
      <?php if($item['image']!==''): ?><img src="<?= e($mediaUrl($item['image'])) ?>" alt="<?= e($item['alt']) ?>" width="1200" height="800" loading="lazy" decoding="async"><?php elseif($slideData['contentType']==='logo'): ?><span class="slide-logo-symbol" aria-hidden="true">◇</span><?php endif; ?>
      <div class="slide-copy"><h3><?= e($item['title']) ?></h3><?php if($item['text']!==''): ?><p><?= e($item['text']) ?></p><?php endif; ?><?php if($item['attribution']!==''): ?><p class="slide-attribution"><?= e($item['attribution']) ?></p><?php endif; ?><?php if($item['price']!==''): ?><p class="slide-price"><?= e($item['price']) ?></p><?php endif; ?><?php if($item['href']!==''): ?><a class="text-link" href="<?= e($pageUrl($item['href'])) ?>"><?= e($item['label']!==''?$item['label']:$item['title']) ?><span aria-hidden="true">↗</span></a><?php endif; ?></div>
    </article></li><?php endforeach; ?>
  </ul>
</section>
