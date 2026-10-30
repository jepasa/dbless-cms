<?php
/** Integrated administrative fragment: escaped settings and a link to the public example, without a second global layout. */
declare(strict_types=1);
use function DBLESSCMS\e;
?>
<section class="hello-plugin"><p><?= e($context->settings['message']) ?></p><a href="<?= e($context->base.'/plugins/hello/welcome') ?>" target="_blank" rel="noopener"><?= e($context->language->text('hello.open')) ?></a></section>
