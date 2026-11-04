<?php
/** Owner-controlled adapters for retired plugin identifiers; aliases never constitute installed packages. */
declare(strict_types=1);
/** Shared CMS services and validated extension integration.
 * @package DBLESSCMS
 */
namespace DBLESSCMS;
/** Require an installed owner to resolve only its validated retired identifiers into trusted plugin adapters. */
interface PluginCompatibilityInterface {
  /**
   * Resolve one owner-declared compatibility identifier without installing or changing state.
   *
   * @param string $id Validated retired identifier from this owner's manifest.
   * @return PluginInterface Trusted internal adapter retaining the historical API.
   * @throws HttpError When the identifier is not supported by this owner (404).
   */
  public function compatibility(string $id): PluginInterface;
}
