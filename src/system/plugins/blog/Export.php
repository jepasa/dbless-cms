<?php
/** Blog production export: validated private editorial catalog and explicitly referenced local raster media; no comments, history, credentials or implicit directory mirroring. */
declare(strict_types=1);
namespace DBLESSCMS;
final class BlogExport {
  /** Export one sanitized installation catalog; takes source store/trusted new staging root, writes private catalog and referenced raster assets, raises on malformed/unsafe/missing files. */
  public static function run(JsonStore $store,string $target): void {
    $data=(new BlogStore($store))->read()['data'];
    $data['articles']=array_filter($data['articles'],static fn(array $item): bool=>$item['deletedAt']==='');
    foreach (['categories','tags','authors'] as $key) $data[$key]=array_filter($data[$key],static fn(array $item): bool=>!$item['deleted']);
    BlogModel::catalog($data);$references=[];
    foreach ($data['articles'] as $article) {
      if ($article['cover']!=='') $references[$article['cover']]=true;
      foreach ($article['blocks'] as $block) if ($block['type']==='image') $references[$block['src']]=true;
    }
    foreach ($data['authors'] as $author) if ($author['image']!=='') $references[$author['image']]=true;
    $public=dirname($store->config->root).'/public_html';
    foreach (array_keys($references) as $reference) {
      BlogModel::media($reference);$source=Lifecycle::path($public.'/'.$reference);$destination=Lifecycle::path($target.'/public_html/'.$reference);
      if (!is_file($source)||filesize($source)>12582912) throw new \RuntimeException('Referenced blog image is missing or too large.');
      $expected=match(strtolower(pathinfo($reference,PATHINFO_EXTENSION))) {'jpg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif','avif'=>'image/avif',default=>''};
      $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($source);$size=@getimagesize($source);
      if ($mime!==$expected||$size===false||$size[0]*$size[1]>40000000||($size['mime']??'')!==$mime) throw new \RuntimeException('Referenced blog image is not an allowed raster.');
      $bytes=file_get_contents($source);if ($bytes===false||preg_match('~<\?(?:php|=)|<\s*(?:script|iframe|object|embed|html)\b~i',$bytes)) throw new \RuntimeException('Referenced blog image contains a forbidden payload.');
      if (!is_dir(dirname($destination))&&!mkdir(dirname($destination),0750,true)) throw new \RuntimeException('Blog media export directory unavailable.');
      if (file_put_contents($destination,$bytes)!==strlen($bytes)) throw new \RuntimeException('Incomplete blog media export.');
    }
    $directory=Lifecycle::path($target.'/system/storage/contents/blog');
    if (!is_dir($directory)&&!mkdir($directory,0700,true)) throw new \RuntimeException('Blog export directory unavailable.');
    $bytes=BlogStore::encode($data);if (file_put_contents($directory.'/catalog.json',$bytes)!==strlen($bytes)||!chmod($directory.'/catalog.json',0600)) throw new \RuntimeException('Incomplete blog export.');
  }
}
