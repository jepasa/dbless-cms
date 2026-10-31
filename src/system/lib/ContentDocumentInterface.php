<?php
/** Optional published-document capability for reference homepages; no HTTP forwarding or administrative context is exposed. */
declare(strict_types=1);
namespace DBLESSCMS;
interface ContentDocumentInterface {
  /** List up to 100 published document IDs/names; receive read context, return plain public options without mutations or draft/credential disclosure. */
  public function documents(ContentContext $context): array;
  /** Resolve one stable published ID; receive ID/context, return name/description/safe HTML or null when unavailable, never return the site frame or perform writes/network calls. */
  public function document(string $id,ContentContext $context): ?array;
}
