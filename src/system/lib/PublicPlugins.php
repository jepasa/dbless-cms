<?php
/** Front controller público de plugins: escopo explícito, ativação, métodos, CSRF/cota e contexto sem credenciais. */
declare(strict_types=1);
namespace CMS2JSON;
final class PublicPlugins {
  /** Executa somente rota pública declarada; escreve corpo/status; falhas controladas são escapadas e falhas internas recebem ID opaco; compartilha trava operacional até o término. */
  public static function run(): void {
    App::headers(false); $language=null;$operation=null;
    try {
      $config=new Config();$operation=Lifecycle::enter($config); $store=new JsonStore($config); $registry=new Plugins($store);
      [$id,$route]=self::destination();
      $page=$registry->route($id,$route,'public');
      if (!$registry->state($id)['enabled']) throw new HttpError(404,'Página não encontrada.','error.not_found');
      $method=$_SERVER['REQUEST_METHOD']??'GET'; $methods=$page['methods']??['GET'];
      if (!in_array($method,$methods,true)) { header('Allow: '.implode(', ',$methods)); throw new HttpError(405,'Método não permitido.','error.method'); }
      $locale=$_GET['locale']??'pt-br';
      if (!is_string($locale)) throw new HttpError(400,'Idioma inválido.','error.parameter');
      $language=$registry->language($id,$locale); $base=App::base($store);
      Security::start($config); Security::limit($config,'plugin-public-'.$id,120,60);
      if ($method==='POST') {
        if (!isset($_SERVER['CONTENT_LENGTH'])) throw new HttpError(411,'Content-Length obrigatório.','error.request');
        $length=$_SERVER['CONTENT_LENGTH'];
        if (!is_string($length) || !ctype_digit($length)) throw new HttpError(400,'Tamanho de requisição inválido.','error.parameter');
        if ((int)$length>($page['maxBodyBytes']??65536)) throw new HttpError(413,'Requisição excede o limite.','error.document_size');
        Security::csrf($_POST['csrf_token']??$_SERVER['HTTP_X_CSRF_TOKEN']??null);
        $parts=parse_url($base); $port=$parts['port']??null;
        $defaultPort=($parts['scheme']==='https'?443:80);
        $origin=strtolower($parts['scheme'].'://'.$parts['host']).($port!==null&&$port!==$defaultPort?':'.$port:'');
        if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN']!==$origin) throw new HttpError(403,'Origem não permitida.','error.request');
      }
      $context=$registry->publicContext($id,$base,$locale,$page['id'],$method,Security::token());
      $plugin=$registry->instance($id);
      if (!$plugin instanceof PublicPluginInterface) throw new HttpError(503,'Contrato público de plugin indisponível.','error.plugin');
      // Reviewed HTML boundary: only a declared trusted plugin returns a complete public document.
      // nosemgrep: github.php-request-to-raw-output
      echo $plugin->publicPage($context);
    } catch (HttpError $failure) {
      http_response_code($failure->status);
      echo e($language!==null&&$language->has($failure->messageKey)?$language->error($failure):$failure->getMessage());
    } catch (\Throwable $failure) {
      $id=bin2hex(random_bytes(6)); error_log('CMS2JSON public-plugin '.$id.' '.$failure::class.' '.basename($failure->getFile()).':'.$failure->getLine());
      http_response_code(500); echo 'Falha interna. Referência '.e($id);
    } finally {Lifecycle::leave($operation);}
  }
  /** Normaliza raiz com 308 e extrai IDs de REQUEST_URI; retorna par de IDs ou encerra redirect, rejeitando entradas inválidas com 400/404. */
  private static function destination(): array {
    $path=parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH);
    $script=(string)($_SERVER['SCRIPT_NAME']??'/plugins/index.php');
    if (!is_string($path)) throw new HttpError(400,'Rota inválida.','error.parameter');
    if ($path===$script) { $id=$_GET['plugin']??''; $route=$_GET['route']??''; }
    elseif (preg_match('~^'.preg_quote(dirname($script),'~').'/([a-z][a-z0-9-]{0,63})(?:/([a-z][a-z0-9-]{0,63}))?/?$~D',$path,$matches)) {
      if (!isset($matches[2]) && !str_ends_with($path,'/')) {
        $query=http_build_query($_GET);header('Location: '.$path.'/'.($query===''?'':'?'.$query),true,308);exit;
      }
      $id=$matches[1]; $route=$matches[2]??'';
    }
    else throw new HttpError(404,'Página não encontrada.','error.not_found');
    if (!is_string($id) || !is_string($route) || !Plugins::identifier($id) || ($route!==''&&!Plugins::identifier($route))) throw new HttpError(400,'Rota inválida.','error.parameter');
    return [$id,$route];
  }
}
