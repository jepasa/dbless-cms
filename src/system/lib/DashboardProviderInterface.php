<?php
/** Optional trusted-plugin contract for bounded dashboard indicators; separate from routing and plugin activation. */
declare(strict_types=1);
namespace DBLESSCMS;
interface DashboardProviderInterface {
  /** Receive private authenticated plugin context; return up to eight ['label'=>dictionary key,'value'=>nonnegative int] indicators without writes, HTML, secrets or public requests. The core groups all indicators in one card by plugin ID. Errors propagate to core isolation/logging. */
  public function dashboard(PluginContext $context): array;
}
