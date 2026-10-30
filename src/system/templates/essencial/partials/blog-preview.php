<?php
/** Essencial Blog demonstration: read-only visible examples and archive links, skipped when the extension is absent or disabled. */
declare(strict_types=1);
use function DBLESSCMS\e;
$blogRegistry=new \DBLESSCMS\Plugins($store);
if (!isset($blogRegistry->all()['blog'])||!$blogRegistry->state('blog')['enabled']) return;
$blogRegistry->instance('blog');$latest=\DBLESSCMS\BlogView::latest($store);
?>
<section class="blog blog-preview" id="journal"><div class="blog-container"><header class="blog-hero"><p class="blog-eyebrow">Blog in action</p><h2>Ideas made to be shared.</h2><p class="blog-lead">Discover structured articles, editorial authors and topic archives. Drafts, schedules and moderation remain under your control in the administration panel.</p><div class="blog-links"><a href="<?= e(\DBLESSCMS\BlogView::url($base_url)) ?>">Explore the journal ↗</a><a href="<?= e(\DBLESSCMS\BlogView::url($base_url,'feed')) ?>">RSS feed</a></div></header><div class="blog-grid">
<?php foreach ($latest as $entry): ?><article class="blog-card"><?php if ($entry['cover']!==''): ?><a class="blog-card-image" href="<?= e(\DBLESSCMS\BlogView::url($base_url,'article',['slug'=>$entry['slug']])) ?>" tabindex="-1" aria-hidden="true"><img src="<?= e(\DBLESSCMS\BlogView::media($base_url,$entry['cover'])) ?>" alt="" width="1280" height="720" loading="lazy"></a><?php endif; ?><div class="blog-card-content"><p class="blog-eyebrow"><?= e(substr($entry['publishedAt'],0,10)) ?></p><h3><a href="<?= e(\DBLESSCMS\BlogView::url($base_url,'article',['slug'=>$entry['slug']])) ?>"><?= e($entry['title']) ?></a></h3><p><?= e($entry['excerpt']) ?></p><a class="blog-read" href="<?= e(\DBLESSCMS\BlogView::url($base_url,'article',['slug'=>$entry['slug']])) ?>">Read article ↗</a></div></article><?php endforeach; ?>
</div></div></section>
