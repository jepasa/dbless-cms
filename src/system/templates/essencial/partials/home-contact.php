<?php
/** Essencial native contact section; consumes validated Homepage data and keeps the template presentation contract. */
declare(strict_types=1);
use function DBLESSCMS\e;
?>
<section id="contact" class="section"><div class="container contact-grid"><div class="text-flow"><p class="eyebrow"><?= e($data['headings']['contact']['kicker']) ?></p><h2><?= e($data['headings']['contact']['title']) ?></h2><p class="section-lead"><?= e($data['headings']['contact']['description']) ?></p><dl class="contact-details" aria-label="<?= e('Informações de contato') ?>">
<?php foreach (['email','phone','address'] as $field): $value=$field==='address'?implode(', ',array_filter([$settings['contact']['city'],$settings['contact']['state'],$settings['contact']['country']],static fn(string $part):bool=>$part!=='')):$settings['contact'][$field];if ($value==='') continue; ?><div><dt><?= e($contactLabels[$field]) ?></dt><dd><?php if ($field==='email'): ?><a href="<?= e('mailto:'.$value) ?>"><?= e($value) ?></a><?php elseif ($field==='phone'): ?><a href="<?= e('tel:'.preg_replace('/[^+0-9]/','',$value)) ?>"><?= e($value) ?></a><?php else: ?><?= e($value) ?><?php endif; ?></dd></div><?php endforeach; ?></dl></div>
<?php if($homepageConversion??false): ?>{{template:fragment id="contact"}}<?php else: require $store->templates->path($id,'partials',$manifest['partials']['contact-form']);endif; ?></div></section>
