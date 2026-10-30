<?php
/** Blocos de página: resolve somente locais Slides registrados e inclui o partial fixo do template, sem interpretar código. */
declare(strict_types=1);
foreach($data['blocks']??[] as $block){
  $slideData=$slides->location($block['location']);
  if($slideData!==null)require $store->templates->path($id,'partials',$manifest['partials']['slides']);
}
