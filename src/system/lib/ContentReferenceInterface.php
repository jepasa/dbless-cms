<?php
/** Automatic Mustache public-data provider; plugin manifests declare capabilities, while the Core owns parsing and output validation. */
declare(strict_types=1);
namespace DBLESSCMS;
interface ContentReferenceInterface {
  /** Resolve an ID/slug or declared query; receive selector, typed parameters and read context; return public JSON or null when absent, throw on corrupt contracts; never mutate or render nested source. */
  public function reference(string $selector,array $parameters,ContentContext $context): ?array;
}
