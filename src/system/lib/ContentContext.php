<?php
/** Explicit component context for trusted local PHP providers; no credentials or administrative payload is serialized to the browser. */
declare(strict_types=1);
namespace DBLESSCMS;
final class ContentContext {
  /** Receive shared store, template, base and page identifiers; store context without reads/writes; provider failures propagate to the resolver. */
  public function __construct(public readonly JsonStore $store,public readonly string $template,public readonly string $base,public readonly string $page) {}
}
