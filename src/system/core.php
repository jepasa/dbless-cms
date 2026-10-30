<?php
/** Entrada privada do site DBLESS-CMS; carrega bootstrap e despacha conteúdo/template. */
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
\DBLESSCMS\App::run();
