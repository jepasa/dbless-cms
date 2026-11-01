<?php
/** Materialize the current native editorial sections as editable HTML; declared dynamic islands stay request-scoped Mustache fragments, without session tokens or stored provider output. */
declare(strict_types=1);
$contactLabels=['email'=>'E-mail','phone'=>'Telefone','address'=>'Localização'];
foreach(\DBLESSCMS\HomepageLayout::entries($manifest,$page) as $entry){
  if($entry['partial']===null){echo '{{template:fragment id="composition" key="'.\DBLESSCMS\e($entry['key']).'"}}';continue;}
  if($entry['partial']==='showcase'){echo '{{template:fragment id="showcase"}}';continue;}
  if($entry['partial']==='home-extras'){echo '{{template:fragment id="extras"}}';continue;}
  require $store->templates->path($id,'partials',$manifest['partials'][$entry['partial']]);
}
