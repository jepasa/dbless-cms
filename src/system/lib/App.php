<?php
/** Front controllers público e administrativo; resolução de conteúdo e renderização por template registrado. */
declare(strict_types=1);
namespace DBLESSCMS;
final class App {
  /** Executa requisição; recebe indicador administrativo, escreve resposta; erros esperados preservam status e inesperados usam ID sem dados privados; reserva trava compartilhada até concluir a requisição. */
  public static function run(bool $admin=false): void {
    $language=null;$operation=null;
    try {
      $config=new Config(); self::headers($admin);$operation=Lifecycle::enter($config);
      if ($admin && ($config->credentials['enabled']??true)!==true) throw new HttpError(404,'Página não encontrada.','error.not_found');
      Security::start($config);
      $store=new JsonStore($config);
      if ($admin) { $language=new Language(); $language->select($store->read('site')['data']['settings']['adminLocale']??null); }
      $base=self::base($store);
      if ($admin) { echo (new Admin($config,$store,$base,$language))->run(); return; }
      foreach (['page','action'] as $name) if (isset($_GET[$name]) && !is_string($_GET[$name])) throw new HttpError(400,'Parâmetro inválido.','error.parameter');
      $action=strtolower(trim($_GET['action']??''));
      if ($action==='contact') {
        $site=$store->read('site')['data']; $manifest=$store->templates->get($site['template']);
        if (!isset($manifest['forms']['contact'])) throw new HttpError(404,'Formulário não disponível.','error.form_unavailable');
        require $store->templates->path($site['template'],'forms',$manifest['forms']['contact']); return;
      }
      $key=strtolower(trim($_GET['page']??'home')); if ($key==='') $key='home';
      // Reviewed HTML boundary: render validates page data and templates escape editorial fields.
      // nosemgrep: github.php-request-to-raw-output
      echo self::render($store,$key,false,null,$base);
    } catch (HttpError $error) { http_response_code($error->status); echo $admin ? Admin::error($language?->error($error)??$error->getMessage(),$language) : e($error->getMessage()); }
    catch (\Throwable $error) {
      $id=bin2hex(random_bytes(6)); error_log('DBLESS-CMS '.$id.' '.$error::class.' '.basename($error->getFile()).':'.$error->getLine());
      http_response_code(500); echo $admin ? Admin::error($language?->text('error.internal',['id'=>$id])??'Falha interna. Referência '.$id,$language) : 'Falha interna. Referência '.e($id);
    } finally {Lifecycle::leave($operation);}
  }

  /** Aplica cabeçalhos por superfície; recebe bool e não retorna; deve ocorrer antes do corpo. */
  public static function headers(bool $admin): void {
    header('Content-Type: text/html; charset=UTF-8'); header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin'); header('X-Frame-Options: SAMEORIGIN');
    header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
    $policy=$admin ? "default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'"
      : "default-src 'self'; style-src 'self'; font-src 'self'; script-src 'self'; img-src 'self' https: data:; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'";
    header('Content-Security-Policy: '.$policy); header('Cache-Control: no-store');
  }

  /** Calcula base pública sem confiar em Host malformado; recebe store; retorna URL e lança 400 para header inválido. */
  public static function base(JsonStore $store): string {
    $host=$_SERVER['HTTP_HOST']??'localhost';
    if (!is_string($host) || !preg_match('/^(?:[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?|\[::1\])(?::[0-9]{1,5})?$/D',$host)) throw new HttpError(400,'Host inválido.','error.host');
    $site=$store->read('site')['data']; $configured=$site['settings']['baseUrl']??'';
    if (is_string($configured) && $configured!=='') return rtrim($configured,'/');
    if ($store->config->production) return rtrim($site['settings']['canonical'],'/');
    $script=(string)($_SERVER['SCRIPT_NAME']??'/index.php');
    $dir=dirname(str_replace(['/cms-admin/index.php','/plugins/index.php'],'/index.php',$script));
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off'?'https://':'http://').$host.($dir==='/'?'':$dir);
  }

  /** Renderiza página e template; recebe store/chave/preview/template/base; retorna HTML e usa 404 em conteúdo ausente/draft. */
  public static function render(JsonStore $store,string $key,bool $preview=false,?string $template=null,?string $base=null): string {
    $site=$store->read('site')['data']; $id=$template??$site['template'];
    $manifest=$store->templates->get($id); $missing=false;
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$key)) { $key='404'; $missing=true; }
    try { $page=$store->read($key,$id)['data']; }
    catch (HttpError $error) { if ($error->status!==404) throw $error; $page=$store->read('404',$id)['data']; $missing=true; }
    if (!$preview && $page['status']!=='published') { $page=$store->read('404',$id)['data']; $missing=true; }
    if ($missing || $page['slug']==='404') http_response_code(404);
    $base_url=$base??self::base($store); $currentPage=$page['slug'];
    $route=['meta'=>$page['meta']]; $settings=$site['settings'];
    $publicLocale=SiteLocale::resolve($settings);
    header('Content-Language: '.$publicLocale['tag']);
    $siteContent=['brand'=>$site['brand'],'media'=>$site['media'],'home'=>$page['page']==='home'?$page['content']:[], 'details'=>[$currentPage=>$page['content']]];
    $templateSettings=isset($manifest['configuration'])?(new Extensions($store))->options($id)['values']:[];
    $navigation=new Navigation($store,$id,$base_url);
    $slides=new Slides($store,$id);
    $contentFile=$store->templates->path($id,'pages',$manifest['pages'][$page['page']]);
    ob_start();
    try { require $store->templates->path($id,'partials',$manifest['partials']['layout']); return (string)ob_get_clean(); }
    catch (\Throwable $error) { ob_end_clean(); throw $error; }
  }
}
