<?php
/** Idioma público independente do painel: tags limitadas, direção, metadados. */
declare(strict_types=1);
namespace CMS2JSON;
final class SiteLocale {
  /** Resolve preferência explícita ou OG legado; recebe settings e retorna tag/direção/OG, sem escrita; tag inválida gera 422. */
  public static function resolve(array $settings): array {
    $legacy=$settings['og']['locale']??'pt-BR';
    $tag=$settings['siteLocale']??(is_string($legacy)?str_replace('_','-',$legacy):'');
    if (!isset($settings['siteLocale']) && $tag==='') $tag='pt-BR';
    if (!is_string($tag) || strlen($tag)>64 || !preg_match('/^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})*$/D',$tag)) throw new HttpError(422,'Idioma público inválido.','error.site_language');
    $parts=explode('-',$tag);$parts[0]=strtolower($parts[0]);$script=null;
    foreach ($parts as $index=>$part) {
      if ($index===0) continue;
      if (strlen($part)===4 && ctype_alpha($part)) { $parts[$index]=ucfirst(strtolower($part)); $script=$parts[$index]; }
      else $parts[$index]=strlen($part)===2?strtoupper($part):strtolower($part);
    }
    $rtl=$script!==null?in_array($script,['Arab','Hebr','Thaa','Syrc','Nkoo','Adlm'],true):in_array($parts[0],['ar','fa','he','ur','ps','dv','yi','sd'],true);
    return ['tag'=>implode('-',$parts),'direction'=>$rtl?'rtl':'ltr','og'=>implode('_',$parts)];
  }
}
