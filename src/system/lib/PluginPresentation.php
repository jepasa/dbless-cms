<?php
/** Per-plugin English capability and usage documents; bounded escaped JSON, no Markdown engine or plugin PHP execution. */
declare(strict_types=1);
namespace DBLESSCMS;
final class PluginPresentation {
  /** Read a manifest-selected relative regular JSON document; receive trusted package root/path, return validated English content or 422/404; reject links/ancestor traversal and oversized inputs. */
  public static function read(string $root,string $relative): array {
    if(!preg_match('~^[a-z][a-z0-9-]*(?:/[a-z][a-z0-9-]*)*\.json$~D',$relative))throw new HttpError(422,'Invalid presentation path.','error.plugin');
    $file=$root;foreach(explode('/',$relative) as $part){$file.='/'.$part;if(is_link($file))throw new HttpError(422,'Unsafe presentation.','error.plugin');}
    if(is_link($root)||!is_file($file))throw new HttpError(404,'Presentation unavailable.','error.not_found');$raw=file_get_contents($file,false,null,0,65537);
    if($raw===false||strlen($raw)>65536)throw new HttpError(422,'Presentation too large.','error.plugin');
    try{$data=json_decode($raw,true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(422,'Invalid presentation.','error.plugin');}
    $text=['type'=>'string','maxLength'=>12000];$schema=['type'=>'object','additionalProperties'=>false,'required'=>['language','title','lead','sections'],'properties'=>['language'=>['type'=>'string','enum'=>['en']],'title'=>$text,'lead'=>$text,'sections'=>['type'=>'array','maxItems'=>20,'items'=>['type'=>'object','additionalProperties'=>false,'required'=>['title','body'],'properties'=>['title'=>$text,'body'=>['type'=>'array','maxItems'=>20,'items'=>$text],'code'=>$text]]]]];
    Schema::validate($data,$schema);return $data;
  }
}
