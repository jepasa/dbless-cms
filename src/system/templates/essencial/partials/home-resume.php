<?php
/** Essencial native resume section; consumes validated Homepage data and keeps the template presentation contract. */
declare(strict_types=1);
use function DBLESSCMS\e;
?>
<section id="resume" class="section section-tint"><div class="container resume-grid"><div class="section-heading text-flow"><p class="eyebrow"><?= e($data['headings']['resume']['kicker']) ?></p><h2><?= e($data['headings']['resume']['title']) ?></h2><p class="section-lead"><?= e($data['headings']['resume']['description']) ?></p></div><div class="timeline"><?php foreach ($data['resume'] as $item): ?><article class="timeline-item text-flow"><h3><?= e($item['title']) ?></h3><p><?= e($item['description']) ?></p></article><?php endforeach; ?></div></div></section>
