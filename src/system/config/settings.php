<?php
/** Configuração operacional CMS2JSON; conteúdo editorial reside em storage/contents/site.json. */
declare(strict_types=1);
return [
  'version' => '2.0.0',
  'environment' => getenv('CMS2JSON_ENV') ?: 'development',
  'contents' => getenv('CMS2JSON_CONTENTS_ROOT') ?: dirname(__DIR__) . '/storage/contents',
  'storage' => getenv('CMS2JSON_STORAGE_ROOT') ?: dirname(__DIR__) . '/storage',
  'contact_enabled' => getenv('CMS2JSON_CONTACT_ENABLED') === '1',
];
