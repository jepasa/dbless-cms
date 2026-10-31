<?php
/** Optional authenticated editor choices for component parameters; separate from metadata discovery and public component queries. */
declare(strict_types=1);
namespace DBLESSCMS;
interface ContentOptionsInterface {
  /** Receive declared service/context; return parameter => list of value/label pairs for authorized insertion UI, no writes or public rendering; source failures propagate. */
  public function contentOptions(string $service,ContentContext $context): array;
}
