<?php
/** Registro de plugins API 1: descoberta por metadados/rotas/navegação, ativação explícita e configuração revisionada. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Plugins {
  private array $catalog = [];
  private array $rejected = [];
  /** Descobre manifests sem execução; recebe store; configuration=false dispensa opções; inválidos ficam diagnosticáveis por ID. */
  public function __construct(private readonly JsonStore $store) {
    $root=$store->config->root.'/plugins';
    if (is_link($root)) throw new HttpError(503,'Registro inseguro.','error.plugin');
    foreach (glob($root.'/*',GLOB_ONLYDIR) ?: [] as $directory) {
      $id=basename($directory);
      try {
        if (!self::identifier($id) || is_link($directory)) throw new \RuntimeException('Diretório inválido.');
        $manifest=self::json($directory.'/plugin.json');
        if (($manifest['id']??null)!==$id || ($manifest['api']??null)!==1 || ($manifest['cmsMajor']??null)!==2 || !is_string($manifest['version']??null) || !preg_match('/^\d+\.\d+\.\d+$/D',$manifest['version']) || !is_string($manifest['name']??null) || strlen($manifest['name'])>100 || strip_tags($manifest['name'])!==$manifest['name'] || ($manifest['entry']??null)!=='Plugin.php' || !is_string($manifest['class']??null) || !is_array($manifest['assets']??null) || !preg_match('/^DBLESSCMS\\\\[A-Za-z]+$/D',$manifest['class']??'')) throw new \RuntimeException('Manifesto incompatível.');
        if (isset($manifest['description'])&&(!is_string($manifest['description'])||strlen($manifest['description'])>1000||strip_tags($manifest['description'])!==$manifest['description']||preg_match('/[\x00-\x1F\x7F]/',$manifest['description']))) throw new \RuntimeException('Descrição inválida.');
        foreach (['Plugin.php','settings.schema.json','languages/pt-br.json'] as $file) $this->regular($directory,$file);
        $public=dirname($store->config->root).'/public_html/plugins/'.$id;
        $icon=$manifest['assets']['icon']??null;
        if (!is_string($icon) || !preg_match('~^[A-Za-z0-9][A-Za-z0-9_-]*(?:/[A-Za-z0-9][A-Za-z0-9_-]*)*\.svg$~D',$icon)) throw new \RuntimeException('Ícone do plugin inválido.');
        $this->regular($public,$icon);
        ExtensionMedia::icon($public.'/'.$icon);
        foreach (['css','js'] as $type) {
          $asset=$manifest['assets'][$type]??'';
          if (!is_string($asset) || !preg_match('~^'.$type.'/[a-z0-9-]+\.'.$type.'$~D',$asset)) throw new \RuntimeException('Asset inválido.');
          $public=dirname($store->config->root).'/public_html/plugins/'.$id;
          $this->regular($public,$asset,is_file($store->config->root.'/config/production.php'));
        }
        $schema=self::json($directory.'/settings.schema.json');
        $language=new Language($directory.'/languages');
        $this->settingsSchema($schema,$language);
        $this->routes($directory,$manifest,$language);
        if (isset($manifest['configuration'])&&$manifest['configuration']!==false) {
          $configuration=$manifest['configuration'];
          if (!is_string($configuration) || !isset($manifest['routes'][$configuration]) || ($manifest['routes'][$configuration]['visibility']??'private')!=='private' || $manifest['routes'][$configuration]['requiresEnabled']!==false) throw new \RuntimeException('Rota de configuração inválida.');
        }
        if (isset($manifest['publicSettings'])) {
          if (!is_array($manifest['publicSettings']) || !array_is_list($manifest['publicSettings']) || count($manifest['publicSettings'])>50) throw new \RuntimeException('Campos públicos inválidos.');
          foreach ($manifest['publicSettings'] as $key) if (!is_string($key) || !isset($schema['properties'][$key])) throw new \RuntimeException('Campo público sem contrato.');
        }
        Schema::validate($manifest['defaults']??null,$schema);
        $this->catalog[$id]=$manifest;
      } catch (\RuntimeException|HttpError $failure) { $this->rejected[$id]='error.plugin'; }
    }
    ksort($this->catalog);
  }
  /** Valida ID opaco; recebe string e retorna bool, sem resolver caminhos. */
  public static function identifier(string $id): bool { return (bool)preg_match('/^[a-z][a-z0-9-]{0,63}$/D',$id); }
  /** Valida rotas e navegação declaradas sem executar scripts; recebe raiz/manifesto/idioma; rejeita destinos externos e desconhecidos. */
  private function routes(string $directory,array $manifest,Language $language): void {
    if (!isset($manifest['routes'])) {
      if (isset($manifest['navigation'])) throw new \RuntimeException('Navegação sem rotas.');
      return;
    }
    if (($manifest['router']??null)!=='Router.php' || !is_array($manifest['routes']) || $manifest['routes']===[] || array_is_list($manifest['routes']) || count($manifest['routes'])>30) throw new \RuntimeException('Registro de rotas inválido.');
    $this->regular($directory,'Router.php');
    foreach ($manifest['routes'] as $id=>$route) {
      if (!is_string($id) || !self::identifier($id) || !is_array($route) || !is_string($route['title']??null) || !$language->has($route['title']) || !is_bool($route['requiresEnabled']??null) || !is_string($route['file']??null) || !preg_match('~^pages/[a-z][a-z0-9-]*\.php$~D',$route['file'])) throw new \RuntimeException('Rota incompatível.');
      if (isset($route['description']) && (!is_string($route['description']) || !$language->has($route['description']))) throw new \RuntimeException('Descrição de rota inválida.');
      $visibility=$route['visibility']??'private';
      if (!in_array($visibility,['private','public'],true)) throw new \RuntimeException('Visibilidade de rota inválida.');
      if ($visibility==='public') {
        $methods=$route['methods']??['GET'];
        if ($route['requiresEnabled']!==true || !is_array($methods) || !array_is_list($methods) || $methods===[] || count($methods)!==count(array_unique($methods,SORT_REGULAR))) throw new \RuntimeException('Contrato público inválido.');
        foreach ($methods as $method) if (!in_array($method,['GET','POST'],true)) throw new \RuntimeException('Método público inválido.');
        if (isset($route['maxBodyBytes']) && (!is_int($route['maxBodyBytes']) || $route['maxBodyBytes']<1 || $route['maxBodyBytes']>10485760)) throw new \RuntimeException('Limite público inválido.');
      }
      $this->regular($directory,$route['file']);
    }
    if (!isset($manifest['navigation'])) return;
    $menu=$manifest['navigation'];
    if (!is_array($menu) || !is_string($menu['label']??null) || !$language->has($menu['label']) || count(array_diff(array_keys($menu),['label','route','items','icon']))>0) throw new \RuntimeException('Menu inválido.');
    if (isset($menu['icon'])&&!in_array($menu['icon'],['image','pages','menu'],true)) throw new \RuntimeException('Ícone inválido.');
    if (isset($menu['items'])) {
      if (isset($menu['route']) || !is_array($menu['items']) || !array_is_list($menu['items']) || count($menu['items'])<1 || count($menu['items'])>20) throw new \RuntimeException('Submenu inválido.');
      $items=$menu['items'];
    } else $items=[['label'=>$menu['label'],'route'=>$menu['route']??null]];
    $separator=true; $seen=[];
    foreach ($items as $item) {
      if (is_array($item) && $item===['separator'=>true]) {
        if ($separator) throw new \RuntimeException('Separador inválido.');
        $separator=true; continue;
      }
      if (!is_array($item) || count(array_diff(array_keys($item),['label','route']))>0 || !is_string($item['label']??null) || !$language->has($item['label']) || !is_string($item['route']??null) || !isset($manifest['routes'][$item['route']]) || ($manifest['routes'][$item['route']]['visibility']??'private')!=='private' || isset($seen[$item['route']])) throw new \RuntimeException('Destino de menu inválido.');
      $seen[$item['route']]=true; $separator=false;
    }
    if ($separator) throw new \RuntimeException('Separador final inválido.');
  }
  /** Monta menus por manifesto/dicionário; recebe locale, retorna dados com ícone próprio, sem executar plugins ou resolver nomes globais legados. */
  public function navigation(string $locale): array {
    $menus=[];
    foreach ($this->catalog as $id=>$manifest) {
      if (!isset($manifest['navigation'])) continue;
      $language=$this->language($id,$locale); $menu=$manifest['navigation'];
      $items=$menu['items']??[['label'=>$menu['label'],'route'=>$menu['route']]];
      foreach ($items as &$item) if (isset($item['label'])) $item['label']=$language->text($item['label']);
      unset($item);
      $menus[$id]=['label'=>$language->text($menu['label']),'dropdown'=>isset($menu['items']),'icon'=>$manifest['assets']['icon'],'items'=>$items];
    }
    return $menus;
  }
  /** Resolve SVG declarado dentro do pacote público; recebe ID/base, retorna URL escapável ou 404 para plugin inválido, sem escrita. */
  public function icon(string $id,string $base=''): string {
    $manifest=$this->get($id);return asset('plugins/'.$id.'/'.$manifest['assets']['icon'],$base);
  }
  /** Resolve rota apenas no escopo solicitado; recebe plugin/ID/visibilidade, retorna metadados ou 404 sem executar código. */
  public function route(string $id,string $route='',string $visibility='private'): array {
    if (!in_array($visibility,['private','public'],true)) throw new \InvalidArgumentException('Escopo de rota inválido.');
    $manifest=$this->get($id); $routes=array_filter($manifest['routes']??[],static fn(array $item): bool => ($item['visibility']??'private')===$visibility);
    $route=$route===''?(string)array_key_first($routes):$route;
    if (!isset($routes[$route])) throw new HttpError(404,'Página de plugin não encontrada.','error.not_found');
    return ['id'=>$route]+$routes[$route];
  }
  /** Monta contexto público sem serviços privados; recebe ID/base/locale/rota/método/token; inclui somente settings declarados publicáveis. */
  public function publicContext(string $id,string $base,string $locale,string $route,string $method,string $token): PublicPluginContext {
    $manifest=$this->get($id); $page=$this->route($id,$route,'public'); $state=$this->state($id);
    if (!$state['enabled']) throw new HttpError(404,'Plugin indisponível.','error.not_found');
    Schema::validate($state['settings'],$this->schema($id));
    $settings=array_intersect_key($state['settings'],array_fill_keys($manifest['publicSettings']??[],true));
    return new PublicPluginContext($id,$page['id'],$base,$this->language($id,$locale),$settings,$token,$method);
  }
  /** Valida campos declarativos API 1 tipados; recebe schema/idioma/profundidade e rejeita limites ou metadados incompletos. */
  private function settingsSchema(array $schema,Language $language,int $depth=0): void {
    if ($depth>8 || ($schema['type']??null)!=='object' || ($schema['additionalProperties']??null)!==false || !is_array($schema['properties']??null) || count($schema['properties'])>50) throw new \RuntimeException('Schema de configuração inválido.');
    if (!is_array($schema['required']??[]) || !array_is_list($schema['required']??[])) throw new \RuntimeException('Campos obrigatórios inválidos.');
    foreach ($schema['required']??[] as $key) if (!is_string($key) || !array_key_exists($key,$schema['properties'])) throw new \RuntimeException('Campo obrigatório desconhecido.');
    foreach ($schema['properties'] as $key=>$field) {
      if (!is_string($key) || !preg_match('/^[a-z][a-zA-Z0-9]*$/D',$key) || !is_array($field) || !is_string($field['title']??null) || !$language->has($field['title'])) throw new \RuntimeException('Campo sem contrato/tradução.');
      if (($field['type']??null)==='object') $this->settingsSchema($field,$language,$depth+1);
      elseif (($field['type']??null)==='string') {
        if (!is_int($field['maxLength']??null) || $field['maxLength']<1 || $field['maxLength']>12000) throw new \RuntimeException('Limite de texto inválido.');
      } elseif (($field['type']??null)==='integer') {
        if (!is_int($field['minimum']??null) || !is_int($field['maximum']??null) || $field['minimum']>$field['maximum']) throw new \RuntimeException('Limite numérico inválido.');
      } elseif (($field['type']??null)!=='boolean') throw new \RuntimeException('Tipo de campo não suportado.');
      if (isset($field['enum'])) {
        if ($field['type']!=='string' || !is_array($field['enum']) || !array_is_list($field['enum']) || $field['enum']===[] || count($field['enum'])>50 || !is_array($field['enumLabels']??null)) throw new \RuntimeException('Seleção inválida.');
        foreach ($field['enum'] as $value) {
          Schema::validate($value,array_diff_key($field,['enum'=>true]));
          if (!is_string($field['enumLabels'][$value]??null) || !$language->has($field['enumLabels'][$value])) throw new \RuntimeException('Opção sem tradução.');
        }
      }
    }
  }
  /** Lê JSON privado limitado sem links; recebe arquivo, retorna objeto ou erro seguro de manifesto. */
  private static function json(string $file): array {
    if (is_link($file) || !is_file($file) || filesize($file)>131072) throw new \RuntimeException('Manifesto indisponível.');
    try { $data=json_decode((string)file_get_contents($file),true,16,JSON_THROW_ON_ERROR); }
    catch (\JsonException) { throw new \RuntimeException('JSON inválido.'); }
    if (!is_array($data)) throw new \RuntimeException('Objeto obrigatório.'); return $data;
  }
  /** Exige arquivo contido e regular; recebe raiz/caminho e flag minificada; falha sem executar o arquivo. */
  private function regular(string $root,string $file,bool $minified=false): void {
    if ($minified) $file=preg_replace('/\.(css|js)$/','.min.$1',$file);
    $parts=explode('/',$file); $path=$root;
    $ancestor='';
    foreach (explode('/',ltrim($root,'/')) as $part) { $ancestor.='/'.$part; if (is_link($ancestor)) throw new \RuntimeException('Raiz insegura.'); }
    foreach ($parts as $part) { $path.='/'.$part; if (is_link($path)) throw new \RuntimeException('Link proibido.'); }
    if (!is_file($path)) throw new \RuntimeException('Arquivo obrigatório ausente.');
  }
  /** Retorna manifests válidos descobertos; sem execução ou efeitos. */
  public function all(): array { return $this->catalog; }
  /** Retorna IDs rejeitados sem dados privados; sem efeitos. */
  public function invalid(): array { return $this->rejected; }
  /** Retorna documento de plugins revisionado; ausência em instalações anteriores usa configuração vazia desativada. */
  public function document(): array { return $this->store->read('plugins'); }
  /** Retorna estado com defaults aditivos de opções; recebe ID válido; não escreve nem altera ativação. */
  public function state(string $id): array {
    $manifest=$this->get($id); $state=$this->document()['data']['plugins'][$id]??['enabled'=>false,'settings'=>[]];
    $state['settings']=array_replace_recursive($manifest['defaults'],$state['settings']);return $state;
  }
  /** Consulta manifesto por ID opaco; retorna objeto ou 404. */
  public function get(string $id): array { return $this->catalog[$id]??throw new HttpError(404,'Plugin indisponível.','error.plugin'); }
  /** Carrega código local confiável apenas após seleção explícita; recebe ID e retorna contrato implementado ou 503. */
  public function instance(string $id): PluginInterface {
    $manifest=$this->get($id); require_once $this->store->config->root.'/plugins/'.$id.'/'.$manifest['entry'];
    $class=$manifest['class']; $plugin=new $class();
    if (!$plugin instanceof PluginInterface) throw new HttpError(503,'Contrato de plugin inválido.','error.plugin');
    return $plugin;
  }
  /** Monta contexto habilitado; recebe ID/base/locale/rota/modo integrado; valida configurações e falha com 404 se desativado. */
  public function context(string $id,string $base,string $locale,string $route='',bool $integrated=false): PluginContext {
    $manifest=$this->get($id); $state=$this->state($id);
    $page=isset($manifest['routes'])?$this->route($id,$route):null;
    if (!$state['enabled'] && ($page['requiresEnabled']??true)) throw new HttpError(404,'Plugin desativado.','error.plugin');
    Schema::validate($state['settings'],self::json($this->store->config->root.'/plugins/'.$id.'/settings.schema.json'));
    if ($page['requiresEnabled']??true) $this->instance($id)->validateSettings($state['settings'],$this->store->config);
    $language=$this->language($id,$locale);
    return new PluginContext($this->store->config,$this->store,$manifest,$state['settings'],$this->document()['revision'],$base,$language,$page['id']??$route,$integrated);
  }
  /** Carrega idioma próprio com fallback pt-br; recebe ID/locale e retorna catálogo validado sem escrita. */
  public function language(string $id,string $locale): Language {
    $this->get($id); $language=new Language($this->store->config->root.'/plugins/'.$id.'/languages'); $language->select($locale); return $language;
  }
  /** Salva ativação/configuração sob revisão global; recebe ID/flag/dados/revisão; valida antes de gravar/backupear. */
  public function save(string $id,bool $enabled,array $settings,string $revision): void {
    $this->get($id); Schema::validate($settings,self::json($this->store->config->root.'/plugins/'.$id.'/settings.schema.json'));
    $this->instance($id)->validateSettings($settings,$this->store->config);
    $document=$this->document()['data']; $document['plugins'][$id]=['enabled'=>$enabled,'settings'=>$settings];
    $this->store->save('plugins',JsonStore::encode($document),$revision);
  }
  /** Salva somente opções declaradas; recebe ID/dados/revisão e preserva ativação; 404 sem configuração, falhas não gravam. */
  public function saveOptions(string $id,array $settings,string $revision): void {
    if (($this->get($id)['configuration']??null)===false) throw new HttpError(404,'Plugin sem configuração.','error.not_found');
    $this->save($id,$this->state($id)['enabled'],$settings,$revision);
  }
  /** Restaura plugin selecionado; recebe ID/backup/revisão e opção de preservar ativação, mantém demais extensões; rejeita opções indisponíveis, links, contratos e conflitos. */
  public function restore(string $id,string $backup,string $revision,bool $optionsOnly=false): void {
    $manifest=$this->get($id);
    if ($optionsOnly&&($manifest['configuration']??null)===false) throw new HttpError(404,'Plugin sem configuração.','error.not_found');
    if (!preg_match('/^[0-9]{8}-[0-9]{6}-[0-9]{6}-[a-f0-9]{12}\.json$/D',$backup) || !in_array($backup,$this->store->history('plugins'),true)) throw new HttpError(422,'Backup inválido.','error.backup');
    $directory=$this->store->config->data['storage'].'/backups/plugins';
    try { $this->regular($directory,$backup); }
    catch (\RuntimeException) { throw new HttpError(422,'Backup inseguro.','error.backup_unsafe'); }
    $raw=file_get_contents($directory.'/'.$backup);
    if ($raw===false) throw new HttpError(503,'Backup indisponível.','error.document_unavailable');
    $document=$this->store->document('plugins',$raw);
    $state=$document['plugins'][$id]??['enabled'=>false,'settings'=>$manifest['defaults']];
    $this->save($id,$optionsOnly?$this->state($id)['enabled']:$state['enabled'],array_replace_recursive($manifest['defaults'],$state['settings']),$revision);
  }
  /** Valida todos os estados antes de salvar/restaurar; recebe documento, sem escrita; código local valida raízes/contratos. */
  public function validateDocument(array $document): void {
    foreach ($document['plugins'] as $id=>$state) {
      if (!isset($this->catalog[$id]) && !$state['enabled']) continue;
      $this->get($id); Schema::validate($state['settings'],$this->schema($id));
      $this->instance($id)->validateSettings($state['settings'],$this->store->config);
    }
  }
  /** Retorna metadados literais; recebe ID/locale administrativo compatível e retorna texto, sem executar entrypoint ou gravar. */
  public function labels(string $id,string $locale): array {
    $manifest=$this->get($id);
    return ['name'=>$manifest['name'],'description'=>$manifest['description']??''];
  }
  /** Retorna schema de configuração para formulário genérico; recebe ID e não executa código do plugin. */
  public function schema(string $id): array { $this->get($id); return self::json($this->store->config->root.'/plugins/'.$id.'/settings.schema.json'); }
}
