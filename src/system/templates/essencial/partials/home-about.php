<?php
/** Essencial native about section; consumes validated Homepage data and keeps the template presentation contract. */
declare(strict_types=1);
use function DBLESSCMS\e;
?>
<section id="about" class="section"><div class="container about-grid"><div class="about-visual"><?php if ($siteContent['media']['profile']!==''): ?><img src="<?= e($mediaUrl($siteContent['media']['profile'])) ?>" alt="<?= e($siteContent['brand']['name']) ?>" width="620" height="720" loading="lazy"><?php else: ?><div class="profile-fallback" aria-hidden="true"></div><?php endif; ?></div><div class="text-flow"><p class="eyebrow"><?= e($data['about']['eyebrow']) ?></p><h2><?= e($data['about']['title']) ?></h2><p class="lead"><?= e($data['about']['lead']) ?></p><ul class="check-list"><?php foreach ($data['about']['points'] as $point): ?><li><span aria-hidden="true">✓</span><?= e($point) ?></li><?php endforeach; ?></ul></div></div>
<?php if ($data['stats']!==[]): ?><dl class="container metrics"><?php foreach ($data['stats'] as $stat): ?><div><dt><?= e($stat['label']) ?></dt><dd><?= e($stat['value']) ?></dd></div><?php endforeach; ?></dl><?php endif; ?></section>
