<?php
/** Optional API 1 contract for read-only component providers; markers never dispatch arbitrary PHP methods. */
declare(strict_types=1);
namespace DBLESSCMS;
interface ContentProviderInterface {
  /** Receive an allowlisted service, validated typed parameters and trusted read context; return schema-validated public data, perform no writes/network calls, propagate errors for core isolation. */
  public function content(string $service,array $parameters,ContentContext $context): array;
}
