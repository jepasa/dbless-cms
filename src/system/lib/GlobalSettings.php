<?php
/** Canonical global site data, version-one migration, public projections and four-card administrative field definitions. */
declare(strict_types=1);
namespace DBLESSCMS;
final class GlobalSettings {
  /** Upgrade version-one values in memory; receives a document, returns v2 or untouched unknown version; never writes or loses the raw revision/backup. */
  public static function upgrade(array $data): array {
    if (($data['schema_version']??null)!==1) return $data;
    Schema::validate($data,json_decode((string)file_get_contents(dirname(__DIR__).'/config/schemas/site-v1.schema.json'),true,32,JSON_THROW_ON_ERROR));
    $s=$data['settings']??[];$brand=$data['brand']??[];$media=$data['media']??[];$og=$s['og']??[];$meta=$s['pageDefaults']??[];$c=$s['contact']??[];
    return ['schema_version'=>2,'template'=>$data['template']??Templates::DEFAULT_ID,'settings'=>[
      'siteLocale'=>SiteLocale::resolve($s)['tag'],'adminLocale'=>$s['adminLocale']??'pt-br',
      'name'=>$brand['name']??$meta['title']??'','slogan'=>$brand['role']??'','description'=>$meta['description']??$brand['tagline']??'',
      'baseUrl'=>$s['baseUrl']??'','canonical'=>$s['canonical']??'',
      'favicon'=>$s['favicon']??'templates/'.($data['template']??Templates::DEFAULT_ID).'/favicon.ico',
      'logo'=>$media['profile']??'','featuredImage'=>$media['hero']??$media['feature']??'',
      'author'=>$meta['author']??$og['author']??'','keywords'=>$meta['keywords']??'','type'=>$og['type']??'website',
      'image'=>$og['image']??'','imageAlt'=>$og['imageAlt']??'','imageType'=>$og['imageType']??'',
      'imageWidth'=>(int)($og['imageWidth']??0),'imageHeight'=>(int)($og['imageHeight']??0),
      'contact'=>['country'=>'','state'=>'','city'=>'','phone'=>$c['phone']??'','email'=>$c['email']??$brand['email']??'','responsible'=>'']
    ]];
  }
  /** Validate canonical URLs, public-root media paths and required identity; receives data, throws 422 on unsafe/malformed values, no I/O. */
  public static function validate(array $data): void {
    $s=$data['settings'];
    Cache::options($s['cache']??[]);
    if (trim($s['name'])==='') throw new HttpError(422,'Site name is required.','error.metadata');
    foreach (['baseUrl','canonical'] as $key) {
      $url=$s[$key];if ($key==='baseUrl'&&$url==='') continue;
      if (!filter_var($url,FILTER_VALIDATE_URL)||!in_array(parse_url($url,PHP_URL_SCHEME),['http','https'],true)||parse_url($url,PHP_URL_USER)!==null||parse_url($url,PHP_URL_PASS)!==null||parse_url($url,PHP_URL_QUERY)!==null||parse_url($url,PHP_URL_FRAGMENT)!==null) throw new HttpError(422,'Invalid site URL.','error.canonical');
    }
    foreach (['logo','featuredImage','image'] as $key) self::media($s[$key]);
    SiteFavicon::reference($s['favicon']);
  }
  /** Validate a relative public-root image reference; receives text, permits blank, throws 422 for traversal/schemes/control/encoding or executable extensions. */
  public static function media(string $path): void {
    if ($path==='') return;
    if (strlen($path)>300||str_starts_with($path,'/')||preg_match('/[\\\\%\x00-\x1F\x7F?#:]/u',$path)||preg_match('~(?:^|/)\.{1,2}(?:/|$)|//~',$path)||!preg_match('~^[\p{L}\p{N}_ .()/\-]+\.(?:png|jpe?g|gif|webp|avif|svg|ico)$~uiD',$path)) throw new HttpError(422,'Invalid public image path.','error.url');
  }
  /** Project current settings for template presentation; receives validated site/store/template, returns only derived view data without persisting legacy fields. */
  public static function presentation(array $site,JsonStore $store,string $template): array {
    $s=$site['settings'];$widgets=new Widgets($store,$template);$socials=[];
    foreach ($widgets->locations($store->templates->get($template)) as $key=>$location) if (str_starts_with($key,'social-')) {
      $link=$widgets->link($key);$record=$widgets->record($key);
      if ($link!==''&&$record!==null) $socials[]=['href'=>$link,'label'=>$record['description']?:$location['description'],'icon'=>'bi-link-45deg'];
    }
    return ['brand'=>['name'=>$s['name'],'role'=>$s['slogan'],'tagline'=>$s['description'],'email'=>$s['contact']['email'],'website'=>$s['baseUrl']?:$s['canonical'],'socials'=>$socials],
      'media'=>['hero'=>$s['featuredImage'],'profile'=>$s['logo'],'feature'=>$s['featuredImage']]];
  }
  /** Derive display-only selected theme from manifest-declared option paths; receives store/id, returns blank for unsupported themes; no mutation. */
  public static function theme(JsonStore $store,string $id): string {
    $manifest=$store->templates->get($id);$paths=$manifest['themeFields']??[];
    if ($paths===[]) return '';
    $options=(new Extensions($store))->options($id)['values'];$parts=[];
    foreach ($paths as $path) { $value=$options;foreach(explode('.',$path) as $key) $value=is_array($value)?($value[$key]??null):null;if(is_string($value)&&$value!=='')$parts[]=$value; }
    return implode(' / ',$parts);
  }
  /** Build the four-card UI contract; receives store/catalog, returns labels, read-only identity and dictionary options; no content writes. */
  public static function groups(JsonStore $store,Language $language): array {
    $site=$store->read('site')['data'];$id=$site['template'];
    return [
      ['title'=>'global.card.global','groups'=>[
        ['title'=>'global.group.language','fields'=>['siteLocale','adminLocale']],
        ['title'=>'global.group.presentation','fields'=>['name','slogan','description','baseUrl','canonical']]]],
      ['title'=>'global.card.identity','groups'=>[
        ['title'=>'global.group.appearance','fields'=>['@template','@theme','favicon','logo','featuredImage']],
        ['title'=>'global.group.metadata','fields'=>['author','keywords','type','image','imageAlt','imageType','imageWidth','imageHeight']]]],
      ['title'=>'global.card.contact','groups'=>[
        ['title'=>'global.group.address','fields'=>['contact.country','contact.state','contact.city']],
        ['title'=>'global.group.communication','fields'=>['contact.phone','contact.email','contact.responsible']]]],
      ['title'=>'global.card.cache','groups'=>[
        ['title'=>'global.group.cache','fields'=>['cache.enabled','cache.development','cache.ttl','cache.maxMiB','cache.maxEntries']]]]
    ];
  }
  /** Export referenced public images only; receives store/new stage, rejects links, disguised executable data or missing media, writes no private state. */
  public static function export(JsonStore $store,string $target): void {
    $settings=$store->read('site')['data']['settings'];$root=dirname($store->config->root).'/public_html';
    foreach(array_unique([$settings['logo'],$settings['featuredImage'],$settings['image']]) as $reference){
      if($reference==='')continue;self::media($reference);$file=Lifecycle::path($root.'/'.$reference);
      if(!is_file($file)||filesize($file)>12582912)throw new \RuntimeException('Global image unavailable.');
      $bytes=file_get_contents($file);if($bytes===false)throw new \RuntimeException('Global image read failed.');
      if(preg_match('~<\?(?:php|=)|<\s*(?:script|iframe|object|embed|html)\b~i',$bytes))throw new \RuntimeException('Unsafe global image.');
      $extension=strtolower(pathinfo($reference,PATHINFO_EXTENSION));
      if($extension==='svg')ExtensionMedia::icon($file);
      elseif($extension==='ico')ExtensionMedia::ico($file);
      else {
        $expected=match($extension){'jpg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif','avif'=>'image/avif',default=>''};
        $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file);$size=@getimagesize($file);
        if($mime!==$expected||$size===false||$size[0]*$size[1]>40000000||($size['mime']??'')!==$mime)throw new \RuntimeException('Invalid global image.');
      }
      $destination=Lifecycle::path($target.'/public_html/'.$reference);
      if(!is_dir(dirname($destination))&&!mkdir(dirname($destination),0750,true))throw new \RuntimeException('Global image directory unavailable.');
      if(file_put_contents($destination,$bytes)!==strlen($bytes))throw new \RuntimeException('Global image export failed.');
    }
  }
}
