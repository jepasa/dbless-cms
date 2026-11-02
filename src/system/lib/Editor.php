<?php
/** Core authoring profiles and public editor metadata; never executes authored PHP/JS or widens the editorial HTML contract. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Editor {
  /** Return a closed portable profile; receives language, returns settings or throws 422 without I/O or code execution. */
  public static function profile(string $language): array {
    if(!in_array($language,['html','mustache','php','css','js'],true))throw new HttpError(422,'Unsupported editor language.','markup.error');
    return ['language'=>$language==='mustache'?'html':$language,'visual'=>in_array($language,['html','mustache'],true),'mustache'=>$language==='mustache','readOnly'=>false,'height'=>420,'toolbar'=>['bold','italic','link','image','list','paragraph','heading','quote']];
  }
  /** Expose safe static editor assets, localized labels and active template CSS; receives store/language/base, returns metadata without credentials, content or provider calls. */
  public static function config(JsonStore $store,Language $language,string $base=''): array {
    $labels=[];foreach($language->messages() as $key=>$message)if(str_starts_with($key,'wysiwyg.'))$labels[substr($key,8)]=$message;
    $site=$store->read('site')['data'];$publicLocale=SiteLocale::resolve($site['settings']);$template=$site['template'];$manifest=$store->templates->all()[$template]??null;$styles=[];
    foreach($manifest['assets']['styles']??[] as $relative)$styles[]=$store->templates->asset($template,$relative,$base);
    return ['version'=>1,'policy'=>ContentMarkup::policy(),'profiles'=>array_combine(['html','mustache','php','css','js'],array_map(self::profile(...),['html','mustache','php','css','js'])),'labels'=>$labels,'script'=>asset('cms-admin/assets/editor-frame.js',$base),'style'=>asset('cms-admin/assets/editor-frame.css',$base),'styles'=>$styles,'publicBase'=>$base.'/','locale'=>$publicLocale['tag'],'direction'=>$publicLocale['direction']];
  }
}
