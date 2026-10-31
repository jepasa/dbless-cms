<?php
/** Painel dbless-cms independente dos templates públicos: autenticação, conteúdo, histórico e seleção de tema. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Admin {
  /** Recebe serviços, base pública e idioma selecionado; armazena contexto sem escrita externa. */
  public function __construct(private readonly Config $config,private readonly JsonStore $store,private readonly string $base,private readonly Language $language) {}

  /** Extrai string limitada de formulário; recebe chave/limite, retorna string ou lança 400. */
  private function input(string $name,int $limit=128): string {
    $value=$_POST[$name]??'';
    if (!is_string($value) || strlen($value)>$limit) throw new HttpError(400,'Campo administrativo inválido.','error.admin_field');
    return $value;
  }

  /** Redireciona após mutação; recebe view, documento e chave de tradução, guarda flash tipado e encerra com 303. */
  private function redirect(string $view,string $key='',string $message='notice.saved'): never {
    $_SESSION['notice_key']=$message;
    header('Location: '.$this->base.'/cms-admin/?view='.rawurlencode($view).($key!==''?'&key='.rawurlencode($key):''),true,303); exit;
  }

  /** Processa mutações autenticadas e CSRF; não retorna em sucesso; erros mantêm conteúdo e revisão. */
  private function post(): void {
    $action=$this->input('action');
    if ($action==='login') {
      Security::limit($this->config,'login',5,900); Security::csrf($_POST['csrf_token']??null);
      if (!Security::login($this->config,$this->input('username'),$this->input('password',200))) throw new HttpError(401,'Usuário ou senha inválidos.','error.credentials');
      $this->redirect('dashboard','','notice.login');
    }
    if (!Security::authenticated($this->config)) throw new HttpError(403,'Autenticação necessária.','error.authentication');
    Security::csrf($_POST['csrf_token']??null);
    if ($action==='cache-clear') {
      $cache=new Cache($this->config,$this->store->read('site')['data']['settings']['cache']??[]);
      if (!$cache->clear()) throw new HttpError(503,'Cache unavailable.','cache.unavailable');
      $this->redirect('dashboard','','cache.cleared');
    }
    if ($action==='logout') {
      $_SESSION=[]; session_regenerate_id(true); $this->redirect('login','','notice.logout');
    }
    if (in_array($action,['extension-toggle','extension-uninstall'],true)) {
      $kind=$this->input('kind'); $id=$this->input('id'); $revision=$this->input('revision'); $manager=new Extensions($this->store);
      if ($action==='extension-uninstall') $manager->uninstall($kind,$id,$revision,$this->input('confirmation'));
      else {
        $enabled=$this->input('enabled');
        if (!in_array($enabled,['0','1'],true)) throw new HttpError(422,'Estado inválido.','error.operation');
        $manager->toggle($kind,$id,$enabled==='1',$revision);
      }
      $this->redirect($kind,'','notice.extension');
    }
    if ($action==='template-options') {
      $options=$_POST['options']??null;
      if (!is_array($options)) throw new HttpError(422,'Opções inválidas.','error.extension_options');
      $id=$this->input('template');
      (new Extensions($this->store))->saveOptions($id,$options,$this->input('revision'));
      $_SESSION['notice_key']='notice.saved'; header('Location: '.$this->base.'/cms-admin/?view=template-settings&template='.rawurlencode($id),true,303); exit;
    }
    if ($action==='plugin-settings') {
      $plugins=new Plugins($this->store); $settings=$_POST['settings']??[];
      if (!is_array($settings)) throw new HttpError(422,'Configuração inválida.','error.plugin');
      $id=$this->input('plugin');
      $plugins->saveOptions($id,OptionFields::input($plugins->schema($id),$settings),$this->input('revision'));
      $this->redirect('plugins');
    }
    $key=$this->input('key'); $revision=$this->input('revision');
    // Adaptador de formulários 2.0: o domínio editorial pertence ao plugin Pages.
    if (in_array($action,['create','save','remove','restore'],true)&&!in_array($key,['site','plugins','templates'],true)) {
      $routes=['create'=>'new','save'=>'editor','remove'=>'editor','restore'=>'history'];$registry=new Plugins($this->store);
      $context=$registry->context('pages',$this->base,$this->language->locale(),$routes[$action]);
      $plugin=$registry->instance('pages');
      if (!$plugin instanceof PagesPlugin) throw new HttpError(404,'Plugin indisponível.','error.plugin');
      $result=$plugin->change($context,$action,$_POST);$_SESSION['notice_key']=$result['notice'];header('Location: '.$result['redirect'],true,303);exit;
    }
    switch ($action) {
      case 'save':
        if (in_array($key,['plugins','templates'],true)) throw new HttpError(422,'Use as configurações de plugins.','error.plugin');
        $raw=$this->input('document',524288);
        if ($key==='site' && (isset($_POST['admin_locale']) || isset($_POST['site_template']) || isset($_POST['site_locale']) || isset($_POST['site_favicon']))) {
          try { $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR); }
          catch (\JsonException) { throw new HttpError(422,'JSON inválido.','error.json'); }
          if (!is_array($data) || !is_array($data['settings']??null)) throw new HttpError(422,'Documento precisa ser um objeto JSON.','error.document_object');
          if (isset($_POST['admin_locale'])) $data['settings']['adminLocale']=$this->input('admin_locale',64);
          if (isset($_POST['site_locale'])) $data['settings']['siteLocale']=$this->input('site_locale',64);
          if (isset($_POST['site_favicon'])) $data['settings']['favicon']=$this->input('site_favicon',240);
          if (isset($_POST['site_template']) && $_POST['site_template']!==$this->store->read('site')['data']['template']) throw new HttpError(422,'Use the template catalog.','error.template_id');
          $raw=JsonStore::encode($data);
        }
        if ($key==='site') {
          try {$submitted=json_decode($raw,true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new HttpError(422,'Invalid JSON.','error.json');}
          if (($submitted['template']??null)!==$this->store->read('site')['data']['template']) throw new HttpError(422,'Use the template catalog.','error.template_id');
        }
        $this->store->save($key,$raw,$revision);
        $this->redirect($key==='site'?'site':'edit',$key,'notice.content');
      case 'restore':
        $this->store->restore($key,$this->input('backup'),$revision);
        $this->redirect($key==='plugins'?'plugins':($key==='site'?'site':'edit'),$key,'notice.restored');
      case 'template':
        (new Extensions($this->store))->toggle('templates',$this->input('template'),true,$revision); $this->redirect('templates','','notice.template');
      default: throw new HttpError(400,'Operação não reconhecida.','error.operation');
    }
  }

  /** Despacha painel/preview; retorna HTML ou redireciona; erros de edição preservam documento/status; configuração explicitamente ausente retorna 404. */
  public function run(): string {
    if (($this->config->credentials['enabled']??true)!==true) throw new HttpError(404,'Página não encontrada.','error.not_found');
    if (($_GET['view']??'')==='plugin-api') return $this->pluginApi();
    $error=null;
    if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
      try { $this->post(); }
      catch (HttpError $failure) { http_response_code($failure->status); $error=$this->translatePluginError($failure); }
    } elseif (($_SERVER['REQUEST_METHOD']??'GET')!=='GET') { header('Allow: GET, POST'); throw new HttpError(405,'Método não permitido.','error.method'); }
    $authenticated=Security::authenticated($this->config);
    $view=$_GET['view']??'dashboard'; $key=$_GET['key']??'home';
    if (!is_string($view) || !is_string($key)) throw new HttpError(400,'Rota administrativa inválida.','error.admin_route');
    if (!in_array($view,['dashboard','pages','page-new','page-trash','edit','site','templates','history','preview','login','plugins','plugin','plugin-panel','plugin-settings','template-settings'],true)) throw new HttpError(404,'Página não encontrada.','error.not_found');
    if (!$authenticated) $view='login';
    $plugins=$authenticated?new Plugins($this->store):null;
    $requestedPlugin=$_GET['plugin']??'';$requestedRoute=$_GET['route']??'';
    $aliases=['pages'=>'overview','page-new'=>'new','page-trash'=>'trash','edit'=>'editor'];
    if ($view==='history'&&!in_array($key,['site','plugins','templates'],true)) $aliases['history']='history';
    if ($authenticated&&isset($aliases[$view])) {
      if (!isset($plugins->all()['pages'])||!$plugins->state('pages')['enabled']) $view='plugins';
      else {$requestedPlugin='pages';$requestedRoute=$aliases[$view];$view='plugin-panel';}
    }
    if ($view==='plugin-settings'&&is_string($requestedPlugin)) {
      $manifest=$plugins->get($requestedPlugin);
      if (($manifest['configuration']??null)===false) throw new HttpError(404,'Plugin sem configuração.','error.not_found');
      if (isset($manifest['configuration'])) {$requestedRoute=$manifest['configuration'];$view='plugin-panel';}
    }
    $manager=new Extensions($this->store);
    $extensionRows=$authenticated&&in_array($view,['plugins','templates'],true)?$manager->catalog($view,$this->language->locale()):[];
    $managementRevision=$authenticated?$this->store->read($view==='plugins'?'plugins':'site')['revision']:'';
    $settingsPlugin=null; $themeOptions=null; $themeId=''; $themeLanguage=null;
    if ($view==='plugin-settings') {
      $settingsPlugin=$_GET['plugin']??''; if (!is_string($settingsPlugin)) throw new HttpError(400,'Plugin inválido.','error.admin_route');
      $plugins->get($settingsPlugin);
    }
    if ($view==='template-settings') {
      $themeId=$_GET['template']??''; if (!is_string($themeId)) throw new HttpError(400,'Template inválido.','error.admin_route');
      $themeOptions=$manager->options($themeId); $themeLanguage=$this->store->templates->language($themeId,$this->language->locale());
    }
    $pluginMenus=$plugins?->navigation($this->language->locale())??[];
    $panelId=''; $panelRoute=null; $panelLanguage=null; $panelContent=''; $panelAssets=null;
    if ($view==='plugin-panel') {
      $panelId=$requestedPlugin; $route=$requestedRoute;
      if (!is_string($panelId) || !is_string($route)) throw new HttpError(400,'Rota inválida.','error.admin_route');
      $panelRoute=$plugins->route($panelId,$route);
      if ($panelRoute['requiresEnabled'] && !$plugins->state($panelId)['enabled']) throw new HttpError(404,'Plugin desativado.','error.plugin');
      $panelLanguage=$plugins->language($panelId,$this->language->locale());
      $panelContext=$plugins->context($panelId,$this->base,$this->language->locale(),$panelRoute['id'],true);
      $panelAssets=$panelContext->manifest['assets'];
      $panelContent=$plugins->instance($panelId)->render($panelContext);
    }
    if ($view==='plugin') {
      $id=$_GET['plugin']??''; if (!is_string($id)) throw new HttpError(400,'Rota inválida.','error.plugin');
      try {
        $route=$_GET['route']??$_GET['screen']??'';
        if (!is_string($route)) throw new HttpError(400,'Rota inválida.','error.admin_route');
        $context=$plugins->context($id,$this->base,$this->language->locale(),$route);
        return $plugins->instance($id)->render($context);
      } catch (HttpError $failure) {
        http_response_code($failure->status); return self::error($this->translatePluginError($failure),$this->language);
      }
    }
    if ($view==='preview') {
      $context=$plugins->context('pages',$this->base,$this->language->locale(),'preview');
      return $plugins->instance('pages')->render($context);
    }
    if ($view==='site') $key='site';
    $site=$authenticated?$this->store->read('site'):null;
    $templates=$authenticated?$this->store->compatibleTemplates():[];
    $rejectedTemplates=$authenticated?array_keys($this->store->templates->invalid()+$this->store->incompatibleTemplates()):[];
    $effectiveTemplate=$site!==null && isset($templates[$site['data']['template']])?$site['data']['template']:array_key_first($templates);
    $pages=$authenticated && $effectiveTemplate!==null?$this->store->pages($effectiveTemplate):[];
    $document=null; $revision='';
    if ($authenticated && $view==='site') {
      $opened=$this->store->read($key,$effectiveTemplate); $editable=$opened['data'];$editable['settings']['cache']=Cache::options($editable['settings']['cache']??[]);$document=JsonStore::encode($editable); $revision=$opened['revision'];
      if ($error!==null && ($_POST['action']??'')==='save' && is_string($_POST['document']??null)) $document=$_POST['document'];
      if ($error!==null && is_string($_POST['revision']??null)) $revision=$_POST['revision'];
    }

    $history=[];
    if ($authenticated && $view==='history') {
      $history=$this->store->history($key);
      try { $revision=$this->store->read($key)['revision']; }
      catch (HttpError $failure) { if ($failure->status!==404) throw $failure; }
    }
    $notice=$_SESSION['notice_key']??null; unset($_SESSION['notice_key'],$_SESSION['notice']);
    $csrf=Security::token(); $base=$this->base; $production=$this->config->production; $language=$this->language;
    $localeChoice=$language->locale();
    if ($error!==null && $view==='site' && is_string($_POST['admin_locale']??null) && $language->available($_POST['admin_locale'])) $localeChoice=$_POST['admin_locale'];
    $siteLocaleChoice=$site!==null?SiteLocale::resolve($site['data']['settings'])['tag']:'pt-BR';
    if ($error!==null && $view==='site' && is_string($_POST['site_locale']??null)) $siteLocaleChoice=$_POST['site_locale'];
    $templateChoice=$site['data']['template']??'';
    $faviconChoice=$site['data']['settings']['favicon']??('templates/'.$templateChoice.'/favicon.ico');
    if ($error!==null && $view==='site' && is_string($_POST['site_favicon']??null)) $faviconChoice=$_POST['site_favicon'];
    if ($error!==null && $view==='site' && is_string($_POST['site_template']??null) && isset($templates[$_POST['site_template']])) $templateChoice=$_POST['site_template'];
    $dashboard=$authenticated&&$view==='dashboard'?Dashboard::snapshot($this->store,$this->base,$language->locale()):null;
    $configured=$this->config->configured();
    if (!$configured) http_response_code(503);
    ob_start();
    try { require $this->config->root.'/admin/layout.php'; return (string)ob_get_clean(); }
    catch (\Throwable $failure) { ob_end_clean(); throw $failure; }
  }

  /** Traduz falha própria do plugin ou do núcleo; recebe exceção e retorna texto sem escape. */
  private function translatePluginError(HttpError $failure): string {
    $id=$_GET['plugin']??$_POST['plugin']??null;
    if (is_string($id)) {
      $plugins=new Plugins($this->store);
      if (isset($plugins->all()[$id])) {
        $catalog=$plugins->language($id,$this->language->locale());
        if ($catalog->has($failure->messageKey)) return $catalog->error($failure);
      }
    }
    return $this->language->has($failure->messageKey)?$this->language->error($failure):$this->language->text('error.request');
  }

  /** Serve API de extensão sob sessão/CSRF; retorna JSON seguro ou string vazia após streaming, preservando status de falhas. */
  private function pluginApi(): string {
    header('Content-Type: application/json; charset=utf-8');
    try {
      if (!Security::authenticated($this->config)) throw new HttpError(403,'Autenticação necessária.','error.authentication');
      $method=$_SERVER['REQUEST_METHOD']??'GET';
      if (!in_array($method,['GET','POST'],true)) throw new HttpError(405,'Método inválido.','error.method');
      if ($method==='POST') { Security::csrf($_POST['csrf_token']??null); Security::limit($this->config,'plugin-mutation',120,60); }
      $id=$_GET['plugin']??''; $operation=$_GET['operation']??'';
      if (!is_string($id) || !is_string($operation)) throw new HttpError(400,'Rota inválida.','error.plugin');
      $route=$_GET['route']??''; if (!is_string($route)) throw new HttpError(400,'Rota inválida.','error.admin_route');
      $plugins=new Plugins($this->store); $context=$plugins->context($id,$this->base,$this->language->locale(),$route);
      $result=$plugins->instance($id)->api($context,$operation);
      return $result===null?'':json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    } catch (HttpError $failure) {
      http_response_code($failure->status);
      return json_encode(['error'=>$this->translatePluginError($failure),'code'=>$failure->messageKey],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    }
  }

  /** Renderiza falha segura; recebe mensagem e idioma opcional, retorna HTML escapado sem template público. */
  public static function error(string $message,?Language $language=null): string {
    $locale=$language?->locale()??'pt-br'; $direction=$language?->direction()??'ltr';
    return '<!doctype html><html lang="'.e($locale).'" dir="'.e($direction).'"><meta charset="utf-8"><title>dbless-cms</title><h1>dbless-cms</h1><p>'.e($message).'</p><a href="./">'.e($language?->text('error.back')??'Voltar ao painel').'</a></html>';
  }
}
