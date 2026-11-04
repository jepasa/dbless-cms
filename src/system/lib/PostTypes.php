<?php
/** Declarative publication types: literal route policies, bounded capabilities and immutable built-in identities; no dynamic code registration. */
declare(strict_types=1);
namespace DBLESSCMS;
final class PostTypes {
  /** Raise a localized publication contract error; receive key/status, never return or mutate storage. */
  public static function fail(string $key='invalid',int $status=422): never {throw new HttpError($status,'Publication operation unavailable.','posts.error.'.$key);}
  /** Validate an opaque identity; receive untrusted value, return bool without resolving paths. */
  public static function identifier(mixed $value): bool {return is_string($value)&&preg_match('/^[a-z][a-z0-9-]{0,63}$/D',$value)===1;}
  /** Validate bounded plain author-owned metadata; receive value/limit/empty policy, return text or 422, no effects. */
  public static function text(mixed $value,int $limit=200,bool $empty=true,bool $multiline=false): string {
    if(!is_string($value)||strlen($value)>$limit||(!$empty&&trim($value)==='')||preg_match('//u',$value)!==1||preg_match($multiline?'/[<>\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/':'/[<>\x00-\x1f\x7f]/',$value))self::fail();return $value;
  }
  /** Validate a literal relative route of at most three safe segments; receive path/root policy, return path or 422, reject reserved namespaces. */
  public static function path(mixed $value,bool $root=false): string {
    if($root&&$value==='')return '';
    if(!is_string($value)||strlen($value)>128||!preg_match('~^[a-z][a-z0-9-]{0,63}(?:/[a-z][a-z0-9-]{0,63}){0,2}$~D',$value)||in_array(explode('/',$value)[0],['home','404','index','site','plugins','templates','cms-admin','cms-assets','media','tracking','robots','sitemap'],true))self::fail('route');return $value;
  }
  /** Return built-in types with read-only additive defaults; no parameters or I/O. Empty labels are localized by the administrative/public presentation. */
  public static function defaults(): array {
    $common=['singular'=>'','plural'=>'','routeMode'=>'default','prefix'=>'','groups'=>true,'tags'=>true,'comments'=>false,'hierarchical'=>true];
    return ['page'=>['id'=>'page','kind'=>'page']+$common,'article'=>array_replace(['id'=>'article','kind'=>'article']+$common,['comments'=>true,'hierarchical'=>false])];
  }
  /** Validate closed type records and independent namespaces; receive map, return map or 422; built-ins cannot change rendering kind. */
  public static function validate(array $types): array {
    if(count($types)>20||!isset($types['page'],$types['article']))self::fail();$prefixes=[];
    foreach($types as $id=>$type){
      if(!self::identifier($id)||!is_array($type)||array_diff(array_keys($type),['id','singular','plural','kind','routeMode','prefix','groups','tags','comments','hierarchical'])||count($type)!==10||($type['id']??null)!==$id||!in_array($type['kind']??null,['page','article'],true)||!in_array($type['routeMode']??null,['default','root','custom'],true))self::fail();
      self::text($type['singular'],100,in_array($id,['page','article'],true));self::text($type['plural'],100,in_array($id,['page','article'],true));
      foreach(['groups','tags','comments','hierarchical'] as $field)if(!is_bool($type[$field]??null))self::fail();
      if(($id==='page'&&($type['kind']!=='page'||$type['comments']))||($id==='article'&&$type['kind']!=='article')||($type['kind']==='page'&&$type['comments'])||($type['kind']==='article'&&$type['hierarchical']))self::fail();
      self::path($type['prefix'],true);if($type['routeMode']==='custom'&&$type['prefix']==='')self::fail('route');
      $prefix=self::prefix($type);if($prefix!==null&&$prefix!==''){foreach($prefixes as $other)if($prefix===$other||str_starts_with($prefix,$other.'/')||str_starts_with($other,$prefix.'/'))self::fail('collision',409);$prefixes[]=$prefix;}
    }return $types;
  }
  /** Resolve one type's literal prefix; receive validated declaration, return path, empty root or null for the legacy Blog query contract; no I/O. */
  public static function prefix(array $type): ?string {
    if($type['routeMode']==='root')return '';if($type['routeMode']==='custom')return $type['prefix'];if($type['id']==='page')return '';if($type['id']==='article')return null;return 'posts/'.$type['id'];
  }
  /** Produce a relative canonical destination from a snapshot; receive types/snapshot, return validated local path/query or 422. */
  public static function route(array $types,array $snapshot): string {
    $type=$types[$snapshot['type']]??null;if($type===null)self::fail();$prefix=self::prefix($type);
    if($prefix===null)return 'plugins/blog/article?slug='.rawurlencode($snapshot['slug']);return ($prefix===''?'':$prefix.'/').rawurlencode($snapshot['slug']);
  }
}
