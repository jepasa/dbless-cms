<?php
/** Conteúdo privado de widgets: contrato fechado, revisão, lock, escrita atômica, histórico e exclusão lógica. */
declare(strict_types=1);
namespace DBLESSCMS;
final class WidgetsStore {
  /** Recebe store compartilhado; não lê nem grava documentos até a operação solicitada. */
  public function __construct(private readonly JsonStore $store) {}

  /** Valida identificador opaco; recebe texto e retorna bool sem efeitos externos. */
  public static function identifier(string $value): bool { return preg_match('/^[a-z][a-z0-9-]{0,47}$/D',$value)===1; }

  /** Valida objeto de campos fechados; recebe objeto/lista de chaves e falha 422 sem expor dados. */
  private static function fields(mixed $value,array $keys): void {
    if (!is_array($value) || array_is_list($value) || count($value)!==count($keys) || array_diff(array_keys($value),$keys)!==[]) throw new HttpError(422,'Contrato de widgets inválido.','widgets.error.contract');
  }

  /** Valida texto literal UTF-8 sem HTML/controles; recebe valor/limite/vazio permitido e retorna bool. */
  private static function text(mixed $value,int $max,bool $empty=false): bool {
    return is_string($value) && strlen($value)<=$max && ($empty || trim($value)!=='') && preg_match('//u',$value)===1 && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F<>]/u',$value);
  }

  /** Validate the closed widget document; receives data, returns unchanged validated copy; duplicates, code, invalid references and excessive content throw 422 without writes. */
  public static function validate(array $data): array {
    self::fields($data,['schema_version','widgets']);
    if($data['schema_version']!==1||!is_array($data['widgets'])||count($data['widgets'])>1000) throw new HttpError(422,'Invalid widget catalog.','widgets.error.contract');
    $seen=[];
    foreach($data['widgets'] as $id=>$record){
      self::fields($record,['id','template','key','value','description','deleted']);
      if(!is_string($id)||!preg_match('/^[a-z][a-z0-9-]{0,63}$/D',$id)||$record['id']!==$id||!is_string($record['template'])||!Templates::identifier($record['template'])||!is_string($record['key'])||!preg_match('/^[a-z][a-z0-9-]{0,63}$/D',$record['key'])||!self::text($record['value'],12000,true)||!self::text($record['description'],500,true)||!is_bool($record['deleted'])) throw new HttpError(422,'Invalid widget.','widgets.error.contract');
      $key=$record['template'].':'.$record['key'];
      if(!$record['deleted']&&isset($seen[$key])) throw new HttpError(422,'Duplicate widget key.','widgets.error.duplicate');
      if(!$record['deleted'])$seen[$key]=true;
    }
    return $data;
  }

  /** Serializa mapas vazios como objetos e JSON indentado; recebe documento validado e retorna bytes, propagando erro de codificação. */
  public static function encode(array $data): string {
    if ($data['widgets']===[]) $data['widgets']=(object)[];
    return json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
  }

  /** Resolve apenas caminhos fixos sem links em nenhum componente; recebe bool histórico e retorna diretório sem criá-lo. */
  private function directory(bool $history=false): string {
    $base=$history?$this->store->config->data['storage']:$this->store->config->data['contents'];
    $ancestor='';
    foreach (explode('/',ltrim($base,'/')) as $part) {
      $ancestor.='/'.$part;
      if (is_link($ancestor)) throw new HttpError(503,'Recurso inseguro.','widgets.error.storage');
    }
    $path=rtrim($base,'/');
    foreach (array_merge([$path],$history?['widgets-history']:['widgets']) as $index=>$part) {
      if ($index!==0) $path.='/'.$part;
      if (is_link($path) || (file_exists($path)&&!is_dir($path))) throw new HttpError(503,'Recurso inseguro.','widgets.error.storage');
    }
    return $path;
  }

  /** Decodifica bytes limitados sem executar conteúdo; recebe JSON e retorna contrato ou 422. */
  public static function decode(string $raw): array {
    if (strlen($raw)>524288) throw new HttpError(422,'Documento excessivo.','widgets.error.contract');
    try { $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR); }
    catch (\JsonException) { throw new HttpError(422,'JSON inválido.','widgets.error.contract'); }
    if (!is_array($data)) throw new HttpError(422,'Documento inválido.','widgets.error.contract');
    return self::validate($data);
  }

  /** Lê recurso regular com limite; recebe caminho fixo validado, retorna bytes ou 503, sem escrita. */
  private function bytes(string $file): string {
    if (is_link($file) || !is_file($file)) throw new HttpError(503,'Recurso indisponível.','widgets.error.storage');
    $raw=file_get_contents($file,false,null,0,524289);
    if ($raw===false) throw new HttpError(503,'Leitura indisponível.','widgets.error.storage');
    return $raw;
  }

  /** Lê documento/revisão, ausência representa instalação vazia; sem argumentos nem escrita; corrupção falha explicitamente. */
  public function read(): array {
    $file=$this->directory().'/widgets.json';
    if (is_link($file)) throw new HttpError(503,'Recurso inseguro.','widgets.error.storage');
    if (!file_exists($file)) return ['data'=>['schema_version'=>1,'widgets'=>[]],'revision'=>hash('sha256','')];
    $raw=$this->bytes($file);return ['data'=>self::decode($raw),'revision'=>hash('sha256',$raw)];
  }

  /** Escreve bytes por rename no mesmo diretório; recebe destino/bytes, sem retorno; qualquer falha é 503 e temporário é removido. */
  private function atomic(string $file,string $raw): void {
    if (is_link($file)) throw new HttpError(503,'Recurso inseguro.','widgets.error.storage');
    $temporary=tempnam(dirname($file),'.widgets-');
    if ($temporary===false) throw new HttpError(503,'Gravação indisponível.','widgets.error.storage');
    try {
      $stream=fopen($temporary,'wb');if ($stream===false) throw new HttpError(503,'Gravação indisponível.','widgets.error.storage');
      try { if (fwrite($stream,$raw)!==strlen($raw)||!fflush($stream)||(function_exists('fsync')&&!fsync($stream))) throw new HttpError(503,'Gravação incompleta.','widgets.error.storage'); }
      finally { fclose($stream); }
      if (!chmod($temporary,0660)||!rename($temporary,$file)) throw new HttpError(503,'Publicação indisponível.','widgets.error.storage');
    } finally { if (is_file($temporary)) unlink($temporary); }
  }

  /** Publica mutação sob revisão/trava, backup e auditoria; recebe revisão/callback e retorna snapshot; falha preserva versão anterior antes do rename. */
  public function mutate(string $revision,callable $change): array {
    return $this->store->transaction(function() use($revision,$change): array {
      $current=$this->read();
      if (!hash_equals($current['revision'],$revision)) throw new HttpError(409,'Revisão desatualizada.','widgets.error.conflict');
      $data=self::validate($change($current['data']));$raw=self::encode($data);
      if (strlen($raw)>524288) throw new HttpError(422,'Documento excessivo.','widgets.error.contract');
      $directory=$this->directory();Security::directory($directory);$history=$this->directory(true);Security::directory($history);
      $this->atomic($history.'/'.(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Ymd-His-u').'-'.bin2hex(random_bytes(8)).'.json',self::encode($current['data']));
      $this->atomic($directory.'/widgets.json',$raw);
      foreach (array_slice($this->history(),20) as $backup) if (!unlink($history.'/'.$backup)) throw new HttpError(503,'Retenção indisponível.','widgets.error.storage');
      if (file_put_contents($this->store->config->data['storage'].'/audit.jsonl',json_encode(['at'=>gmdate(DATE_ATOM),'event'=>'widgets-save','revision'=>hash('sha256',$raw)],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX)===false) throw new HttpError(503,'Auditoria indisponível.','widgets.error.storage');
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
    if (!in_array($id,$this->history(),true)) throw new HttpError(422,'Histórico inválido.','widgets.error.contract');
    $data=self::decode($this->bytes($this->directory(true).'/'.$id));
    return $this->mutate($revision,static fn(array $current): array=>$data);
  }
}
