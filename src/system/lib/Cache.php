<?php
/** Private bounded structural JSON cache; atomic writes, one nonblocking lock, observable counters and safe dynamic fallback. Never caches rendered HTML, sessions or tokens. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Cache {
  public const DEFAULTS=['enabled'=>false,'development'=>false,'ttl'=>300,'maxMiB'=>16,'maxEntries'=>500];
  private bool $failed=false;
  private string $directory;
  private array $options;
  /** Receive trusted config and validated options; initialize memory only, reject malformed settings, no filesystem writes. */
  public function __construct(private readonly Config $config,array $options=[]) {
    $this->options=self::options($options);$this->directory=rtrim($config->data['storage'],'/').'/cache';
  }
  /** Merge additive defaults; receive an optional settings map, return normalized values or HTTP 422 for unknown keys/types/ranges; no I/O. */
  public static function options(array $options): array {
    if (array_diff_key($options,self::DEFAULTS)!==[]) throw new HttpError(422,'Invalid cache settings.','cache.invalid');
    $options=array_replace(self::DEFAULTS,$options);
    foreach (['enabled','development'] as $key) if (!is_bool($options[$key])) throw new HttpError(422,'Invalid cache settings.','cache.invalid');
    foreach (['ttl'=>[1,86400],'maxMiB'=>[1,256],'maxEntries'=>[1,2000]] as $key=>[$minimum,$maximum]) if (!is_int($options[$key])||$options[$key]<$minimum||$options[$key]>$maximum) throw new HttpError(422,'Invalid cache settings.','cache.invalid');
    return $options;
  }
  /** Return whether caching is effective in this environment; no parameters, writes or errors. */
  public function enabled(): bool {return $this->options['enabled']&&($this->config->production||$this->options['development']);}
  /** Validate all ancestors and the fixed child name; receive path, return safe path or cache-specific failure; no links followed. */
  private function path(string $path): string {
    try {return Lifecycle::path($path);} catch (\RuntimeException) {throw new CacheFailure('Unsafe cache path.');}
  }
  /** Acquire a private, nonblocking exclusive lock; return stream or cache-specific failure, create only the fixed cache directory. */
  private function lock(): mixed {
    $directory=$this->path($this->directory);
    if (!is_dir($directory)&&!@mkdir($directory,0700,true)&&!is_dir($directory)) throw new CacheFailure('Cache directory unavailable.');
    $file=$this->path($directory.'/cache.lock');$stream=@fopen($file,'c+');
    if ($stream===false) throw new CacheFailure('Cache lock unavailable.');
    if (!@chmod($file,0600)||!flock($stream,LOCK_EX|LOCK_NB)) {fclose($stream);throw new CacheFailure('Cache busy or unavailable.');}
    return $stream;
  }
  /** Decode bounded regular cache JSON; receive file/limit, return array or null if absent, fail explicitly for malformed/unsafe files. */
  private function read(string $file,int $limit=1048576): ?array {
    $this->path($file);if (!file_exists($file)) return null;
    if (!is_file($file)) throw new CacheFailure('Cache file unavailable.');
    $raw=@file_get_contents($file,false,null,0,$limit+1);
    if ($raw===false||strlen($raw)>$limit) throw new CacheFailure('Cache file exceeds limits.');
    try {$data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);} catch (\JsonException) {throw new CacheFailure('Invalid cache JSON.');}
    if (!is_array($data)) throw new CacheFailure('Invalid cache record.');return $data;
  }
  /** Atomically write a bounded JSON record under the held lock; receive safe path/array, return nothing, clean temporary files on any failure. */
  private function write(string $file,array $data): void {
    $this->path($file);$bytes=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);
    $temporary=@tempnam($this->directory,'.cache-');if ($temporary===false) throw new CacheFailure('Cache write unavailable.');
    try {
      if (!@chmod($temporary,0600)||@file_put_contents($temporary,$bytes)!==strlen($bytes)||!@rename($temporary,$file)) throw new CacheFailure('Cache publication unavailable.');
    } finally {if (is_file($temporary)&&!@unlink($temporary)) error_log('DBLESS-CMS cache temporary cleanup failed');}
  }
  /** Read the bounded rolling 24-hour measurement window; no arguments, return counters, never include content, URLs, cookies or user data. */
  private function counters(): array {
    $data=$this->read($this->directory.'/metrics.json',2048);$now=time();
    if ($data===null||!is_int($data['since']??null)||$data['since']>$now||$now-$data['since']>=86400) return ['since'=>$now,'updated'=>null,'hits'=>0,'misses'=>0];
    foreach (['hits','misses'] as $key) if (!is_int($data[$key]??null)||$data[$key]<0||$data[$key]>1000000000) throw new CacheFailure('Invalid cache metrics.');
    if (!is_int($data['updated']??null)) throw new CacheFailure('Invalid cache metrics.');return $data;
  }
  /** List fixed-name regular entries and metadata under lock; return bounded inventory, reject links/unexpected files, no deletion. */
  private function entries(): array {
    $files=@scandir($this->directory);if ($files===false||count($files)>2005) throw new CacheFailure('Cache inventory unavailable.');$entries=[];
    foreach ($files as $name) {
      if (in_array($name,['.','..','cache.lock','metrics.json'],true)) continue;
      if (!preg_match('/^[a-f0-9]{64}\.json$/D',$name)) throw new CacheFailure('Unexpected cache file.');
      $file=$this->path($this->directory.'/'.$name);$stat=@lstat($file);
      if ($stat===false||!is_file($file)) throw new CacheFailure('Invalid cache entry.');
      $entries[$file]=['bytes'=>$stat['size'],'modified'=>$stat['mtime']];
    }
    return $entries;
  }
  /** Remove one fixed-path entry under lock; receive path, return nothing or a cache-specific I/O failure. */
  private function remove(string $file): void {if (!@unlink($this->path($file))) throw new CacheFailure('Cache cleanup unavailable.');}
  /** Report once per instance without paths/content; no inputs/return, flags fallback availability for the dashboard. */
  private function failure(): void {if (!$this->failed) error_log('DBLESS-CMS cache unavailable; dynamic data used');$this->failed=true;}
  /** Check a versioned entry for accidental corruption; receive envelope, return a deterministic digest or CacheFailure for unrepresentable JSON; this is not authentication against a local code owner. */
  private static function digest(array $record): string {
    try {return hash('sha256',json_encode([$record['version'],$record['expires'],$record['value']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));}
    catch (\JsonException) {throw new CacheFailure('Cache value is not representable as JSON.');}
  }
  /** Remember structural JSON by source/format identity; callers must revalidate live contracts on every result, including hits. Receive opaque key/producer, return array. Producer errors propagate; cache I/O degrades to dynamic data and never hides content errors. */
  public function remember(string $key,callable $producer): array {
    if (!$this->enabled()) return $producer();
    $stream=null;$value=null;
    try {
      $stream=$this->lock();$file=$this->directory.'/'.hash('sha256',$key.':'.json_encode($this->options,JSON_THROW_ON_ERROR)).'.json';$record=$this->read($file);
      $metrics=$this->counters();$now=time();
      if ($record!==null&&($record['version']??null)===1&&is_int($record['expires']??null)&&$record['expires']>$now&&$record['expires']<=$now+$this->options['ttl']&&is_array($record['value']??null)&&is_string($record['digest']??null)&&hash_equals(self::digest($record),$record['digest'])) {
        $value=$record['value'];$metrics['hits']=min(1000000000,$metrics['hits']+1);
      } else {
        // Only the data producer may throw application/validation exceptions; those must reach the caller unchanged.
        $value=$producer();$metrics['misses']=min(1000000000,$metrics['misses']+1);
        $new=['version'=>1,'expires'=>$now+$this->options['ttl'],'value'=>$value];$new['digest']=self::digest($new);$bytes=strlen(json_encode($new,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
        $entries=$this->entries();
        foreach ($entries as $path=>$entry) {
          $item=$this->read($path);
          if (!is_int($item['expires']??null)||$item['expires']<=$now||$path===$file) {$this->remove($path);unset($entries[$path]);}
        }
        uasort($entries,static fn(array $a,array $b): int=>$a['modified']<=>$b['modified']);
        $total=array_sum(array_column($entries,'bytes'));
        while ($entries!==[]&&(count($entries)>=$this->options['maxEntries']||$total+$bytes>$this->options['maxMiB']*1048576)) {$old=array_key_first($entries);$total-=$entries[$old]['bytes'];$this->remove($old);unset($entries[$old]);}
        if ($bytes<=min(1048576,$this->options['maxMiB']*1048576)) $this->write($file,$new);
      }
      $metrics['updated']=$now;$this->write($this->directory.'/metrics.json',$metrics);
      return $value;
    } catch (CacheFailure) {$this->failure();return $value??$producer();}
    finally {if (is_resource($stream)) {flock($stream,LOCK_UN);fclose($stream);}}
  }
  /** Return current settings/status, entry usage and real measurements; no parameters, never creates a missing cache, uses unavailable/disabled/no-data explicitly. */
  public function snapshot(): array {
    $result=['status'=>$this->enabled()?'enabled':'disabled','options'=>$this->options,'entries'=>0,'expired'=>0,'bytes'=>0,'metrics'=>null];$stream=null;
    try {
      $this->path($this->directory);if (!is_dir($this->directory)) return $result;
      $stream=$this->lock();$entries=$this->entries();
      foreach ($entries as $file=>$entry) {$record=$this->read($file);$result['entries']++;$result['bytes']+=$entry['bytes'];if (($record['expires']??0)<=time()) $result['expired']++;}
      $metrics=$this->counters();if ($metrics['updated']!==null) $result['metrics']=$metrics;
      return $result;
    } catch (CacheFailure) {$this->failure();$result['status']='unavailable';return $result;}
    finally {if (is_resource($stream)) {flock($stream,LOCK_UN);fclose($stream);}}
  }
  /** Purge all owned entries and reset counters; caller must enforce auth/CSRF. Return success flag, never touch contents, sessions or non-cache paths. */
  public function clear(): bool {
    $stream=null;
    try {
      $this->path($this->directory);if (!is_dir($this->directory)) return true;
      $stream=$this->lock();foreach ($this->entries() as $file=>$entry) $this->remove($file);
      $metrics=$this->path($this->directory.'/metrics.json');if (file_exists($metrics)) $this->remove($metrics);return true;
    } catch (CacheFailure) {$this->failure();return false;}
    finally {if (is_resource($stream)) {flock($stream,LOCK_UN);fclose($stream);}}
  }
}
