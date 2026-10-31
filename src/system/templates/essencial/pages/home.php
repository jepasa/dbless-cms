<?php
/** Essencial Homepage composition: ordered native sections and reusable component sections, driven by validated page data and static manifest partials. */
declare(strict_types=1);
$data=$page['content'];
foreach(\DBLESSCMS\HomepageLayout::entries($manifest,$page) as $entry){
  if($entry['partial']!==null)require $store->templates->path($id,'partials',$manifest['partials'][$entry['partial']]);
  else{$homeSection=$entry['section'];require $store->templates->path($id,'partials',$manifest['partials'][$manifest['homepage']['sectionPartial']]);}
}
