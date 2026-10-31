<?php
/** Template-owned portable Homepage section; component blocks are resolved outside paragraphs and plain content remains escaped. */
declare(strict_types=1);
use function DBLESSCMS\e;
?>
<section class="section" id="<?= e($homeSection['key']) ?>"><div class="container text-flow"><?php if($homeSection['title']!==''): ?><h2><?= e($homeSection['title']) ?></h2><?php endif; ?><?php foreach($homeSection['body'] as $paragraph)echo $components->paragraph($paragraph); ?></div></section>
