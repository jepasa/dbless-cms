<?php
/** Conteúdo opcional da home: seções e blocos com marcadores Slides controlados, saída escapada e fallback sem locais. */
declare(strict_types=1);
use function CMS2JSON\e;
$data=$page['content'];
?>
<?php if (($data['sections']??[])!==[]||($data['blocks']??[])!==[]): ?><section class="section"><div class="container text-flow">
<?php foreach ($data['sections']??[] as $section): ?><section><?php if ($section['title']!==''): ?><h2><?= e($section['title']) ?></h2><?php endif; ?>
<?php foreach ($section['body']??[] as $paragraph): $slideKey=\CMS2JSON\Slides::marker($paragraph);if ($slideKey===null): ?><p><?= e($paragraph) ?></p><?php else: $slideData=$slides->location($slideKey);if ($slideData!==null)require $store->templates->path($id,'partials',$manifest['partials']['slides']);endif;endforeach; ?>
<?php if (($section['list']??[])!==[]): ?><ul><?php foreach ($section['list'] as $entry): ?><li><?= e($entry) ?></li><?php endforeach; ?></ul><?php endif; ?></section><?php endforeach; ?>
<?php require $store->templates->path($id,'partials',$manifest['partials']['slide-blocks']); ?>
</div></section><?php endif; ?>
