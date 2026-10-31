<?php
/** Private plugin-owned English presentation; every document value is escaped and no plugin code is invoked. */
declare(strict_types=1);
use function DBLESSCMS\e;
?>
<article class="panel plugin-presentation text-flow" lang="en" dir="ltr" data-plugin-presentation>
<h2><?= e($presentation['title']) ?></h2><p class="lead"><?= e($presentation['lead']) ?></p>
<?php foreach($presentation['sections'] as $section): ?><section><h3><?= e($section['title']) ?></h3><?php foreach($section['body'] as $paragraph): ?><p><?= e($paragraph) ?></p><?php endforeach; ?><?php if(isset($section['code'])): ?><pre><code><?= e($section['code']) ?></code></pre><?php endif; ?></section><?php endforeach; ?>
</article>
