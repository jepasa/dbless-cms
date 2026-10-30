<?php
/** Sessão, CSRF e limites atômicos com estado privado; sem registro de dados pessoais. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Security {
  /** Cria diretório privado; recebe caminho, retorna nada; falha fechada em erro/symlink. */
  public static function directory(string $path): void {
    if (is_link($path) || (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path))) throw new HttpError(503,'Armazenamento privado indisponível.','error.storage');
  }

  /** Inicia sessão segura com configuração privada; não retorna; falha com 503 se indisponível. */
  public static function start(Config $config): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $path = $config->data['storage'] . '/sessions';
    self::directory($path);
    session_save_path($path);
    session_name('DBLESSCMSSESSID');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off','httponly'=>true,'samesite'=>'Strict']);
    if (!session_start()) throw new HttpError(503,'Sessão indisponível.','error.session');
  }

  /** Obtém token da sessão; sem parâmetros; atualiza token vencido e retorna string aleatória. */
  public static function token(): string {
    if (!is_string($_SESSION['csrf'] ?? null) || ($_SESSION['csrf_at'] ?? 0) < time()-3600) {
      $_SESSION['csrf'] = bin2hex(random_bytes(32)); $_SESSION['csrf_at'] = time();
    }
    return $_SESSION['csrf'];
  }

  /** Valida token fornecido sem conversões; retorna nada e lança 403 em ausência/expiração/divergência. */
  public static function csrf(mixed $token): void {
    if (!is_string($token) || !is_string($_SESSION['csrf'] ?? null) || ($_SESSION['csrf_at'] ?? 0) < time()-3600 || !hash_equals($_SESSION['csrf'], $token)) throw new HttpError(403,'Sessão expirada. Recarregue a página.','error.csrf');
  }

  /** Conta tentativa sob trava exclusiva; recebe config/escopo/cota/janela, retorna nada; lança 429/503 sem PII persistida. */
  public static function limit(Config $config, string $scope, int $maximum, int $window): void {
    $dir = $config->data['storage'] . '/rate'; self::directory($dir);
    $file = $dir . '/' . hash('sha256', $scope . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) . '.json';
    if (is_link($file)) throw new HttpError(503,'Limite temporariamente indisponível.','error.rate_unavailable');
    $handle = fopen($file, 'c+');
    if ($handle === false) throw new HttpError(503,'Limite temporariamente indisponível.','error.rate_unavailable');
    try {
      if (!flock($handle, LOCK_EX)) throw new HttpError(503,'Limite temporariamente indisponível.','error.rate_unavailable');
      $raw = stream_get_contents($handle);
      try { $state = $raw === '' ? [] : json_decode($raw, true, 16, JSON_THROW_ON_ERROR); }
      catch (\JsonException) { throw new HttpError(503,'Estado de limite inválido.','error.rate_invalid'); }
      if (!is_array($state)) throw new HttpError(503,'Limite temporariamente indisponível.','error.rate_unavailable');
      $times = array_values(array_filter($state, static fn($t): bool => is_int($t) && $t > time()-$window));
      if (count($times) >= $maximum) { header('Retry-After: ' . $window); throw new HttpError(429,'Muitas tentativas. Aguarde antes de tentar novamente.','error.rate'); }
      $times[] = time(); $bytes = json_encode($times, JSON_THROW_ON_ERROR);
      if (!rewind($handle) || !ftruncate($handle,0) || fwrite($handle,$bytes) !== strlen($bytes) || !fflush($handle)) throw new HttpError(503,'Limite temporariamente indisponível.','error.rate_unavailable');
    } finally { flock($handle,LOCK_UN); fclose($handle); }
  }

  /** Verifica sessão e timeout com credencial atual; recebe config, retorna bool; expira autenticação vencida. */
  public static function authenticated(Config $config): bool {
    $fingerprint = hash('sha256', json_encode($config->credentials, JSON_THROW_ON_ERROR));
    if (!isset($_SESSION['auth']) || !hash_equals($fingerprint, (string)$_SESSION['auth']) || ($_SESSION['last_seen'] ?? 0) < time()-1800) { unset($_SESSION['auth']); return false; }
    $_SESSION['last_seen'] = time(); return true;
  }

  /** Valida usuário/senha, regenera sessão; retorna bool e não registra credenciais; falha de sessão é exceção. */
  public static function login(Config $config, string $user, string $password): bool {
    $credentials = $config->credentials;
    if (!$config->configured()) return false;
    $valid = isset($credentials['password_plaintext']) ? hash_equals($credentials['password_plaintext'],$password) : password_verify($password,$credentials['password_hash']);
    if (!$valid || !hash_equals($credentials['username'],$user)) return false;
    if (!session_regenerate_id(true)) throw new HttpError(503,'Sessão indisponível.','error.session');
    $_SESSION['auth'] = hash('sha256',json_encode($credentials,JSON_THROW_ON_ERROR)); $_SESSION['last_seen'] = time();
    return true;
  }
}
