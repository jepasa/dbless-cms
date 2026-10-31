<?php
/** Core dashboard fragment; escapes state and measured indicators, provides authenticated POST/CSRF cache purge without a separate cache route. */
declare(strict_types=1);
use function DBLESSCMS\e;
$cache=$dashboard['cache'];$metrics=$cache['metrics'];
?>
<div class="dashboard-grid" data-dashboard>
<?php foreach (['published','drafts','plugins','template'] as $indicator): ?>
<section class="panel dashboard-stat"><h2><?= e($t('dashboard.'.$indicator)) ?></h2><p class="dashboard-value"><?= e($dashboard[$indicator]) ?></p></section>
<?php endforeach; ?>
<section class="panel dashboard-cache"><div class="dashboard-card-heading"><h2><?= e($t('global.card.cache')) ?></h2><span class="status-pill"><?= e($t('cache.'.$cache['status'])) ?></span></div>
<p class="muted"><?= e($t('cache.help')) ?></p>
<dl class="dashboard-metrics">
<dt><?= e($t('cache.entries')) ?></dt><dd><?= $cache['status']==='unavailable'?e($t('dashboard.no_data')):e($cache['entries']) ?></dd>
<dt><?= e($t('cache.expired')) ?></dt><dd><?= $cache['status']==='unavailable'?e($t('dashboard.no_data')):e($cache['expired']) ?></dd>
<dt><?= e($t('cache.storage')) ?></dt><dd><?= $cache['status']==='unavailable'?e($t('dashboard.no_data')):e(round($cache['bytes']/1024,1).' KiB / '.$cache['options']['maxMiB'].' MiB') ?></dd>
<?php foreach (['hits','misses'] as $counter): ?><dt><?= e($t('cache.'.$counter)) ?></dt><dd><?= $metrics===null?e($t('dashboard.no_data')):e($metrics[$counter]) ?></dd><?php endforeach; ?>
<dt><?= e($t('cache.ratio')) ?></dt><dd><?= $metrics===null||$metrics['hits']+$metrics['misses']===0?e($t('dashboard.no_data')):e(round(100*$metrics['hits']/($metrics['hits']+$metrics['misses']),1).'%') ?></dd>
<dt><?= e($t('cache.period')) ?></dt><dd><?= $metrics===null?e($t('dashboard.no_data')):e(gmdate('Y-m-d H:i:s',$metrics['since']).' UTC') ?></dd>
<dt><?= e($t('cache.updated')) ?></dt><dd><?= $metrics===null?e($t('dashboard.no_data')):e(gmdate('Y-m-d H:i:s',$metrics['updated']).' UTC') ?></dd>
</dl><p class="muted"><?= e($t('cache.window')) ?></p>
<div class="dashboard-actions"><a class="button button-secondary" href="?view=site"><?= e($t('nav.settings')) ?></a>
<form method="post" data-confirm="<?= e($t('cache.confirm_clear')) ?>"><input type="hidden" name="action" value="cache-clear"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><button type="submit" class="button-secondary"><?= e($t('cache.clear')) ?></button></form></div></section>
<?php foreach ($dashboard['indicators'] as $item): ?><section class="panel dashboard-stat"><p class="muted"><?= e($item['plugin']) ?></p><h2><?= e($item['label']) ?></h2><p class="dashboard-value"><?= e($item['value']) ?></p></section><?php endforeach; ?>
<?php foreach ($dashboard['warnings'] as $plugin): ?><p class="notice" role="status"><?= e($t('dashboard.provider_unavailable',['plugin'=>$plugin])) ?></p><?php endforeach; ?>
</div>
