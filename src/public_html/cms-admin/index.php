<?php
/** Front controller administrativo; somente assets são públicos, toda lógica reside em system. */
declare(strict_types=1);
require_once __DIR__.'/../../system/bootstrap.php';
\CMS2JSON\App::run(true);
