<?php
/**
 * Executa uma requisição sintética ao entrypoint em processo PHP CLI isolado.
 * Entradas: argv[1] contém a raiz confiável da aplicação; STDIN contém JSON de teste.
 * Saída: JSON com status/corpo. Não testa headers/rewrite; mail é desabilitado pelo chamador.
 * Efeitos: sessão temporária. Erros de JSON/tipo são exceções e warnings permanecem visíveis.
 */
declare(strict_types=1);

$request = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($request) || !isset($argv[1]) || !is_file($argv[1] . '/public_html/index.php')) {
  throw new InvalidArgumentException('Fixture requer raiz da aplicação e requisição JSON válidas.');
}
$_GET = $request['query'] ?? [];
$_POST = $request['post'] ?? [];
$_SERVER = [
  'REQUEST_METHOD' => $request['method'] ?? 'GET',
  'HTTP_HOST' => $request['host'] ?? 'localhost',
  'SERVER_PORT' => '80',
  'SCRIPT_NAME' => ($request['admin'] ?? false) ? '/cms-admin/index.php' : '/index.php',
  'REMOTE_ADDR' => '127.0.0.1',
];
if (isset($request['length'])) $_SERVER['CONTENT_LENGTH'] = (string)$request['length'];
if (isset($request['origin'])) $_SERVER['HTTP_ORIGIN'] = $request['origin'];
if (isset($request['session']) && is_string($request['session'])) session_id($request['session']);
ob_start();

/**
 * Serializa resposta mesmo se o handler chamar exit; sem parâmetros/retorno.
 * Consome buffer de saída e escreve JSON. Falhas de serialização são exceções.
 */
register_shutdown_function(static function (): void {
  $body = (string)ob_get_clean();
  echo json_encode([
    'status' => http_response_code() ?: 200,
    'body' => $body,
    'session' => session_id(),
  ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
});
require $argv[1] . '/public_html/' . (($request['admin'] ?? false) ? 'cms-admin/index.php' : 'index.php');
