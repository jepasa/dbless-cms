<?php
/** Image provenance catalog and minimal public credits: stable identities survive managed moves/trash, private evidence never enters public data or export. */
declare(strict_types=1);
namespace DBLESSCMS;
final class MediaRights {
  /** Receive trusted store; no reads/writes until an explicit operation. */
  public function __construct(private readonly JsonStore $store) {}
  /** Validate literal text and safe relative path components without following a user path; receive path/allow-empty, return void or 422. */
  private static function relative(string $path,bool $empty=false): void {
    if($empty&&$path==='')return;if(strlen($path)>500||str_starts_with($path,'/')||preg_match('/[\\\\%\x00-\x1F\x7F:?<>]/u',$path))throw new HttpError(422,'Invalid media reference.','fm.error.path');foreach(explode('/',$path) as $part)if(!preg_match('/^[\pL\pN][\pL\pN _().-]*$/uD',$part)||str_contains($part,'..')||trim($part)!==$part)throw new HttpError(422,'Invalid media reference.','fm.error.path');
  }
  /** Validate provenance URL without fetching; receive value, return void or 422; empty remains unknown rather than an invented license. */
  private static function url(string $url): void {if($url!==''&&(preg_match('/[\x00-\x20\x7F\\\\]/',$url)||!filter_var($url,FILTER_VALIDATE_URL)||!in_array(parse_url($url,PHP_URL_SCHEME),['https','http'],true)||parse_url($url,PHP_URL_USER)!==null||parse_url($url,PHP_URL_PASS)!==null))throw new HttpError(422,'Invalid source/license URL.','fm.error.rights');}
  /** Validate complete bounded rights catalog; receive document, return unchanged validated state or 422, no hidden migration or automatic legal approval. */
  public static function validate(array $data): array {
    if(count($data)!==2||($data['schema_version']??null)!==1||!is_array($data['assets']??null)||count($data['assets'])>1000)throw new HttpError(422,'Invalid media rights catalog.','fm.error.rights');$locations=[];
    foreach($data['assets'] as $id=>$record){$keys=['id','scope','root','path','sha256','width','height','author','source','license','licenseUrl','credit','evidence','altSuggestion','review','trash','parent','derivativesAllowed'];if(!is_array($record)||count($record)!==count($keys)||array_diff(array_keys($record),$keys)||!preg_match('/^asset-[a-f0-9]{24}$/D',(string)$id)||$record['id']!==$id||!in_array($record['scope'],['public','private'],true)||!is_string($record['sha256'])||!preg_match('/^[a-f0-9]{64}$/D',$record['sha256'])||!is_int($record['width'])||!is_int($record['height'])||$record['width']<1||$record['height']<1||$record['width']*$record['height']>40000000||!is_bool($record['derivativesAllowed']))throw new HttpError(422,'Invalid media identity.','fm.error.rights');
      foreach(['root'=>240,'path'=>240,'author'=>300,'source'=>2048,'licenseUrl'=>2048,'credit'=>1000,'evidence'=>1000,'altSuggestion'=>1000,'parent'=>30] as $field=>$limit)if(!is_string($record[$field])||strlen($record[$field])>$limit||!preg_match('//u',$record[$field])||preg_match('/[\x00-\x1F\x7F<>]/u',$record[$field]))throw new HttpError(422,'Invalid rights text.','fm.error.rights');
      self::relative($record['root'],true);self::relative($record['path']);if(!preg_match('/\.(?:jpe?g|png|gif|webp|ico)$/iD',$record['path']))throw new HttpError(422,'Only managed image provenance is supported.','fm.error.rights');self::url($record['source']);self::url($record['licenseUrl']);
      if(!in_array($record['license'],['unknown','owned','permission','cc0','cc-by','cc-by-sa','other'],true)||!in_array($record['review'],['unreviewed','approved','restricted'],true)||!is_string($record['trash'])||$record['trash']!==''&&!preg_match('/^[a-f0-9]{32}$/D',$record['trash'])||$record['parent']!==''&&!preg_match('/^asset-[a-f0-9]{24}$/D',$record['parent']))throw new HttpError(422,'Invalid rights decision.','fm.error.rights');
      if($record['review']==='approved'&&(trim($record['author'])===''||$record['license']==='unknown'))throw new HttpError(422,'Approval requires authorship and a rights reference.','fm.error.rights');
      if($record['trash']===''){$key=$record['scope'].'/'.$record['root'].'/'.$record['path'];if(isset($locations[$key]))throw new HttpError(422,'Duplicate media location.','fm.error.rights');$locations[$key]=true;}
    }return $data;
  }
  /** Return fixed revisioned Images catalog; history is private and never exported, no file contents or evidence automatically collected. */
  public function repository(): ExtensionDocument {return new ExtensionDocument($this->store,'files-manager/rights.json',['schema_version'=>1,'assets'=>[]],self::validate(...));}
  /** Find current metadata by authorized scope/root/path; receive strings, return record or null without reading image contents or exposing absolute paths. */
  public function find(string $scope,string $root,string $path): ?array {foreach($this->repository()->read()['data']['assets'] as $record)if($record['scope']===$scope&&$record['root']===$root&&$record['path']===$path&&$record['trash']==='')return $record;return null;}
  /** Save explicit administrator provenance against a current file description and CAS; receive descriptor/fields/revision, return catalog snapshot or 409/422, never trusts a browser checksum. */
  public function save(array $file,array $fields,string $revision): array {
    return $this->repository()->change($revision,function(array $data)use($file,$fields):array{$old=null;foreach($data['assets'] as $record)if($record['scope']===$file['scope']&&$record['root']===$file['root']&&$record['path']===$file['path']&&$record['trash']==='')$old=$record;
      $id=$old['id']??'asset-'.bin2hex(random_bytes(12));$record=['id'=>$id,'scope'=>$file['scope'],'root'=>$file['root'],'path'=>$file['path'],'sha256'=>$file['sha256'],'width'=>$file['width'],'height'=>$file['height'],'trash'=>'','parent'=>$old['parent']??$file['parent']??''];
      foreach(['author','source','license','licenseUrl','credit','evidence','altSuggestion','review','derivativesAllowed'] as $field)$record[$field]=$fields[$field]??($field==='derivativesAllowed'?false:'');if($record['review']==='approved'&&$record['license']!=='owned'&&$record['licenseUrl']===''&&$record['evidence']==='')throw new HttpError(422,'A rights reference is required before approval.','fm.error.rights');$data['assets'][$id]=$record;return $data;});
  }
  /** Update known metadata for one filesystem move/trash/restore under the caller's shared lock; receive scope/root/old/new/trash identifiers, return void; unchanged catalog is not rewritten. */
  public function moved(string $scope,string $root,string $from,string $to,?string $trash=null,?string $restore=null): void {
    $repo=$this->repository();$snapshot=$repo->read();$changed=false;$data=$snapshot['data'];foreach($data['assets'] as &$record){if($record['scope']!==$scope||$record['root']!==$root)continue;if($restore!==null){if($record['trash']!==$restore)continue;$record['trash']='';$changed=true;continue;}if($record['trash']!==''||!($record['path']===$from||str_starts_with($record['path'],$from.'/')))continue;$record['path']=$to.substr($record['path'],strlen($from));if($trash!==null)$record['trash']=$trash;$changed=true;}unset($record);if($changed)$repo->change($snapshot['revision'],static fn(array $old):array=>$data);
  }
  /** Resolve a recorded regular public image within the public root, checking current bytes; receive record, return relative path or null for private/trash/unreviewed/changed content. */
  private function publicPath(array $record): ?string {
    if($record['scope']!=='public'||$record['trash']!==''||$record['review']!=='approved')return null;$relative=($record['root']!==''?$record['root'].'/':'').$record['path'];
    try{$file=Lifecycle::path(dirname($this->store->config->root).'/public_html/'.$relative);}catch(\RuntimeException){return null;}if(!is_file($file)||!hash_equals($record['sha256'],hash_file('sha256',$file)))return null;return $relative;
  }
  /** Return minimal approved public metadata by stable ID; receive ID/base, return public-only values or 404, never fetch source URLs or serialize evidence/private parent IDs. */
  public function asset(string $id,string $base): array {
    $record=$this->repository()->read()['data']['assets'][$id]??null;$relative=$record!==null?$this->publicPath($record):null;if($relative===null)throw new HttpError(404,'Public media unavailable.','fm.error.rights');$result=array_intersect_key($record,array_fill_keys(['id','author','source','license','licenseUrl','credit','altSuggestion','width','height'],true));$result['src']=$base.'/'.str_replace('%2F','/',rawurlencode($relative));return $result;
  }
  /** Append visible source/author labels beside approved local images in full public HTML; receive bytes/base, return unchanged without public metadata, no alt overwrite or policy against trusted owner code. */
  public function decorate(string $html,string $base): string {
    if(!preg_match('~\A\s*(?:(?:<!--[\s\S]*?-->|<!doctype\s+html[^>]*>)\s*)*<html\b~i',$html)||!preg_match('~</body\s*>~i',$html))return $html;foreach(headers_list() as $header)if(str_starts_with(strtolower($header),'content-disposition:')||str_starts_with(strtolower($header),'content-type:')&&!preg_match('~^content-type:\s*text/html(?:;|$)~i',$header))return $html;
    $records=[];foreach($this->repository()->read()['data']['assets'] as $record)if($record['scope']==='public'&&$record['trash']===''&&$record['review']==='approved')$records[($record['root']!==''?$record['root'].'/':'').$record['path']]=$record;if($records===[])return $html;
    $dom=new \DOMDocument('1.0','UTF-8');$previous=libxml_use_internal_errors(true);try{$dom->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);$count=0;foreach(iterator_to_array($dom->getElementsByTagName('img')) as $image){$src=rawurldecode($image->getAttribute('src'));if(str_starts_with($src,rtrim($base,'/').'/'))$src=substr($src,strlen(rtrim($base,'/'))+1);elseif(str_starts_with($src,'/')&&!str_starts_with($src,'//'))$src=ltrim($src,'/');elseif(preg_match('~^[a-z]+:~i',$src)||str_starts_with($src,'//'))continue;$record=$records[$src]??null;if($record===null||$this->publicPath($record)===null)continue;
        $anchor=$image;while($anchor->parentNode instanceof \DOMElement&&in_array(strtolower($anchor->parentNode->nodeName),['picture','a','button'],true))$anchor=$anchor->parentNode;if($anchor->nextSibling instanceof \DOMElement&&$anchor->nextSibling->hasAttribute('data-image-credit'))continue;
        $credit=$dom->createElement('span');$credit->setAttribute('class','cms-image-credit');$credit->setAttribute('data-image-credit',$record['id']);$credit->appendChild($dom->createTextNode($record['author'].($record['credit']!==''?' — '.$record['credit']:'')));
        foreach(['source'=>'Source','licenseUrl'=>$record['license']] as $field=>$label)if($record[$field]!==''){$credit->appendChild($dom->createTextNode(' · '));$link=$dom->createElement('a');$link->setAttribute('href',$record[$field]);$link->appendChild($dom->createTextNode($label));$credit->appendChild($link);}$anchor->parentNode->insertBefore($credit,$anchor->nextSibling);$count++;
      }if($count===0)return $html;$head=$dom->getElementsByTagName('head')->item(0);if($head!==null){$link=$dom->createElement('link');$link->setAttribute('rel','stylesheet');$link->setAttribute('href',asset('cms-assets/media.css',$base));$head->appendChild($link);}foreach(iterator_to_array($dom->childNodes) as $child)if($child->nodeType===XML_PI_NODE)$dom->removeChild($child);return (string)$dom->saveHTML();
    }finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
  }
  /** Export only approved records for images actually present in the new package; receive target, omit evidence/private parent/trash/history and never copy unrelated media. */
  public function export(string $target): void {
    $data=['schema_version'=>1,'assets'=>[]];foreach($this->repository()->read()['data']['assets'] as $id=>$record){$relative=$this->publicPath($record);if($relative===null)continue;$file=Lifecycle::path($target.'/public_html/'.$relative);if(!is_file($file)||!hash_equals($record['sha256'],hash_file('sha256',$file)))continue;$record['evidence']='';$record['parent']='';$data['assets'][$id]=$record;}
    // Keep the operator's existing review decision; private permission evidence is omitted, never replaced with a public document or invented license.
    ExtensionDocument::atomic($target.'/system/storage/contents/files-manager/rights.json',ExtensionDocument::encode($data));
  }
}
