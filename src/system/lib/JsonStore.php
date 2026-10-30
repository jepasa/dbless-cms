<?php
/** Documentos JSON privados, sanitização, revisão otimista, backups e exclusão recuperável sob lock global. */
declare(strict_types=1);
namespace CMS2JSON;
final class JsonStore {
  public readonly Templates $templates;
  private array $incompatible = [];
  private bool $locked = false;
  /** Recebe configuração, inicializa registro; sem escrita; contratos inválidos falham no carregamento. */
  public function __construct(public readonly Config $config) { $this->templates=new Templates($config); }

  /** Normaliza chave opaca conhecida (site ou slug); retorna caminho privado e rejeita traversal/symlinks. */
  private function path(string $key): string {
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$key)) throw new HttpError(400,'Identificador de documento inválido.','error.document_id');
    $base=rtrim($this->config->data['contents'],'/'); $file=$base.(in_array($key,['site','plugins','templates'],true)?'/'.$key.'.json':'/pages/'.$key.'.json');
    if (is_link($base) || is_link($base.'/pages') || is_link($file)) throw new HttpError(422,'Documento inseguro.','error.document_unsafe');
    return $file;
  }

  /** Decodifica/valida/sanitiza documento; recebe chave/JSON/template opcional, retorna objeto; erros são 422. */
  public function document(string $key,string $raw,?string $template=null): array {
    if (strlen($raw)>524288) throw new HttpError(413,'Documento excede 512 KiB.','error.document_size');
    try { $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR); }
    catch (\JsonException) { throw new HttpError(422,'JSON inválido.','error.json'); }
    if (!is_array($data) || array_is_list($data)) throw new HttpError(422,'Documento precisa ser um objeto JSON.','error.document_object');
    $data=Schema::sanitize($data);
    if ($key==='templates') {
      if (($data['schema_version']??null)!==1 || !is_array($data['templates']??null) || count($data)!==2 || count($data['templates'])>50) throw new HttpError(422,'Opções de templates inválidas.','error.extension_options');
      foreach ($data['templates'] as $id=>$options) if (!is_string($id) || !Templates::identifier($id) || !is_array($options)) throw new HttpError(422,'Opções inválidas.','error.extension_options');
    } elseif ($key==='plugins') {
      if (($data['schema_version']??null)!==1 || !is_array($data['plugins']??null) || count($data)!==2 || count($data['plugins'])>50) throw new HttpError(422,'Configuração de plugins inválida.','error.plugin');
      foreach ($data['plugins'] as $id=>$state) {
        if (!is_string($id) || !Plugins::identifier($id) || !is_array($state) || count($state)!==2 || !is_bool($state['enabled']??null) || !is_array($state['settings']??null)) throw new HttpError(422,'Configuração de plugin inválida.','error.plugin');
      }
    } elseif ($key==='site') {
      $schema=json_decode((string)file_get_contents($this->config->root.'/config/schemas/site.schema.json'),true,64,JSON_THROW_ON_ERROR);
      Schema::validate($data,$schema);
      if (($data['schema_version']??null)!==1) throw new HttpError(422,'Versão de conteúdo incompatível.','error.version');
      if (!is_string($data['settings']['canonical']) || !filter_var($data['settings']['canonical'],FILTER_VALIDATE_URL) || !in_array(parse_url($data['settings']['canonical'],PHP_URL_SCHEME),['https','http'],true)) throw new HttpError(422,'URL canônica precisa ser absoluta.','error.canonical');
      $publicLocale=SiteLocale::resolve($data['settings']);
      if (isset($data['settings']['siteLocale'])) $data['settings']['siteLocale']=$publicLocale['tag'];
      if (!Templates::identifier($data['template'])) throw new HttpError(422,'Identificador de template inválido.','error.template_id');
    } else {
      $schema=json_decode((string)file_get_contents($this->config->root.'/config/schemas/page.schema.json'),true,64,JSON_THROW_ON_ERROR);
      Schema::validate($data,$schema);
      if ($data['schema_version']!==1 || $data['slug']!==$key || !in_array($data['status'],['draft','published'],true)) throw new HttpError(422,'Identidade, versão ou status inválido.','error.identity');
      if (in_array($key,['home','404'],true) && $data['status']!=='published') throw new HttpError(422,'Página obrigatória precisa permanecer publicada.','error.required_published');
      foreach (['title'=>200,'description'=>500] as $field=>$limit) if (trim($data['meta'][$field])==='' || strlen($data['meta'][$field])>$limit) throw new HttpError(422,'Metadado vazio ou excessivo.','error.metadata');
      $template??=$this->read('site')['data']['template'];
      $manifest=$this->templates->get($template);
      if (!isset($manifest['pages'][$data['page']])) throw new HttpError(422,'Página não suportada pelo template.','error.page_unsupported');
      $contracts=$this->templates->schema($template);
      if (!isset($contracts['pages'][$data['page']])) throw new HttpError(422,'Contrato de página ausente.','error.page_contract');
      Schema::validate($data['content'],$contracts['pages'][$data['page']],'content');
      Slides::page($data['content']);
    }
    return $data;
  }

  /** Lê documento validado e revisão dos bytes originais; recebe chave/template; retorna dados/revisão ou 404. */
  public function read(string $key,?string $template=null): array {
    $file=$this->path($key);
    if ($key==='templates' && !file_exists($file)) return ['data'=>['schema_version'=>1,'templates'=>[]],'revision'=>hash('sha256','')];
    if ($key==='plugins' && !file_exists($file)) return ['data'=>['schema_version'=>1,'plugins'=>[]],'revision'=>hash('sha256','')];
    if (!is_file($file)) throw new HttpError(404,'Documento não encontrado.','error.document_missing');
    $raw=file_get_contents($file);
    if ($raw===false) throw new HttpError(503,'Documento indisponível.','error.document_unavailable');
    return ['data'=>$this->document($key,$raw,$template),'revision'=>hash('sha256',$raw)];
  }

  /** Lista páginas validadas por slug; não escreve; falha em documento inválido em vez de ocultá-lo. */
  public function pages(?string $template=null): array {
    $template??=$this->read('site')['data']['template']; $pages=[];
    foreach (glob($this->config->data['contents'].'/pages/*.json') ?: [] as $file) {
      $key=basename($file,'.json'); $pages[$key]=$this->read($key,$template);
    }
    ksort($pages); return $pages;
  }

  /** Descobre templates e valida todas as páginas contra cada contrato; retorna opções compatíveis sem alterar conteúdo. */
  public function compatibleTemplates(): array {
    $options=[]; $this->incompatible=[];
    foreach ($this->templates->all() as $id=>$manifest) {
      try { $this->pages($id); $options[$id]=$manifest; }
      catch (HttpError $error) {
        if (!in_array($error->status,[413,422],true)) throw $error;
        $this->incompatible[$id]=$error->messageKey;
      }
    }
    return $options;
  }

  /** Retorna IDs/chaves dos templates incompatíveis na última descoberta; sem parâmetros ou efeitos. */
  public function incompatibleTemplates(): array { return $this->incompatible; }

  /** Executa mutação serializada; recebe callback, retorna resultado e permite serviços aninhados na mesma instância/trava; propaga falhas. */
  public function transaction(callable $callback): mixed {
    if ($this->locked) return $callback();
    Security::directory($this->config->data['storage']);
    $file=$this->config->data['storage'].'/contents.lock';
    if (is_link($file)) throw new HttpError(503,'Trava indisponível.','error.lock');
    $lock=fopen($file,'c+'); if ($lock===false) throw new HttpError(503,'Trava indisponível.','error.lock');
    try { if (!flock($lock,LOCK_EX)) throw new HttpError(503,'Trava indisponível.','error.lock');  $this->locked=true; return $callback(); }
    finally { $this->locked=false; flock($lock,LOCK_UN); fclose($lock); }
  }

  /** Publica bytes por rename no mesmo diretório; recebe caminho/bytes; sem retorno e sem fallback em falha. */
  private function atomic(string $file,string $bytes): void {
    $temporary=tempnam(dirname($file),'.cms2json-');
    if ($temporary===false) throw new HttpError(503,'Gravação indisponível.','error.write');
    try {
      $stream=fopen($temporary,'wb'); if ($stream===false) throw new HttpError(503,'Gravação indisponível.','error.write');
      try { if (fwrite($stream,$bytes)!==strlen($bytes) || !fflush($stream) || (function_exists('fsync')&&!fsync($stream))) throw new HttpError(503,'Gravação incompleta.','error.write_incomplete'); }
      finally { fclose($stream); }
      if (!chmod($temporary,0660) || !rename($temporary,$file)) throw new HttpError(503,'Publicação indisponível.','error.publish');
    } finally { if (is_file($temporary)) unlink($temporary); }
  }

  /** Serializa JSON legível preservando mapas vazios de extensões como objetos; recebe objeto e retorna bytes UTF-8 indentados; erros de codificação são propagados. */
  public static function encode(array $data): string {
    if (isset($data['plugins'])&&is_array($data['plugins'])) foreach ($data['plugins'] as &$state) if (is_array($state)&&isset($state['settings'])&&$state['settings']===[]) $state['settings']=(object)[];
    unset($state);
    foreach (['plugins','templates'] as $map) if (array_key_exists($map,$data) && $data[$map]===[]) $data[$map]=(object)[];
    return json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n"; }

  /** Valida idioma em mutação global; recebe documento validado, sem escrita; catálogo ausente gera 422. */
  private function language(array $data): void {
    if (isset($data['settings']['adminLocale']) && !(new Language())->available($data['settings']['adminLocale'])) throw new HttpError(422,'Idioma do painel indisponível.','error.language');
  }

  /** Salva snapshot limitado a vinte revisões; recebe chave/bytes; não retorna e falha antes de substituir conteúdo. */
  private function backup(string $key,string $bytes): void {
    $dir=$this->config->data['storage'].'/backups/'.$key; Security::directory($dir);
    $this->atomic($dir.'/'.(new \DateTimeImmutable('now',new \DateTimeZone('UTC')))->format('Ymd-His-u').'-'.bin2hex(random_bytes(6)).'.json',$bytes);
    $files=glob($dir.'/*.json') ?: []; rsort($files,SORT_STRING);
    foreach (array_slice($files,20) as $file) if (!unlink($file)) throw new HttpError(503,'Retenção de histórico indisponível.','error.retention');
  }

  /** Salva documento sob revisão e contrato; recebe chave/JSON/revisão, retorna versão nova; conflito gera 409. */
  public function save(string $key,string $raw,string $revision): array {
    return $this->transaction(function() use($key,$raw,$revision): array {
      $current=$this->read($key);
      if (!hash_equals($current['revision'],$revision)) throw new HttpError(409,'Outra edição foi salva. Reabra a página antes de substituir o conteúdo.','error.conflict');
      $data=$this->document($key,$raw);
      $this->validateMutation($key,$data);
      $file=$this->path($key); if (is_file($file)) $this->backup($key,(string)file_get_contents($file));
      $this->atomic($file,self::encode($data)); $this->audit('save',$key); return $this->read($key);
    });
  }

  /** Valida escrita e compatibilidade do padrão; recebe chave/dados, sem escrita; rejeita opções ou retorno ao padrão incompatíveis. */
  private function validateMutation(string $key,array $data): void {
    if ($key==='plugins') { (new Plugins($this))->validateDocument($data); return; }
    if ($key==='templates') {
      $installed=$this->templates->all();
      foreach ($data['templates'] as $id=>$options) if (isset($installed[$id])) Schema::validate($options,$this->templates->configuration($id)['schema']);
      return;
    }
    if ($key==='site') {
      $this->language($data); $this->templates->get($data['template']); $this->pages($data['template']); $this->pages(Templates::DEFAULT_ID); return;
    }
    $this->document($key,self::encode($data),Templates::DEFAULT_ID);
  }

  /** Cria rascunho por modelo existente; recebe slug/modelo, retorna documento; rejeita reservados/colisões. */
  public function create(string $key,string $model): array {
    return $this->transaction(function() use($key,$model): array {
      $file=$this->path($key);
      if (in_array($key,['site','plugins','templates','home','404'],true) || is_file($file)) throw new HttpError(409,'Identificador reservado ou já existente.','error.reserved');
      if (in_array($model,['site','plugins','templates','404'],true)) throw new HttpError(422,'Modelo de página inválido.','error.model');
      $data=$this->read($model)['data']; $data['slug']=$key; $data['status']='draft';
      $data=$this->document($key,self::encode($data)); $this->validateMutation($key,$data); $this->atomic($file,self::encode($data));
      $this->audit('create',$key); return $this->read($key);
    });
  }

  /** Move página para histórico recuperável sob revisão; recebe chave/revisão; não apaga páginas obrigatórias. */
  public function remove(string $key,string $revision): void {
    $this->transaction(function() use($key,$revision): void {
      if (in_array($key,['site','plugins','templates','home','404'],true)) throw new HttpError(422,'Documento obrigatório não pode ser removido.','error.required_remove');
      $current=$this->read($key);
      if (!hash_equals($current['revision'],$revision)) throw new HttpError(409,'Revisão desatualizada.','error.revision');
      $this->backup($key,(string)file_get_contents($this->path($key)));
      if (!unlink($this->path($key))) throw new HttpError(503,'Remoção indisponível.','error.remove');
      $this->audit('remove',$key);
    });
  }

  /** Lista backups por chave; retorna IDs seguros sem dados pessoais; não modifica o histórico. */
  public function history(string $key): array {
    $this->path($key); $files=glob($this->config->data['storage'].'/backups/'.$key.'/*.json') ?: [];
    $ids=array_map('basename',$files); rsort($ids,SORT_STRING); return $ids;
  }

  /** Restaura backup sanitizado inclusive página removida; recebe chave/ID/revisão, retorna documento; conflitos geram 409. */
  public function restore(string $key,string $id,string $revision): array {
    return $this->transaction(function() use($key,$id,$revision): array {
      if (!preg_match('/^[0-9]{8}-[0-9]{6}-[0-9]{6}-[a-f0-9]{12}\.json$/D',$id) || !in_array($id,$this->history($key),true)) throw new HttpError(422,'Backup inválido.','error.backup');
      $file=$this->path($key); $exists=is_file($file);
      if (($exists && !hash_equals($this->read($key)['revision'],$revision)) || (!$exists && $revision!=='')) throw new HttpError(409,'Revisão desatualizada.','error.revision');
      $backup=$this->config->data['storage'].'/backups/'.$key.'/'.$id;
      if (is_link($backup)) throw new HttpError(422,'Backup inseguro.','error.backup_unsafe');
      $data=$this->document($key,(string)file_get_contents($backup));
      $this->validateMutation($key,$data);
      if ($exists) $this->backup($key,(string)file_get_contents($file));
      $this->atomic($file,self::encode($data)); $this->audit('restore',$key); return $this->read($key);
    });
  }

  /** Registra somente operação/chave/data, sem documentos ou tokens; recebe strings e falha explicitamente se log indisponível. */
  private function audit(string $event,string $key): void {
    $bytes=json_encode(['at'=>gmdate(DATE_ATOM),'event'=>$event,'key'=>$key],JSON_THROW_ON_ERROR)."\n";
    if (file_put_contents($this->config->data['storage'].'/audit.jsonl',$bytes,FILE_APPEND|LOCK_EX)===false) throw new HttpError(503,'Auditoria indisponível.','error.audit');
  }
}
