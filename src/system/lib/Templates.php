<?php
/** Descobre templates privados e seus recursos públicos por manifesto, validando contratos e contenção de caminhos. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Templates {
  public const DEFAULT_ID = 'essencial';
  private array $rejected = [];
  private array $contracts = [];

  /** Recebe configuração e mantém referência sem efeitos externos. */
  public function __construct(private readonly Config $config) {}

  /** Descobre diretórios com manifesto; retorna somente válidos, registrando rejeitados sem interromper os demais. */
  public function all(): array {
    $items=[]; $this->rejected=[];
    foreach (glob($this->config->root.'/templates/*',GLOB_ONLYDIR) ?: [] as $directory) {
      $id=basename($directory);
      try { $items[$id]=$this->get($id); }
      catch (HttpError $error) { $this->rejected[$id]=$error->messageKey; }
    }
    ksort($items); return $items;
  }

  /** Retorna IDs/chaves de erro dos templates rejeitados pela última descoberta; sem efeitos ou dados de conteúdo. */
  public function invalid(): array { return $this->rejected; }

  /** Valida ID opaco antes de resolver caminhos; recebe string, retorna bool sem efeitos externos. */
  public static function identifier(string $id): bool { return preg_match('/^[A-Za-z][A-Za-z0-9-]{0,63}$/D',$id)===1; }

  /** Carrega objeto JSON privado limitado; recebe ID/arquivo; retorna mapa ou 422 sem expor bytes/caminhos. */
  private function json(string $id,string $file): array {
    $path=$this->path($id,'',$file);
    $raw=@file_get_contents($path,false,null,0,524289);
    if ($raw===false || strlen($raw)>524288) throw new HttpError(422,'Manifesto de template indisponível.','error.manifest_unavailable');
    try { $data=json_decode($raw,true,64,JSON_THROW_ON_ERROR); }
    catch (\JsonException) { throw new HttpError(422,'Manifesto de template inválido.','error.manifest'); }
    if (!is_array($data) || array_is_list($data)) throw new HttpError(422,'Manifesto de template inválido.','error.manifest');
    return $data;
  }

  /** Valida manifesto v2, scripts/contratos/assets e thumbnail PNG 16:9 declarado; recebe ID, retorna manifesto ou 422 sem escrita. */
  public function get(string $id): array {
    if (!self::identifier($id)) throw new HttpError(422,'Identificador de template inválido.','error.template_id');
    $manifest=$this->json($id,'template.json');
    if (($manifest['schema_version']??null)!==2 || ($manifest['id']??null)!==$id) throw new HttpError(422,'Manifesto de template inválido.','error.manifest');
    foreach (['name','version','author','description'] as $key) {
      $value=$manifest[$key]??null;
      if (!is_string($value) || trim($value)==='' || strlen($value)>1000 || strip_tags($value)!==$value || preg_match('/[\x00-\x1F\x7F]/',$value)) throw new HttpError(422,'Manifesto de template inválido.','error.manifest');
    }
    if (!preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?$/D',$manifest['version'])) throw new HttpError(422,'Manifesto de template inválido.','error.manifest');
    foreach (['pages','forms','partials'] as $group) {
      if (!is_array($manifest[$group]??null) || array_is_list($manifest[$group])) throw new HttpError(422,'Manifesto de template inválido.','error.manifest');
      foreach ($manifest[$group] as $key=>$file) {
        if (!is_string($file) || !preg_match('/^[A-Za-z0-9-]+$/D',(string)$key) || !str_ends_with($file,'.php')) throw new HttpError(422,'Manifesto de template inválido.','error.manifest');
        $this->path($id,$group,$file);
      }
    }
    foreach (['layout','header','footer'] as $key) if (!isset($manifest['partials'][$key])) throw new HttpError(422,'Partials obrigatórios ausentes.','error.partials');
    foreach (['home','404'] as $key) if (!isset($manifest['pages'][$key])) throw new HttpError(422,'Template precisa de home e 404.','error.required_pages');
    if (!isset($manifest['forms']['contact'])) throw new HttpError(422,'Template precisa do formulário contact.','error.contact_form');
    if (!is_string($manifest['contract']??null) || !str_ends_with($manifest['contract'],'.json')) throw new HttpError(422,'Contrato de página ausente.','error.page_contract');
    $contract=$this->json($id,$manifest['contract']);
    foreach ($manifest['pages'] as $key=>$file) if (!is_array($contract['pages'][$key]??null)) throw new HttpError(422,'Contrato de página ausente.','error.page_contract');
    $this->contracts[$id]=$contract;
    $assets=$manifest['assets']??null;
    if (!is_array($assets) || !is_array($assets['files']??null)) throw new HttpError(422,'Recursos obrigatórios do template inválidos.','error.template_assets');
    foreach (['styles'=>'css','scripts'=>'js'] as $group=>$extension) {
      if (!is_array($assets[$group]??null) || !array_is_list($assets[$group]) || ($group==='styles' && $assets[$group]===[])) throw new HttpError(422,'Recursos obrigatórios do template inválidos.','error.template_assets');
      foreach ($assets[$group] as $file) {
        if (!is_string($file) || !str_ends_with($file,'.'.$extension)) throw new HttpError(422,'Recursos obrigatórios do template inválidos.','error.template_assets');
        $this->publicPath($id,$file);
      }
    }
    foreach ($assets['files'] as $key=>$file) {
      if (!is_string($file) || !preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D',(string)$key) || !preg_match('/\.(?:png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|otf)$/Di',$file)) throw new HttpError(422,'Recursos obrigatórios do template inválidos.','error.template_assets');
      $this->publicPath($id,$file);
    }
    $thumbnail=$assets['files']['thumbnail']??null;
    if (!is_string($thumbnail) || !str_ends_with($thumbnail,'.png')) throw new HttpError(422,'Thumbnail PNG obrigatório.','error.template_assets');
    try { ExtensionMedia::thumbnail($this->publicPath($id,$thumbnail)); }
    catch (\RuntimeException) { throw new HttpError(422,'Thumbnail deve ser PNG válido em 16:9.','error.template_assets'); }
    $favicon=$assets['files']['favicon']??null;
    if (!is_string($favicon) || !str_ends_with($favicon,'.ico')) throw new HttpError(422,'Favicon ICO obrigatório.','error.template_assets');
    try {ExtensionMedia::ico($this->publicPath($id,$favicon));}
    catch (\RuntimeException) {throw new HttpError(422,'Favicon ICO inválido.','error.template_assets');}
    if (isset($manifest['configuration'])) {
      $language=$this->language($id,'pt-br');
      if (isset($manifest['configuration'])) {
        $definition=$manifest['configuration'];
        if (!is_array($definition) || !is_string($definition['schema']??null) || !is_array($definition['defaults']??null)) throw new HttpError(422,'Opções inválidas.','error.extension_options');
        $options=$this->json($id,$definition['schema']); OptionFields::contract($options,$language); Schema::validate($definition['defaults'],$options);
      }
    }
    Navigation::locations($manifest);
    Slides::locations($manifest);
    return $manifest;
  }

  /** Carrega idioma privado sem executar PHP; recebe ID/locale e retorna catálogo; falhas são 422 sem caminhos internos. */
  public function language(string $id,string $locale): Language {
    if (!self::identifier($id) || is_link($this->config->root.'/templates/'.$id)) throw new HttpError(422,'Template inválido.','error.template_id');
    try { $language=new Language($this->config->root.'/templates/'.$id.'/languages'); $language->select($locale); return $language; }
    catch (\RuntimeException) { throw new HttpError(422,'Idioma do template inválido.','error.manifest'); }
  }
  /** Retorna metadados literais; recebe ID/locale administrativo compatível, sem traduzir manifesto ou alterar dados. */
  public function labels(string $id,string $locale): array {
    $manifest=$this->get($id);return ['name'=>$manifest['name'],'description'=>$manifest['description']];
  }
  /** Obtém schema/defaults de configuração declarada; recebe ID e retorna contrato ou 404 quando indisponível. */
  public function configuration(string $id): array {
    $manifest=$this->get($id); $definition=$manifest['configuration']??null;
    if (!is_array($definition)) throw new HttpError(404,'Sem configurações.','error.extension_options');
    return ['schema'=>$this->json($id,$definition['schema']),'defaults'=>$definition['defaults']];
  }
  /** Resolve script privado regular; recebe ID/grupo/arquivo simples, retorna caminho contido ou 422. */
  public function path(string $id,string $group,string $file): string {
    if (!self::identifier($id) || !in_array($group,['','pages','partials','forms'],true) || !preg_match('/^[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.(php|json)$/D',$file)) throw new HttpError(422,'Caminho de template inválido.','error.template_path');
    $base=$this->config->root.'/templates'; $dir=$base.'/'.$id; $text=$dir.($group!==''?'/'.$group:'').'/'.$file;
    $real=realpath($text); $realDir=realpath($dir);
    if (is_link($base) || $real===false || $realDir===false || is_link($dir) || ($group!==''&&is_link($dir.'/'.$group)) || is_link($text) || !is_file($real) || !str_starts_with($real,$realDir.'/')) throw new HttpError(422,'Arquivo de template ausente ou inseguro.','error.template_file');
    return $real;
  }

  /** Resolve recurso local sob public_html/templates/ID; recebe ID/caminho, rejeita traversal/symlinks e usa minificados no pacote. */
  public function publicPath(string $id,string $relative): string {
    if (!self::identifier($id) || !preg_match('~^[A-Za-z0-9][A-Za-z0-9_.-]*(?:/[A-Za-z0-9][A-Za-z0-9_.-]*)*$~D',$relative) || in_array('assets',explode('/',$relative),true) || preg_match('/\.(?:php[0-9]*|phtml|phar)$/Di',$relative)) throw new HttpError(422,'Recursos obrigatórios do template inválidos.','error.template_assets');
    if (is_file($this->config->root.'/config/production.php') && !preg_match('/\.min\.(css|js)$/D',$relative)) $relative=preg_replace('/\.(css|js)$/D','.min.$1',$relative);
    $base=dirname($this->config->root).'/public_html/templates'; $dir=$base.'/'.$id;
    $cursor=$base;
    foreach (array_merge([$id],explode('/',$relative)) as $part) {
      $cursor.='/'.$part;
      if (is_link($cursor)) throw new HttpError(422,'Recursos obrigatórios do template inválidos.','error.template_assets');
    }
    $real=realpath($cursor); $realDir=realpath($dir);
    if (is_link($base) || $real===false || $realDir===false || !is_file($real) || !str_starts_with($real,$realDir.'/')) throw new HttpError(422,'Recursos obrigatórios do template inválidos.','error.template_assets');
    return $real;
  }

  /** Retorna URL de recurso validado, respeitando minificação; recebe ID/caminho/base, sem escrita ou execução. */
  public function asset(string $id,string $relative,string $base=''): string {
    $this->publicPath($id,$relative); return asset('templates/'.$id.'/'.$relative,$base);
  }

  /** Carrega contrato declarado; recebe ID, retorna mapa validado; contratos inválidos geram 422. */
  public function schema(string $id): array { $this->get($id); return $this->contracts[$id]; }
}
