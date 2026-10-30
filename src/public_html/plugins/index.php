<?php
/** Entrada pública compartilhada: despacha somente rotas public declaradas, mantendo scripts dos plugins fora do DocumentRoot. */
declare(strict_types=1);
require_once dirname(__DIR__,2).'/system/bootstrap.php';
\CMS2JSON\PublicPlugins::run();
