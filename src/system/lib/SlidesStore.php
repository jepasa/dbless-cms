<?php
/** Coleções Slides privadas: contrato fechado, revisão, lock, publicação, histórico e lixeira lógica. */
declare(strict_types=1);
namespace DBLESSCMS;
final class SlidesStore {
  public const CONTENT_TYPES=['banner','product','testimonial','logo','notice'];
  /** Recebe serviço editorial compartilhado; não lê/grava até operação explícita. */
  public function __construct(private readonly JsonStore $store) {}
  /** Valida ID opaco; recebe string e retorna bool sem efeitos. */
  public static function identifier(string $value): bool { return MenuStore::identifier($value); }
  /** Rejeita campos ausentes/desconhecidos; recebe objeto/chaves e lança 422 sem expor dados. */
  private static function fields(mixed $value,array $keys): void {
    if (!is_array($value)||array_is_list($value)||count($value)!==count($keys)||array_diff(array_keys($value),$keys)!==[]) throw new HttpError(422,'Contrato de slides inválido.','slides.error.contract');
  }
  /** Valida texto literal UTF-8 limitado; recebe valor/limite/vazio permitido e retorna bool. */
  private static function text(mixed $value,int $max,bool $empty=false): bool {
    return is_string($value)&&strlen($value)<=$max&&($empty||trim($value)!=='')&&preg_match('//u',$value)===1&&!preg_match('/[\x00-\x1F\x7F<>]/u',$value);
  }
  /** Valida caminho público relativo de imagem sem traversal/privados; recebe texto e retorna bool sem ler disco. */
  public static function image(string $value): bool {
    if ($value==='') return true;
    if (strlen($value)>1024||!preg_match('~\.(?:png|jpe?g|webp|gif|avif)$~Di',$value)||in_array(strtolower(explode('/',$value)[0]),['system','cms-admin','plugins','storage','config'],true)) return false;
    foreach(explode('/',$value) as $part)if(strlen($part)>120||!preg_match('/^[\pL\pN_][\pL\pN_ ().-]*$/uD',$part)||str_contains($part,'..')||trim($part)!==$part||str_ends_with($part,'.'))return false;
    return true;
  }
  /** Valida link editorial interno/âncora/HTTP(S), sem credenciais ou escapes; recebe texto e retorna bool. */
  public static function link(string $value): bool {
    if ($value==='') return true;
    if (!self::text($value,2048)||preg_match('~[\s\\\\]~u',$value)||str_contains(rawurldecode($value),'..')||preg_match('/[\x00-\x20\x7F]/',rawurldecode($value))) return false;
    if (preg_match('~^/(?!/)[A-Za-z0-9_/?=&%.#-]*$~D',$value)||preg_match('/^#[A-Za-z][A-Za-z0-9_-]*$/D',$value)) return true;
    return filter_var($value,FILTER_VALIDATE_URL)!==false&&in_array(strtolower((string)parse_url($value,PHP_URL_SCHEME)),['https','http'],true)&&parse_url($value,PHP_URL_USER)===null&&parse_url($value,PHP_URL_PASS)===null;
  }
  /** Valida itens planos fechados; recebe lista e lança 422 por IDs duplicados, HTML, campos/URLs/tipos inválidos. */
  public static function items(mixed $items): void {
    if (!is_array($items)||!array_is_list($items)||count($items)>50) throw new HttpError(422,'Itens inválidos.','slides.error.contract');
    $seen=[];
    foreach($items as $item) {
      self::fields($item,['id','title','text','image','alt','href','label','attribution','price','enabled']);
      if (!is_string($item['id'])||!self::identifier($item['id'])||isset($seen[$item['id']])||!is_bool($item['enabled'])) throw new HttpError(422,'Item inválido.','slides.error.contract');
      foreach(['title'=>200,'text'=>2000,'alt'=>300,'label'=>100,'attribution'=>200,'price'=>80,'image'=>1024,'href'=>2048] as $key=>$limit) if (!self::text($item[$key],$limit,$key!=='title')) throw new HttpError(422,'Campo inválido.','slides.error.contract');
      if (!self::image($item['image'])||!self::link($item['href'])||($item['image']!==''&&trim($item['alt'])==='')) throw new HttpError(422,'Imagem ou destino inválido.','slides.error.contract');
      $seen[$item['id']]=true;
    }
  }
  /** Valida documento completo sem consultar templates; recebe dados e retorna cópia ou 422. */
  public static function validate(array $data): array {
    self::fields($data,['schema_version','collections','assignments']);
    if ($data['schema_version']!==1||!is_array($data['collections'])||count($data['collections'])>50||!is_array($data['assignments'])||!array_is_list($data['assignments'])||count($data['assignments'])>200) throw new HttpError(422,'Documento inválido.','slides.error.contract');
    foreach($data['collections'] as $id=>$set) {
      self::fields($set,['id','name','contentType','status','deleted','autoplay','interval','items']);
      if (!is_string($id)||!self::identifier($id)||$set['id']!==$id||!self::text($set['name'],160)||!in_array($set['contentType'],self::CONTENT_TYPES,true)||!in_array($set['status'],['draft','published'],true)||!is_bool($set['deleted'])||!is_bool($set['autoplay'])||!is_int($set['interval'])||$set['interval']<3000||$set['interval']>30000) throw new HttpError(422,'Conjunto inválido.','slides.error.contract');
      self::items($set['items']);
    }
    $seen=[];
    foreach($data['assignments'] as $binding) {
      self::fields($binding,['template','location','collection']);
      foreach($binding as $value) if(!is_string($value)) throw new HttpError(422,'Associação inválida.','slides.error.binding');
      $key=$binding['template'].':'.$binding['location'];
      if (!Templates::identifier($binding['template'])||!self::identifier($binding['location'])||!isset($data['collections'][$binding['collection']])||isset($seen[$key])) throw new HttpError(422,'Associação inválida.','slides.error.binding');
      $seen[$key]=true;
    }
    return $data;
  }

  /** Serializa mapas vazios como objetos e JSON indentado; recebe documento validado e retorna bytes, propagando erro de codificação. */
  public static function encode(array $data): string {
    if ($data['collections']===[]) $data['collections']=(object)[];
    return json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
  }

  /** Resolve apenas caminhos fixos sem links em nenhum componente; recebe bool histórico e retorna diretório sem criá-lo. */
  private function directory(bool $history=false): string {
    $base=$history?$this->store->config->data['storage']:$this->store->config->data['contents'];
    $ancestor='';
    foreach (explode('/',ltrim($base,'/')) as $part) {
      $ancestor.='/'.$part;
      if (is_link($ancestor)) throw new HttpError(503,'Recurso inseguro.','slides.error.storage');
    }
    $path=rtrim($base,'/');
    foreach (array_merge([$path],$history?['slides-history']:['slides']) as $index=>$part) {
      if ($index!==0) $path.='/'.$part;
      if (is_link($path) || (file_exists($path)&&!is_dir($path))) throw new HttpError(503,'Recurso inseguro.','slides.error.storage');
    }
    return $path;
  }

  /** Decodifica bytes limitados sem executar conteúdo; recebe JSON e retorna contrato ou 422. */
  public static function decode(string $raw): array {
    if (strlen($raw)>524288) throw new HttpError(422,'Documento excessivo.','slides.error.contract');
    try { $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR); }
    catch (\JsonException) { throw new HttpError(422,'JSON inválido.','slides.error.contract'); }
    if (!is_array($data)) throw new HttpError(422,'Documento inválido.','slides.error.contract');
    return self::validate($data);
  }

  /** Lê recurso regular com limite; recebe caminho fixo validado, retorna bytes ou 503, sem escrita. */
  private function bytes(string $file): string {
    if (is_link($file) || !is_file($file)) throw new HttpError(503,'Recurso indisponível.','slides.error.storage');
    $raw=file_get_contents($file,false,null,0,524289);
    if ($raw===false) throw new HttpError(503,'Leitura indisponível.','slides.error.storage');
    return $raw;
  }

  /** Lê documento/revisão, ausência representa instalação vazia; sem argumentos nem escrita; corrupção falha explicitamente. */
  public function read(): array {
    $file=$this->directory().'/slides.json';
    if (is_link($file)) throw new HttpError(503,'Recurso inseguro.','slides.error.storage');
    if (!file_exists($file)) return ['data'=>['schema_version'=>1,'collections'=>[],'assignments'=>[]],'revision'=>hash('sha256','')];
    $raw=$this->bytes($file);return ['data'=>self::decode($raw),'revision'=>hash('sha256',$raw)];
  }

  /** Escreve bytes por rename no mesmo diretório; recebe destino/bytes, sem retorno; qualquer falha é 503 e temporário é removido. */
  private function atomic(string $file,string $raw): void {
    if (is_link($file)) throw new HttpError(503,'Recurso inseguro.','slides.error.storage');
    $temporary=tempnam(dirname($file),'.slides-');
    if ($temporary===false) throw new HttpError(503,'Gravação indisponível.','slides.error.storage');
    try {
      $stream=fopen($temporary,'wb');if ($stream===false) throw new HttpError(503,'Gravação indisponível.','slides.error.storage');
      try { if (fwrite($stream,$raw)!==strlen($raw)||!fflush($stream)||(function_exists('fsync')&&!fsync($stream))) throw new HttpError(503,'Gravação incompleta.','slides.error.storage'); }
      finally { fclose($stream); }
      if (!chmod($temporary,0660)||!rename($temporary,$file)) throw new HttpError(503,'Publicação indisponível.','slides.error.storage');
    } finally { if (is_file($temporary)) unlink($temporary); }
  }

  /** Publica mutação sob revisão/trava, backup e auditoria; recebe revisão/callback e retorna snapshot; falha preserva versão anterior antes do rename. */
  public function mutate(string $revision,callable $change): array {
    return $this->store->transaction(function() use($revision,$change): array {
      $current=$this->read();
      if (!hash_equals($current['revision'],$revision)) throw new HttpError(409,'Revisão desatualizada.','slides.error.conflict');
      $data=self::validate($change($current['data']));$raw=self::encode($data);
      if (strlen($raw)>524288) throw new HttpError(422,'Documento excessivo.','slides.error.contract');
      $directory=$this->directory();Security::directory($directory);$history=$this->directory(true);Security::directory($history);
      $audit=$this->store->config->data['storage'].'/audit.jsonl';
      if(is_link($audit)||(file_exists($audit)&&!is_file($audit)))throw new HttpError(503,'Auditoria insegura.','slides.error.storage');
      $this->atomic($history.'/'.(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Ymd-His-u').'-'.bin2hex(random_bytes(8)).'.json',self::encode($current['data']));
      $this->atomic($directory.'/slides.json',$raw);
      foreach (array_slice($this->history(),20) as $backup) if (!unlink($history.'/'.$backup)) throw new HttpError(503,'Retenção indisponível.','slides.error.storage');
      if (file_put_contents($this->store->config->data['storage'].'/audit.jsonl',json_encode(['at'=>gmdate(DATE_ATOM),'event'=>'slides-save','revision'=>hash('sha256',$raw)],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX)===false) throw new HttpError(503,'Auditoria indisponível.','slides.error.storage');
      return $this->read();
    });
  }

  /** Lista somente backups regulares com IDs fechados; retorna nomes ordenados, sem ler conteúdo ou escrever. */
  public function history(): array {
    $directory=$this->directory(true);$ids=[];
    foreach (glob($directory.'/*.json')?:[] as $file) if (!is_link($file)&&is_file($file)&&preg_match('/^[0-9]{8}-[0-9]{6}(?:-[0-9]{6})?-[a-f0-9]{16}\.json$/D',basename($file))) $ids[]=basename($file);
    rsort($ids,SORT_STRING);return $ids;
  }

  /** Restaura documento validado sob revisão e backup; recebe ID/revisão, retorna snapshot; nunca aceita caminhos arbitrários. */
  public function restore(string $id,string $revision): array {
    if (!in_array($id,$this->history(),true)) throw new HttpError(422,'Histórico inválido.','slides.error.contract');
    $data=self::decode($this->bytes($this->directory(true).'/'.$id));
    return $this->mutate($revision,static fn(array $current): array=>$data);
  }
}
