<?php
/** Conteúdo privado de menus: contrato fechado, revisão, lock, escrita atômica, histórico e exclusão lógica. */
declare(strict_types=1);
namespace CMS2JSON;
final class MenuStore {
  /** Recebe store compartilhado; não lê nem grava documentos até a operação solicitada. */
  public function __construct(private readonly JsonStore $store) {}

  /** Valida identificador opaco; recebe texto e retorna bool sem efeitos externos. */
  public static function identifier(string $value): bool { return preg_match('/^[a-z][a-z0-9-]{0,47}$/D',$value)===1; }

  /** Valida objeto de campos fechados; recebe objeto/lista de chaves e falha 422 sem expor dados. */
  private static function fields(mixed $value,array $keys): void {
    if (!is_array($value) || array_is_list($value) || count($value)!==count($keys) || array_diff(array_keys($value),$keys)!==[]) throw new HttpError(422,'Contrato de menu inválido.','menu.error.contract');
  }

  /** Valida texto literal UTF-8 sem HTML/controles; recebe valor/limite/vazio permitido e retorna bool. */
  private static function text(mixed $value,int $max,bool $empty=false): bool {
    return is_string($value) && strlen($value)<=$max && ($empty || trim($value)!=='') && preg_match('//u',$value)===1 && !preg_match('/[\x00-\x1F\x7F<>]/u',$value);
  }

  /** Valida árvore plana e destinos tipados; recebe itens, retorna profundidade máxima; ciclos/referências/URLs inválidos geram 422. */
  public static function items(mixed $items): int {
    if (!is_array($items) || !array_is_list($items) || count($items)>100) throw new HttpError(422,'Itens inválidos.','menu.error.contract');
    $index=[];
    foreach ($items as $item) {
      self::fields($item,['id','parent','label','kind','page','anchor','url','enabled','newTab']);
      if (!is_string($item['id']) || !self::identifier($item['id']) || isset($index[$item['id']]) || !self::text($item['label'],160) || !is_string($item['parent']) || ($item['parent']!==''&&!self::identifier($item['parent'])) || !in_array($item['kind'],['page','anchor','url','group'],true) || !is_bool($item['enabled']) || !is_bool($item['newTab'])) throw new HttpError(422,'Item inválido.','menu.error.contract');
      foreach (['page'=>64,'anchor'=>100,'url'=>2048] as $key=>$limit) if (!self::text($item[$key],$limit,true)) throw new HttpError(422,'Destino inválido.','menu.error.contract');
      if (in_array($item['kind'],['page','anchor'],true)) {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$item['page']) || in_array($item['page'],['404','site','plugins','templates'],true) || $item['url']!=='') throw new HttpError(422,'Página inválida.','menu.error.contract');
        if ($item['kind']==='anchor' && !preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,99}$/D',$item['anchor'])) throw new HttpError(422,'Âncora inválida.','menu.error.contract');
        if ($item['kind']==='page' && $item['anchor']!=='') throw new HttpError(422,'Âncora inesperada.','menu.error.contract');
      } elseif ($item['page']!=='' || $item['anchor']!=='') throw new HttpError(422,'Destino incompatível.','menu.error.contract');
      if ($item['kind']==='url') {
        $url=$item['url']; $scheme=strtolower((string)parse_url($url,PHP_URL_SCHEME));
        if (preg_match('/[\s\\\\]/u',$url) || !in_array($scheme,['https','http','mailto','tel'],true) || (in_array($scheme,['https','http'],true)&&(!filter_var($url,FILTER_VALIDATE_URL)||parse_url($url,PHP_URL_USER)!==null||parse_url($url,PHP_URL_PASS)!==null)) || ($scheme==='mailto'&&!filter_var(substr($url,7),FILTER_VALIDATE_EMAIL)) || ($scheme==='tel'&&!preg_match('/^tel:\+?[0-9().-]{3,40}$/D',$url))) throw new HttpError(422,'URL inválida.','menu.error.contract');
      } elseif ($item['url']!=='') throw new HttpError(422,'URL inesperada.','menu.error.contract');
      $index[$item['id']]=$item;
    }
    $max=0;
    foreach ($index as $id=>$item) {
      $seen=[$id=>true];$depth=1;$parent=$item['parent'];
      while ($parent!=='') {
        if (isset($seen[$parent]) || !isset($index[$parent]) || ++$depth>5) throw new HttpError(422,'Hierarquia inválida.','menu.error.hierarchy');
        $seen[$parent]=true;$parent=$index[$parent]['parent'];
      }
      $max=max($max,$depth);
    }
    return $max;
  }

  /** Valida documento completo limitado sem consultar templates instalados; recebe dados, retorna cópia validada ou 422. */
  public static function validate(array $data): array {
    self::fields($data,['schema_version','menus','assignments']);
    if ($data['schema_version']!==1 || !is_array($data['menus']) || count($data['menus'])>50 || !is_array($data['assignments']) || !array_is_list($data['assignments']) || count($data['assignments'])>200) throw new HttpError(422,'Documento inválido.','menu.error.contract');
    foreach ($data['menus'] as $id=>$menu) {
      self::fields($menu,['id','name','deleted','items']);
      if (!is_string($id) || !self::identifier($id) || $menu['id']!==$id || !self::text($menu['name'],160) || !is_bool($menu['deleted'])) throw new HttpError(422,'Menu inválido.','menu.error.contract');
      self::items($menu['items']);
    }
    $seen=[];
    foreach ($data['assignments'] as $binding) {
      self::fields($binding,['template','location','menu','type']);
      foreach ($binding as $value) if (!is_string($value)) throw new HttpError(422,'Associação inválida.','menu.error.contract');
      $key=$binding['template'].':'.$binding['location'];
      if (!Templates::identifier($binding['template']) || !self::identifier($binding['location']) || !isset($data['menus'][$binding['menu']]) || !in_array($binding['type'],Navigation::TYPES,true) || isset($seen[$key])) throw new HttpError(422,'Associação inválida.','menu.error.contract');
      $seen[$key]=true;
    }
    return $data;
  }

  /** Serializa mapas vazios como objetos e JSON indentado; recebe documento validado e retorna bytes, propagando erro de codificação. */
  public static function encode(array $data): string {
    if ($data['menus']===[]) $data['menus']=(object)[];
    return json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
  }

  /** Resolve apenas caminhos fixos sem links em nenhum componente; recebe bool histórico e retorna diretório sem criá-lo. */
  private function directory(bool $history=false): string {
    $base=$history?$this->store->config->data['storage']:$this->store->config->data['contents'];
    $ancestor='';
    foreach (explode('/',ltrim($base,'/')) as $part) {
      $ancestor.='/'.$part;
      if (is_link($ancestor)) throw new HttpError(503,'Recurso inseguro.','menu.error.storage');
    }
    $path=rtrim($base,'/');
    foreach (array_merge([$path],$history?['menu-history']:['menu']) as $index=>$part) {
      if ($index!==0) $path.='/'.$part;
      if (is_link($path) || (file_exists($path)&&!is_dir($path))) throw new HttpError(503,'Recurso inseguro.','menu.error.storage');
    }
    return $path;
  }

  /** Decodifica bytes limitados sem executar conteúdo; recebe JSON e retorna contrato ou 422. */
  public static function decode(string $raw): array {
    if (strlen($raw)>524288) throw new HttpError(422,'Documento excessivo.','menu.error.contract');
    try { $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR); }
    catch (\JsonException) { throw new HttpError(422,'JSON inválido.','menu.error.contract'); }
    if (!is_array($data)) throw new HttpError(422,'Documento inválido.','menu.error.contract');
    return self::validate($data);
  }

  /** Lê recurso regular com limite; recebe caminho fixo validado, retorna bytes ou 503, sem escrita. */
  private function bytes(string $file): string {
    if (is_link($file) || !is_file($file)) throw new HttpError(503,'Recurso indisponível.','menu.error.storage');
    $raw=file_get_contents($file,false,null,0,524289);
    if ($raw===false) throw new HttpError(503,'Leitura indisponível.','menu.error.storage');
    return $raw;
  }

  /** Lê documento/revisão, ausência representa instalação vazia; sem argumentos nem escrita; corrupção falha explicitamente. */
  public function read(): array {
    $file=$this->directory().'/menus.json';
    if (is_link($file)) throw new HttpError(503,'Recurso inseguro.','menu.error.storage');
    if (!file_exists($file)) return ['data'=>['schema_version'=>1,'menus'=>[],'assignments'=>[]],'revision'=>hash('sha256','')];
    $raw=$this->bytes($file);return ['data'=>self::decode($raw),'revision'=>hash('sha256',$raw)];
  }

  /** Escreve bytes por rename no mesmo diretório; recebe destino/bytes, sem retorno; qualquer falha é 503 e temporário é removido. */
  private function atomic(string $file,string $raw): void {
    if (is_link($file)) throw new HttpError(503,'Recurso inseguro.','menu.error.storage');
    $temporary=tempnam(dirname($file),'.menu-');
    if ($temporary===false) throw new HttpError(503,'Gravação indisponível.','menu.error.storage');
    try {
      $stream=fopen($temporary,'wb');if ($stream===false) throw new HttpError(503,'Gravação indisponível.','menu.error.storage');
      try { if (fwrite($stream,$raw)!==strlen($raw)||!fflush($stream)||(function_exists('fsync')&&!fsync($stream))) throw new HttpError(503,'Gravação incompleta.','menu.error.storage'); }
      finally { fclose($stream); }
      if (!chmod($temporary,0660)||!rename($temporary,$file)) throw new HttpError(503,'Publicação indisponível.','menu.error.storage');
    } finally { if (is_file($temporary)) unlink($temporary); }
  }

  /** Publica mutação sob revisão/trava, backup e auditoria; recebe revisão/callback e retorna snapshot; falha preserva versão anterior antes do rename. */
  public function mutate(string $revision,callable $change): array {
    return $this->store->transaction(function() use($revision,$change): array {
      $current=$this->read();
      if (!hash_equals($current['revision'],$revision)) throw new HttpError(409,'Revisão desatualizada.','menu.error.conflict');
      $data=self::validate($change($current['data']));$raw=self::encode($data);
      if (strlen($raw)>524288) throw new HttpError(422,'Documento excessivo.','menu.error.contract');
      $directory=$this->directory();Security::directory($directory);$history=$this->directory(true);Security::directory($history);
      $this->atomic($history.'/'.(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Ymd-His-u').'-'.bin2hex(random_bytes(8)).'.json',self::encode($current['data']));
      $this->atomic($directory.'/menus.json',$raw);
      foreach (array_slice($this->history(),20) as $backup) if (!unlink($history.'/'.$backup)) throw new HttpError(503,'Retenção indisponível.','menu.error.storage');
      if (file_put_contents($this->store->config->data['storage'].'/audit.jsonl',json_encode(['at'=>gmdate(DATE_ATOM),'event'=>'menu-save','revision'=>hash('sha256',$raw)],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX)===false) throw new HttpError(503,'Auditoria indisponível.','menu.error.storage');
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
    if (!in_array($id,$this->history(),true)) throw new HttpError(422,'Histórico inválido.','menu.error.contract');
    $data=self::decode($this->bytes($this->directory(true).'/'.$id));
    return $this->mutate($revision,static fn(array $current): array=>$data);
  }
}
