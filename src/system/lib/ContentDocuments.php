<?php
/** Manifest-discovered published document sources with independent providers, bounded output and cycle protection; missing sources never dispatch arbitrary PHP methods. */
declare(strict_types=1);
namespace DBLESSCMS;
final class ContentDocuments {
  private static array $stack=[];
  /** Validate a stable document ID (including numeric-leading page slugs); receive value, return bool without I/O. */
  public static function identifier(mixed $id): bool {return is_string($id)&&preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$id)===1;}
  /** Validate optional metadata without loading plugin PHP; receive manifest, throw 422 on unsupported capability metadata, no mutations. */
  public static function definition(array $manifest): void {
    if(!isset($manifest['documentSource']))return;$data=$manifest['documentSource'];
    if(!is_array($data)||count($data)!==2||($data['api']??null)!==1||!is_string($data['description']??null)||trim($data['description'])===''||strlen($data['description'])>500||preg_match('/[\x00-\x1F\x7F<>]/',$data['description']))throw new HttpError(422,'Invalid document source metadata.','markup.error');
  }
  /** Build authenticated source-picker options; receive read context, return enabled provider names/public IDs and isolated availability flags, never expose drafts. */
  public static function options(ContentContext $context): array {
    if(!Security::authenticated($context->store->config))throw new HttpError(403,'Authentication required.','error.authentication');$registry=new Plugins($context->store);$result=[];
    foreach($registry->all() as $id=>$manifest)if(isset($manifest['documentSource'])&&$registry->state($id)['enabled']){
      $items=[];$unavailable=false;try{$provider=$registry->instance($id);if(!$provider instanceof ContentDocumentInterface)throw new \UnexpectedValueException('Document interface missing.');$items=$provider->documents($context);if(!array_is_list($items)||count($items)>100)throw new \UnexpectedValueException('Too many source options.');foreach($items as $item)if(!is_array($item)||count($item)!==2||!is_string($item['id']??null)||!self::identifier($item['id'])||!is_string($item['name']??null)||strlen($item['name'])>500||preg_match('/[\x00-\x1F\x7F<>]/',$item['name']))throw new \UnexpectedValueException('Invalid document option.');}
      catch(\Throwable $error){$items=[];$unavailable=true;error_log('DBLESS-CMS document choices '.$id.' '.$error::class);}$result[$id]=['name'=>$manifest['name'],'description'=>$manifest['documentSource']['description'],'items'=>$items,'unavailable'=>$unavailable];
    }return $result;
  }
  /** Resolve only an enabled declared document provider; receive stable reference/context, return safe public HTML projection or null, log bounded failures and guard cycles; no writes/HTTP includes. */
  public static function resolve(array $source,ContentContext $context): ?array {
    $plugin=$source['plugin']??'';$id=$source['id']??'';if(!is_string($plugin)||!Plugins::identifier($plugin)||!is_string($id)||!self::identifier($id))return null;$key=spl_object_id($context->store).':'.$plugin.':'.$id;
    if(count(self::$stack)>=4||isset(self::$stack[$key]))throw new HttpError(422,'Circular document reference.','markup.error');self::$stack[$key]=true;
    try{$registry=new Plugins($context->store);$manifest=$registry->all()[$plugin]??null;if($manifest===null||!isset($manifest['documentSource'])||!$registry->state($plugin)['enabled'])return null;$provider=$registry->instance($plugin);if(!$provider instanceof ContentDocumentInterface)throw new \UnexpectedValueException('Document interface missing.');$result=$provider->document($id,$context);if($result===null)return null;
      Schema::validate($result,['type'=>'object','additionalProperties'=>false,'required'=>['name','description','html'],'properties'=>['name'=>['type'=>'string','maxLength'=>500],'description'=>['type'=>'string','maxLength'=>12000],'html'=>['type'=>'string','maxLength'=>524288]]]);$result['html']=ContentMarkup::html($result['html'],false);return $result;
    }catch(\Throwable $error){error_log('DBLESS-CMS document '.$plugin.' '.$error::class);return null;}finally{unset(self::$stack[$key]);}
  }
  /** Render a page's public semantic body without header/footer/global metadata; receive validated page/context, return safe HTML, support shared markup and legacy paragraph components without writes. */
  public static function page(array $page,ContentContext $context): string {
    if(isset($page['markup']))return ContentMarkup::render($page['markup'],$context);$data=$page['content'];$components=new Components($context);$html='<section class="section"><div class="container text-flow"><h1>'.e($data['title']??$page['meta']['title']).'</h1>';
    foreach(['lead','summary'] as $key)if(is_string($data[$key]??null)&&$data[$key]!=='')$html.='<p>'.e($data[$key]).'</p>';
    if(($data['image']??'')!=='')$html.='<img src="'.e(preg_match('~^https?://~i',$data['image'])?$data['image']:$context->base.'/'.ltrim($data['image'],'/')).'" alt="'.e($data['title']??'').'" loading="lazy">';
    if(($data['facts']??[])!==[]){$html.='<dl>';foreach($data['facts'] as $fact)$html.='<dt>'.e($fact['label']).'</dt><dd>'.e($fact['value']).'</dd>';$html.='</dl>';}
    foreach($data['sections']??[] as $section){$html.='<section><h2>'.e($section['title']??'').'</h2>';foreach($section['body']??[] as $paragraph)$html.=$components->paragraph($paragraph);if(($section['list']??[])!==[]){$html.='<ul>';foreach($section['list'] as $item)$html.='<li>'.e($item).'</li>';$html.='</ul>';}$html.='</section>';}
    return $html.'</div></section>';
  }
}
