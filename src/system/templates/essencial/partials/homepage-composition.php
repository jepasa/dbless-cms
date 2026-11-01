<?php
/** Live custom Homepage sections retain published plugin bindings; receive the public view scope and render trusted template sections without mutation. */
declare(strict_types=1);
foreach(\DBLESSCMS\HomepageLayout::entries($manifest,$page) as $entry)if($entry['partial']===null&&$entry['key']===$fragmentKey){$homeSection=$entry['section'];require $store->templates->path($id,'partials',$manifest['partials']['homepage-section']);}
