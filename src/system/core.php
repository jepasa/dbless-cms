<?php
/** Entrada privada do site CMS2JSON; carrega bootstrap e despacha conteúdo/template. */
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
\CMS2JSON\App::run();
