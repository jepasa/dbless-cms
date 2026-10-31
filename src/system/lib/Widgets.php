<?php
/** Template widget contract and read-only value provider; values never execute HTML, PHP, CSS or JavaScript. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Widgets {
  private ?array $records=null;
  /** Keep store/template for deferred reads; receives services/identifier, no filesystem mutation. */
  public function __construct(private readonly JsonStore $store,private readonly string $template) {}
  /** Validate static key/description pairs; receives a manifest, returns positions or throws 422 for dynamic/unsafe definitions, no I/O. */
  public static function locations(array $manifest): array {
    $locations=$manifest['widgetLocations']??[];
    if (!is_array($locations)||(array_is_list($locations)&&$locations!==[])||count($locations)>100) throw new HttpError(422,'Invalid widget positions.','error.manifest');
    foreach($locations as $key=>$description) if(!is_string($key)||!preg_match('/^[a-z][a-z0-9-]{0,63}$/D',$key)||!is_string($description)||trim($description)===''||strlen($description)>500||preg_match('//u',$description)!==1||preg_match('/[\x00-\x1F\x7F<>]/u',$description)) throw new HttpError(422,'Invalid widget position.','error.manifest');
    return $locations;
  }
  /** Resolve one available active-template record; receives key, returns data or null for missing/disabled/trash; real corruption propagates, no writes. */
  public function record(string $key): ?array {
    if(!isset(self::locations($this->store->templates->get($this->template))[$key])) return null;
    if($this->records===null){
      $this->records=[];$registry=new Plugins($this->store);
      if(isset($registry->all()['widgets'])&&$registry->state('widgets')['enabled']){
        $registry->instance('widgets');$this->records=(new WidgetsStore($this->store))->read()['data']['widgets'];
      }
    }
    foreach($this->records as $record) if(!$record['deleted']&&$record['template']===$this->template&&$record['key']===$key) return $record;
    return null;
  }
  /** Return a plain value or caller fallback; receives key/default, returns unescaped text for contextual escaping by the template; no mutation. */
  public function value(string $key,string $default=''): string {return $this->record($key)['value']??$default;}
  /** Validate a widget link at output; receives key, returns local/HTTP/mail/telephone URI or blank; never trusts a plain text value as an executable destination. */
  public function link(string $key): string {
    $value=$this->value($key);
    if($value===''||preg_match('/[\x00-\x20\x7F\\\\]/',$value)||str_contains(rawurldecode($value),'..')) return '';
    if(str_starts_with($value,'/')&&!str_starts_with($value,'//')) return $value;
    $scheme=strtolower((string)parse_url($value,PHP_URL_SCHEME));
    if(in_array($scheme,['http','https'],true)&&filter_var($value,FILTER_VALIDATE_URL)&&parse_url($value,PHP_URL_USER)===null&&parse_url($value,PHP_URL_PASS)===null) return $value;
    if($scheme==='mailto'&&filter_var(substr($value,7),FILTER_VALIDATE_EMAIL)) return $value;
    if($scheme==='tel'&&preg_match('/^tel:\+?[0-9().-]{3,40}$/D',$value)) return $value;
    return '';
  }
}
