<?php
/** Configuração operacional DBLESS-CMS; conteúdo editorial reside em storage/contents/site.json. */
declare(strict_types=1);
return [
  'version' => '2.3.2',
  'environment' => getenv('DBLESS_CMS_ENV') ?: 'development',
  'contents' => getenv('DBLESS_CMS_CONTENTS_ROOT') ?: dirname(__DIR__) . '/storage/contents',
  'storage' => getenv('DBLESS_CMS_STORAGE_ROOT') ?: dirname(__DIR__) . '/storage',
  'contact_enabled' => getenv('DBLESS_CMS_CONTACT_ENABLED') === '1',
];
