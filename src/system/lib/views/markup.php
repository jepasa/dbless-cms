<?php
/** Public safe authored fragment inside the active template frame; supplied data is validated by core renderers, never executed as PHP. */
declare(strict_types=1);
echo $homepageHtml??\DBLESSCMS\ContentMarkup::render($markupDefinition,new \DBLESSCMS\ContentContext($store,$id,$base_url,$currentPage));
