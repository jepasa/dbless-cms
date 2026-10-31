<?php
/** Legacy template adapter: preserve the registered native homepage and append portable custom sections; templates can opt into full native ordering explicitly. */
declare(strict_types=1);
use function DBLESSCMS\e;
require $nativeContentFile;
foreach(\DBLESSCMS\HomepageLayout::entries($manifest,$page) as $entry){
  if($entry['section']===null)continue;$homeSection=$entry['section'];
  ?><section class="section" id="<?= e($homeSection['key']) ?>"><div class="container"><h2><?= e($homeSection['title']) ?></h2><?php foreach($homeSection['body'] as $paragraph)echo $components->paragraph($paragraph); ?></div></section><?php
}
