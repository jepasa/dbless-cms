<?php
/** Validação de contratos estruturados e sanitização de texto/URLs; JSON não executa HTML ou PHP. */
declare(strict_types=1);
namespace CMS2JSON;
final class Schema {
  /** Valida subconjunto explícito de JSON Schema; recebe valor/contrato/caminho; lança 422 em divergência. */
  public static function validate(mixed $value, array $schema, string $path = 'documento'): void {
    $types = (array)($schema['type'] ?? []);
    $actual = match(true) { is_string($value)=>'string',is_bool($value)=>'boolean',is_int($value)=>'integer',is_float($value)=>'number',is_null($value)=>'null',is_array($value)&&array_is_list($value)=>'array',is_array($value)=>'object',default=>'invalid' };
    if ($types && !in_array($actual,$types,true) && !($actual==='array' && $value===[] && in_array('object',$types,true))) throw new HttpError(422, 'Tipo inválido em ' . $path . '.', 'validation.type', ['path'=>$path]);
    if (isset($schema['enum']) && !in_array($value,$schema['enum'],true)) throw new HttpError(422,'Opção inválida em '.$path.'.','validation.choice',['path'=>$path]);
    if ((is_int($value) || is_float($value)) && ((isset($schema['minimum']) && $value<$schema['minimum']) || (isset($schema['maximum']) && $value>$schema['maximum']))) throw new HttpError(422,'Número fora do limite em '.$path.'.','validation.range',['path'=>$path]);
    if (is_string($value) && strlen($value)>($schema['maxLength'] ?? 12000)) throw new HttpError(422, 'Texto excede o limite em ' . $path . '.', 'validation.text_length', ['path'=>$path]);
    if ($actual === 'object' || (is_array($value) && $value === [] && in_array('object',$types,true))) {
      foreach ($schema['required'] ?? [] as $key) if (!array_key_exists($key,$value)) throw new HttpError(422, 'Campo obrigatório em ' . $path . '.', 'validation.required', ['path'=>$path]);
      foreach ($value as $key=>$child) {
        if (($schema['additionalProperties'] ?? true)===false && !isset($schema['properties'][$key])) throw new HttpError(422, 'Campo desconhecido em ' . $path . '.', 'validation.unknown', ['path'=>$path]);
        if (isset($schema['properties'][$key])) self::validate($child,$schema['properties'][$key],$path.'.'.$key);
      }
    } elseif ($actual === 'array') {
      if (count($value)>($schema['maxItems'] ?? 100)) throw new HttpError(422,'Lista excede o limite.','error.list');
      foreach ($value as $index=>$child) self::validate($child,$schema['items'] ?? [],$path.'.'.$index);
    }
  }

  /** Limpa texto/URLs e normaliza assets 2.0 legados; recebe dados/chave/profundidade; retorna dados sem HTML ou lança 422. */
  public static function sanitize(mixed $value, string $key = '', int $depth = 0): mixed {
    if ($depth>16) throw new HttpError(422,'Conteúdo excessivamente aninhado.','error.nested');
    if (is_array($value)) {
      if (count($value)>200) throw new HttpError(422,'Documento excede o limite de campos.','error.fields');
      foreach ($value as $name=>$child) $value[$name]=self::sanitize($child,(string)$name,$depth+1);
      return $value;
    }
    if (!is_string($value)) return $value;
    if (str_contains($value,'<?') || str_contains($value,"\0")) throw new HttpError(422,'Conteúdo executável não é permitido.','error.executable');
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\x{202A}-\x{202E}\x{2066}-\x{2069}]/u','',strip_tags($value));
    if ($value === null) throw new HttpError(422,'Texto precisa ser UTF-8 válido.','error.utf8');
    if ($key==='email' && $value!=='' && !filter_var($value,FILTER_VALIDATE_EMAIL)) throw new HttpError(422,'E-mail de conteúdo inválido.','error.content_email');
    if (in_array($key,['href','url','image','hero','profile','feature','website','canonical','baseUrl'],true) && $value !== '') {
      if (str_starts_with($value,'assets/')) $value='templates/jepasaDotCom/'.substr($value,7);
      if (preg_match('/[\x00-\x20\x7F]/',$value) || str_starts_with($value,'//') || str_contains(rawurldecode($value),'..') || !preg_match('~^(?:https?://[^/]+(?:/.*)?|mailto:[^\s]+|tel:[+0-9()-]+|/[^\\\\]*|#[A-Za-z0-9_-]+|templates/[A-Za-z][A-Za-z0-9-]*/[A-Za-z0-9_./-]+)$~D',$value)) throw new HttpError(422,'URL de conteúdo inválida.','error.url');
    }
    return $value;
  }
}
