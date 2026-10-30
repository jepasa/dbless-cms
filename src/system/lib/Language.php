<?php
/** Descoberta/validação de dicionários JSON privados e tradução administrativa por chaves estáveis. */
declare(strict_types=1);
namespace DBLESSCMS;
final class Language {
  private array $catalogs = [];
  private array $invalid = [];
  private string $selected = 'pt-br';
  public bool $fallback = false;

  /** Recebe diretório privado opcional; descobre arquivos regulares, rejeita inválidos e falha se a base não existir. */
  public function __construct(?string $directory = null) {
    $directory ??= dirname(__DIR__) . '/languages';
    if (is_link($directory) || !is_dir($directory)) throw new \RuntimeException('Diretório de idiomas indisponível.');
    $base = $this->load($directory . '/pt-br.json');
    $this->catalogs['pt-br'] = $base;
    foreach (glob($directory . '/*.json') ?: [] as $file) {
      if (basename($file) === 'pt-br.json') continue;
      try {
        $catalog = $this->load($file);
        $keys = array_keys($catalog['messages']); $required = array_keys($base['messages']);
        sort($keys); sort($required);
        if ($keys !== $required) throw new \RuntimeException('Chaves de tradução incompatíveis.');
        foreach ($base['messages'] as $key => $text) {
          if ($this->placeholders($text) !== $this->placeholders($catalog['messages'][$key])) throw new \RuntimeException('Placeholders incompatíveis.');
        }
        $this->catalogs[$catalog['locale']] = $catalog;
      } catch (\RuntimeException $error) {
        $this->invalid[] = basename($file);
        error_log('DBLESS-CMS language ' . basename($file) . ': ' . $error->getMessage());
      }
    }
    ksort($this->catalogs);
  }

  /** Lê JSON limitado sem seguir symlink; recebe arquivo, retorna catálogo validado; erro não inclui conteúdo. */
  private function load(string $file): array {
    $id = basename($file, '.json');
    if (!preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/D', $id) || is_link($file) || !is_file($file)) throw new \RuntimeException('Arquivo de idioma inválido.');
    $raw = @file_get_contents($file, false, null, 0, 524289);
    if ($raw === false || strlen($raw) > 524288) throw new \RuntimeException('Dicionário indisponível ou excessivo.');
    try { $catalog = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); }
    catch (\JsonException) { throw new \RuntimeException('JSON de idioma inválido.'); }
    if (!is_array($catalog) || count($catalog) !== 4 || ($catalog['locale'] ?? null) !== $id || !is_string($catalog['name'] ?? null) || !in_array($catalog['direction'] ?? null, ['ltr','rtl'], true) || !is_array($catalog['messages'] ?? null) || array_is_list($catalog['messages'])) throw new \RuntimeException('Estrutura de idioma inválida.');
    $this->plain($catalog['name'], 80);
    foreach ($catalog['messages'] as $key => $text) {
      if (!is_string($key) || !preg_match('/^[a-z][a-zA-Z0-9_.]*$/D', $key) || !is_string($text)) throw new \RuntimeException('Mensagem de idioma inválida.');
      $this->plain($text, 12000);
    }
    return $catalog;
  }

  /** Exige texto simples não vazio e limitado; recebe texto/limite de bytes; lança erro sem dados do catálogo. */
  private function plain(string $text, int $limit): void {
    if (trim($text) === '' || strlen($text) > $limit || strip_tags($text) !== $text || str_contains($text, '<?') || preg_match('/[\x00-\x1F\x7F]/', $text)) throw new \RuntimeException('Tradução precisa ser texto simples.');
  }

  /** Extrai nomes únicos de placeholders; recebe texto, retorna lista ordenada sem efeitos. */
  private function placeholders(string $text): array {
    preg_match_all('/\{([a-zA-Z][a-zA-Z0-9_]*)\}/', $text, $matches);
    $keys = array_values(array_unique($matches[1])); sort($keys); return $keys;
  }

  /** Seleciona locale ou base; recebe preferência opaca, retorna nada e informa fallback sem alterar arquivos. */
  public function select(?string $locale): void {
    $this->fallback = $locale !== null && !isset($this->catalogs[$locale]);
    $this->selected = $locale !== null && isset($this->catalogs[$locale]) ? $locale : 'pt-br';
  }

  /** Informa disponibilidade de locale; recebe string não confiável e retorna bool sem resolver caminhos. */
  public function available(string $locale): bool { return isset($this->catalogs[$locale]); }

  /** Retorna metadados dos idiomas válidos, sem mensagens, sem parâmetros ou efeitos. */
  public function all(): array { return array_map(static fn(array $item): array => array_diff_key($item, ['messages'=>true]), $this->catalogs); }

  /** Retorna nomes dos arquivos rejeitados para diagnóstico administrativo; sem conteúdo ou escrita. */
  public function invalid(): array { return $this->invalid; }

  /** Retorna locale ativo; sem parâmetros, efeitos ou falhas esperadas. */
  public function locale(): string { return $this->selected; }

  /** Retorna direção declarada do idioma ativo; sem parâmetros, efeitos ou falhas esperadas. */
  public function direction(): string { return $this->catalogs[$this->selected]['direction']; }

  /** Traduz chave com parâmetros textuais; retorna texto sem escape; o consumidor escapa no contexto de saída. */
  public function text(string $key, array $parameters = []): string {
    $text = $this->catalogs[$this->selected]['messages'][$key] ?? throw new \LogicException('Chave de tradução ausente: ' . $key);
    $tokens = [];
    foreach ($parameters as $name => $value) $tokens['{' . $name . '}'] = (string)$value;
    return strtr($text, $tokens);
  }

  /** Traduz erro HTTP com chave/argumentos; recebe exceção e retorna mensagem pública no idioma ativo. */
  public function error(HttpError $error): string { return $this->text($error->messageKey, $error->parameters); }

  /** Retorna mensagens do idioma ativo para cliente do plugin; sem segredos ou efeitos. */
  public function messages(): array { return $this->catalogs[$this->selected]['messages']; }

  /** Consulta chave sem fallback implícito; recebe chave e retorna bool sem efeitos. */
  public function has(string $key): bool { return isset($this->catalogs[$this->selected]['messages'][$key]); }

  /** Retorna subconjunto de mensagens do editor JS; sem parâmetros, sem credenciais ou conteúdo editorial. */
  public function clientMessages(): array {
    return array_filter($this->catalogs[$this->selected]['messages'], static fn(string $key): bool => str_starts_with($key, 'field.') || str_starts_with($key, 'editor.') || str_starts_with($key, 'status.'), ARRAY_FILTER_USE_KEY);
  }
}
