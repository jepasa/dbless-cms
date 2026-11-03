<?php
/** Generic authored page models independent from existing editorial pages; model markup and semantic content remain portable to Essencial-compatible templates. */
declare(strict_types=1);
namespace DBLESSCMS;
final class PageBlueprints {
  /** List closed model IDs; no parameters, returns declaration keys without dynamic code or I/O. */
  public static function names(): array {return ['blank','institutional','content','landing'];}
  /** Build a fresh draft with no inherited content/metadata/publication state; receive model/title/URL, return snapshot or 422 on unknown model. */
  public static function create(string $model,string $title,string $url): array {
    if(!in_array($model,self::names(),true)||trim($title)===''||strlen($title)>200||!PagePublications::identifier($url))throw new HttpError(422,'Invalid page model.','pages.editorial.invalid');
    $html=$model==='blank'?'':'<section><h1>'.e($title).'</h1><p></p></section>';
    if($model==='institutional')$html.="\n<section><h2>About</h2><p></p></section>\n<section><h2>Contact</h2><p></p></section>";
    if($model==='content')$html.="\n<article><h2>Details</h2><p></p></article>";
    if($model==='landing')$html.="\n<section><h2>Benefits</h2><ul><li></li></ul></section>\n<section><h2>Next step</h2><p></p></section>";
    return ['url'=>$url,'seo'=>PagePublications::seo(),'document'=>['schema_version'=>1,'slug'=>$url,'status'=>'draft','page'=>'starter-page','meta'=>['title'=>$title,'description'=>$title,'keywords'=>'','image'=>'','imageAlt'=>''],'markup'=>['html'=>$html,'bindings'=>[]],'content'=>['title'=>$title,'lead'=>'','summary'=>'','eyebrow'=>'','image'=>'','facts'=>[],'sections'=>[],'related'=>[],'cta'=>['eyebrow'=>'','title'=>'','description'=>'']]]];
  }
}
