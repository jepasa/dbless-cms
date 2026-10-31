<?php
/** Essencial semantic list for validated component records; href/text/image are data and never markup or template paths. */
declare(strict_types=1);
use function DBLESSCMS\e;
?>
<ul class="component-list" data-content-component="<?= e($code) ?>"><?php foreach($componentData['items'] as $item): ?><li><strong><?php if($item['href']!==''): ?><a href="<?= e(str_starts_with($item['href'],'/')?$base_url.$item['href']:$item['href']) ?>"><?= e($item['title']) ?></a><?php else: ?><?= e($item['title']) ?><?php endif; ?></strong><?php if($item['text']!==''): ?><p><?= e($item['text']) ?></p><?php endif; ?></li><?php endforeach; ?></ul>
