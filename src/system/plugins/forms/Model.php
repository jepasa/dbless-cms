<?php
/** Declarative Forms contracts: closed fields, explicit purpose/notice and minimal public definitions; no executable validators or arbitrary recipients. */
declare(strict_types=1);
namespace DBLESSCMS;
final class FormsModel {
  /** Validate literal bounded UTF-8 text; receive value/limit/allow-empty, return text or 422 without logging data. */
  public static function text(mixed $value,int $max,bool $empty=false): string {
    if(!is_string($value)||strlen($value)>$max||!preg_match('//u',$value)||(!$empty&&trim($value)==='')||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F<>]/u',$value))throw new HttpError(422,'Invalid form text.','forms.error.contract');return $value;
  }
  /** Validate an opaque form/field ID; receive value, return bool without path or method dispatch. */
  public static function id(mixed $value): bool {return is_string($value)&&preg_match('/^[a-z][a-z0-9-]{0,47}$/D',$value)===1;}
  /** Return an editable noncollecting demonstration; receive optional ID, return complete draft defaults without writes. */
  public static function empty(string $id=''): array {return ['id'=>$id,'name'=>'','description'=>'','status'=>'draft','deleted'=>false,'demo'=>true,'purpose'=>'','legalBasis'=>'','noticeVersion'=>'1','noticeText'=>'','privacyPage'=>'','destination'=>'contact','retainDays'=>0,'consentText'=>'','submitLabel'=>'Send message','successText'=>'Your message was accepted by the delivery service.','fields'=>[['name'=>'email','label'=>'Email','type'=>'email','required'=>true,'maxLength'=>254,'options'=>[],'autocomplete'=>'email'],['name'=>'message','label'=>'Message','type'=>'textarea','required'=>true,'maxLength'=>4000,'options'=>[],'autocomplete'=>'off']]];}
  /** Validate one full form, retaining stable IDs and explicit legal editorial decisions; receive record, return safe record or 422. */
  public static function form(array $form): array {
    $defaults=self::empty();if(count($form)!==count($defaults)||array_diff(array_keys($form),array_keys($defaults))||!self::id($form['id']))throw new HttpError(422,'Invalid form identity.','forms.error.contract');
    foreach(['name'=>200,'description'=>1000,'purpose'=>1000,'noticeVersion'=>80,'noticeText'=>3000,'privacyPage'=>64,'consentText'=>1000,'submitLabel'=>100,'successText'=>500] as $key=>$limit)self::text($form[$key],$limit,in_array($key,['description','privacyPage','consentText'],true));
    if(preg_match('/[\r\n]/',$form['name']))throw new HttpError(422,'Invalid form subject.','forms.error.contract');
    if(!in_array($form['status'],['draft','published'],true)||!is_bool($form['deleted'])||!is_bool($form['demo'])||!in_array($form['legalBasis'],['','precontract','consent','legitimate-interest','legal-obligation','other'],true)||!in_array($form['destination'],['contact','privacy'],true)||!is_int($form['retainDays'])||$form['retainDays']<0||$form['retainDays']>365||!is_array($form['fields'])||!array_is_list($form['fields'])||count($form['fields'])<1||count($form['fields'])>20)throw new HttpError(422,'Invalid form configuration.','forms.error.contract');
    if(!$form['demo']&&$form['status']==='published'&&$form['legalBasis']==='')throw new HttpError(422,'Assess and select a legal basis before collection.','forms.error.notice');
    if($form['privacyPage']!==''&&!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$form['privacyPage'])||$form['legalBasis']==='consent'&&trim($form['consentText'])==='')throw new HttpError(422,'A specific consent text is required for this legal-basis choice.','forms.error.notice');
    $names=[];$total=0;$renderBudget=0;foreach($form['fields'] as $field){Schema::validate($field,['type'=>'object','additionalProperties'=>false,'required'=>['name','label','type','required','maxLength','options','autocomplete'],'properties'=>['name'=>['type'=>'string','maxLength'=>48],'label'=>['type'=>'string','maxLength'=>200],'type'=>['enum'=>['text','email','tel','textarea','select','radio','checkbox']],'required'=>['type'=>'boolean'],'maxLength'=>['type'=>'integer','minimum'=>1,'maximum'=>10000],'options'=>['type'=>'array','maxItems'=>30],'autocomplete'=>['enum'=>['off','name','given-name','family-name','email','tel','organization','country-name']]]]);
      if(!self::id($field['name'])||isset($names[$field['name']])||in_array($field['name'],['id','csrf-token','csrf_token','ticket','website','consent','form','action','revision'],true))throw new HttpError(422,'Duplicate or reserved form field.','forms.error.contract');$names[$field['name']]=true;self::text($field['label'],200);$total+=$field['maxLength'];$renderBudget+=strlen(e($field['label']))+500;
      if(in_array($field['type'],['select','radio'],true)&&$field['options']===[])throw new HttpError(422,'Choices are required.','forms.error.contract');
      foreach($field['options'] as $option){self::text($option,200);$renderBudget+=2*strlen(e($option))+100;}if(count(array_unique($field['options'],SORT_REGULAR))!==count($field['options'])||!in_array($field['type'],['select','radio'],true)&&$field['options']!==[])throw new HttpError(422,'Invalid form choices.','forms.error.contract');
    }if($total>30000||$renderBudget>60000)throw new HttpError(422,'Form input budget exceeded.','forms.error.contract');return $form;
  }
  /** Validate a bounded forms catalog on every read; receive document, return unchanged data or 422, no mutation or hidden migration. */
  public static function validate(array $data): array {
    if(count($data)!==2||($data['schema_version']??null)!==1||!is_array($data['forms']??null)||count($data['forms'])>100)throw new HttpError(422,'Invalid Forms catalog.','forms.error.contract');
    foreach($data['forms'] as $id=>$record){if(!is_array($record)||($record['id']??null)!==$id)throw new HttpError(422,'Invalid form record.','forms.error.contract');self::form($record);}return $data;
  }
  /** Return fixed catalog repository with history containing definitions only; receive store, no writes or public submission access. */
  public static function repository(JsonStore $store): ExtensionDocument {return new ExtensionDocument($store,'forms/catalog.json',['schema_version'=>1,'forms'=>[]],self::validate(...));}
  /** Select one published live form; receive store/ID, return definition or 404, no form state transition during render. */
  public static function published(JsonStore $store,string $id): array {
    if(!self::id($id))throw new HttpError(404,'Form unavailable.','forms.error.missing');$record=self::repository($store)->read()['data']['forms'][$id]??null;
    if($record===null||$record['deleted']||$record['status']!=='published')throw new HttpError(404,'Form unavailable.','forms.error.missing');return $record;
  }
  /** Project only read-only public definition data; receive form, return bounded field data without recipient, submissions, tokens or legal decision evidence. */
  public static function definition(array $form): array {
    return ['id'=>$form['id'],'name'=>$form['name'],'description'=>$form['description'],'notice'=>$form['noticeText'],'noticeVersion'=>$form['noticeVersion'],'fields'=>array_map(static fn(array $field):array=>['name'=>$field['name'],'label'=>$field['label'],'type'=>$field['type'],'required'=>$field['required'],'options'=>$field['options']],$form['fields'])];
  }
  /** Validate user fields against server definition, rejecting extra/array input and mismatched choices; receive form/input, return field values or 422, never log or persist values. */
  public static function values(array $form,array $input): array {
    $allowed=['id','csrf_token','ticket','website','consent'];foreach($form['fields'] as $field)$allowed[]=$field['name'];if(array_diff(array_keys($input),$allowed))throw new HttpError(422,'Unknown submission field.','forms.error.fields');$values=[];
    foreach($form['fields'] as $field){$raw=$input[$field['name']]??'';if(!is_string($raw)||strlen($raw)>$field['maxLength']||!preg_match('//u',$raw)||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$raw)||$field['type']!=='textarea'&&preg_match('/[\r\n]/',$raw))throw new HttpError(422,'Invalid submitted field.','forms.error.fields');$value=trim($raw);
      if($field['required']&&$value==='')throw new HttpError(422,'Complete all required fields.','forms.error.required');
      if($value!==''&&($field['type']==='email'&&!filter_var($value,FILTER_VALIDATE_EMAIL)||$field['type']==='checkbox'&&$value!=='1'||in_array($field['type'],['select','radio'],true)&&!in_array($value,$field['options'],true)))throw new HttpError(422,'Invalid field value.','forms.error.fields');$values[$field['name']]=$value;
    }if(($input['website']??'')!==''||isset($input['consent'])&&!is_string($input['consent'])||$form['legalBasis']==='consent'&&($input['consent']??'')!=='1')throw new HttpError(422,'Review the form and privacy choices.','forms.error.fields');return $values;
  }
}
