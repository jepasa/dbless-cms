<?php
/** Explicit request-scoped interactive renderer, separate from read-only content data; trusted local plugins implement declared form services only. */
declare(strict_types=1);
namespace DBLESSCMS;
interface ContentFormProviderInterface {
  /** Render one published form for the current visitor; receive stable ID and trusted context, return a bounded fragment; may issue session tickets, never submit or expose private data. */
  public function renderForm(string $id,ContentContext $context): string;
}
