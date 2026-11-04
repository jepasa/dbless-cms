<?php
/** Bounded plugin tutorials: mandatory canonical English, declared locale documents and legacy dictionary projection; escaped rendering never executes plugin code or writes content. */
declare(strict_types=1);
namespace DBLESSCMS;
final class PluginPresentation {
  /** Validate a normalized locale identifier; receive text, return bool without paths, I/O or mutations. */
  private static function locale(string $value): bool {return strlen($value)<=35&&preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/D',$value)===1;}
  /** Validate bounded explicit locale-to-document declarations; receive manifest, return map or 422 for unsafe paths/IDs, no I/O or writes. */
  public static function translations(array $manifest): array {
    if(!array_key_exists('presentationLocales',$manifest))return [];$map=$manifest['presentationLocales'];
    if(!is_array($map)||count($map)>20||$map!==[]&&array_is_list($map))throw new HttpError(422,'Invalid presentation locales.','error.plugin');
    foreach($map as $locale=>$file)if(!is_string($locale)||!self::locale($locale)||!is_string($file)||$file!=='languages/presentation-'.$locale.'.json')throw new HttpError(422,'Invalid presentation locale path.','error.plugin');
    return $map;
  }
  /** Validate a localizable canonical outline; receive manifest/validated English document, require stable IDs when opting into locale files, throw 422 without file writes. */
  public static function definition(array $manifest,array $canonical): void {
    self::translations($manifest);if(array_key_exists('presentationLocales',$manifest)&&count(array_column($canonical['sections'],'id'))!==count($canonical['sections']))throw new HttpError(422,'Localized presentation requires section IDs.','error.plugin');
  }
  /** Preserve legacy dictionary-backed tutorials; receive validated data/language/plugin ID, return projection unchanged for non-opted-in dictionaries, throw on missing legacy keys, no storage changes. */
  public static function localized(array $data,Language $language,string $id): array {
    $prefix=$id.'.presentation.';if(!$language->has($prefix.'title'))return $data;
    foreach(['title','lead'] as $field)$data[$field]=$language->text($prefix.$field);
    foreach($data['sections'] as $index=>&$section){
      $section['title']=$language->text($prefix.'sections.'.$index.'.title');
      foreach(['body','steps'] as $field)foreach($section[$field] as $position=>&$text)$text=$language->text($prefix.'sections.'.$index.'.'.$field.'.'.$position);unset($text);
    }unset($section);$data['language']=$language->locale();return $data;
  }
  /** Read mandatory adjacent English tutorial; receive trusted package root/exact canonical filename, return bounded data or 422/404, reject links and never invoke plugin code. */
  public static function read(string $root,string $relative): array {
    if($relative!=='presentation.json')throw new HttpError(422,'Invalid presentation path.','error.plugin');return self::document($root,$relative,'en');
  }
  /** Load one declared local document; receive trusted root/validated relative path/expected locale, return validated tutorial or 422/404 without leaking failed I/O paths or writing. */
  private static function document(string $root,string $relative,string $locale): array {
    if(!self::locale($locale)||($relative!=='presentation.json'&&$relative!=='languages/presentation-'.$locale.'.json'))throw new HttpError(422,'Invalid presentation path.','error.plugin');
    $file=$root;if(is_link($root))throw new HttpError(422,'Unsafe presentation root.','error.plugin');
    foreach(explode('/',$relative) as $part){$file.='/'.$part;if(is_link($file))throw new HttpError(422,'Unsafe presentation.','error.plugin');}
    if(!is_file($file))throw new HttpError(404,'Presentation unavailable.','error.not_found');$raw=@file_get_contents($file,false,null,0,65537);
    if($raw===false||strlen($raw)>65536)throw new HttpError(422,'Presentation unavailable or too large.','error.plugin');
    try{$data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(422,'Invalid presentation.','error.plugin');}
    $text=['type'=>'string','maxLength'=>12000];$schema=['type'=>'object','additionalProperties'=>false,'required'=>['language','title','lead','sections'],'properties'=>['language'=>['type'=>'string','enum'=>[$locale]],'title'=>$text,'lead'=>$text,'sections'=>['type'=>'array','maxItems'=>20,'items'=>['type'=>'object','additionalProperties'=>false,'required'=>['title','body','steps'],'properties'=>['id'=>['type'=>'string','pattern'=>'^[a-z][a-z0-9-]{0,63}$','maxLength'=>64],'title'=>$text,'body'=>['type'=>'array','maxItems'=>20,'items'=>$text],'steps'=>['type'=>'array','maxItems'=>20,'items'=>$text],'code'=>$text]]]]];
    Schema::validate($data,$schema);
    if(trim($data['title'])===''||trim($data['lead'])===''||$data['sections']===[])throw new HttpError(422,'Empty presentation.','error.plugin');
    foreach($data['sections'] as $section){
      if(trim($section['title'])===''||$section['steps']===[])throw new HttpError(422,'Tutorial steps required.','error.plugin');
      foreach($section['steps'] as $step)if(trim($step)==='')throw new HttpError(422,'Empty tutorial step.','error.plugin');
    }
    $ids=array_column($data['sections'],'id');if($ids!==[]&&(count($ids)!==count($data['sections'])||count(array_unique($ids))!==count($ids)))throw new HttpError(422,'Invalid presentation section IDs.','error.plugin');
    return $data;
  }
  /** Match the complete canonical outline and inherit immutable technical examples; receive documents, return localized data or 422 for stale IDs/code, no I/O or writes. */
  private static function compatible(array $canonical,array $translated): array {
    if(array_column($canonical['sections'],'id')!==array_column($translated['sections'],'id'))throw new HttpError(422,'Presentation outline mismatch.','error.plugin');
    foreach($translated['sections'] as $index=>&$section){$source=$canonical['sections'][$index];foreach($section['body'] as $paragraph)if(trim($paragraph)==='')throw new HttpError(422,'Empty translated paragraph.','error.plugin');if(array_key_exists('code',$section)&&(!array_key_exists('code',$source)||$section['code']!==$source['code']))throw new HttpError(422,'Presentation example mismatch.','error.plugin');if(array_key_exists('code',$source))$section['code']=$source['code'];}unset($section);return $translated;
  }
  /** Select exact admin-locale document/projection or visible English fallback; receive package root/manifest/locale/optional matching legacy language, return document+warning, propagate canonical/metadata errors and never write/execute PHP. */
  public static function select(string $root,array $manifest,string $locale,?Language $legacy=null): array {
    if(!self::locale($locale))throw new HttpError(422,'Invalid presentation locale.','error.plugin');$canonical=self::read($root,$manifest['presentation']);self::definition($manifest,$canonical);$map=self::translations($manifest);
    if(isset($map[$locale])){
      try{return ['document'=>self::compatible($canonical,self::document($root,$map[$locale],$locale)),'warning'=>null];}
      catch(HttpError $error){if(!in_array($error->status,[404,422],true))throw $error;return ['document'=>$canonical,'warning'=>'invalid'];}
    }
    $document=!array_key_exists('presentationLocales',$manifest)&&$legacy!==null&&$legacy->locale()===$locale?self::localized($canonical,$legacy,$manifest['id']):$canonical;
    $same=$document['language']===$locale||$document['language']==='en'&&($locale==='en'||str_starts_with($locale,'en-'));
    return ['document'=>$document,'warning'=>$same?null:'missing'];
  }
}
