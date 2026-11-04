<?php
/** Per-plugin canonical English documents and dictionary-backed admin projection; bounded escaped JSON, no Markdown engine or plugin PHP execution. */
declare(strict_types=1);
namespace DBLESSCMS;
final class PluginPresentation {
  /** Project an opted-in tutorial through complete plugin dictionary keys; receive validated data/language/plugin ID, return localized text without changing code or storage; missing keys fail explicitly. */
  public static function localized(array $data,Language $language,string $id): array {
    $prefix=$id.'.presentation.';if(!$language->has($prefix.'title'))return $data;
    foreach(['title','lead'] as $field)$data[$field]=$language->text($prefix.$field);
    foreach($data['sections'] as $index=>&$section){
      $section['title']=$language->text($prefix.'sections.'.$index.'.title');
      foreach(['body','steps'] as $field)foreach($section[$field] as $position=>&$text)$text=$language->text($prefix.'sections.'.$index.'.'.$field.'.'.$position);unset($text);
    }unset($section);$data['language']=$language->locale();return $data;
  }
  /** Read the required adjacent presentation.json tutorial; receive trusted package root/path, return validated English content or 422/404; reject links/ancestor traversal and oversized inputs. */
  public static function read(string $root,string $relative): array {
    if($relative!=='presentation.json')throw new HttpError(422,'Invalid presentation path.','error.plugin');
    $file=$root;foreach(explode('/',$relative) as $part){$file.='/'.$part;if(is_link($file))throw new HttpError(422,'Unsafe presentation.','error.plugin');}
    if(is_link($root)||!is_file($file))throw new HttpError(404,'Presentation unavailable.','error.not_found');$raw=file_get_contents($file,false,null,0,65537);
    if($raw===false||strlen($raw)>65536)throw new HttpError(422,'Presentation too large.','error.plugin');
    try{$data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(422,'Invalid presentation.','error.plugin');}
    $text=['type'=>'string','maxLength'=>12000];$schema=['type'=>'object','additionalProperties'=>false,'required'=>['language','title','lead','sections'],'properties'=>['language'=>['type'=>'string','enum'=>['en']],'title'=>$text,'lead'=>$text,'sections'=>['type'=>'array','maxItems'=>20,'items'=>['type'=>'object','additionalProperties'=>false,'required'=>['title','body','steps'],'properties'=>['title'=>$text,'body'=>['type'=>'array','maxItems'=>20,'items'=>$text],'steps'=>['type'=>'array','maxItems'=>20,'items'=>$text],'code'=>$text]]]]];
    Schema::validate($data,$schema);
    if(trim($data['title'])===''||trim($data['lead'])===''||$data['sections']===[])throw new HttpError(422,'Empty presentation.','error.plugin');
    foreach($data['sections'] as $section){
      if(trim($section['title'])===''||$section['steps']===[])throw new HttpError(422,'Tutorial steps required.','error.plugin');
      foreach($section['steps'] as $step)if(trim($step)==='')throw new HttpError(422,'Empty tutorial step.','error.plugin');
    }
    return $data;
  }
}
