<?php
/** Export literal public editorial media from already sanitized staging content; never fetch remote URLs, mirror repositories or inspect private working drafts. */
declare(strict_types=1);
namespace DBLESSCMS;
final class EditorialMedia {
  /** Collect static src/poster references from one validated fragment; receives source and reference set by reference, returns nothing and performs no rendering/provider calls. */
  private static function markup(string $html,array &$references): void {
    ContentMarkup::html($html);$previous=libxml_use_internal_errors(true);$document=new \DOMDocument();
    try{$document->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);foreach(['img','video','source'] as $tag)foreach($document->getElementsByTagName($tag) as $node)foreach(['src','poster'] as $attribute){$value=$node->getAttribute($attribute);$value=preg_replace('~^\{\{\s*cms\.base\s*\}\}/~','',$value);if($value!==''&&!str_contains($value,'{{'))$references[$value]=true;}}
    finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
  }
  /** Walk only sanitized exported data for authored markup and page metadata; receives data/reference set, no external I/O and no interpretation of arbitrary text as HTML. */
  private static function collect(array $data,array &$references): void {
    if(isset($data['html'],$data['bindings'])&&is_string($data['html'])&&is_array($data['bindings']))self::markup($data['html'],$references);
    if(($data['type']??null)==='image'&&is_string($data['src']??null)&&$data['src']!=='')$references[$data['src']]=true;
    if(isset($data['meta']['image'])&&is_string($data['meta']['image'])&&$data['meta']['image']!=='')$references[$data['meta']['image']]=true;
    if(isset($data['seo']['socialImage'])&&is_string($data['seo']['socialImage'])&&$data['seo']['socialImage']!=='')$references[$data['seo']['socialImage']]=true;
    foreach($data as $value)if(is_array($value))self::collect($value,$references);
  }
  /** Copy only explicit local references to a fresh staging tree; receives store/target, validates scope, containment, MIME and bytes, raises on missing/unsafe media without fetching or copying private media. */
  public static function export(JsonStore $store,string $target): void {
    $directory=Lifecycle::path($target.'/system/storage/contents');$references=[];
    foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS)) as $file){if($file->isLink())throw new \RuntimeException('Unsafe editorial staging.');if(!$file->isFile()||$file->getExtension()!=='json')continue;if($file->getSize()>PagePublications::LIMIT)throw new \RuntimeException('Oversized editorial document.');$data=json_decode((string)file_get_contents($file->getPathname()),true,64,JSON_THROW_ON_ERROR);if(is_array($data))self::collect($data,$references);}
    $root=MediaLibrary::roots($store)['public'];$public=dirname($store->config->root).'/public_html';
    foreach(array_keys($references) as $reference){
      if(preg_match('~^https?://~i',$reference))continue;$relative=ltrim(rawurldecode($reference),'/');MediaLibrary::relative($relative);$destination=Lifecycle::path($target.'/public_html/'.$relative);
      // Already-declared package assets are validated by their owner; do not copy arbitrary code trees.
      if(is_file($destination))continue;
      if(($root!==''&&!str_starts_with($relative,$root.'/'))||in_array(strtolower(explode('/',$relative)[0]),['cms-admin','cms-assets','plugins','templates','customizations','tracking','system'],true))throw new \RuntimeException('Editorial media must belong to the configured public repository.');
      $source=Lifecycle::path($public.'/'.$relative);if(!is_file($source)||MediaLibrary::mime($source)===null)throw new \RuntimeException('Referenced editorial media is missing or invalid.');
      Security::directory(dirname($destination));$bytes=file_get_contents($source);if($bytes===false||file_put_contents($destination,$bytes)!==strlen($bytes))throw new \RuntimeException('Incomplete editorial media export.');
    }
  }
}
