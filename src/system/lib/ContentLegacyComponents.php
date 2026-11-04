<?php
/** Preserve existing native component markers when Pages adopts authored HTML; bounded opaque slots reuse registered read-only providers/layouts after editorial validation. */
declare(strict_types=1);
namespace DBLESSCMS;
final class ContentLegacyComponents {
  private const PATTERN='/\{\{[a-z][a-z0-9-]{0,63}:[a-z][a-z0-9-]{0,63}(?: [a-z][a-zA-Z0-9]{0,47}="[^"{}\r\n]{0,512}")*\}\}/';
  /** Reserve legacy source markers; receive HTML after form/template preparation, return HTML/slots or 422 for malformed/excessive/forged source, never execute providers. */
  public static function prepare(string $source): array {
    if(preg_match('/data-cms-component-slot\s*=/i',$source))throw new HttpError(422,'Reserved component slot.','markup.error');$slots=[];
    $html=preg_replace_callback(self::PATTERN,static function(array $match)use(&$slots):string{$marker=Components::parse($match[0]);if($marker['plugin']==='template'||$marker['service']==='form')throw new HttpError(422,'Invalid reserved marker.','markup.error');if(count($slots)>=20)throw new HttpError(422,'Component budget exceeded.','markup.error');$token=bin2hex(random_bytes(16));$slots[$token]=$match[0];return '<div data-cms-component-slot="'.$token.'"></div>';},$source);return ['html'=>$html,'slots'=>$slots];
  }
  /** Mount validated noninteractive legacy output without reparsing it as Mustache; receive rendered HTML/slots/context, return composed HTML or 422 for unsafe nesting/output/amplification, never write storage. */
  public static function mount(string $html,array $slots,ContentContext $context): string {
    if($slots===[])return $html;$previous=libxml_use_internal_errors(true);$dom=new \DOMDocument('1.0','UTF-8');
    try{$dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$xpath=new \DOMXPath($dom);$count=0;$components=new Components($context);
      foreach($slots as $token=>$marker){$nodes=$xpath->query('//*[@data-cms-component-slot="'.$token.'"]');$count+=$nodes->length;if($count>20)throw new HttpError(422,'Component expansion budget exceeded.','markup.error');if($nodes->length===0)continue;
        foreach(iterator_to_array($nodes) as $node)foreach(['a','button','form','label','pre','code'] as $tag)if($xpath->query('ancestor::'.$tag,$node)->length)throw new HttpError(422,'Invalid component nesting.','markup.error');
        $fragment=ContentMarkup::html($components->render($marker),false);if(strlen($fragment)>131072)throw new HttpError(422,'Component output too large.','markup.error');$source=new \DOMDocument('1.0','UTF-8');$source->loadHTML('<?xml encoding="UTF-8"><html><body>'.$fragment.'</body></html>',LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
        foreach(iterator_to_array($nodes) as $node){foreach(iterator_to_array($source->getElementsByTagName('body')->item(0)->childNodes) as $child)$node->parentNode->insertBefore($dom->importNode($child,true),$node);$node->parentNode->removeChild($node);}
      }
      $result='';foreach($dom->getElementsByTagName('body')->item(0)->childNodes as $node)$result.=$dom->saveHTML($node);return $result;
    }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
  }
}
