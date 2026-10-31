<?php
/** Essencial native portfolio section; consumes validated Homepage data and keeps the template presentation contract. */
declare(strict_types=1);
use function DBLESSCMS\e;
?>
<section id="portfolio" class="section"><div class="container"><div class="section-heading text-flow"><p class="eyebrow"><?= e($data['headings']['portfolio']['kicker']) ?></p><h2><?= e($data['headings']['portfolio']['title']) ?></h2><p class="section-lead"><?= e($data['headings']['portfolio']['description']) ?></p></div><div class="portfolio-grid">
<?php foreach ($data['portfolio'] as $item): ?><article class="project-card"><a class="project-image" href="<?= e($pageUrl($item['href'])) ?>" aria-label="<?= e($item['title']) ?>"><?php if ($item['image']!==''): ?><img src="<?= e($mediaUrl($item['image'])) ?>" alt="<?= e($item['title']) ?>" width="800" height="600" loading="lazy"><?php else: ?><div class="image-fallback" aria-hidden="true"></div><?php endif; ?></a><div class="project-copy text-flow"><p class="eyebrow"><?= e($item['badge']) ?></p><h3><a href="<?= e($pageUrl($item['href'])) ?>"><?= e($item['title']) ?></a></h3><p><?= e($item['description']) ?></p><a class="text-link" href="<?= e($pageUrl($item['href'])) ?>"><?= e('Ver projeto') ?> <span aria-hidden="true">↗</span></a></div></article><?php endforeach; ?></div></div></section>
