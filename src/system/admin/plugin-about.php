<?php
/** Private escaped plugin tutorial in its projected locale: escaped introduction, local contents links, ordered steps and inert code examples; never invokes plugin code. */
declare(strict_types=1);
use function DBLESSCMS\e;
?>
<article class="panel plugin-presentation text-flow" lang="<?= e($presentation['language']) ?>" dir="<?= e($presentationDirection) ?>" data-plugin-presentation>
<?php if($presentationWarning!==null): ?><p class="notice" role="status" data-presentation-fallback lang="<?= e($language->locale()) ?>" dir="<?= e($language->direction()) ?>"><?= e($t('catalog.presentation_fallback_'.$presentationWarning,['locale'=>$language->locale()])) ?></p><?php endif; ?>
<h2><?= e($presentation['title']) ?></h2><p class="lead"><?= e($presentation['lead']) ?></p>
<nav aria-label="<?= e($t('catalog.presentation_contents')) ?>" lang="<?= e($language->locale()) ?>" dir="<?= e($language->direction()) ?>">
<strong><?= e($t('catalog.presentation_contents')) ?></strong>
<ol><?php foreach($presentation['sections'] as $index=>$section): ?><li><a href="#presentation-section-<?= e($section['id']??(string)$index) ?>" lang="<?= e($presentation['language']) ?>" dir="<?= e($presentationDirection) ?>"><?= e($section['title']) ?></a></li><?php endforeach; ?></ol>
</nav>
<?php foreach($presentation['sections'] as $index=>$section): ?>
<section id="presentation-section-<?= e($section['id']??(string)$index) ?>" aria-labelledby="presentation-heading-<?= e($section['id']??(string)$index) ?>">
<h3 id="presentation-heading-<?= e($section['id']??(string)$index) ?>"><?= e($section['title']) ?></h3>
<?php foreach($section['body'] as $paragraph): ?><p><?= e($paragraph) ?></p><?php endforeach; ?>
<ol><?php foreach($section['steps'] as $step): ?><li><?= e($step) ?></li><?php endforeach; ?></ol>
<?php if(isset($section['code'])): ?><pre><code><?= e($section['code']) ?></code></pre><?php endif; ?>
</section><?php endforeach; ?>
</article>
