<?php
/** Complete optional plugin example: schema validation and independent private/public routing, with no persistent state. */
declare(strict_types=1);
namespace CMS2JSON;
require_once __DIR__.'/Router.php';
final class HelloPlugin implements PluginInterface, PublicPluginInterface {
  /** Validate settings against the local schema; receives settings/config, returns nothing and throws on invalid data or unreadable schema. */
  public function validateSettings(array $settings,Config $config): void {
    $raw=file_get_contents(__DIR__.'/settings.schema.json');
    if($raw===false)throw new HttpError(503,'Settings schema unavailable.');
    Schema::validate($settings,json_decode($raw,true,32,JSON_THROW_ON_ERROR));
  }
  /** Return an integrated admin fragment for an authorized context; no writes; unknown routes fail with 404. */
  public function render(PluginContext $context): string {return HelloRouter::admin($context);}
  /** Reject unsupported private API operations; receives context/name, always throws 404 and never changes state. */
  public function api(PluginContext $context,string $operation): ?array {throw new HttpError(404,'Operation not found.');}
  /** Return a public document using only the restricted public context; no writes and 404 for unknown routes. */
  public function publicPage(PublicPluginContext $context): string {return HelloRouter::publicPage($context);}
}
