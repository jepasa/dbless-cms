<?php
/** Essencial native services section; consumes validated Homepage data and keeps the template presentation contract. */
declare(strict_types=1);
use function DBLESSCMS\e;
?>
<section id="services" class="section section-tint"><div class="container"><div class="section-heading text-flow"><p class="eyebrow"><?= e($data['headings']['services']['kicker']) ?></p><h2><?= e($data['headings']['services']['title']) ?></h2><p class="section-lead"><?= e($data['headings']['services']['description']) ?></p></div><div class="service-grid">
<?php foreach ($data['services'] as $index=>$item): ?><article class="service-card text-flow"><span class="service-number" aria-hidden="true"><?= e(str_pad((string)($index+1),2,'0',STR_PAD_LEFT)) ?></span><h3><?= e($item['title']) ?></h3><p><?= e($item['description']) ?></p><a class="text-link" href="<?= e($pageUrl($item['href'])) ?>"><?= e('Conhecer serviço') ?> <span aria-hidden="true">↗</span></a></article><?php endforeach; ?></div></div></section>
