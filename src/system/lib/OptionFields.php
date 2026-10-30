<?php
/** Opções declarativas de extensões: contrato limitado, formulário tipado e leitura de POST sem código/HTML arbitrário. */
declare(strict_types=1);
namespace DBLESSCMS;
final class OptionFields {
  /** Valida schema traduzível de objetos/string/boolean/integer; recebe contrato/dicionário/profundidade e lança 422 sem executar scripts. */
  public static function contract(array $schema,Language $language,int $depth=0): void {
    if ($depth>5 || ($schema['type']??null)!=='object' || ($schema['additionalProperties']??null)!==false || !is_array($schema['properties']??null) || count($schema['properties'])>30 || !is_array($schema['required']??null) || !array_is_list($schema['required'])) throw new HttpError(422,'Contrato de opções inválido.','error.extension_options');
    foreach ($schema['required'] as $required) if (!is_string($required)) throw new HttpError(422,'Campo obrigatório inválido.','error.extension_options');
    if (array_diff($schema['required'],array_keys($schema['properties']))) throw new HttpError(422,'Campos obrigatórios inválidos.','error.extension_options');
    foreach ($schema['properties'] as $key=>$field) {
      if (!is_string($key) || !preg_match('/^[a-z][a-zA-Z0-9]*$/D',$key) || !is_array($field) || !is_string($field['title']??null) || !$language->has($field['title'])) throw new HttpError(422,'Campo sem tradução.','error.extension_options');
      if (($field['type']??null)==='object') { self::contract($field,$language,$depth+1); continue; }
      if (!in_array($field['type']??null,['string','boolean','integer'],true)) throw new HttpError(422,'Tipo não suportado.','error.extension_options');
      if ($field['type']==='string' && (!is_int($field['maxLength']??null) || $field['maxLength']<1 || $field['maxLength']>4000)) throw new HttpError(422,'Limite de texto inválido.','error.extension_options');
      if ($field['type']==='integer' && (!is_int($field['minimum']??null) || !is_int($field['maximum']??null) || $field['minimum']>$field['maximum'])) throw new HttpError(422,'Limite numérico inválido.','error.extension_options');
      if (isset($field['enum'])) {
        if ($field['type']!=='string' || !is_array($field['enum']) || !array_is_list($field['enum']) || !$field['enum'] || count($field['enum'])>30 || !is_array($field['enumLabels']??null)) throw new HttpError(422,'Opções inválidas.','error.extension_options');
        foreach ($field['enum'] as $choice) if (!is_string($choice) || !isset($field['enumLabels'][$choice]) || !is_string($field['enumLabels'][$choice]) || !$language->has($field['enumLabels'][$choice])) throw new HttpError(422,'Opção sem tradução.','error.extension_options');
      }
    }
  }
  /** Lê valores do formulário conforme contrato; recebe schema/POST e retorna dados tipados; rejeita tipos/chaves/limites sem gravar. */
  public static function input(array $schema,array $input): array {
    if (array_diff(array_keys($input),array_keys($schema['properties']))) throw new HttpError(422,'Campo desconhecido.','error.extension_options');
    $values=[];
    foreach ($schema['properties'] as $key=>$field) {
      if (!array_key_exists($key,$input) && !in_array($key,$schema['required'],true) && $field['type']!=='boolean') continue;
      $value=$input[$key]??null;
      if ($field['type']==='boolean') {
        if ($value!==null && $value!=='1') throw new HttpError(422,'Booleano inválido.','error.extension_options');
        $values[$key]=$value==='1';
      } elseif ($field['type']==='object') {
        if (!is_array($value)) throw new HttpError(422,'Grupo inválido.','error.extension_options');
        $values[$key]=self::input($field,$value);
      } elseif ($field['type']==='integer') {
        if (!is_string($value) || !preg_match('/^-?(?:0|[1-9][0-9]*)$/D',$value) || filter_var($value,FILTER_VALIDATE_INT)===false) throw new HttpError(422,'Inteiro inválido.','error.extension_options');
        $values[$key]=(int)$value;
      } else {
        if (!is_string($value)) throw new HttpError(422,'Texto inválido.','error.extension_options');
        $values[$key]=$value;
      }
    }
    Schema::validate($values,$schema); return $values;
  }
  /** Renderiza controles canônicos escapados; recebe schema/valores/idioma/prefixo e retorna HTML, sem escrita ou scripts inline. */
  public static function html(array $schema,array $values,Language $language,string $prefix='options'): string {
    $html='';
    foreach ($schema['properties'] as $key=>$field) {
      $name=$prefix.'['.$key.']'; $value=$values[$key]??''; $label=e($language->text($field['title']));
      if ($field['type']==='object') { $html.='<fieldset class="field-group"><legend>'.$label.'</legend><div class="stack">'.self::html($field,is_array($value)?$value:[],$language,$name).'</div></fieldset>'; continue; }
      if ($field['type']==='boolean') { $html.='<label class="plugin-enabled"><input type="checkbox" name="'.e($name).'" value="1"'.($value===true?' checked':'').'>'.$label.'</label>'; continue; }
      $labelId='option-label-'.substr(hash('sha256',$name),0,16);
      $html.='<label><span id="'.$labelId.'">'.$label.'</span>';
      if (isset($field['enum'])) {
        $html.='<select name="'.e($name).'" aria-labelledby="'.$labelId.'" required>';
        foreach ($field['enum'] as $choice) $html.='<option value="'.e($choice).'"'.($choice===$value?' selected':'').'>'.e($language->text($field['enumLabels'][$choice])).'</option>';
        $html.='</select>';
      } elseif ($field['type']==='integer') $html.='<input type="number" name="'.e($name).'" value="'.e($value).'" min="'.e($field['minimum']).'" max="'.e($field['maximum']).'" step="1" required>';
      else $html.='<input name="'.e($name).'" value="'.e($value).'" maxlength="'.e($field['maxLength']).'" required>';
      $html.='</label>';
    }
    return $html;
  }
}
