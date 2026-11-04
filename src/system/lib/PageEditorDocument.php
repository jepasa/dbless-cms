<?php
/** One lossless Pages editing envelope: canonical HTML/bindings plus preserved native data, with bounded semantic projection and no provider execution or storage writes. */
declare(strict_types=1);
namespace DBLESSCMS;
final class PageEditorDocument {
  /** Decode and validate an editor payload; receive bounded JSON, return HTML/bindings/content or 422 without mutating any document. Native schema validation remains the repository's responsibility. */
  public static function decode(string $json): array {
    if(strlen($json)>524288)throw new HttpError(422,'Page document is too large.','pages.documentInvalid');
    try{$value=json_decode($json,true,40,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(422,'Invalid page document JSON.','pages.documentInvalid');}
    if(!is_array($value)||array_is_list($value)||array_diff(array_keys($value),['html','bindings','content'])!==[]||!isset($value['html'],$value['bindings'],$value['content'])||!is_string($value['html'])||!is_array($value['bindings'])||!is_array($value['content'])||($value['content']!==[]&&array_is_list($value['content'])))throw new HttpError(422,'Expected html, bindings and content.','pages.documentInvalid');
    $markup=ContentMarkup::definition(['html'=>$value['html'],'bindings'=>$value['bindings']]);return $markup+['content'=>$value['content']];
  }
  /** Read an existing page into one editor envelope; receive validated page, return exact authored source or a portable native projection, never persist or resolve plugin values. */
  public static function open(array $page): array {
    $markup=$page['markup']??['html'=>self::native($page['content']),'bindings'=>[]];
    return self::decode(JsonStore::encode($markup+['content'=>$page['content']]));
  }
  /** Apply a canonical envelope to a page; receive page/JSON, return a copy retaining metadata and other contracts, propagate validation errors before any write. */
  public static function apply(array $page,string $json): array {
    $value=self::decode($json);$page['content']=$value['content'];$page['markup']=['html'=>$value['html'],'bindings'=>$value['bindings']];return $page;
  }
  /** Escape one native text without resolving Mustache; receive text, return source-safe HTML, retain whole legacy markers instead of freezing their output. */
  private static function text(string $value): string {
    if(Components::parse($value)!==null)return trim($value);
    $escaped=e($value);return preg_match('/\{\{\s*[#^]?[a-zA-Z][a-zA-Z0-9_-]*\./',$value)?$escaped:str_replace(['{{','}}'],['&#123;&#123;','&#125;&#125;'],$escaped);
  }
  /** Project standard native editorial fields as semantic HTML; receive content, return portable markup or 422 for unsupported block types, preserving references as source and leaving unknown fields in the envelope. */
  private static function native(array $data): string {

    $html='<section class="section"><div class="container">';
    foreach(['eyebrow'=>'p','title'=>'h1','lead'=>'p','summary'=>'p'] as $key=>$tag)if(($data[$key]??'')!=='')$html.="\n<".$tag.'>'.self::text($data[$key]).'</'.$tag.'>';
    if(($data['image']??'')!==''){$url=$data['image'];if(!preg_match('~^https?://~i',$url))$url='{{cms.base}}/'.ltrim($url,'/');$html.="\n".'<img src="'.e($url).'" alt="'.e($data['title']??'').'">';}
    foreach($data['sections']??[] as $section){$html.="\n<section>";if(($section['title']??'')!=='')$html.='<h2>'.self::text($section['title']).'</h2>';foreach($section['body']??[] as $text)$html.=Components::parse($text)!==null?"\n".self::text($text):"\n<p>".self::text($text).'</p>'; if(($section['list']??[])!==[]){$html.="\n<ul>";foreach($section['list'] as $text)$html.='<li>'.self::text($text).'</li>';$html.='</ul>';}$html.="\n</section>";}
    if(($data['facts']??[])!==[]){$html.="\n<dl>";foreach($data['facts'] as $fact)$html.='<dt>'.self::text($fact['label']).'</dt><dd>'.self::text($fact['value']).'</dd>';$html.='</dl>';}
    if(($data['related']??[])!==[]){$html.="\n<nav><ul>";foreach($data['related'] as $item){$url=$item['href'];if(str_starts_with($url,'/'))$url='{{cms.base}}'.$url;$html.='<li><a href="'.e($url).'">'.self::text($item['label']).'</a></li>';}$html.='</ul></nav>';}
    if(($data['cta']['title']??'')!==''){$html.="\n<section>";foreach(['eyebrow'=>'p','title'=>'h2','description'=>'p'] as $key=>$tag)if(($data['cta'][$key]??'')!=='')$html.='<'.$tag.'>'.self::text($data['cta'][$key]).'</'.$tag.'>';$html.='</section>';}
    foreach($data['blocks']??[] as $block){if(($block['type']??'')!=='slides'||!preg_match('/^[a-z][a-z0-9-]{0,47}$/D',$block['location']??''))throw new HttpError(422,'Unsupported native block.','pages.conversionUnsupported');$html.="\n{{slides:".$block['location'].'}}';}
    if($data['demonstrateMenus']??false)$html.='{{template:fragment id="menu-examples"}}';
    return $html."\n</div></section>";
  }
}
