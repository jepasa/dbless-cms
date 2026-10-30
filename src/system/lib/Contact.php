<?php
/** Contato central: tipos/limites, CSRF/origem, cota atômica e resposta verdadeira de transporte. */
declare(strict_types=1);
namespace CMS2JSON;
final class Contact {
  /** Processa POST sintético ou real; recebe config/store/base; escreve texto, sem persistir PII; erros HTTP são explícitos. */
  public static function handle(Config $config,JsonStore $store,string $base): void {
    header('Content-Type: text/plain; charset=UTF-8');
    if (($_SERVER['REQUEST_METHOD']??'GET')!=='POST') { header('Allow: POST'); throw new HttpError(405,'Method Not Allowed'); }
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>16384) throw new HttpError(413,'Mensagem excede o tamanho permitido.');
    Security::limit($config,'contact',5,600);
    $values=[];
    foreach (['name'=>120,'email'=>254,'subject'=>200,'message'=>10000] as $field=>$limit) {
      $value=$_POST[$field]??null;
      if (!is_string($value) || trim($value)==='') throw new HttpError(400,'Preencha todos os campos obrigatórios.');
      if (strlen($value)>$limit || !preg_match('//u',$value) || str_contains($value,"\0")) throw new HttpError(400,'Campo excede o limite ou contém dados inválidos.');
      if ($field!=='message' && preg_match('/[\r\n]/',$value)) throw new HttpError(400,'Campo contém dados inválidos.');
      $values[$field]=trim($value);
    }
    if (!filter_var($values['email'],FILTER_VALIDATE_EMAIL)) throw new HttpError(400,'E-mail inválido.');
    Security::csrf($_POST['csrf_token']??null);
    $origin=$_SERVER['HTTP_ORIGIN']??null;
    $parts=parse_url($base); $expected=($parts['scheme']??'http').'://'.($parts['host']??'localhost').(isset($parts['port'])?':'.$parts['port']:'');
    if ($origin!==null && (!is_string($origin) || rtrim($origin,'/')!==$expected)) throw new HttpError(403,'Origem não permitida.');
    $to=$store->read('site')['data']['settings']['contact']['email'];
    if (!$config->data['contact_enabled'] || !function_exists('mail') || !filter_var($to,FILTER_VALIDATE_EMAIL)) throw new HttpError(503,'Envio indisponível. Tente novamente mais tarde.');
    $body="Nome: {$values['name']}\nE-mail: {$values['email']}\n\n{$values['message']}";
    $sent=@mail($to,$values['subject'],$body,['From'=>$to,'Reply-To'=>$values['email']]);
    if (!$sent) throw new HttpError(503,'Envio indisponível. Tente novamente mais tarde.');
    echo 'OK';
  }
}
