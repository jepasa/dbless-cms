<?php
/** Essencial cards for validated component data; plain values are escaped, URLs remain template-owned and SVG is never embedded. */
declare(strict_types=1);
use function DBLESSCMS\e;
?>
<div class="component-grid" data-content-component="<?= e($code) ?>">
<?php foreach($componentData['items'] as $item): ?><article class="component-card"><?php if($item['image']!==''): ?><img src="<?= e(preg_match('~^https?://~i',$item['image'])?$item['image']:$base_url.'/'.ltrim($item['image'],'/')) ?>" alt="<?= e($item['title']) ?>" loading="lazy" width="800" height="500"><?php endif; ?><div><h3><?php if($item['href']!==''): ?><a href="<?= e(str_starts_with($item['href'],'/')?$base_url.$item['href']:$item['href']) ?>"><?= e($item['title']) ?></a><?php else: ?><?= e($item['title']) ?><?php endif; ?></h3><p><?= e($item['text']) ?></p></div></article><?php endforeach; ?>
</div>
