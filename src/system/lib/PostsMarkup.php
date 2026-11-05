<?php
/** Lossless editorial projection for legacy Blog blocks; source remains inert, native blocks are retained separately and provider results are never materialized. */
declare(strict_types=1);
namespace DBLESSCMS;
final class PostsMarkup {
  /** Escape literal native text including Mustache delimiters; receive text, return inert source HTML without effects. */
  private static function literal(string $value): string {return str_replace(['{{','}}'],['&#123;&#123;','&#125;&#125;'],e($value));}
  /** Project validated native blocks into shared authoring source; receive blocks, return html/bindings or 422 for incompatible legacy scopes, without executing providers. */
  public static function blocks(array $blocks): array {
    $html=[];$bindings=[];
    foreach($blocks as $block){$text=isset($block['text'])?self::literal($block['text']):'';
      $html[]=match($block['type']){
        'markup'=>$block['html'],'paragraph'=>'<p>'.$text.'</p>','quote'=>'<blockquote><p>'.$text.'</p></blockquote>','code'=>'<pre><code>'.$text.'</code></pre>',
        'heading'=>'<h'.$block['level'].'>'.$text.'</h'.$block['level'].'>',
        'link'=>'<p><a href="'.e($block['url']).'">'.$text.'</a></p>',
        'image'=>'<figure><img src="{{cms.base}}/'.e($block['src']).'" alt="'.e($block['alt']).'"><figcaption>'.self::literal($block['caption']).'</figcaption></figure>',
        'list'=>'<'.($block['ordered']?'ol':'ul').'>'.implode('',array_map(static fn(string $item):string=>'<li>'.self::literal($item).'</li>',$block['items'])).'</'.($block['ordered']?'ol':'ul').'>',
        default=>throw new HttpError(422,'Unsupported native block.','posts.error.invalid')};
      foreach($block['bindings']??[] as $binding){$alias=$binding['alias'];if(isset($bindings[$alias])&&$bindings[$alias]!==$binding)PostTypes::fail('projection');$bindings[$alias]=$binding;}
    }return ContentMarkup::definition(['html'=>implode("\n",$html),'bindings'=>array_values($bindings)]);
  }
}
