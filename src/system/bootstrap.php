<?php
/** Inicialização privada CMS2JSON: autoload, escape e resolução de assets por ambiente. */
declare(strict_types=1);
namespace CMS2JSON;

/** Carrega exclusivamente classes locais; recebe nome e não retorna; arquivos ausentes seguem o autoloader. */
spl_autoload_register(static function (string $class): void {
  if (str_starts_with($class, __NAMESPACE__ . '\\')) {
    $name = substr($class, strlen(__NAMESPACE__) + 1);
    if (preg_match('/^[A-Za-z]+$/', $name) && is_file(__DIR__ . '/lib/' . $name . '.php')) {
      require __DIR__ . '/lib/' . $name . '.php';
    }
  }
});

/** Escapa texto escalar para HTML; retorna string sem efeitos; entradas são tipadas. */
function e(string|int|float $text): string { return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** Resolve asset lógico por ambiente; recebe caminho/base e retorna URL, sem leitura ou escrita. */
function asset(string $path, string $base = ''): string {
  if (is_file(__DIR__ . '/config/production.php') && !preg_match('/\.min\.(css|js)$/', $path)) {
    $path = preg_replace('/\.(css|js)$/', '.min.$1', $path);
  }
  return rtrim($base, '/') . '/' . ltrim($path, '/');
}
