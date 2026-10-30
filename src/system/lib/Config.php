<?php
/** Configuração privada: .env somente em desenvolvimento; produção usa config/app.json. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Config {
  public readonly array $data;
  public readonly array $credentials;
  public readonly string $root;
  public readonly bool $production;

  /** Carrega configuração e valida credenciais/caminhos; retorna instância; falhas são explícitas e sem segredos. */
  public function __construct() {
    $this->root = dirname(__DIR__);
    $this->production = is_file($this->root . '/config/production.php') || getenv('DBLESS_CMS_ENV') === 'production';
    $env = [];
    $dotenv = dirname($this->root, 2) . '/.env';
    if (!$this->production && getenv('DBLESS_CMS_IGNORE_DOTENV') !== '1' && is_file($dotenv)) {
      $lines = file($dotenv, FILE_IGNORE_NEW_LINES);
      if ($lines === false) throw new \RuntimeException('Não foi possível ler a configuração local.');
      foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!preg_match('/^(DBLESS_CMS_[A-Z_]+)=(.*)$/', $line, $match)) throw new \RuntimeException('Configuração local inválida.');
        $value=$match[2];
        if (strlen($value)>=2 && in_array($value[0],['"',"'"],true) && substr($value,-1)===$value[0]) $value=substr($value,1,-1);
        $env[$match[1]]=$value;
      }
    }
    $data = require $this->root . '/config/settings.php';
    foreach (['contents' => 'DBLESS_CMS_CONTENTS_ROOT', 'storage' => 'DBLESS_CMS_STORAGE_ROOT'] as $key => $variable) {
      $data[$key] = getenv($variable) ?: ($env[$variable] ?? $data[$key]);
      $path = $data[$key];
      if (!is_string($path) || !str_starts_with($path, '/') || str_contains($path, "\0")) throw new \RuntimeException('Diretório privado inválido.');
      $public = dirname($this->root) . '/public_html';
      if (str_starts_with(rtrim($path, '/') . '/', $public . '/')) throw new \RuntimeException('Dados privados não podem ficar na raiz pública.');
    }
    $data['contact_enabled'] = !$this->production
      ? (getenv('DBLESS_CMS_CONTACT_ENABLED') ?: ($env['DBLESS_CMS_CONTACT_ENABLED'] ?? '0')) === '1'
      : false;
    $credentials = ['enabled' => true, 'username' => '', 'password_hash' => ''];
    $private = getenv('DBLESS_CMS_APP_CONFIG') ?: $this->root . '/config/app.json';
    if (is_file($private)) {
      if (is_link($private)) throw new \RuntimeException('Configuração privada inválida.');
      $app = json_decode((string)file_get_contents($private), true, 32, JSON_THROW_ON_ERROR);
      if (($app['schema_version'] ?? null) !== 1 || !is_array($app['editor'] ?? null)) throw new \RuntimeException('Configuração privada inválida.');
      $credentials = $app['editor'];
      $data['contact_enabled'] = ($app['contact_enabled'] ?? false) === true;
    }
    if (!$this->production) {
      foreach (['username'=>'DBLESS_CMS_ADMIN_USER','password_hash'=>'DBLESS_CMS_ADMIN_PASSWORD_HASH','password_plaintext'=>'DBLESS_CMS_ADMIN_PASSWORD'] as $key=>$variable) {
        $value = getenv($variable);
        if ($value === false) $value = $env[$variable] ?? '';
        if ($value !== '') $credentials[$key] = $value;
      }
    }
    if ($this->production && isset($credentials['password_plaintext'])) throw new \RuntimeException('Produção exige hash de senha.');
    if (isset($credentials['password_plaintext']) && (!is_string($credentials['password_plaintext']) || strlen($credentials['password_plaintext']) < 16)) throw new \RuntimeException('Senha local deve conter pelo menos 16 caracteres.');
    if (!is_string($credentials['username'] ?? null) || ($credentials['username'] !== '' && !preg_match('/^[A-Za-z0-9_.@-]{3,100}$/', $credentials['username']))) throw new \RuntimeException('Usuário administrativo inválido.');
    $hash = $credentials['password_hash'] ?? '';
    if (!is_string($hash) || ($hash !== '' && password_get_info($hash)['algoName'] === 'unknown')) throw new \RuntimeException('Hash de senha inválido.');
    $this->credentials = $credentials;
    $this->data = $data;
  }

  /** Informa se existe credencial utilizável; sem parâmetros, efeitos ou erros esperados. */
  public function configured(): bool {
    return ($this->credentials['username'] ?? '') !== '' && (($this->credentials['password_hash'] ?? '') !== '' || isset($this->credentials['password_plaintext']));
  }
}
