<?php
/** Armazenamento do gerenciador: contenção, MIME, cotas, revisão, lock e lixeira privada recuperável. */
declare(strict_types=1);
namespace CMS2JSON;
final class ManagedFiles {
  private const TYPES=['jpg'=>['image/jpeg'],'jpeg'=>['image/jpeg'],'png'=>['image/png'],'gif'=>['image/gif'],'webp'=>['image/webp'],'pdf'=>['application/pdf'],'txt'=>['text/plain']];
  private string $anchor;
  private string $root;
  private string $state;
  /** Resolve escopo e raízes sem escrita; recebe contexto/escopo; rejeita configurações, symlinks e escapes. */
  public function __construct(private readonly PluginContext $context, private readonly string $scope) {
    if (!in_array($scope,['public','private'],true)) self::fail('scope');
    $this->anchor=$scope==='public'?dirname($context->config->root).'/public_html':$context->config->data['storage'].'/files';
    $relative=$context->settings['uploads'][$scope]; self::relative($relative);
    $this->root=rtrim($this->anchor,'/').($relative===''?'':'/'.$relative);
    $this->state=$context->config->data['storage'].'/plugins/files-manager';
    self::noLinks($this->root); self::noLinks($this->state);
    if ($scope==='public') $this->protect($relative);
  }
  /** Lança erro localizado seguro; recebe chave/status, não expõe caminhos absolutos. */
  private static function fail(string $key,int $status=422): never { throw new HttpError($status,'Operação de arquivo recusada.','fm.error.'.$key); }
  /** Valida caminho relativo segmentado; recebe string, não resolve nem grava; traversal e nomes ocultos falham. */
  public static function relative(string $path): void {
    if (strlen($path)>240 || substr_count($path,'/')>11) self::fail('path');
    if ($path==='') return;
    foreach (explode('/',$path) as $part) self::name($part);
  }
  /** Exige basename UTF-8 simples sem escapes/dotfiles; recebe nome e falha antes de qualquer escrita. */
  private static function name(string $name): void {
    if ($name==='' || strlen($name)>120 || !preg_match('/^[\pL\pN][\pL\pN _().-]*$/uD',$name) || str_contains($name,'..') || trim($name)!==$name || str_ends_with($name,'.')) self::fail('path');
  }
  /** Rejeita symlink em cada ancestral existente; recebe caminho absoluto, sem efeitos. */
  private static function noLinks(string $path): void {
    $current='';
    foreach (explode('/',ltrim($path,'/')) as $part) { if ($part==='.' || $part==='..') self::fail('path'); $current.='/'.$part; if (is_link($current)) self::fail('link'); }
  }
  /** Impede uso das áreas de código público e arquivos de sistema; recebe caminho relativo à raiz pública. */
  private function protect(string $relative): void {
    $first=strtolower(explode('/',$relative)[0]);
    if (in_array($first,['cms-admin','templates','plugins','index.php','robots.txt','sitemap.xml'],true)) self::fail('protected',403);
  }
  /** Resolve alvo contido validado; recebe relativo/existência, retorna caminho absoluto; não segue links. */
  private function path(string $relative,bool $exists=true): string {
    self::relative($relative);
    $path=$this->root.($relative===''?'':'/'.$relative); self::noLinks($path);
    if ($this->scope==='public') {
      $prefix=$this->context->settings['uploads']['public'];
      $this->protect(($prefix===''?'':$prefix.'/').$relative);
    }
    if ($exists && !file_exists($path)) self::fail('missing',404);
    if (file_exists($path) && !is_dir($path) && !is_file($path)) self::fail('type');
    return $path;
  }
  /** Filtra entradas administráveis por nome/extensão/namespace; recebe relativo e flag pasta, retorna bool. */
  private function visible(string $relative,bool $directory): bool {
    try { $this->path($relative); if (!$directory && !isset(self::TYPES[strtolower(pathinfo($relative,PATHINFO_EXTENSION))])) return false; return true; }
    catch (HttpError) { return false; }
  }
  /** Lista diretório e revisão de bytes/identidade imediatos; recebe relativo, retorna JSON sem caminhos privados. */
  public function listing(string $relative): array {
    $directory=$this->path($relative,false); $entries=[]; $fingerprint=[$this->context->revision,$this->scope,$this->root,$relative];
    if (file_exists($directory) && !is_dir($directory)) self::fail('directory');
    if (is_dir($directory)) {
      $names=scandir($directory); if ($names===false) self::fail('io',503);
      foreach ($names as $name) {
        if ($name==='.' || $name==='..') continue;
        $rel=$relative===''?$name:$relative.'/'.$name; $file=$directory.'/'.$name;
        if (is_link($file)) continue;
        $folder=is_dir($file); if (!$this->visible($rel,$folder)) continue;
        $stat=stat($file); if ($stat===false) self::fail('io',503);
        $fingerprint[]=[$name,$stat['ino'],$stat['mtime'],$stat['ctime'],$stat['size'],$folder?'dir':hash_file('sha256',$file)];
        $entries[]=['name'=>$name,'path'=>$rel,'directory'=>$folder,'size'=>$folder?0:$stat['size'],'modified'=>gmdate(DATE_ATOM,$stat['mtime'])];
        if (count($entries)>1000) self::fail('quota',413);
      }
    }
    usort($entries,static fn(array $a,array $b): int => ($b['directory']<=>$a['directory']) ?: strnatcasecmp($a['name'],$b['name']));
    return ['entries'=>$entries,'path'=>$relative,'scope'=>$this->scope,'revision'=>hash('sha256',json_encode($fingerprint,JSON_THROW_ON_ERROR)),'maxBytes'=>10485760];
  }
  /** Valida árvore inteira antes de mover/excluir; recebe caminho e relativo; retorna quantidade/bytes e rejeita arquivos estranhos. */
  private function tree(string $path,string $relative,int $depth=0): array {
    if ($depth>12 || is_link($path)) self::fail('link');
    if (!is_dir($path)) {
      if (!$this->visible($relative,false)) self::fail('protected',403);
      return [1,filesize($path)];
    }
    $count=1; $bytes=0;
    foreach (scandir($path) ?: [] as $name) {
      if ($name==='.' || $name==='..') continue;
      $rel=$relative===''?$name:$relative.'/'.$name;
      self::name($name);
      [$n,$b]=$this->tree($path.'/'.$name,$rel,$depth+1); $count+=$n; $bytes+=$b;
      if ($count>1000 || $bytes>262144000) self::fail('quota',413);
    }
    return [$count,$bytes];
  }
  /** Validate PNG framing/CRCs and return scan bytes excluding opaque C2PA caBX metadata; receives complete bytes, never rewrites the file, throws 422 on corruption/trailing data, and does not authenticate C2PA claims. */
  private static function pngScan(string $bytes): string {
    if (!str_starts_with($bytes,"\x89PNG\r\n\x1a\n")) self::fail('type');
    $offset=8;$total=strlen($bytes);$scan=substr($bytes,0,8);$header=false;$data=false;$closed=false;$end=false;
    while ($offset<$total) {
      if ($total-$offset<12) self::fail('type');
      $length=unpack('N',substr($bytes,$offset,4))[1];$type=substr($bytes,$offset+4,4);
      if ($length>$total-$offset-12 || !preg_match('/^[A-Za-z]{4}$/D',$type)) self::fail('type');
      $payload=substr($bytes,$offset+8,$length);$crc=substr($bytes,$offset+8+$length,4);
      if (!hash_equals(hash('crc32b',$type.$payload,true),$crc)) self::fail('type');
      if (!$header && ($type!=='IHDR'||$length!==13)) self::fail('type');
      if ($type==='IHDR') {if ($header) self::fail('type');$header=true;}
      if ($type==='IDAT') {if ($closed) self::fail('type');$data=true;} elseif ($data) $closed=true;
      if ($type==='IEND') {if ($length!==0||!$data||$offset+12!==$total) self::fail('type');$end=true;}
      if (!in_array($type,['IHDR','PLTE','IDAT','IEND'],true) && ctype_upper($type[0])) self::fail('type');
      // caBX stores C2PA assertions, including a harmless embedded SVG icon; it is not rendered as SVG by a PNG viewer.
      if ($type!=='caBX') $scan.=substr($bytes,$offset,$length+12);
      $offset+=$length+12;
      if ($end) break;
    }
    if (!$end) self::fail('type');
    return $scan;
  }
  /** Valida MIME/extensão/conteúdo limitado; recebe arquivo/nome, retorna MIME ou 422; nunca executa dados. */
  private function mime(string $file,string $name): string {
    $extension=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    if (!isset(self::TYPES[$extension])) self::fail('type');
    $size=filesize($file); if ($size===false || $size===0 || $size>10485760) self::fail('size',413);
    $mime=(new \finfo(FILEINFO_MIME_TYPE))->file($file);
    if (!in_array($mime,self::TYPES[$extension],true)) self::fail('type');
    if (str_starts_with($mime,'image/')) {
      $image=@getimagesize($file);
      if ($image===false || $image[0]*$image[1]>40000000 || ($image['mime']??'')!==$mime) self::fail('type');
    }
    $bytes=file_get_contents($file); if ($bytes===false) self::fail('io',503);
    if (preg_match('/<\?(?:php|=)|<script\b|<html\b/i',$bytes)) self::fail('type');
    $scan=$mime==='image/png'?self::pngScan($bytes):$bytes;
    if (preg_match('/<svg\b/i',$scan)) self::fail('type');
    if ($mime==='text/plain' && (!preg_match('//u',$bytes) || str_contains($bytes,"\0"))) self::fail('type');
    if ($mime==='application/pdf' && !str_starts_with($bytes,'%PDF-')) self::fail('type');
    return $mime;
  }
  /** Prepara destino novo sem sobrescrita; recebe relativo e retorna caminho; pais devem existir e ser diretórios. */
  private function destination(string $relative): string {
    if ($relative==='') self::fail('path');
    $file=$this->path($relative,false);
    if (file_exists($file)) self::fail('collision',409);
    if (!is_dir(dirname($file))) self::fail('directory');
    return $file;
  }
  /** Lê metadados privados da lixeira por ID aleatório; retorna objeto vinculado ao escopo e raiz atuais ou 404. */
  private function tombstone(string $id): array {
    if (!preg_match('/^[a-f0-9]{32}$/D',$id)) self::fail('missing',404);
    $directory=$this->state.'/trash/'.$id; self::noLinks($directory.'/metadata.json'); self::noLinks($directory.'/data');
    $raw=@file_get_contents($directory.'/metadata.json');
    if ($raw===false) self::fail('missing',404);
    try { $item=json_decode($raw,true,8,JSON_THROW_ON_ERROR); }
    catch (\JsonException) { self::fail('io',503); }
    if (!is_array($item) || ($item['scope']??null)!==$this->scope || ($item['root']??null)!==$this->context->settings['uploads'][$this->scope] || !is_string($item['path']??null)) self::fail('missing',404);
    self::relative($item['path']); $item['id']=$id; return $item;
  }
  /** Lista lixeira do escopo/raiz com revisão; não expõe caminhos absolutos nem lê conteúdo dos arquivos. */
  public function trash(): array {
    $entries=[];
    foreach (glob($this->state.'/trash/*',GLOB_ONLYDIR) ?: [] as $directory) {
      try { $item=$this->tombstone(basename($directory)); unset($item['root']); $entries[]=$item; }
      catch (HttpError $error) { if ($error->status!==404) throw $error; }
    }
    return ['entries'=>$entries,'revision'=>hash('sha256',$this->context->revision.json_encode($entries,JSON_THROW_ON_ERROR)),'scope'=>$this->scope];
  }
  /** Remove árvore já validada sem seguir links; recebe caminho, sem retorno; erro de I/O é explícito. */
  private function erase(string $path): void {
    if (is_link($path)) self::fail('link');
    if (is_dir($path)) {
      foreach (scandir($path) ?: [] as $name) if ($name!=='.' && $name!=='..') $this->erase($path.'/'.$name);
      if (!rmdir($path)) self::fail('io',503);
    } elseif (!unlink($path)) self::fail('io',503);
  }
  /** Executa uma mutação serializada sob sessão/CSRF verificados pelo núcleo; recebe operação/payload; retorna nova listagem. */
  public function mutate(string $operation,array $input): array {
    $directory=$input['path']??''; $revision=$input['revision']??'';
    if (!is_string($directory) || !is_string($revision)) self::fail('path');
    self::relative($directory); self::noLinks($this->state); Security::directory($this->state);
    $lockfile=$this->state.'/files.lock'; self::noLinks($lockfile); $lock=fopen($lockfile,'c+');
    if ($lock===false) self::fail('io',503);
    try {
      if (!flock($lock,LOCK_EX)) self::fail('io',503);
      if (!hash_equals($this->context->revision,$this->context->store->read('plugins')['revision'])) self::fail('revision',409);
      $trash=in_array($operation,['restore','purge'],true);
      $current=$trash?$this->trash():$this->listing($directory);
      if (!hash_equals($current['revision'],$revision)) self::fail('revision',409);
      self::noLinks($this->root); Security::directory($this->root);
      $parent=$this->path($directory); if (!is_dir($parent)) self::fail('directory');
      $target=$input['target']??''; $name=$input['name']??'';
      if (!is_string($target) || !is_string($name)) self::fail('path');
      if (in_array($operation,['mkdir','upload'],true)) {
        if ($operation==='upload') {
          $upload=$_FILES['file']??null;
          if (!is_array($upload) || ($upload['error']??null)!==UPLOAD_ERR_OK || !is_string($upload['name']??null) || !is_string($upload['tmp_name']??null) || !is_uploaded_file($upload['tmp_name'])) self::fail('upload');
          $name=$upload['name']; $this->mime($upload['tmp_name'],$name);
        }
        self::name($name); $relative=$directory===''?$name:$directory.'/'.$name; $file=$this->destination($relative);
        // A raiz pública vazia pode conter o CMS: a cota é por pasta administrável, sem percorrer código.
        if (count($current['entries'])>=1000 || array_sum(array_column($current['entries'],'size'))+($operation==='upload'?filesize($upload['tmp_name']):0)>262144000) self::fail('quota',413);
        if ($operation==='mkdir') { if (!mkdir($file,0770)) self::fail('io',503); }
        else { if (!move_uploaded_file($upload['tmp_name'],$file)) self::fail('io',503); if (!chmod($file,0640)) self::fail('io',503); }
      } elseif (in_array($operation,['rename','move','delete'],true)) {
        self::name($target); $relative=$directory===''?$target:$directory.'/'.$target; $file=$this->path($relative); $this->tree($file,$relative);
        if ($operation==='delete') {
          if (stat(dirname($file))['dev']!==stat($this->state)['dev']) self::fail('filesystem');
          $id=bin2hex(random_bytes(16)); $trashdir=$this->state.'/trash/'.$id; self::noLinks($trashdir); Security::directory($trashdir);
          $deletedAt=gmdate(DATE_ATOM);
          $metadata=['status'=>'deleted','deleted_at'=>$deletedAt,'scope'=>$this->scope,'root'=>$this->context->settings['uploads'][$this->scope],'path'=>$relative,'name'=>$target,'directory'=>is_dir($file),'size'=>is_file($file)?filesize($file):0,'deleted'=>$deletedAt];
          if (file_put_contents($trashdir.'/metadata.json',JsonStore::encode($metadata),LOCK_EX)===false) self::fail('io',503);
          if (!rename($file,$trashdir.'/data')) { unlink($trashdir.'/metadata.json'); rmdir($trashdir); self::fail('io',503); }
        } else {
          if ($operation==='rename') { self::name($name); $dest=$directory===''?$name:$directory.'/'.$name; }
          else {
            $destination=$input['destination']??null; if (!is_string($destination)) self::fail('path'); self::relative($destination);
            if (!is_dir($this->path($destination))) self::fail('directory');
            $dest=$destination===''?$target:$destination.'/'.$target;
          }
          if (str_starts_with($dest,$relative.'/')) self::fail('path');
          if (is_file($file)) $this->mime($file,basename($dest));
          $to=$this->destination($dest);
          if (stat(dirname($file))['dev']!==stat(dirname($to))['dev']) self::fail('filesystem');
          if (!rename($file,$to)) self::fail('io',503);
        }
      } elseif ($trash) {
        $item=$this->tombstone($target); $trashdir=$this->state.'/trash/'.$target; $data=$trashdir.'/data';
        if ($operation==='restore') {
          $to=$this->destination($item['path']);
          if (stat(dirname($to))['dev']!==stat($trashdir)['dev']) self::fail('filesystem');
          self::noLinks($data);
          if (!rename($data,$to)) self::fail('io',503);
        } else {
          if ($name!==$item['name']) self::fail('confirmation');
          self::noLinks($data); $this->erase($data);
        }
        if (!unlink($trashdir.'/metadata.json') || !rmdir($trashdir)) self::fail('io',503);
      } else self::fail('operation',400);
      $event=['at'=>gmdate(DATE_ATOM),'event'=>$operation,'scope'=>$this->scope,'target'=>hash('sha256',$directory.'/'.$target.'/'.$name)];
      if (file_put_contents($this->state.'/audit.jsonl',json_encode($event,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX)===false) self::fail('io',503);
      clearstatcache(); return $trash?$this->trash():$this->listing($directory);
    } finally { flock($lock,LOCK_UN); fclose($lock); }
  }
  /** Transmite arquivo validado em sessão autenticada; recebe relativo/preview, sem retorno; MIME inseguro nunca fica inline. */
  public function download(string $relative,bool $preview): void {
    $file=$this->path($relative); if (!is_file($file)) self::fail('type');
    $mime=$this->mime($file,basename($file));
    if ($preview && !str_starts_with($mime,'image/')) self::fail('type');
    header('Content-Type: '.$mime); header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: '.($preview?'inline':'attachment').'; filename="download.'.pathinfo($file,PATHINFO_EXTENSION).'"; filename*=UTF-8\'\''.rawurlencode(basename($file)));
    header('Content-Length: '.filesize($file)); session_write_close();
    if (readfile($file)===false) self::fail('io',503);
  }
}
